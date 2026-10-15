<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Analysis;

use Shipard\Core\Ai\Exception\LlmApiException;
use Shipard\Core\Ai\Exception\LlmUnsupportedProviderException;
use Shipard\Core\Ai\LlmChatResult;
use Shipard\Core\Ai\LlmClient;
use Shipard\Core\Ai\LlmRetry;
use Shipard\Core\Ai\ModelCatalog;
use Shipard\Core\Logging\ErrorLogger;

/**
 * Analýza zprávy nanečisto (tasks/testing-phase4.md D34) — výpočetní část
 * běhu analýzy jako sdílená služba **bez zápisu**: nemá spojení do DB,
 * nedělá claim, nemění stav zprávy, neukládá analýzu, návrh ani partnera.
 * Pro zprávu, profil a hotové rozhodnutí o volání ({@see AnalysisRequest}):
 *
 *   1. přílohy ({@see AttachmentPreparer}) → prompt ({@see PromptRenderer};
 *      šablona profilu nebo ze souboru) → model (streamovaně, opakování
 *      přechodných chyb {@see LlmRetry} s hookem před každým pokusem)
 *      → {@see OutputParser} (JSON + `output_schema` profilu) → tělo
 *      výsledku v4 ({@see resultBody()});
 *   2. validace canonicalu a obohacení
 *      ({@see AnalysisResultWriter::validateCanonical()} — jen čte: schéma,
 *      historie řádků, pravidla a LLM klasifikace štítků);
 *   3. {@see AnalysisOutcome}: tělo, výstup modelu, validovaný canonical,
 *      cena, tokeny, čas, nebo typ chyby runneru s poznámkou.
 *
 * Ostrý runner ({@see AnalysisRunner}) jede přes tutéž službu a sám dělá
 * claim, zámek lease (hook `beforeAttempt`), strop opakování a zápis
 * (`storeResult` s předanou validací, `storeFailure`); `ai-eval` nad ní
 * porovnává výstup s očekáváním korpusu. Mapování chyb je totéž co dřív
 * v runneru: šablona / schéma profilu / provider = `config_error`;
 * nevalidní JSON = `schema_error`; neúplný výsledek (`max_tokens`,
 * `refusal`, …) = `ai_error` bez opakování; chyba nastavení API
 * (`isConfigurationError()`) = `config_error`; jiná chyba API = `ai_error`
 * s `transient` podle `isTransient()`.
 */
final class AnalysisComputation implements AnalysisComputing
{
    public const STALL_TIMEOUT_SECONDS = 180;
    public const CALL_TIMEOUT_SECONDS = 840;
    /** Pauzy mezi pokusy při přechodné chybě (celkem tři pokusy). */
    public const RETRY_DELAYS = [10, 60];

    /**
     * @param AnalysisResultWriter $validator jen {@see AnalysisResultWriter::validateCanonical()} — nic se nezapisuje
     * @param \Closure(int): void|null $sleep pauza mezi pokusy (testy)
     */
    public function __construct(
        private readonly AttachmentPreparer $attachments,
        private readonly PromptRenderer $prompts,
        private readonly OutputParser $parser,
        private readonly LlmClient $llm,
        private readonly AnalysisResultWriter $validator,
        private readonly ?ModelCatalog $catalog = null,
        private readonly ?\Closure $sleep = null,
    ) {}

    /**
     * Výjimka hooku `beforeAttempt` (zaniklý claim) propadá beze změny;
     * ostatní chyby běhu končí jako {@see AnalysisOutcome::failure()}.
     */
    public function compute(AnalysisRequest $request): AnalysisOutcome
    {
        $maxTokens = $request->call->maxTokens;
        $result = null;
        $cost = null;

        try {
            $schema = self::outputSchemaOf($request->profile);
            $attachments = $this->attachments->prepare($request->messageId, (int) ($request->message['raw_source_attachment'] ?? 0));
            $prompt = $this->prompts->render($request->promptTemplate(), $request->message, $attachments, $schema);
            $params = $request->call->chatParams(
                system: null,
                messages: [['role' => 'user', 'content' => self::contentBlocks($prompt, $attachments)]],
                stallTimeoutSeconds: $request->stallTimeoutSeconds,
                timeoutSeconds: $request->timeoutSeconds,
            );

            $startedAt = 0.0;
            $result = LlmRetry::run(
                function () use ($params, &$startedAt): LlmChatResult {
                    $startedAt = microtime(true);
                    return $this->llm->streamChat($params, static function (string $delta): void {});
                },
                $request->retryDelays,
                $request->beforeAttempt,
                $this->sleep,
            );
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $tokensIn = $result->inputTokens ?? 0;
            $tokensOut = $result->outputTokens ?? 0;
            $modelName = $result->model ?? $request->call->model;
            // Cena z rozhodnutí resolveru (model katalogu × násobitel platformy,
            // tasks/ai-bedrock-eu.md D60), ne z ID v odpovědi; vlastní ID = 0 (D43).
            $cost = $request->call->costUsd($this->catalog ?? ModelCatalog::empty(), $tokensIn, $tokensOut) ?? 0.0;

            if (!$result->isComplete()) {
                // Useknutý výstup = rozbitý JSON; opakování se stejným limitem
                // dopadne stejně. Odmítnutí (`refusal`) a přetečení kontextu
                // (`model_context_window_exceeded`) by jinak propadly do
                // parsování a skončily zavádějící chybou schématu (F0-D4).
                $note = $result->stopReason === 'max_tokens'
                    ? "anthropic: output truncated at max_tokens={$maxTokens}"
                    : "anthropic: stop_reason {$result->stopReason}";
                return AnalysisOutcome::failure('ai_error', $note, false, $tokensIn, $cost, null, $result->text, $modelName, $result->stopReason, $tokensOut, $durationMs);
            }
            if ($result->stopReason !== null && $result->stopReason !== 'end_turn') {
                ErrorLogger::warn('AnalysisComputation: unexpected stop_reason', ['message' => $request->messageId, 'stopReason' => $result->stopReason]);
            }

            $parsed = $this->parser->parse($result->text, $schema);
            $body = self::resultBody($request, $modelName, $parsed, $tokensIn, $tokensOut, $durationMs, $cost);

            return AnalysisOutcome::success(
                $body,
                $parsed,
                $this->validate($body),
                $tokensIn,
                $tokensOut,
                $durationMs,
                $cost,
                $result->text,
                $modelName,
                $result->stopReason,
            );
        } catch (PromptRenderException $e) {
            return AnalysisOutcome::failure('config_error', 'prompt template: ' . $e->getMessage(), false, null, $cost, $e);
        } catch (ProfileConfigException | LlmUnsupportedProviderException $e) {
            return AnalysisOutcome::failure('config_error', $e->getMessage(), false, null, $cost, $e);
        } catch (SchemaValidationException $e) {
            return AnalysisOutcome::failure('schema_error', $e->getMessage(), false, $result?->inputTokens, $cost, $e, $result?->text, $result?->model, $result?->stopReason, $result?->outputTokens);
        } catch (LlmApiException $e) {
            if ($e->isConfigurationError()) {
                // Klíč, oprávnění, neznámý či vyřazený model, strop útraty
                // organizace (D18) — opraví správce; hláška poskytovatele říká
                // co (u stropu i kdy). Žádné opakování, žádná fronta.
                $note = sprintf('anthropic: HTTP %d %s: %s', $e->statusCode, $e->errorType, $e->getMessage());
                return AnalysisOutcome::failure('config_error', $note, false, null, $cost, $e);
            }
            $transient = $e->isTransient();
            $note = sprintf(
                'anthropic %s: HTTP %d %s: %s',
                $transient ? 'transient' : 'permanent',
                $e->statusCode,
                $e->errorType,
                $e->getMessage(),
            );
            return AnalysisOutcome::failure('ai_error', $note, $transient, null, $cost, $e);
        }
    }

    /**
     * Validace a obohacení canonicalu návrhu — jen když tělo má dokument
     * a prošlo by vstupní kontrolou zápisu (klasifikace zprávy); jinak
     * null a `storeResult` si validaci (nebo odmítnutí těla) udělá sám,
     * aby LLM klasifikace štítků neběžela u těla, které zápis stejně odmítne.
     *
     * @param array<string, mixed> $body
     */
    private function validate(array $body): ?ValidatedCanonical
    {
        $document = $body['document'] ?? null;
        $classification = $body['message_classification'] ?? null;
        if (!is_array($document) || !is_array($classification) || trim((string) ($classification['primary_type'] ?? '')) === '') {
            return null;
        }
        $extracted = is_array($document['extracted_json'] ?? null) ? $document['extracted_json'] : null;
        $validation = $this->validator->validateCanonical($extracted, trim((string) ($document['doc_type'] ?? 'other')));
        return new ValidatedCanonical(
            isset($validation[0]) && is_string($validation[0]) ? $validation[0] : null,
            (bool) ($validation[1] ?? false),
        );
    }

    /**
     * `output_schema` profilu jako objekt (`\stdClass` drží prázdné objekty).
     *
     * @param array<string, mixed> $profile
     */
    public static function outputSchemaOf(array $profile): \stdClass
    {
        $raw = $profile['output_schema'] ?? null;
        if ($raw instanceof \stdClass) {
            return $raw;
        }
        if (is_array($raw)) {
            $raw = json_encode($raw, JSON_UNESCAPED_UNICODE);
        }
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw) : null;
        if (!$decoded instanceof \stdClass) {
            throw new ProfileConfigException('profile output_schema is not a JSON object');
        }
        return $decoded;
    }

    /**
     * Jedna zpráva `user`: text promptu, pak za každou přílohu blok
     * `document` (PDF, base64) / `image` / `text` (D18).
     *
     * @param list<PreparedAttachment> $attachments
     * @return list<array<string, mixed>>
     */
    public static function contentBlocks(string $prompt, array $attachments): array
    {
        $blocks = [['type' => 'text', 'text' => $prompt]];
        foreach ($attachments as $att) {
            switch ($att->kind) {
                case PreparedAttachment::KIND_PDF:
                    $blocks[] = ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => (string) $att->base64]];
                    break;
                case PreparedAttachment::KIND_IMAGE:
                    $blocks[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $att->mimeType, 'data' => (string) $att->base64]];
                    break;
                case PreparedAttachment::KIND_TEXT:
                    $blocks[] = ['type' => 'text', 'text' => "--- Attachment {$att->filename} (#{$att->ndx}) ---\n" . (string) $att->text];
                    break;
                default:
                    ErrorLogger::warn('AnalysisComputation: unknown attachment kind — skipped', ['kind' => $att->kind, 'ndx' => $att->ndx]);
            }
        }
        return $blocks;
    }

    /**
     * Tělo výsledku pro {@see AnalysisResultWriter::storeResult()} (kontrakt
     * v4, modules/core/mail/docs/ai-analysis.md → „Zápis výsledku běhu“):
     * top-level pole z výstupu modelu, nic tvarově specifického navíc.
     * `backend_ndx` 0 (serverové připojení pro vývoj) zápis uloží jako NULL.
     *
     * @param array<string, mixed> $parsed
     * @return array<string, mixed>
     */
    public static function resultBody(AnalysisRequest $request, string $modelName, array $parsed, int $tokensIn, int $tokensOut, int $durationMs, float $cost): array
    {
        $body = [
            'model_name' => $modelName,
            'prompt_version' => $request->promptVersion(),
            'profile_ndx' => $request->profileNdx(),
            'backend_ndx' => $request->call->connectionId,
            'tokens_input' => $tokensIn,
            'tokens_output' => $tokensOut,
            'duration_ms' => $durationMs,
            'cost_usd' => $cost,
            'overall_confidence' => (float) ($parsed['overall_confidence'] ?? 0.0),
            'analysis_json' => $parsed,
        ];

        if (is_array($parsed['message_classification'] ?? null)) {
            $body['message_classification'] = $parsed['message_classification'];
        } else {
            ErrorLogger::warn('AnalysisComputation: model output has no message_classification', [
                'promptVersion' => $body['prompt_version'],
            ]);
        }

        $document = $parsed['document'] ?? null;
        if (is_array($document)) {
            $body['document'] = [
                'doc_type' => (string) (($document['doc_type'] ?? '') !== '' ? $document['doc_type'] : 'unknown'),
                'extracted_json' => $document['extracted_json'] ?? [],
                'confidence' => (float) ($document['confidence'] ?? 0.0),
            ];
        }

        $findings = $parsed['secondary_findings'] ?? null;
        if (is_array($findings)) {
            $cleaned = array_values(array_filter($findings, 'is_array'));
            if ($cleaned !== []) {
                $body['secondary_findings'] = $cleaned;
            }
        }

        return $body;
    }
}

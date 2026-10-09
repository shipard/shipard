<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Analysis;

use Shipard\Core\Ai\AnthropicPricing;
use Shipard\Core\Ai\Exception\LlmApiException;
use Shipard\Core\Ai\Exception\LlmUnsupportedProviderException;
use Shipard\Core\Ai\LlmChatParams;
use Shipard\Core\Ai\LlmChatResult;
use Shipard\Core\Ai\LlmClient;
use Shipard\Core\Ai\LlmRetry;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Logging\ErrorLogger;

/**
 * AI analýza došlé zprávy v procesu (tasks/mail-analysis-inprocess.md
 * D12–D18) — tytéž kroky jako pull protokol démona, volané jako služby:
 *
 *   1. slot souběhu (flock, neblokující) — bez slotu konec, zpráva čeká
 *   2. {@see AnalysisQueue::isEligible()} — ne → konec
 *   3. {@see AnalysisClaimService::claim()} 10 → 20, lease; profil, backend, klíč
 *   4. přílohy ({@see AttachmentPreparer}) → prompt ({@see PromptRenderer})
 *   5. model (streamovaně; opakování přechodných chyb, před každým pokusem
 *      prodloužení lease — vypršelá lease = konec bez zápisu)
 *   6. {@see OutputParser} — JSON + schéma profilu
 *   7. {@see AnalysisResultWriter} 20 → 30 / 10 / 70
 *
 * Mapování chyb: chyba claimu z konfigurace (`NO_PROFILE`, `NO_BACKEND`,
 * `BACKEND_KEY_*`, `SECRETS_UNAVAILABLE`) = konec bez zápisu, zpráva
 * zůstává ve frontě, jedno varování; `schema_error` bez opakování;
 * vyčerpaný strop útraty poskytovatele = `config_error` bez opakování;
 * jiná chyba API = `ai_error`, opakovatelná podle `isTransient()` a stropu
 * tří selhaných běhů za hodinu; `stop_reason = max_tokens` = `ai_error`
 * bez opakování; neplatný claim při zápisu = jen varování včetně ceny.
 * Běh v procesu zapisuje `created_by = NULL` (strojový kontext).
 *
 * `sweep()` (minutový cron za reaperem): pro zprávy ve frontě bez
 * aktivního claimu spustí runner — nejvýš tolik, kolik je volných slotů.
 */
class AnalysisRunner
{
    public const LEASE_SECONDS = 900;
    public const STALL_TIMEOUT_SECONDS = 180;
    public const CALL_TIMEOUT_SECONDS = 840;
    /** Pauzy mezi pokusy při přechodné chybě (celkem tři pokusy). */
    public const RETRY_DELAYS = [10, 60];
    /** Kaskáda profil → backend → tento default (0 = nenastaveno). */
    public const DEFAULT_MAX_TOKENS = 32768;
    /** Přechodná chyba vrací zprávu do fronty nejvýš tolikrát za hodinu. */
    public const MAX_FAILURES_PER_HOUR = 3;
    public const FAILURE_WINDOW_SECONDS = 3600;
    public const ERROR_MESSAGE_MAX_LENGTH = 2000;

    private const MESSAGES_TABLE = 'core_mail_incoming_messages';
    private const PROFILES_TABLE = 'core_mail_ai_profiles';
    private const BACKENDS_TABLE = 'core_ai_backends';

    /**
     * @param \Closure(int): void|null $spawn Spuštění runneru pro zprávu
     *        (sweep); null = sweep jen vypíše, co by spustil.
     * @param \Closure(int): void|null $sleep Pauza mezi pokusy (testy).
     * @param string|null $analyzerId `analyzer_id` claimu; null =
     *        `internal:<hostname>:<pid>`.
     */
    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly AnalysisServices $services,
        private readonly AttachmentPreparer $attachments,
        private readonly PromptRenderer $prompts,
        private readonly OutputParser $parser,
        private readonly LlmClient $llm,
        private readonly AnalysisSlots $slots,
        private readonly ?\Closure $spawn = null,
        private readonly ?\Closure $sleep = null,
        private readonly ?string $analyzerId = null,
    ) {}

    /**
     * @return array{
     *     status: 'disabled'|'no_slot'|'not_eligible'|'not_configured'|'claim_failed'|'done'|'failed'|'lost_claim'|'crashed',
     *     message: int,
     *     note?: string,
     *     analysisNdx?: int,
     *     hasDocument?: bool,
     *     errorType?: string,
     *     retryable?: bool,
     *     newState?: int
     * }
     */
    public function run(int $messageId): array
    {
        if ($this->slots->isDisabled()) {
            return ['status' => 'disabled', 'message' => $messageId, 'note' => 'in-process analysis is disabled (ai.analysis.maxConcurrent = 0)'];
        }
        $slot = $this->slots->tryAcquire();
        if ($slot === null) {
            return ['status' => 'no_slot', 'message' => $messageId, 'note' => 'no free analysis slot — message stays queued for the sweep'];
        }

        try {
            return $this->runInSlot($messageId);
        } catch (\Throwable $e) {
            // Nečekaná chyba (DB, disk): claim se neuvolňuje — vyprší a reaper
            // zprávu vrátí do fronty (jako u pádu démona).
            ErrorLogger::logException($e, "AnalysisRunner: message {$messageId} crashed — claim left to expire");
            return ['status' => 'crashed', 'message' => $messageId, 'note' => get_class($e) . ': ' . $e->getMessage()];
        } finally {
            $slot->release();
        }
    }

    /** @return array<string, mixed> */
    private function runInSlot(int $messageId): array
    {
        if (!$this->services->queue->isEligible($messageId)) {
            return ['status' => 'not_eligible', 'message' => $messageId, 'note' => 'message is not queued for analysis (state, workflow, preprocessing gate, flags or an active claim)'];
        }

        try {
            $claim = $this->services->claims->claim($messageId, $this->analyzerId(), self::LEASE_SECONDS);
        } catch (AnalysisClaimException $e) {
            if ($e->isConfigurationError()) {
                ErrorLogger::warn('AnalysisRunner: AI analysis is not configured — message stays queued', [
                    'message' => $messageId,
                    'code' => $e->errorCode,
                    'error' => $e->getMessage(),
                ]);
                return ['status' => 'not_configured', 'message' => $messageId, 'note' => $e->errorCode . ': ' . $e->getMessage()];
            }
            return ['status' => 'claim_failed', 'message' => $messageId, 'note' => $e->errorCode . ': ' . $e->getMessage()];
        }

        return $this->analyze($messageId, $claim);
    }

    /** @return array<string, mixed> */
    private function analyze(int $messageId, AnalysisClaim $claim): array
    {
        $maxTokens = self::maxTokensOf($claim);
        $result = null;
        $cost = null;

        try {
            $message = $this->loadMessage($messageId);
            $schema = self::outputSchemaOf($claim->profile);
            $attachments = $this->attachments->prepare($messageId, (int) ($message['raw_source_attachment'] ?? 0));
            $prompt = $this->prompts->render((string) ($claim->profile['prompt_template'] ?? ''), $message, $attachments, $schema);
            $params = $this->chatParams($claim, $maxTokens, self::contentBlocks($prompt, $attachments));

            $startedAt = 0.0;
            $result = LlmRetry::run(
                function () use ($params, &$startedAt): LlmChatResult {
                    $startedAt = microtime(true);
                    return $this->llm->streamChat($params, static function (string $delta): void {});
                },
                self::RETRY_DELAYS,
                function (int $attempt) use ($claim): void {
                    if (!$this->services->claims->extend($claim->claimId, self::LEASE_SECONDS)) {
                        throw new AnalysisClaimLostException("claim {$claim->claimId} is no longer active (attempt {$attempt})");
                    }
                },
                $this->sleep,
            );
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $tokensIn = $result->inputTokens ?? 0;
            $tokensOut = $result->outputTokens ?? 0;
            $modelName = $result->model ?? (string) $claim->backend['model'];
            $cost = AnthropicPricing::costUsd($modelName, $tokensIn, $tokensOut);

            if ($result->stopReason === 'max_tokens') {
                // Useknutý výstup = rozbitý JSON; opakování se stejným limitem dopadne stejně.
                return $this->fail($messageId, $claim, 'ai_error', "anthropic: output truncated at max_tokens={$maxTokens}", false, $tokensIn, $cost);
            }
            if ($result->stopReason !== null && $result->stopReason !== 'end_turn') {
                ErrorLogger::warn('AnalysisRunner: unexpected stop_reason', ['message' => $messageId, 'stopReason' => $result->stopReason]);
            }

            $parsed = $this->parser->parse($result->text, $schema);
            $body = self::resultBody($claim, $modelName, $parsed, $tokensIn, $tokensOut, $durationMs, $cost);

            if (!$this->services->claims->isActive($claim->claimId)) {
                return $this->lostClaim($messageId, $claim, 'before storing the result', $cost);
            }
            $analysisNdx = $this->services->results->storeResult($messageId, $claim->claimId, $body, null);

            return [
                'status' => 'done',
                'message' => $messageId,
                'analysisNdx' => $analysisNdx,
                'hasDocument' => is_array($parsed['document'] ?? null),
                'note' => sprintf('tokens %d/%d, %d ms, %.4f USD', $tokensIn, $tokensOut, $durationMs, $cost),
            ];
        } catch (AnalysisClaimLostException $e) {
            return $this->lostClaim($messageId, $claim, $e->getMessage(), $cost);
        } catch (PromptRenderException $e) {
            return $this->fail($messageId, $claim, 'config_error', 'prompt template: ' . $e->getMessage(), false, null, $cost);
        } catch (ProfileConfigException | LlmUnsupportedProviderException $e) {
            return $this->fail($messageId, $claim, 'config_error', $e->getMessage(), false, null, $cost);
        } catch (SchemaValidationException $e) {
            return $this->fail($messageId, $claim, 'schema_error', $e->getMessage(), false, $result?->inputTokens, $cost);
        } catch (LlmApiException $e) {
            if ($e->isSpendLimitReached()) {
                // Strop útraty organizace — API stojí do dalšího měsíce; hláška
                // poskytovatele říká kdy (D18). Žádné opakování, žádná fronta.
                return $this->fail($messageId, $claim, 'config_error', 'anthropic: ' . $e->getMessage(), false, null, $cost);
            }
            $transient = $e->isTransient();
            $retryable = $transient
                && $this->services->results->countRecentFailures($messageId, self::FAILURE_WINDOW_SECONDS) < self::MAX_FAILURES_PER_HOUR;
            $note = sprintf(
                'anthropic %s: HTTP %d %s: %s',
                $transient ? 'transient' : 'permanent',
                $e->statusCode,
                $e->errorType,
                $e->getMessage(),
            );
            return $this->fail($messageId, $claim, 'ai_error', $note, $retryable, null, $cost);
        } catch (AnalysisResultException $e) {
            if ($e->errorCode !== AnalysisResultException::VALIDATION_ERROR) {
                throw $e;
            }
            // Writer tělo odmítl (tvar kontraktu) — bez explicitního selhání
            // by claim jen vypršel a zpráva se točila dokola s cenou modelu.
            return $this->fail($messageId, $claim, 'config_error', 'result rejected (' . $e->errorCode . '): ' . $e->getMessage(), false, $result?->inputTokens, $cost);
        }
    }

    /**
     * Sweep (D14): zprávy ve frontě bez aktivního claimu → spawn runneru,
     * nejvýš tolik, kolik je volných slotů. Bez použitelného backendu
     * (aktivní výchozí profil → aktivní backend s klíčem) nedělá nic —
     * jedno varování za sweep.
     *
     * @return array{spawned: list<int>, skipped: string|null}
     */
    public function sweep(): array
    {
        if ($this->slots->isDisabled()) {
            return ['spawned' => [], 'skipped' => 'in-process analysis is disabled (ai.analysis.maxConcurrent = 0)'];
        }
        if (!$this->hasUsableBackend()) {
            ErrorLogger::warn('AnalysisRunner sweep: no usable AI backend (active default profile with an active backend holding an API key) — queue left as is');
            return ['spawned' => [], 'skipped' => 'no usable AI backend'];
        }
        $free = $this->slots->freeCount();
        if ($free <= 0) {
            return ['spawned' => [], 'skipped' => 'no free analysis slot'];
        }

        $spawned = [];
        foreach ($this->services->queue->eligible($free) as $row) {
            $messageId = (int) $row['ndx'];
            if ($this->spawn !== null) {
                ($this->spawn)($messageId);
            }
            $spawned[] = $messageId;
        }

        return ['spawned' => $spawned, 'skipped' => null];
    }

    // ── pomocníci ──────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function fail(int $messageId, AnalysisClaim $claim, string $errorType, string $note, bool $retryable, ?int $tokensUsed, ?float $cost): array
    {
        $note = mb_substr($note, 0, self::ERROR_MESSAGE_MAX_LENGTH);
        if (!$this->services->claims->isActive($claim->claimId)) {
            return $this->lostClaim($messageId, $claim, "before storing the failure [{$errorType}] {$note}", $cost);
        }
        $newState = $this->services->results->storeFailure(
            $messageId,
            $claim->claimId,
            $errorType,
            $note,
            $retryable,
            $tokensUsed,
            (string) ($claim->backend['model'] ?? ''),
            (string) ($claim->profile['prompt_version'] ?? ''),
            null,
        );

        return [
            'status' => 'failed',
            'message' => $messageId,
            'errorType' => $errorType,
            'retryable' => $retryable,
            'newState' => $newState,
            'note' => $note,
        ];
    }

    /** @return array<string, mixed> */
    private function lostClaim(int $messageId, AnalysisClaim $claim, string $where, ?float $cost): array
    {
        ErrorLogger::warn('AnalysisRunner: claim expired during the run — nothing stored', [
            'message' => $messageId,
            'claim' => $claim->claimId,
            'where' => $where,
            'costUsd' => $cost,
        ]);
        return ['status' => 'lost_claim', 'message' => $messageId, 'note' => $where];
    }

    /** @return array<string, mixed> */
    private function loadMessage(int $messageId): array
    {
        $message = $this->db->fetchRow(
            'SELECT subject, sender_email, sender_name, body_plain, body_html, received_at, raw_source_attachment
               FROM %n WHERE id = %i',
            self::MESSAGES_TABLE,
            $messageId,
        );
        if ($message === null) {
            throw new \RuntimeException("message {$messageId} vanished after claim");
        }
        return $message;
    }

    private function hasUsableBackend(): bool
    {
        $row = $this->db->fetchRow(
            'SELECT b.api_key FROM %n p JOIN %n b ON b.id = p.backend
              WHERE p.is_default = %i AND p.is_active = %i AND b.is_active = %i LIMIT 1',
            self::PROFILES_TABLE,
            self::BACKENDS_TABLE,
            1,
            1,
            1,
        );
        return $row !== null && (string) ($row['api_key'] ?? '') !== '';
    }

    private function analyzerId(): string
    {
        return $this->analyzerId ?? ('internal:' . (gethostname() ?: 'unknown') . ':' . getmypid());
    }

    /** `max_tokens` kaskádou profil → backend → default (0 = nenastaveno). */
    public static function maxTokensOf(AnalysisClaim $claim): int
    {
        foreach ([(int) ($claim->profile['max_tokens'] ?? 0), (int) ($claim->backend['max_tokens'] ?? 0)] as $value) {
            if ($value > 0) {
                return $value;
            }
        }
        return self::DEFAULT_MAX_TOKENS;
    }

    /**
     * Teplota z backendu, jako ji posílal démon. Jediné místo, kde runner
     * čte ladicí parametry backendu — ladění (thinking, volitelná teplota)
     * přebere `AiBackendResolver::tuning()` z tasks/ai-models-phase0.md.
     */
    private static function temperatureOf(AnalysisClaim $claim): ?float
    {
        return (float) ($claim->backend['temperature'] ?? 0.0);
    }

    /** `output_schema` profilu jako objekt (`\stdClass` drží prázdné objekty). */
    private static function outputSchemaOf(array $profile): \stdClass
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

    /** @param list<array<string, mixed>> $content */
    private function chatParams(AnalysisClaim $claim, int $maxTokens, array $content): LlmChatParams
    {
        $backend = $claim->backend;
        return new LlmChatParams(
            provider: (string) ($backend['provider'] ?? 'anthropic'),
            model: (string) ($backend['model'] ?? ''),
            apiKey: $claim->apiKey,
            baseUrl: isset($backend['base_url']) && $backend['base_url'] !== null ? (string) $backend['base_url'] : '',
            system: null,
            messages: [['role' => 'user', 'content' => $content]],
            maxTokens: $maxTokens,
            temperature: self::temperatureOf($claim),
            tools: null,
            stallTimeoutSeconds: self::STALL_TIMEOUT_SECONDS,
            timeoutSeconds: self::CALL_TIMEOUT_SECONDS,
        );
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
                    ErrorLogger::warn('AnalysisRunner: unknown attachment kind — skipped', ['kind' => $att->kind, 'ndx' => $att->ndx]);
            }
        }
        return $blocks;
    }

    /**
     * Tělo výsledku = dnešní tělo `/result` (kontrakt v4, přenos
     * `_build_result_payload` démona): top-level pole z výstupu modelu,
     * nic tvarově specifického navíc.
     *
     * @param array<string, mixed> $parsed
     * @return array<string, mixed>
     */
    public static function resultBody(AnalysisClaim $claim, string $modelName, array $parsed, int $tokensIn, int $tokensOut, int $durationMs, float $cost): array
    {
        $body = [
            'model_name' => $modelName,
            'prompt_version' => (string) ($claim->profile['prompt_version'] ?? ''),
            'profile_ndx' => $claim->profileNdx(),
            'backend_ndx' => $claim->backendNdx(),
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
            ErrorLogger::warn('AnalysisRunner: model output has no message_classification', [
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

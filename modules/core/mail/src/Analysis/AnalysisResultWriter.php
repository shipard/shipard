<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Analysis;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Core\Exchange\Enrich\RowEnrichmentPipeline;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;
use Shipard\Module\Core\Mail\AttentionKinds;
use Shipard\Module\Core\Mail\IncomingMessageDocument;
use Shipard\Module\Core\Mail\MessagePartnerWriter;
use Shipard\Module\Core\Mail\MessageTitleComposer;
use Shipard\Module\Core\Mail\PostAnalysisDisposer;
use Shipard\Module\Core\Mail\PrimaryTypes;

/**
 * Zápis výsledku a selhání AI analýzy jako služba
 * (tasks/mail-analysis-inprocess.md D12/D13), volaná in-process runnerem.
 * Tvar `$body` = kontrakt v4 (modules/core/mail/docs/ai-analysis.md →
 * „Zápis výsledku běhu“), message-centricky.
 *
 * `storeResult()`: vytvoří záznam v `core_mail_message_analyses`
 * s canonical návrhem (`document` 0..1 → canonical_json + proposed_type),
 * uvolní claim, přepne analysis_state → 30. docState: jen když je zpráva
 * stále v Nové (10) a běh přinesl validní dokument → 10 → 20 (K řešení).
 * Běh bez dokumentu docState nemění (zpráva zůstává v Nové — dashboard
 * řeší karta „Není faktura"); ruční workflow stav pipeline nikdy
 * nepřepisuje.
 *
 * `message_classification` je povinná (prompt v4 ji vždy generuje);
 * pole `extracted_documents` se od v4 nepřijímá (D11 — big-bang, bez
 * kompatibilní mezivrstvy). `secondary_findings` se strukturálně
 * nevaliduje — žije jen v analysis_json.
 *
 * Z validního canonicalu se navíc zapíše partner zprávy
 * (`partner_name` / `partner_person`, vrstva 1 — {@see MessagePartnerWriter})
 * a titulek zprávy `ai_title` (`message_classification.title`, fallback
 * {@see MessageTitleComposer}).
 *
 * Běh bez dokumentu u zprávy `other` od odesílatele s potvrzeným
 * pravidlem ji může rovnou archivovat ({@see PostAnalysisDisposer},
 * tasks/mail-sender-rules-after-analysis.md D4–D6) — jen první úspěšná
 * analýza, jistota klasifikace ≥ `review` práh profilu, ne ruční nahrání.
 *
 * `storeFailure()`: záznam se status 3, uvolnění claimu, analysis_state
 * 20 → 10 (retryable) nebo 20 → 70 (permanent). docState se nemění.
 * Samotný řádek selhaného běhu zapisuje `recordFailedRun()` — sdílí ho
 * reaper při třetím vypršení claimu za hodinu (D26).
 */
class AnalysisResultWriter
{
    private const MESSAGES_TABLE = 'core_mail_incoming_messages';
    private const ANALYSES_TABLE = 'core_mail_message_analyses';
    private const CLAIMS_TABLE = 'core_mail_analysis_claims';

    /** Kontrakt registry extrakce (schéma v modules/base/registry/schemas). */
    private const REGISTRY_FORMAT_ID = 'shpd.registry.document';
    private const REGISTRY_FORMAT_VERSION = '1';

    // Workflow stavy zprávy (core.mail.docStatesIncoming) — pipeline na ně
    // sahá jediným místem: result s dokumenty posouvá Novou na K řešení.
    private const DOC_STATE_NEW = IncomingMessageDocument::DOC_STATE_NEW;
    private const DOC_STATE_IN_PROGRESS = IncomingMessageDocument::DOC_STATE_OPEN;
    private const DOC_STATE_IN_PROGRESS_MAIN = 2;

    /** Lazy validator registry canonicalu (viz registrySchemaValidator()). */
    private ?SchemaValidator $registrySchemaValidator = null;

    /** Lazy zápis partnera zprávy z canonicalu (viz partnerWriter()). */
    private ?MessagePartnerWriter $partnerWriter = null;

    /** Lazy archivace ostatní pošty podle pravidla odesílatele (viz postAnalysisDisposer()). */
    private ?PostAnalysisDisposer $postAnalysisDisposer = null;

    /**
     * Lazy fallback titulku zprávy z canonicalu per AI profil běhu
     * (klíč 0 = výchozí profil DS) — viz titleComposer().
     *
     * @var array<int, MessageTitleComposer>
     */
    private array $titleComposers = [];

    /**
     * SchemaValidator je záměrně nullable (unit testy bez validace): bez
     * něj se canonical ukládá tak, jak přišel, a považuje se za validní.
     */
    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly DataSourceConfig $config,
        private readonly ?SchemaValidator $schemaValidator = null,
        private readonly ?RowEnrichmentPipeline $enricher = null,
        private readonly ?ConfigRuntime $configRuntime = null,
    ) {}

    /**
     * @param array<string, mixed> $body Tělo výsledku (kontrakt v4).
     * @param int|null $userId Kdo běh zapisuje (`created_by`); null = strojový kontext.
     * @return int Id záznamu v `core_mail_message_analyses`.
     * @throws AnalysisResultException
     */
    public function storeResult(int $messageId, int $claimId, array $body, ?int $userId): int
    {
        $modelName = trim((string) ($body['model_name'] ?? ''));
        $promptVersion = trim((string) ($body['prompt_version'] ?? ''));
        if ($modelName === '' || $promptVersion === '') {
            throw new AnalysisResultException(
                AnalysisResultException::VALIDATION_ERROR,
                'model_name and prompt_version are required',
                422,
            );
        }

        if (array_key_exists('extracted_documents', $body)) {
            throw new AnalysisResultException(
                AnalysisResultException::VALIDATION_ERROR,
                'extracted_documents is no longer accepted — send document (0..1), contract v4',
                422,
                [['field' => 'extracted_documents']],
            );
        }

        $classification = $body['message_classification'] ?? null;
        if (!is_array($classification)
            || trim((string) ($classification['primary_type'] ?? '')) === ''
        ) {
            throw new AnalysisResultException(
                AnalysisResultException::VALIDATION_ERROR,
                'message_classification with primary_type is required',
                422,
                [['field' => 'message_classification']],
            );
        }

        $document = is_array($body['document'] ?? null) ? $body['document'] : null;

        $profileNdx = isset($body['profile_ndx']) && (int) $body['profile_ndx'] > 0
            ? (int) $body['profile_ndx']
            : null;
        $backendNdx = isset($body['backend_ndx']) && (int) $body['backend_ndx'] > 0
            ? (int) $body['backend_ndx']
            : null;

        // 1) Canonical návrhu: validace + enrichment (včetně případné LLM
        //    klasifikace obsahového štítku). Běží PŘED transakcí — jen čte
        //    (validace, SELECTy, LLM volání); držet kvůli LLM otevřenou tx
        //    by blokovalo zámky. Nevalidní výstup dostává forenzní wrapper
        //    (dashboard z něj staví chybovou kartu), běh se uloží.
        $canonicalJson = null;
        $proposedType = null;
        $documentValid = false;
        $docConfidence = null;
        if ($document !== null) {
            $proposedType = trim((string) ($document['doc_type'] ?? 'other'));
            $docConfidence = isset($document['confidence']) ? (float) $document['confidence'] : null;
            $extractedJson = is_array($document['extracted_json'] ?? null)
                ? $document['extracted_json']
                : null;
            [$canonicalJson, $documentValid] = $this->validateCanonical(
                $extractedJson,
                $proposedType,
            );
        }
        $contentTag = $this->extractContentTag($canonicalJson, $documentValid);
        // Canonical jako pole pro zápis partnera (vrstva 1) — jen validní
        // návrh, forenzní wrapper se do partnera nepropisuje.
        $canonical = $documentValid && $canonicalJson !== null
            ? json_decode($canonicalJson, true)
            : null;

        $dibi = $this->db->getDibiConnection();
        $dibi->begin();
        try {
            $now = date('Y-m-d H:i:s');

            // 2) message_analyses záznam. `confidence` nese jistotu návrhu
            //    (document.confidence) — z ní se za běhu počítá pásmo
            //    ready/review/low; běh bez dokumentu ukládá overall_confidence.
            $dibi->insert(self::ANALYSES_TABLE, [
                'message' => $messageId,
                'profile' => $profileNdx,
                'backend' => $backendNdx,
                'analyzed_at' => $now,
                'status' => 2, // success
                'model_name' => $modelName,
                'model_version' => isset($body['model_version']) ? (string) $body['model_version'] : null,
                'prompt_version' => $promptVersion,
                'analysis_json' => isset($body['analysis_json'])
                    ? (string) json_encode($body['analysis_json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : null,
                'canonical_json' => $canonicalJson,
                'proposed_type' => $proposedType,
                'content_tag' => $contentTag,
                'confidence' => $docConfidence
                    ?? (isset($body['overall_confidence']) ? (float) $body['overall_confidence'] : null),
                'tokens_input' => isset($body['tokens_input']) ? (int) $body['tokens_input'] : null,
                'tokens_output' => isset($body['tokens_output']) ? (int) $body['tokens_output'] : null,
                'duration_ms' => isset($body['duration_ms']) ? (int) $body['duration_ms'] : null,
                'cost_usd' => isset($body['cost_usd']) ? (float) $body['cost_usd'] : null,
                'created' => $now,
                'created_by' => $userId,
            ])->execute();
            $analysisNdx = (int) $dibi->getInsertId();

            // 3) Uvolni claim
            $dibi->update(self::CLAIMS_TABLE, [
                'released' => 1,
                'released_at' => $now,
                'release_reason' => 'result',
            ])->where('id = %i', $claimId)->execute();

            // 4) analysis_state → 30 (Analyzováno), vynulovat needs_reanalysis.
            $dibi->update(self::MESSAGES_TABLE, [
                'analysis_state' => AnalysisStates::ANALYZED,
                'needs_reanalysis' => 0,
                'modified' => $now,
            ])->where('id = %i', $messageId)->execute();

            // 5) Workflow: Nová → K řešení, jen když běh přinesl validní
            // dokument a uživatel mezitím stav ručně nezměnil
            // (docState != 10 → nechat být).
            if ($documentValid) {
                $dibi->update(self::MESSAGES_TABLE, [
                    'docState' => self::DOC_STATE_IN_PROGRESS,
                    'docStateMain' => self::DOC_STATE_IN_PROGRESS_MAIN,
                ])
                ->where('id = %i', $messageId)
                ->where('docState = %i', self::DOC_STATE_NEW)
                ->execute();
            }

            // 6) AI klasifikace typu zprávy (message_classification).
            $this->applyMessageClassification($dibi, $messageId, $body);

            // 7) Partner zprávy — vrstva 1
            //    (tasks/mail-message-title-partner.md D5/D8): partner_name
            //    dokud target_row IS NULL, partner_person jen do NULL a jen
            //    shodou identifikátorem. Zdroj: validní canonical návrhu;
            //    u zprávy bez dokladu (`other`, document null) protistrana
            //    z klasifikace (tasks/mail-other-attention.md D7 — od koho
            //    zpráva skutečně je, ne kdo ji přeposlal). Best-effort —
            //    selhání nesmí shodit uložení výsledku (runner by běh
            //    opakoval).
            try {
                if (is_array($canonical) && $proposedType !== null) {
                    $this->partnerWriter()->writeFromCanonical($dibi, $messageId, $canonical, $proposedType);
                } elseif ($document === null
                    && trim((string) ($classification['primary_type'] ?? '')) === PrimaryTypes::OTHER
                    && is_array($classification['party'] ?? null)
                ) {
                    $this->partnerWriter()->writeFromClassification($dibi, $messageId, $classification['party']);
                }
            } catch (\Throwable $e) {
                ErrorLogger::warn('AnalysisResultWriter::storeResult partner write failed', [
                    'messageNdx' => $messageId,
                    'error' => $e->getMessage(),
                ]);
            }

            // 8) Titulek zprávy (ai_title) — AI-vlastněný, zapisuje se každý
            //    běh (i NULL). Best-effort ze stejného důvodu jako partner.
            try {
                $this->applyMessageTitle(
                    $dibi, $messageId, $body,
                    is_array($canonical) ? $canonical : null, $proposedType, $profileNdx,
                );
            } catch (\Throwable $e) {
                ErrorLogger::warn('AnalysisResultWriter::storeResult title write failed', [
                    'messageNdx' => $messageId,
                    'error' => $e->getMessage(),
                ]);
            }

            // 9) Pravidlo odesílatele po analýze
            //    (tasks/mail-sender-rules-after-analysis.md D4–D6): zpráva
            //    `other` bez dokumentu od odesílatele s potvrzeným pravidlem
            //    → Archiv s auditem `auto_disposed_*`. Čte stav po zápisu
            //    klasifikace (ruční volba uživatele má přednost), nad stejným
            //    spojením. Best-effort jako partner a titulek.
            try {
                $this->postAnalysisDisposer()->afterResult(
                    $messageId,
                    $document !== null,
                    $this->classificationConfidence($body),
                    $profileNdx,
                );
            } catch (\Throwable $e) {
                ErrorLogger::warn('AnalysisResultWriter::storeResult post-analysis disposal failed', [
                    'messageNdx' => $messageId,
                    'error' => $e->getMessage(),
                ]);
            }

            $dibi->commit();
        } catch (\Throwable $e) {
            $dibi->rollback();
            throw new AnalysisResultException(
                AnalysisResultException::INTERNAL_ERROR,
                $e->getMessage(),
                500,
                [],
                $e,
            );
        }

        return $analysisNdx;
    }

    /**
     * Záznam neúspěchu: `error_message` ve tvaru `[typ] zpráva` (z něj
     * {@see \Shipard\Module\Core\Mail\AnalysisErrorPresenter} skládá hlášku
     * pro uživatele), uvolnění claimu, `analysis_state` 20 → 10 (retryable)
     * nebo 20 → 70 (permanent). Spec tasks/mail-phase3a.md §3.6.
     *
     * @return int Nový `analysis_state` zprávy (10 nebo 70).
     * @throws AnalysisResultException
     */
    public function storeFailure(
        int $messageId,
        int $claimId,
        string $errorType,
        string $errorMessage,
        bool $retryable,
        ?int $tokensUsed,
        ?string $modelName,
        ?string $promptVersion,
        ?int $userId,
    ): int {
        $dibi = $this->db->getDibiConnection();
        $dibi->begin();
        try {
            $now = date('Y-m-d H:i:s');

            $this->recordFailedRun($messageId, $errorType, $errorMessage, $tokensUsed, $modelName, $promptVersion, $userId, $now);

            $dibi->update(self::CLAIMS_TABLE, [
                'released' => 1,
                'released_at' => $now,
                'release_reason' => 'failed',
            ])->where('id = %i', $claimId)->execute();

            // retryable=true → zpět do fronty (10), jinak permanent error (70)
            $newState = $retryable ? AnalysisStates::QUEUED : AnalysisStates::FAILED;

            $dibi->update(self::MESSAGES_TABLE, [
                'analysis_state' => $newState,
                'modified' => $now,
            ])->where('id = %i', $messageId)->execute();

            $dibi->commit();
        } catch (\Throwable $e) {
            $dibi->rollback();
            throw new AnalysisResultException(
                AnalysisResultException::INTERNAL_ERROR,
                $e->getMessage(),
                500,
                [],
                $e,
            );
        }

        return $newState;
    }

    /**
     * Řádek selhaného běhu v `core_mail_message_analyses` (status 3,
     * `error_message` = `[typ] zpráva`) — bez vlastní transakce a bez změny
     * stavu zprávy či claimu. Volá ho `storeFailure()` uvnitř své transakce
     * a {@see \Shipard\Module\Core\Mail\AnalysisClaimReaper} při třetím
     * vypršení claimu za hodinu (tasks/mail-analysis-queue-drain.md D26);
     * jediné místo s tímto INSERTem.
     *
     * @param string|null $now `analyzed_at` a `created`; null = teď.
     */
    public function recordFailedRun(
        int $messageId,
        string $errorType,
        string $errorMessage,
        ?int $tokensUsed,
        ?string $modelName,
        ?string $promptVersion,
        ?int $userId,
        ?string $now = null,
    ): void {
        $errorType = trim($errorType);
        $errorMessage = trim($errorMessage);
        $now ??= date('Y-m-d H:i:s');

        $this->db->getDibiConnection()->insert(self::ANALYSES_TABLE, [
            'message' => $messageId,
            'analyzed_at' => $now,
            'status' => 3, // failed
            'model_name' => $modelName ?? 'unknown',
            'prompt_version' => $promptVersion ?? 'unknown',
            'error_message' => $errorMessage !== '' ? "[{$errorType}] {$errorMessage}" : "[{$errorType}]",
            'tokens_input' => $tokensUsed,
            'created' => $now,
            'created_by' => $userId,
        ])->execute();
    }

    /**
     * Počet selhaných běhů zprávy (`status = 3`) za posledních `$sinceSeconds`
     * — strop opakování přechodných chyb v runneru (nejvýš třikrát za hodinu,
     * tasks/mail-analysis-inprocess.md D18).
     */
    public function countRecentFailures(int $messageId, int $sinceSeconds, ?int $now = null): int
    {
        return (int) $this->db->fetchSingle(
            'SELECT COUNT(*) FROM %n WHERE message = %i AND status = %i AND analyzed_at > %s',
            self::ANALYSES_TABLE,
            $messageId,
            3,
            date('Y-m-d H:i:s', ($now ?? time()) - $sinceSeconds),
        );
    }

    /**
     * Validate the proposed canonical against shpd.docs.document.v1 schema.
     * Invalid output is wrapped (for forensics) — never rejected outright,
     * so the user can still see what came out and trigger reanalyze.
     *
     * Registry targety (dle `primaryTypes[doc_type].target`) se validují
     * proti `shpd.registry.document.v1` a přeskakují enrichment
     * (docs-specifikum). Confidence pásma se nepersistují (D3) — počítá je
     * za běhu AnalysisConfidenceResolver.
     *
     * @param array<string, mixed>|null $extractedJson  Raw canonical from AI (or null).
     * @return array{0: ?string, 1: bool}  [jsonForDb, isValid]
     */
    public function validateCanonical(?array $extractedJson, string $docType): array
    {
        if ($extractedJson === null) {
            return [null, false];
        }

        // D12: čas extrakce je serverový fakt — hodnotu od modelu nepodmíněně
        // přepisujeme. Registry schéma pole source nezná, razítkuje se jen
        // docs větev; bez source pole se forenzní obsah nedotváří.
        if (
            PrimaryTypes::targetFor($this->configRuntime, $docType) !== PrimaryTypes::TARGET_REGISTRY
            && is_array($extractedJson['source'] ?? null)
        ) {
            $extractedJson['source']['extractedAt'] = date(DATE_ATOM);
        }

        // If no SchemaValidator was wired (e.g. unit tests), skip validation
        // and store as-is.
        if ($this->schemaValidator === null) {
            return [
                (string) json_encode($extractedJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                true,
            ];
        }

        if (PrimaryTypes::targetFor($this->configRuntime, $docType) === PrimaryTypes::TARGET_REGISTRY) {
            return $this->validateRegistryCanonical($extractedJson);
        }

        $schemaIssues = $this->schemaValidator->validate(
            $extractedJson,
            DocumentApplier::FORMAT_ID,
            DocumentApplier::FORMAT_VERSION,
        );

        if ($schemaIssues === []) {
            if ($this->enricher !== null) {
                // Obohacení řádků — Vrstva 0 (historie) + obsahová eskalace
                // (pravidlo IČO / LLM klasifikace, D16/D17) — do canonical_json
                // se ukládá obohacený canonical. Selhání zápisu nesmí shodit
                // (runner by běh opakoval) → pokračuje se neobohaceně.
                try {
                    $extractedJson = $this->enricher->enrichAtResult($extractedJson);
                } catch (\Throwable $e) {
                    ErrorLogger::logException($e, 'AnalysisResultWriter row enrichment failed');
                }
            }
            return [
                (string) json_encode($extractedJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                true,
            ];
        }

        return self::wrapInvalid($extractedJson, $schemaIssues);
    }

    /**
     * Registry větev ingestu: validace proti `shpd.registry.document.v1`
     * (schéma modulu base.registry) — žádný RowHistoryEnricher
     * (docs-specifikum). Invalid výstup dostává stejný forenzní wrapper
     * jako docs cesta.
     *
     * @param array<string, mixed> $extractedJson
     * @return array{0: ?string, 1: bool}  [jsonForDb, isValid]
     */
    private function validateRegistryCanonical(array $extractedJson): array
    {
        $schemaIssues = $this->registrySchemaValidator()->validate(
            $extractedJson,
            self::REGISTRY_FORMAT_ID,
            self::REGISTRY_FORMAT_VERSION,
        );

        if ($schemaIssues === []) {
            return [
                (string) json_encode($extractedJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                true,
            ];
        }

        return self::wrapInvalid($extractedJson, $schemaIssues);
    }

    /**
     * @param array<string, mixed> $extractedJson
     * @param list<array<string, mixed>> $schemaIssues
     * @return array{0: string, 1: false}
     */
    private static function wrapInvalid(array $extractedJson, array $schemaIssues): array
    {
        $wrapped = [
            '_validationError' => 'Canonical schema validation failed',
            '_validationIssues' => $schemaIssues,
            '_rawOutput' => $extractedJson,
        ];
        return [
            (string) json_encode($wrapped, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            false,
        ];
    }

    /**
     * Obsahový štítek z `_resolve.contentTag.tag` obohaceného canonicalu —
     * denormalizace do sloupce `content_tag` (filtrování analýz, learning
     * handler). null = bez štítku / nevalidní dokument. Tělo sdílí
     * s ISDOC importem ({@see RowEnrichmentPipeline::contentTagOf()}).
     */
    private function extractContentTag(?string $canonicalJson, bool $documentValid): ?string
    {
        if ($canonicalJson === null || !$documentValid) {
            return null;
        }
        $canonical = json_decode($canonicalJson, true);
        return is_array($canonical) ? RowEnrichmentPipeline::contentTagOf($canonical) : null;
    }

    /**
     * SchemaValidator nad schématy base.registry — druhá instance loaderu
     * mířící do `modules/base/registry/schemas` (soubor drží konvenci
     * `{formatId}.v{version}.json`, takže SchemaLoader funguje beze změny).
     */
    private function registrySchemaValidator(): SchemaValidator
    {
        return $this->registrySchemaValidator ??= new SchemaValidator(
            new SchemaLoader(dirname(__DIR__, 4) . '/base/registry/schemas'),
        );
    }

    /**
     * Zapíše AI klasifikaci typu zprávy z `message_classification`
     * (spec tasks/mail-states-and-classification.md §B1) a s ní pozornost
     * u zprávy bez dokladu (tasks/mail-other-attention.md D1–D3, #105):
     * `attention`, `action_note`, `action_due`. Běží uvnitř transakce
     * resultu. Přítomnost pole vynucuje storeResult() (422) — kontrakt v4
     * ho má povinné; fallback čtení z `analysis_json` zůstává pro robustnost.
     *
     * - Neznámý `primary_type` → warning + ignore; nesmí rozbít uložení
     *   výsledku (žádná 422). Totéž neznámá `attention` (pole zůstane NULL).
     * - AI nikdy nepřepisuje hodnotu nastavenou uživatelem
     *   (`primary_type_source = 'user'` → UPDATE se nedotkne řádku) —
     *   jeden UPDATE, jeden guard pro typ i pozornost.
     * - Pozornost jen u typu `other`; u dokladu a dokumentu Spisovny jsou
     *   všechna tři pole NULL (zpráva mohla být dřív `other` a reanalýzou
     *   se stát fakturou — faktura nesmí nést poznámku „Zaplatit“).
     *   Poznámka a lhůta jen u `action`, i kdyby je model poslal jinde.
     *
     * @param array<string, mixed> $body
     */
    public function applyMessageClassification(\Dibi\Connection $dibi, int $messageId, array $body): void
    {
        $classification = self::classificationOf($body);
        if ($classification === null) {
            return;
        }

        $primaryType = trim((string) ($classification['primary_type'] ?? ''));
        if ($primaryType === '') {
            return;
        }

        if (!in_array($primaryType, $this->knownPrimaryTypes(), true)) {
            ErrorLogger::warn('AnalysisResultWriter ignoring unknown primary_type', [
                'messageNdx' => $messageId,
                'primary_type' => $primaryType,
            ]);
            return;
        }

        $dibi->update(self::MESSAGES_TABLE, [
            'primary_type' => $primaryType,
            'primary_type_source' => 'ai',
            ...$this->attentionFields($messageId, $primaryType, $classification),
        ])
        ->where('id = %i', $messageId)
        ->where('primary_type_source != %s', 'user')
        ->execute();
    }

    /**
     * Sloupce pozornosti z klasifikace (tasks/mail-other-attention.md D3):
     * u typu jiného než `other` vše NULL; `attention` validovaná proti
     * `core.mail.attentionKinds` (neznámá → warning + NULL); `action_note`
     * trim + sjednocení whitespace + 200 znaků (stejný helper jako titulek);
     * `due_date` jen round-trip validní `YYYY-MM-DD`, jinak NULL. Mimo
     * `action` poznámka i lhůta NULL.
     *
     * @param array<string, mixed> $classification
     * @return array{attention: ?string, action_note: ?string, action_due: ?string}
     */
    private function attentionFields(int $messageId, string $primaryType, array $classification): array
    {
        $fields = ['attention' => null, 'action_note' => null, 'action_due' => null];
        if ($primaryType !== PrimaryTypes::OTHER) {
            return $fields;
        }

        $attention = trim((string) ($classification['attention'] ?? ''));
        if ($attention === '') {
            return $fields;
        }
        if (!in_array($attention, AttentionKinds::known($this->configRuntime), true)) {
            ErrorLogger::warn('AnalysisResultWriter ignoring unknown attention', [
                'messageNdx' => $messageId,
                'attention' => $attention,
            ]);
            return $fields;
        }

        $fields['attention'] = $attention;
        if ($attention === AttentionKinds::ACTION) {
            $fields['action_note'] = MessageTitleComposer::clean($classification['action_note'] ?? null);
            $fields['action_due'] = self::isoDateOrNull($classification['due_date'] ?? null);
        }
        return $fields;
    }

    /** `YYYY-MM-DD` s round-trip kontrolou (2026-02-30 → null); jiný vstup → null. */
    private static function isoDateOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }

    /**
     * Titulek zprávy `ai_title` (tasks/mail-message-title-partner.md D1/D2):
     * `message_classification.title` (trim, sjednocení whitespace, 200 znaků;
     * fallback čtení z `analysis_json` jako u klasifikace), když chybí →
     * deterministický fallback z validního canonicalu
     * ({@see MessageTitleComposer}), jinak NULL.
     *
     * Zapisuje se **vždy**, i NULL — sloupec vlastní AI, re-analýza bez
     * dokumentu titulek smaže. Bez guardu na `primary_type_source` (uživatel
     * titulek needituje) i na `target_row` (záměr, P2). Výstup staršího
     * promptu bez `title` projde — pole není v kontraktu povinné (P8).
     *
     * Fallback skládá labely typů v jazyce AI profilu běhu (`$profileNdx`,
     * jinak výchozí profil DS) — stejně jako titulek od AI (D2).
     *
     * @param array<string, mixed>      $body
     * @param array<string, mixed>|null $canonical validní canonical návrhu (bez wrapperu), nebo null
     */
    public function applyMessageTitle(
        \Dibi\Connection $dibi,
        int $messageId,
        array $body,
        ?array $canonical,
        ?string $proposedType,
        ?int $profileNdx = null,
    ): void {
        $classification = self::classificationOf($body);

        $title = $classification !== null
            ? MessageTitleComposer::clean($classification['title'] ?? null)
            : null;
        if ($title === null && $canonical !== null && $proposedType !== null) {
            $title = $this->titleComposer($profileNdx)->compose($canonical, $proposedType);
        }

        $dibi->update(self::MESSAGES_TABLE, ['ai_title' => $title])
            ->where('id = %i', $messageId)
            ->execute();
    }

    /**
     * `message_classification` z těla, fallback z `analysis_json`
     * (robustnost vůči staršímu tvaru těla); null = chybí v obou.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>|null
     */
    private static function classificationOf(array $body): ?array
    {
        $classification = $body['message_classification'] ?? null;
        if (!is_array($classification)) {
            $analysisJson = $body['analysis_json'] ?? null;
            $classification = is_array($analysisJson)
                ? ($analysisJson['message_classification'] ?? null)
                : null;
        }
        return is_array($classification) ? $classification : null;
    }

    /**
     * Klíče cfgItem `core.mail.primaryTypes` — server toleruje i typy
     * s `enabled: false` (prompt AI omezuje na enabled). Bez compiled
     * configu degraduje na pevný seznam (musí odpovídat primaryTypes.jsonc).
     *
     * @return list<string>
     */
    public function knownPrimaryTypes(): array
    {
        $cfg = $this->configRuntime?->cfgItem('core.mail.primaryTypes');
        if (is_array($cfg) && $cfg !== []) {
            return array_map('strval', array_keys($cfg));
        }

        return [
            'invoiceReceived', 'other', 'creditNote', 'order', 'quotation', 'statement', 'complaint',
            'contract', 'insurance', 'certificate', 'official',
        ];
    }

    /**
     * Jistota AI klasifikace typu zprávy (`message_classification.confidence`)
     * pro archivaci podle pravidla odesílatele (D5) — stejný fallback do
     * `analysis_json` jako {@see applyMessageClassification()}. Nečíselná
     * nebo chybějící hodnota → null (zpráva se neodklízí).
     *
     * @param array<string, mixed> $body
     */
    private function classificationConfidence(array $body): ?float
    {
        $classification = self::classificationOf($body);
        if ($classification === null) {
            return null;
        }

        $confidence = $classification['confidence'] ?? null;
        return is_numeric($confidence) ? (float) $confidence : null;
    }

    /**
     * Archivace ostatní pošty podle pravidla odesílatele — nad DS connection
     * resultu (sdílí transakci), lazy.
     */
    private function postAnalysisDisposer(): PostAnalysisDisposer
    {
        return $this->postAnalysisDisposer ??= new PostAnalysisDisposer($this->db, $this->configRuntime);
    }

    /**
     * Zápis partnera zprávy z canonicalu (vrstva 1) — resolver nad DS
     * connection, lazy (běh bez dokumentu ho nepotřebuje).
     */
    private function partnerWriter(): MessagePartnerWriter
    {
        return $this->partnerWriter ??= MessagePartnerWriter::create(
            $this->db->getDibiConnection(),
            $this->configRuntime,
        );
    }

    /**
     * Fallback titulku z canonicalu — labely typů z compiled configu
     * v jazyce AI profilu běhu (lazy, cache per profil).
     */
    private function titleComposer(?int $profileNdx): MessageTitleComposer
    {
        $key = $profileNdx ?? 0;
        return $this->titleComposers[$key] ??= MessageTitleComposer::forDataSource($this->db, $this->config, $profileNdx);
    }
}

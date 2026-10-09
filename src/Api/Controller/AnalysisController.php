<?php

declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\AuthContext;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Document\DocumentEventDispatcher;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Document\TableGateway;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Mail\IncomingMessageCode;
use Shipard\Module\Core\Attachments\AttachmentService;
use Shipard\Module\Base\Registry\RegistryApplier;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Core\Exchange\Enrich\RowEnrichmentPipeline;
use Shipard\Module\Core\Exchange\Resolve\PartyResolver;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;
use Shipard\Module\Core\Mail\AIAnalyzerProvisioner;
use Shipard\Module\Core\Mail\Analysis\AnalysisClaimException;
use Shipard\Module\Core\Mail\Analysis\AnalysisClaimService;
use Shipard\Module\Core\Mail\Analysis\AnalysisQueue;
use Shipard\Module\Core\Mail\Analysis\AnalysisResultException;
use Shipard\Module\Core\Mail\Analysis\AnalysisResultWriter;
use Shipard\Module\Core\Mail\Analysis\AnalysisServices;
use Shipard\Module\Core\Mail\Analysis\AnalysisStates;
use Shipard\Module\Core\Mail\IncomingMessageDocument;
use Shipard\Module\Core\Mail\MessageProposalApplier;
use Shipard\Module\Core\Mail\PrimaryTypes;
use Shipard\Module\Core\Mail\ProposalApplyOutcome;
use Shipard\Module\Docs\Core\OwnCompanyResolver;

/**
 * Endpoints `/_mail/analysis/*` — pull-based protokol pro externí AI analyzer.
 *
 * Autentizace přes `shpd_ak_` token systémového uživatele `_ai_analyzer`
 * (viz `ai-analyzer-setup` CLI). Endpointy vyžadují `X-Claim-Token` hlavičku
 * (vyjma /queue a /claim).
 *
 * Fronta, claim a zápis výsledku žijí ve službách
 * `Shipard\Module\Core\Mail\Analysis\*` (sdílené s in-process runnerem,
 * tasks/mail-analysis-inprocess.md D13); endpointy jsou tenké obálky
 * nad nimi — tvar odpovědí a chybové kódy se nemění.
 *
 * Spec: tasks/mail-phase3a.md §3.
 */
class AnalysisController
{
    public const MAIL_TABLE_ID = 303;

    private const MESSAGES_TABLE = 'core_mail_incoming_messages';
    private const MAILBOXES_TABLE = 'core_mail_mailboxes';
    private const ANALYSES_TABLE = 'core_mail_message_analyses';
    private const PROFILES_TABLE = 'core_mail_ai_profiles';
    private const CLAIMS_TABLE = 'core_mail_analysis_claims';
    private const ATTACHMENTS_TABLE = 'core_attachments_files';
    private const HEADS_TABLE = 'docs_core_heads';
    private const REGISTRY_TABLE = 'base_registry_documents';

    // Workflow stavy zprávy (core.mail.docStatesIncoming) — reanalyze
    // odmítá Archiv a Koš; posun Nová → K řešení dělá AnalysisResultWriter.
    private const DOC_STATE_ARCHIVED = IncomingMessageDocument::DOC_STATE_ARCHIVED;
    private const DOC_STATE_TRASH = IncomingMessageDocument::DOC_STATE_TRASH;

    // Pipeline status analýzy (core.mail.analysisStates) — ortogonální
    // ke workflow, řídí ho výhradně pipeline + reanalyze. Zdroj hodnot
    // je AnalysisStates; aliasy drží dnešní názvy pro ostatní třídy.
    public const ANALYSIS_NONE = AnalysisStates::NONE;
    public const ANALYSIS_QUEUED = AnalysisStates::QUEUED;
    public const ANALYSIS_ANALYZING = AnalysisStates::ANALYZING;
    public const ANALYSIS_ANALYZED = AnalysisStates::ANALYZED;
    public const ANALYSIS_FAILED = AnalysisStates::FAILED;

    /** @see AnalysisStates::PREPROCESS_BLOCKING_STATES */
    public const PREPROCESS_BLOCKING_STATES = AnalysisStates::PREPROCESS_BLOCKING_STATES;

    /**
     * Služby analýzy (fronta, claim, zápis výsledku) — líně ze stejných
     * závislostí jako controller; wiring sdílený s in-process runnerem
     * (tasks/mail-analysis-inprocess.md D13).
     */
    private ?AnalysisServices $services = null;

    /**
     * SchemaValidator + DocumentApplier are intentionally nullable for
     * back-compat with the Phase 1 wiring (and unit tests that don't need
     * either). When null, /result skips canonical validation and
     * /applyExtracted falls back to plain status update.
     *
     * @param array<string, TableDefinition> $tables
     */
    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly DataSourceConfig $config,
        private readonly string $dsPath,
        private readonly array $tables,
        private readonly DocumentRegistry $documentRegistry,
        private readonly ?SchemaValidator $schemaValidator = null,
        private readonly ?DocumentApplier $applier = null,
        private readonly ?ConfigRuntime $configRuntime = null,
        private readonly ?DocumentEventDispatcher $eventDispatcher = null,
        private readonly ?RowEnrichmentPipeline $enricher = null,
    ) {}

    private function services(): AnalysisServices
    {
        return $this->services ??= AnalysisServices::create(
            $this->db,
            $this->config,
            $this->schemaValidator,
            $this->enricher,
            $this->configRuntime,
        );
    }

    // -------------------------------------------------------------------
    // Auth helpers
    // -------------------------------------------------------------------

    /**
     * Ověří, že volající je systémový uživatel _ai_analyzer (přes API key).
     */
    private function verifyAnalyzerAuth(AuthContext $auth): ?Response
    {
        if (!$auth->isAuthenticated) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
        }
        if ($auth->tokenType !== 'api_key') {
            return Response::error('UNAUTHORIZED', 'API key required', 401);
        }

        $user = $this->db->fetchRow(
            'SELECT login FROM core_system_users WHERE id = %i',
            $auth->userId,
        );
        if ($user === null || ($user['login'] ?? '') !== AIAnalyzerProvisioner::ANALYZER_LOGIN) {
            return Response::error(
                'FORBIDDEN',
                'This endpoint is restricted to the _ai_analyzer system user',
                403,
            );
        }

        return null;
    }

    /**
     * Načte aktivní claim podle X-Claim-Token a ověří shodu s message_ndx.
     * Vrací claim row, nebo Response s chybou (401/404/410).
     *
     * @return array<string, mixed>|Response
     */
    private function validateClaimToken(int $messageNdx, Request $request): array|Response
    {
        $token = $request->getHeader('X-Claim-Token');
        if ($token === null || trim($token) === '') {
            return Response::error('MISSING_CLAIM_TOKEN', 'X-Claim-Token header is required', 401);
        }

        $row = $this->db->fetchRow(
            'SELECT * FROM %n WHERE %n = %s LIMIT 1',
            self::CLAIMS_TABLE,
            'claim_token',
            trim($token),
        );

        if ($row === null) {
            return Response::error('INVALID_CLAIM_TOKEN', 'Claim token not found', 401);
        }

        if ((int) $row['message'] !== $messageNdx) {
            return Response::error('CLAIM_TOKEN_MISMATCH', 'Claim token does not match message', 401);
        }

        if ((int) $row['released'] === 1) {
            return Response::error('CLAIM_RELEASED', 'Claim has already been released', 410);
        }

        $expiresAt = strtotime((string) $row['expires_at']);
        if ($expiresAt === false || $expiresAt < time()) {
            return Response::error('CLAIM_EXPIRED', 'Claim has expired', 410);
        }

        return $row;
    }

    /**
     * Bezpečnostní hlavičky pro response, který může obsahovat tajemství
     * (claim s plaintext API klíčem). Spec §10 dec.2.
     */
    private function withNoStoreHeaders(Response $response): Response
    {
        return $response
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->withHeader('Pragma', 'no-cache');
    }

    // -------------------------------------------------------------------
    // GET /queue
    // -------------------------------------------------------------------

    /**
     * Vrátí zprávy připravené k analýze. Spec §3.1. Predikát fronty drží
     * {@see AnalysisQueue}; tady jen dekorace řádků (počet příloh,
     * doporučený profil).
     */
    public function queue(AuthContext $auth, Request $request): Response
    {
        $authError = $this->verifyAnalyzerAuth($auth);
        if ($authError !== null) {
            return $authError;
        }

        $params = $request->getQueryParams();
        $limit = isset($params['limit']) ? max(1, min(50, (int) $params['limit'])) : 5;

        // recommended_profile_ndx = profile_override zprávy NEBO default profile DS
        $defaultProfile = $this->db->fetchRow(
            'SELECT id FROM %n WHERE %n = %i AND %n = %i LIMIT 1',
            self::PROFILES_TABLE,
            'is_default',
            1,
            'is_active',
            1,
        );
        $defaultProfileId = $defaultProfile !== null ? (int) $defaultProfile['id'] : null;

        $queue = $this->services()->queue;
        $now = date('Y-m-d H:i:s');
        $rows = $queue->eligible($limit, $now);

        $messages = [];
        foreach ($rows as $row) {
            $messageNdx = (int) $row['ndx'];
            $attCount = (int) $this->db->fetchSingle(
                'SELECT COUNT(*) FROM %n WHERE %n = %i AND %n = %i',
                self::ATTACHMENTS_TABLE,
                'table_id',
                self::MAIL_TABLE_ID,
                'record_id',
                $messageNdx,
            );

            $recommendedProfile = !empty($row['profile_override'])
                ? (int) $row['profile_override']
                : $defaultProfileId;

            $messages[] = [
                'ndx' => $messageNdx,
                'received_at' => $this->normalizeDateTime($row['received_at']),
                'subject' => (string) $row['subject'],
                'sender_email' => (string) $row['sender_email'],
                'attachment_count' => $attCount,
                'recommended_profile_ndx' => $recommendedProfile,
                'has_raw_source' => !empty($row['raw_source_attachment']),
            ];
        }

        $totalAvailable = $queue->countEligible($now);

        return Response::success([
            'messages' => $messages,
            'total_available' => $totalAvailable,
        ]);
    }

    private function normalizeDateTime(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            return $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:s');
        }
        return (string) $value;
    }

    // -------------------------------------------------------------------
    // POST /{ndx}/claim
    // -------------------------------------------------------------------

    /**
     * Atomic claim — ověř analysis_state=10 (ve frontě), žádná aktivní claim,
     * vytvoř claim record, přepni analysis_state→20, decryptuj api_key.
     * docState (workflow) se nemění. Spec §3.2. Tělo drží
     * {@see AnalysisClaimService}; tady vstup a mapování chyby na Response.
     */
    public function claim(AuthContext $auth, Request $request, int $messageNdx): Response
    {
        $authError = $this->verifyAnalyzerAuth($auth);
        if ($authError !== null) {
            return $authError;
        }

        $body = $request->getBody() ?? [];
        $analyzerId = trim((string) ($body['analyzer_id'] ?? ''));
        if ($analyzerId === '') {
            return Response::error(
                'VALIDATION_ERROR',
                'analyzer_id is required',
                422,
                [['field' => 'analyzer_id']],
            );
        }

        $requestedProfile = isset($body['profile_ndx']) ? (int) $body['profile_ndx'] : null;
        $leaseSeconds = AnalysisClaimService::clampLeaseSeconds(
            isset($body['lease_seconds']) ? (int) $body['lease_seconds'] : null,
        );

        try {
            $claim = $this->services()->claims->claim($messageNdx, $analyzerId, $leaseSeconds, $requestedProfile);
        } catch (AnalysisClaimException $e) {
            return Response::error($e->errorCode, $e->getMessage(), $e->httpStatus);
        }

        $profile = $claim->profile;
        $backend = $claim->backend;
        $response = Response::success([
            'claim_token' => $claim->claimToken,
            'expires_at' => $claim->expiresAt,
            'profile' => [
                'profile_ndx' => (int) $profile['id'],
                'profile_id' => (string) $profile['profile_id'],
                'prompt_version' => (string) $profile['prompt_version'],
                'prompt_template' => (string) $profile['prompt_template'],
                'output_schema' => $this->decodeJsonField($profile['output_schema']),
                'supported_doc_types' => $this->decodeJsonField($profile['supported_doc_types']),
                'language' => (string) $profile['language'],
                'confidence_thresholds' => $this->decodeJsonField($profile['confidence_thresholds']),
                // 0 = nenastaveno; analyzér řeší kaskádu profil → backend →
                // vlastní default. ?? kryje DS před ds-upgrade (SELECT *).
                'max_tokens' => (int) ($profile['max_tokens'] ?? 0),
            ],
            'backend' => [
                'backend_ndx' => (int) $backend['id'],
                'provider' => (string) $backend['provider'],
                'model' => (string) $backend['model'],
                'api_key' => $claim->apiKey,
                'base_url' => $backend['base_url'] !== null ? (string) $backend['base_url'] : null,
                'max_tokens' => (int) $backend['max_tokens'],
                'temperature' => (float) $backend['temperature'],
            ],
        ]);

        return $this->withNoStoreHeaders($response);
    }

    private function decodeJsonField(mixed $raw): mixed
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $decoded = json_decode((string) $raw, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    // -------------------------------------------------------------------
    // GET /{ndx}/payload
    // -------------------------------------------------------------------

    /**
     * Vrátí subject, body, sender + metadata příloh BEZ obsahu. Spec §3.3.
     */
    public function payload(AuthContext $auth, Request $request, int $messageNdx): Response
    {
        $authError = $this->verifyAnalyzerAuth($auth);
        if ($authError !== null) {
            return $authError;
        }

        $claim = $this->validateClaimToken($messageNdx, $request);
        if ($claim instanceof Response) {
            return $claim;
        }

        $msg = $this->db->fetchRow(
            'SELECT subject, sender_email, sender_name, body_plain, body_html, received_at,
                    raw_source_attachment
               FROM %n WHERE id = %i',
            self::MESSAGES_TABLE,
            $messageNdx,
        );
        if ($msg === null) {
            return Response::error('NOT_FOUND', "Message {$messageNdx} not found", 404);
        }

        $rawSourceNdx = isset($msg['raw_source_attachment']) ? (int) $msg['raw_source_attachment'] : 0;

        $attRows = $this->db->fetchAll(
            'SELECT id, name, mime_type, file_size FROM %n
              WHERE %n = %i AND %n = %i AND id != %i AND is_deleted = %i
              ORDER BY id ASC',
            self::ATTACHMENTS_TABLE,
            'table_id',
            self::MAIL_TABLE_ID,
            'record_id',
            $messageNdx,
            $rawSourceNdx, // exclude raw .eml from analyzer-visible attachments
            0,
        );

        $attachments = [];
        foreach ($attRows as $att) {
            $attachments[] = [
                'ndx' => (int) $att['id'],
                'filename' => (string) $att['name'],
                'mime_type' => (string) $att['mime_type'],
                'size_bytes' => (int) $att['file_size'],
            ];
        }

        return Response::success([
            'message' => [
                'subject' => (string) $msg['subject'],
                'sender_email' => (string) $msg['sender_email'],
                'sender_name' => $msg['sender_name'] !== null ? (string) $msg['sender_name'] : null,
                'body_plain' => $msg['body_plain'] !== null ? (string) $msg['body_plain'] : null,
                'body_html' => $msg['body_html'] !== null ? (string) $msg['body_html'] : null,
                'received_at' => $this->normalizeDateTime($msg['received_at']),
            ],
            'attachments' => $attachments,
        ]);
    }

    // -------------------------------------------------------------------
    // GET /{ndx}/attachments/{att_ndx}/content
    // -------------------------------------------------------------------

    /**
     * Streamuje binární obsah jedné přílohy. Spec §3.4.
     * Validace: claim_token, attachment patří k messageNdx.
     */
    public function attachmentContent(
        AuthContext $auth,
        Request $request,
        int $messageNdx,
        int $attachmentNdx,
    ): Response {
        $authError = $this->verifyAnalyzerAuth($auth);
        if ($authError !== null) {
            return $authError;
        }

        $claim = $this->validateClaimToken($messageNdx, $request);
        if ($claim instanceof Response) {
            return $claim;
        }

        // Vyloučit raw_source_attachment (.eml originál) — analyzer pracuje
        // s rozparsovanými přílohami z /payload, ne s celým e-mailem (jinak
        // by se data analyzovala dvakrát: jako MIME příloha a jako součást .eml).
        $msgRow = $this->db->fetchRow(
            'SELECT raw_source_attachment FROM %n WHERE id = %i',
            self::MESSAGES_TABLE,
            $messageNdx,
        );
        if ($msgRow === null) {
            return Response::error('NOT_FOUND', "Message {$messageNdx} not found", 404);
        }
        $rawSourceNdx = isset($msgRow['raw_source_attachment'])
            ? (int) $msgRow['raw_source_attachment']
            : 0;
        if ($rawSourceNdx > 0 && $attachmentNdx === $rawSourceNdx) {
            return Response::error(
                'NOT_FOUND',
                'Raw source (.eml) is not exposed via this endpoint',
                404,
            );
        }

        $att = $this->db->fetchRow(
            'SELECT * FROM %n WHERE id = %i AND %n = %i AND %n = %i AND is_deleted = %i',
            self::ATTACHMENTS_TABLE,
            $attachmentNdx,
            'table_id',
            self::MAIL_TABLE_ID,
            'record_id',
            $messageNdx,
            0,
        );
        if ($att === null) {
            return Response::error(
                'NOT_FOUND',
                "Attachment {$attachmentNdx} not found for message {$messageNdx}",
                404,
            );
        }

        $service = new AttachmentService($this->db, $this->dsPath, $this->tables);
        $filePath = $service->getFilePath($att);
        if (!is_file($filePath)) {
            return Response::error('NOT_FOUND', 'Attachment file missing on disk', 404);
        }

        $this->streamFile(
            $filePath,
            (string) $att['mime_type'],
            (string) $att['name'],
            (int) $att['file_size'],
        );

        // streamFile exits — pro type safety
        return Response::success(null, 204);
    }

    private function streamFile(string $filePath, string $mimeType, string $displayName, int $fileSize): never
    {
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: ' . $mimeType);
        $asciiName = preg_replace('/[^\x20-\x7E]/', '_', $displayName);
        header("Content-Disposition: attachment; filename=\"{$asciiName}\"; filename*=UTF-8''" . rawurlencode($displayName));
        header('Content-Length: ' . $fileSize);
        header('Cache-Control: no-store, no-cache, must-revalidate');
        readfile($filePath);
        exit;
    }

    // -------------------------------------------------------------------
    // POST /{ndx}/result
    // -------------------------------------------------------------------

    /**
     * Uloží výsledek analýzy (kontrakt v4, message-centricky) — tělo drží
     * {@see AnalysisResultWriter::storeResult()}; tady auth, claim token
     * a mapování výjimky na Response. `created_by` = uživatel `_ai_analyzer`.
     */
    public function result(AuthContext $auth, Request $request, int $messageNdx): Response
    {
        $authError = $this->verifyAnalyzerAuth($auth);
        if ($authError !== null) {
            return $authError;
        }

        $claim = $this->validateClaimToken($messageNdx, $request);
        if ($claim instanceof Response) {
            return $claim;
        }

        $body = $request->getBody() ?? [];
        try {
            $analysisNdx = $this->services()->results->storeResult(
                $messageNdx,
                (int) $claim['id'],
                $body,
                $auth->userId,
            );
        } catch (AnalysisResultException $e) {
            return Response::error($e->errorCode, $e->getMessage(), $e->httpStatus, $e->details);
        }

        return Response::success([
            'analysis_ndx' => $analysisNdx,
        ], 201);
    }

    /**
     * Validace canonicalu návrhu — delegát na
     * {@see AnalysisResultWriter::validateCanonical()}; jednotkové testy
     * ho volají reflexí pod tímto názvem.
     *
     * @param array<string, mixed>|null $extractedJson
     * @return array{0: ?string, 1: bool}  [jsonForDb, isValid]
     */
    private function validateAndStoreCanonical(?array $extractedJson, string $docType): array
    {
        return $this->services()->results->validateCanonical($extractedJson, $docType);
    }

    /**
     * Delegáty na {@see AnalysisResultWriter} pro jednotkové testy, které
     * klasifikaci, titulek a seznam typů volají reflexí na controlleru.
     *
     * @param array<string, mixed> $body
     */
    private function applyMessageClassification(\Dibi\Connection $dibi, int $messageNdx, array $body): void
    {
        $this->services()->results->applyMessageClassification($dibi, $messageNdx, $body);
    }

    /**
     * @param array<string, mixed>      $body
     * @param array<string, mixed>|null $canonical
     */
    private function applyMessageTitle(
        \Dibi\Connection $dibi,
        int $messageNdx,
        array $body,
        ?array $canonical,
        ?string $proposedType,
        ?int $profileNdx = null,
    ): void {
        $this->services()->results->applyMessageTitle($dibi, $messageNdx, $body, $canonical, $proposedType, $profileNdx);
    }

    /** @return list<string> */
    private function knownPrimaryTypes(): array
    {
        return $this->services()->results->knownPrimaryTypes();
    }

    // -------------------------------------------------------------------
    // POST /{ndx}/failed
    // -------------------------------------------------------------------

    /**
     * Uloží neúspěch analýzy: failed analysis record, uvolnění claimu,
     * analysis_state 20→10 (retryable) nebo 20→70 (permanent). docState se
     * nemění. Spec §3.6. Tělo drží {@see AnalysisResultWriter::storeFailure()}.
     */
    public function failed(AuthContext $auth, Request $request, int $messageNdx): Response
    {
        $authError = $this->verifyAnalyzerAuth($auth);
        if ($authError !== null) {
            return $authError;
        }

        $claim = $this->validateClaimToken($messageNdx, $request);
        if ($claim instanceof Response) {
            return $claim;
        }

        $body = $request->getBody() ?? [];
        $errorType = trim((string) ($body['error_type'] ?? 'ai_error'));
        $errorMessage = trim((string) ($body['error_message'] ?? ''));
        $retryable = (bool) ($body['retryable'] ?? false);
        $tokensUsed = isset($body['tokens_used']) ? (int) $body['tokens_used'] : null;

        try {
            $newState = $this->services()->results->storeFailure(
                $messageNdx,
                (int) $claim['id'],
                $errorType,
                $errorMessage,
                $retryable,
                $tokensUsed,
                isset($body['model_name']) ? (string) $body['model_name'] : null,
                isset($body['prompt_version']) ? (string) $body['prompt_version'] : null,
                $auth->userId,
            );
        } catch (AnalysisResultException $e) {
            return Response::error($e->errorCode, $e->getMessage(), $e->httpStatus, $e->details);
        }

        return Response::success([
            'message_ndx' => $messageNdx,
            'retryable' => $retryable,
            'new_state' => $newState === AnalysisStates::QUEUED ? 'queued' : 'ai_failed',
        ], 200);
    }

    // -------------------------------------------------------------------
    // POST /_mail/messages/{ndx}/reanalyze
    // -------------------------------------------------------------------

    /**
     * UI akce "Znovu analyzovat". Spec §4.
     *
     * Auth: běžný přihlášený uživatel (UI), ne _ai_analyzer.
     *
     * Validace: analysis_state ∈ {30 Analyzováno, 70 Analýza selhala}
     * a zpráva není v Archivu/Koši. Zprávu s aplikovaným návrhem
     * (poslední analýza resolution=40 + živý target) reanalyzovat nelze —
     * 409, nejdřív unapply. Historie analýz se nemění (superseded jako
     * koncept zanikl — „aktuální návrh" je implicitně poslední běh).
     * Nastaví analysis_state→10, needs_reanalysis=true, profile_override
     * (volitelné). docState se nemění.
     */
    public function reanalyze(AuthContext $auth, Request $request, int $messageNdx): Response
    {
        if (!$auth->isAuthenticated) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
        }

        $body = $request->getBody() ?? [];
        $profileOverrideNdx = isset($body['profile_override_ndx']) && (int) $body['profile_override_ndx'] > 0
            ? (int) $body['profile_override_ndx']
            : null;

        $dibi = $this->db->getDibiConnection();
        $dibi->begin();
        try {
            $msg = $dibi->fetch(
                'SELECT id, docState, analysis_state, target_row, mailbox,'
                . ' ai_analysis_enabled FROM %n WHERE id = %i',
                self::MESSAGES_TABLE,
                $messageNdx,
            );
            if ($msg === null) {
                $dibi->rollback();
                return Response::error('NOT_FOUND', "Message {$messageNdx} not found", 404);
            }

            $analysisState = (int) $msg['analysis_state'];
            $docState = (int) $msg['docState'];
            if (($analysisState !== self::ANALYSIS_ANALYZED && $analysisState !== self::ANALYSIS_FAILED)
                || $docState === self::DOC_STATE_ARCHIVED
                || $docState === self::DOC_STATE_TRASH
            ) {
                $dibi->rollback();
                return Response::error(
                    'INVALID_STATE',
                    'Reanalyze requires analysis_state 30 (Analyzováno) or 70 (Analýza selhala)'
                        . ' and a message outside Archive/Trash',
                    409,
                );
            }

            // Aplikovaný návrh s živým targetem nelze reanalyzovat —
            // nejdřív unapply (jinak by lineage doklad ↔ zpráva osiřela).
            $targetRow = isset($msg['target_row']) ? (int) $msg['target_row'] : 0;
            if ($targetRow > 0) {
                $latest = $dibi->fetch(
                    'SELECT resolution FROM %n WHERE message = %i AND status = %i'
                    . ' ORDER BY analyzed_at DESC, id DESC LIMIT 1',
                    self::ANALYSES_TABLE,
                    $messageNdx,
                    2,
                );
                if ($latest !== null
                    && (int) ($latest['resolution'] ?? 0) === MessageProposalApplier::RESOLUTION_APPLIED
                ) {
                    $dibi->rollback();
                    return Response::error(
                        'INVALID_STATE',
                        'Message has an applied proposal with a live target — unapply first',
                        409,
                    );
                }
            }

            // Validuj profile override (pokud zadán)
            if ($profileOverrideNdx !== null) {
                $profile = $dibi->fetch(
                    'SELECT id FROM %n WHERE id = %i AND is_active = %i',
                    self::PROFILES_TABLE,
                    $profileOverrideNdx,
                    1,
                );
                if ($profile === null) {
                    $dibi->rollback();
                    return Response::error(
                        'INVALID_PROFILE',
                        "Profile {$profileOverrideNdx} not found or inactive",
                        422,
                    );
                }
            }

            $now = date('Y-m-d H:i:s');

            // Vrátit analýzu do fronty — docState (workflow) zůstává
            $update = [
                'analysis_state' => self::ANALYSIS_QUEUED,
                'needs_reanalysis' => 1,
                'profile_override' => $profileOverrideNdx,
                'modified' => $now,
            ];

            // Zpráva ze schránky s vypnutou analýzou: explicitní záměr
            // uživatele přebíjí default schránky — bez message-level
            // ai_analysis_enabled=1 by ji /queue nikdy nevydal.
            $enabled = $msg['ai_analysis_enabled'] ?? null;
            if ($enabled === null || !(int) $enabled) {
                $mb = $dibi->fetch(
                    'SELECT ai_analysis_disabled FROM %n WHERE id = %i',
                    self::MAILBOXES_TABLE,
                    (int) $msg['mailbox'],
                );
                if ($mb !== null && (int) $mb['ai_analysis_disabled'] === 1) {
                    $update['ai_analysis_enabled'] = 1;
                }
            }

            $dibi->update(self::MESSAGES_TABLE, $update)
                ->where('id = %i', $messageNdx)->execute();

            $dibi->commit();
        } catch (\Throwable $e) {
            $dibi->rollback();
            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }

        return Response::success([
            'message_ndx' => $messageNdx,
            'profile_override_ndx' => $profileOverrideNdx,
        ]);
    }

    // -------------------------------------------------------------------
    // POST /_mail/messages/{ndx}/apply  +  /reject  +  /unapply
    // -------------------------------------------------------------------
    //
    // Pro UI akce "Použít" / "Zamítnout" nad dokumentovým návrhem poslední
    // analýzy zprávy. Verdikt se zapisuje na řádek analýzy (resolution),
    // lineage na zprávu (target_*) a doklad (source_message) — viz
    // MessageProposalApplier.

    public function applyMessage(AuthContext $auth, Request $request, int $messageNdx): Response
    {
        if (!$auth->isAuthenticated) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
        }

        // Apply core lives in the shared service so this HTTP endpoint and
        // the MCP mail_draft_document tool run one code path. The controller
        // only parses the body and maps the outcome back onto a Response.
        $body = $request->getBody();
        $body = is_array($body) ? $body : [];
        $service = $this->buildProposalApplier();
        $outcome = $service->apply(
            $messageNdx,
            $auth->userId,
            array_key_exists('_resolve', $body) && is_array($body['_resolve']) ? $body['_resolve'] : null,
            is_array($body['applyOptions'] ?? null) ? $body['applyOptions'] : [],
        );

        return $this->outcomeToResponse($outcome);
    }

    /**
     * Map a {@see ProposalApplyOutcome} onto Response payloads / HTTP
     * statuses.
     */
    private function outcomeToResponse(ProposalApplyOutcome $outcome): Response
    {
        if (!$outcome->ok) {
            return Response::error(
                $outcome->errorCode ?? 'INTERNAL_ERROR',
                $outcome->errorMessage ?? 'Apply failed',
                $outcome->statusCode,
                $outcome->canonical !== null ? ['canonical' => $outcome->canonical] : [],
            );
        }

        $payload = [
            'savedDocId'  => (int) ($outcome->savedDocId ?? 0),
            'messageNdx'  => $outcome->messageNdx,
            'analysisNdx' => $outcome->analysisNdx,
        ];
        if ($outcome->idempotent) {
            $payload['idempotent'] = true;
        } elseif ($outcome->recovered) {
            $payload['recovered'] = true;
        } else {
            $payload['canonical'] = $outcome->canonical;
        }
        return Response::success($payload);
    }

    public function rejectMessage(AuthContext $auth, Request $request, int $messageNdx): Response
    {
        if (!$auth->isAuthenticated) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
        }

        $body = $request->getBody() ?? [];
        $reason = trim((string) ($body['reason'] ?? ''));
        if ($reason === '') {
            return Response::error(
                'VALIDATION_ERROR',
                'reason is required',
                422,
                [['field' => 'reason']],
            );
        }

        $outcome = $this->buildProposalApplier()->reject($messageNdx, $auth->userId, $reason);
        if (!$outcome->ok) {
            return Response::error(
                $outcome->errorCode ?? 'INTERNAL_ERROR',
                $outcome->errorMessage ?? 'Reject failed',
                $outcome->statusCode,
            );
        }

        return Response::success([
            'messageNdx'  => $outcome->messageNdx,
            'analysisNdx' => $outcome->analysisNdx,
            'resolution'  => MessageProposalApplier::RESOLUTION_REJECTED,
        ]);
    }

    /**
     * Průběžné uložení rozhodnutí z review modalu (resolve badge popovery)
     * na řádek poslední úspěšné analýzy — `user_actions_json` (#76,
     * tasks/mail-review-decisions-persist.md). Body `{"_resolve": {cesta:
     * userAction}}` — vždy **celá** mapa (last-write-wins), prázdná = smazat.
     * Odpověď vrací mapu tak, jak byla uložena (po sanitizaci) — nic se
     * nečte zpět z DB. Guardy (404/409) viz
     * {@see MessageProposalApplier::saveUserActions}.
     */
    public function saveDecisions(AuthContext $auth, Request $request, int $messageNdx): Response
    {
        if (!$auth->isAuthenticated) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
        }

        $body = $request->getBody();
        $body = is_array($body) ? $body : [];
        if (!array_key_exists('_resolve', $body) || !is_array($body['_resolve'])) {
            return Response::error(
                'VALIDATION_ERROR',
                '_resolve must be an object',
                422,
                [['field' => '_resolve']],
            );
        }

        $flat = MessageProposalApplier::sanitizeUserActions($body['_resolve']);
        $outcome = $this->buildProposalApplier()->saveUserActions($messageNdx, $auth->userId, $flat);
        if (!$outcome->ok) {
            return Response::error(
                $outcome->errorCode ?? 'INTERNAL_ERROR',
                $outcome->errorMessage ?? 'Decisions save failed',
                $outcome->statusCode,
            );
        }

        return Response::success([
            'messageNdx'  => $outcome->messageNdx,
            'analysisNdx' => $outcome->analysisNdx,
            'userActions' => self::userActionsPayload($flat),
        ]);
    }

    /**
     * Flat mapa rozhodnutí pro JSON odpověď: prázdná mapa jako `{}`, ne `[]`
     * (PHP `json_encode([])` dá pole; frontend dělá `?? {}` + Object.keys).
     *
     * @param array<string, string> $flat
     */
    private static function userActionsPayload(array $flat): array|\stdClass
    {
        return $flat === [] ? new \stdClass() : $flat;
    }

    /**
     * Undo apply: cílová entita do Koše, resolution analýzy → NULL, zpráva
     * 40→20. Viz {@see MessageProposalApplier::unapply}.
     */
    public function unapplyMessage(AuthContext $auth, Request $request, int $messageNdx): Response
    {
        if (!$auth->isAuthenticated) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
        }

        $outcome = $this->buildProposalApplier()->unapply($messageNdx, $auth->userId);
        if (!$outcome->ok) {
            return Response::error(
                $outcome->errorCode ?? 'INTERNAL_ERROR',
                $outcome->errorMessage ?? 'Unapply failed',
                $outcome->statusCode,
            );
        }

        return Response::success([
            'messageNdx'   => $outcome->messageNdx,
            'analysisNdx'  => $outcome->analysisNdx,
            'trashedDocId' => (int) ($outcome->savedDocId ?? 0),
        ]);
    }

    /**
     * Postaví TableGateway nad `docs_core_heads` pro přesun cílového dokladu do
     * Koše přes Document flow (paralela k
     * `FormController::applyStateTransitionViaDocument`). Vrací null, když
     * definice tabulky chybí (modul docs vypnutý).
     */
    private function buildHeadsGateway(): ?TableGateway
    {
        $def = $this->tables[self::HEADS_TABLE] ?? null;
        if ($def === null) {
            return null;
        }
        return new TableGateway(
            self::HEADS_TABLE,
            $this->db->getDibiConnection(),
            $this->documentRegistry,
            $def->childTables,
            $this->configRuntime,
            $this->config,
            $this->eventDispatcher,
            $def->docStates,
            $def,
        );
    }

    /**
     * Sestaví sdílený apply/reject/unapply servis včetně mapy target
     * applierů (registrace napevno ve wiringu, vzor FeedSources — žádný
     * plugin registr). Docs target jede interně přes exchange
     * DocumentApplier, `registry` přes RegistryApplier (jen když je modul
     * base.registry aktivní — poznáme podle přítomnosti tabulky
     * v definicích).
     */
    private function buildProposalApplier(): MessageProposalApplier
    {
        $targetAppliers = [];
        if (isset($this->tables[self::REGISTRY_TABLE])) {
            $dibi = $this->db->getDibiConnection();
            $targetAppliers[PrimaryTypes::TARGET_REGISTRY] = new RegistryApplier(
                $this->db,
                $this->documentRegistry,
                new AttachmentService($this->db, $this->dsPath, $this->tables),
                $this->configRuntime,
                new PartyResolver($dibi, new OwnCompanyResolver($dibi)),
            );
        }

        return new MessageProposalApplier(
            $this->db,
            $this->applier,
            $this->enricher,
            $this->configRuntime,
            $targetAppliers,
            $this->buildHeadsGateway(),
        );
    }

    /**
     * Read-only preview of the message's document proposal (latest
     * successful analysis) — returns enriched canonical with `_resolve`
     * populated for the UI split-view modal. Server-side injection of
     * `source.message` + informative `applyOptions` mirrors
     * {@see applyMessage} so the preview reflects how an apply would run
     * (without doing the side-creates/save).
     *
     * For runs whose canonical was wrapped during /result validation,
     * returns the wrapper directly so the UI can render its dedicated
     * error view. Attachments = **all** content attachments of the message
     * (D10 z mail-message-centric).
     */
    public function previewMessage(AuthContext $auth, Request $request, int $messageNdx): Response
    {
        if (!$auth->isAuthenticated) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
        }

        $message = $this->db->fetchRow(
            'SELECT * FROM %n WHERE id = %i',
            self::MESSAGES_TABLE, $messageNdx,
        );
        if ($message === null) {
            return Response::error('NOT_FOUND', "Message {$messageNdx} not found", 404);
        }

        $analysis = $this->buildProposalApplier()->latestSuccessfulAnalysis($messageNdx);
        if ($analysis === null) {
            return Response::error('NO_ANALYSIS', "Message {$messageNdx} has no successful analysis", 404);
        }
        $analysisNdx = (int) $analysis['id'];

        if ($analysis['canonical_json'] === null || $analysis['canonical_json'] === '') {
            return Response::error('NO_PROPOSAL', 'Latest analysis produced no document proposal', 404);
        }

        $canonicalJson = json_decode((string) $analysis['canonical_json'], true);
        if (!is_array($canonicalJson)) {
            return Response::error('CORRUPTED_DATA', 'canonical_json cannot be parsed', 500);
        }

        $attachments = $this->loadContentAttachmentsMeta($message);

        $base = [
            'messageNdx'   => $messageNdx,
            'analysisNdx'  => $analysisNdx,
            'proposedType' => $analysis['proposed_type'] !== null ? (string) $analysis['proposed_type'] : null,
            'confidence'   => $analysis['confidence'] !== null ? (float) $analysis['confidence'] : null,
            'resolution'   => $analysis['resolution'] !== null ? (int) $analysis['resolution'] : null,
            'attachments'  => $attachments,
            // Uložená rozhodnutí z review (#76) — ve všech větvích odpovědi
            // (ai_failed, registry, bez applieru, docs). `?? null`: sloupec
            // chybí na DS před ds-upgrade, preview nesmí spadnout.
            'userActions'  => self::userActionsPayload(
                MessageProposalApplier::decodeUserActions($analysis['user_actions_json'] ?? null),
            ),
            // Zdrojová zpráva pro hlavičku review modalu
            // (tasks/mail-source-message-link.md D1, D3) — rovněž ve všech
            // větvích odpovědi.
            'message'      => $this->sourceMessageBlock($messageNdx, $message),
        ];

        // ai_failed wrapper → return it for the special UI render path
        if (isset($canonicalJson['_validationError'])) {
            return Response::success($base + [
                'aiFailed' => true,
                'wrapper'  => $canonicalJson,
            ]);
        }

        // Registry target: canonical se vrací přímo — source injection,
        // enrichment i applier->preview (_resolve) jsou docs-specifika,
        // registry review nemá resolve panel (design §7.8). `target` klíč
        // dává frontendu branch pro RegistryExtractedPreview.
        $proposedType = (string) ($analysis['proposed_type'] ?? '');
        if (PrimaryTypes::targetFor($this->configRuntime, $proposedType) === PrimaryTypes::TARGET_REGISTRY) {
            return Response::success($base + [
                'aiFailed'  => false,
                'canonical' => $canonicalJson,
                'target'    => PrimaryTypes::TARGET_REGISTRY,
            ]);
        }

        // Without applier wired (e.g. ConfigRuntime missing), return raw
        // canonical without resolve — the UI can still render the read-only
        // view, just without resolve badges.
        if ($this->applier === null) {
            return Response::success($base + [
                'aiFailed'  => false,
                'canonical' => $canonicalJson,
            ]);
        }

        // Server-controlled injection — applier preview is informative, so
        // applyOptions are advisory (they would only matter for /apply).
        $canonical = $canonicalJson;
        $canonical['source'] = is_array($canonical['source'] ?? null) ? $canonical['source'] : [];
        $canonical['source']['message'] = $messageNdx;
        if (empty($canonical['source']['kind'])) {
            $canonical['source']['kind'] = 'aiExtraction';
        }
        $canonical['applyOptions'] = [
            'autoCreateMode' => 'safe',
            'targetDocState' => 10,
        ];

        // Fresh obohacení (historie + obsahové štítky, bez LLM) — přepíše
        // persistnutý enrichment blok aktuálním stavem DB; fresh re-check
        // pravidla IČO má přednost před persistnutým LLM štítkem (D16).
        // Selhání preview neblokuje.
        if ($this->enricher !== null) {
            try {
                $canonical = $this->enricher->enrichFresh($canonical);
            } catch (\Throwable $e) {
                ErrorLogger::logException($e, 'AnalysisController::previewMessage row history enrichment failed');
            }
        }

        // Uložená rozhodnutí z review (#76) do `_resolve` — až po
        // enrichmentu, aby piny přežily. Náhled tak počítá s volbou kódu
        // DPH, místa a režimu (tasks/exchange-preview-vat-choices.md D15);
        // strany a položky resolve nečte, pro ně je to neutrální.
        $savedActions = MessageProposalApplier::decodeUserActions($analysis['user_actions_json'] ?? null);
        if ($savedActions !== []) {
            $canonical['_resolve'] = MessageProposalApplier::mergeUserActions(
                is_array($canonical['_resolve'] ?? null) ? $canonical['_resolve'] : [],
                MessageProposalApplier::expandUserActions($savedActions),
            );
        }

        $result = $this->applier->preview($canonical);
        if (!$result->success) {
            // preview() should always succeed (resolve issues live in
            // _resolve.issues, not errorCode), but propagate defensively.
            return Response::error(
                $result->errorCode ?? 'INTERNAL_ERROR',
                $result->errorMessage ?? 'Preview failed',
                $result->statusCode,
                ['canonical' => $result->canonical],
            );
        }

        return Response::success($base + [
            'aiFailed'  => false,
            'canonical' => $result->canonical,
        ]);
    }

    /**
     * Metadata zdrojové zprávy pro subtitle review modalu: kód plný i krátký
     * (D8), datum přijetí formátované serverem (`j. n. Y H:i` jako
     * IncomingMessagesViewer::formatDateTime) a odesílatel — `sender_name`,
     * jinak `sender_email`, jinak null (stejné pořadí jako t2 řádku Došlé
     * pošty). Prázdný `message_id` → `code` i `codeShort` prázdný řetězec,
     * frontend kód nevykreslí a zbytek řádku ano. Sloupce čte přes `?? null`:
     * starší řádky je mít nemusí.
     *
     * @param array<string, mixed> $message
     * @return array{ndx: int, code: string, codeShort: string, receivedAt: ?string, sender: ?string}
     */
    private function sourceMessageBlock(int $messageNdx, array $message): array
    {
        $code = trim((string) ($message['message_id'] ?? ''));
        $senderName = trim((string) ($message['sender_name'] ?? ''));
        $senderEmail = trim((string) ($message['sender_email'] ?? ''));

        return [
            'ndx'        => $messageNdx,
            'code'       => $code,
            'codeShort'  => IncomingMessageCode::short($code),
            'receivedAt' => $this->formatReceivedAt($message['received_at'] ?? null),
            'sender'     => $senderName !== '' ? $senderName : ($senderEmail !== '' ? $senderEmail : null),
        ];
    }

    private function formatReceivedAt(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('j. n. Y H:i');
        }
        $ts = is_string($value) ? strtotime($value) : false;
        return $ts !== false ? date('j. n. Y H:i', $ts) : null;
    }

    /**
     * Fetch metadata of all content attachments of a message for the UI PDF
     * viewer panel — everything on the message except the raw .eml source
     * and deleted files (D10: karta/preview = všechny obsahové přílohy).
     *
     * @param array<string, mixed> $message
     * @return array<int, array{ndx: int, filename: string, mime_type: string, size_bytes: int}>
     */
    private function loadContentAttachmentsMeta(array $message): array
    {
        $rawSourceNdx = isset($message['raw_source_attachment'])
            ? (int) $message['raw_source_attachment']
            : 0;
        $rows = $this->db->fetchAll(
            'SELECT id, name, mime_type, file_size
             FROM %n
             WHERE table_id = %i AND record_id = %i AND id != %i AND is_deleted = %i
             ORDER BY att_order ASC, name ASC',
            self::ATTACHMENTS_TABLE, self::MAIL_TABLE_ID, (int) $message['id'], $rawSourceNdx, 0,
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'ndx'        => (int) $row['id'],
                'filename'   => (string) $row['name'],
                'mime_type'  => (string) $row['mime_type'],
                'size_bytes' => (int) $row['file_size'],
            ];
        }
        return $out;
    }
}

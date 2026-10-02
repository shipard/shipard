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
use Shipard\Core\Security\DsSecretCipher;
use Shipard\Core\Security\Exception\InvalidCiphertextException;
use Shipard\Core\Security\Exception\SecretsKeyInsecureException;
use Shipard\Core\Security\Exception\SecretsKeyMissingException;
use Shipard\Module\Core\Attachments\AttachmentService;
use Shipard\Module\Base\Registry\RegistryApplier;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Core\Exchange\Enrich\RowEnrichmentPipeline;
use Shipard\Module\Core\Exchange\Resolve\PartyResolver;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;
use Shipard\Module\Core\Ai\AIBackendDocument;
use Shipard\Module\Core\Mail\AIAnalyzerProvisioner;
use Shipard\Module\Core\Mail\MessagePartnerWriter;
use Shipard\Module\Core\Mail\MessageProposalApplier;
use Shipard\Module\Core\Mail\MessageTitleComposer;
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
 * Spec: tasks/mail-phase3a.md §3.
 */
class AnalysisController
{
    public const MAIL_TABLE_ID = 303;

    private const MESSAGES_TABLE = 'core_mail_incoming_messages';
    private const MAILBOXES_TABLE = 'core_mail_mailboxes';
    private const ANALYSES_TABLE = 'core_mail_message_analyses';
    private const BACKENDS_TABLE = 'core_ai_backends';
    private const PROFILES_TABLE = 'core_mail_ai_profiles';
    private const CLAIMS_TABLE = 'core_mail_analysis_claims';
    private const ATTACHMENTS_TABLE = 'core_attachments_files';
    private const HEADS_TABLE = 'docs_core_heads';
    private const REGISTRY_TABLE = 'base_registry_documents';

    /** Kontrakt registry extrakce (schéma v modules/base/registry/schemas). */
    private const REGISTRY_FORMAT_ID = 'shpd.registry.document';
    private const REGISTRY_FORMAT_VERSION = '1';

    // Workflow stavy zprávy (core.mail.docStatesIncoming) — pipeline na ně
    // sahá jediným místem: result s dokumenty posouvá Novou na K řešení.
    private const DOC_STATE_NEW = 10;
    private const DOC_STATE_IN_PROGRESS = 20;
    private const DOC_STATE_IN_PROGRESS_MAIN = 2;
    private const DOC_STATE_ARCHIVED = 80;
    private const DOC_STATE_TRASH = 90;

    // Pipeline status analýzy (core.mail.analysisStates) — ortogonální
    // ke workflow, řídí ho výhradně pipeline + reanalyze.
    public const ANALYSIS_NONE = 0;
    public const ANALYSIS_QUEUED = 10;
    public const ANALYSIS_ANALYZING = 20;
    public const ANALYSIS_ANALYZED = 30;
    public const ANALYSIS_FAILED = 70;

    /**
     * preprocess_state (core.mail.preprocessStates), ve kterých zpráva do
     * fronty nepatří — technické předzpracování ještě nedoběhlo (gate,
     * tasks/mail-preprocess.md D9). Ortogonální k analysis_state.
     */
    public const PREPROCESS_BLOCKING_STATES = [10, 20];

    private const DEFAULT_LEASE_SECONDS = 300;
    private const MIN_LEASE_SECONDS = 60;
    private const MAX_LEASE_SECONDS = 900;

    /** Lazy validator registry canonicalu (viz registrySchemaValidator()). */
    private ?SchemaValidator $registrySchemaValidator = null;

    /** Lazy zápis partnera zprávy z canonicalu (viz partnerWriter()). */
    private ?MessagePartnerWriter $partnerWriter = null;

    /**
     * Lazy fallback titulku zprávy z canonicalu per AI profil běhu
     * (klíč 0 = výchozí profil DS) — viz titleComposer().
     *
     * @var array<int, MessageTitleComposer>
     */
    private array $titleComposers = [];

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

    private function clampLeaseSeconds(?int $requested): int
    {
        $seconds = $requested ?? self::DEFAULT_LEASE_SECONDS;
        return max(self::MIN_LEASE_SECONDS, min(self::MAX_LEASE_SECONDS, $seconds));
    }

    // -------------------------------------------------------------------
    // GET /queue
    // -------------------------------------------------------------------

    /**
     * Vrátí zprávy připravené k analýze. Spec §3.1.
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

        // Najdi zprávy ve frontě (analysis_state=10) mimo Archiv/Koš,
        // ai_analysis_enabled NOT FALSE (NULL nebo true), schránka bez
        // ai_analysis_disabled (explicitní message-level enabled=1 flag
        // schránky přebíjí), bez aktivní claim. SQL inspirace: NOT EXISTS
        // na claims s released=0 a expires_at v budoucnu. docState se
        // jinak nekontroluje — workflow je ortogonální.
        $now = date('Y-m-d H:i:s');
        $rows = $this->db->fetchAll(
            'SELECT m.id AS ndx, m.received_at, m.subject, m.sender_email,
                    m.profile_override, m.raw_source_attachment
               FROM %n m
               JOIN %n mb ON mb.id = m.mailbox
              WHERE m.analysis_state = %i
                AND m.docState NOT IN %in
                AND m.preprocess_state NOT IN %in
                AND (m.ai_analysis_enabled IS NULL OR m.ai_analysis_enabled = %i)
                AND (mb.ai_analysis_disabled = %i OR m.ai_analysis_enabled = %i)
                AND NOT EXISTS (
                    SELECT 1 FROM %n c
                     WHERE c.message = m.id
                       AND c.released = %i
                       AND c.expires_at > %s
                )
              ORDER BY m.received_at ASC, m.id ASC
              LIMIT %i',
            self::MESSAGES_TABLE,
            self::MAILBOXES_TABLE,
            self::ANALYSIS_QUEUED,
            [self::DOC_STATE_ARCHIVED, self::DOC_STATE_TRASH],
            self::PREPROCESS_BLOCKING_STATES,
            1,
            0,
            1,
            self::CLAIMS_TABLE,
            0,
            $now,
            $limit,
        );

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

        $totalAvailable = (int) $this->db->fetchSingle(
            'SELECT COUNT(*) FROM %n m
               JOIN %n mb ON mb.id = m.mailbox
              WHERE m.analysis_state = %i
                AND m.docState NOT IN %in
                AND m.preprocess_state NOT IN %in
                AND (m.ai_analysis_enabled IS NULL OR m.ai_analysis_enabled = %i)
                AND (mb.ai_analysis_disabled = %i OR m.ai_analysis_enabled = %i)
                AND NOT EXISTS (
                    SELECT 1 FROM %n c
                     WHERE c.message = m.id
                       AND c.released = %i
                       AND c.expires_at > %s
                )',
            self::MESSAGES_TABLE,
            self::MAILBOXES_TABLE,
            self::ANALYSIS_QUEUED,
            [self::DOC_STATE_ARCHIVED, self::DOC_STATE_TRASH],
            self::PREPROCESS_BLOCKING_STATES,
            1,
            0,
            1,
            self::CLAIMS_TABLE,
            0,
            $now,
        );

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
     * docState (workflow) se nemění. Spec §3.2.
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
        $leaseSeconds = $this->clampLeaseSeconds(
            isset($body['lease_seconds']) ? (int) $body['lease_seconds'] : null,
        );

        $dibi = $this->db->getDibiConnection();
        $dibi->begin();
        try {
            // FOR UPDATE serializuje souběžné claim() přes řádek zprávy —
            // bez tohoto by dva analyzéry mohli oba projít SELECT a oba
            // INSERT do claims (tabulka nemá partial unique). Spec §3.2
            // "Atomicky" + §2.4 popis invariantu max-jedna-aktivní-claim.
            $msgRow = $dibi->fetch(
                'SELECT id, analysis_state, preprocess_state, profile_override FROM %n WHERE id = %i FOR UPDATE',
                self::MESSAGES_TABLE,
                $messageNdx,
            );
            if ($msgRow === null) {
                $dibi->rollback();
                return Response::error('NOT_FOUND', "Message {$messageNdx} not found", 404);
            }
            if ((int) $msgRow['analysis_state'] !== self::ANALYSIS_QUEUED) {
                $dibi->rollback();
                return Response::error(
                    'INVALID_STATE',
                    'Message is not queued for analysis (analysis_state != 10)',
                    409,
                );
            }
            // Gate předzpracování i na claimu — /queue zprávu nevydá, ale
            // analyzer může claimovat ze staršího snapshotu fronty.
            if (in_array((int) ($msgRow['preprocess_state'] ?? 0), self::PREPROCESS_BLOCKING_STATES, true)) {
                $dibi->rollback();
                return Response::error(
                    'INVALID_STATE',
                    'Message is being preprocessed (preprocess_state in 10/20)',
                    409,
                );
            }

            $now = date('Y-m-d H:i:s');
            $activeClaim = $dibi->fetch(
                'SELECT id FROM %n WHERE message = %i AND released = %i AND expires_at > %s LIMIT 1',
                self::CLAIMS_TABLE,
                $messageNdx,
                0,
                $now,
            );
            if ($activeClaim !== null) {
                $dibi->rollback();
                return Response::error('ALREADY_CLAIMED', 'Message already has an active claim', 409);
            }

            // Vyber profil + backend
            $profileNdx = $requestedProfile
                ?? (isset($msgRow['profile_override']) ? (int) $msgRow['profile_override'] : null);
            $profile = $this->resolveProfile($profileNdx);
            if ($profile === null) {
                $dibi->rollback();
                return Response::error(
                    'NO_PROFILE',
                    'No active profile available (default missing or requested profile invalid)',
                    409,
                );
            }
            $backend = $this->resolveBackend((int) $profile['backend']);
            if ($backend === null) {
                $dibi->rollback();
                return Response::error(
                    'NO_BACKEND',
                    'Profile references a backend that is missing or inactive',
                    409,
                );
            }

            // Decrypt api_key přes AIBackendDocument — single source of truth
            try {
                $cipher = DsSecretCipher::forConfig($this->config);
            } catch (SecretsKeyMissingException | SecretsKeyInsecureException $e) {
                $dibi->rollback();
                return Response::error(
                    'SECRETS_UNAVAILABLE',
                    'Server cannot decrypt backend API key: ' . $e->getMessage(),
                    500,
                );
            }
            $backendDoc = new AIBackendDocument();
            $backendDoc->setSecretCipher($cipher);
            try {
                $apiKey = $backendDoc->decryptApiKey($backend);
            } catch (InvalidCiphertextException $e) {
                $dibi->rollback();
                return Response::error(
                    'BACKEND_KEY_CORRUPTED',
                    'Stored API key cannot be decrypted (corrupted or wrong secrets.key)',
                    500,
                );
            }
            if ($apiKey === null || $apiKey === '') {
                $dibi->rollback();
                return Response::error(
                    'BACKEND_KEY_MISSING',
                    "Backend '{$backend['backend_id']}' has no API key set. Run ai-analyzer-set-key.",
                    409,
                );
            }

            // Vytvoř claim
            $claimToken = $this->generateClaimToken();
            $expiresAt = date('Y-m-d H:i:s', time() + $leaseSeconds);
            $dibi->insert(self::CLAIMS_TABLE, [
                'message' => $messageNdx,
                'analyzer_id' => $analyzerId,
                'claim_token' => $claimToken,
                'claimed_at' => $now,
                'expires_at' => $expiresAt,
                'released' => 0,
            ])->execute();

            // Přepni analýzu na "Analyzuje se" — docState zůstává
            $dibi->update(self::MESSAGES_TABLE, [
                'analysis_state' => self::ANALYSIS_ANALYZING,
                'modified' => $now,
            ])->where('id = %i', $messageNdx)->execute();

            $dibi->commit();
        } catch (\Throwable $e) {
            $dibi->rollback();
            // Plaintext API key žije v této metodě v paměti — exception message
            // nesmí prosáknout do klienta (spec §10 dec.2). Detail loguj server-side.
            ErrorLogger::warn('AnalysisController::claim failed', [
                'error' => $e->getMessage(),
            ]);
            return Response::error('INTERNAL_ERROR', 'Internal server error during claim', 500);
        }

        $response = Response::success([
            'claim_token' => $claimToken,
            'expires_at' => $expiresAt,
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
                'api_key' => $apiKey,
                'base_url' => $backend['base_url'] !== null ? (string) $backend['base_url'] : null,
                'max_tokens' => (int) $backend['max_tokens'],
                'temperature' => (float) $backend['temperature'],
            ],
        ]);

        return $this->withNoStoreHeaders($response);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveProfile(?int $requestedNdx): ?array
    {
        if ($requestedNdx !== null && $requestedNdx > 0) {
            $row = $this->db->fetchRow(
                'SELECT * FROM %n WHERE id = %i AND is_active = %i LIMIT 1',
                self::PROFILES_TABLE,
                $requestedNdx,
                1,
            );
            return $row;
        }

        return $this->db->fetchRow(
            'SELECT * FROM %n WHERE is_default = %i AND is_active = %i LIMIT 1',
            self::PROFILES_TABLE,
            1,
            1,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveBackend(int $backendNdx): ?array
    {
        return $this->db->fetchRow(
            'SELECT * FROM %n WHERE id = %i AND is_active = %i LIMIT 1',
            self::BACKENDS_TABLE,
            $backendNdx,
            1,
        );
    }

    private function generateClaimToken(): string
    {
        return 'ct_' . bin2hex(random_bytes(30));
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
     * Atomicky uloží výsledek analýzy (kontrakt v4, message-centricky):
     * vytvoří záznam v message_analyses s canonical návrhem (`document`
     * 0..1 → canonical_json + proposed_type), uvolní claim, přepne
     * analysis_state→30. docState: jen když je zpráva stále v Nové (10)
     * a běh přinesl validní dokument → 10→20 (K řešení). Běh bez dokumentu
     * docState nemění (zpráva zůstává v Nové — dashboard řeší karta „Není
     * faktura"); ruční workflow stav pipeline nikdy nepřepisuje.
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
        $modelName = trim((string) ($body['model_name'] ?? ''));
        $promptVersion = trim((string) ($body['prompt_version'] ?? ''));
        if ($modelName === '' || $promptVersion === '') {
            return Response::error(
                'VALIDATION_ERROR',
                'model_name and prompt_version are required',
                422,
            );
        }

        if (array_key_exists('extracted_documents', $body)) {
            return Response::error(
                'VALIDATION_ERROR',
                'extracted_documents is no longer accepted — send document (0..1), contract v4',
                422,
                [['field' => 'extracted_documents']],
            );
        }

        $classification = $body['message_classification'] ?? null;
        if (!is_array($classification)
            || trim((string) ($classification['primary_type'] ?? '')) === ''
        ) {
            return Response::error(
                'VALIDATION_ERROR',
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
        //    (dashboard z něj staví chybovou kartu), běh se uloží a vrací
        //    se 201.
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
            [$canonicalJson, $documentValid] = $this->validateAndStoreCanonical(
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
                'message' => $messageNdx,
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
                'created_by' => $auth->userId,
            ])->execute();
            $analysisNdx = (int) $dibi->getInsertId();

            // 3) Uvolni claim
            $dibi->update(self::CLAIMS_TABLE, [
                'released' => 1,
                'released_at' => $now,
                'release_reason' => 'result',
            ])->where('id = %i', (int) $claim['id'])->execute();

            // 4) analysis_state → 30 (Analyzováno), vynulovat needs_reanalysis.
            $dibi->update(self::MESSAGES_TABLE, [
                'analysis_state' => self::ANALYSIS_ANALYZED,
                'needs_reanalysis' => 0,
                'modified' => $now,
            ])->where('id = %i', $messageNdx)->execute();

            // 5) Workflow: Nová → K řešení, jen když běh přinesl validní
            // dokument a uživatel mezitím stav ručně nezměnil
            // (docState != 10 → nechat být).
            if ($documentValid) {
                $dibi->update(self::MESSAGES_TABLE, [
                    'docState' => self::DOC_STATE_IN_PROGRESS,
                    'docStateMain' => self::DOC_STATE_IN_PROGRESS_MAIN,
                ])
                ->where('id = %i', $messageNdx)
                ->where('docState = %i', self::DOC_STATE_NEW)
                ->execute();
            }

            // 6) AI klasifikace typu zprávy (message_classification).
            $this->applyMessageClassification($dibi, $messageNdx, $body);

            // 7) Partner zprávy z canonicalu — vrstva 1
            //    (tasks/mail-message-title-partner.md D5/D8): partner_name
            //    dokud target_row IS NULL, partner_person jen do NULL a jen
            //    shodou identifikátorem. Best-effort — selhání nesmí shodit
            //    uložení výsledku (analyzer by zprávu retryoval).
            if (is_array($canonical) && $proposedType !== null) {
                try {
                    $this->partnerWriter()->writeFromCanonical($dibi, $messageNdx, $canonical, $proposedType);
                } catch (\Throwable $e) {
                    ErrorLogger::warn('AnalysisController::result partner write failed', [
                        'messageNdx' => $messageNdx,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // 8) Titulek zprávy (ai_title) — AI-vlastněný, zapisuje se každý
            //    běh (i NULL). Best-effort ze stejného důvodu jako partner.
            try {
                $this->applyMessageTitle(
                    $dibi, $messageNdx, $body,
                    is_array($canonical) ? $canonical : null, $proposedType, $profileNdx,
                );
            } catch (\Throwable $e) {
                ErrorLogger::warn('AnalysisController::result title write failed', [
                    'messageNdx' => $messageNdx,
                    'error' => $e->getMessage(),
                ]);
            }

            $dibi->commit();
        } catch (\Throwable $e) {
            $dibi->rollback();
            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }

        return Response::success([
            'analysis_ndx' => $analysisNdx,
        ], 201);
    }

    /**
     * Zapíše AI klasifikaci typu zprávy z `message_classification`
     * (spec tasks/mail-states-and-classification.md §B1). Běží uvnitř
     * transakce resultu. Přítomnost pole vynucuje result() (422) —
     * kontrakt v4 ho má povinné; fallback čtení z `analysis_json` zůstává
     * pro robustnost.
     *
     * - Neznámý `primary_type` → warning + ignore; nesmí rozbít uložení
     *   výsledku (žádná 422).
     * - AI nikdy nepřepisuje hodnotu nastavenou uživatelem
     *   (`primary_type_source = 'user'` → UPDATE se nedotkne řádku).
     *
     * @param array<string, mixed> $body
     */
    private function applyMessageClassification(\Dibi\Connection $dibi, int $messageNdx, array $body): void
    {
        $classification = $body['message_classification'] ?? null;
        if (!is_array($classification)) {
            $analysisJson = $body['analysis_json'] ?? null;
            $classification = is_array($analysisJson)
                ? ($analysisJson['message_classification'] ?? null)
                : null;
        }
        if (!is_array($classification)) {
            return;
        }

        $primaryType = trim((string) ($classification['primary_type'] ?? ''));
        if ($primaryType === '') {
            return;
        }

        if (!in_array($primaryType, $this->knownPrimaryTypes(), true)) {
            ErrorLogger::warn('AnalysisController::result ignoring unknown primary_type', [
                'messageNdx' => $messageNdx,
                'primary_type' => $primaryType,
            ]);
            return;
        }

        $dibi->update(self::MESSAGES_TABLE, [
            'primary_type' => $primaryType,
            'primary_type_source' => 'ai',
        ])
        ->where('id = %i', $messageNdx)
        ->where('primary_type_source != %s', 'user')
        ->execute();
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
     * titulek needituje) i na `target_row` (záměr, P2). Starší analyzer bez
     * `title` projde — pole není v kontraktu povinné (P8).
     *
     * Fallback skládá labely typů v jazyce AI profilu běhu (`$profileNdx`,
     * jinak výchozí profil DS) — stejně jako titulek od AI (D2), ne v jazyce
     * requestu analyzeru.
     *
     * @param array<string, mixed>      $body
     * @param array<string, mixed>|null $canonical validní canonical návrhu (bez wrapperu), nebo null
     */
    private function applyMessageTitle(
        \Dibi\Connection $dibi,
        int $messageNdx,
        array $body,
        ?array $canonical,
        ?string $proposedType,
        ?int $profileNdx = null,
    ): void {
        $classification = $body['message_classification'] ?? null;
        if (!is_array($classification)) {
            $analysisJson = $body['analysis_json'] ?? null;
            $classification = is_array($analysisJson)
                ? ($analysisJson['message_classification'] ?? null)
                : null;
        }

        $title = is_array($classification)
            ? MessageTitleComposer::clean($classification['title'] ?? null)
            : null;
        if ($title === null && $canonical !== null && $proposedType !== null) {
            $title = $this->titleComposer($profileNdx)->compose($canonical, $proposedType);
        }

        $dibi->update(self::MESSAGES_TABLE, ['ai_title' => $title])
            ->where('id = %i', $messageNdx)
            ->execute();
    }

    /**
     * Klíče cfgItem `core.mail.primaryTypes` — server toleruje i typy
     * s `enabled: false` (prompt AI omezuje na enabled). Bez compiled
     * configu degraduje na pevný seznam (musí odpovídat primaryTypes.jsonc).
     *
     * @return list<string>
     */
    private function knownPrimaryTypes(): array
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
    private function validateAndStoreCanonical(
        ?array $extractedJson,
        string $docType,
    ): array {
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
            return $this->validateAndStoreRegistryCanonical($extractedJson);
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
                // se ukládá obohacený canonical. Selhání /result nesmí shodit
                // (analyzer by zprávu retryoval) → pokračuje se neobohaceně.
                try {
                    $extractedJson = $this->enricher->enrichAtResult($extractedJson);
                } catch (\Throwable $e) {
                    ErrorLogger::logException($e, 'AnalysisController::result row enrichment failed');
                }
            }
            return [
                (string) json_encode($extractedJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                true,
            ];
        }

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
     * Registry větev ingestu: validace proti `shpd.registry.document.v1`
     * (schéma modulu base.registry) — žádný RowHistoryEnricher
     * (docs-specifikum). Invalid výstup dostává stejný forenzní wrapper
     * jako docs cesta.
     *
     * @param array<string, mixed> $extractedJson
     * @return array{0: ?string, 1: bool}  [jsonForDb, isValid]
     */
    private function validateAndStoreRegistryCanonical(array $extractedJson): array
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
     * SchemaValidator nad schématy base.registry — druhá instance loaderu
     * mířící do `modules/base/registry/schemas` (soubor drží konvenci
     * `{formatId}.v{version}.json`, takže SchemaLoader funguje beze změny).
     */
    private function registrySchemaValidator(): SchemaValidator
    {
        return $this->registrySchemaValidator ??= new SchemaValidator(
            new SchemaLoader(dirname(__DIR__, 3) . '/modules/base/registry/schemas'),
        );
    }

    // -------------------------------------------------------------------
    // POST /{ndx}/failed
    // -------------------------------------------------------------------

    /**
     * Atomicky uloží neúspěch analýzy: vytvoří failed analysis record,
     * uvolní claim, přepne analysis_state 20→10 (retryable) nebo 20→70
     * (permanent). docState se nemění. Spec §3.6.
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

        $dibi = $this->db->getDibiConnection();
        $dibi->begin();
        try {
            $now = date('Y-m-d H:i:s');

            $dibi->insert(self::ANALYSES_TABLE, [
                'message' => $messageNdx,
                'analyzed_at' => $now,
                'status' => 3, // failed
                'model_name' => isset($body['model_name']) ? (string) $body['model_name'] : 'unknown',
                'prompt_version' => isset($body['prompt_version']) ? (string) $body['prompt_version'] : 'unknown',
                'error_message' => $errorMessage !== '' ? "[{$errorType}] {$errorMessage}" : "[{$errorType}]",
                'tokens_input' => $tokensUsed,
                'created' => $now,
                'created_by' => $auth->userId,
            ])->execute();

            $dibi->update(self::CLAIMS_TABLE, [
                'released' => 1,
                'released_at' => $now,
                'release_reason' => 'failed',
            ])->where('id = %i', (int) $claim['id'])->execute();

            // retryable=true → zpět do fronty (10), jinak permanent error (70)
            $newState = $retryable ? self::ANALYSIS_QUEUED : self::ANALYSIS_FAILED;

            $dibi->update(self::MESSAGES_TABLE, [
                'analysis_state' => $newState,
                'modified' => $now,
            ])->where('id = %i', $messageNdx)->execute();

            $dibi->commit();
        } catch (\Throwable $e) {
            $dibi->rollback();
            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }

        return Response::success([
            'message_ndx' => $messageNdx,
            'retryable' => $retryable,
            'new_state' => $retryable ? 'queued' : 'ai_failed',
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
        );
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

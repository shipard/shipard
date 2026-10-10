<?php

declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\AuthContext;
use Shipard\Api\MultipartFiles;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Api\TableAccessGuard;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Security\DsSecretCipher;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Document\DocumentEventDispatcher;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Document\TableGateway;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Mail\AllowedSenders;
use Shipard\Core\Settings\SettingsStore;
use Shipard\Module\Core\Attachments\AttachmentService;
use Shipard\Module\Core\Mail\BulkHeadersDetector;
use Shipard\Module\Core\Mail\IdempotencyStore;
use Shipard\Module\Core\Mail\IncomingMessageDocument;
use Shipard\Module\Core\Mail\Analysis\AnalysisQueue;
use Shipard\Module\Core\Mail\IsdocImportService;
use Shipard\Module\Core\Mail\Preprocess\PreprocessRuleMatcher;
use Shipard\Module\Core\Mail\Preprocess\PreprocessRunner;
use Shipard\Module\Core\Mail\Preprocess\PreprocessSpawner;
use Shipard\Module\Core\Mail\MailAiProvisioner;
use Shipard\Module\Core\Mail\MailRouterProvisioner;
use Shipard\Module\Core\Mail\MessagePartnerWriter;
use Shipard\Module\Core\Mail\MessageTargetWriter;
use Shipard\Module\Core\Mail\MessageTitleComposer;
use Shipard\Module\Core\Mail\OtherMailQuery;
use Shipard\Module\Core\Mail\SenderRuleDispositions;
use Shipard\Module\Core\Mail\SenderRuleMatcher;

/**
 * Endpoint `POST /_mail/incoming` — příjem došlé pošty z externí služby
 * shipard-mail-router. Request je multipart/form-data s povinnými poli
 * `received_at`, `sender_email`, `raw_source` (.eml) a 0..N přílohami
 * v poli `attachments[]`.
 *
 * Viz `tasks/mail-phase2a.md` § 2 — API kontrakt.
 */
class MailController
{
    private const MAIL_TABLE = 'core_mail_incoming_messages';
    private const MAIL_TABLE_ID = 303;

    /** docState Archiv (core.mail.docStatesIncoming) — cíl pre-triage. */
    private const DOC_STATE_ARCHIVED = 80;

    private const PREPROCESS_RULES_TABLE = 'core_mail_preprocess_rules';

    /** Strop souborů v jedné dávce ručního uploadu (D6). */
    private const UPLOAD_MAX_FILES = 20;

    /** Systémové účty, kterým upload endpoint nepatří — je pro UI uživatele (účet zrušeného analyzeru je deaktivovaný, guard zůstává). */
    private const UPLOAD_FORBIDDEN_LOGINS = [MailRouterProvisioner::ROUTER_LOGIN, MailAiProvisioner::LEGACY_ANALYZER_LOGIN];

    private AttachmentService $attachments;
    private IdempotencyStore $idempotency;
    private ?MessageTargetWriter $targetWriter = null;

    /**
     * @param array<string, TableDefinition> $tables
     * @param \Closure(): IsdocImportService|null $isdocImportFactory Lazy
     *        wiring **detekce** ISDOC — service se staví až při prvním
     *        kandidátovi (intake bez ISDOC neplatí režii wiringu). Import
     *        sám běží v runneru předzpracování (#81 D1), tady stačí
     *        service bez enricheru a writerů.
     * @param \Closure(int): void|null $preprocessSpawner Test seam — náhrada
     *        detached spawnu runneru předzpracování (default PreprocessSpawner).
     * @param DocumentEventDispatcher|null $eventDispatcher Handlery dokumentů
     *        pro zápisy přes TableGateway (Archivovat vše / Vrátit) — vzor
     *        SenderRulesController.
     * @param \Closure(int): void|null $analysisSpawner Detached spawn runneru
     *        AI analýzy (tasks/mail-analysis-inprocess.md D14) — wiring jen
     *        v public/index.php (AnalysisSpawner); null = bez spawnu, zprávu
     *        dohledá `mail-analyze --sweep` (testy nesmí pouštět placené běhy).
     */
    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly string $dsPath,
        private readonly array $tables,
        private readonly DocumentRegistry $documentRegistry,
        private readonly ?ConfigRuntime $config = null,
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?\Closure $isdocImportFactory = null,
        private readonly ?\Closure $preprocessSpawner = null,
        private readonly ?DocumentEventDispatcher $eventDispatcher = null,
        private readonly ?\Closure $analysisSpawner = null,
    ) {
        $this->attachments = new AttachmentService($db, $dsPath, $tables);
        $this->idempotency = new IdempotencyStore($db);
    }

    /**
     * `POST /_mail/senders/{id}/password` — jediná cesta, jak nastavit
     * SMTP heslo senderu. Sloupec `password_enc` je sensitive (generické
     * CRUD ho nečte ani nezapíše); plaintext z body se zašifruje
     * DsSecretCipher a uloží. Admin session only — API klíče nikdy
     * nemají isAdmin.
     */
    public function setSenderPassword(AuthContext $auth, Request $request, int $id): Response
    {
        if (!$auth->isAuthenticated || !$auth->isAdmin) {
            return Response::error('FORBIDDEN', 'Administrator session required', 403);
        }

        $body = $request->getBody();
        $password = is_array($body) && is_string($body['password'] ?? null) ? $body['password'] : '';
        if ($password === '') {
            return Response::error('VALIDATION', "Field 'password' is required and must be a non-empty string", 400);
        }

        $sender = $this->db->fetchRow('SELECT id FROM core_mail_senders WHERE id = %i', $id);
        if ($sender === null) {
            return Response::error('NOT_FOUND', "Sender #{$id} not found", 404);
        }

        try {
            $cipher = DsSecretCipher::forConfig($this->dsConfig ?? new DataSourceConfig($this->dsPath));
            $encrypted = $cipher->encrypt($password);
        } catch (\Throwable $e) {
            // Chybu šifrování vracíme bez detailů — nesmí uniknout nic o klíči.
            return Response::error('INTERNAL_ERROR', 'Failed to encrypt the password: ' . $e->getMessage(), 500);
        }

        $this->db->updateWhere('core_mail_senders', ['password_enc' => $encrypted], 'id = %i', $id);

        return Response::success(['id' => $id, 'passwordSet' => true]);
    }

    /**
     * `POST /_mail/messages/archive-informational` — Archivovat vše v sekci
     * Ostatní dashboardu (tasks/mail-other-attention.md D5, #105). Vybere
     * řádky čekající ostatní pošty s pozorností `info` / `promo`
     * (`OtherMailQuery::informationalWhere` — tatáž množina, kterou feed
     * označuje `archivable`) a každý převede do Archivu (80) přes
     * `TableGateway` / `IncomingMessageDocument` (hooky, `docStateMain`,
     * handlery), ne hromadným UPDATE. Řádky s NULL pozorností (starší
     * analýza) a K vyřízení zůstávají. Vrací `{archived: [id…], count}`;
     * prázdný výběr → 200 s `count: 0`. Práva jako `archive_message`
     * (přihlášený uživatel).
     *
     * Každá zpráva je vlastní transakce gateway (MariaDB vnořené transakce
     * nemá — vnější `begin` by druhý `START TRANSACTION` tiše commitl); při
     * chybě uprostřed odpověď nese jen skutečně archivované id.
     *
     * Učení pravidel (`SenderRuleSuggestionHandler`): `IncomingMessageDocument`
     * dnes přechod stavu neeviduje, takže gateway `stateChanged` nevysílá
     * a hromadný archiv nic neučí (známý bug, D6). Až se opraví, počítal
     * by se každý řádek jako ruční odklizení per odesílatel — pak zvážit
     * vynechání hromadného archivu z učení.
     */
    public function archiveInformational(AuthContext $auth): Response
    {
        if (!$auth->isAuthenticated) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
        }

        $gateway = $this->buildMessagesGateway();
        if ($gateway === null) {
            return Response::error('INTERNAL_ERROR', 'Messages table is not available', 500);
        }

        $rows = $this->db->fetchAll(
            'SELECT `m`.`id` FROM %n `m` WHERE ' . OtherMailQuery::informationalWhere() . ' ORDER BY `m`.`id`',
            self::MAIL_TABLE,
        );

        $archived = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $doc = $gateway->loadDocument($id);
            // Souběh: uživatel zprávu mezitím odklidil sám → přeskočit.
            if ($doc === null || (int) ($doc['docState'] ?? 0) !== IncomingMessageDocument::DOC_STATE_NEW) {
                continue;
            }
            $doc['docState'] = self::DOC_STATE_ARCHIVED;
            if ($gateway->saveDocument($doc)->isSuccess()) {
                $archived[] = $id;
            }
        }

        return Response::success(['archived' => $archived, 'count' => count($archived)]);
    }

    /**
     * `POST /_mail/messages/restore-archived` `{ids: [int…]}` — Vrátit
     * z toastu po Archivovat vše (D5): jen zprávy v Archivu (80) bez
     * `auto_disposed_by` (ruční archiv; pravidlem archivované mají vlastní
     * digest + Vrátit vše s jinou sémantikou) zpět do Nové (10) přes
     * gateway; `analysis_state` zůstává. Cizí nebo už přesunutá id se tiše
     * přeskočí. Vrací `{restored: n}`.
     */
    public function restoreArchived(AuthContext $auth, Request $request): Response
    {
        if (!$auth->isAuthenticated) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
        }

        $body = $request->getBody();
        $ids = is_array($body) && is_array($body['ids'] ?? null) ? $body['ids'] : null;
        if ($ids === null) {
            return Response::error('VALIDATION_ERROR', 'ids must be an array of message ids', 422, [['field' => 'ids']]);
        }
        $ids = array_values(array_unique(array_filter(
            array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $ids),
            static fn (int $v): bool => $v > 0,
        )));
        if ($ids === []) {
            return Response::success(['restored' => 0]);
        }

        $gateway = $this->buildMessagesGateway();
        if ($gateway === null) {
            return Response::error('INTERNAL_ERROR', 'Messages table is not available', 500);
        }

        $rows = $this->db->fetchAll(
            'SELECT id FROM %n WHERE id IN %in AND docState = %i AND auto_disposed_by IS NULL',
            self::MAIL_TABLE,
            $ids,
            self::DOC_STATE_ARCHIVED,
        );

        $restored = 0;
        foreach ($rows as $row) {
            $doc = $gateway->loadDocument((int) $row['id']);
            if ($doc === null) {
                continue;
            }
            $doc['docState'] = IncomingMessageDocument::DOC_STATE_NEW;
            if ($gateway->saveDocument($doc)->isSuccess()) {
                $restored++;
            }
        }

        return Response::success(['restored' => $restored]);
    }

    /** Gateway nad zprávami pro zápisy se všemi hooky (vzor SenderRulesController::buildGateway). */
    private function buildMessagesGateway(): ?TableGateway
    {
        $def = $this->tables[self::MAIL_TABLE] ?? null;
        if ($def === null) {
            return null;
        }

        return new TableGateway(
            self::MAIL_TABLE,
            $this->db->getDibiConnection(),
            $this->documentRegistry,
            $def->childTables,
            $this->config,
            $this->dsConfig,
            $this->eventDispatcher,
            $def->docStates,
            $def,
        );
    }

    /**
     * GET /_mail/sender-addresses — adresy, ze kterých zdroj dat smí
     * odesílat (#90 D39): výchozí `mail.defaultFrom` a aktivní odesílatelé.
     * Nabídka pro volbu odesílatele; SMTP údaje odesílatelů nenese.
     */
    public function senderAddresses(AuthContext $auth): Response
    {
        // Stejná práva jako čtení tabulky odesílatelů.
        $guardErr = TableAccessGuard::guardTable(
            'core_mail_senders',
            $auth,
            $this->tables['core_mail_senders'] ?? null,
        );
        if ($guardErr !== null) {
            return $guardErr;
        }

        $allowed = new AllowedSenders($this->db, new SettingsStore($this->db));

        return Response::success([
            'addresses' => $allowed->addresses(),
            'default'   => $allowed->defaultFrom(),
        ]);
    }

    public function receiveIncoming(AuthContext $auth, Request $request): Response
    {
        $authError = $this->verifyAuth($auth);
        if ($authError !== null) {
            return $authError;
        }

        $idempotencyKey = $this->extractIdempotencyKey($request);

        if ($idempotencyKey !== null) {
            $cached = $this->idempotency->lookup($idempotencyKey);
            if ($cached !== null) {
                return $this->replayResponse($cached['response_body']);
            }
        }

        $validation = $this->validateFormFields();
        if ($validation instanceof Response) {
            return $validation;
        }
        $fields = $validation;

        $mailboxResolved = $this->resolveMailbox($fields['mailbox']);
        if ($mailboxResolved instanceof Response) {
            return $mailboxResolved;
        }
        $mailboxId = $mailboxResolved;

        $rawSourceFile = $this->validateRawSource();
        if ($rawSourceFile instanceof Response) {
            return $rawSourceFile;
        }

        $fields['is_bulk'] = $this->detectBulkHeaders($rawSourceFile['tmp_name']) ? 1 : 0;

        $attachmentFiles = MultipartFiles::collect('attachments');

        $uploadedFiles = [];
        $contentAttachments = [];
        $dibi = $this->db->getDibiConnection();

        // Pre-triage (Fáze 3, D7): potvrzené pravidlo odesílatele s dispozicí
        // „Archivovat hned“ → zpráva vzniká rovnou v Archivu, bez analýzy,
        // s auditem na zprávě. Matcher vrací nejkonkrétnější pravidlo bez
        // ohledu na dispozici (tasks/mail-sender-rules-after-analysis.md D6):
        // `archiveIfOther` nechá zprávu projít normální cestou (předzpracování,
        // ISDOC, AI) a zasáhne až po analýze (PostAnalysisDisposer).
        $matchedRule = new SenderRuleMatcher($dibi)->match($fields['sender_email']);
        $preTriageRule = ($matchedRule['disposition'] ?? null) === SenderRuleDispositions::ARCHIVE
            ? $matchedRule
            : null;

        // Technické předzpracování (tasks/mail-preprocess.md): jen zprávy,
        // které nejdou do Archivu. Plán je snapshot pravidel v čase intake
        // (D12) — runner vykonává jej, ne aktuální pravidla.
        $preprocessPlan = $preTriageRule === null ? $this->matchPreprocessPlan($fields) : null;

        $dibi->begin();

        try {
            $messageId = $this->insertIncomingMessage($fields, $mailboxId, $auth->userId, $preTriageRule, $preprocessPlan);

            if ($preTriageRule !== null) {
                $dibi->query(
                    'UPDATE core_mail_sender_rules SET hit_count = hit_count + 1, last_hit_at = %s WHERE id = %i',
                    date('Y-m-d H:i:s'),
                    (int) $preTriageRule['id'],
                );
            }

            if ($preprocessPlan !== null) {
                $dibi->query(
                    'UPDATE %n SET hit_count = hit_count + 1, last_hit_at = %s WHERE id IN %in',
                    self::PREPROCESS_RULES_TABLE,
                    date('Y-m-d H:i:s'),
                    array_column($preprocessPlan, 'ruleNdx'),
                );
            }

            $rawResult = $this->attachments->upload(
                self::MAIL_TABLE_ID,
                $messageId,
                $rawSourceFile['name'],
                $rawSourceFile['tmp_name'],
                $auth->userId,
            );
            if (!$rawResult['success']) {
                throw new \RuntimeException($rawResult['error'] ?? 'Nelze uložit .eml soubor');
            }
            $uploadedFiles[] = $rawResult['data'];
            $rawAttachmentId = (int) $rawResult['data']['id'];

            $dibi->update(self::MAIL_TABLE, ['raw_source_attachment' => $rawAttachmentId])
                ->where('id = %i', $messageId)
                ->execute();

            foreach ($attachmentFiles as $att) {
                $attResult = $this->attachments->upload(
                    self::MAIL_TABLE_ID,
                    $messageId,
                    $att['name'],
                    $att['tmp_name'],
                    $auth->userId,
                );
                if (!$attResult['success']) {
                    throw new \RuntimeException($attResult['error'] ?? 'Nelze uložit přílohu');
                }
                $uploadedFiles[] = $attResult['data'];
                $contentAttachments[] = $attResult['data'];
            }

            $createdRow = $this->db->fetchRow('SELECT message_id FROM %n WHERE id = %i', self::MAIL_TABLE, $messageId);
            $messageCode = (string) ($createdRow['message_id'] ?? '');

            $responseData = [
                'ndx' => $messageId,
                'message_id' => $messageCode,
                'idempotent_replay' => false,
            ];

            if ($idempotencyKey !== null) {
                $payloadForReplay = ['success' => true, 'data' => $responseData];
                $this->idempotency->store(
                    $idempotencyKey,
                    $messageId,
                    (string) json_encode($payloadForReplay, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                );
            }

            $dibi->commit();

            // Deterministický ISDOC import (tasks/mail-isdoc-import.md, #81)
            // — až po commitu intake tx, nikdy nesmí shodit příjem pošty.
            // Auto-archivovaná zpráva žádné zpracování nedostává. Zpráva
            // s plánem předzpracování jde rovnou do runneru (D10) — ISDOC
            // import udělá sám nad původními i vygenerovanými přílohami;
            // bez plánu se inline jen detekuje a platný ISDOC se do runneru
            // odloží (#81 D4), import s obsahovou eskalací běží tam.
            if ($preTriageRule === null) {
                if ($preprocessPlan === null) {
                    if (!$this->deferIsdocImport($messageId, $contentAttachments)) {
                        $this->spawnAnalysis($messageId);
                    }
                } else {
                    $this->spawnPreprocess($messageId);
                }
            }

            return Response::success($responseData, 201);
        } catch (\Throwable $e) {
            $dibi->rollback();
            $this->cleanupOrphanedFiles($uploadedFiles);

            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Odložení ISDOC importu do runneru předzpracování (#81 D1/D4) — běží
     * po commitu intake / upload tx. Inline zůstává jen detekce bez zápisu
     * (parse, schéma, dedup identitou); import s obsahovou eskalací (LLM)
     * dělá detached runner, aby nezpomalil HTTP request. Výsledek se do
     * response nepropaguje (mail-router ani dashboard ho nepotřebují).
     *
     * Podmíněný UPDATE řeší závod s analyzerem: mezi commitem a odložením
     * je zpráva ve frontě (`analysis_state` 10) a detect() může trvat
     * (pdfdetach). Claimnutá zpráva (20) se nesmí zaseknout za gate →
     * 0 řádků = analyzer vyhrál, nic se neděje. `analysis_state` 0 musí
     * projít (DS bez AI). Invariant: nikdy nesmí shodit příjem ani upload.
     *
     * @param list<array<string, mixed>> $contentAttachments Uploady příloh
     *        bez raw .eml souboru.
     * @return bool Zpráva odložena do runneru předzpracování (ten pak spustí
     *         i analýzu); false = zpráva zůstává přímo ve frontě AI.
     */
    private function deferIsdocImport(int $messageId, array $contentAttachments): bool
    {
        if ($this->isdocImportFactory === null) {
            return false;
        }

        try {
            $hasCandidate = false;
            foreach ($contentAttachments as $file) {
                if (is_array($file) && IsdocImportService::isPotentialCandidate($file)) {
                    $hasCandidate = true;
                    break;
                }
            }
            if (!$hasCandidate) {
                return false;
            }

            if (!($this->isdocImportFactory)()->detect($messageId, $contentAttachments)) {
                return false; // žádný / vadný ISDOC, více identit → AI fronta jako dřív
            }

            $this->db->execute(
                'UPDATE %n SET preprocess_state = %i, preprocess_log = %s, modified = %s'
                . ' WHERE id = %i AND preprocess_state = %i AND analysis_state IN %in',
                self::MAIL_TABLE,
                PreprocessRunner::STATE_PENDING,
                PreprocessRunner::encodeLog(PreprocessRunner::isdocOnlyLog()),
                date('Y-m-d H:i:s'),
                $messageId,
                PreprocessRunner::STATE_NONE,
                IsdocImportService::ANALYSIS_IMPORTABLE_STATES,
            );
            if ($this->db->getAffectedRows() === 0) {
                return false; // analyzer si zprávu mezitím claimnul — nechat mu ji
            }

            $this->spawnPreprocess($messageId);
            return true;
        } catch (\Throwable $e) {
            ErrorLogger::logException($e, 'MailController ISDOC deferral failed — message stays in AI queue');
            return false;
        }
    }

    /**
     * Plán předzpracování dle potvrzených pravidel; null = žádné pravidlo
     * nematchlo (zpráva jde dnešní cestou). Selhání matcheru nikdy nesmí
     * shodit příjem pošty — zpráva pak projde bez předzpracování.
     *
     * @param array<string, mixed> $fields
     * @return list<array{ruleId: string, ruleNdx: int, actions: list<array<string, mixed>>}>|null
     */
    private function matchPreprocessPlan(array $fields): ?array
    {
        try {
            return new PreprocessRuleMatcher($this->db->getDibiConnection())->match(
                (string) $fields['sender_email'],
                (string) ($fields['subject'] ?? ''),
                $fields['body_html'] ?? null,
                $fields['body_plain'] ?? null,
            );
        } catch (\Throwable $e) {
            ErrorLogger::logException($e, 'MailController::receiveIncoming preprocess matcher failed — message goes through without preprocessing');
            return null;
        }
    }

    /**
     * Detached spawn runneru předzpracování po commitu intake / uploadu
     * (D8) — pro plán pravidel i pro ISDOC-only odložení (#81). Selhání
     * jen zaloguje — zprávu ve stavu 10 zvedne rescue sweep.
     */
    private function spawnPreprocess(int $messageId): void
    {
        try {
            if ($this->preprocessSpawner !== null) {
                ($this->preprocessSpawner)($messageId);
                return;
            }
            new PreprocessSpawner($this->dsPath)->spawn($messageId);
        } catch (\Throwable $e) {
            ErrorLogger::logException($e, 'MailController preprocess spawn failed — sweep will pick the message up');
        }
    }

    /**
     * Detached spawn runneru AI analýzy po commitu příjmu / nahrání
     * (tasks/mail-analysis-inprocess.md D14) — jen pro zprávu, která nešla
     * do předzpracování (odtud ji spouští runner předzpracování sám) a je
     * ve frontě (`AnalysisQueue::isEligible`: stav 10, schránka s AI, bez
     * claimu). Pořadí vůči ISDOC: až po `deferIsdocImport()`, jinak by si
     * AI zprávu claimla dřív, než doběhne detekce, a za peníze analyzovala
     * fakturu, kterou umí deterministický import. Bez wiringu nic — zprávu
     * dohledá `mail-analyze --sweep`; selhání jen zalogovat.
     */
    private function spawnAnalysis(int $messageId): void
    {
        if ($this->analysisSpawner === null) {
            return;
        }
        try {
            if (new AnalysisQueue($this->db)->isEligible($messageId)) {
                ($this->analysisSpawner)($messageId);
            }
        } catch (\Throwable $e) {
            ErrorLogger::logException($e, 'MailController analysis spawn failed — sweep will pick the message up');
        }
    }

    /**
     * `POST /_mail/messages/upload` — ruční nahrání souborů z dashboardu
     * (tasks/mail-dashboard-upload.md). Multipart: `mode` (single|perFile)
     * + `attachments[]`; vznikne 1..N zpráv s defaulty dle D4, celá dávka
     * v jedné transakci. Pre-triage pravidel odesílatele se přeskakuje —
     * odesílatelem je přihlášený uživatel (D8).
     */
    public function uploadMessages(AuthContext $auth, Request $request): Response
    {
        if (!$auth->isAuthenticated) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
        }

        $user = $this->db->fetchRow(
            'SELECT login, email, full_name FROM core_system_users WHERE id = %i',
            $auth->userId,
        );
        if ($user === null) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
        }
        if (in_array((string) ($user['login'] ?? ''), self::UPLOAD_FORBIDDEN_LOGINS, true)) {
            return Response::error('FORBIDDEN', 'This endpoint is for interactive users, not system accounts', 403);
        }

        $mode = trim((string) ($_POST['mode'] ?? ''));
        if ($mode !== 'single' && $mode !== 'perFile') {
            return Response::error('VALIDATION_ERROR', "mode musí být 'single' nebo 'perFile'", 422, [['field' => 'mode']]);
        }

        $files = MultipartFiles::collect('attachments');
        if ($files === []) {
            return Response::error('VALIDATION_ERROR', 'Chybí soubory (pole attachments[])', 422, [['field' => 'attachments']]);
        }
        if (count($files) > self::UPLOAD_MAX_FILES) {
            return Response::error(
                'TOO_MANY_FILES',
                'Najednou lze nahrát nejvýše ' . self::UPLOAD_MAX_FILES . ' souborů',
                422,
                [['field' => 'attachments']],
            );
        }

        $mailboxResolved = $this->resolveMailbox('');
        if ($mailboxResolved instanceof Response) {
            return $mailboxResolved;
        }
        $mailboxId = $mailboxResolved;

        $sender = $this->resolveUploadSender($user, $mailboxId);
        if ($sender instanceof Response) {
            return $sender;
        }

        // D4: single = jedna zpráva se všemi soubory, perFile = zpráva per soubor.
        $plans = [];
        if ($mode === 'single') {
            $subject = self::subjectFromFilename($files[0]['name']);
            if (count($files) > 1) {
                $subject .= ' (+' . (count($files) - 1) . ')';
            }
            $plans[] = ['subject' => $subject, 'files' => $files];
        } else {
            foreach ($files as $file) {
                $plans[] = ['subject' => self::subjectFromFilename($file['name']), 'files' => [$file]];
            }
        }

        $fields = [
            'sender_email' => $sender['email'],
            'sender_name' => $sender['name'],
            'received_at' => date('Y-m-d H:i:s'),
            'external_message_id' => null,
            'in_reply_to' => null,
            'reply_references' => null,
            'body_plain' => null,
            'body_html' => null,
            'source_type' => 1,
            'is_bulk' => 0,
        ];

        $uploadedFiles = [];
        $dibi = $this->db->getDibiConnection();

        $dibi->begin();

        try {
            $messages = [];
            $isdocBatches = [];

            foreach ($plans as $plan) {
                $messageId = $this->insertIncomingMessage(
                    ['subject' => $plan['subject']] + $fields,
                    $mailboxId,
                    $auth->userId,
                );

                $contentAttachments = [];
                foreach ($plan['files'] as $att) {
                    $attResult = $this->attachments->upload(
                        self::MAIL_TABLE_ID,
                        $messageId,
                        $att['name'],
                        $att['tmp_name'],
                        $auth->userId,
                    );
                    if (!$attResult['success']) {
                        throw new \RuntimeException($attResult['error'] ?? 'Nelze uložit přílohu');
                    }
                    $uploadedFiles[] = $attResult['data'];
                    $contentAttachments[] = $attResult['data'];
                }

                $createdRow = $this->db->fetchRow('SELECT message_id FROM %n WHERE id = %i', self::MAIL_TABLE, $messageId);
                $messages[] = [
                    'ndx' => $messageId,
                    'message_id' => (string) ($createdRow['message_id'] ?? ''),
                    'subject' => $plan['subject'],
                ];
                $isdocBatches[$messageId] = $contentAttachments;
            }

            $dibi->commit();

            // ISDOC až po commitu dávky (D8): inline detekce, platný ISDOC
            // se odloží do runneru per zpráva (#81 D4 — až 20 runnerů naráz
            // je v pořádku, neserializovat). Nikdy nesmí shodit upload.
            foreach ($isdocBatches as $messageId => $contentAttachments) {
                if (!$this->deferIsdocImport($messageId, $contentAttachments)) {
                    $this->spawnAnalysis($messageId);
                }
            }

            return Response::success(['mode' => $mode, 'messages' => $messages], 201);
        } catch (\Throwable $e) {
            $dibi->rollback();
            $this->cleanupOrphanedFiles($uploadedFiles);

            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * D4: odesílatel = přihlášený uživatel; bez platného e-mailu fallback
     * na adresu default schránky.
     *
     * @param array<string, mixed> $user Řádek z core_system_users
     * @return array{email: string, name: ?string}|Response
     */
    private function resolveUploadSender(array $user, int $mailboxId): array|Response
    {
        $email = trim((string) ($user['email'] ?? ''));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $mailbox = $this->db->fetchRow(
                'SELECT email_address FROM core_mail_mailboxes WHERE id = %i',
                $mailboxId,
            );
            $email = trim((string) ($mailbox['email_address'] ?? ''));
        }
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return Response::error(
                'VALIDATION_ERROR',
                'Nelze určit e-mail odesílatele — uživatel ani default schránka nemají platnou adresu',
                422,
                [['field' => 'sender_email']],
            );
        }

        $name = trim((string) ($user['full_name'] ?? ''));
        if ($name === '') {
            $name = trim((string) ($user['login'] ?? ''));
        }

        return ['email' => $email, 'name' => $name !== '' ? $name : null];
    }

    /** Předmět z názvu souboru: basename bez přípony, prázdný → '(bez předmětu)'. */
    private static function subjectFromFilename(string $name): string
    {
        $base = trim(pathinfo(basename($name), PATHINFO_FILENAME));
        return $base !== '' ? $base : '(bez předmětu)';
    }

    /**
     * Endpoint `POST /_mail/import` — programové založení jedné importované
     * došlé zprávy (JSON, žádný .eml/přílohy). Vytváří přes Document path,
     * takže `beforeSave` vygeneruje `message_id` a znormalizuje `sender_email`.
     *
     * Na rozdíl od `/_mail/incoming` není omezen na systémového uživatele
     * `_mail_router` — běží pod libovolným api_key (typicky `_legacy_importer`),
     * konzistentně s `/_exchange/*`. Idempotenci řeší volající (LocalIdMap).
     *
     * Volitelná pole `partner_person` a `partner_name` nesou partnera zprávy
     * (protistrana dokumentu, ne odesílatel). **U zprávy navázané na doklad
     * se ignorují** — vyhrává partner cílového dokladu, stejně jako titulek
     * `ai_title`, který si server odvodí sám (tasks/mail-import-partner-title.md
     * D1/D8). Runner je proto posílá jen u zpráv bez navázaného dokladu.
     *
     * Viz `tasks/mail-phase4-import-endpoint.md`,
     * `tasks/mail-import-partner-title.md`.
     */
    public function importMessage(AuthContext $auth, Request $request): Response
    {
        if (!$auth->isAuthenticated || $auth->tokenType !== 'api_key') {
            return Response::error('UNAUTHORIZED', 'API key required', 401);
        }

        $body = $request->getBody();
        if ($body === null) {
            return Response::error('BAD_REQUEST', 'Request body must be a JSON object', 400);
        }

        $mailboxResolved = $this->resolveMailbox(trim((string) ($body['mailbox'] ?? '')));
        if ($mailboxResolved instanceof Response) {
            return $mailboxResolved;
        }
        $mailboxId = $mailboxResolved;

        // received_at: ISO8601 → DB datetime; validitu dořeší Document::validate
        $receivedAtRaw = trim((string) ($body['received_at'] ?? ''));
        $receivedAt = $receivedAtRaw !== '' && strtotime($receivedAtRaw) !== false
            ? date('Y-m-d H:i:s', (int) strtotime($receivedAtRaw))
            : '';

        $subject = trim((string) ($body['subject'] ?? ''));
        if ($subject === '') {
            $subject = '(bez předmětu)';
        }

        // primary_type/source_type z těla mají přednost před defaulty v beforeSave,
        // proto je nastavujeme do $data před jeho voláním.
        $data = [
            'mailbox'             => $mailboxId,
            'subject'             => $subject,
            'sender_email'        => trim((string) ($body['sender_email'] ?? '')),
            'sender_name'         => self::nullIfEmpty($body['sender_name'] ?? null),
            'sender_person'       => isset($body['sender_person']) ? (int) $body['sender_person'] : null,
            'received_at'         => $receivedAt,
            'body_plain'          => self::nullIfEmpty($body['body_plain'] ?? null),
            'body_html'           => self::nullIfEmpty($body['body_html'] ?? null),
            'external_message_id' => self::nullIfEmpty($body['external_message_id'] ?? null),
            'in_reply_to'         => self::nullIfEmpty($body['in_reply_to'] ?? null),
            'reply_references'    => self::nullIfEmpty($body['reply_references'] ?? null),
            'target_table_id'     => self::nullIfEmpty($body['target_table_id'] ?? null),
            'target_row'          => isset($body['target_row']) ? (int) $body['target_row'] : null,
            // Partner zprávy jako **návrh** (vrstva 1) — u navázané zprávy ho
            // níž přebijí fakta z dokladu (D8). Runner ho posílá jen tam, kde
            // ho server nezjistí: zprávy bez navázaného dokladu (D5).
            'partner_person'      => self::positiveIntOrNull($body['partner_person'] ?? null),
            'partner_name'        => MessagePartnerWriter::normalizeName($body['partner_name'] ?? null),
            'created_by'          => $auth->userId,
        ];
        if (!empty($body['primary_type'])) {
            $data['primary_type'] = (string) $body['primary_type'];
        }
        if (isset($body['source_type'])) {
            $data['source_type'] = (int) $body['source_type'];
        }

        // docState explicitně — beforeSave ho neřeší a DB default by byl 10.
        // Default 40 (Hotovo); volající pošle 10 pro nenavázané zprávy.
        $docState = isset($body['docState']) ? (int) $body['docState'] : 40;
        $data['docState']     = $docState;
        $data['docStateMain'] = $this->resolveIncomingMainState($docState);

        // analysis_state: explicitní hodnota z requestu má přednost. Jinak
        // platí default z beforeSave — fronta jen pro docState 10/20
        // (Nová/K řešení) s dostupnou AI; import rovnou do Hotovo/Archiv/Koše
        // dostane 0.
        if (isset($body['analysis_state'])) {
            $data['analysis_state'] = (int) $body['analysis_state'];
        }

        // Partner a titulek z cílového dokladu (vrstva 2, D1/D2/D4) — cíl je
        // autorita, non-null fakt přebije hodnotu z payloadu; null nechává
        // vrstvu 1 na pokoji (D8). Patří to před validate(): zprávu vkládáme
        // rovnou v cílovém stavu, ne UPDATEm po insertu (P3).
        foreach ($this->targetWriter()->factsFor($data['target_table_id'], $data['target_row']) as $column => $value) {
            if ($value !== null) {
                $data[$column] = $value;
            }
        }

        $doc = $this->documentRegistry->getDocument(self::MAIL_TABLE);
        $dibi = $this->db->getDibiConnection();
        $doc->setDb($dibi);

        $validation = $doc->validate($data);
        if (!$validation->isValid()) {
            $first = $validation->getErrors()[0];
            return Response::error(
                'VALIDATION_ERROR',
                $first->message,
                422,
                [['field' => $first->column, 'code' => $first->code]],
            );
        }

        // Vygeneruje message_id, znormalizuje sender_email, dorovná default
        // primary_type/source_type (pokud je v $data nemáme).
        $doc->beforeSave($data);

        $dibi->insert(self::MAIL_TABLE, $data)->execute();
        $messageId = (int) $dibi->getInsertId();

        $createdRow = $this->db->fetchRow('SELECT message_id FROM %n WHERE id = %i', self::MAIL_TABLE, $messageId);

        return Response::success([
            'ndx'        => $messageId,
            'message_id' => (string) ($createdRow['message_id'] ?? ''),
        ], 201);
    }

    /**
     * Writer faktů z cílového záznamu (lazy — import bez navázaného dokladu
     * ho nepotřebuje). Titulek je DS-wide data v jazyce AI profilu, proto
     * `forDataSource()`; bez `dsConfig` degraduje na composer bez configu
     * (titulek bez labelu typu). `$this->config` se tu vědomě nepoužije —
     * je v jazyce requestu a runner `Accept-Language` neposílá (P2).
     */
    private function targetWriter(): MessageTargetWriter
    {
        if ($this->targetWriter === null) {
            $this->targetWriter = $this->dsConfig !== null
                ? MessageTargetWriter::forDataSource($this->db, $this->dsConfig)
                : new MessageTargetWriter($this->db, new MessageTitleComposer(null));
        }
        return $this->targetWriter;
    }

    /** Kladné celé číslo z payloadu, jinak null (0 a záporné = „nevyplněno"). */
    private static function positiveIntOrNull(mixed $value): ?int
    {
        $int = is_numeric($value) ? (int) $value : 0;
        return $int > 0 ? $int : null;
    }

    /**
     * Dopočítá `docStateMain` pro daný `docState` z `core.mail.docStatesIncoming`.
     * Bez compiled configu degraduje na pevnou mapu (hodnoty jsou stabilní
     * v docStatesIncoming.jsonc).
     */
    private function resolveIncomingMainState(int $docState): int
    {
        if ($this->config !== null) {
            return DocStateConfig::fromCfgItem(
                $this->config->cfgItem('core.mail.docStatesIncoming'),
            )->getMainState($docState);
        }

        return [10 => 1, 20 => 2, 40 => 3, 80 => 4, 90 => 5][$docState] ?? 1;
    }

    private function verifyAuth(AuthContext $auth): ?Response
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
        if ($user === null || ($user['login'] ?? '') !== MailRouterProvisioner::ROUTER_LOGIN) {
            return Response::error('FORBIDDEN', 'This endpoint is restricted to the mail-router system user', 403);
        }

        return null;
    }

    private function extractIdempotencyKey(Request $request): ?string
    {
        $key = $request->getHeader('X-Idempotency-Key');
        if ($key === null) {
            return null;
        }
        $trimmed = trim($key);
        return $trimmed !== '' ? $trimmed : null;
    }

    private function replayResponse(string $responseBody): Response
    {
        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded) || !isset($decoded['data']) || !is_array($decoded['data'])) {
            return Response::error('INTERNAL_ERROR', 'Corrupted idempotency record', 500);
        }
        $decoded['data']['idempotent_replay'] = true;
        return Response::success($decoded['data'], 201);
    }

    /**
     * @return array{
     *     mailbox: string,
     *     external_message_id: ?string,
     *     received_at: string,
     *     subject: string,
     *     sender_email: string,
     *     sender_name: ?string,
     *     body_plain: ?string,
     *     body_html: ?string,
     *     in_reply_to: ?string,
     *     reply_references: ?string,
     *     source_type: int,
     * }|Response
     */
    private function validateFormFields(): array|Response
    {
        $receivedAt = trim((string) ($_POST['received_at'] ?? ''));
        if ($receivedAt === '') {
            return Response::error('VALIDATION_ERROR', 'received_at je povinné', 422, [['field' => 'received_at']]);
        }
        if (strtotime($receivedAt) === false) {
            return Response::error('VALIDATION_ERROR', 'received_at není platné ISO8601 datum', 422, [['field' => 'received_at']]);
        }

        $senderEmail = trim((string) ($_POST['sender_email'] ?? ''));
        if ($senderEmail === '') {
            return Response::error('VALIDATION_ERROR', 'sender_email je povinné', 422, [['field' => 'sender_email']]);
        }
        if (filter_var($senderEmail, FILTER_VALIDATE_EMAIL) === false) {
            return Response::error('VALIDATION_ERROR', 'sender_email není platná adresa', 422, [['field' => 'sender_email']]);
        }

        $subject = trim((string) ($_POST['subject'] ?? ''));
        if ($subject === '') {
            $subject = '(bez předmětu)';
        }

        return [
            'mailbox' => trim((string) ($_POST['mailbox'] ?? '')),
            'external_message_id' => self::nullIfEmpty($_POST['external_message_id'] ?? null),
            'received_at' => date('Y-m-d H:i:s', (int) strtotime($receivedAt)),
            'subject' => $subject,
            'sender_email' => $senderEmail,
            'sender_name' => self::nullIfEmpty($_POST['sender_name'] ?? null),
            'body_plain' => self::nullIfEmpty($_POST['body_plain'] ?? null),
            'body_html' => self::nullIfEmpty($_POST['body_html'] ?? null),
            'in_reply_to' => self::nullIfEmpty($_POST['in_reply_to'] ?? null),
            'reply_references' => self::nullIfEmpty($_POST['reply_references'] ?? null),
            'source_type' => isset($_POST['source_type']) ? (int) $_POST['source_type'] : 2,
        ];
    }

    private function resolveMailbox(string $mailboxCode): int|Response
    {
        if ($mailboxCode === '') {
            $row = $this->db->fetchRow(
                'SELECT id FROM core_mail_mailboxes WHERE is_default = %i LIMIT 1',
                1,
            );
            if ($row === null) {
                return Response::error(
                    'VALIDATION_ERROR',
                    'DS has no default mailbox configured',
                    422,
                    [['field' => 'mailbox']],
                );
            }
            return (int) $row['id'];
        }

        $row = $this->db->fetchRow(
            'SELECT id FROM core_mail_mailboxes WHERE mailbox_id = %s',
            $mailboxCode,
        );
        if ($row === null) {
            return Response::error(
                'VALIDATION_ERROR',
                "Schránka '{$mailboxCode}' neexistuje v tomto DS",
                422,
                [['field' => 'mailbox']],
            );
        }

        return (int) $row['id'];
    }

    /**
     * Deterministický signál hromadné pošty z hlaviček raw `.eml`.
     * Čte jen začátek souboru (hlavičky), selhání nikdy neblokuje ingest —
     * vrací false a zaloguje warn.
     */
    private function detectBulkHeaders(string $tmpName): bool
    {
        try {
            // 128 KB s rezervou pokryje hlavičkový blok; tělo nečteme.
            $head = @file_get_contents($tmpName, false, null, 0, 131072);
            if ($head === false) {
                ErrorLogger::warn('MailController::receiveIncoming cannot read raw_source for bulk detection');
                return false;
            }
            return new BulkHeadersDetector()->detect($head);
        } catch (\Throwable $e) {
            ErrorLogger::logException($e, 'MailController::receiveIncoming bulk headers detection failed');
            return false;
        }
    }

    /**
     * @return array{name: string, tmp_name: string}|Response
     */
    private function validateRawSource(): array|Response
    {
        if (!isset($_FILES['raw_source']) || $_FILES['raw_source']['error'] !== UPLOAD_ERR_OK) {
            return Response::error(
                'VALIDATION_ERROR',
                'raw_source (.eml soubor) je povinný',
                422,
                [['field' => 'raw_source']],
            );
        }
        return [
            'name' => (string) ($_FILES['raw_source']['name'] ?? 'message.eml'),
            'tmp_name' => (string) $_FILES['raw_source']['tmp_name'],
        ];
    }

    /**
     * Insert přes DocumentRegistry — respektuje `beforeSave` hook (generace
     * `message_id`, normalizace sender_email atd.).
     *
     * Používáme Dibi přímo v rámci aktivní tx místo TableGateway, protože
     * Gateway má vlastní `begin/commit` a my potřebujeme obalit také upload
     * attachmentů do jedné tx.
     *
     * @param array<string, mixed>|null $matchedRule Potvrzené pravidlo
     *        odesílatele s dispozicí `archive` (pre-triage) — zpráva pak
     *        vzniká rovnou v Archivu (80), bez analýzy, s auditem
     *        `auto_disposed_*`. Pravidlo `archiveIfOther` sem nepatří
     *        (zasahuje až po analýze).
     * @param list<array<string, mixed>>|null $preprocessPlan Plán
     *        předzpracování — zpráva vzniká s `preprocess_state=10` a plánem
     *        v `preprocess_log` (snapshot, D12). `analysis_state` se počítá
     *        beze změny — osy jsou ortogonální (D9), frontu hlídá gate.
     */
    private function insertIncomingMessage(
        array $fields,
        int $mailboxId,
        int $authorId,
        ?array $matchedRule = null,
        ?array $preprocessPlan = null,
    ): int {
        $doc = $this->documentRegistry->getDocument(self::MAIL_TABLE);
        $dibi = $this->db->getDibiConnection();
        $doc->setDb($dibi);

        $data = [
            'mailbox' => $mailboxId,
            'subject' => $fields['subject'],
            'sender_email' => $fields['sender_email'],
            'sender_name' => $fields['sender_name'],
            'received_at' => $fields['received_at'],
            'external_message_id' => $fields['external_message_id'],
            'in_reply_to' => $fields['in_reply_to'],
            'reply_references' => $fields['reply_references'],
            'body_plain' => $fields['body_plain'],
            'body_html' => $fields['body_html'],
            'source_type' => $fields['source_type'],
            'is_bulk' => (int) ($fields['is_bulk'] ?? 0),
            'created_by' => $authorId,
        ];

        if ($matchedRule !== null) {
            $data['docState'] = self::DOC_STATE_ARCHIVED;
            $data['docStateMain'] = $this->resolveIncomingMainState(self::DOC_STATE_ARCHIVED);
            // beforeSave by pro docState 80 vrátil 0 sám; explicitně kvůli čitelnosti.
            $data['analysis_state'] = 0;
            $data['auto_disposed_by'] = (int) $matchedRule['id'];
            $data['auto_disposed_at'] = date('Y-m-d H:i:s');
        }

        if ($preprocessPlan !== null) {
            $data['preprocess_state'] = PreprocessRunner::STATE_PENDING;
            $data['preprocess_log'] = PreprocessRunner::encodeLog([
                'plan' => $preprocessPlan,
                'results' => [],
                'attempts' => 0,
                'createdAt' => date('c'),
            ]);
        }

        $validation = $doc->validate($data);
        if (!$validation->isValid()) {
            $first = $validation->getErrors()[0];
            throw new \RuntimeException(
                "Validation failed on {$first->column}: {$first->message}",
            );
        }

        $doc->beforeSave($data);

        $dibi->insert(self::MAIL_TABLE, $data)->execute();
        return (int) $dibi->getInsertId();
    }

    /**
     * Odstraní soubory uložené na disk během neúspěšné transakce.
     * DB záznamy rollback odstraní, orphan files zůstanou.
     *
     * @param list<array<string, mixed>> $uploadedFiles
     */
    private function cleanupOrphanedFiles(array $uploadedFiles): void
    {
        foreach ($uploadedFiles as $att) {
            $filePath = (string) ($att['file_path'] ?? '');
            $fileName = (string) ($att['file_name'] ?? '');
            if ($filePath === '' || $fileName === '') {
                continue;
            }
            $fullPath = $this->dsPath . '/att/' . $filePath . '/' . $fileName;
            if (is_file($fullPath)) {
                @unlink($fullPath);
            }
        }
    }

    private static function nullIfEmpty(mixed $value): ?string
    {
        $s = trim((string) ($value ?? ''));
        return $s !== '' ? $s : null;
    }
}

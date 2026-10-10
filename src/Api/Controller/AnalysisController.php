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
use Shipard\Module\Core\Mail\Analysis\AnalysisServices;
use Shipard\Module\Core\Mail\Analysis\AnalysisStates;
use Shipard\Module\Core\Mail\IncomingMessageDocument;
use Shipard\Module\Core\Mail\MessageProposalApplier;
use Shipard\Module\Core\Mail\PrimaryTypes;
use Shipard\Module\Core\Mail\ProposalApplyOutcome;
use Shipard\Module\Docs\Core\OwnCompanyResolver;

/**
 * Akce nad analyzovanou zprávou `/_mail/messages/{ndx}/…` — reanalýza,
 * použití / zamítnutí / vrácení návrhu, náhled a rozhodnutí z review
 * modalu (docs/mail/api-contract.md §9). Auth: běžný uživatelský token.
 *
 * Samotnou analýzu dělá runner v procesu nad službami
 * `Shipard\Module\Core\Mail\Analysis\*` (`AnalysisQueue`,
 * `AnalysisClaimService`, `AnalysisResultWriter`; tasks/mail-analysis-inprocess.md
 * D13). Strojový pull protokol `/_mail/analysis/*` pro externí analyzer
 * byl zrušen (#85 D20, tasks/ai-analyzer-removal.md) — tyto cesty vrací 404.
 */
class AnalysisController
{
    public const MAIL_TABLE_ID = 303;

    private const MESSAGES_TABLE = 'core_mail_incoming_messages';
    private const MAILBOXES_TABLE = 'core_mail_mailboxes';
    private const ANALYSES_TABLE = 'core_mail_message_analyses';
    private const PROFILES_TABLE = 'core_mail_ai_profiles';
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
     * (tasks/mail-analysis-inprocess.md D13). Controller z nich používá
     * jen gate fronty při reanalýze.
     */
    private ?AnalysisServices $services = null;

    /**
     * SchemaValidator, DocumentApplier a enricher jsou nullable kvůli
     * jednotkovým testům a degradaci bez kompilované konfigurace: bez
     * applieru `previewMessage` / `applyMessage` vrací chybu nastavení,
     * bez enricheru se náhled neobohacuje.
     *
     * @param array<string, TableDefinition> $tables
     * @param \Closure(int): void|null $analysisSpawner Detached spawn runneru
     *        AI analýzy po reanalýze (tasks/mail-analysis-inprocess.md D14) —
     *        wiring jen v public/index.php; null = bez spawnu (sweep).
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
        private readonly ?\Closure $analysisSpawner = null,
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

        $this->spawnAnalysis($messageNdx);

        return Response::success([
            'message_ndx' => $messageNdx,
            'profile_override_ndx' => $profileOverrideNdx,
        ]);
    }

    /**
     * Detached spawn runneru analýzy po reanalýze (D14) — po commitu, jen
     * je-li zpráva ve frontě (gate předzpracování, bez claimu); bez wiringu
     * nic — dohledá ji sweep. Selhání jen zalogovat.
     */
    private function spawnAnalysis(int $messageNdx): void
    {
        if ($this->analysisSpawner === null) {
            return;
        }
        try {
            if ($this->services()->queue->isEligible($messageNdx)) {
                ($this->analysisSpawner)($messageNdx);
            }
        } catch (\Throwable $e) {
            ErrorLogger::logException($e, 'AnalysisController reanalyze spawn failed — sweep will pick the message up');
        }
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

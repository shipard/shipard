<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Document;

use Dibi\Connection;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Document\DocumentEventDispatcher;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Module\Base\Persons\PersonType;
use Shipard\Module\Core\Exchange\Common\ApplyResult;
use Shipard\Module\Core\Exchange\Common\TransactionlessTableGateway;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Docs\Core\BoundNumberSeriesProvisioner;
use Shipard\Module\Docs\Core\DocDocument;
use Shipard\Module\Docs\Core\OwnCompanyResolver;
use Shipard\Module\Docs\Core\RoundingModes;
use Shipard\Module\Core\Exchange\Resolve\AccountResolver;
use Shipard\Module\Core\Exchange\Resolve\BankAccountResolver;
use Shipard\Module\Core\Exchange\Resolve\ItemResolver;
use Shipard\Module\Core\Exchange\Resolve\PartyResolver;
use Shipard\Module\Core\Exchange\Resolve\ResolveResult;
use Shipard\Module\Core\Exchange\Resolve\ResolveStatus;
use Shipard\Module\Core\Exchange\Resolve\UnitResolver;
use Shipard\Module\Core\Exchange\Resolve\VatCodeResolver;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;

/**
 * Orchestrator of the canonical → DB save pipeline. See
 * docs/exchange-format.md §10 for the full step sequence.
 *
 *   /validate  — schema + DocumentValidator, no DB writes, no resolve.
 *   /preview   — validate + full resolve, populates `_resolve`.
 *   /apply     — validate + resolve + reconcile with userAction +
 *                outer transaction { side-creates + saveDocument +
 *                lineage update }.
 *
 * The Applier never reaches below the Document layer — all business
 * logic (number assignment, snapshots, totals, recap) stays in
 * DocDocument::beforeSave. Applier's job is only to translate
 * canonical → internal $data and call the existing TableGateway.
 */
class DocumentApplier
{
    public const FORMAT_ID = 'shpd.docs.document';
    public const FORMAT_VERSION = '1';

    private const ACTIVE_STATES = [10, 40, 80];

    /**
     * States an explicit `useExisting:<id>` pin may target. Archived (70)
     * records are legitimate historical references — migrated documents
     * routinely point at partners/items archived since. Only Deleted (90)
     * is rejected. Fresh resolve stays limited to ACTIVE_STATES.
     */
    private const LINKABLE_STATES = [10, 40, 70, 80];

    /** Map canonical vat.mode → docs_core_heads.vat_mode (cfgItem docs.core.vatModes). */
    private const VAT_MODE_MAP = [
        'none'      => 0,
        'fromBase'  => 1,
        'fromTotal' => 2,
    ];

    /** Map canonical vat.place → docs_core_heads.vat_place (cfgItem docs.core.vatPlaces). */
    /** Canonical `vat.recapSource` → docs_core_heads.vat_recap_source. */
    private const VAT_RECAP_SOURCE_MAP = [
        'computed' => 0,
        'declared' => 1,
    ];

    /** Canonical `vat.calcSource` → docs_core_heads.vat_calc_source. */
    private const VAT_CALC_SOURCE_MAP = [
        'header' => 0,
        'rows'   => 1,
    ];

    /**
     * Canonical `vat.controlStatementMode` → docs_core_heads.cs_mode
     * (extension economy.vat, cfgItem economy.vat.controlStatementModes, #77).
     * `auto` se do payloadu nedává — sloupec má default 0 a na DS bez
     * economy.vat by neexistoval; ruční režim tam naopak selhat má.
     */
    private const CS_MODE_MAP = [
        'auto'      => 0,
        'detail'    => 1,
        'aggregate' => 2,
        'exclude'   => 3,
    ];

    private const VAT_PLACE_MAP = [
        'domestic'    => 0,
        'intracom'    => 1,
        'thirdCountry' => 2,
    ];

    /** Map canonical payment.method → docs_core_heads.payment_method. */
    private const PAYMENT_METHOD_MAP = [
        'cash'           => 0,
        'bankTransfer'   => 1,
        'card'           => 2,
        'cashOnDelivery' => 3,
        'setOff'         => 4,
        'paymentGateway' => 5,
    ];

    /** Map canonical row.priceCalcMode → docs_core_rows.price_calc_mode. */
    private const PRICE_CALC_MODE_MAP = [
        'fromUnitPrice' => 0,
        'fromTotal'     => 1,
    ];

    /** Map canonical rowKind → docs_core_rows.row_kind. */
    private const ROW_KIND_MAP = [
        'text'    => 0,
        'item'    => 1,
        'section' => 2,
    ];

    /**
     * Map canonical docType (descriptive name from docs/exchange-format.md
     * section 5) → docs.core.docTypes cfgItem key (short code stored in
     * docs_core_heads.doc_type). Passing the short code directly is also
     * accepted — passthrough when no alias matches.
     */
    private const DOC_TYPE_MAP = [
        'invoiceReceived'      => 'invni',
        'invoiceIssued'        => 'invno',
        'accountingDocument'   => 'cmnbkp',
        'cashDocument'         => 'cash',
        'cashRegisterDocument' => 'cashreg',
    ];

    /**
     * Apply-time options pulled from `$canonical['applyOptions']` at the
     * start of apply(). Read by resolveOne() for autoCreateMode behaviour.
     * Reset to [] on every apply().
     *
     * @var array<string, mixed>
     */
    private array $applyOptionsCache = [];

    public function __construct(
        private readonly Connection $db,
        private readonly ConfigRuntime $config,
        private readonly TransactionlessTableGateway $headsGateway,
        private readonly TransactionlessTableGateway $personsGateway,
        private readonly TransactionlessTableGateway $itemsGateway,
        private readonly SchemaValidator $schemaValidator,
        private readonly DocumentValidator $documentValidator,
        private readonly PartyResolver $partyResolver,
        private readonly ItemResolver $itemResolver,
        private readonly UnitResolver $unitResolver,
        private readonly VatCodeResolver $vatCodeResolver,
        private readonly BankAccountResolver $bankAccountResolver,
        private readonly AccountResolver $accountResolver,
    ) {}

    /**
     * Production factory wiring a full Applier with all resolvers and
     * gateways from a DataSourceConnection-backed environment.
     *
     * @param array<string, TableDefinition> $tables Indexed by table name —
     *        used to fish out childTables config for each gateway.
     */
    public static function create(
        Connection $db,
        ConfigRuntime $config,
        DataSourceConfig $dsConfig,
        DocumentRegistry $registry,
        array $tables,
        ?DocumentEventDispatcher $eventDispatcher = null,
    ): self {
        $vatRateResolver = new \Shipard\Module\World\Vat\VatRateResolver($config);
        $own = new OwnCompanyResolver($db);

        return new self(
            db: $db,
            config: $config,
            // Only the heads gateway needs the event dispatcher — a document
            // saved at state 40 must trigger DocsHeadsEventHandler (accounting).
            // Persons/items never transition to an accounted state.
            headsGateway: self::buildGateway('docs_core_heads', $db, $registry, $config, $dsConfig, $tables, $eventDispatcher),
            personsGateway: self::buildGateway('base_persons_persons', $db, $registry, $config, $dsConfig, $tables),
            itemsGateway: self::buildGateway('economy_items', $db, $registry, $config, $dsConfig, $tables),
            schemaValidator: new SchemaValidator(SchemaLoader::default()),
            documentValidator: new DocumentValidator(),
            partyResolver: new PartyResolver($db, $own),
            itemResolver: new ItemResolver($db),
            unitResolver: new UnitResolver($db),
            vatCodeResolver: new VatCodeResolver($vatRateResolver),
            bankAccountResolver: new BankAccountResolver($db),
            accountResolver: new AccountResolver($db),
        );
    }

    /**
     * @param array<string, TableDefinition> $tables
     */
    private static function buildGateway(
        string $tableName,
        Connection $db,
        DocumentRegistry $registry,
        ConfigRuntime $config,
        DataSourceConfig $dsConfig,
        array $tables,
        ?DocumentEventDispatcher $eventDispatcher = null,
    ): TransactionlessTableGateway {
        $childTables = $tables[$tableName]?->childTables ?? [];
        return new TransactionlessTableGateway(
            $tableName,
            $db,
            $registry,
            $childTables,
            $config,
            $dsConfig,
            $eventDispatcher,
            $tables[$tableName]?->docStates,
        );
    }

    // ── Public entry points ─────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $canonical
     */
    public function validate(array $canonical): ApplyResult
    {
        $schemaIssues = $this->schemaValidator->validate($canonical, self::FORMAT_ID, self::FORMAT_VERSION);
        if ($schemaIssues !== []) {
            return ApplyResult::error(
                code: 'schema_invalid',
                message: 'Struktura dokumentu neodpovídá schématu.',
                canonical: $this->withResolveIssues($canonical, $schemaIssues),
                statusCode: 400,
            );
        }

        $validatorIssues = $this->documentValidator->validate($canonical);
        $enriched = $this->withResolveIssues($canonical, $validatorIssues);

        if ($this->hasErrors($validatorIssues)) {
            return ApplyResult::error(
                code: 'validation_failed',
                message: 'Validace dokumentu selhala.',
                canonical: $enriched,
                statusCode: 422,
            );
        }

        return ApplyResult::ok($enriched);
    }

    /**
     * @param array<string, mixed> $canonical
     */
    public function preview(array $canonical): ApplyResult
    {
        // 1. Static checks first; schema errors abort early.
        $schemaIssues = $this->schemaValidator->validate($canonical, self::FORMAT_ID, self::FORMAT_VERSION);
        if ($schemaIssues !== []) {
            return ApplyResult::error(
                'schema_invalid',
                'Struktura dokumentu neodpovídá schématu.',
                $this->withResolveIssues($canonical, $schemaIssues),
                statusCode: 400,
            );
        }

        // 2. Semantic checks + resolve. Both contribute to _resolve.issues.
        $issues = $this->documentValidator->validate($canonical);
        $this->appendVatModeIssue($canonical, $issues);
        $this->appendRecapSourceIssue($canonical, $issues);
        $resolved = $this->resolveAll($canonical, $issues);
        $enriched = $this->withResolve($canonical, $resolved, $issues);

        // preview always succeeds even with errors — client renders the
        // payload and decides what to do.
        return ApplyResult::ok($enriched);
    }

    /**
     * @param array<string, mixed> $canonical
     */
    public function apply(array $canonical): ApplyResult
    {
        $this->applyOptionsCache = is_array($canonical['applyOptions'] ?? null)
            ? $canonical['applyOptions']
            : [];

        // 0. Idempotency check — same extracted_document already applied?
        //    Return existing savedDocId without re-saving. See Phase 2 spec
        //    "Idempotency apply".
        $idempotent = $this->checkIdempotent($canonical);
        if ($idempotent !== null) {
            return $idempotent;
        }

        // 1+2. Schema + DocumentValidator.
        $schemaIssues = $this->schemaValidator->validate($canonical, self::FORMAT_ID, self::FORMAT_VERSION);
        if ($schemaIssues !== []) {
            return ApplyResult::error(
                'schema_invalid',
                'Struktura dokumentu neodpovídá schématu.',
                $this->withResolveIssues($canonical, $schemaIssues),
                statusCode: 400,
            );
        }
        $validatorIssues = $this->documentValidator->validate($canonical);
        $this->appendVatModeIssue($canonical, $validatorIssues);
        $this->appendRecapSourceIssue($canonical, $validatorIssues);

        // 3. Re-run resolve (fresh DB read; client's _resolve might be stale).
        $resolved = $this->resolveAll($canonical, $validatorIssues);

        // 4. Reconcile with client _resolve.*.userAction.
        $clientResolve = is_array($canonical['_resolve'] ?? null) ? $canonical['_resolve'] : [];
        $plan = $this->reconcile($resolved, $clientResolve, $validatorIssues);

        $enriched = $this->withResolve($canonical, $resolved, $validatorIssues);

        if ($plan['errorCode'] !== null) {
            return ApplyResult::error(
                $plan['errorCode'],
                $plan['errorMessage'] ?? 'Reconcile selhal.',
                $enriched,
                statusCode: $plan['errorCode'] === 'conflict' ? 409 : 422,
            );
        }

        // 5. Validation gate — errors block save.
        if ($this->hasErrors($validatorIssues)) {
            return ApplyResult::error('validation_failed', 'Validace dokumentu selhala.', $enriched);
        }

        // 5a. Pokladna kódem (#59 D12): u typů s řadou vázanou na pokladnu
        //     (cash, cashreg) určuje řadu; u faktur jde do cash_desk hlavičky,
        //     ale jen při platbě hotově. Neznámý kód = čistá 422 tady.
        $docTypeCode = $this->mapDocType($canonical);
        $cashDeskCode = $canonical['cashDesk'] ?? null;
        $cashDeskCode = is_string($cashDeskCode) && trim($cashDeskCode) !== '' ? trim($cashDeskCode) : null;
        $cashDeskId = null;
        if ($cashDeskCode !== null) {
            $cashDeskId = $this->resolveCashDeskIdByCode($cashDeskCode);
            if ($cashDeskId === null) {
                return ApplyResult::error(
                    'cash_desk_not_found',
                    "Pokladna s kódem '{$cashDeskCode}' nebyla nalezena (economy_codebooks_cash_desks).",
                    $enriched,
                    statusCode: 422,
                );
            }
        }

        // 5b. Resolve the target number series up-front. An explicit but
        //     unknown numberSeriesCode must fail as a clean apply-level error
        //     (422) here, not blow up mid-transaction as internal_error (500).
        //     Vázaný typ: řada = (doc_type, pokladna), numberSeriesCode se
        //     ignoruje. Chybí-li řada, nejdřív ji zkusí založit provisioner
        //     (idempotentní; pokladna importovaná přes generický CRUD nespustí
        //     afterSave handler, takže řady může dostat až tady) — teprve
        //     pokladna mimo stav 40 nebo neexistující je chyba. Archivovaná
        //     pokladna (70) má řady v archivu a přijme ji jen import
        //     (applyOptions.importNumber) — historické doklady jsou legitimní,
        //     živý doklad na ni založit nejde (#59 Task E, E2).
        if ($this->isCashDeskBoundDocType($docTypeCode)) {
            $importMode = is_array($canonical['applyOptions']['importNumber'] ?? null);
            $numberSeriesId = $cashDeskId !== null
                ? $this->resolveBoundNumberSeries($docTypeCode, $cashDeskId, $importMode)
                : null;
            if ($numberSeriesId === null && $cashDeskId !== null) {
                (new BoundNumberSeriesProvisioner(new DataSourceConnection($this->db), $this->config))
                    ->provisionForCashDesk($cashDeskId);
                $numberSeriesId = $this->resolveBoundNumberSeries($docTypeCode, $cashDeskId, $importMode);
            }
            if ($numberSeriesId === null) {
                return ApplyResult::error(
                    'cash_desk_not_found',
                    "Pokladna '" . ($cashDeskCode ?? '') . "' nemá číselnou řadu typu {$docTypeCode}"
                    . ' a nelze ji založit — pokladna není ve stavu V pořádku (40); archivovanou pokladnu přijme jen import.',
                    $enriched,
                    statusCode: 422,
                );
            }
        } else {
            $seriesCode = $canonical['applyOptions']['numberSeriesCode'] ?? null;
            $seriesCode = is_string($seriesCode) && $seriesCode !== '' ? $seriesCode : null;
            try {
                $numberSeriesId = $this->resolveNumberSeriesFor($docTypeCode, $seriesCode);
            } catch (NumberSeriesNotFoundException $e) {
                return ApplyResult::error('number_series_not_found', $e->getMessage(), $enriched, statusCode: 422);
            }
            if ($cashDeskId !== null && ($canonical['payment']['method'] ?? null) !== 'cash') {
                // DocDocument::validate by odmítl (cash_desk_requires_cash_payment).
                $validatorIssues[] = [
                    'severity' => 'warning',
                    'path'     => 'cashDesk',
                    'code'     => 'cash_desk_ignored',
                    'message'  => 'Pokladna má na faktuře smysl jen při platbě hotově — ignorováno.',
                ];
                $cashDeskId = null;
            }
        }
        $plan['cashDeskId'] = $cashDeskId;

        // 5c. Import mode: vlastní bankovní účet zadaný kódem číselníku
        //     (datové sady, #40) → id. Neznámý kód = čistá 422 tady, ne pád
        //     v transakci.
        $ownBank = $canonical['applyOptions']['importOwnBankAccount'] ?? null;
        if (is_string($ownBank)) {
            $ownBankId = $this->resolveOwnBankAccountByCode($ownBank);
            if ($ownBankId === null) {
                return ApplyResult::error(
                    'own_bank_account_not_found',
                    "Vlastní bankovní účet s kódem '{$ownBank}' nebyl nalezen (economy_codebooks_bank_accounts).",
                    $enriched,
                    statusCode: 422,
                );
            }
            $canonical['applyOptions']['importOwnBankAccount'] = $ownBankId;
        }

        // 6–11. Transactional save.
        $this->db->begin();
        try {
            // Side-creates first so we have ids to link in the doc.
            $sideCreatedIds = $this->runSideCreates($plan, $resolved);

            // Doplnění pohybu (operation) item řádkům — až po side-creates,
            // kdy jsou finální ID položek v DB (matched i právě založené),
            // takže item_type se čte jednotně přes ID. Issues se propíší do
            // finální response přes withResolve() po commitu.
            $plan['rowOperationDefaults'] = $this->defaultRowOperationsForApply(
                $canonical, $plan, $sideCreatedIds, $validatorIssues,
            );

            // Transform canonical → internal $data.
            $data = $this->transform($canonical, $plan, $sideCreatedIds, $numberSeriesId);

            // Save doc head + rows + vat_recap through DocDocument.
            $result = $this->headsGateway->saveDocument($data);
            if (!$result->isSuccess()) {
                // DocumentResult::validationFailed() leaves errorMessage null
                // and carries field-level errors in ValidationResult — bubble
                // them up explicitly, otherwise the caller only sees the
                // generic "unknown error" placeholder.
                $msg = $result->getErrorMessage();
                if ($msg === null && $result->getValidation() !== null) {
                    $parts = array_map(
                        static fn($e) => ($e->column !== '' ? $e->column . ': ' : '') . $e->message,
                        $result->getValidation()->getErrors(),
                    );
                    $msg = $parts !== [] ? implode('; ', $parts) : null;
                }
                throw new \RuntimeException('Save failed: ' . ($msg ?? 'unknown error'));
            }
            $savedDocId = (int) $result->getData()['id'];

            // Per-partner item-code mapping learning — only after we know
            // which row.item ids ended up actually linked.
            $this->writeSupplierCodeMappings($canonical, $plan, $sideCreatedIds);

            // Lineage targets only — analysis resolution / message docState
            // update lives in MessageProposalApplier so the verdict write
            // stays one place. See tasks/mail-message-centric.md D6.
            $this->writeLineageTargets($canonical, $savedDocId);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            return ApplyResult::error('internal_error', $e->getMessage(), $enriched, statusCode: 500);
        }

        // Mark canCreate references as matched (with newly-assigned ids).
        $resolved = $this->annotateSideCreated($resolved, $sideCreatedIds);
        $resolved = $this->annotateNoItemRows($resolved, $plan['rowNoItems']);
        $finalCanonical = $this->withResolve($canonical, $resolved, $validatorIssues);
        $finalCanonical['savedDocId'] = $savedDocId;

        return ApplyResult::ok($finalCanonical, $savedDocId);
    }

    // ── Resolve all references ──────────────────────────────────────────────

    /**
     * @param array<string, mixed> $canonical
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     *        Reference; resolvers append issues for missing references.
     * @return array<string, mixed>  `_resolve`-shaped resolve data.
     */
    private function resolveAll(array $canonical, array &$issues): array
    {
        // Účetní doklad (cmnbkp): nemá supplier/customer/selfParty — saldo
        // identita žije per řádek. Resolvujeme jen řádky (item + účet z čísla).
        $docTypeCode = $this->mapDocType($canonical);
        $isAccountingDoc = $docTypeCode === 'cmnbkp';
        // Pokladní doklad / prodejka: strany nepovinné (anonymní doklad) —
        // chybějící strana se neresolvuje, jinak by skončila unresolved_required.
        $partiesOptional = $this->isCashDeskBoundDocType($docTypeCode);

        $supplierResult = null;
        $customerResult = null;
        $supplierBankResult = null;
        $supplierPersonId = null;
        $supplierCountry = '';

        if (!$isAccountingDoc) {
            $selfParty = $canonical['selfParty'] ?? null;
            $supplier = is_array($canonical['supplier'] ?? null) ? $canonical['supplier'] : [];
            $customer = is_array($canonical['customer'] ?? null) ? $canonical['customer'] : [];
            $supplierCountry = strtolower((string) ($supplier['country'] ?? ''));

            if ($selfParty === 'supplier') {
                $supplierResult = $this->partyResolver->resolveSelfParty();
            } elseif (!$partiesOptional || $supplier !== []) {
                $supplierResult = $this->partyResolver->resolve($supplier);
            }
            if ($selfParty === 'customer') {
                $customerResult = $this->partyResolver->resolveSelfParty();
            } elseif (!$partiesOptional || $customer !== []) {
                $customerResult = $this->partyResolver->resolve($customer);
            }

            $supplierPersonId = $supplierResult !== null && $supplierResult->status === ResolveStatus::Matched
                ? $supplierResult->matchedId
                : null;

            if (is_array($supplier['bankAccount'] ?? null) && $supplier['bankAccount'] !== []) {
                $supplierBankResult = $this->bankAccountResolver->resolvePartnerBank(
                    $supplier['bankAccount'],
                    $supplierPersonId,
                );
            }
        }

        // Osoba pro saldokonto (#72): ruční plátce z payloadu — resolvuje se
        // jako strana (bez selfParty), u každého typu dokladu; chybějící =
        // plátce odvodí DocDocument při uložení.
        $balancePartyResult = null;
        $balanceParty = is_array($canonical['balanceParty'] ?? null) ? $canonical['balanceParty'] : [];
        if ($balanceParty !== []) {
            $balancePartyResult = $this->partyResolver->resolve($balanceParty);
        }

        $rowsResolve = [];
        $rows = is_array($canonical['rows'] ?? null) ? $canonical['rows'] : [];
        $vatCountry = strtolower((string) ($canonical['vat']['registrationCountry'] ?? ''));
        $taxPointDate = $canonical['dates']['taxPointDate'] ?? ($canonical['dates']['issueDate'] ?? null);

        foreach ($rows as $idx => $row) {
            $rowResolve = ['index' => $idx];
            if (is_array($row['item'] ?? null) && $row['item'] !== []) {
                $rowResolve['item'] = $this->itemResolver->resolve($row['item'], $supplierPersonId)->toArray();
            }

            // Účet z čísla (kontační řádek acc.record). Nenalezeno → warning,
            // řádek skončí jako chybový až při účtování — neblokovat import.
            if (isset($row['account']) && (string) $row['account'] !== '') {
                $accountId = $this->accountResolver->resolve((string) $row['account']);
                $rowResolve['account'] = [
                    'number'    => (string) $row['account'],
                    'matchedId' => $accountId,
                    'status'    => $accountId !== null ? 'matched' : 'notFound',
                ];
                if ($accountId === null) {
                    $issues[] = [
                        'severity' => 'warning',
                        'path'     => "rows.{$idx}.account",
                        'code'     => 'account_not_found',
                        'message'  => "Účet „{$row['account']}\" nebyl v rozvrhu nalezen; řádek bude bez účtu.",
                    ];
                }
            }
            if (!empty($row['unit'])) {
                $unitR = $this->unitResolver->resolve((string) $row['unit']);
                $rowResolve['unit'] = $unitR->toArray();
                if ($unitR->status === ResolveStatus::NotFound) {
                    $issues[] = [
                        'severity' => 'warning',
                        'path'     => "rows.{$idx}.unit",
                        'code'     => 'unit_not_found',
                        'message'  => "Jednotka „{$row['unit']}\" nebyla rozpoznána; bude doplněna default.",
                    ];
                }
            }
            if (is_array($row['vat'] ?? null) && !empty($row['vat']['code'])) {
                $rowVatCountry = $this->vatCountryForCode((string) $row['vat']['code'], $vatCountry, $supplierCountry);
                $vatR = $this->vatCodeResolver->resolve(
                    (string) $row['vat']['code'],
                    $rowVatCountry !== '' ? $rowVatCountry : null,
                    is_string($taxPointDate) ? $taxPointDate : null,
                    isset($row['vat']['pct']) ? (float) $row['vat']['pct'] : null,
                );
                $rowResolve['vatCode'] = $vatR->toArray();
                if ($vatR->status === ResolveStatus::NotFound) {
                    $issues[] = [
                        'severity' => 'error',
                        'path'     => "rows.{$idx}.vat.code",
                        'code'     => 'vat_code_unknown',
                        'message'  => "Neznámý kód DPH „{$row['vat']['code']}\".",
                    ];
                }
            }
            $rowsResolve[] = $rowResolve;
        }

        $resolved = ['rows' => $rowsResolve];
        // For accounting documents these stay unset → reconcile skips them
        // (no supplier/customer/bank to reconcile, no unresolved_required).
        if ($supplierResult !== null) {
            $resolved['supplier'] = $supplierResult->toArray();
        }
        if ($customerResult !== null) {
            $resolved['customer'] = $customerResult->toArray();
        }
        if ($balancePartyResult !== null) {
            $resolved['balanceParty'] = $balancePartyResult->toArray();
        }
        if ($supplierBankResult !== null) {
            $resolved['supplierBank'] = $supplierBankResult->toArray();
        }
        return $resolved;
    }

    // ── Reconcile: validate userAction → execution plan ─────────────────────

    /**
     * @param array<string, mixed> $resolved        Fresh resolve output.
     * @param array<string, mixed> $clientResolve   Client's _resolve with userAction set.
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     * @return array{
     *   errorCode: ?string, errorMessage: ?string,
     *   partyCreates: array<string, array<string, mixed>>,
     *   bankCreate: ?array<string, mixed>,
     *   rowItemCreates: array<int, array<string, mixed>>,
     *   rowSkips: list<int>,
     *   resolvedSupplier: ?int, resolvedCustomer: ?int, resolvedBalanceParty: ?int,
     *   resolvedSupplierBank: ?int,
     *   resolvedRowItems: array<int, int|null>,
     *   resolvedRowUnits: array<int, int|null>,
     *   resolvedRowVatCodes: array<int, array<string, mixed>|null>
     * }
     */
    private function reconcile(array $resolved, array $clientResolve, array &$issues): array
    {
        $plan = [
            'errorCode'           => null,
            'errorMessage'        => null,
            'partyCreates'        => [],
            'bankCreate'          => null,
            'rowItemCreates'      => [],
            'rowSkips'            => [],
            'rowNoItems'          => [],
            'resolvedSupplier'    => null,
            'resolvedCustomer'    => null,
            'resolvedBalanceParty' => null,
            'resolvedSupplierBank'=> null,
            'resolvedRowItems'    => [],
            'resolvedRowUnits'    => [],
            'resolvedRowVatCodes' => [],
            'resolvedRowAccounts' => [],
            'resolvedRowPartners' => [],
            'resolvedHeadPartner' => null,
        ];

        // Hlavičkový partner účetního dokladu — nepovinný, pin přes
        // _resolve.partner (useExisting:<id>). Bez pinu zůstává null.
        $plan['resolvedHeadPartner'] = $this->resolvePin(
            'partner',
            is_array($clientResolve['partner'] ?? null) ? ($clientResolve['partner']['userAction'] ?? null) : null,
            $issues,
        );

        foreach (['supplier', 'customer', 'balanceParty'] as $partyKey) {
            $fresh = $resolved[$partyKey] ?? null;
            $client = $clientResolve[$partyKey] ?? null;
            if ($fresh === null) {
                continue;
            }
            $userAction = is_array($client) ? ($client['userAction'] ?? null) : null;
            $res = $this->resolveOne($partyKey, $fresh, $userAction, 'base_persons_persons', $plan, $issues);
            $plan["resolved" . ucfirst($partyKey)] = $res['id'];
            if ($res['autoCreate']) {
                $plan['partyCreates'][$partyKey] = $fresh['createPayload'] ?? [];
            }
        }

        if (isset($resolved['supplierBank'])) {
            $fresh = $resolved['supplierBank'];
            $client = $clientResolve['supplierBank'] ?? null;
            $userAction = is_array($client) ? ($client['userAction'] ?? null) : null;
            $res = $this->resolveOne('supplierBank', $fresh, $userAction, 'base_persons_bank_accounts', $plan, $issues);
            $plan['resolvedSupplierBank'] = $res['id'];
            if ($res['autoCreate']) {
                $plan['bankCreate'] = $fresh['createPayload'] ?? [];
            }
        }

        $clientRows = is_array($clientResolve['rows'] ?? null) ? $clientResolve['rows'] : [];
        foreach ($resolved['rows'] ?? [] as $i => $rowResolve) {
            $clientRow = $clientRows[$i] ?? null;
            $rowUserAction = is_array($clientRow) ? ($clientRow['userAction'] ?? null) : null;
            if ($rowUserAction === 'skip') {
                $plan['rowSkips'][] = $i;
                continue;
            }

            $itemFresh = $rowResolve['item'] ?? null;
            if ($itemFresh !== null) {
                $itemClient = is_array($clientRow['item'] ?? null) ? $clientRow['item'] : null;
                $itemAction = $itemClient['userAction'] ?? null;
                if ($itemAction === 'noItem') {
                    // „Jen účet — bez položky" (D24): řádek se pořídí bez item
                    // FK. resolveOne se nevolá — pin přebíjí fresh status i
                    // enrichment návrh (D3). Povinnost účtu se validuje níž,
                    // až je resolvedRowAccounts pro řádek známé.
                    $plan['rowNoItems'][] = $i;
                    $plan['resolvedRowItems'][$i] = null;
                } else {
                    $itemRes = $this->resolveOne("rows.{$i}.item", $itemFresh, $itemAction, 'economy_items', $plan, $issues);
                    $plan['resolvedRowItems'][$i] = $itemRes['id'];
                    if ($itemRes['autoCreate']) {
                        $plan['rowItemCreates'][$i] = $itemFresh['createPayload'] ?? [];
                    }
                }
            } else {
                $plan['resolvedRowItems'][$i] = null;
            }

            $unitFresh = $rowResolve['unit'] ?? null;
            $plan['resolvedRowUnits'][$i] = ($unitFresh['status'] ?? null) === 'matched'
                ? ($unitFresh['matchedId'] ?? null)
                : null;

            $plan['resolvedRowVatCodes'][$i] = $rowResolve['vatCode'] ?? null;

            // Účet z čísla (kontace) — passthrough, žádná userAction (číslo je
            // autoritativní; nenalezeno už dalo warning v resolveAll).
            $accountFresh = $rowResolve['account'] ?? null;
            $plan['resolvedRowAccounts'][$i] = ($accountFresh['status'] ?? null) === 'matched'
                ? ($accountFresh['matchedId'] ?? null)
                : null;

            // Řádek s pinem noItem bez naresolvovaného účtu nemá co účtovat —
            // apply musí selhat srozumitelně, ne až při účtování konceptu.
            if (in_array($i, $plan['rowNoItems'], true) && $plan['resolvedRowAccounts'][$i] === null) {
                $plan['errorCode'] = 'no_item_requires_account';
                $plan['errorMessage'] = "Řádek {$i}: volba „jen účet — bez položky\" vyžaduje platný účet.";
                $issues[] = [
                    'severity' => 'error',
                    'path'     => "rows.{$i}.item",
                    'code'     => 'no_item_requires_account',
                    'message'  => 'Volba „jen účet — bez položky" vyžaduje řádek s platným účtem.',
                ];
            }

            // Per-řádkový partner — pin přes _resolve.rows[i].partner.
            $plan['resolvedRowPartners'][$i] = $this->resolvePin(
                "rows.{$i}.partner",
                is_array($clientRow['partner'] ?? null) ? ($clientRow['partner']['userAction'] ?? null) : null,
                $issues,
            );
        }

        return $plan;
    }

    /**
     * Resolve a `useExisting:<id>` pin against linkable persons (anything
     * except Deleted 90 — archived targets are allowed, see
     * LINKABLE_STATES). Returns the id when valid + linkable, null
     * otherwise (no pin / malformed). A pin pointing at a missing or
     * deleted record emits a warning issue instead of failing silently —
     * the partner is optional, but the caller must be able to see it was
     * dropped. Used for the optional accounting-document partners (head +
     * per row), which are pin-only in MVP — no fresh resolve, no
     * side-create.
     *
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    private function resolvePin(string $path, ?string $userAction, array &$issues): ?int
    {
        if (!is_string($userAction) || !str_starts_with($userAction, 'useExisting:')) {
            return null;
        }
        $idStr = substr($userAction, strlen('useExisting:'));
        if (!ctype_digit($idStr) || (int) $idStr <= 0) {
            return null;
        }
        $id = (int) $idStr;
        if (!$this->entityLinkable('base_persons_persons', $id)) {
            $issues[] = [
                'severity' => 'warning',
                'path'     => $path,
                'code'     => 'pin_target_missing',
                'message'  => "Cílový záznam base_persons_persons#{$id} pro „{$path}\" neexistuje nebo je smazaný — partner nebyl nastaven.",
            ];
            return null;
        }
        return $id;
    }

    /**
     * Generic per-reference reconcile.
     *
     * Returns {id: ?int, autoCreate: bool}:
     *   - `id`         — resolved DB id, or null when none (error or
     *                    pending side-create).
     *   - `autoCreate` — true when caller should schedule a side-create
     *                    from `$fresh['createPayload']`. Driven by
     *                    explicit `userAction = 'create'` OR by
     *                    `applyOptions.autoCreateMode` (liberal always;
     *                    safe when `safetyGuardOk()` passes).
     *
     * @param array<string, mixed> $fresh Fresh resolve output (toArray shape).
     * @param array<string, mixed> $plan  Modified in place on error.
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     * @return array{id: ?int, autoCreate: bool}
     */
    private function resolveOne(string $path, array $fresh, ?string $userAction, string $existsTable, array &$plan, array &$issues): array
    {
        $status = $fresh['status'] ?? null;

        if ($userAction === null) {
            if ($status === 'matched') {
                return ['id' => $fresh['matchedId'] ?? null, 'autoCreate' => false];
            }
            if ($status === 'canCreate' && $this->autoCreateAllowed($existsTable, $fresh)) {
                return ['id' => null, 'autoCreate' => true];
            }
            if ($status === 'canCreate' || $status === 'ambiguous' || $status === 'notFound') {
                $plan['errorCode'] = 'unresolved_required';
                $plan['errorMessage'] = "Reference „{$path}\" vyžaduje rozhodnutí (userAction).";
                $issues[] = [
                    'severity' => 'error',
                    'path'     => $path,
                    'code'     => 'unresolved_required',
                    'message'  => 'Nelze automaticky propojit; doplňte _resolve.userAction.',
                ];
            }
            return ['id' => null, 'autoCreate' => false];
        }

        if (str_starts_with($userAction, 'useExisting:')) {
            $idStr = substr($userAction, strlen('useExisting:'));
            if (!ctype_digit($idStr) || (int) $idStr <= 0) {
                $plan['errorCode'] = 'conflict';
                $plan['errorMessage'] = "Neplatné id v userAction pro „{$path}\".";
                return ['id' => null, 'autoCreate' => false];
            }
            $id = (int) $idStr;
            if (!$this->entityLinkable($existsTable, $id)) {
                $plan['errorCode'] = 'conflict';
                $plan['errorMessage'] = "Cílový záznam {$existsTable}#{$id} pro „{$path}\" neexistuje nebo je smazaný.";
                return ['id' => null, 'autoCreate' => false];
            }
            return ['id' => $id, 'autoCreate' => false];
        }

        if ($userAction === 'create') {
            if ($status !== 'canCreate') {
                $plan['errorCode'] = 'conflict';
                $plan['errorMessage'] = "userAction=\"create\" pro „{$path}\", ale resolve status je „{$status}\".";
                return ['id' => null, 'autoCreate' => false];
            }
            return ['id' => null, 'autoCreate' => true];
        }

        if ($userAction === 'skip') {
            return ['id' => null, 'autoCreate' => false];
        }

        $plan['errorCode'] = 'conflict';
        $plan['errorMessage'] = "Neznámá userAction „{$userAction}\" pro „{$path}\".";
        return ['id' => null, 'autoCreate' => false];
    }

    /**
     * Decide whether `userAction = null` on a `canCreate` reference should
     * be promoted to autocreate based on applyOptions.autoCreateMode.
     *
     * - `strict` (default) — never.
     * - `liberal`          — always.
     * - `safe`             — only if `$fresh['createPayload']` has enough
     *                        identifiers per {@see safetyGuardOk()}.
     *
     * @param array<string, mixed> $fresh
     */
    private function autoCreateAllowed(string $existsTable, array $fresh): bool
    {
        $mode = $this->applyOptionsCache['autoCreateMode'] ?? 'strict';
        if ($mode === 'liberal') {
            return true;
        }
        if ($mode === 'safe') {
            return $this->safetyGuardOk($existsTable, $fresh);
        }
        return false;
    }

    /**
     * Per-table minimum identifiers required for "safe" autocreate.
     * See exchange-format-phase2.md §"autoCreateMode" guard table.
     *
     * @param array<string, mixed> $fresh
     */
    private function safetyGuardOk(string $existsTable, array $fresh): bool
    {
        $payload = $fresh['createPayload'] ?? [];
        if (!is_array($payload)) {
            return false;
        }
        return match ($existsTable) {
            'base_persons_persons' => !empty($payload['company_id']),
            'economy_items' => !empty($payload['name']),
            'base_persons_bank_accounts' =>
                !empty($payload['iban']) || !empty($payload['account_number']),
            default => false,
        };
    }

    /**
     * True when the record exists in a linkable state (anything except
     * Deleted 90). Used for validating explicit `useExisting:<id>` pins —
     * archived (70) targets are allowed, see LINKABLE_STATES.
     */
    private function entityLinkable(string $table, int $id): bool
    {
        $row = $this->db->fetch(
            'SELECT [id] FROM %n WHERE [id] = %i AND [docState] IN (%i, %i, %i, %i)',
            $table, $id,
            self::LINKABLE_STATES[0], self::LINKABLE_STATES[1], self::LINKABLE_STATES[2], self::LINKABLE_STATES[3],
        );
        return $row !== null;
    }

    // ── Side-creates ────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $resolved
     * @return array{supplier: ?int, customer: ?int, balanceParty: ?int, supplierBank: ?int, rowItems: array<int, int>}
     */
    private function runSideCreates(array $plan, array $resolved): array
    {
        $ids = ['supplier' => null, 'customer' => null, 'balanceParty' => null, 'supplierBank' => null, 'rowItems' => []];

        foreach (['supplier', 'customer', 'balanceParty'] as $partyKey) {
            $payload = $plan['partyCreates'][$partyKey] ?? null;
            if (!is_array($payload) || $payload === []) {
                continue;
            }
            $payload['docState'] = 40;
            $payload['docStateMain'] = 3;
            if (!isset($payload['person_type'])) {
                $payload['person_type'] = PersonType::Company->value;
            }
            $result = $this->personsGateway->saveDocument($payload);
            if (!$result->isSuccess()) {
                throw new \RuntimeException("Side-create person ({$partyKey}) failed: " . ($result->getErrorMessage() ?? ''));
            }
            $ids[$partyKey] = (int) $result->getData()['id'];
        }

        if (is_array($plan['bankCreate'] ?? null)) {
            $bankPayload = $plan['bankCreate'];
            // Re-link bank to a freshly-created supplier if needed.
            if (empty($bankPayload['person']) && $ids['supplier'] !== null) {
                $bankPayload['person'] = $ids['supplier'];
            }
            if (!empty($bankPayload['person'])) {
                // base_persons_bank_accounts has no docState — bypass.
                $this->db->insert('base_persons_bank_accounts', $bankPayload)->execute();
                $ids['supplierBank'] = (int) $this->db->getInsertId();
            }
        }

        foreach ($plan['rowItemCreates'] ?? [] as $rowIdx => $payload) {
            $payload = $this->prepareItemCreatePayload($payload, $plan['resolvedRowUnits'][$rowIdx] ?? null);
            $result = $this->itemsGateway->saveDocument($payload);
            if (!$result->isSuccess()) {
                throw new \RuntimeException("Side-create item (row {$rowIdx}) failed: " . ($result->getErrorMessage() ?? ''));
            }
            $ids['rowItems'][$rowIdx] = (int) $result->getData()['id'];
        }

        return $ids;
    }

    /**
     * Thin wrapper around `Connection::query()` so subclasses (especially
     * testing doubles) can intercept. `Connection::query()` itself is
     * final and cannot be mocked directly. Pattern matches DocDocument.
     */
    protected function executeSql(mixed ...$args): void
    {
        $this->db->query(...$args);
    }

    /**
     * Per-partner item code learning — see docs/exchange-format.md §"Side-
     * creates a per-partner item mapping". Idempotent via unique index.
     *
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $plan
     * @param array{supplier: ?int, customer: ?int, supplierBank: ?int, rowItems: array<int, int>} $sideIds
     */
    private function writeSupplierCodeMappings(array $canonical, array $plan, array $sideIds): void
    {
        $supplierId = $sideIds['supplier'] ?? $plan['resolvedSupplier'] ?? null;
        if ($supplierId === null) {
            return;
        }

        $rows = is_array($canonical['rows'] ?? null) ? $canonical['rows'] : [];
        foreach ($rows as $i => $row) {
            if (!is_array($row)) continue;
            if (in_array($i, $plan['rowSkips'] ?? [], true)) {
                continue;
            }
            $supplierCode = $row['item']['supplierCode'] ?? null;
            if (!is_string($supplierCode) || trim($supplierCode) === '') {
                continue;
            }
            $itemId = $sideIds['rowItems'][$i] ?? ($plan['resolvedRowItems'][$i] ?? null);
            if ($itemId === null) {
                continue;
            }
            $this->executeSql(
                'INSERT IGNORE INTO [economy_items_supplier_codes]
                 ([person], [item], [supplier_code], [supplier_name], [created])
                 VALUES (%i, %i, %s, %sN, NOW())',
                $supplierId, $itemId, $supplierCode,
                isset($row['item']['name']) ? (string) $row['item']['name'] : null,
            );
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function prepareItemCreatePayload(array $payload, ?int $unitId): array
    {
        $payload['docState'] = 40;
        $payload['docStateMain'] = 3;
        if ($unitId !== null) {
            $payload['unit'] = $unitId;
        }
        // item_kind is required by ItemDocument::validate. Pick the first
        // active kind as a generic default — better than failing the save.
        if (empty($payload['item_kind'])) {
            $row = $this->db->fetch(
                'SELECT [id] FROM [economy_items_kinds]
                 WHERE [docState] IN (%i, %i, %i) ORDER BY [id] LIMIT 1',
                self::ACTIVE_STATES[0], self::ACTIVE_STATES[1], self::ACTIVE_STATES[2],
            );
            if ($row !== null) {
                $payload['item_kind'] = (int) $row['id'];
            }
        }
        // Default unit fallback if not resolved on the row.
        if (empty($payload['unit'])) {
            $row = $this->db->fetch(
                'SELECT [id] FROM [core_units] WHERE [system_code] = %s LIMIT 1',
                'pcs',
            );
            if ($row !== null) {
                $payload['unit'] = (int) $row['id'];
            }
        }
        return $payload;
    }

    // ── Transform canonical → internal $data ───────────────────────────────

    /**
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $plan
     * @param array{supplier: ?int, customer: ?int, supplierBank: ?int, rowItems: array<int, int>} $sideIds
     * @param ?int $numberSeriesId Pre-resolved by apply() (honors numberSeriesCode).
     * @return array<string, mixed>
     */
    private function transform(array $canonical, array $plan, array $sideIds, ?int $numberSeriesId): array
    {
        $docType = $this->mapDocType($canonical);
        $selfParty = $canonical['selfParty'] ?? null;
        $targetDocState = (int) ($canonical['applyOptions']['targetDocState'] ?? 10);

        // Účetní doklad (cmnbkp): hlavičkový partner je nepovinný a žije per
        // řádek; bere se z volitelného pinu (resolvedHeadPartner), bez
        // selfParty resolution. Faktura: partner = ta druhá strana. Pokladní
        // doklad / prodejka: nepovinný — pin, jinak strana z payloadu.
        $partyPartner = match ($selfParty) {
            'customer' => $sideIds['supplier'] ?? $plan['resolvedSupplier'] ?? null,
            'supplier' => $sideIds['customer'] ?? $plan['resolvedCustomer'] ?? null,
            default    => $sideIds['supplier'] ?? $plan['resolvedSupplier']
                           ?? ($sideIds['customer'] ?? $plan['resolvedCustomer'] ?? null),
        };
        $isCashDeskBound = $this->isCashDeskBoundDocType($docType);
        $partnerId = match (true) {
            $docType === 'cmnbkp' => $plan['resolvedHeadPartner'] ?? null,
            $isCashDeskBound      => $plan['resolvedHeadPartner'] ?? $partyPartner,
            default               => $partyPartner,
        };
        // Směr pokladního dokladu (jen cashDocument; validátor hlídá 1/2).
        $cashDir = isset($canonical['cashDirection']) ? (int) $canonical['cashDirection'] : 0;
        // Ruční plátce (#72): poslaná strana = partner_balance s příznakem
        // ručního zadání, aby ho odvození (terminál / dopravce) nepřepsalo.
        $balancePartyId = $sideIds['balanceParty'] ?? $plan['resolvedBalanceParty'] ?? null;

        $vatRegistrationId = $this->resolveVatRegistrationFor($canonical);
        // Derivace přebíjí deklarovaný mode (kromě none) — koriguje se jen
        // interní vat_mode, canonical vč. totals zůstává nedotčený a
        // DocDocument si base/VAT/totals přepočítá při apply sám.
        $vatMode = self::VAT_MODE_MAP[(string) ($canonical['vat']['mode'] ?? 'fromBase')] ?? 1;
        $derivedVatMode = VatModeDerivation::derive($canonical);
        if ($derivedVatMode !== null && $vatMode !== 0) {
            $vatMode = $derivedVatMode;
        }
        $vatPlace = self::VAT_PLACE_MAP[(string) ($canonical['vat']['place'] ?? 'domestic')] ?? 0;
        // Autorita rekapitulace + řádky k převzetí (R3/I4/I7). Přepočítanou
        // rekapitulaci si DocDocument spočítá z řádků sám, `vatRecap` se pak
        // do payloadu nedává — prázdný child set by u nového dokladu nic
        // nezměnil, ale u převzaté je to jediná cesta, jak se data dostanou
        // do docs_core_vat_recap.
        $recapSource = $this->resolveRecapSource($canonical);
        $calcSource = self::VAT_CALC_SOURCE_MAP[(string) ($canonical['vat']['calcSource'] ?? 'header')] ?? 0;
        $csMode = self::CS_MODE_MAP[(string) ($canonical['vat']['controlStatementMode'] ?? 'auto')] ?? 0;
        // Pokladní doklad bez způsobu úhrady = Hotovost (ostatní Převodem).
        $defaultPaymentMethod = $isCashDeskBound ? 'cash' : 'bankTransfer';
        $paymentMethod = self::PAYMENT_METHOD_MAP[(string) ($canonical['payment']['method'] ?? $defaultPaymentMethod)]
            ?? self::PAYMENT_METHOD_MAP[$defaultPaymentMethod];

        // AI extractors sometimes omit accountingDate even when issueDate
        // is present. DocDocument::beforeSave (applyDateDefaults) would
        // backfill accounting_date from issue_date, but its validate() runs
        // first and rejects an empty accounting_date — so default it here,
        // matching the same Czech accounting practice as the beforeSave
        // hook. The form-based flow still requires accounting_date
        // explicitly (DocsHeadsForm marks it required); this fallback
        // applies only to the Exchange apply path.
        $issueDate = $canonical['dates']['issueDate'] ?? null;
        $accountingDate = $canonical['dates']['accountingDate'] ?? $issueDate;

        // Import mode (legacy migration): the client supplies the document's own
        // number + sequence and (for issued invoices) our own bank account.
        // Both are opt-in — without them apply() behaves exactly as before.
        $importNumber = $canonical['applyOptions']['importNumber'] ?? null;
        $importOwnBank = $canonical['applyOptions']['importOwnBankAccount'] ?? null;

        $data = [
            'doc_type'             => $docType,
            'number_series'        => $numberSeriesId,
            'doc_text'             => $canonical['docText'] ?? null,
            'partner_doc_number'   => $canonical['docNumber'] ?? null,
            'partner'              => $partnerId,
            'partner_balance'        => $balancePartyId !== null ? (int) $balancePartyId : null,
            'partner_balance_manual' => $balancePartyId !== null ? 1 : null,
            // Import mode: virtual field consumed + removed by
            // DocDocument::beforeSave. Must NOT reach SQL. Null when not in
            // import mode → dropped by the array_filter below.
            '_importNumber'        => is_array($importNumber) ? [
                'docNumber'      => (string) ($importNumber['docNumber'] ?? ''),
                // Explicit null = number outside the series formula (migrated
                // duplicate keys) — must survive to DocDocument, `?? 0` would
                // coerce it to 0 and trigger the malformed-payload fallback.
                'sequenceNumber' => (array_key_exists('sequenceNumber', $importNumber)
                        && $importNumber['sequenceNumber'] === null)
                    ? null
                    : (int) ($importNumber['sequenceNumber'] ?? 0),
            ] : null,
            // Import mode: dobový snapshot partnera z kanonické strany.
            // Virtual field consumed + removed by DocDocument::beforeSave —
            // Applier nerozhoduje o cílovém sloupci (trade_dir větvení je věc
            // Document vrstvy). Null mimo import mód → dropped by array_filter.
            '_importPartnerSnapshot' => is_array($importNumber)
                ? $this->buildImportPartnerSnapshot($canonical, $docType, $cashDir)
                : null,
            // Pokladna (#59 D12): u vázaných typů ji DocDocument stejně
            // přepíše z řady; u faktur = platba hotově na této pokladně.
            // Null (nepokladní typ bez kódu) → vypadne přes array_filter.
            'cash_desk'            => $plan['cashDeskId'] ?? null,
            // 0 = typ bez směru; validátor u cashDocument vynucuje 1/2.
            'cash_dir'             => $cashDir !== 0 ? $cashDir : null,
            // Import mode: our own bank account (issued invoices need it at
            // state 40+; standard self-party flow can't carry it).
            'bank_account'         => $importOwnBank !== null ? (int) $importOwnBank : null,
            'partner_bank'         => $sideIds['supplierBank'] ?? $plan['resolvedSupplierBank'] ?? null,
            'partner_bank_account' => $canonical['supplier']['bankAccount']['accountNumber'] ?? null,
            'partner_bank_iban'    => $canonical['supplier']['bankAccount']['iban'] ?? null,
            'partner_bank_bic'     => $canonical['supplier']['bankAccount']['bic'] ?? null,
            'issue_date'           => $issueDate,
            'due_date'             => $canonical['dates']['dueDate'] ?? null,
            'accounting_date'      => $accountingDate,
            'vat_duzp'             => $canonical['dates']['taxPointDate'] ?? null,
            'vat_dppd'             => $canonical['dates']['vatObligationDate'] ?? null,
            'period_from'          => $canonical['dates']['periodFrom'] ?? null,
            'period_to'            => $canonical['dates']['periodTo'] ?? null,
            'vat_mode'             => $vatMode,
            'vat_calc_source'      => $calcSource,
            'vat_recap_source'     => $recapSource['source'],
            'vat_place'            => $vatPlace,
            'cs_mode'              => $csMode !== 0 ? $csMode : null,
            'vat_registration'    => $vatRegistrationId,
            'doc_currency'         => isset($canonical['currency'])
                                       ? strtolower((string) $canonical['currency'])
                                       : null,
            'exchange_rate'        => $canonical['exchangeRate'] ?? null,
            // Odvozeno z čísel (computed vs declared), extrahovaný
            // totals.totalRounding je jen informativní. Null → klíč vypadne
            // přes array_filter níže a platí default 0 (bez zaokrouhlení).
            'total_rounding_mode'  => $this->deriveTotalRoundingMode($canonical),
            'payment_method'       => $paymentMethod,
            'payment_reference'    => $canonical['payment']['paymentReference'] ?? null,
            'specific_symbol'      => $canonical['payment']['specificSymbol'] ?? null,
            'constant_symbol'      => $canonical['payment']['constantSymbol'] ?? null,
            'notice'               => $canonical['notes']['internal'] ?? null,
            'doc_notice'           => $canonical['notes']['onDocument'] ?? null,
            'source_kind'          => $canonical['source']['kind'] ?? null,
            'source_message'       => $canonical['source']['message'] ?? null,
            'source_extracted_at'  => $this->mapExtractedAt($canonical['source']['extractedAt'] ?? null),
            'docState'             => $targetDocState,
            'rows'                 => $this->transformRows($canonical['rows'] ?? [], $plan, $sideIds),
            'vatRecap'             => $recapSource['recap'] !== [] ? $recapSource['recap'] : null,
        ];

        return array_filter(
            $data,
            static fn($v, $k) => $v !== null || in_array($k, ['rows'], true),
            ARRAY_FILTER_USE_BOTH,
        ) + ['rows' => $data['rows']];
    }

    /**
     * Import mód: přeloží kanonickou stranu partnera (supplier/customer dle
     * selfParty) do tvaru {@see \Shipard\Module\Docs\Core\PersonSnapshotBuilder}.
     * DocDocument ji persistuje jako dobový snapshot partnera — snapshoty se
     * u importu nestaví z dnešního adresáře, dobová data (především `vat_id`
     * pro kontrolní hlášení) nese payload; za jejich dobovost odpovídá
     * exportér. Null = bez snapshotu (typ bez stran — účetní doklad;
     * prázdná strana v payloadu — anonymní pokladní doklad).
     *
     * Která strana je partner, říká směr obchodu dokladu
     * (DocDocument::resolveTradeDir — per typ, u pokladního dokladu per
     * doklad z cash_dir): 1 → partner je odběratel, 2 → dodavatel.
     * Explicitní selfParty má přednost.
     *
     * @param array<string, mixed> $canonical
     * @return array<string, mixed>|null
     */
    private function buildImportPartnerSnapshot(array $canonical, string $docType, int $cashDir = 0): ?array
    {
        $tradeDir = DocDocument::resolveTradeDir(['doc_type' => $docType, 'cash_dir' => $cashDir], $this->config);
        if ($tradeDir === null) {
            return null;
        }

        $supplier = is_array($canonical['supplier'] ?? null) ? $canonical['supplier'] : [];
        $customer = is_array($canonical['customer'] ?? null) ? $canonical['customer'] : [];
        // Partnerská strana = ta druhá než selfParty; bez selfParty dle
        // směru obchodu, s fallbackem na druhou stranu (zrcadlí kaskádu
        // výběru hlavičkového partnera v transform()).
        $party = match ($canonical['selfParty'] ?? null) {
            'customer' => $supplier,
            'supplier' => $customer,
            default    => $tradeDir === 1
                ? ($customer !== [] ? $customer : $supplier)
                : ($supplier !== [] ? $supplier : $customer),
        };
        if ($party === []) {
            return null;
        }

        // Prázdný string = hodnota chybí — snapshot drží null jako
        // PersonSnapshotBuilder u prázdných DB sloupců.
        $s = static function (mixed $v): ?string {
            if ($v === null) {
                return null;
            }
            $t = trim((string) $v);
            return $t === '' ? null : $t;
        };

        $snap = [
            'name'               => (string) ($party['name'] ?? ''),
            'company_id'         => $s($party['companyId'] ?? null),
            'tax_id'             => $s($party['taxId'] ?? null),
            'vat_id'             => $s($party['vatId'] ?? null),
            'court_registration' => $s($party['courtRegistration'] ?? null),
            'contact'            => [
                'email' => $s($party['contact']['email'] ?? null),
                'phone' => $s($party['contact']['phone'] ?? null),
            ],
        ];

        $addr = is_array($party['address'] ?? null) ? $party['address'] : [];
        if ($addr !== []) {
            $country = $s($addr['country'] ?? null);
            $snap['address'] = [
                'street'        => $s($addr['street'] ?? null),
                'house_number'  => $s($addr['houseNumber'] ?? null),
                'city'          => $s($addr['city'] ?? null),
                'city_part'     => $s($addr['cityPart'] ?? null),
                'zip'           => $s($addr['zip'] ?? null),
                'country'       => $country !== null ? strtolower($country) : null,
                'display_block' => $s($addr['displayBlock'] ?? null),
                'display_line'  => $s($addr['displayLine'] ?? null),
            ];
        }

        $bank = is_array($party['bankAccount'] ?? null) ? $party['bankAccount'] : [];
        if ($bank !== []) {
            $currency = $s($bank['currency'] ?? null);
            $snap['bank_account'] = [
                'name'           => null, // canonical jméno účtu nenese
                'account_number' => $s($bank['accountNumber'] ?? null),
                'iban'           => $s($bank['iban'] ?? null),
                'bic'            => $s($bank['bic'] ?? null),
                'currency'       => $currency !== null ? strtolower($currency) : null,
            ];
        }

        return $snap;
    }

    /**
     * Země pro resolve DPH kódu — kaskáda: explicitní `vat.registrationCountry`
     * → prefix z kódu (konvence „{země}-{číslo}“, např. cz-110; nese
     * registraci přímo, proto vyhrává nad zemí dodavatele) → `supplier.country`.
     * Model smí top-level "vat" vynechat (nullable od v2.3.0), bez fallbacku
     * by pak KAŽDÝ řádek skončil `vat_code_unknown`. Sdílí řádky i
     * rekapitulace, aby se kód rekapitulace hledal ve stejném číselníku
     * jako kódy řádků. Prázdný řetězec = země neznámá.
     */
    private function vatCountryForCode(string $code, string $vatCountry, string $supplierCountry): string
    {
        if ($vatCountry !== '') {
            return $vatCountry;
        }
        if (preg_match('/^([a-z]{2})-/i', $code, $m) === 1) {
            return strtolower($m[1]);
        }
        return $supplierCountry;
    }

    // ── Autorita rekapitulace DPH (vat.recapSource) ─────────────────────────

    /**
     * Zdroj rekapitulace pro `docs_core_heads.vat_recap_source` a řádky
     * rekapitulace k převzetí (`docs/vat-calculation.md` § 5, R3/I4/I7).
     *
     * - `vat.recapSource: "declared"` — rekapitulace ze zdroje je fakt
     *   (import ze starého Shipardu, doklad dodavatele). Bere se, jak
     *   přišla; jediná podmínka je dohledatelný DPH kód u každého řádku,
     *   bez něj by nešlo určit ani flagy sčítání (`vat_code` je NOT NULL).
     *   Vnitřní nesrovnalost **není** důvod k přepočtu — u přenesení daňové
     *   povinnosti `base + tax ≠ total` platí a je správně; nesrovnalost
     *   hlásí warning `vat_recap_inconsistent`.
     * - `"computed"` — spočítat z řádků.
     * - `null` — odvodí se: u dokladu, který **přijímáme** (`selfParty`
     *   customer, typicky AI extrakce faktury dodavatele), je převzatá
     *   tehdy, když rekapitulace je neprázdná, každý řádek projde
     *   aritmetickou kontrolou a kódy jsou dohledatelné. Jinak přepočítaná.
     *   U vystavených dokladů vždy přepočítaná — rekapitulaci děláme my.
     *
     * „Dohledatelný kód" (I7) = existuje v číselníku země registrace, ověřuje
     * se stejným `VatCodeResolver` a stejnou kaskádou země jako kódy řádků.
     * Kód, který resolver nezná, by `DocDocument::takeOverVatRecapitulation`
     * odmítl `DomainException` a apply by skončil 500 — místo toho se
     * rekapitulace přepočítá z řádků a uživatel dostane info issue s důvodem.
     *
     * @param array<string, mixed> $canonical
     * @return array{source: int, recap: array<int, array<string, mixed>>, fallback: ?string}
     */
    private function resolveRecapSource(array $canonical): array
    {
        $computed = ['source' => 0, 'recap' => [], 'fallback' => null];
        $flag = $canonical['vat']['recapSource'] ?? null;
        if ($flag !== null && (self::VAT_RECAP_SOURCE_MAP[(string) $flag] ?? null) === 0) {
            return $computed;
        }
        $explicit = (string) ($flag ?? '') === 'declared';

        $entries = $canonical['vatRecap'] ?? null;
        if (!is_array($entries) || $entries === []) {
            return $explicit
                ? ['source' => 0, 'recap' => [], 'fallback' => 'rekapitulace je prázdná']
                : $computed;
        }

        // Bez explicitní deklarace přebírá rekapitulaci jen doklad, který
        // přijímáme — u vystaveného ji počítáme sami.
        if (!$explicit && (string) ($canonical['selfParty'] ?? '') !== 'customer') {
            return $computed;
        }

        $codesByPct = $this->recapCodesFromRows($canonical);
        $vatCountry = strtolower((string) ($canonical['vat']['registrationCountry'] ?? ''));
        $supplierCountry = strtolower((string) ($canonical['supplier']['country'] ?? ''));
        $taxPointDate = $canonical['dates']['taxPointDate'] ?? ($canonical['dates']['issueDate'] ?? null);
        $recap = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $pct = (float) ($entry['vatPct'] ?? 0);
            $code = trim((string) ($entry['vatCode'] ?? ''));
            if ($code === '') {
                // I7: ISDOC rekapitulaci kódy nenese — dohledáme je z řádků,
                // ale jen když je pro sazbu jednoznačný.
                $code = $codesByPct[(string) $pct] ?? '';
            }
            if ($code === '') {
                return [
                    'source'   => 0,
                    'recap'    => [],
                    'fallback' => sprintf('řádek se sazbou %s %% nemá DPH kód', (string) $pct),
                ];
            }
            // I7: kód musí existovat v číselníku země registrace. Neznámý kód
            // (AI si ho vymyslela, import s jiným mapováním) → přepočítaná.
            $country = $this->vatCountryForCode($code, $vatCountry, $supplierCountry);
            $codeR = $this->vatCodeResolver->resolve(
                $code,
                $country !== '' ? $country : null,
                is_string($taxPointDate) ? $taxPointDate : null,
                $pct,
            );
            if ($codeR->status === ResolveStatus::NotFound) {
                return [
                    'source'   => 0,
                    'recap'    => [],
                    'fallback' => sprintf(
                        'DPH kód %s není v číselníku%s',
                        $code,
                        $country !== '' ? ' země ' . strtoupper($country) : '',
                    ),
                ];
            }
            if (!$explicit && !$this->recapEntryIsConsistent($entry, $pct)) {
                return [
                    'source'   => 0,
                    'recap'    => [],
                    'fallback' => sprintf('řádek %s %s %% je vnitřně nekonzistentní', $code, (string) $pct),
                ];
            }
            $recap[] = [
                'vat_code'        => $code,
                'vat_pct'         => $pct,
                'base'            => round((float) ($entry['base'] ?? 0), 2),
                'tax'             => round((float) ($entry['tax'] ?? 0), 2),
                'total'           => round((float) ($entry['total'] ?? 0), 2),
                'is_reverse_pair' => ($entry['isReversePair'] ?? null) === true ? 1 : 0,
            ];
        }

        if ($recap === []) {
            return $explicit
                ? ['source' => 0, 'recap' => [], 'fallback' => 'rekapitulace je prázdná']
                : $computed;
        }
        return ['source' => 1, 'recap' => $recap, 'fallback' => null];
    }

    /**
     * Mapa sazba → DPH kód z položkových řádků, jen pro sazby s jediným
     * kódem (I7). Slouží rekapitulaci bez kódů (ISDOC).
     *
     * @param array<string, mixed> $canonical
     * @return array<string, string>
     */
    private function recapCodesFromRows(array $canonical): array
    {
        $byPct = [];
        foreach ((array) ($canonical['rows'] ?? []) as $row) {
            if (!is_array($row) || (string) ($row['rowKind'] ?? 'item') !== 'item') {
                continue;
            }
            $code = trim((string) ($row['vat']['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $key = (string) (float) ($row['vat']['pct'] ?? 0);
            $byPct[$key][$code] = true;
        }
        $out = [];
        foreach ($byPct as $key => $codes) {
            if (count($codes) === 1) {
                $out[$key] = (string) array_key_first($codes);
            }
        }
        return $out;
    }

    /**
     * Aritmetika řádku rekapitulace — stejné tolerance jako
     * {@see DocumentValidator::checkVatRecapArithmetic}. Oddaňovací páry
     * a nulové sazby projdou vždy (daň je u nich informativní).
     *
     * @param array<string, mixed> $entry
     */
    private function recapEntryIsConsistent(array $entry, float $pct): bool
    {
        if (($entry['isReversePair'] ?? null) === true || $pct === 0.0) {
            return true;
        }
        foreach (['base', 'tax', 'total'] as $key) {
            if (!isset($entry[$key]) || !is_numeric($entry[$key])) {
                return false;
            }
        }
        $base  = (float) $entry['base'];
        $tax   = (float) $entry['tax'];
        $total = (float) $entry['total'];
        if (abs($base + $tax - $total) > 0.02) {
            return false;
        }
        return abs($tax - round($base * $pct / 100.0, 2)) <= max(0.05, abs($base) * 0.001);
    }

    /**
     * Info issue, když rekapitulace nešla převzít a spočítá se z řádků —
     * bez něj by uživatel nepoznal, proč je na dokladu jiná rekapitulace
     * než na předloze.
     *
     * @param array<string, mixed> $canonical
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    private function appendRecapSourceIssue(array $canonical, array &$issues): void
    {
        $resolved = $this->resolveRecapSource($canonical);
        if ($resolved['fallback'] === null) {
            return;
        }
        $issues[] = [
            'severity' => 'info',
            'path'     => 'vat.recapSource',
            'code'     => 'recap_source_computed_fallback',
            'message'  => 'Rekapitulaci DPH nešlo převzít z dokladu ('
                . $resolved['fallback'] . ') — spočítá se z řádků.',
        ];
    }

    /**
     * Když derivace ({@see VatModeDerivation}) přebije deklarovaný
     * `vat.mode`, přidá do `_resolve.issues` warning `vat_mode_derived`,
     * aby korekce byla viditelná v review modalu. Volá se z preview()
     * i apply() — transform() běží až v transakci, po sestavení
     * `_resolve` bloku (a preview transform vůbec nevolá).
     *
     * @param array<string, mixed> $canonical
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    private function appendVatModeIssue(array $canonical, array &$issues): void
    {
        $declared = self::VAT_MODE_MAP[(string) ($canonical['vat']['mode'] ?? 'fromBase')] ?? 1;
        if ($declared === 0) {
            return;
        }
        $derived = VatModeDerivation::derive($canonical);
        if ($derived === null || $derived === $declared) {
            return;
        }
        $issues[] = [
            'severity' => 'warning',
            'path'     => 'vat.mode',
            'code'     => 'vat_mode_derived',
            'message'  => $derived === 2
                ? 'Řádky jsou v cenách s DPH — režim výpočtu odvozen shora (fromTotal).'
                : 'Řádky jsou v cenách bez DPH — režim výpočtu odvozen zdola (fromBase).',
        ];
    }

    // ── Doplnění pohybu (operation) na item řádcích ─────────────────────────

    /**
     * Item řádek, kterému se při apply doplňuje pohyb: rowKind item,
     * bez explicitní operation (AI ji správně nevrací — interní účetní
     * koncept, na předloze není) a bez kontace accSide. Textové/sekční
     * a kontační řádky se nedoplňují; explicitní operation = passthrough
     * (operace s vlajkou rowSide sem nespadnou — nesou operation).
     */
    private function rowNeedsOperation(mixed $row): bool
    {
        return is_array($row)
            && (self::ROW_KIND_MAP[(string) ($row['rowKind'] ?? 'item')] ?? 1) === 1
            && !isset($row['accSide'])
            && (string) ($row['operation'] ?? '') === '';
    }

    /**
     * Doplní pohyb item řádkům bez operation podle cfgItem
     * `docs.core.applyRowOperations` — primárně mapou `byItemType`
     * z item_type položky řádku, jinak výchozím pohybem `default`
     * docTypu. Bez doplnění koncept z AI analýzy neprojde na docState
     * 40 („Pohyb je povinný", DocRowOperationRules::validateRow).
     * Volá se po runSideCreates() — finální ID položek (matched
     * i právě side-created) už jsou v DB, item_type se čte jednotně
     * přes ID. docType bez záznamu v cfg → beze změny (null).
     *
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $plan
     * @param array{supplier: ?int, customer: ?int, supplierBank: ?int, rowItems: array<int, int>} $sideIds
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     * @return array<int, string> index řádku → doplněný kód operace
     */
    private function defaultRowOperationsForApply(array $canonical, array $plan, array $sideIds, array &$issues): array
    {
        $docType = $this->mapDocType($canonical);
        $cfgApply = $this->config->cfgItem('docs.core.applyRowOperations');
        if (!is_array($cfgApply[$docType] ?? null)) {
            return [];
        }

        $rows = is_array($canonical['rows'] ?? null) ? $canonical['rows'] : [];
        $rowItemIds = [];
        foreach ($rows as $i => $row) {
            if (!$this->rowNeedsOperation($row) || in_array($i, $plan['rowSkips'] ?? [], true)) {
                continue;
            }
            $rowItemIds[$i] = $sideIds['rowItems'][$i] ?? ($plan['resolvedRowItems'][$i] ?? null);
        }
        if ($rowItemIds === []) {
            return [];
        }

        $types = $this->fetchItemTypes(array_filter($rowItemIds, static fn($id) => $id !== null));
        $rowItemTypes = array_map(
            static fn(?int $id) => $id !== null ? ($types[$id] ?? null) : null,
            $rowItemIds,
        );
        return $this->resolveRowOperationDefaults($docType, $rowItemTypes, $issues);
    }

    /**
     * Přeloží item_type řádků na kódy pohybů dle
     * `docs.core.applyRowOperations` pro daný docType. Doplnění je
     * TICHÉ — žádné info issue: AI pohyb nikdy nevrací, doplňuje se
     * tedy na každém item řádku každého apply a hláška, která svítí
     * vždy, by učila uživatele sekci Upozornění přeskakovat.
     * Transparentnost dává sám výsledek (sloupec Pohyb konceptu) —
     * na rozdíl od `vat_mode_derived`, kde výsledek odchylku od
     * výstupu AI nevysvětlí. Kód, který v `docs.core.rowOperations`
     * neexistuje nebo není pro docType povolený, se NEdoplní (chová se
     * jako docType bez záznamu) a přidá warning
     * `row_operation_config_invalid` — ten hlásí skutečný problém
     * a vystřelí jen při rozbité mapě.
     *
     * @param array<int, ?int> $rowItemTypes index řádku → item_type (null = bez položky / neznámý)
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     * @return array<int, string> index řádku → kód operace
     */
    private function resolveRowOperationDefaults(string $docType, array $rowItemTypes, array &$issues): array
    {
        $cfgApply = $this->config->cfgItem('docs.core.applyRowOperations');
        $cfg = is_array($cfgApply[$docType] ?? null) ? $cfgApply[$docType] : null;
        if ($cfg === null) {
            return [];
        }
        $cfgOps = $this->config->cfgItem('docs.core.rowOperations');
        $cfgOps = is_array($cfgOps) ? $cfgOps : [];
        $byItemType = is_array($cfg['byItemType'] ?? null) ? $cfg['byItemType'] : [];

        $out = [];
        foreach ($rowItemTypes as $i => $itemType) {
            $code = ($itemType !== null ? ($byItemType[(string) $itemType] ?? null) : null)
                ?? ($cfg['default'] ?? null);
            if (!is_string($code) || $code === '') {
                continue;
            }
            if (!isset($cfgOps[$code]['docTypes'][$docType])) {
                $issues[] = [
                    'severity' => 'warning',
                    'path'     => "rows.{$i}.operation",
                    'code'     => 'row_operation_config_invalid',
                    'message'  => "Pohyb „{$code}“ z docs.core.applyRowOperations neexistuje nebo není povolen pro doklad „{$docType}“; pohyb nedoplněn.",
                ];
                continue;
            }
            $out[$i] = $code;
        }
        return $out;
    }

    /**
     * @param array<int, int> $itemIds
     * @return array<int, int> ID položky → item_type
     */
    private function fetchItemTypes(array $itemIds): array
    {
        $itemIds = array_values(array_unique(array_map('intval', $itemIds)));
        if ($itemIds === []) {
            return [];
        }
        $out = [];
        foreach ($this->db->fetchAll(
            'SELECT [id], [item_type] FROM [economy_items] WHERE [id] IN %in',
            $itemIds,
        ) as $row) {
            $out[(int) $row['id']] = (int) $row['item_type'];
        }
        return $out;
    }

    /**
     * Odvodí `total_rounding_mode` z rozdílu mezi spočtenou a deklarovanou
     * celkovou částkou. Konzervativně: mod se nastaví jen když se computed
     * a declared liší o >= 0,01 a < 1,00 a některý mod declared přesně
     * reprodukuje; jinak null (default 0, případný totals_mismatch warning
     * z validátoru zůstává v platnosti). Rozdíl přesně 0,01 je platné
     * zaokrouhlení (69,99 → 70,00; starý Shipard je má běžně) — podmínka
     * „declared = celé číslo reprodukované modem“ ho odliší od šumu v DPH.
     *
     * Computed se bere z nejautoritativnějšího dostupného zdroje:
     * Σ vatRecap[].total → totalBase + totalVat → Σ řádků s DPH per řádek.
     * Matematický mod (1) má u shodného výsledku přednost před směrovými
     * (3 = ceil, 4 = floor); mod 5 (matematicky na 0,05 — hotovost SK a část
     * eurozóny) se zkouší až za celými jednotkami, protože celá declared je
     * násobek 0,05 taky. DocDocument si pak total_amount/total_rounding
     * dopočte sám z řádků — tady se výpočet neduplikuje, jen se volí mod.
     *
     * @param array<string, mixed> $canonical
     */
    private function deriveTotalRoundingMode(array $canonical): ?int
    {
        $totals = $canonical['totals'] ?? null;
        if (!is_array($totals) || !isset($totals['totalAmount']) || !is_numeric($totals['totalAmount'])) {
            return null;
        }
        $declared = round((float) $totals['totalAmount'], 2);

        $computed = null;

        // 1. Σ vatRecap[].total — jen když má total všechny řádky rekapitulace.
        $vatRecap = $canonical['vatRecap'] ?? null;
        if (is_array($vatRecap) && count($vatRecap) > 0) {
            $acc = 0.0;
            $complete = true;
            foreach ($vatRecap as $r) {
                if (!is_array($r) || !isset($r['total']) || !is_numeric($r['total'])) {
                    $complete = false;
                    break;
                }
                $acc += (float) $r['total'];
            }
            if ($complete) {
                $computed = round($acc, 2);
            }
        }

        // 2. totalBase + totalVat
        if ($computed === null
            && isset($totals['totalBase']) && is_numeric($totals['totalBase'])
            && isset($totals['totalVat']) && is_numeric($totals['totalVat'])) {
            $computed = round((float) $totals['totalBase'] + (float) $totals['totalVat'], 2);
        }

        // 3. Σ řádků: totalPrice × (1 + vat.pct/100) — jako validator, varianta 2.
        if ($computed === null) {
            $rows = $canonical['rows'] ?? null;
            if (is_array($rows)) {
                $acc = 0.0;
                $hasAny = false;
                foreach ($rows as $row) {
                    if (!is_array($row) || !isset($row['totalPrice']) || !is_numeric($row['totalPrice'])) {
                        continue;
                    }
                    $hasAny = true;
                    $pct = $row['vat']['pct'] ?? null;
                    $acc += (float) $row['totalPrice']
                        * ($pct !== null && is_numeric($pct) ? 1.0 + ((float) $pct) / 100.0 : 1.0);
                }
                if ($hasAny) {
                    $computed = round($acc, 2);
                }
            }
        }

        if ($computed === null) {
            return null;
        }

        $diff = abs($declared - $computed);
        if ($diff < 0.005 || $diff >= 1.00) {
            return null;
        }

        // Pořadí = priorita (viz docblok): 1 → 3 → 4 → 5. Kdyby se 0,05
        // zkoušelo před celými jednotkami, faktury na celé Kč by dostaly mod 5.
        $eps = 0.001;
        $candidates = [
            RoundingModes::MATH_UNIT,
            RoundingModes::UP_UNIT,
            RoundingModes::DOWN_UNIT,
            RoundingModes::MATH_FIVE_CENT,
        ];
        foreach ($candidates as $mode) {
            if (abs(RoundingModes::apply($computed, $mode) - $declared) <= $eps) {
                return $mode;
            }
        }
        return null;
    }

    /**
     * @param array<int, mixed> $rows
     * @param array<string, mixed> $plan
     * @param array{supplier: ?int, customer: ?int, supplierBank: ?int, rowItems: array<int, int>} $sideIds
     * @return array<int, array<string, mixed>>
     */
    private function transformRows(array $rows, array $plan, array $sideIds): array
    {
        // Kontační operace (vlajka rowSide v docs.core.rowOperations) účtují
        // částku přímo — detekce nesmí stát jen na přítomnosti accSide:
        // operace s rowSide: 0 (FX) stranu z konstrukce nenesou.
        $cfgOps = $this->config->cfgItem('docs.core.rowOperations');
        $cfgOps = is_array($cfgOps) ? $cfgOps : [];

        $out = [];
        $orderPos = 0;
        foreach ($rows as $i => $row) {
            if (in_array($i, $plan['rowSkips'] ?? [], true)) {
                continue;
            }
            if (!is_array($row)) continue;

            $orderPos++;
            $contation = isset($row['accSide'])
                || isset($cfgOps[(string) ($row['operation'] ?? '')]['rowSide']);
            $itemId = $sideIds['rowItems'][$i] ?? ($plan['resolvedRowItems'][$i] ?? null);
            $unitId = $plan['resolvedRowUnits'][$i] ?? null;
            $vat = $plan['resolvedRowVatCodes'][$i] ?? null;
            $vatPct = null;
            $vatCode = null;
            if (is_array($vat) && ($vat['status'] ?? null) === 'matched') {
                $vatPct = $vat['createPayload']['pct'] ?? null;
                $vatCode = $vat['createPayload']['code'] ?? null;
            }

            $out[] = array_filter([
                'row_kind'        => self::ROW_KIND_MAP[(string) ($row['rowKind'] ?? 'item')] ?? 1,
                // Row movement (docs.core.rowOperations). Explicit canonical
                // value wins (passthrough); null on item rows falls back to
                // the default computed by defaultRowOperationsForApply()
                // (cfgItem docs.core.applyRowOperations) — without a movement
                // the row cannot be confirmed at 40 (DocRowOperationRules).
                'operation'       => ($row['operation'] ?? null)
                                      ?: ($plan['rowOperationDefaults'][$i] ?? null),
                'order_pos'       => $orderPos,
                'item'            => $itemId,
                'unit'            => $unitId,
                'quantity'        => $row['quantity'] ?? null,
                'unit_price'      => $row['unitPrice'] ?? null,
                'total_price'     => $row['totalPrice'] ?? null,
                // Kontační řádek (accSide nebo operace s vlajkou rowSide —
                // FX řádky stranu nenesou) účtuje částku přímo → fromTotal,
                // jinak by calculateRowPrice přepsal total_price z qty×unit (0).
                'price_calc_mode' => $contation
                                      ? 1
                                      : (self::PRICE_CALC_MODE_MAP[(string) ($row['priceCalcMode'] ?? 'fromUnitPrice')] ?? 0),
                'discount_pct'    => $row['discountPct'] ?? null,
                'discount_amount' => $row['discountAmount'] ?? null,
                'vat_code'        => $vatCode,
                'vat_pct'         => $vatPct,
                // Text řádku: faktury ho nesou přes item.description / item.name;
                // účetní doklad (acc.record) item fragment nemá → bere se z
                // řádkové úrovně. Top-level description má přednost.
                'description'     => $row['description']
                                      ?? (is_array($row['item'] ?? null)
                                          ? ($row['item']['description'] ?? $row['item']['name'] ?? null)
                                          : null),
                // Kontace (účetní doklad) — chybí u faktur → array_filter je
                // vynechá, takže faktury jsou beze změny.
                'account'           => $plan['resolvedRowAccounts'][$i] ?? null,
                'acc_side'          => isset($row['accSide'])
                                        ? (['debit' => 0, 'credit' => 1][$row['accSide']] ?? null)
                                        : null,
                'partner'           => $plan['resolvedRowPartners'][$i] ?? null,
                'payment_reference' => $row['paymentReference'] ?? null,
                'specific_symbol'   => $row['specificSymbol'] ?? null,
                'constant_symbol'   => $row['constantSymbol'] ?? null,
                'due_date'          => $row['dueDate'] ?? null,
            ], static fn($v) => $v !== null);
        }
        return $out;
    }

    /**
     * Resolve the target number series for a document.
     *
     *   - $seriesCode !== null → exact match on (doc_type, doc_number_code).
     *     No match throws {@see NumberSeriesNotFoundException}; the caller
     *     maps it to a clean apply-level error. NO silent fallback to the
     *     first active series.
     *   - $seriesCode === null → legacy behaviour: first active series for
     *     the doc_type (backward compatible for clients not sending a code).
     */
    private function resolveNumberSeriesFor(string $docType, ?string $seriesCode = null): ?int
    {
        if ($seriesCode !== null) {
            $row = $this->db->fetch(
                'SELECT [id] FROM [docs_core_number_series]
                 WHERE [doc_type] = %s AND [doc_number_code] = %s AND [docState] IN (%i, %i, %i)
                 ORDER BY [id] LIMIT 1',
                $docType, $seriesCode,
                self::ACTIVE_STATES[0], self::ACTIVE_STATES[1], self::ACTIVE_STATES[2],
            );
            if ($row === null) {
                throw new NumberSeriesNotFoundException($docType, $seriesCode);
            }
            return (int) $row['id'];
        }

        $row = $this->db->fetch(
            'SELECT [id] FROM [docs_core_number_series]
             WHERE [doc_type] = %s AND [docState] IN (%i, %i, %i)
             ORDER BY [id] LIMIT 1',
            $docType,
            self::ACTIVE_STATES[0], self::ACTIVE_STATES[1], self::ACTIVE_STATES[2],
        );
        return $row !== null ? (int) $row['id'] : null;
    }

    /** Má typ dokladu řadu vázanou na pokladnu (`docTypes[].series_binding = cash_desk`)? */
    private function isCashDeskBoundDocType(string $docType): bool
    {
        $cfg = $this->config->cfgItem('docs.core.docTypes');
        return is_array($cfg) && (($cfg[$docType]['series_binding'] ?? null) === 'cash_desk');
    }

    /** Archivovaná entita (V archívu) — pokladna s historickými doklady (#59 Task E). */
    private const ARCHIVED_STATE = 70;

    /**
     * `cashDesk` kanonického dokumentu = `economy_codebooks_cash_desks.code`.
     * Archivovanou pokladnu (70) najde taky — zda se na ni dá doklad zařadit,
     * rozhoduje až řada (resolveBoundNumberSeries, jen import mód).
     */
    private function resolveCashDeskIdByCode(string $code): ?int
    {
        $row = $this->db->fetch(
            'SELECT [id] FROM [economy_codebooks_cash_desks]
             WHERE [code] = %s AND [docState] IN %in
             ORDER BY [id] LIMIT 1',
            $code,
            [...self::ACTIVE_STATES, self::ARCHIVED_STATE],
        );
        return $row !== null ? (int) $row['id'] : null;
    }

    /**
     * Řada vázaného typu pro pokladnu (BoundNumberSeriesProvisioner ji zakládá
     * per pokladna ve stavu 40/70). Archivní řadu (70) přijme jen import mód —
     * živý doklad na archivovanou pokladnu založit nejde.
     */
    private function resolveBoundNumberSeries(string $docType, int $cashDeskId, bool $importMode = false): ?int
    {
        $states = $importMode ? [...self::ACTIVE_STATES, self::ARCHIVED_STATE] : self::ACTIVE_STATES;
        $row = $this->db->fetch(
            'SELECT [id] FROM [docs_core_number_series]
             WHERE [doc_type] = %s AND [cash_desk] = %i AND [docState] IN %in
             ORDER BY [id] LIMIT 1',
            $docType, $cashDeskId,
            $states,
        );
        return $row !== null ? (int) $row['id'] : null;
    }

    /**
     * `applyOptions.importOwnBankAccount` jako string = kód číselníku
     * `economy_codebooks_bank_accounts.code` (přenosné sady místo interního id).
     */
    private function resolveOwnBankAccountByCode(string $code): ?int
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }
        $row = $this->db->fetch(
            'SELECT [id] FROM [economy_codebooks_bank_accounts]
             WHERE [code] = %s AND [docState] IN (%i, %i, %i)
             ORDER BY [id] LIMIT 1',
            $code,
            self::ACTIVE_STATES[0], self::ACTIVE_STATES[1], self::ACTIVE_STATES[2],
        );
        return $row !== null ? (int) $row['id'] : null;
    }

    /**
     * Map canonical docType (descriptive name or short code) → the short code
     * stored in docs_core_heads.doc_type. Passthrough when no alias matches.
     *
     * @param array<string, mixed> $canonical
     */
    private function mapDocType(array $canonical): string
    {
        return self::mapDocTypeValue((string) ($canonical['docType'] ?? ''));
    }

    /**
     * Shared alias→short-code translation for callers outside the applier
     * (RowHistoryEnricher filters history by docs_core_heads.doc_type).
     */
    public static function mapDocTypeValue(string $docType): string
    {
        return self::DOC_TYPE_MAP[$docType] ?? $docType;
    }

    /**
     * @param array<string, mixed> $canonical
     */
    private function resolveVatRegistrationFor(array $canonical): ?int
    {
        $country = strtolower((string) ($canonical['vat']['registrationCountry'] ?? ''));
        if ($country === '') {
            return null;
        }
        $row = $this->db->fetch(
            'SELECT [id] FROM [economy_codebooks_vat_registrations]
             WHERE [country] = %s AND [docState] IN (%i, %i, %i)
             ORDER BY [id] LIMIT 1',
            $country,
            self::ACTIVE_STATES[0], self::ACTIVE_STATES[1], self::ACTIVE_STATES[2],
        );
        return $row !== null ? (int) $row['id'] : null;
    }

    private function mapExtractedAt(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            $dt = new \DateTimeImmutable($value);
            return $dt->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    // ── Lineage update ─────────────────────────────────────────────────────

    /**
     * Stamp the source message with target_table_id + target_row (where the
     * canonical went) — the reverse side of heads.source_message, written
     * atomically inside the doc-save transaction (D6 z mail-message-centric).
     * Analysis resolution / message docState are intentionally NOT touched
     * here — those move through MessageProposalApplier so the verdict write
     * stays one place.
     *
     * @param array<string, mixed> $canonical
     */
    private function writeLineageTargets(array $canonical, int $savedDocId): void
    {
        $messageNdx = $canonical['source']['message'] ?? null;
        if (!is_int($messageNdx) || $messageNdx <= 0) {
            return;
        }
        $this->executeSql(
            'UPDATE [core_mail_incoming_messages]
             SET [target_table_id] = %s,
                 [target_row] = %i
             WHERE [id] = %i',
            'docs_core_heads', $savedDocId, $messageNdx,
        );
    }

    /**
     * Idempotency pre-check for apply(). If the canonical's source.message
     * already points at a docs target (target_table_id + target_row set),
     * return the existing savedDocId without re-saving. Saves a duplicate
     * INSERT on retries / double-clicks.
     *
     * @param array<string, mixed> $canonical
     */
    private function checkIdempotent(array $canonical): ?ApplyResult
    {
        $messageNdx = $canonical['source']['message'] ?? null;
        if (!is_int($messageNdx) || $messageNdx <= 0) {
            return null;
        }
        $row = $this->db->fetch(
            'SELECT [target_table_id], [target_row]
             FROM [core_mail_incoming_messages]
             WHERE [id] = %i',
            $messageNdx,
        );
        if ($row === null
            || empty($row['target_row'])
            || (string) $row['target_table_id'] !== 'docs_core_heads'
        ) {
            return null;
        }
        $existingId = (int) $row['target_row'];

        $enriched = $canonical;
        $enriched['savedDocId'] = $existingId;
        $enriched['_resolve'] = [
            'summary' => [
                'status'          => 'alreadyApplied',
                'matchedCount'    => 0,
                'unresolvedCount' => 0,
                'ambiguousCount'  => 0,
                'errorCount'      => 0,
            ],
            'issues' => [],
        ];
        return ApplyResult::ok($enriched, $existingId);
    }

    // ── Output enrichment helpers ──────────────────────────────────────────

    /**
     * @param array<string, mixed> $canonical
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     * @return array<string, mixed>
     */
    private function withResolveIssues(array $canonical, array $issues): array
    {
        $resolve = is_array($canonical['_resolve'] ?? null) ? $canonical['_resolve'] : [];
        $resolve['issues'] = array_merge(
            is_array($resolve['issues'] ?? null) ? $resolve['issues'] : [],
            $issues,
        );
        $resolve['summary'] = $this->buildSummary([], $resolve['issues']);
        $canonical['_resolve'] = $resolve;
        return $canonical;
    }

    /**
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $resolved
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     * @return array<string, mixed>
     */
    private function withResolve(array $canonical, array $resolved, array $issues): array
    {
        $resolve = $resolved + ['issues' => $issues];
        $resolve['summary'] = $this->buildSummary($resolved, $issues);
        // Per-row enrichment audit (RowHistoryEnricher) is not part of the
        // fresh resolve — carry it over from the incoming _resolve by index,
        // otherwise preview/apply responses would drop it.
        if (is_array($resolve['rows'] ?? null)) {
            $resolve['rows'] = $this->carryOverEnrichment(
                $canonical['_resolve']['rows'] ?? null,
                $resolve['rows'],
            );
        }
        $canonical['_resolve'] = $resolve;
        return $canonical;
    }

    /**
     * @param array<int, array<string, mixed>> $freshRows
     * @return array<int, array<string, mixed>>
     */
    private function carryOverEnrichment(mixed $previousRows, array $freshRows): array
    {
        if (!is_array($previousRows) || $previousRows === []) {
            return $freshRows;
        }
        $byIndex = [];
        foreach ($previousRows as $entry) {
            if (is_array($entry) && isset($entry['index']) && is_array($entry['enrichment'] ?? null)) {
                $byIndex[(int) $entry['index']] = $entry['enrichment'];
            }
        }
        if ($byIndex === []) {
            return $freshRows;
        }
        foreach ($freshRows as $pos => $entry) {
            $idx = $entry['index'] ?? null;
            if (is_int($idx) && isset($byIndex[$idx])) {
                $freshRows[$pos]['enrichment'] = $byIndex[$idx];
            }
        }
        return $freshRows;
    }

    /**
     * @param array<string, mixed> $resolved
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     * @return array<string, int|string>
     */
    private function buildSummary(array $resolved, array $issues): array
    {
        $matched = 0;
        $unresolved = 0;
        $ambiguous = 0;
        foreach (['supplier', 'customer', 'balanceParty', 'supplierBank'] as $key) {
            $status = $resolved[$key]['status'] ?? null;
            if ($status === 'matched') $matched++;
            elseif ($status === 'ambiguous') $ambiguous++;
            elseif ($status === 'notFound' || $status === 'canCreate') $unresolved++;
        }
        foreach ($resolved['rows'] ?? [] as $rowR) {
            foreach (['item', 'unit', 'vatCode'] as $k) {
                $status = $rowR[$k]['status'] ?? null;
                if ($status === 'matched') $matched++;
                elseif ($status === 'ambiguous') $ambiguous++;
                elseif ($status === 'notFound' || $status === 'canCreate') $unresolved++;
            }
        }
        $errors = count(array_filter($issues, static fn($i) => ($i['severity'] ?? null) === 'error'));

        $status = match (true) {
            $errors > 0 || $unresolved > 0 || $ambiguous > 0 => 'needsAttention',
            default => 'ok',
        };
        return [
            'status'          => $status,
            'matchedCount'    => $matched,
            'unresolvedCount' => $unresolved,
            'ambiguousCount'  => $ambiguous,
            'errorCount'      => $errors,
        ];
    }

    /**
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    private function hasErrors(array $issues): bool
    {
        foreach ($issues as $issue) {
            if (($issue['severity'] ?? null) === 'error') {
                return true;
            }
        }
        return false;
    }

    /**
     * Stamp rows decided as `noItem` with status `noItem` in the response
     * `_resolve` — the fresh resolve status (canCreate/notFound) would
     * otherwise suggest an unresolved reference on a successfully applied
     * row. Response-only status (schema has additionalProperties).
     *
     * @param array<string, mixed> $resolved
     * @param list<int> $noItemRows
     * @return array<string, mixed>
     */
    private function annotateNoItemRows(array $resolved, array $noItemRows): array
    {
        if ($noItemRows === []) {
            return $resolved;
        }
        foreach ($resolved['rows'] ?? [] as $i => $rowR) {
            if (isset($rowR['item']) && in_array($i, $noItemRows, true)) {
                $resolved['rows'][$i]['item']['status'] = 'noItem';
            }
        }
        return $resolved;
    }

    /**
     * @param array<string, mixed> $resolved
     * @param array{supplier: ?int, customer: ?int, supplierBank: ?int, rowItems: array<int, int>} $sideIds
     * @return array<string, mixed>
     */
    private function annotateSideCreated(array $resolved, array $sideIds): array
    {
        foreach (['supplier', 'customer', 'balanceParty', 'supplierBank'] as $key) {
            if (($resolved[$key]['status'] ?? null) === 'canCreate' && ($sideIds[$key] ?? null) !== null) {
                $resolved[$key]['status'] = 'matched';
                $resolved[$key]['matchedId'] = $sideIds[$key];
                $resolved[$key]['matchedBy'] = 'created';
            }
        }
        foreach ($resolved['rows'] ?? [] as $i => $rowR) {
            if (($rowR['item']['status'] ?? null) === 'canCreate' && isset($sideIds['rowItems'][$i])) {
                $resolved['rows'][$i]['item']['status'] = 'matched';
                $resolved['rows'][$i]['item']['matchedId'] = $sideIds['rowItems'][$i];
                $resolved['rows'][$i]['item']['matchedBy'] = 'created';
            }
        }
        return $resolved;
    }
}

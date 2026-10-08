<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Document;

use Dibi\Connection;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\NestedTransaction;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Document\DocumentEventDispatcher;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Module\Base\Persons\PersonType;
use Shipard\Module\Core\Exchange\Common\ApplyResult;
use Shipard\Module\Core\Exchange\Common\TransactionlessTableGateway;
use Shipard\Module\Core\Exchange\Enrich\RowEnrichmentPipeline;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Module\Docs\Core\BoundNumberSeriesProvisioner;
use Shipard\Module\Docs\Core\DocDocument;
use Shipard\Module\Docs\Core\DocRowCalculator;
use Shipard\Module\Docs\Core\DocTypes;
use Shipard\Module\Docs\Core\OwnCompanyResolver;
use Shipard\Module\Docs\Core\RoundingModes;
use Shipard\Core\Accounting\JournalDimensionSet;
use Shipard\Module\Core\Exchange\Resolve\AccountResolver;
use Shipard\Module\Core\Exchange\Resolve\DimensionResolver;
use Shipard\Module\Core\Exchange\Resolve\BankAccountResolver;
use Shipard\Module\Core\Exchange\Resolve\ItemResolver;
use Shipard\Module\Core\Exchange\Resolve\PartyResolver;
use Shipard\Module\Core\Exchange\Resolve\ResolveResult;
use Shipard\Module\Core\Exchange\Resolve\ResolveStatus;
use Shipard\Module\Core\Exchange\Resolve\UnitResolver;
use Shipard\Module\Core\Exchange\Resolve\VatCodeResolver;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;
use Shipard\Module\World\Trade\TradeUnionResolver;

/**
 * Orchestrator of the canonical → DB save pipeline. See
 * docs/exchange-format.md §10 for the full step sequence.
 *
 *   /validate  — schema + DocumentValidator, no DB writes, no resolve.
 *   /preview   — validate + full resolve, populates `_resolve`.
 *   /apply     — validate + resolve + reconcile with userAction +
 *                NestedTransaction { side-creates + saveDocument +
 *                lineage update } — vlastní transakce, nebo SAVEPOINT
 *                uvnitř transakce volajícího (generátory dokladů, #110).
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

    /**
     * DPH kontext dokladu ({@see vatContext()}) — cache per canonical, protože
     * ho čtou appendRecapSourceIssue() (před resolveAll), resolveAll() i
     * transform() (až v transakci apply).
     *
     * @var array{key: string, ctx: array<string, mixed>}|null
     */
    private ?array $vatContextCache = null;

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

    /**
     * D4 (tasks/exchange-preview-vat-recompute.md): rozdíl částky k úhradě
     * mezi dokladem dodavatele a skutečným výpočtem, od kterého náhled
     * hlásí `computed_total_mismatch`. `computed` už nese zaokrouhlení
     * podle `deriveTotalRoundingMode()`, takže stačí haléř.
     */
    private const COMPUTED_TOTAL_TOLERANCE = 0.01;

    /** Náhled nic nezakládá — `transform()` bez založených entit (D2). */
    private const NO_SIDE_IDS = ['supplier' => null, 'customer' => null, 'supplierBank' => null, 'rowItems' => []];

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
        'proformaIssued'       => 'invpo',
        'proformaReceived'     => 'invpi',
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
        private readonly VatCodeDerivation $vatCodeDerivation,
        private readonly VatPlaceDerivation $vatPlaceDerivation,
        /**
         * Taxonomie `core.exchange.contentTags` (compiled, `name` už
         * lokalizované): `crossBorderSupply` pro fallback druhu plnění
         * a veto (tasks/exchange-received-supply-kind.md D2/D4), `name`
         * pro zprávu `supply_kind_derived`. Prázdná = bez fallbacku.
         *
         * @var array<string, array<string, mixed>>
         */
        private readonly array $contentTags = [],
        /**
         * Dimenze deníku z objektu `dimensions` (#110 T2) — přirozený klíč →
         * id záznamu. Null = DS bez dimenzí s `exchangeKey`, objekt se ignoruje
         * (neznámé klíče hlásí DocumentValidator).
         */
        private readonly ?DimensionResolver $dimensionResolver = null,
        /**
         * `economy_items.accounting_account` existuje (extension
         * `economy.accounting`) — účet položky pro `effectiveAccount`
         * v náhledu (#111 D7b). False = zdroj dat bez účetnictví: účet
         * z položky se nedohledává (dotaz na chybějící sloupec by náhled
         * shodil), `effectiveAccount` z položky je null.
         */
        private readonly bool $itemsHaveAccountingAccount = false,
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
        $contentTags = $config->cfgItem('core.exchange.contentTags');

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
            documentValidator: new DocumentValidator(JournalDimensionSet::fromConfig($config)),
            partyResolver: new PartyResolver($db, $own),
            itemResolver: new ItemResolver($db),
            unitResolver: new UnitResolver($db),
            vatCodeResolver: new VatCodeResolver($vatRateResolver),
            bankAccountResolver: new BankAccountResolver($db),
            accountResolver: new AccountResolver($db),
            vatCodeDerivation: new VatCodeDerivation($vatRateResolver),
            vatPlaceDerivation: new VatPlaceDerivation(new TradeUnionResolver($config)),
            contentTags: is_array($contentTags) ? $contentTags : [],
            dimensionResolver: new DimensionResolver($db, JournalDimensionSet::fromConfig($config), $tables),
            itemsHaveAccountingAccount: self::hasColumn($tables['economy_items'] ?? null, 'accounting_account'),
        );
    }

    private static function hasColumn(?TableDefinition $table, string $column): bool
    {
        foreach ($table?->columns ?? [] as $col) {
            if ($col->id === $column) {
                return true;
            }
        }
        return false;
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
            $tables[$tableName] ?? null,
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
        $this->appendAuthorIssue($canonical, $validatorIssues);
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
        //    Vynechané řádky (#111 D9, D12) z klientského _resolve jednou pro
        //    všechny kroky: důvod přepočtu rekapitulace, náhledový plán,
        //    bloky řádků.
        $clientResolve = is_array($canonical['_resolve'] ?? null) ? $canonical['_resolve'] : [];
        $rowSkips = $this->skippedRowIndices($canonical, $clientResolve);
        $issues = $this->documentValidator->validate($canonical);
        $this->appendAuthorIssue($canonical, $issues);
        $this->appendVatModeIssue($canonical, $issues);
        $this->appendVatHeaderIssues($canonical, $issues);
        $this->appendRecapSourceIssue($canonical, $issues, $rowSkips);
        $resolved = $this->withVatBlocks($canonical, $this->resolveAll($canonical, $issues));
        // 2b. Efektivní položka a účet řádku pro review modal (#111 D3, D7b)
        //     — čte uložené volby z klientského _resolve, ale nerozhoduje
        //     (reconcile se v náhledu nevolá).
        $resolved = $this->annotateRowDisplay($canonical, $resolved, $rowSkips);
        // 3. Rekapitulace a součty, jak skončí na dokladu — stejným kódem
        //    jako uložení (tasks/exchange-preview-vat-recompute.md D2–D4, D6);
        //    vynechané řádky v nich nejsou (#111 D9).
        $resolved['computed'] = $this->computePreviewAmounts($canonical, $resolved, $issues, $rowSkips);
        $enriched = $this->withResolve($canonical, $resolved, $issues);

        // preview always succeeds even with errors — client renders the
        // payload and decides what to do.
        return ApplyResult::ok($enriched);
    }

    /**
     * Náhled návrhu: rekapitulace DPH, součty a sazby řádků, **které skončí
     * na dokladu** — spočítané `DocDocument::computeAmounts()`, tedy stejným
     * kódem jako `beforeSave()` při apply, ne opsané z toho, co přečetla AI
     * (tasks/exchange-preview-vat-recompute.md D2–D4, D6; #87 task A).
     *
     * Vstup staví tentýž `transform()` jako apply, s náhledovým plánem
     * ({@see previewPlan}): kódy DPH a jednotky z čerstvého resolve, bez
     * založených entit a bez řady. Instanci dokumentu dává gateway podle
     * typu dokladu, takže platí přetížení podtříd (účetní doklad sčítá
     * z řádků, rekapitulaci nemá).
     *
     * D4: částka k úhradě se porovná s dokladem dodavatele → warning
     * `computed_total_mismatch` (u samovyměření se liší daň, k úhradě
     * sedí). Heuristický `totals_mismatch` validátoru (odhad z canonicalu)
     * skutečný výpočet nahrazuje — z issues se vyřadí, dvě hlášky o tomtéž
     * by mátly. Bez `canonical.totals.totalAmount` se neporovnává.
     *
     * D6: výjimka náhled neshodí — `null` + info `computed_unavailable`
     * (zalogováno), frontend ukáže data z canonicalu s poznámkou.
     *
     * `rows` (#97): cena za jednotku a cena položkových řádků, jak je
     * spočítal doklad, klíčované **indexem canonicalu** (`index`) — pořadí
     * z {@see transformedRowIndices()}, ne z pozice ve výstupu. U přijatého
     * dokladu neplátce DPH jsou to ceny včetně daně dodavatele; bez nich by
     * náhled ukazoval řádky bez daně a součty s daní.
     *
     * Vynechané řádky (#111 D9): náhledový plán nese `rowSkips` stejně jako
     * apply, takže vynechaný řádek chybí v `rows`, rekapitulaci i součtech
     * a rozdíl proti dokladu dodavatele hlásí `computed_total_mismatch`.
     * Rekapitulace se při vynechání přepočítá z řádků (D12,
     * {@see resolveRecapSource()}).
     *
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $resolved  Výstup {@see resolveAll}.
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     * @param list<int> $rowSkips Vynechané řádky ({@see skippedRowIndices}).
     * @return array{recapSource: string, recapFallback: ?string, vatRecap: list<array<string, mixed>>, totals: array<string, float>, rows: list<array{index: int, unitPrice: ?float, totalPrice: ?float}>}|null
     *         Blok `_resolve.computed` (D3) v měně dokladu; domácí měna se nevrací.
     */
    private function computePreviewAmounts(array $canonical, array $resolved, array &$issues, array $rowSkips): ?array
    {
        $unavailable = static function (array &$issues): void {
            $issues[] = [
                'severity' => 'info',
                'path'     => 'totals',
                'code'     => 'computed_unavailable',
                'message'  => 'Rekapitulaci DPH a součty dokladu nešlo spočítat'
                    . ' — náhled ukazuje údaje přečtené z dokladu, ne výsledek výpočtu.',
            ];
        };
        $plan = $this->previewPlan($resolved, $rowSkips);
        try {
            $data = $this->transform($canonical, $plan, self::NO_SIDE_IDS, null);
            $doc = $this->headsGateway->createDocument($data);
            if (!$doc instanceof DocDocument) {
                // Registr bez DocDocument pro typ (holý DefaultDocument) —
                // není co počítat; stav, ne pád, proto bez logu výjimky.
                $unavailable($issues);
                return null;
            }
            $computed = $doc->computeAmounts($data);
        } catch (\Throwable $e) {
            ErrorLogger::logException($e, 'DocumentApplier::preview: computeAmounts failed');
            $unavailable($issues);
            return null;
        }

        $recapSource = $this->resolveRecapSource($canonical, $rowSkips);
        $rowIndices = $this->transformedRowIndices(
            is_array($canonical['rows'] ?? null) ? $canonical['rows'] : [],
            $plan,
        );
        $computedRows = [];
        foreach (array_values($computed['rows']) as $pos => $row) {
            if (!isset($rowIndices[$pos]) || (int) ($row['row_kind'] ?? 1) !== 1) {
                continue;
            }
            $computedRows[] = [
                'index'      => $rowIndices[$pos],
                'unitPrice'  => isset($row['unit_price']) ? (float) $row['unit_price'] : null,
                'totalPrice' => isset($row['total_price']) ? (float) $row['total_price'] : null,
            ];
        }
        $result = [
            // Zdroj podle toho, co dokument skutečně použil (převzatá jen
            // neprázdná); důvod fallbacku z téhož odvození jako transform().
            'recapSource'   => $computed['recapDeclared'] ? 'declared' : 'computed',
            'recapFallback' => $recapSource['fallback'],
            'vatRecap'      => array_map(
                static fn (array $r): array => [
                    'vatCode'       => (string) ($r['vat_code'] ?? ''),
                    'vatPct'        => (float) ($r['vat_pct'] ?? 0),
                    'base'          => (float) ($r['base'] ?? 0),
                    'tax'           => (float) ($r['tax'] ?? 0),
                    'total'         => (float) ($r['total'] ?? 0),
                    'isReversePair' => !empty($r['is_reverse_pair']),
                ],
                array_values($computed['recap']),
            ),
            'totals' => [
                'totalBase'     => (float) ($data['total_base'] ?? 0),
                'totalVat'      => (float) ($data['total_vat'] ?? 0),
                'totalAmount'   => (float) ($data['total_amount'] ?? 0),
                'totalRounding' => (float) ($data['total_rounding'] ?? 0),
            ],
            'rows' => $computedRows,
        ];

        // D4: skutečný výpočet nahrazuje heuristiku validátoru.
        $issues = array_values(array_filter(
            $issues,
            static fn (array $issue): bool => ($issue['code'] ?? null) !== 'totals_mismatch',
        ));
        $declared = $canonical['totals']['totalAmount'] ?? null;
        if ($declared !== null && is_numeric($declared)) {
            $declaredF = round((float) $declared, 2);
            $computedF = $result['totals']['totalAmount'];
            if (abs($declaredF - $computedF) > self::COMPUTED_TOTAL_TOLERANCE) {
                $issues[] = [
                    'severity' => 'warning',
                    'path'     => 'totals.totalAmount',
                    'code'     => 'computed_total_mismatch',
                    'message'  => "Částka k úhradě na dokladu dodavatele {$declaredF} se liší od částky,"
                        . " která skončí na dokladu ({$computedF}) — zkontroluj řádky a režim DPH.",
                    'declared' => $declaredF,
                    'computed' => $computedF,
                ];
            }
        }

        return $result;
    }

    /**
     * Plán pro náhled (D2): tvar {@see reconcile}, ale z klientských
     * rozhodnutí jen vynechané řádky (`rowSkips`, #111 D9) a bez založených
     * entit — strany, položky a účty `null`, jen kódy DPH a jednotky
     * z čerstvého resolve. `reconcile()` se **nevolá**: u nespárované
     * strany či položky by nastavil `unresolved_required` a přidal error
     * issue, které náhled nehlásí. Číselná řada je parametr `transform()`
     * (null), `rowOperationDefaults` chybí (pohyb řádku výpočet částek
     * neovlivní).
     *
     * @param array<string, mixed> $resolved  Výstup {@see resolveAll}.
     * @param list<int> $rowSkips Vynechané řádky ({@see skippedRowIndices}).
     * @return array<string, mixed>
     */
    private function previewPlan(array $resolved, array $rowSkips): array
    {
        $plan = [
            'errorCode'            => null,
            'errorMessage'         => null,
            'partyCreates'         => [],
            'bankCreate'           => null,
            'rowItemCreates'       => [],
            'rowSkips'             => $rowSkips,
            'rowNoItems'           => [],
            'rowItemPins'          => [],
            'resolvedSupplier'     => null,
            'resolvedCustomer'     => null,
            'resolvedBalanceParty' => null,
            'resolvedSupplierBank' => null,
            'resolvedRowItems'     => [],
            'resolvedRowUnits'     => [],
            'resolvedRowVatCodes'  => [],
            'resolvedRowAccounts'  => [],
            'resolvedRowPartners'  => [],
            'resolvedHeadPartner'  => null,
            'cashDeskId'           => null,
        ];
        foreach ($resolved['rows'] ?? [] as $rowResolve) {
            if (!is_array($rowResolve) || !isset($rowResolve['index'])) {
                continue;
            }
            $i = (int) $rowResolve['index'];
            $unitFresh = $rowResolve['unit'] ?? null;
            $plan['resolvedRowUnits'][$i] = ($unitFresh['status'] ?? null) === 'matched'
                ? ($unitFresh['matchedId'] ?? null)
                : null;
            $plan['resolvedRowVatCodes'][$i] = $rowResolve['vatCode'] ?? null;
        }
        return $plan;
    }

    /**
     * @param array<string, mixed> $canonical
     */
    public function apply(array $canonical): ApplyResult
    {
        $this->applyOptionsCache = is_array($canonical['applyOptions'] ?? null)
            ? $canonical['applyOptions']
            : [];

        // Přegenerovat v místě (#110 D24, Q2): cílový koncept se nahradí
        // z payloadu; idempotence podle zprávy se pro výslovné
        // přegenerování nehodí (zpráva už na doklad ukazuje).
        $replaceId = self::replaceConceptId($this->applyOptionsCache);

        // 0. Idempotency check — same extracted_document already applied?
        //    Return existing savedDocId without re-saving. See Phase 2 spec
        //    "Idempotency apply".
        $idempotent = $replaceId === null ? $this->checkIdempotent($canonical) : null;
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
        $clientResolve = is_array($canonical['_resolve'] ?? null) ? $canonical['_resolve'] : [];
        // Vynechané řádky (#111 D9, D12) — tentýž seznam pro důvod přepočtu
        // rekapitulace i pro plán.
        $rowSkips = $this->skippedRowIndices($canonical, $clientResolve);
        $validatorIssues = $this->documentValidator->validate($canonical);
        $this->appendAuthorIssue($canonical, $validatorIssues);
        $this->appendVatModeIssue($canonical, $validatorIssues);
        $this->appendVatHeaderIssues($canonical, $validatorIssues);
        $this->appendRecapSourceIssue($canonical, $validatorIssues, $rowSkips);

        // 3. Re-run resolve (fresh DB read; client's _resolve might be stale).
        $resolved = $this->withVatBlocks($canonical, $this->resolveAll($canonical, $validatorIssues));

        // 4. Reconcile with client _resolve.*.userAction.
        $plan = $this->reconcile($resolved, $clientResolve, $validatorIssues, $rowSkips);

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
            $seriesIdOption = $canonical['applyOptions']['numberSeriesId'] ?? null;
            $seriesIdOption = is_int($seriesIdOption) && $seriesIdOption > 0 ? $seriesIdOption : null;
            if ($seriesCode !== null && $seriesIdOption !== null) {
                return ApplyResult::error(
                    'number_series_conflict',
                    'applyOptions nese numberSeriesCode i numberSeriesId — řadu určuje jen jedno z nich.',
                    $enriched,
                    statusCode: 422,
                );
            }
            try {
                $numberSeriesId = $seriesIdOption !== null
                    ? $this->resolveNumberSeriesById($docTypeCode, $seriesIdOption)
                    : $this->resolveNumberSeriesFor($docTypeCode, $seriesCode);
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

        // 6–11. Transactional save — NestedTransaction: vlastní transakce,
        //       nebo SAVEPOINT uvnitř cizí (periodická fakturace volá apply()
        //       ve své transakci a atomicky navazuje doklad na období, #110).
        //       Neúspěch closure = rollback vlastní práce; vnější transakce
        //       zůstává volajícímu.
        try {
            [$savedDocId, $sideCreatedIds] = NestedTransaction::run($this->db, function () use ($canonical, $resolved, &$plan, &$validatorIssues, $numberSeriesId, $replaceId, $docTypeCode): array {
            // Přegenerovat (Q2): zámek cílového konceptu — zavře závod
            // „koncept mezitím potvrzený“ (ApplyAbortedException → 409/422).
            $this->lockReplaceTarget($replaceId, $docTypeCode);

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
            if ($replaceId !== null) {
                $data = $this->applyReplaceConcept($data, $replaceId, $validatorIssues);
            }

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

            return [$savedDocId, $sideCreatedIds];
            });
        } catch (ApplyAbortedException $e) {
            return ApplyResult::error($e->errorCode, $e->getMessage(), $enriched, statusCode: $e->statusCode);
        } catch (\Throwable $e) {
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
        // D2 (exchange-received-reverse-charge): u přijatého dokladu se kódy
        // DPH hledají v číselníku NAŠÍ registrace; ostatní doklady jdou
        // kaskádou registrationCountry → prefix kódu → země dodavatele.
        $vatCtx = $this->vatContext($canonical);
        $vatCountry = $vatCtx['derive']
            ? (string) $vatCtx['ownCountry']
            : strtolower((string) ($canonical['vat']['registrationCountry'] ?? ''));
        $taxPointDate = $vatCtx['taxPointDate'];

        foreach ($rows as $idx => $row) {
            // Text řádku, jak skončí na dokladu (#84 D2): náhled ho zobrazuje
            // hotový ze serveru, frontend skladbu nezrcadlí. Informativní,
            // apply ho nečte — skládá si ho znovu tímtéž helperem.
            $rowResolve = [
                'index'   => $idx,
                'rowText' => CanonicalRowText::compose(is_array($row) ? $row : []),
            ];
            if (self::rowHasItem($row)) {
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
            $rowDimensions = $this->resolveDimensions(
                is_array($row['dimensions'] ?? null) ? $row['dimensions'] : [],
                "rows.{$idx}.dimensions",
                $issues,
            );
            if ($rowDimensions !== []) {
                $rowResolve['dimensions'] = $rowDimensions;
            }
            $vatResolve = $this->resolveRowVatCode(
                (int) $idx,
                is_array($row) ? $row : [],
                $vatCtx,
                $vatCountry,
                $supplierCountry,
                $issues,
            );
            if ($vatResolve !== null) {
                $rowResolve['vatCode'] = $vatResolve;
            }
            $rowsResolve[] = $rowResolve;
        }

        $resolved = ['rows' => $rowsResolve];
        // Dimenze hlavičky (#110 D23) — výchozí hodnota pro řádky bez vlastní.
        $headDimensions = $this->resolveDimensions(
            is_array($canonical['dimensions'] ?? null) ? $canonical['dimensions'] : [],
            'dimensions',
            $issues,
        );
        if ($headDimensions !== []) {
            $resolved['dimensions'] = $headDimensions;
        }
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

    // ── Vynechané řádky (#111 D9, D12) ──────────────────────────────────────

    /**
     * Řádek canonicalu nese blok položky — tatáž podmínka, podle které
     * {@see resolveAll()} vyrábí `_resolve.rows[i].item`; podle ní
     * {@see skippedRowIndices()} uznává položkovou volbu `skip`.
     */
    private static function rowHasItem(mixed $row): bool
    {
        return is_array($row) && is_array($row['item'] ?? null) && $row['item'] !== [];
    }

    /**
     * Indexy řádků canonicalu vynechaných volbou uživatele v klientském
     * `_resolve` (#111 D9): řádková `rows[i].userAction = skip`, nebo
     * položková `rows[i].item.userAction = skip` u řádku s blokem položky
     * („Vynechat řádek“ z review modalu). Jediný zdroj pro {@see reconcile()}
     * (`rowSkips` plánu), {@see previewPlan()}, {@see resolveRecapSource()}
     * (D12) i {@see annotateRowDisplay()} — náhled a apply vynechávají
     * stejně. Pozice v `_resolve.rows` = index canonicalu ({@see resolveAll()}
     * vyrábí záznam pro každý řádek). Jede nad canonicalem, protože ho
     * {@see appendRecapSourceIssue()} potřebuje před resolve.
     *
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $clientResolve Klientské `_resolve`.
     * @return list<int>
     */
    private function skippedRowIndices(array $canonical, array $clientResolve): array
    {
        $rows = is_array($canonical['rows'] ?? null) ? $canonical['rows'] : [];
        $clientRows = is_array($clientResolve['rows'] ?? null) ? $clientResolve['rows'] : [];
        $out = [];
        foreach ($rows as $i => $row) {
            $clientRow = is_array($clientRows[$i] ?? null) ? $clientRows[$i] : [];
            if (($clientRow['userAction'] ?? null) === 'skip') {
                $out[] = (int) $i;
                continue;
            }
            if (self::rowHasItem($row)
                && is_array($clientRow['item'] ?? null)
                && ($clientRow['item']['userAction'] ?? null) === 'skip'
            ) {
                $out[] = (int) $i;
            }
        }
        return $out;
    }

    // ── Reconcile: validate userAction → execution plan ─────────────────────

    /**
     * @param array<string, mixed> $resolved        Fresh resolve output.
     * @param array<string, mixed> $clientResolve   Client's _resolve with userAction set.
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     * @param list<int> $rowSkips Vynechané řádky ({@see skippedRowIndices}, #111 D9).
     * @return array{
     *   errorCode: ?string, errorMessage: ?string,
     *   partyCreates: array<string, array<string, mixed>>,
     *   bankCreate: ?array<string, mixed>,
     *   rowItemCreates: array<int, array<string, mixed>>,
     *   rowSkips: list<int>,
     *   rowNoItems: list<int>,
     *   rowItemPins: list<int>,
     *   resolvedSupplier: ?int, resolvedCustomer: ?int, resolvedBalanceParty: ?int,
     *   resolvedSupplierBank: ?int,
     *   resolvedRowItems: array<int, int|null>,
     *   resolvedRowUnits: array<int, int|null>,
     *   resolvedRowVatCodes: array<int, array<string, mixed>|null>
     * }
     */
    private function reconcile(array $resolved, array $clientResolve, array &$issues, array $rowSkips): array
    {
        $plan = [
            'errorCode'           => null,
            'errorMessage'        => null,
            'partyCreates'        => [],
            'bankCreate'          => null,
            'rowItemCreates'      => [],
            'rowSkips'            => $rowSkips,
            'rowNoItems'          => [],
            // Řádky s výslovnou volbou položky useExisting:<id> (#111 D7b, D8):
            // účet navržený historií / štítkem se nezapíše, mapování kódu
            // dodavatele se přepíše.
            'rowItemPins'         => [],
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
            // Dimenze deníku (#110 T2): id dimenze → id záznamu, jen nalezené.
            // Klíč je autoritativní — žádná userAction, nenalezený dal error.
            'resolvedHeadDimensions' => self::matchedDimensionIds($resolved['dimensions'] ?? []),
            'resolvedRowDimensions'  => [],
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
        // Enrichment řádků podle klíče `index` (RowHistoryEnricher::writeEnrichment),
        // ne podle pozice — pro D7b porovnání účtu.
        $enrichmentByIndex = [];
        foreach ($clientRows as $entry) {
            if (is_array($entry) && isset($entry['index']) && is_array($entry['enrichment'] ?? null)) {
                $enrichmentByIndex[(int) $entry['index']] = $entry['enrichment'];
            }
        }
        foreach ($resolved['rows'] ?? [] as $i => $rowResolve) {
            if (in_array($i, $rowSkips, true)) {
                // Vynechaný řádek — řádková volba i „Vynechat řádek" z review
                // modalu (rows[i].item = skip), {@see skippedRowIndices}:
                // na doklad se nezapíše, resolveOne se nevolá.
                continue;
            }
            $clientRow = $clientRows[$i] ?? null;

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
                    if ($itemRes['id'] !== null && is_string($itemAction) && str_starts_with($itemAction, 'useExisting:')) {
                        $plan['rowItemPins'][] = $i;
                    }
                }
            } else {
                $plan['resolvedRowItems'][$i] = null;
            }

            $unitFresh = $rowResolve['unit'] ?? null;
            $plan['resolvedRowUnits'][$i] = ($unitFresh['status'] ?? null) === 'matched'
                ? ($unitFresh['matchedId'] ?? null)
                : null;
            $plan['resolvedRowDimensions'][$i] = self::matchedDimensionIds($rowResolve['dimensions'] ?? []);

            $plan['resolvedRowVatCodes'][$i] = $rowResolve['vatCode'] ?? null;

            // Účet z čísla (kontace) — passthrough, žádná userAction (číslo je
            // autoritativní; nenalezeno už dalo warning v resolveAll).
            $accountFresh = $rowResolve['account'] ?? null;
            $plan['resolvedRowAccounts'][$i] = ($accountFresh['status'] ?? null) === 'matched'
                ? ($accountFresh['matchedId'] ?? null)
                : null;

            // D7b (#111): u ručně zvolené položky jde účet s položkou — účet
            // řádku navržený historií nebo štítkem (shoda čísla se
            // `enrichment.suggested.account`) se nezapíše. Účet od uživatele
            // nebo z AI zůstává; `noItem` se nemění.
            $suggestedAccount = $enrichmentByIndex[(int) ($rowResolve['index'] ?? $i)]['suggested']['account'] ?? null;
            if ($plan['resolvedRowAccounts'][$i] !== null
                && in_array($i, $plan['rowItemPins'], true)
                && is_string($suggestedAccount)
                && trim((string) ($accountFresh['number'] ?? '')) === trim($suggestedAccount)) {
                $plan['resolvedRowAccounts'][$i] = null;
            }

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
     * Řádek s výslovnou volbou položky (`rowItemPins`, #111 D8) naučené
     * mapování **přepíše** (`ON DUPLICATE KEY UPDATE`) — oprava špatného
     * napárování se musí projevit u příští faktury. Automatické napárování
     * a potvrzení z Konceptu (`SupplierCodeCaptureHandler`) dál jen
     * `INSERT IGNORE`.
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
            $sql = in_array($i, $plan['rowItemPins'] ?? [], true)
                ? 'INSERT INTO [economy_items_supplier_codes]
                   ([person], [item], [supplier_code], [supplier_name], [created])
                   VALUES (%i, %i, %s, %sN, NOW())
                   ON DUPLICATE KEY UPDATE [item] = VALUES([item]), [supplier_name] = VALUES([supplier_name])'
                : 'INSERT IGNORE INTO [economy_items_supplier_codes]
                   ([person], [item], [supplier_code], [supplier_name], [created])
                   VALUES (%i, %i, %s, %sN, NOW())';
            $this->executeSql(
                $sql,
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
        // Nedaňový typ (docTypes[].tax_document: false, #79 D1): DUZP/DPPD
        // kanonický doklad nenese — ignorují se už tady (DocDocument je
        // při uložení stejně vynuluje).
        $taxDocument = DocTypes::isTaxDocument($this->config, $docType);

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
        $vatCtx = $this->vatContext($canonical);
        // Režim výpočtu: volba uživatele → derivace → canonical → default,
        // s ochranou „Bez DPH“ + samovyměření ({@see effectiveVatMode}).
        // Koriguje se jen interní vat_mode, canonical vč. totals zůstává
        // nedotčený a DocDocument si base/VAT/totals přepočítá sám.
        $vatMode = self::VAT_MODE_MAP[$this->effectiveVatMode($canonical, $vatCtx)['value']];
        // Přijatý doklad neplátce DPH (#97): ceny řádků včetně daně dodavatele
        // (prázdné = nic se nepřevádí). Hlavička bez registrace a rekapitulace
        // plyne z téhož kontextu v resolveVatRegistrationFor() / resolveRecapSource().
        $nonPayerGross = $this->nonPayerGrossTotals($canonical, $vatCtx);
        // Místo plnění: u přijatého dokladu efektivní místo z kontextu
        // (odvozené z prefixu DIČ dodavatele, jinak hodnota z canonicalu) —
        // tasks/exchange-received-vat-place.md D1. Stejné místo dostala
        // derivace kódů řádků, jinak by kód a hlavička nesouhlasily.
        $vatPlace = self::VAT_PLACE_MAP[(string) ($vatCtx['place'] ?? 'domestic')] ?? 0;
        // Autorita rekapitulace + řádky k převzetí (R3/I4/I7). Přepočítanou
        // rekapitulaci si DocDocument spočítá z řádků sám, `vatRecap` se pak
        // do payloadu nedává — prázdný child set by u nového dokladu nic
        // nezměnil, ale u převzaté je to jediná cesta, jak se data dostanou
        // do docs_core_vat_recap.
        $recapSource = $this->resolveRecapSource($canonical, $plan['rowSkips'] ?? []);
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
            'vat_duzp'             => $taxDocument ? ($canonical['dates']['taxPointDate'] ?? null) : null,
            'vat_dppd'             => $taxDocument ? ($canonical['dates']['vatObligationDate'] ?? null) : null,
            'period_from'          => $canonical['dates']['periodFrom'] ?? null,
            'period_to'            => $canonical['dates']['periodTo'] ?? null,
            // Otevírací / uzávěrkové období (#69 D20) — jen import mód;
            // AI extrakce ani ruční apply doklad do uzávěrky nezařadí.
            'fiscal_period_type'   => is_array($importNumber)
                                       && in_array($canonical['fiscalPeriodType'] ?? null, ['opening', 'closing'], true)
                                       ? $canonical['fiscalPeriodType'] : null,
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
            'total_rounding_mode'  => $this->deriveTotalRoundingMode(
                $canonical,
                $nonPayerGross !== [] ? round(array_sum($nonPayerGross), 2) : null,
            ),
            'payment_method'       => $paymentMethod,
            'payment_reference'    => $canonical['payment']['paymentReference'] ?? null,
            'specific_symbol'      => $canonical['payment']['specificSymbol'] ?? null,
            'constant_symbol'      => $canonical['payment']['constantSymbol'] ?? null,
            'notice'               => $canonical['notes']['internal'] ?? null,
            'doc_notice'           => $canonical['notes']['onDocument'] ?? null,
            'source_kind'          => $canonical['source']['kind'] ?? null,
            'source_message'       => $canonical['source']['message'] ?? null,
            'source_extracted_at'  => $this->mapExtractedAt($canonical['source']['extractedAt'] ?? null),
            // Dimenze deníku (#110 T2): sloupce hlavičky podle deklarace
            // (`headColumn`); chybějící objekt = sloupce se nezapíší (NULL).
            ...$this->dimensionColumns($plan['resolvedHeadDimensions'] ?? [], head: true),
            'docState'             => $targetDocState,
            'rows'                 => $this->transformRows(
                $canonical['rows'] ?? [],
                $plan,
                $sideIds,
                $vatCtx['nonPayer'],
                $nonPayerGross,
            ),
            'vatRecap'             => $recapSource['recap'] !== [] ? $recapSource['recap'] : null,
        ];

        $head = array_filter(
            $data,
            static fn($v, $k) => $v !== null || in_array($k, ['rows'], true),
            ARRAY_FILTER_USE_BOTH,
        ) + ['rows' => $data['rows']];

        // Autor dokladu (#93 D9): přítomný klíč jde do hlavičky i jako null —
        // mimo filtr nullů, protože „bez autora“ je rozhodnutí, ne chybějící
        // hodnota (DocAuthorResolver přítomný klíč nepřepisuje). Chybějící
        // klíč do dat nepatří: autora pak určí resolver.
        $applyOptions = is_array($canonical['applyOptions'] ?? null) ? $canonical['applyOptions'] : [];
        if (array_key_exists('author', $applyOptions)) {
            $head['author'] = $applyOptions['author'] !== null ? (int) $applyOptions['author'] : null;
        }

        return $head;
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
     * jako kódy řádků. Prázdný řetězec = země neznámá. U přijatého dokladu
     * na zdroji s registrací DPH sem už přichází země naší registrace
     * ({@see vatContext()}, D2) a kaskáda se neuplatní.
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

    // ── Kód DPH řádků přijatého dokladu (VatCodeDerivation, D1–D3) ─────────

    /**
     * DPH kontext dokladu — naše registrace (D2) a rozhodnutí o kódu DPH
     * každého položkového řádku (D1), tasks/exchange-received-reverse-charge.md;
     * efektivní místo plnění (tasks/exchange-received-vat-place.md D1).
     * Počítá se líně a cachuje per canonical ({@see $vatContextCache}).
     *
     * `derive` je true jen u přijatého dokladu (`selfParty: customer`) na
     * zdroji s registrací DPH platnou k datu dokladu; jinak jde vše dnešní
     * cestou (vystavené a účetní doklady).
     *
     * `nonPayer` (#97 D2): přijatý doklad, ke kterému žádná registrace
     * k datu neplatí — zdroj tehdy nebyl plátcem. Doklad vznikne Bez DPH
     * s daní dodavatele v cenách řádků ({@see nonPayerGrossTotals()}).
     * Plátcovství je registrace k datu, ne příznak `economy.vatAgenda`
     * (docs/ds-setup.md D5): bývalý plátce má příznak vypnutý, ale starší
     * doklady s DPH. Import (`applyOptions.importNumber` — starý Shipard,
     * datové sady) přenáší hotové doklady: platnost registrace se u něj
     * nezkoumá a `nonPayer` je vždy false.
     *
     * `place` je místo, které na dokladu skončí: u přijatého dokladu
     * odvozené z prefixu DIČ dodavatele ({@see VatPlaceDerivation},
     * `placeSource: "vatId"`, `placePrefix`), jinak `vat.place`
     * z canonicalu (`placeSource: "ai"`), nebo null. Čtou ho tři místa —
     * derivace kódu řádků, transform `vat_place` a hlavičkové issues.
     *
     * Druh plnění řádku je efektivní ({@see effectiveSupplyKinds()},
     * tasks/exchange-received-supply-kind.md D2): z canonicalu, jinak mimo
     * tuzemsko ze štítku řádku (`supplyKindSource: "tag"`, warning
     * `supply_kind_derived`). Pořadí: místo z DIČ s druhy z canonicalu →
     * fallback druhů → jediný doplňkový průchod místa, když fallback něco
     * doplnil a první průchod selhal na prefixu (XI jen zboží).
     *
     * @param array<string, mixed> $canonical
     * @return array{
     *   derive: bool,
     *   nonPayer: bool,
     *   ownCountry: ?string,
     *   ownRegistrationId: ?int,
     *   taxPointDate: ?string,
     *   place: ?string,
     *   placeSource: ?string,
     *   placePrefix: ?string,
     *   autoPlace: ?string,
     *   pins: array{place: ?string, mode: ?string, rows: array<int, string>, invalid: list<array{severity: string, path: string, code: string, message: string}>},
     *   codeOptions: list<array{code: string, label: string, pct: float, reverseCharge: bool, reducedDeduction: bool, supplyKind: ?string}>,
     *   rows: array<int, array{
     *     input: string, derived: ?string, reason: ?string,
     *     effective: ?string, matchedBy: ?string,
     *     issue: ?array{severity: string, code: string, message: string},
     *     supplyKind: ?string, supplyKindSource: ?string, tag: ?string
     *   }>
     * }
     */
    private function vatContext(array $canonical): array
    {
        $rows = is_array($canonical['rows'] ?? null) ? $canonical['rows'] : [];
        $supplierVatId = self::partyVatId($canonical['supplier'] ?? null);
        $customerVatId = self::partyVatId($canonical['customer'] ?? null);
        $pins = $this->vatPins($canonical);
        $importMode = is_array($canonical['applyOptions']['importNumber'] ?? null);
        // Klíč cache: strany dokladu (DIČ) rozhodují o místě plnění, štítek
        // dokladu a výjimky řádků o druhu plnění (D2), volby uživatele
        // (#87 B) o místě i kódech, data a import mód o registraci k datu
        // (#97) — bez nich by cache vrátila kontext jiného dokladu / bez
        // fallbacku / bez pinu.
        $key = md5((string) json_encode([
            $canonical['selfParty'] ?? null,
            $canonical['vat'] ?? null,
            $canonical['dates'] ?? null,
            $importMode,
            $supplierVatId,
            $customerVatId,
            $canonical['_resolve']['contentTag']['tag'] ?? null,
            $canonical['_resolve']['contentTag']['rowExceptions'] ?? null,
            [$pins['place'], $pins['mode'], $pins['rows']],
            array_map(
                static fn (mixed $row): ?array => is_array($row)
                    ? [$row['rowKind'] ?? null, $row['accSide'] ?? null, $row['vat'] ?? null]
                    : null,
                $rows,
            ),
        ]));
        if ($this->vatContextCache !== null && $this->vatContextCache['key'] === $key) {
            return $this->vatContextCache['ctx'];
        }

        $taxPointDate = $canonical['dates']['taxPointDate'] ?? ($canonical['dates']['issueDate'] ?? null);
        $vat = is_array($canonical['vat'] ?? null) ? $canonical['vat'] : [];
        $inputPlace = is_string($vat['place'] ?? null) ? $vat['place'] : null;
        $ctx = [
            'derive'            => false,
            'nonPayer'          => false,
            'ownCountry'        => null,
            'ownRegistrationId' => null,
            'taxPointDate'      => is_string($taxPointDate) && $taxPointDate !== '' ? $taxPointDate : null,
            'place'             => $inputPlace,
            'placeSource'       => $inputPlace !== null ? 'ai' : null,
            'placePrefix'       => null,
            // Místo bez volby uživatele — náhled ho ukazuje jako „Automaticky (…)“.
            'autoPlace'         => $inputPlace,
            'pins'              => $pins,
            'codeOptions'       => [],
            'rows'              => [],
        ];

        if (($canonical['selfParty'] ?? null) === 'customer') {
            $own = $this->ownVatRegistration($importMode ? null : $this->registrationDate($canonical));
            if ($own !== null) {
                $ctx['derive'] = true;
                $ctx['ownCountry'] = $own['country'];
                $ctx['ownRegistrationId'] = $own['id'];
            } elseif (!$importMode) {
                $ctx['nonPayer'] = true;
            }
        }

        if ($ctx['derive']) {
            // Místo plnění před kódem — kód na místu závisí (cz-217 vs cz-417)
            // a kontrola souladu existujícího kódu musí dostat totéž místo.
            // První průchod s druhy plnění z canonicalu (XI jen zboží).
            $derivedPlace = $this->vatPlaceDerivation->derive(
                (string) $ctx['ownCountry'],
                $ctx['taxPointDate'],
                $supplierVatId,
                $customerVatId,
                self::rowSupplyKinds($rows),
            );
            if ($derivedPlace['place'] !== null) {
                $ctx['place'] = $derivedPlace['place'];
                $ctx['placeSource'] = 'vatId';
                $ctx['placePrefix'] = $derivedPlace['prefix'];
            }
            // D2: chybějící druh plnění ze štítku řádku potřebuje efektivní
            // místo (jen mimo tuzemsko); místo u XI potřebuje druhy. Jediný
            // doplňkový průchod derivace místa — když fallback něco doplnil
            // a první průchod selhal na prefixu (XI bez druhů); žádný cyklus.
            $kinds = $this->effectiveSupplyKinds($canonical, $rows, $ctx['place']);
            if ($kinds['changed'] && $derivedPlace['place'] === null && $derivedPlace['prefix'] !== null) {
                $secondPlace = $this->vatPlaceDerivation->derive(
                    (string) $ctx['ownCountry'],
                    $ctx['taxPointDate'],
                    $supplierVatId,
                    $customerVatId,
                    array_values(array_map(static fn (array $k): ?string => $k['supplyKind'], $kinds['rows'])),
                );
                if ($secondPlace['place'] !== null) {
                    $ctx['place'] = $secondPlace['place'];
                    $ctx['placeSource'] = 'vatId';
                    $ctx['placePrefix'] = $secondPlace['prefix'];
                    if ($secondPlace['place'] === 'domestic') {
                        // Pojistka: v tuzemsku fallback neplatí — zahodit, třetí průchod není.
                        $kinds = $this->effectiveSupplyKinds($canonical, $rows, 'domestic');
                    }
                }
            }
            $ctx['autoPlace'] = $ctx['place'];
            if ($pins['place'] !== null) {
                // D10 (#87 task B): místo z volby uživatele přebíjí DIČ i AI.
                // Derivace proběhla jen kvůli hodnotě „Automaticky“ (autoPlace);
                // druh plnění ze štítku i kódy dostanou zvolené místo.
                $ctx['place'] = $pins['place'];
                $ctx['placeSource'] = 'user';
                $ctx['placePrefix'] = null;
                $kinds = $this->effectiveSupplyKinds($canonical, $rows, $ctx['place']);
            }
        }

        if ($ctx['derive']) {
            // Nabídka kódů pro ruční volbu (D13) — až po konečném místě;
            // táž množina validuje piny v decideRowVatCode() (jedna funkce,
            // jinak by UI nabídlo a server odmítl). Bez DUZP není z čeho.
            $ctx['codeOptions'] = $ctx['taxPointDate'] !== null
                ? $this->vatCodeDerivation->options((string) $ctx['ownCountry'], $ctx['taxPointDate'], $ctx['place'])
                : [];
            $place = $ctx['place'];
            $reverseCharge = is_bool($vat['reverseCharge'] ?? null) ? $vat['reverseCharge'] : null;
            foreach ($rows as $idx => $row) {
                if (!is_array($row)
                    || (string) ($row['rowKind'] ?? 'item') !== 'item'
                    || isset($row['accSide'])
                    || !is_array($row['vat'] ?? null)) {
                    continue;
                }
                $ctx['rows'][(int) $idx] = $this->decideRowVatCode(
                    (string) $ctx['ownCountry'],
                    $ctx['taxPointDate'],
                    $place,
                    $reverseCharge,
                    $row['vat'],
                    $kinds['rows'][(int) $idx] ?? self::NO_SUPPLY_KIND,
                    $pins['rows'][(int) $idx] ?? null,
                    $ctx['codeOptions'],
                );
            }
        }

        $this->vatContextCache = ['key' => $key, 'ctx' => $ctx];
        return $ctx;
    }

    /**
     * Volby uživatele k DPH z `_resolve` (#87 task B, D8): místo plnění
     * a režim hlavičky (`useValue:<hodnota>`), kód DPH řádku
     * (`useCode:<klíč číselníku>`). Jediné místo, kde se prefixy parsují.
     * Neplatná hodnota nebo akce → `invalid` (error `vat_pin_invalid`, D11)
     * a do voleb se nedostane. Zda volby platí (jen větev derive, D9),
     * rozhoduje {@see vatContext()}; řádky jsou klíčované indexem canonicalu
     * jako v {@see reconcile()}.
     *
     * @param array<string, mixed> $canonical
     * @return array{place: ?string, mode: ?string, rows: array<int, string>, invalid: list<array{severity: string, path: string, code: string, message: string}>}
     */
    private function vatPins(array $canonical): array
    {
        $pins = ['place' => null, 'mode' => null, 'rows' => [], 'invalid' => []];
        $resolve = is_array($canonical['_resolve'] ?? null) ? $canonical['_resolve'] : [];
        $invalid = static function (string $path, string $action) use (&$pins): void {
            $pins['invalid'][] = [
                'severity' => 'error',
                'path'     => $path,
                'code'     => 'vat_pin_invalid',
                'message'  => "Neplatná volba „{$action}“ — u místa plnění a režimu se čeká useValue:<hodnota>,"
                    . ' u kódu DPH řádku useCode:<kód>.',
            ];
        };

        foreach (['place' => self::VAT_PLACE_MAP, 'mode' => self::VAT_MODE_MAP] as $field => $allowed) {
            $action = $resolve['vat'][$field]['userAction'] ?? null;
            if (!is_string($action) || $action === '') {
                continue;
            }
            $value = str_starts_with($action, 'useValue:') ? substr($action, strlen('useValue:')) : '';
            if ($value !== '' && isset($allowed[$value])) {
                $pins[$field] = $value;
            } else {
                $invalid("vat.{$field}", $action);
            }
        }

        foreach ((is_array($resolve['rows'] ?? null) ? $resolve['rows'] : []) as $idx => $rowResolve) {
            $action = is_array($rowResolve) ? ($rowResolve['vatCode']['userAction'] ?? null) : null;
            if (!is_string($action) || $action === '') {
                continue;
            }
            $code = str_starts_with($action, 'useCode:') ? trim(substr($action, strlen('useCode:'))) : '';
            if ($code !== '') {
                $pins['rows'][(int) $idx] = $code;
            } else {
                $invalid("rows.{$idx}.vat.code", $action);
            }
        }
        return $pins;
    }

    /** Canonical `supplier.vatId` / `customer.vatId` jako řetězec; chybí → null. */
    private static function partyVatId(mixed $party): ?string
    {
        if (!is_array($party) || !is_scalar($party['vatId'] ?? null)) {
            return null;
        }
        $vatId = trim((string) $party['vatId']);
        return $vatId !== '' ? $vatId : null;
    }

    /**
     * `rows[].vat.supplyKind` položkových řádků (`rowKind` item, bez kontace)
     * pro omezení prefixu DIČ na druh plnění ({@see VatPlaceDerivation});
     * řádek bez druhu = null.
     *
     * @param array<int|string, mixed> $rows
     * @return list<string|null>
     */
    private static function rowSupplyKinds(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)
                || (string) ($row['rowKind'] ?? 'item') !== 'item'
                || isset($row['accSide'])) {
                continue;
            }
            $kind = $row['vat']['supplyKind'] ?? null;
            $out[] = is_string($kind) && trim($kind) !== '' ? trim($kind) : null;
        }
        return $out;
    }

    /** Řádek bez informace o druhu plnění ({@see effectiveSupplyKinds()}). */
    private const NO_SUPPLY_KIND = ['supplyKind' => null, 'source' => null, 'tag' => null, 'tagSupply' => null];

    /**
     * Efektivní druh plnění položkových řádků (tasks/exchange-received-supply-kind.md
     * D2): `rows[].vat.supplyKind` z canonicalu (`source: "ai"`), jinak — jen
     * když efektivní místo ≠ tuzemsko — `crossBorderSupply` štítku řádku
     * (`source: "tag"`; výjimka řádku před štítkem dokladu,
     * {@see RowEnrichmentPipeline::rowContentTagOf()}). `tagSupply` nese
     * hodnotu štítku vždy — veto `special` (D4) kontroluje derivace sama,
     * i proti druhu z AI. Bez bloku `_resolve.contentTag` (pokrytý doklad,
     * žádný LLM běh) fallback není. Canonical se nemění; `changed` =
     * fallback aspoň jeden druh doplnil.
     *
     * @param array<string, mixed> $canonical
     * @param array<int|string, mixed> $rows
     * @return array{
     *   rows: array<int, array{supplyKind: ?string, source: ?string, tag: ?string, tagSupply: ?string}>,
     *   changed: bool
     * }
     */
    private function effectiveSupplyKinds(array $canonical, array $rows, ?string $place): array
    {
        $crossBorder = $place !== null && $place !== 'domestic';
        $out = ['rows' => [], 'changed' => false];
        foreach ($rows as $idx => $row) {
            if (!is_array($row)
                || (string) ($row['rowKind'] ?? 'item') !== 'item'
                || isset($row['accSide'])) {
                continue;
            }
            $idx = (int) $idx;
            $ai = $row['vat']['supplyKind'] ?? null;
            $ai = is_string($ai) && trim($ai) !== '' ? trim($ai) : null;
            $tag = RowEnrichmentPipeline::rowContentTagOf($canonical, $idx);
            $tagSupply = $tag !== null ? $this->tagSupplyOf($tag) : null;

            $entry = ['supplyKind' => $ai, 'source' => $ai !== null ? 'ai' : null, 'tag' => $tag, 'tagSupply' => $tagSupply];
            if ($ai === null && $crossBorder
                && in_array($tagSupply, [VatCodeDerivation::TAG_SUPPLY_GOODS, VatCodeDerivation::TAG_SUPPLY_SERVICES], true)
            ) {
                $entry['supplyKind'] = $tagSupply;
                $entry['source'] = 'tag';
                $out['changed'] = true;
            }
            $out['rows'][$idx] = $entry;
        }
        return $out;
    }

    /** `crossBorderSupply` štítku z taxonomie; neznámá hodnota nebo štítek = null. */
    private function tagSupplyOf(string $tag): ?string
    {
        $value = $this->contentTags[$tag]['crossBorderSupply'] ?? null;
        return is_string($value) && in_array($value, VatCodeDerivation::TAG_SUPPLY_VALUES, true) ? $value : null;
    }

    /** Lokalizovaný `name` štítku z taxonomie (compiled config jazyka requestu), fallback klíč. */
    private function contentTagLabel(string $tag): string
    {
        $name = $this->contentTags[$tag]['name'] ?? null;
        return is_string($name) && $name !== '' ? $name : $tag;
    }

    /**
     * Datum, ke kterému se posuzuje plátcovství přijatého dokladu (#97 D2):
     * DUZP, bez něj datum vystavení, bez obou dnešek. Jen část `YYYY-MM-DD`
     * — registrace se porovnává jako datum, ne jako řetězec s časem.
     *
     * @param array<string, mixed> $canonical
     */
    private function registrationDate(array $canonical): string
    {
        foreach (['taxPointDate', 'issueDate'] as $key) {
            $value = $canonical['dates'][$key] ?? null;
            if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value, $m) === 1) {
                return $m[0];
            }
        }
        return date('Y-m-d');
    }

    /**
     * Naše registrace DPH pro přijatý doklad (D2): první aktivní podle
     * `country`, `id` — stejné pořadí jako výchozí hodnota formuláře
     * (`DocsHeadsFormBase::resolveVatRegistrationOptions()`).
     *
     * S datem se bere jen registrace k němu platná (`valid_from` /
     * `valid_to`, null = neomezeno) — plátcovství je registrace k datu
     * (docs/ds-setup.md D5, #97 D2). Null pak znamená, že zdroj k datu
     * plátcem nebyl. Bez data (import) platnost nerozhoduje.
     *
     * @param string|null $date `YYYY-MM-DD`, null = bez ohledu na platnost
     * @return array{id: int, country: string}|null
     */
    private function ownVatRegistration(?string $date): ?array
    {
        $row = $date === null
            ? $this->db->fetch(
                'SELECT [id], [country] FROM [economy_codebooks_vat_registrations]
                 WHERE [docState] IN (%i, %i, %i)
                 ORDER BY [country], [id] LIMIT 1',
                self::ACTIVE_STATES[0], self::ACTIVE_STATES[1], self::ACTIVE_STATES[2],
            )
            : $this->db->fetch(
                'SELECT [id], [country] FROM [economy_codebooks_vat_registrations]
                 WHERE [docState] IN (%i, %i, %i)
                   AND ([valid_from] IS NULL OR [valid_from] <= %s)
                   AND ([valid_to] IS NULL OR [valid_to] >= %s)
                 ORDER BY [country], [id] LIMIT 1',
                self::ACTIVE_STATES[0], self::ACTIVE_STATES[1], self::ACTIVE_STATES[2],
                $date, $date,
            );
        if ($row === null || !isset($row['id']) || !isset($row['country'])) {
            return null;
        }
        $country = strtolower(trim((string) $row['country']));
        if ($country === '') {
            return null;
        }
        return ['id' => (int) $row['id'], 'country' => $country];
    }

    /**
     * Rozhodnutí o kódu DPH jednoho řádku (tabulka chování v tasku):
     *
     *   prázdný kód + derivace          → odvozený kód, bez issue
     *   platný kód v souladu se signály → beze změny (dnešní chování)
     *   neznámý / v rozporu + derivace  → odvozený kód, warning vat_code_derived
     *   neznámý / prázdný bez derivace  → error vat_code_unknown s důvodem
     *   prázdný kód bez signálů         → nic (jako dnes)
     *   volba uživatele (useCode, #87 B) → zvolený kód, matchedBy user; mimo
     *                                      nabídku error vat_code_pin_invalid,
     *                                      v rozporu se signály warning
     *                                      vat_code_pin_conflict (volba platí)
     *
     * Kód z historie řádků (RowHistoryEnricher) sem přijde jako vyplněný —
     * proto kontrola souladu, jinak by stará chybná historie přebila derivaci.
     * Kontrola souladu dostane jen druh plnění z canonicalu, ne fallback ze
     * štítku — kód potvrzený člověkem štítek nezpochybňuje.
     *
     * @param array<string, mixed> $rowVat canonical `rows[].vat`
     * @param array{supplyKind: ?string, source: ?string, tag: ?string, tagSupply: ?string} $kind
     *        efektivní druh plnění řádku ({@see effectiveSupplyKinds()})
     * @param string|null $pinned  kód zvolený uživatelem (`useCode:`), {@see vatPins()}
     * @param list<array{code: string, label: string, pct: float, reverseCharge: bool, reducedDeduction: bool, supplyKind: ?string}> $options
     *        nabídka kódů k efektivnímu místu a DUZP (D13) — jediná platná množina pinů
     * @return array{
     *   input: string, derived: ?string, reason: ?string,
     *   effective: ?string, matchedBy: ?string,
     *   issue: ?array{severity: string, code: string, message: string},
     *   supplyKind: ?string, supplyKindSource: ?string, tag: ?string
     * }
     */
    private function decideRowVatCode(
        string $country,
        ?string $date,
        ?string $place,
        ?bool $reverseCharge,
        array $rowVat,
        array $kind,
        ?string $pinned = null,
        array $options = [],
    ): array {
        $input = trim((string) ($rowVat['code'] ?? ''));
        $pct = isset($rowVat['pct']) && is_numeric($rowVat['pct']) ? (float) $rowVat['pct'] : null;
        $supplyKind = $kind['supplyKind'];
        $reverseChargeCode = isset($rowVat['reverseChargeCode']) && (string) $rowVat['reverseChargeCode'] !== ''
            ? (string) $rowVat['reverseChargeCode']
            : null;
        $hasSignals = $pct !== null || $supplyKind !== null || $reverseChargeCode !== null
            || $place !== null || $reverseCharge !== null;

        $derived = $date !== null
            ? $this->vatCodeDerivation->derive(
                $country,
                $date,
                $place,
                $reverseCharge,
                $pct,
                $supplyKind,
                $reverseChargeCode,
                $kind['tagSupply'],
            )
            : ['code' => null, 'reason' => 'doklad nemá DUZP ani datum vystavení'];

        $out = [
            'input'            => $input,
            'derived'          => $derived['code'],
            'reason'           => $derived['reason'],
            'effective'        => null,
            'matchedBy'        => null,
            'issue'            => null,
            'supplyKind'       => $supplyKind,
            'supplyKindSource' => $kind['source'],
            'tag'              => $kind['tag'],
        ];
        $useDerived = static function (array $out, string $code, ?array $issue): array {
            $out['effective'] = $code;
            $out['matchedBy'] = 'derived';
            $out['issue'] = $issue;
            return $out;
        };

        // #87 task B (D10–D12): volba uživatele má nejvyšší prioritu. Platí
        // jen kód z nabídky (táž množina, kterou dostal klient — D13);
        // rozpor se signály dokladu volbu neruší, jen se ohlásí. Derivace
        // se spočítala i tak — odvozený kód patří do zprávy konfliktu.
        if ($pinned !== null) {
            if (!in_array($pinned, array_column($options, 'code'), true)) {
                $out['issue'] = [
                    'severity' => 'error',
                    'code'     => 'vat_code_pin_invalid',
                    'message'  => "Zvolený kód DPH „{$pinned}“ není v nabídce pro toto místo plnění a datum — vyber jiný.",
                ];
                return $out;
            }
            $out['effective'] = $pinned;
            $out['matchedBy'] = 'user';
            $conflict = $date !== null
                ? $this->vatCodeDerivation->conflict(
                    $country,
                    $pinned,
                    $date,
                    $place,
                    $reverseCharge,
                    $pct,
                    $kind['source'] === 'ai' ? $supplyKind : null,
                )
                : null;
            if ($conflict !== null) {
                $hint = $derived['code'] !== null && $derived['code'] !== $pinned
                    ? "; odvozený by byl „{$derived['code']}“"
                    : '';
                $out['issue'] = [
                    'severity' => 'warning',
                    'code'     => 'vat_code_pin_conflict',
                    'message'  => "Zvolený kód DPH „{$pinned}“ neodpovídá dokladu ({$conflict}{$hint}) — platí volba.",
                ];
            }
            return $out;
        }

        if ($input === '') {
            if ($derived['code'] !== null) {
                return $useDerived($out, $derived['code'], null);
            }
            if (!$hasSignals) {
                return $out;
            }
            $out['issue'] = [
                'severity' => 'error',
                'code'     => 'vat_code_unknown',
                'message'  => "Kód DPH řádku nejde odvodit ({$derived['reason']}) — doklad založ ručně.",
            ];
            return $out;
        }

        $known = $this->vatCodeResolver->resolve($input, $country, $date, $pct)->status === ResolveStatus::Matched;
        if ($known) {
            $conflict = $date !== null
                ? $this->vatCodeDerivation->conflict(
                    $country,
                    $input,
                    $date,
                    $place,
                    $reverseCharge,
                    $pct,
                    $kind['source'] === 'ai' ? $supplyKind : null,
                )
                : null;
            if ($conflict === null || $derived['code'] === $input) {
                $out['effective'] = $input;
                $out['matchedBy'] = 'input';
                return $out;
            }
            if ($derived['code'] !== null) {
                return $useDerived($out, $derived['code'], [
                    'severity' => 'warning',
                    'code'     => 'vat_code_derived',
                    'message'  => "Kód DPH „{$input}“ neodpovídá dokladu ({$conflict}) — nahrazen odvozeným „{$derived['code']}“.",
                ]);
            }
            $out['issue'] = [
                'severity' => 'error',
                'code'     => 'vat_code_unknown',
                'message'  => "Kód DPH „{$input}“ neodpovídá dokladu ({$conflict}) a jiný nejde odvodit"
                    . " ({$derived['reason']}) — doklad založ ručně.",
            ];
            return $out;
        }

        if ($derived['code'] !== null) {
            return $useDerived($out, $derived['code'], [
                'severity' => 'warning',
                'code'     => 'vat_code_derived',
                'message'  => "Neznámý kód DPH „{$input}“ — nahrazen odvozeným „{$derived['code']}“.",
            ]);
        }
        $out['issue'] = [
            'severity' => 'error',
            'code'     => 'vat_code_unknown',
            'message'  => "Neznámý kód DPH „{$input}“; kód nejde odvodit ({$derived['reason']}) — doklad založ ručně.",
        ];
        return $out;
    }

    /**
     * `_resolve.rows[].vatCode` jednoho řádku. Null = řádek bez kódu, u
     * kterého není z čeho odvozovat → žádný blok (jako dnes).
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $vatCtx {@see vatContext()}
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     * @return array<string, mixed>|null
     */
    private function resolveRowVatCode(
        int $idx,
        array $row,
        array $vatCtx,
        string $vatCountry,
        string $supplierCountry,
        array &$issues,
    ): ?array {
        $rowVat = is_array($row['vat'] ?? null) ? $row['vat'] : [];
        $pct = isset($rowVat['pct']) && is_numeric($rowVat['pct']) ? (float) $rowVat['pct'] : null;
        $date = $vatCtx['taxPointDate'];
        $decision = $vatCtx['rows'][$idx] ?? null;

        if ($decision === null) {
            // Dnešní cesta: vystavené a účetní doklady, zdroj bez registrace
            // DPH, řádek bez objektu vat. Volba kódu tu neplatí (D9, #87 B).
            if (isset($vatCtx['pins']['rows'][$idx])) {
                $issues[] = [
                    'severity' => 'info',
                    'path'     => "rows.{$idx}.vat.code",
                    'code'     => 'vat_pin_ignored',
                    'message'  => 'Volba kódu DPH platí jen u přijatého dokladu na zdroji s registrací DPH — ignorována.',
                ];
            }
            if ($vatCtx['nonPayer']) {
                // #97: na doklad Bez DPH se kód nepropisuje, proto se ani
                // neresolvuje — neznámý kód z historie řádků by jinak
                // zablokoval apply kvůli hodnotě, která se zahodí.
                return null;
            }
            $code = trim((string) ($rowVat['code'] ?? ''));
            if ($code === '') {
                return null;
            }
            $country = $this->vatCountryForCode($code, $vatCountry, $supplierCountry);
            $vatR = $this->vatCodeResolver->resolve($code, $country !== '' ? $country : null, $date, $pct);
            if ($vatR->status === ResolveStatus::NotFound) {
                $issues[] = [
                    'severity' => 'error',
                    'path'     => "rows.{$idx}.vat.code",
                    'code'     => 'vat_code_unknown',
                    'message'  => "Neznámý kód DPH „{$code}\".",
                ];
            }
            return $vatR->toArray();
        }

        // D2: druh plnění doplněný ze štítku — hlásí se, jen když na něm
        // výsledek stojí (odvozený kód, nebo derivace selhala); ponechaný
        // kód z canonicalu / historie fallback nepotřeboval → bez šumu.
        $fromTag = $decision['supplyKindSource'] === 'tag'
            && !in_array($decision['matchedBy'], ['input', 'user'], true);
        if ($fromTag) {
            $issues[] = [
                'severity' => 'warning',
                'path'     => "rows.{$idx}.vat.supplyKind",
                'code'     => 'supply_kind_derived',
                'message'  => sprintf(
                    'Druh plnění řádku doplněn podle štítku „%s“ (%s).',
                    $this->contentTagLabel((string) $decision['tag']),
                    $decision['supplyKind'] === VatCodeDerivation::TAG_SUPPLY_GOODS ? 'zboží' : 'služby',
                ),
            ];
        }
        if ($decision['issue'] !== null) {
            $issues[] = [
                'severity' => $decision['issue']['severity'],
                'path'     => "rows.{$idx}.vat.code",
                'code'     => $decision['issue']['code'],
                'message'  => $decision['issue']['message'],
            ];
        }
        if ($decision['effective'] === null) {
            if ($decision['issue'] === null) {
                return null;
            }
            $notFound = ResolveResult::notFound()->toArray();
            if ($fromTag) {
                $notFound['supplyKindSource'] = 'tag';
            }
            return $notFound;
        }
        // Odvozený i zvolený kód dostane sazbu z číselníku k DUZP (D4; #87 B
        // D10) — `pct` z dokladu dodavatele (u samovyměření 0) se u nich
        // nepoužije ani jako fallback.
        $fromCodebook = in_array($decision['matchedBy'], ['derived', 'user'], true);
        $resolved = $this->vatCodeResolver
            ->resolve($decision['effective'], $vatCountry, $date, $fromCodebook ? null : $pct)
            ->toArray();
        if ($fromCodebook) {
            $resolved['matchedBy'] = $decision['matchedBy'];
            if ($fromTag) {
                $resolved['supplyKindSource'] = 'tag';
            }
        }
        return $resolved;
    }

    /**
     * Kód, který na položkovém řádku skončí: rozhodnutí z kontextu (odvozený
     * nebo ponechaný), mimo derivaci kód z canonicalu. Prázdný = žádný.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $vatCtx {@see vatContext()}
     */
    private function effectiveRowVatCode(int $idx, array $row, array $vatCtx): string
    {
        if (isset($vatCtx['rows'][$idx])) {
            return (string) ($vatCtx['rows'][$idx]['effective'] ?? '');
        }
        return trim((string) ($row['vat']['code'] ?? ''));
    }

    /**
     * D3: má některý položkový řádek (po derivaci) kód s `reverseVatCode`?
     * Jen u přijatého dokladu na zdroji s registrací DPH — jinde se
     * rekapitulace řídí dnešními pravidly.
     *
     * @param array<string, mixed> $vatCtx {@see vatContext()}
     */
    private function rowsCarryReverseCharge(array $vatCtx): bool
    {
        if (!$vatCtx['derive']) {
            return false;
        }
        foreach ($vatCtx['rows'] as $decision) {
            if ($decision['effective'] === null) {
                continue;
            }
            $codeR = $this->vatCodeResolver->resolve(
                $decision['effective'],
                (string) $vatCtx['ownCountry'],
                $vatCtx['taxPointDate'],
                null,
            );
            if ($codeR->status === ResolveStatus::Matched && !empty($codeR->createPayload['reverseVatCode'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Hlavičkové DPH issues, které transform() hlásit nemůže — běží až
     * v transakci apply po sestavení `_resolve` a preview ho nevolá:
     *
     * - D5: neznámé `vat.mode` / `vat.place` → warning místo tichého
     *   fallbacku (transform dál padá na fromBase / tuzemsko). Schéma má
     *   obě pole jako enum, takže sem takový payload dojde jen mimo
     *   schema validaci — pojistka, ne hlavní cesta.
     * - `vat.place` přijatého dokladu odvozené z DIČ dodavatele
     *   (tasks/exchange-received-vat-place.md D1) v rozporu s neprázdnou
     *   hodnotou z AI → warning `vat_place_derived`; neznámou hodnotu
     *   derivace nahradila, `vat_place_unknown` se pak nehlásí.
     * - D2: `vat.registrationCountry` z AI / ISDOC v rozporu s naší
     *   registrací → info, hodnota se nepoužije.
     *
     * @param array<string, mixed> $canonical
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    private function appendVatHeaderIssues(array $canonical, array &$issues): void
    {
        $vat = is_array($canonical['vat'] ?? null) ? $canonical['vat'] : [];
        $vatCtx = $this->vatContext($canonical);

        // Volby DPH (#87 B): neplatná hodnota = error (D11, všechny cesty);
        // mimo větev derive se volby ignorují s info issue (D9) — hlavičkové
        // tady, řádkové hlásí resolveRowVatCode().
        foreach ($vatCtx['pins']['invalid'] as $invalid) {
            $issues[] = $invalid;
        }
        if (!$vatCtx['derive']) {
            foreach (['place' => 'místa plnění', 'mode' => 'režimu DPH'] as $field => $label) {
                if ($vatCtx['pins'][$field] !== null) {
                    $issues[] = [
                        'severity' => 'info',
                        'path'     => "vat.{$field}",
                        'code'     => 'vat_pin_ignored',
                        'message'  => "Volba {$label} platí jen u přijatého dokladu na zdroji s registrací DPH — ignorována.",
                    ];
                }
            }
        }

        $mode = $vat['mode'] ?? null;
        if ($mode !== null && (!is_string($mode) || !isset(self::VAT_MODE_MAP[$mode]))) {
            $label = is_scalar($mode) ? (string) $mode : (string) json_encode($mode);
            $issues[] = [
                'severity' => 'warning',
                'path'     => 'vat.mode',
                'code'     => 'vat_mode_unknown',
                'message'  => "Neznámý režim výpočtu DPH „{$label}“ — použije se výpočet zdola (fromBase).",
            ];
        }
        $place = $vat['place'] ?? null;
        if ($vatCtx['placeSource'] === 'vatId') {
            if ($place !== null && $place !== $vatCtx['place']) {
                $label = is_scalar($place) ? (string) $place : (string) json_encode($place);
                $issues[] = [
                    'severity' => 'warning',
                    'path'     => 'vat.place',
                    'code'     => 'vat_place_derived',
                    'message'  => sprintf(
                        'Místo plnění „%s“ z návrhu nahrazeno „%s“ podle DIČ dodavatele (prefix %s).',
                        $this->vatPlaceLabel($label),
                        $this->vatPlaceLabel((string) $vatCtx['place']),
                        (string) $vatCtx['placePrefix'],
                    ),
                ];
            }
        } elseif ($vatCtx['placeSource'] !== 'user'
            && $place !== null && (!is_string($place) || !isset(self::VAT_PLACE_MAP[$place]))) {
            // Zvolené místo (D10) neznámou hodnotu z AI přebíjí — hláška
            // „použije se tuzemsko“ by lhala.
            $label = is_scalar($place) ? (string) $place : (string) json_encode($place);
            $issues[] = [
                'severity' => 'warning',
                'path'     => 'vat.place',
                'code'     => 'vat_place_unknown',
                'message'  => "Neznámé místo plnění „{$label}“ — použije se tuzemsko.",
            ];
        }

        $declared = strtolower(trim((string) ($vat['registrationCountry'] ?? '')));
        if ($vatCtx['derive'] && $declared !== '' && $declared !== $vatCtx['ownCountry']) {
            $issues[] = [
                'severity' => 'info',
                'path'     => 'vat.registrationCountry',
                'code'     => 'vat_registration_country_derived',
                'message'  => sprintf(
                    'Země registrace DPH „%s“ z dokladu se u přijatého dokladu nepoužije'
                        . ' — kódy DPH i registrace jdou podle naší registrace (%s).',
                    strtoupper($declared),
                    strtoupper((string) $vatCtx['ownCountry']),
                ),
            ];
        }
    }

    /**
     * Název místa plnění pro zprávu issue z cfgItem `docs.core.vatPlaces`
     * (`name:cs` v surové, `name` v kompilované konfiguraci — zprávy
     * applieru jsou česky). Neznámý klíč nebo chybějící cfgItem → klíč,
     * jak přišel.
     */
    private function vatPlaceLabel(string $canonicalPlace): string
    {
        $idx = self::VAT_PLACE_MAP[$canonicalPlace] ?? null;
        if ($idx === null) {
            return $canonicalPlace;
        }
        $places = $this->config->cfgItem('docs.core.vatPlaces');
        $def = is_array($places) ? ($places[(string) $idx] ?? null) : null;
        if (!is_array($def)) {
            return $canonicalPlace;
        }
        $name = $def['name:cs'] ?? $def['name'] ?? null;
        return is_string($name) && $name !== '' ? $name : $canonicalPlace;
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
     * Přijatý doklad neplátce DPH (#97 D8) rekapitulaci nemá nikdy — ani
     * při explicitním `declared`. Daň dodavatele je součástí ceny řádků,
     * rekapitulace zůstává jen v canonicalu analýzy; bez důvodu fallbacku,
     * info o tom nese `vat_non_payer`.
     *
     * „Dohledatelný kód" (I7) = existuje v číselníku země registrace, ověřuje
     * se stejným `VatCodeResolver` a stejnou kaskádou země jako kódy řádků.
     * Kód, který resolver nezná, by `DocDocument::takeOverVatRecapitulation`
     * odmítl `DomainException` a apply by skončil 500 — místo toho se
     * rekapitulace přepočítá z řádků a uživatel dostane info issue s důvodem.
     *
     * Vynechaný řádek (#111 D12): rekapitulace dodavatele je za všechny
     * řádky, doklad jich má míň — přepočet i při explicitním `declared`,
     * důvod „vynechaný řádek“. Jinak by součty dokladu zůstaly za celou
     * fakturu a náhled by vynechání neukázal.
     *
     * @param array<string, mixed> $canonical
     * @param list<int> $rowSkips Vynechané řádky ({@see skippedRowIndices}).
     * @return array{source: int, recap: array<int, array<string, mixed>>, fallback: ?string}
     */
    private function resolveRecapSource(array $canonical, array $rowSkips = []): array
    {
        $computed = ['source' => 0, 'recap' => [], 'fallback' => null];
        if ($this->vatContext($canonical)['nonPayer']) {
            return $computed;
        }
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
        // D12 (#111): vynechaný řádek — až tady, kde by se jinak převzala,
        // aby důvod odpovídal skutečnosti (prázdná rekapitulace a vystavený
        // doklad mají svoje).
        if ($rowSkips !== []) {
            return ['source' => 0, 'recap' => [], 'fallback' => 'vynechaný řádek'];
        }

        $vatCtx = $this->vatContext($canonical);
        // D3 (exchange-received-reverse-charge): samovyměření — rekapitulace
        // dodavatele je z jeho pohledu (0 %, daň 0), naše nese nárok na
        // odpočet + oddaňovací pár. Bez explicitního declared vždy přepočet.
        if (!$explicit && $this->rowsCarryReverseCharge($vatCtx)) {
            return [
                'source'   => 0,
                'recap'    => [],
                'fallback' => 'přenesení daňové povinnosti — rekapitulace dodavatele se nepřebírá',
            ];
        }
        $codesByPct = $this->recapCodesFromRows($canonical, $vatCtx);
        $vatCountry = $vatCtx['derive']
            ? (string) $vatCtx['ownCountry']
            : strtolower((string) ($canonical['vat']['registrationCountry'] ?? ''));
        $supplierCountry = strtolower((string) ($canonical['supplier']['country'] ?? ''));
        $taxPointDate = $vatCtx['taxPointDate'];
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
     * kódem (I7). Slouží rekapitulaci bez kódů (ISDOC, AI od promptu
     * v4.6.0). Bere kód, který na řádku skončí — odvozený má přednost před
     * canonicalem, jinak by domácí faktury bez kódů přestaly rekapitulaci
     * přebírat (#75).
     *
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $vatCtx {@see vatContext()}
     * @return array<string, string>
     */
    private function recapCodesFromRows(array $canonical, array $vatCtx): array
    {
        $byPct = [];
        foreach ((array) ($canonical['rows'] ?? []) as $idx => $row) {
            if (!is_array($row) || (string) ($row['rowKind'] ?? 'item') !== 'item') {
                continue;
            }
            $code = $this->effectiveRowVatCode((int) $idx, $row, $vatCtx);
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
     * @param list<int> $rowSkips Vynechané řádky ({@see skippedRowIndices}, D12).
     */
    private function appendRecapSourceIssue(array $canonical, array &$issues, array $rowSkips = []): void
    {
        $resolved = $this->resolveRecapSource($canonical, $rowSkips);
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
     * `applyOptions.author` (#93 D9): přítomný klíč určuje autora dokladu
     * („Vystavil“), `null` = bez autora. Hodnota musí být id existujícího
     * uživatele — aktivního i neaktivního (import historie nese autory,
     * kteří se už nepřihlašují). Chybějící klíč se nekontroluje: autora
     * doplní `DocAuthorResolver` při uložení.
     *
     * @param array<string, mixed> $canonical
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    private function appendAuthorIssue(array $canonical, array &$issues): void
    {
        $options = $canonical['applyOptions'] ?? null;
        if (!is_array($options) || ($options['author'] ?? null) === null) {
            return;
        }
        $author = $options['author'];
        $row = is_int($author) && $author > 0
            ? $this->db->fetch('SELECT [id] FROM [core_system_users] WHERE [id] = %i', $author)
            : null;
        if ($row === null || $row === false) {
            $issues[] = [
                'severity' => 'error',
                'path'     => 'applyOptions.author',
                'code'     => 'author_not_found',
                'message'  => 'Autor dokladu (applyOptions.author) není id existujícího uživatele.',
            ];
        }
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
        $vatCtx = $this->vatContext($canonical);
        if ($vatCtx['nonPayer']) {
            $this->appendNonPayerIssues($canonical, $issues);
            return;
        }
        $effective = $this->effectiveVatMode($canonical, $vatCtx);
        if ($effective['pinned']) {
            // D10/D11 (#87 B): zvolený režim se nehlásí jako odvozený (ani
            // tiché přepnutí none → fromBase u samovyměření) a podezření
            // validátoru — odhad bez znalosti volby — by jen mátlo.
            $issues = array_values(array_filter(
                $issues,
                static fn (array $issue): bool => ($issue['code'] ?? null) !== 'vat_mode_suspect',
            ));
            return;
        }
        if ($effective['source'] !== 'derived') {
            return;
        }
        $issues[] = [
            'severity' => 'warning',
            'path'     => 'vat.mode',
            'code'     => 'vat_mode_derived',
            'message'  => match ($effective['reason']) {
                'reverseCharge' => 'Doklad uvádí režim bez DPH, ale řádky jsou v přenesení daňové povinnosti'
                    . ' — režim výpočtu odvozen zdola (fromBase).',
                'rowsWithVat'   => 'Řádky jsou v cenách s DPH — režim výpočtu odvozen shora (fromTotal).',
                default         => 'Řádky jsou v cenách bez DPH — režim výpočtu odvozen zdola (fromBase).',
            },
        ];
    }

    /**
     * Issues přijatého dokladu neplátce DPH (#97 D7, D9) — místo hlášek
     * o režimu a rekapitulaci, které jsou u něj dané a jen by mátly
     * (`vat_mode_derived`, podezření validátoru `vat_mode_suspect`,
     * `recap_source_computed_fallback`):
     *
     * - info `vat_non_payer`, když doklad nese daň dodavatele — vysvětlí,
     *   proč jsou ceny řádků jiné než na předloze. Doklad od neplátce nic
     *   nehlásí, nic se na něm nemění.
     * - warning `non_payer_reverse_charge`, když doklad nese přenesení
     *   daňové povinnosti: neplátce, který je identifikovanou osobou, daň
     *   přiznat musí, applier ji ale nevyměří (D9, mimo rozsah).
     *
     * @param array<string, mixed> $canonical
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    private function appendNonPayerIssues(array $canonical, array &$issues): void
    {
        $issues = array_values(array_filter(
            $issues,
            static fn (array $issue): bool => ($issue['code'] ?? null) !== 'vat_mode_suspect',
        ));
        if ($this->carriesSupplierVat($canonical)) {
            $issues[] = [
                'severity' => 'info',
                'path'     => 'vat.mode',
                'code'     => 'vat_non_payer',
                'message'  => 'K datu dokladu neplatí žádná registrace DPH (neplátce) — doklad vznikne bez DPH'
                    . ' a daň dodavatele je součástí cen řádků.',
            ];
        }

        $reverseCharge = ($canonical['vat']['reverseCharge'] ?? null) === true;
        foreach ((array) ($canonical['rows'] ?? []) as $row) {
            if (is_array($row) && trim((string) ($row['vat']['reverseChargeCode'] ?? '')) !== '') {
                $reverseCharge = true;
                break;
            }
        }
        if ($reverseCharge) {
            $issues[] = [
                'severity' => 'warning',
                'path'     => 'vat.reverseCharge',
                'code'     => 'non_payer_reverse_charge',
                'message'  => 'Doklad je v přenesení daňové povinnosti, ale k datu dokladu neplatí žádná registrace DPH'
                    . ' — daň se na dokladu nevyměří. Identifikovaná osoba ji musí přiznat mimo tento doklad.',
            ];
        }
    }

    /**
     * Režim výpočtu, jak ho říká doklad: deklarovaný `vat.mode` (default
     * fromBase), u jiného než „Bez DPH“ ověřený proti číslům
     * ({@see VatModeDerivation}). Říká, **v jakých cenách jsou řádky** —
     * čte ho {@see effectiveVatMode()} i převod cen neplátce
     * ({@see nonPayerGrossTotals()}), který výsledný režim dokladu mění.
     *
     * @param array<string, mixed> $canonical
     * @return array{mode: string, source: string, reason: ?string}
     *         source ai | default | derived; reason jen u derived.
     */
    private function documentVatMode(array $canonical): array
    {
        $declared = $canonical['vat']['mode'] ?? null;
        $known = is_string($declared) && isset(self::VAT_MODE_MAP[$declared]);
        $mode = $known ? $declared : 'fromBase';
        $source = $known ? 'ai' : 'default';
        $reason = null;
        $derived = $mode !== 'none' ? VatModeDerivation::derive($canonical) : null;
        if ($derived !== null && $derived !== self::VAT_MODE_MAP[$mode]) {
            $mode = $derived === 2 ? 'fromTotal' : 'fromBase';
            $source = 'derived';
            $reason = $derived === 2 ? 'rowsWithVat' : 'rowsWithoutVat';
        }
        return ['mode' => $mode, 'source' => $source, 'reason' => $reason];
    }

    /**
     * Efektivní režim výpočtu DPH dokladu — jediné místo pro transform(),
     * appendVatModeIssue() i blok `_resolve.vat.mode` (D14), aby se zrcadla
     * nerozešla. Priorita: neplátce DPH (#97 D3) → volba uživatele (jen ve
     * větvi derive, D9/D10) → VatModeDerivation (kromě „Bez DPH“) →
     * canonical → default fromBase.
     * „Bez DPH“ se samovyměřením se vždy přepne na fromBase — DocDocument
     * by při vat_mode 0 rekapitulaci nestavěl a nárok i oddanění by se
     * ztratily (D1 z #86); proti volbě tiše (D11).
     *
     * Přijatý doklad neplátce je vždy „Bez DPH“ (`source: "nonPayer"`),
     * před derivací i ochranou samovyměření — ta stojí na registraci
     * a neplátce by s `vat_mode` 1 padl na povinné registraci DPH.
     *
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $vatCtx {@see vatContext()}
     * @return array{value: string, source: string, reason: ?string, pinned: bool, auto: string}
     *         value klíč VAT_MODE_MAP; source user | derived | ai | default | nonPayer;
     *         reason jen u derived (reverseCharge | rowsWithVat | rowsWithoutVat);
     *         auto = hodnota bez volby uživatele (náhled: „Automaticky (…)“).
     */
    private function effectiveVatMode(array $canonical, array $vatCtx): array
    {
        if ($vatCtx['nonPayer']) {
            return ['value' => 'none', 'source' => 'nonPayer', 'reason' => null, 'pinned' => false, 'auto' => 'none'];
        }
        $pin = $vatCtx['derive'] ? $vatCtx['pins']['mode'] : null;

        // Automatická hodnota se počítá vždy — i při volbě ji náhled ukazuje.
        ['mode' => $auto, 'source' => $autoSource, 'reason' => $reason] = $this->documentVatMode($canonical);
        if ($auto === 'none' && $this->rowsCarryReverseCharge($vatCtx)) {
            $auto = 'fromBase';
            $autoSource = 'derived';
            $reason = 'reverseCharge';
        }
        if ($pin === null) {
            return ['value' => $auto, 'source' => $autoSource, 'reason' => $reason, 'pinned' => false, 'auto' => $auto];
        }

        $value = $pin;
        $source = 'user';
        if ($value === 'none' && $this->rowsCarryReverseCharge($vatCtx)) {
            $value = 'fromBase';
            $source = 'derived';
        }
        return ['value' => $value, 'source' => $source, 'reason' => null, 'pinned' => true, 'auto' => $auto];
    }

    // ── Přijatý doklad neplátce DPH: daň dodavatele v cenách řádků (#97) ────

    /**
     * Nese doklad daň dodavatele? Některý položkový řádek má kladnou sazbu
     * nebo nenulovou daň, případně ji nese rekapitulace. Jediný predikát
     * pro převod cen ({@see nonPayerGrossTotals()}) i info `vat_non_payer`
     * — doklad od neplátce (bez daně) zůstává, jak přišel, a nic se nehlásí.
     *
     * @param array<string, mixed> $canonical
     */
    private function carriesSupplierVat(array $canonical): bool
    {
        $nonZero = static fn (mixed $v): bool => $v !== null && is_numeric($v) && abs((float) $v) >= 0.005;
        $positive = static fn (mixed $v): bool => $v !== null && is_numeric($v) && (float) $v > 0.0;

        foreach ((array) ($canonical['rows'] ?? []) as $row) {
            if (!is_array($row)
                || (string) ($row['rowKind'] ?? 'item') !== 'item'
                || isset($row['accSide'])) {
                continue;
            }
            if ($positive($row['vat']['pct'] ?? null) || $nonZero($row['computed']['vatAmount'] ?? null)) {
                return true;
            }
        }
        foreach ((array) ($canonical['vatRecap'] ?? []) as $entry) {
            if (!is_array($entry) || ($entry['isReversePair'] ?? null) === true) {
                continue;
            }
            if ($positive($entry['vatPct'] ?? null) || $nonZero($entry['tax'] ?? null)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Ceny položkových řádků přijatého dokladu neplátce DPH **včetně daně
     * dodavatele** (#97 D4, D5): index canonicalu → cena, která skončí na
     * řádku jako `total_price` (`price_calc_mode` 1, bez slev — cena je už
     * obsahuje). Daň je pro neplátce součástí ceny pořízení: musí skončit
     * v nákladech a v závazku, ne zmizet. Jediné místo výpočtu — čte ho
     * `transform()` při apply i v náhledu.
     *
     * Prázdné pole = nic se nepřevádí: zdroj je plátce, nebo doklad daň
     * dodavatele nenese ({@see carriesSupplierVat()}). Jinak jdou do
     * výsledku **všechny** položkové řádky (ne kontační — ty účtují částku
     * přímo), aby součet řádků dokladu byl přesně součet vrácených cen.
     *
     * Cena řádku (D4), první dostupné:
     *  1. `computed.vatTotal` od dodavatele (ISDOC, případně AI),
     *  2. řádky už v cenách s daní ({@see documentVatMode()} fromTotal)
     *     → cena řádku beze změny,
     *  3. cena řádku × (1 + sazba / 100), na 2 místa; řádek bez sazby
     *     nebo s 0 % beze změny.
     *
     * Dorovnání (D5): při úplné rekapitulaci
     * ({@see VatModeDerivation::recapIsComplete()}) se řádky každé sazby
     * srovnají na `vatRecap[].total` té sazby — rozdíl do tolerance
     * zaokrouhlení jde na řádek s největší absolutní částkou. Doklad pak
     * sedí na částku k úhradě i v haléřích. Větší rozdíl se nedorovnává
     * (řádky jsou neúplné; náhled ho ukáže jako `computed_total_mismatch`).
     *
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $vatCtx {@see vatContext()}
     * @return array<int, float>
     */
    private function nonPayerGrossTotals(array $canonical, array $vatCtx): array
    {
        if (!$vatCtx['nonPayer'] || !$this->carriesSupplierVat($canonical)) {
            return [];
        }
        $cfgOps = $this->config->cfgItem('docs.core.rowOperations');
        $cfgOps = is_array($cfgOps) ? $cfgOps : [];
        $pricesWithVat = $this->documentVatMode($canonical)['mode'] === 'fromTotal';

        $gross = [];
        $rateOf = [];
        foreach ((array) ($canonical['rows'] ?? []) as $idx => $row) {
            if (!is_array($row)
                || (string) ($row['rowKind'] ?? 'item') !== 'item'
                || isset($row['accSide'])
                || isset($cfgOps[(string) ($row['operation'] ?? '')]['rowSide'])) {
                continue;
            }
            $pct = isset($row['vat']['pct']) && is_numeric($row['vat']['pct']) ? (float) $row['vat']['pct'] : 0.0;
            $vatTotal = $row['computed']['vatTotal'] ?? null;
            if ($vatTotal !== null && is_numeric($vatTotal)) {
                $value = round((float) $vatTotal, 2);
            } else {
                $net = $this->rowNetTotal($row);
                if ($net === null) {
                    continue;
                }
                $value = $pricesWithVat || $pct <= 0.0 ? $net : round($net * (1.0 + $pct / 100.0), 2);
            }
            $gross[(int) $idx] = $value;
            $rateOf[(int) $idx] = self::rateKey($pct);
        }

        $recap = $canonical['vatRecap'] ?? null;
        if (!VatModeDerivation::recapIsComplete($recap)) {
            return $gross;
        }
        $recapByRate = [];
        foreach ($recap as $entry) {
            if (($entry['isReversePair'] ?? null) === true) {
                continue;
            }
            $key = self::rateKey($entry['vatPct'] ?? null);
            $recapByRate[$key] = ($recapByRate[$key] ?? 0.0) + (float) $entry['total'];
        }
        foreach ($recapByRate as $key => $recapTotal) {
            $indices = array_keys($rateOf, $key, true);
            if ($indices === []) {
                continue;
            }
            $sum = 0.0;
            $target = $indices[0];
            foreach ($indices as $i) {
                $sum += $gross[$i];
                if (abs($gross[$i]) > abs($gross[$target])) {
                    $target = $i;
                }
            }
            $diff = round($recapTotal - $sum, 2);
            if (abs($diff) < 0.005 || abs($diff) > VatModeDerivation::tolerance(count($indices)) + 1e-9) {
                continue;
            }
            $gross[$target] = round($gross[$target] + $diff, 2);
        }
        return $gross;
    }

    /**
     * Cena položkového řádku canonicalu po slevě, bez přičtení daně — vstup
     * převodu cen neplátce ({@see nonPayerGrossTotals()}).
     *
     * Bez slevy je to `totalPrice` (částka řádku z dokladu dodavatele);
     * chybí-li, množství × jednotková cena — jinak by se řádek nepřevedl
     * a daň by se u něj ztratila dál.
     *
     * Se slevou rozhoduje `priceCalcMode`, stejně jako u dokladu plátce
     * ({@see DocRowCalculator::computePrice()}): z ceny za jednotku se
     * sleva odečítá od množství × jednotkové ceny — `totalPrice` z dokladu
     * dodavatele slevu zpravidla už obsahuje a odečetla by se podruhé.
     *
     * @param array<string, mixed> $row
     */
    private function rowNetTotal(array $row): ?float
    {
        $num = static fn (mixed $v): ?float => $v !== null && is_numeric($v) ? (float) $v : null;
        $quantity = $num($row['quantity'] ?? null);
        $unitPrice = $num($row['unitPrice'] ?? null);
        $total = $num($row['totalPrice'] ?? null);
        $fromUnit = $quantity !== null && $unitPrice !== null ? round($quantity * $unitPrice, 2) : null;

        $discounted = !empty($row['discountPct']) || !empty($row['discountAmount']);
        $unitMode = (self::PRICE_CALC_MODE_MAP[(string) ($row['priceCalcMode'] ?? 'fromUnitPrice')] ?? 0) === 0;
        $base = $discounted && $unitMode ? ($fromUnit ?? $total) : ($total ?? $fromUnit);
        if ($base === null) {
            return null;
        }
        if (!$discounted) {
            return round($base, 2);
        }
        return DocRowCalculator::computePrice([
            'row_kind'        => 1,
            'price_calc_mode' => 1,
            'quantity'        => $quantity,
            'total_price'     => $base,
            'discount_pct'    => $row['discountPct'] ?? null,
            'discount_amount' => $row['discountAmount'] ?? null,
        ])['net_total'];
    }

    /** Sazba jako klíč skupiny — `21`, `21.0` i `"21"` jsou tatáž sazba. */
    private static function rateKey(mixed $pct): string
    {
        return number_format($pct !== null && is_numeric($pct) ? (float) $pct : 0.0, 2, '.', '');
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
     * U přijatého dokladu neplátce DPH (#97) je computed součet cen řádků
     * s daní, které na doklad skutečně jdou (`$rowsTotal`) — odhad z
     * canonicalu by u řádků v cenách s daní přičetl sazbu podruhé.
     *
     * @param array<string, mixed> $canonical
     * @param float|null $rowsTotal Σ {@see nonPayerGrossTotals()}; null = odvodit z canonicalu
     */
    private function deriveTotalRoundingMode(array $canonical, ?float $rowsTotal = null): ?int
    {
        $totals = $canonical['totals'] ?? null;
        if (!is_array($totals) || !isset($totals['totalAmount']) || !is_numeric($totals['totalAmount'])) {
            return null;
        }
        $declared = round((float) $totals['totalAmount'], 2);

        $computed = $rowsTotal;

        // 1. Σ vatRecap[].total — jen když má total všechny řádky rekapitulace.
        $vatRecap = $canonical['vatRecap'] ?? null;
        if ($computed === null && is_array($vatRecap) && count($vatRecap) > 0) {
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
     * @param bool $nonPayer Přijatý doklad neplátce DPH (#97): doklad je Bez DPH,
     *        kód z historie řádků ani sazba dodavatele se na řádky nepropisují.
     * @param array<int, float> $grossTotals {@see nonPayerGrossTotals()} — řádek
     *        s cenou včetně daně jde na doklad z celkové ceny: bez jednotkové
     *        ceny bez daně (dopočítá ji DocRowCalculator) a bez slev, které
     *        cena už obsahuje.
     * @return array<int, array<string, mixed>>
     */
    private function transformRows(
        array $rows,
        array $plan,
        array $sideIds,
        bool $nonPayer = false,
        array $grossTotals = [],
    ): array {
        // Kontační operace (vlajka rowSide v docs.core.rowOperations) účtují
        // částku přímo — detekce nesmí stát jen na přítomnosti accSide:
        // operace s rowSide: 0 (FX) stranu z konstrukce nenesou.
        $cfgOps = $this->config->cfgItem('docs.core.rowOperations');
        $cfgOps = is_array($cfgOps) ? $cfgOps : [];

        $out = [];
        $orderPos = 0;
        foreach ($this->transformedRowIndices($rows, $plan) as $i) {
            $row = $rows[$i];

            $orderPos++;
            $contation = isset($row['accSide'])
                || isset($cfgOps[(string) ($row['operation'] ?? '')]['rowSide']);
            $itemId = $sideIds['rowItems'][$i] ?? ($plan['resolvedRowItems'][$i] ?? null);
            $unitId = $plan['resolvedRowUnits'][$i] ?? null;
            $vat = $plan['resolvedRowVatCodes'][$i] ?? null;
            $vatPct = null;
            $vatCode = null;
            if (!$nonPayer && is_array($vat) && ($vat['status'] ?? null) === 'matched') {
                $vatPct = $vat['createPayload']['pct'] ?? null;
                $vatCode = $vat['createPayload']['code'] ?? null;
            }
            $gross = $grossTotals[$i] ?? null;

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
                'unit_price'      => $gross !== null ? null : ($row['unitPrice'] ?? null),
                'total_price'     => $gross ?? ($row['totalPrice'] ?? null),
                // Kontační řádek (accSide nebo operace s vlajkou rowSide —
                // FX řádky stranu nenesou) účtuje částku přímo → fromTotal,
                // jinak by calculateRowPrice přepsal total_price z qty×unit (0).
                'price_calc_mode' => $contation || $gross !== null
                                      ? 1
                                      : (self::PRICE_CALC_MODE_MAP[(string) ($row['priceCalcMode'] ?? 'fromUnitPrice')] ?? 0),
                'discount_pct'    => $gross !== null ? null : ($row['discountPct'] ?? null),
                'discount_amount' => $gross !== null ? null : ($row['discountAmount'] ?? null),
                'vat_code'        => $vatCode,
                'vat_pct'         => $vatPct,
                // Text řádku skládá výhradně CanonicalRowText (#84 D1/D2):
                // top-level description první (účetní doklad, export), jinak
                // item.name + " — " + item.description. Stejný text vidí
                // náhled (_resolve.rows[i].rowText) i poziční guard
                // SupplierCodeCaptureHandler — neskládat tady vlastní řetěz.
                'description'     => CanonicalRowText::compose($row),
                // Kontace (účetní doklad) — chybí u faktur → array_filter je
                // vynechá, takže faktury jsou beze změny.
                'account'           => $plan['resolvedRowAccounts'][$i] ?? null,
                'acc_side'          => isset($row['accSide'])
                                        ? (['debit' => 0, 'credit' => 1][$row['accSide']] ?? null)
                                        : null,
                'partner'           => $plan['resolvedRowPartners'][$i] ?? null,
                // Dimenze deníku řádku (#110 T2) — sloupce `rowColumn`.
                ...$this->dimensionColumns($plan['resolvedRowDimensions'][$i] ?? [], head: false),
                'payment_reference' => $row['paymentReference'] ?? null,
                'specific_symbol'   => $row['specificSymbol'] ?? null,
                'constant_symbol'   => $row['constantSymbol'] ?? null,
                'due_date'          => $row['dueDate'] ?? null,
            ], static fn($v) => $v !== null);
        }
        return $out;
    }

    /**
     * Dimenze deníku z objektu `dimensions` (#110 T2): klíč = id dimenze,
     * hodnota = přirozený klíč záznamu (`exchangeKey`). Neznámé id hlásí
     * {@see DocumentValidator} (`dimension_unknown`) — tady se přeskočí;
     * prázdná hodnota = bez dimenze; hodnota bez záznamu = chyba
     * `dimension_not_found` (blokuje apply, klíč je autoritativní). Platí
     * i v importním módu. Bez resolveru (DS bez dimenzí) se nic neřeší.
     *
     * @param array<string, mixed> $dimensions
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     * @return array<string, array{value: string, status: string, matchedId?: int}>
     */
    private function resolveDimensions(array $dimensions, string $path, array &$issues): array
    {
        if ($this->dimensionResolver === null) {
            return [];
        }
        $out = [];
        foreach ($dimensions as $id => $value) {
            $id = (string) $id;
            if (!is_scalar($value) || trim((string) $value) === '' || !$this->dimensionResolver->knows($id)) {
                continue;
            }
            $value = trim((string) $value);
            $matchedId = $this->dimensionResolver->resolve($id, $value);
            if ($matchedId !== null) {
                $out[$id] = ['value' => $value, 'status' => 'matched', 'matchedId' => $matchedId];
                continue;
            }
            $out[$id] = ['value' => $value, 'status' => 'notFound'];
            $issues[] = [
                'severity' => 'error',
                'path'     => "{$path}.{$id}",
                'code'     => 'dimension_not_found',
                'message'  => $this->dimensionResolver->name($id) . ": záznam „{$value}“ nebyl nalezen.",
            ];
        }
        return $out;
    }

    /**
     * @param array<string, array{value: string, status: string, matchedId?: int}> $resolve
     * @return array<string, int> id dimenze → id záznamu (jen nalezené)
     */
    private static function matchedDimensionIds(array $resolve): array
    {
        $out = [];
        foreach ($resolve as $id => $r) {
            if (($r['status'] ?? null) === 'matched' && isset($r['matchedId'])) {
                $out[(string) $id] = (int) $r['matchedId'];
            }
        }
        return $out;
    }

    /**
     * Sloupce hlavičky (`headColumn`) / řádku (`rowColumn`) pro nalezené
     * dimenze. Dimenze bez sloupce na dané úrovni se vynechá.
     *
     * @param array<string, int> $matched id dimenze → id záznamu
     * @return array<string, int>
     */
    private function dimensionColumns(array $matched, bool $head): array
    {
        if ($matched === []) {
            return [];
        }
        $out = [];
        $set = JournalDimensionSet::fromConfig($this->config);
        foreach ($matched as $id => $recordId) {
            $dimension = $set->get($id);
            $column = $head ? $dimension?->headColumn : $dimension?->rowColumn;
            if ($column !== null) {
                $out[$column] = $recordId;
            }
        }
        return $out;
    }

    /**
     * Indexy canonicalu řádků, které {@see transformRows()} převede, v pořadí
     * výstupu — řádek přeskočený volbou uživatele (`rowSkips`) a ne-pole
     * v něm chybí. Jediný zdroj pořadí: čte ho i náhled, když ceny spočítané
     * dokladem přiřazuje zpět řádkům canonicalu ({@see computePreviewAmounts()}).
     *
     * @param array<int, mixed> $rows
     * @param array<string, mixed> $plan
     * @return list<int>
     */
    private function transformedRowIndices(array $rows, array $plan): array
    {
        $out = [];
        foreach ($rows as $i => $row) {
            if (in_array($i, $plan['rowSkips'] ?? [], true) || !is_array($row)) {
                continue;
            }
            $out[] = $i;
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

    /**
     * Řada podle id (`applyOptions.numberSeriesId`, #110 — generované doklady
     * znají řadu z nastavení, kód řady je nepovinný a neunikátní): musí
     * existovat, být aktivní a typu dokladu, jinak number_series_not_found.
     *
     * @throws NumberSeriesNotFoundException
     */
    private function resolveNumberSeriesById(string $docType, int $seriesId): int
    {
        $row = $this->db->fetch(
            'SELECT [id] FROM [docs_core_number_series]
             WHERE [id] = %i AND [doc_type] = %s AND [docState] IN (%i, %i, %i)',
            $seriesId, $docType,
            self::ACTIVE_STATES[0], self::ACTIVE_STATES[1], self::ACTIVE_STATES[2],
        );
        if ($row === null) {
            throw new NumberSeriesNotFoundException($docType, "#{$seriesId}");
        }
        return (int) $row['id'];
    }

    // ── Přegenerovat v místě — applyOptions.replaceConcept (#110 D24, Q2) ──

    /**
     * Hlavičkové sloupce, které `transform()` při null zahodí a které tedy
     * replace mód musí explicitně vynulovat / vrátit na default — update
     * přes TableGateway zapisuje jen přítomné klíče a staré hodnoty by
     * přežily. Hodnota = to, co by měl čerstvý doklad z applieru.
     */
    private const REPLACE_RESET_HEAD = [
        'doc_text' => null, 'partner_doc_number' => null, 'partner' => null, 'partner_address' => null,
        'partner_balance' => null, 'partner_balance_manual' => 0,
        'partner_bank' => null, 'partner_bank_account' => null, 'partner_bank_iban' => null, 'partner_bank_bic' => null,
        'due_date' => null, 'vat_duzp' => null, 'vat_dppd' => null, 'period_from' => null, 'period_to' => null,
        'fiscal_period_type' => null, 'vat_registration' => null, 'doc_currency' => null, 'exchange_rate' => null,
        'cash_desk' => null, 'cash_dir' => 0, 'bank_account' => null, 'payment_terminal' => null, 'transport' => null,
        'payment_reference' => null, 'specific_symbol' => null, 'constant_symbol' => null,
        'notice' => null, 'doc_notice' => null, 'total_rounding_mode' => 0, 'vat_rounding_mode' => 0,
        'source_kind' => null, 'source_message' => null, 'source_extracted_at' => null,
    ];

    /** @param array<string, mixed> $applyOptions */
    private static function replaceConceptId(array $applyOptions): ?int
    {
        $value = $applyOptions['replaceConcept'] ?? null;
        return is_int($value) && $value > 0 ? $value : null;
    }

    /**
     * Cílový koncept zamčený do konce transakce: musí existovat, být
     * v Konceptu (10) a stejného typu jako payload.
     *
     * @throws ApplyAbortedException replace_target_not_found (422)
     *         | replace_target_not_concept (409) | replace_target_type_mismatch (422)
     */
    private function lockReplaceTarget(?int $docId, string $docTypeCode): void
    {
        if ($docId === null) {
            return;
        }
        $row = $this->db->fetch(
            'SELECT [id], [docState], [doc_type] FROM [docs_core_heads] WHERE [id] = %i FOR UPDATE',
            $docId,
        );
        if ($row === null) {
            throw new ApplyAbortedException('replace_target_not_found', "Doklad #{$docId} k přegenerování neexistuje.", 422);
        }
        $state = (int) ($row['docState'] ?? 0);
        if ($state !== 10) {
            throw new ApplyAbortedException(
                'replace_target_not_concept',
                "Doklad #{$docId} není Koncept (stav {$state}) — přegenerovat jde jen koncept.",
                409,
            );
        }
        $targetType = (string) ($row['doc_type'] ?? '');
        if ($targetType !== $docTypeCode) {
            throw new ApplyAbortedException(
                'replace_target_type_mismatch',
                "Doklad #{$docId} je typu {$targetType}, payload {$docTypeCode}.",
                422,
            );
        }
    }

    /**
     * Hlavička pro update v místě: `id` cíle, autor zůstává (klíč se
     * neposílá — přítomný klíč by přepsal), sloupce mimo payload se
     * vrátí na hodnotu čerstvého dokladu; `rows` bez id nahradí řádky
     * (TableGateway::syncChildren), rekapitulaci přepočítá DocDocument.
     *
     * @param array<string, mixed> $head
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     * @return array<string, mixed>
     */
    private function applyReplaceConcept(array $head, int $docId, array &$issues): array
    {
        $head['id'] = $docId;
        if (array_key_exists('author', $head)) {
            unset($head['author']);
            $issues[] = [
                'severity' => 'warning',
                'path'     => 'applyOptions.author',
                'code'     => 'replace_author_ignored',
                'message'  => 'Při přegenerování zůstává autor původního konceptu.',
            ];
        }
        foreach ($this->replaceResetColumns() as $column => $reset) {
            if (!array_key_exists($column, $head)) {
                $head[$column] = $reset;
            }
        }
        return $head;
    }

    /**
     * Reset mapa vč. dynamických sloupců: hlavičkové sloupce dimenzí deníku
     * (podle konfigurace DS) a `cs_mode` jen s extension economy.vat.
     *
     * @return array<string, int|null>
     */
    private function replaceResetColumns(): array
    {
        $columns = self::REPLACE_RESET_HEAD;
        foreach (JournalDimensionSet::fromConfig($this->config) as $dimension) {
            if ($dimension->headColumn !== null) {
                $columns[$dimension->headColumn] = null;
            }
        }
        if (is_array($this->config->cfgItem('economy.vat.controlStatementModes'))) {
            $columns['cs_mode'] = 0;
        }
        return $columns;
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
     * Opačné mapy pro generátory kanonických dokladů z interních dat
     * (periodická fakturace, #110): krátký kód doc_type → kanonický název,
     * `vat_mode` → `vat.mode`, `payment_method` → `payment.method`. Neznámá
     * hodnota = passthrough / null, žádná výjimka — builder ji ohlásí sám.
     */
    public static function canonicalDocType(string $docTypeCode): string
    {
        $name = array_search($docTypeCode, self::DOC_TYPE_MAP, true);
        return $name === false ? $docTypeCode : $name;
    }

    public static function canonicalVatMode(int $vatMode): ?string
    {
        $name = array_search($vatMode, self::VAT_MODE_MAP, true);
        return $name === false ? null : $name;
    }

    public static function canonicalPaymentMethod(int $paymentMethod): ?string
    {
        $name = array_search($paymentMethod, self::PAYMENT_METHOD_MAP, true);
        return $name === false ? null : $name;
    }

    /**
     * @param array<string, mixed> $canonical
     */
    private function resolveVatRegistrationFor(array $canonical): ?int
    {
        // D2: přijatý doklad → naše registrace (stejná volba jako výchozí
        // hodnota formuláře); hodnota z AI / ISDOC se nepoužije.
        $vatCtx = $this->vatContext($canonical);
        if ($vatCtx['derive']) {
            return $vatCtx['ownRegistrationId'];
        }
        if ($vatCtx['nonPayer']) {
            // #97: doklad Bez DPH registraci nenese. Hledání podle země níže
            // platnost nezkoumá — bývalému plátci by vrátilo prošlou registraci.
            return null;
        }
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
     * Efektivní hlavička DPH a nabídka kódů do `_resolve` (#87 task B,
     * D13/D14): `vat.place` / `vat.mode` = {value, source, auto} (source ai |
     * vatId | user | derived | default) — náhled zobrazuje tohle, ne canonical;
     * `vatCodeOptions` jen ve větvi derive (jinde není z čeho vybírat).
     * buildSummary() čte pevné klíče, bloky navíc ho nerozhodí.
     *
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $resolved  Výstup {@see resolveAll}.
     * @return array<string, mixed>
     */
    private function withVatBlocks(array $canonical, array $resolved): array
    {
        $vatCtx = $this->vatContext($canonical);
        $place = $vatCtx['place'];
        $placeKnown = is_string($place) && isset(self::VAT_PLACE_MAP[$place]);
        $autoPlace = $vatCtx['autoPlace'];
        $mode = $this->effectiveVatMode($canonical, $vatCtx);
        // `auto` = hodnota bez volby uživatele — select v náhledu ji ukazuje
        // jako „Automaticky (…)“ i poté, co uživatel zvolil něco jiného.
        $resolved['vat'] = [
            'place' => [
                'value'  => $placeKnown ? $place : 'domestic',
                'source' => $placeKnown ? ($vatCtx['placeSource'] ?? 'default') : 'default',
                'auto'   => is_string($autoPlace) && isset(self::VAT_PLACE_MAP[$autoPlace]) ? $autoPlace : 'domestic',
            ],
            'mode' => ['value' => $mode['value'], 'source' => $mode['source'], 'auto' => $mode['auto']],
        ];
        if ($vatCtx['derive']) {
            $resolved['vatCodeOptions'] = $vatCtx['codeOptions'];
        }
        return $resolved;
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

    // ── Náhled: efektivní položka a účet řádku (#111) ──────────────────────

    /**
     * Doplní do `_resolve.rows[*]` bloky pro review modal (#111 D3, D7b),
     * jen `/preview`:
     *
     *  - `item.display` = `{id, code, name, pinned}` efektivní položky:
     *    uložená volba `useExisting:<id>` (`pinned: true`) má přednost před
     *    automatickým napárováním (`status: matched`, `pinned: false`).
     *    Volba na neexistující nebo smazanou položku → klíč chybí (apply ji
     *    stejně odmítne `conflict`). `noItem`, `skip` a nerozhodnutý
     *    nenapárovaný řádek `display` nemají.
     *  - `effectiveAccount` = účet, podle kterého se řádek zaúčtuje, nebo
     *    návrh účtu řádku; `null` = sloupec Účet ukáže „—“. Klíč je na
     *    každém řádku. Volba `skip` → null; kontační řádek (`accSide`) nebo
     *    volba `noItem` → účet řádku (`source: "row"`); efektivní položka →
     *    její účet, jen u účetní položky (`item_type` 2) s vyplněným
     *    `accounting_account` (`source: "item"`), jinak null — účet doplněný
     *    historií nebo štítkem se na řádek s položkou při apply nezapíše
     *    (D7b, {@see reconcile}); bez efektivní položky (nerozhodnuto,
     *    `canCreate`, `ambiguous`, `notFound`, pin na neexistující id) →
     *    účet řádku jako návrh (`source: "row"`).
     *
     *  - `item.matchedDisplay` = `{id, code, name}` automaticky napárované
     *    položky u každého bloku `status: matched`, nezávisle na volbě
     *    (#111 D10) — panel „Napárováno automaticky“ i po ruční volbě.
     *    Smazaná položka klíč nemá (klient ukáže `#id`).
     *
     * Volby se čtou stejně jako v {@see reconcile()} — pozičně z
     * `_resolve.rows[$pos].item.userAction`; vynechané řádky dává
     * `$rowSkips` ({@see skippedRowIndices}). Dva dotazy nezávisle na počtu
     * řádků: položky (efektivní i napárované, s LEFT JOIN na účty, jen když
     * sloupec `accounting_account` existuje) a účty řádků (název).
     *
     * @param array<string, mixed> $canonical Vstup s klientským `_resolve`.
     * @param array<string, mixed> $resolved  Výstup {@see resolveAll}.
     * @param list<int> $rowSkips Vynechané řádky ({@see skippedRowIndices}).
     * @return array<string, mixed>
     */
    private function annotateRowDisplay(array $canonical, array $resolved, array $rowSkips): array
    {
        $rows = is_array($resolved['rows'] ?? null) ? $resolved['rows'] : [];
        if ($rows === []) {
            return $resolved;
        }
        $canonicalRows = is_array($canonical['rows'] ?? null) ? $canonical['rows'] : [];
        $clientRows = is_array($canonical['_resolve']['rows'] ?? null) ? $canonical['_resolve']['rows'] : [];

        // 1. Per řádek: volba, efektivní a napárovaná položka, id účtu řádku.
        $decisions = [];
        $effectiveItems = [];
        $matchedItems = [];
        $itemIds = [];
        $accountIds = [];
        foreach ($rows as $pos => $rowResolve) {
            if (!is_array($rowResolve)) {
                continue;
            }
            $clientRow = is_array($clientRows[$pos] ?? null) ? $clientRows[$pos] : [];
            $itemAction = is_array($clientRow['item'] ?? null) ? ($clientRow['item']['userAction'] ?? null) : null;
            $decision = null;
            $itemId = null;
            // Automatické napárování nezávisle na volbě (D10).
            $matchedId = null;
            if (($rowResolve['item']['status'] ?? null) === 'matched') {
                $candidate = $rowResolve['item']['matchedId'] ?? null;
                $matchedId = is_int($candidate) && $candidate > 0 ? $candidate : null;
            }
            if (in_array($pos, $rowSkips, true)) {
                // Řádková i položková volba skip ({@see skippedRowIndices}).
                $decision = 'skip';
            } elseif (!isset($rowResolve['item'])) {
                // Řádek bez bloku položky (text, kontace) — volby položky
                // reconcile ignoruje, tady také.
            } elseif (is_string($itemAction) && str_starts_with($itemAction, 'useExisting:')) {
                $idStr = substr($itemAction, strlen('useExisting:'));
                if (ctype_digit($idStr) && (int) $idStr > 0) {
                    $decision = 'useExisting';
                    $itemId = (int) $idStr;
                }
            } elseif ($itemAction === 'noItem') {
                $decision = 'noItem';
            }
            if ($decision === null) {
                $itemId = $matchedId;
            }
            $decisions[$pos] = $decision;
            $effectiveItems[$pos] = $itemId;
            $matchedItems[$pos] = $matchedId;
            if ($itemId !== null) {
                $itemIds[] = $itemId;
            }
            if ($matchedId !== null) {
                $itemIds[] = $matchedId;
            }
            if (($rowResolve['account']['status'] ?? null) === 'matched' && isset($rowResolve['account']['matchedId'])) {
                $accountIds[] = (int) $rowResolve['account']['matchedId'];
            }
        }

        $items = $this->fetchItemsForDisplay(array_values(array_unique($itemIds)));
        $accounts = $this->fetchAccountsForDisplay(array_values(array_unique($accountIds)));

        // 2. Zápis bloků.
        foreach ($rows as $pos => $rowResolve) {
            if (!is_array($rowResolve)) {
                continue;
            }
            $decision = $decisions[$pos] ?? null;
            $item = ($effectiveItems[$pos] ?? null) !== null ? ($items[$effectiveItems[$pos]] ?? null) : null;
            if ($item !== null) {
                $resolved['rows'][$pos]['item']['display'] = [
                    'id'     => $item['id'],
                    'code'   => $item['code'],
                    'name'   => $item['name'],
                    'pinned' => $decision === 'useExisting',
                ];
            }
            $matched = ($matchedItems[$pos] ?? null) !== null ? ($items[$matchedItems[$pos]] ?? null) : null;
            if ($matched !== null) {
                $resolved['rows'][$pos]['item']['matchedDisplay'] = [
                    'id'   => $matched['id'],
                    'code' => $matched['code'],
                    'name' => $matched['name'],
                ];
            }

            $rowAccount = null;
            if (($rowResolve['account']['status'] ?? null) === 'matched' && isset($rowResolve['account']['matchedId'])) {
                $accId = (int) $rowResolve['account']['matchedId'];
                $rowAccount = [
                    'id'     => $accId,
                    'number' => (string) ($rowResolve['account']['number'] ?? ($accounts[$accId]['number'] ?? '')),
                    'name'   => $accounts[$accId]['name'] ?? null,
                    'source' => 'row',
                ];
            }
            $canonicalRow = $canonicalRows[$rowResolve['index'] ?? $pos] ?? null;
            $contation = is_array($canonicalRow) && isset($canonicalRow['accSide']);

            if ($decision === 'skip') {
                $effective = null;
            } elseif ($contation || $decision === 'noItem') {
                $effective = $rowAccount;
            } elseif ($item !== null) {
                $effective = $item['item_type'] === 2 && $item['account_id'] !== null
                    ? [
                        'id'     => $item['account_id'],
                        'number' => (string) $item['account_number'],
                        'name'   => $item['account_name'],
                        'source' => 'item',
                    ]
                    : null;
            } else {
                $effective = $rowAccount;
            }
            $resolved['rows'][$pos]['effectiveAccount'] = $effective;
        }
        return $resolved;
    }

    /**
     * Položky pro `display` / `effectiveAccount` jedním dotazem; účet jen
     * s extension `economy.accounting` (jinak `account_*` null).
     *
     * @param list<int> $itemIds
     * @return array<int, array{id: int, code: string, name: string, item_type: int, account_id: ?int, account_number: ?string, account_name: ?string}>
     */
    private function fetchItemsForDisplay(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }
        $rows = $this->itemsHaveAccountingAccount
            ? $this->db->fetchAll(
                'SELECT [i.id], [i.code], [i.name], [i.item_type],
                        [a.id] AS [account_id], [a.number] AS [account_number], [a.name] AS [account_name]
                 FROM [economy_items] AS [i]
                 LEFT JOIN [economy_accounting_accounts] AS [a] ON [a.id] = [i.accounting_account]
                 WHERE [i.id] IN %in AND [i.docState] IN %in',
                $itemIds, self::LINKABLE_STATES,
            )
            : $this->db->fetchAll(
                'SELECT [i.id], [i.code], [i.name], [i.item_type]
                 FROM [economy_items] AS [i]
                 WHERE [i.id] IN %in AND [i.docState] IN %in',
                $itemIds, self::LINKABLE_STATES,
            );
        $out = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $accountId = isset($row['account_id']) ? (int) $row['account_id'] : null;
            $out[$id] = [
                'id'             => $id,
                'code'           => (string) ($row['code'] ?? ''),
                'name'           => (string) ($row['name'] ?? ''),
                'item_type'      => (int) ($row['item_type'] ?? 0),
                'account_id'     => $accountId,
                'account_number' => $accountId !== null ? (string) ($row['account_number'] ?? '') : null,
                'account_name'   => $accountId !== null && isset($row['account_name']) ? (string) $row['account_name'] : null,
            ];
        }
        return $out;
    }

    /**
     * Názvy účtů řádků (`_resolve.rows[*].account`) jedním dotazem.
     *
     * @param list<int> $accountIds
     * @return array<int, array{number: string, name: ?string}>
     */
    private function fetchAccountsForDisplay(array $accountIds): array
    {
        if ($accountIds === []) {
            return [];
        }
        $out = [];
        foreach ($this->db->fetchAll(
            'SELECT [id], [number], [name] FROM [economy_accounting_accounts] WHERE [id] IN %in',
            $accountIds,
        ) as $row) {
            $out[(int) $row['id']] = [
                'number' => (string) ($row['number'] ?? ''),
                'name'   => isset($row['name']) ? (string) $row['name'] : null,
            ];
        }
        return $out;
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

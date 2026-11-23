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
use Shipard\Core\Document\ValidationResult;
use Shipard\Module\Core\Exchange\Common\ApplyResult;
use Shipard\Module\Core\Exchange\Common\TransactionlessTableGateway;
use Shipard\Module\Core\Exchange\Document\Interpret\PendingRecords;
use Shipard\Module\Core\Exchange\Document\Interpret\ProposalDefaults;
use Shipard\Module\Core\Exchange\Document\Interpret\ProposalReport;
use Shipard\Module\Core\Exchange\Document\Interpret\RecapInterpretation;
use Shipard\Module\Core\Exchange\Document\Interpret\RecordMatching;
use Shipard\Module\Core\Exchange\Document\Interpret\RoundingInterpretation;
use Shipard\Module\Core\Exchange\Document\Interpret\SupplierCodeLearning;
use Shipard\Module\Core\Exchange\Document\Interpret\VatInterpretation;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Module\Docs\Core\AccountingDateRule;
use Shipard\Module\Docs\Core\BoundNumberSeriesProvisioner;
use Shipard\Module\Docs\Core\Defaults\DocHeadDefaults;
use Shipard\Module\Docs\Core\Defaults\DocRowDefaults;
use Shipard\Module\Docs\Core\DocDocument;
use Shipard\Module\Docs\Core\DocRowCalculator;
use Shipard\Module\Docs\Core\DocTypes;
use Shipard\Module\Docs\Core\OwnCompanyResolver;
use Shipard\Module\Docs\Core\PersonSnapshotBuilder;
use Shipard\Module\Docs\Core\RoundingModes;
use Shipard\Module\Docs\Core\TotalRoundingAuto;
use Shipard\Module\Docs\Core\VatRateDates;
use Shipard\Core\Accounting\JournalDimensionSet;
use Shipard\Module\Core\Exchange\Resolve\AccountResolver;
use Shipard\Module\Core\Exchange\Resolve\DimensionResolver;
use Shipard\Module\Core\Exchange\Resolve\BankAccountResolver;
use Shipard\Module\Core\Exchange\Resolve\ItemResolver;
use Shipard\Module\Core\Exchange\Resolve\PartyResolver;
use Shipard\Module\Core\Exchange\Resolve\ReferenceException;
use Shipard\Module\Core\Exchange\Resolve\ReferenceResolver;
use Shipard\Module\Core\Exchange\Resolve\ResolveResult;
use Shipard\Module\Core\Exchange\Resolve\ResolveStatus;
use Shipard\Module\Core\Exchange\Resolve\UnitResolver;
use Shipard\Module\Core\Exchange\Resolve\VatCodeResolver;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;
use Shipard\Module\Core\Exchange\Values\CanonicalValueNormalizer;
use Shipard\Module\Economy\ExchangeRates\ExchangeRateResolver;
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
     * Kontext dokladu z payloadu — reference, data, registrace DPH, oprava,
     * kurz; sdílí ho pokyn i interpretace návrhu ({@see PayloadContext}).
     */
    private readonly PayloadContext $payload;

    /** Společné kroky resolve nad tím, co payload říká ({@see PayloadResolve}). */
    private readonly PayloadResolve $payloadResolve;

    /**
     * Interpretace papírové podoby dokladu (#166 D51) — odvozování, které
     * běží jen v režimu návrh. Applier ho jen volá; vlastní kopii pravidel
     * nemá.
     */
    private readonly VatInterpretation $vatInterpretation;
    private readonly RecapInterpretation $recapInterpretation;
    private readonly RecordMatching $recordMatching;
    private readonly PendingRecords $pendingRecords;
    private readonly SupplierCodeLearning $supplierCodeLearning;
    private readonly ProposalDefaults $proposalDefaults;
    private readonly ProposalReport $proposalReport;

    /** Adaptér výchozích hodnot pokynu — viz applyInstructionDefaults(). */
    private ?InstructionDefaults $instructionDefaults = null;

    /**
     * Pokyn (#166 D44): payload, jak přišel (po normalizaci tvarů) — z něj
     * se skládá odpověď; applier dál pracuje s kopií doplněnou o výchozí
     * hodnoty. Null = návrh nebo `/validate`.
     *
     * @var array<string, mixed>|null
     */
    private ?array $instructionPayload = null;

    /**
     * Pole, která pokyn doplnil sám — `_resolve.defaults`. Null = návrh
     * (klíč se nevypisuje).
     *
     * @var list<array{path: string, value: mixed}>|null
     */
    private ?array $appliedDefaults = null;

    /**
     * Výchozí jednotky řádků bez systémového kódu (index řádku → id) —
     * formát je vyjádřit neumí, {@see resolveAll()} je bere odsud.
     *
     * @var array<int, int>
     */
    private array $defaultUnitIds = [];

    /** Map canonical vat.mode → docs_core_heads.vat_mode (cfgItem docs.core.vatModes). */
    public const VAT_MODE_MAP = [
        'none'      => 0,
        'fromBase'  => 1,
        'fromTotal' => 2,
    ];

    /** Map canonical vat.place → docs_core_heads.vat_place (cfgItem docs.core.vatPlaces). */
    /** Canonical `vat.recapSource` → docs_core_heads.vat_recap_source. */
    public const VAT_RECAP_SOURCE_MAP = [
        'computed' => 0,
        'declared' => 1,
    ];

    /**
     * Canonical `vat.rateSource` → docs_core_heads.vat_rate_source
     * (cfgItem docs.core.vatRateSources, #159 D11). Chybí / null = auto.
     */
    public const VAT_RATE_SOURCE_MAP = [
        'auto'     => VatRateDates::SOURCE_AUTO,
        'document' => VatRateDates::SOURCE_DOCUMENT,
        'rows'     => VatRateDates::SOURCE_ROWS,
    ];

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

    public const VAT_PLACE_MAP = [
        'domestic'    => 0,
        'intracom'    => 1,
        'thirdCountry' => 2,
    ];

    /** Map canonical payment.method → docs_core_heads.payment_method. */
    public const PAYMENT_METHOD_MAP = [
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
    public const ROW_KIND_MAP = [
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
         * id záznamu. Null = DS bez dimenzí s přirozeným klíčem, objekt se ignoruje
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
        /**
         * Kurz z lístku pro doklad v cizí měně bez kurzu (#121 D6) — náhled
         * i apply. Null = DS bez modulu kurzů (tabulka chybí): nic se
         * nedohledává, kurz zůstane, jak přišel.
         */
        private readonly ?ExchangeRateResolver $exchangeRateResolver = null,
        /**
         * Hledání záznamů podle referencí formátu (#166 D30) — strany,
         * položky, řada, pokladna, vlastní účet, účet řádku, registrace DPH,
         * původní doklad. Null = applier bez definic tabulek (testy): každá
         * reference skončí chybou `reference_table_unknown`.
         */
        private ?ReferenceResolver $references = null,
    ) {
        $this->payload = new PayloadContext($db, $config, $references, $exchangeRateResolver);
        $this->payloadResolve = new PayloadResolve($this->payload, $vatCodeResolver, $vatCodeDerivation, $dimensionResolver);
        $this->vatInterpretation = new VatInterpretation(
            $db,
            $config,
            $this->payload,
            $this->payloadResolve,
            $vatCodeResolver,
            $vatCodeDerivation,
            $vatPlaceDerivation,
            $contentTags,
        );
        $this->recapInterpretation = new RecapInterpretation(
            $this->payload,
            $this->payloadResolve,
            $this->vatInterpretation,
            $vatCodeResolver,
        );
        $this->recordMatching = new RecordMatching(
            $db,
            $this->payload,
            $this->payloadResolve,
            $this->vatInterpretation,
            $partyResolver,
            $itemResolver,
            $unitResolver,
            $bankAccountResolver,
            $accountResolver,
        );
        $this->pendingRecords = new PendingRecords($db, $personsGateway, $itemsGateway);
        // Zápis přes executeSql() — testovací podtřídy ho zachytávají.
        $this->supplierCodeLearning = new SupplierCodeLearning(fn (mixed ...$args) => $this->executeSql(...$args));
        $this->proposalDefaults = new ProposalDefaults($db, $config, $this->payload);
        $this->proposalReport = new ProposalReport($db, $itemsHaveAccountingAccount);
    }

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
            documentValidator: new DocumentValidator(JournalDimensionSet::fromConfig($config), $tables),
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
            exchangeRateResolver: isset($tables[ExchangeRateResolver::TABLE_LISTS])
                ? ExchangeRateResolver::create($db, $config, $dsConfig)
                : null,
            references: new ReferenceResolver($db, $tables),
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

    /**
     * Normalizace hodnot canonicalu (#118 D2): tvary v obou režimech; oprava
     * bankovního spojení z redundance (IBAN ↔ číslo účtu) je interpretace
     * předlohy a běží jen v návrhu (#166 D19, U5).
     *
     * @param array<string, mixed> $canonical
     * @return array<string, mixed>
     */
    private static function normalizeValues(array $canonical, ApplyMode $mode): array
    {
        // Reference (#166 D30, D32): strana, partner a položka zadané jen
        // referencí → objekt s `ref`, aby zbytek applieru četl jeden tvar;
        // pokyn `_resolve` nečte vůbec — blok se zahodí hned tady a volající
        // ho ohlásí ({@see appendResolveIgnoredIssue()}).
        $canonical = CanonicalReferences::expand($canonical);
        if (!$mode->derives()) {
            $canonical = CanonicalReferences::withoutResolve($canonical);
        }
        return $mode->derives()
            ? CanonicalValueNormalizer::normalize($canonical)
            : CanonicalValueNormalizer::normalizeShapes($canonical);
    }

    // ── Reference formátu (#166 D30–D33) ────────────────────────────────────

    // ── Výchozí hodnoty pokynu (#166 D40–D44, D48–D50) ──────────────────────

    /**
     * Pokyn: co payload neuvádí, doplní se týmž kódem jako ve formuláři
     * ({@see InstructionDefaults}) — do **pracovní kopie**, se kterou běží
     * zbytek náhledu i uložení. Odpověď se skládá z payloadu, jak přišel,
     * a doplněná pole vyjmenuje `_resolve.defaults`. Návrh se nemění:
     * výchozí hodnoty nedostává a klíč `defaults` nenese.
     *
     * Hlavička jde první — registrace DPH, datum a režim DPH určují kontext,
     * ve kterém se řádku vybírá kód DPH.
     *
     * @param array<string, mixed> $canonical payload po normalizaci tvarů
     * @return array<string, mixed> pracovní kopie
     */
    private function applyInstructionDefaults(array $canonical, ApplyMode $mode): array
    {
        $this->instructionPayload = null;
        $this->appliedDefaults = null;
        $this->defaultUnitIds = [];
        if ($mode->derives()) {
            return $canonical;
        }
        $this->instructionPayload = $canonical;
        $this->instructionDefaults ??= new InstructionDefaults(
            $this->payload->headDefaults(),
            new DocRowDefaults(new DataSourceConnection($this->db), $this->config),
            $this->references,
            new DataSourceConnection($this->db),
            $this->config,
        );
        $import = PayloadContext::isImportMode($canonical);

        $head = $this->instructionDefaults->head($canonical, $this->payload->mapDocType($canonical), $import);
        $canonical = $head['canonical'];
        $rows = $this->instructionDefaults->rows(
            $canonical,
            $this->defaultsHead($canonical),
            $import,
            $this->payload->vatRegistrationReference($canonical)['country'] ?? null,
        );

        $this->appliedDefaults = [...$head['defaults'], ...$rows['defaults']];
        $this->defaultUnitIds = $rows['unitIds'];
        return $rows['canonical'];
    }

    /** Doplnil pole pokyn sám ({@see applyInstructionDefaults()})? */
    private function isDefaulted(string $path): bool
    {
        return in_array($path, array_column($this->appliedDefaults ?? [], 'path'), true);
    }

    /**
     * Sloupce hlavičky, ze kterých se skládá kontext DPH pro výchozí hodnoty
     * řádků (`DocHeadVatContext::fromHead()`): typ, směr, místo plnění,
     * registrace a sloupce data sazby — tytéž hodnoty, které do dokladu
     * pošle {@see transform()}.
     *
     * @param array<string, mixed> $canonical payload s doplněnou hlavičkou
     * @return array<string, mixed>
     */
    private function defaultsHead(array $canonical): array
    {
        $vat = is_array($canonical['vat'] ?? null) ? $canonical['vat'] : [];
        return [
            'cash_dir'         => isset($canonical['cashDirection']) ? (int) $canonical['cashDirection'] : 0,
            'vat_place'        => self::VAT_PLACE_MAP[(string) ($vat['place'] ?? 'domestic')] ?? 0,
            'vat_registration' => $this->payload->vatRegistrationReference($canonical)['id'] ?? null,
            'doc_currency'     => strtolower((string) ($canonical['currency'] ?? '')),
        ] + $this->payload->vatRateHead($canonical, $this->payload->declaredVatMode($canonical)['mode']);
    }

    /**
     * Pokyn `_resolve` nečte (#166 D32): payload, který blok nese, projde
     * a dostane jeden info nález — volby uživatele patří návrhu.
     *
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    private static function appendResolveIgnoredIssue(bool $ignored, array &$issues): void
    {
        if ($ignored) {
            $issues[] = [
                'severity' => 'info',
                'path'     => '_resolve',
                'code'     => 'resolve_ignored',
                'message'  => 'Blok _resolve platí jen v režimu návrh — pokyn ho ignoruje; záznamy urči referencí v polích dokladu.',
            ];
        }
    }

    /** Nese pokyn blok `_resolve`, který se bude ignorovat? Volat před {@see normalizeValues()}. */
    private static function ignoresResolve(array $canonical, ApplyMode $mode): bool
    {
        return !$mode->derives() && CanonicalReferences::carriesResolve($canonical);
    }

    // ── Hledání záznamů pokynu — jen přes reference (#166 D31) ──────────────

    /**
     * Strana dokladu v pokynu: `ref`, jinak nic — objekt s názvem, IČO nebo
     * DIČ bez reference je chyba `reference_missing` (ne tiché „bez
     * partnera“), prázdný objekt strana bez osoby. Návrh hledá i podle údajů
     * ({@see RecordMatching}).
     *
     * @param array<string, mixed> $party
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     * @return array<string, mixed>|null blok `_resolve`; null = strana se nehledá
     */
    private function resolveParty(string $path, array $party, array &$issues): ?array
    {
        if (CanonicalReferences::isSet($party['ref'] ?? null)) {
            return $this->payloadResolve->referenceBlock('base_persons_persons', $party['ref'], $path, $issues);
        }
        if (!CanonicalReferences::partyIdentifies($party)) {
            return null;
        }
        $issues[] = [
            'severity' => 'error',
            'path'     => $path,
            'code'     => 'reference_missing',
            'message'  => 'Strana nese údaje osoby, ale ne referenci — v pokynu ji urči polem ref (id, "@kód osoby", "@company_id:IČO").',
        ];
        return PayloadResolve::REFERENCE_ERROR_BLOCK;
    }

    /**
     * Položka řádku v pokynu — stejné pořadí jako u strany. Řádek s `ourCode`
     * hlásí validátor (`signal_not_allowed`), tady se jen nehledá.
     *
     * @param array<string, mixed> $item
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     * @return array<string, mixed>|null
     */
    private function resolveRowItem(string $path, array $item, array &$issues): ?array
    {
        if (CanonicalReferences::isSet($item['ref'] ?? null)) {
            return $this->payloadResolve->referenceBlock('economy_items', $item['ref'], $path, $issues);
        }
        if (trim((string) ($item['ourCode'] ?? '')) !== '' || !CanonicalReferences::itemIdentifies($item)) {
            return null;
        }
        $issues[] = [
            'severity' => 'error',
            'path'     => $path,
            'code'     => 'reference_missing',
            'message'  => 'Řádek nese údaje položky, ale ne referenci — v pokynu ji urči polem ref (id, "@kód položky"); řádek bez položky nese text v poli description.',
        ];
        return PayloadResolve::REFERENCE_ERROR_BLOCK;
    }

    /**
     * Účet řádku v pokynu: jen reference (číslo = id, `"@518100"`). Holé
     * číslo je signál návrhu (D39) — odmítá ho validátor, tady se nehledá.
     *
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     * @return array{number: ?string, matchedId: ?int, status: string}|null
     */
    private function resolveRowAccount(int|string $idx, mixed $account, array &$issues): ?array
    {
        if (!CanonicalReferences::isSet($account) || CanonicalReferences::isAccountSignal($account)) {
            return null;
        }
        return $this->payloadResolve->rowAccountReference($idx, $account, $issues);
    }

    /**
     * @param array<string, mixed> $canonical
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     *        Reference; resolvers append issues for missing references.
     * @return array<string, mixed>  `_resolve`-shaped resolve data.
     */
    private function resolveAll(array $canonical, array &$issues, ApplyMode $mode): array
    {
        if ($mode->derives()) {
            // Návrh hledá i podle údajů z papíru — resolve interpretace.
            return $this->recordMatching->resolveAll($canonical, $issues);
        }
        // Účetní doklad (cmnbkp): nemá supplier/customer/selfParty — saldo
        // identita žije per řádek. Resolvujeme jen řádky (item + účet z čísla).
        $docTypeCode = $this->payload->mapDocType($canonical);
        $isAccountingDoc = $docTypeCode === 'cmnbkp';
        // Pokladní doklad / prodejka: strany nepovinné (anonymní doklad) —
        // chybějící strana se neresolvuje, jinak by skončila unresolved_required.
        $partiesOptional = $this->payload->isCashDeskBoundDocType($docTypeCode);

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

            // Strana vlastní firmy referenci nenese; druhá strana: `ref`,
            // jinak v návrhu hledání podle údajů ({@see resolveParty()}).
            $supplierResult = $selfParty === 'supplier'
                ? $this->partyResolver->resolveSelfParty()->toArray()
                : $this->resolveParty('supplier', $supplier, $issues);
            $customerResult = $selfParty === 'customer'
                ? $this->partyResolver->resolveSelfParty()->toArray()
                : $this->resolveParty('customer', $customer, $issues);

            $supplierPersonId = ($supplierResult['status'] ?? null) === ResolveStatus::Matched->value
                ? ($supplierResult['matchedId'] ?? null)
                : null;

            // Objekt bez IBAN i čísla účtu = doklad bez bankovního spojení
            // (účtenka, karta) — není to reference k rozhodnutí (#103 F3).
            // Pokyn účet partnera nezakládá (#166 D31): vazba vznikne jen
            // přesnou shodou s jeho existujícím účtem, jinak zůstanou jen
            // údaje ve sloupcích hlavičky.
            if (is_array($supplier['bankAccount'] ?? null) && BankAccountResolver::hasIdentifier($supplier['bankAccount'])) {
                $bankResult = $this->bankAccountResolver->resolvePartnerBank(
                    $supplier['bankAccount'],
                    $supplierPersonId,
                );
                if ($bankResult->status === ResolveStatus::Matched) {
                    $supplierBankResult = $bankResult;
                }
            }
        }

        // Osoba pro saldokonto (#72): ruční plátce z payloadu — resolvuje se
        // jako strana (bez selfParty), u každého typu dokladu; chybějící =
        // plátce odvodí DocDocument při uložení.
        $balancePartyResult = null;
        $balanceParty = is_array($canonical['balanceParty'] ?? null) ? $canonical['balanceParty'] : [];
        if ($balanceParty !== []) {
            $balancePartyResult = $this->resolveParty('balanceParty', $balanceParty, $issues);
        }

        $rowsResolve = [];
        $rows = is_array($canonical['rows'] ?? null) ? $canonical['rows'] : [];
        // D2 (exchange-received-reverse-charge): u přijatého dokladu se kódy
        // DPH hledají v číselníku NAŠÍ registrace; ostatní doklady jdou
        // kaskádou registrationCountry → prefix kódu → země dodavatele.
        // Pokyn: země registrace dokladu ({@see PayloadContext::vatCodeCountry()}).
        $vatCtx = $this->payload->vatContext($canonical);
        $vatCountry = $this->payload->vatCodeCountry($vatCtx, $canonical);
        $taxPointDate = $vatCtx['taxPointDate'];

        foreach ($rows as $idx => $row) {
            // Text řádku, jak skončí na dokladu (#84 D2): náhled ho zobrazuje
            // hotový ze serveru, frontend skladbu nezrcadlí. Informativní,
            // apply ho nečte — skládá si ho znovu tímtéž helperem.
            $rowResolve = [
                'index'   => $idx,
                'rowText' => CanonicalRowText::compose(is_array($row) ? $row : []),
            ];
            if (PayloadResolve::rowHasItem($row)) {
                $itemBlock = $this->resolveRowItem("rows.{$idx}.item", $row['item'], $issues);
                if ($itemBlock !== null) {
                    $rowResolve['item'] = $itemBlock;
                }
            }

            // Účet kontačního řádku (acc.record) — {@see resolveRowAccount()}.
            $accountBlock = $this->resolveRowAccount($idx, is_array($row) ? ($row['account'] ?? null) : null, $issues);
            if ($accountBlock !== null) {
                $rowResolve['account'] = $accountBlock;
            }
            // Partner řádku (kontace, úhrada, záloha) — {@see PayloadResolve::resolvePartner()}.
            $partnerBlock = $this->payloadResolve->resolvePartner(
                "rows.{$idx}.partner",
                is_array($row) ? ($row[CanonicalReferences::PARTNER] ?? null) : null,
                false,
                $issues,
            );
            if ($partnerBlock !== null) {
                $rowResolve['partner'] = $partnerBlock;
            }
            if (empty($row['unit']) && isset($this->defaultUnitIds[$idx])) {
                // Výchozí jednotka z karty položky bez systémového kódu
                // (#166 D42) — formát ji vyjádřit neumí, id nese adaptér.
                $rowResolve['unit'] = ResolveResult::matched($this->defaultUnitIds[$idx], 'default')->toArray();
            }
            if (!empty($row['unit'])) {
                // Pokyn (#166 D37): jen přesný systémový kód, neznámá
                // jednotka je chyba; zkratky a aliasy zná jen návrh.
                $unitR = $this->unitResolver->resolve((string) $row['unit'], true);
                $rowResolve['unit'] = $unitR->toArray();
                if ($unitR->status === ResolveStatus::NotFound) {
                    $issues[] = [
                        'severity' => 'error',
                        'path'     => "rows.{$idx}.unit",
                        'code'     => 'unit_not_found',
                        'message'  => "Jednotka „{$row['unit']}\" není systémový kód jednotky.",
                    ];
                }
            }
            $rowDimensions = $this->payloadResolve->resolveDimensions(
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
            $subjectResolve = $this->payloadResolve->resolveRowReverseChargeCode(
                (int) $idx,
                is_array($row) ? $row : [],
                $vatResolve,
                $vatCtx,
                $vatCountry,
                $supplierCountry,
                $issues,
            );
            if ($subjectResolve !== null) {
                $rowResolve['reverseChargeCode'] = $subjectResolve;
            }
            $rowsResolve[] = $rowResolve;
        }

        $resolved = ['rows' => $rowsResolve];
        // Dimenze hlavičky (#110 D23) — výchozí hodnota pro řádky bez vlastní.
        $headDimensions = $this->payloadResolve->resolveDimensions(
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
            $resolved['supplier'] = $supplierResult;
        }
        if ($customerResult !== null) {
            $resolved['customer'] = $customerResult;
        }
        if ($balancePartyResult !== null) {
            $resolved['balanceParty'] = $balancePartyResult;
        }
        if ($supplierBankResult !== null) {
            $resolved['supplierBank'] = $supplierBankResult->toArray();
        }
        // Partner hlavičky (#166 D30) — jen účetní doklad a typy vázané na
        // pokladnu; u faktur je partnerem strana (nález dává validátor).
        $headPartner = CanonicalReferences::carriesHeadPartner($canonical)
            ? $this->payloadResolve->resolvePartner(CanonicalReferences::PARTNER, $canonical[CanonicalReferences::PARTNER] ?? null, false, $issues)
            : null;
        if ($headPartner !== null) {
            $resolved['partner'] = $headPartner;
            // Pokladní doklad: partnera hlavičky nese i strana podle směru —
            // dvě různé osoby pokyn neuloží (v návrhu má pole přednost).
            $headPartnerId = PayloadResolve::matchedPersonId($headPartner);
            $partyPartnerId = match ($canonical['selfParty'] ?? null) {
                'customer' => PayloadResolve::matchedPersonId($supplierResult),
                'supplier' => PayloadResolve::matchedPersonId($customerResult),
                default    => PayloadResolve::matchedPersonId($supplierResult) ?? PayloadResolve::matchedPersonId($customerResult),
            };
            if ($headPartnerId !== null && $partyPartnerId !== null && $headPartnerId !== $partyPartnerId) {
                $issues[] = [
                    'severity' => 'error',
                    'path'     => CanonicalReferences::PARTNER,
                    'code'     => 'partner_conflict',
                    'message'  => 'Pole partner a strana dokladu určují různé osoby — partnera pokladního dokladu urči jedním z nich.',
                ];
            }
        }
        // Opravný doklad (#92 task B U3): výsledek dohledání původního dokladu
        // do náhledu; withResolve() ho vrátí klientovi.
        $correction = $this->payloadResolve->correctionBlock($canonical);
        if ($correction !== null) {
            $resolved['correction'] = $correction;
        }
        return $resolved;
    }

    /**
     * Řádky vynechané volbou uživatele v `_resolve` — jen návrh
     * ({@see RecordMatching::skippedRowIndices()}); pokyn řádky nevynechává
     * (#166 D19).
     *
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $clientResolve Klientské `_resolve`.
     * @return list<int>
     */
    private function skippedRowIndices(array $canonical, array $clientResolve, ApplyMode $mode): array
    {
        return $mode->derives() ? $this->recordMatching->skippedRowIndices($canonical, $clientResolve) : [];
    }

    /**
     * Plán uložení z bloků `_resolve`: id spárovaných záznamů, v návrhu
     * i volby uživatele a co se má založit ({@see RecordMatching::reconcile()}).
     * Pokyn `_resolve` nečte — plán je jen projekce nalezených referencí.
     *
     * @param array<string, mixed> $resolved        Fresh resolve output.
     * @param array<string, mixed> $clientResolve   Client's _resolve with userAction set.
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     * @param list<int> $rowSkips
     * @return array<string, mixed>
     */
    private function reconcile(array $resolved, array $clientResolve, array &$issues, array $rowSkips): array
    {
        return $this->recordMatching->reconcile(
            $resolved,
            $clientResolve,
            $issues,
            $rowSkips,
            $this->applyOptionsCache['autoCreateMode'] ?? 'strict',
        );
    }

    /**
     * Založení osob, účtu dodavatele a položek podle plánu — jen návrh
     * ({@see PendingRecords}); v pokynu je plán zakládání vždy prázdný (D31).
     *
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $resolved
     * @return array{supplier: ?int, customer: ?int, balanceParty: ?int, supplierBank: ?int, rowItems: array<int, int>}
     */
    private function runSideCreates(array $plan, array $resolved): array
    {
        return $this->pendingRecords->create($plan);
    }

    /**
     * Pohyb řádků, které ho nenesou — jen návrh ({@see ProposalDefaults::rowOperations()}).
     *
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $sideIds
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     * @return array<int, string> index řádku → doplněný kód operace
     */
    private function defaultRowOperationsForApply(array $canonical, array $plan, array $sideIds, array &$issues): array
    {
        return $this->proposalDefaults->rowOperations($canonical, $plan, $sideIds, $issues);
    }

    // ── Public entry points ─────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $canonical
     * @param ApplyMode $mode Pokyn (výchozí) neodvozuje, návrh interpretuje (#166 D18).
     */
    public function validate(array $canonical, ApplyMode $mode = ApplyMode::Instruction): ApplyResult
    {
        // Statická kontrola výchozí hodnoty nedoplňuje — stav z předchozího volání pryč.
        $this->instructionPayload = null;
        $this->appliedDefaults = null;
        $this->defaultUnitIds = [];
        $schemaIssues = $this->schemaValidator->validate($canonical, self::FORMAT_ID, self::FORMAT_VERSION);
        if ($schemaIssues !== []) {
            return ApplyResult::error(
                code: 'schema_invalid',
                message: 'Struktura dokumentu neodpovídá schématu.',
                canonical: $this->withResolveIssues($canonical, $schemaIssues),
                statusCode: 400,
            );
        }

        // Tvary hodnot (v návrhu i oprava IBAN ↔ číslo účtu, #118 D2) — po schématu, před DocumentValidator.
        $resolveIgnored = self::ignoresResolve($canonical, $mode);
        $canonical = self::normalizeValues($canonical, $mode);
        $validatorIssues = $this->documentValidator->validate($canonical, $mode);
        self::appendResolveIgnoredIssue($resolveIgnored, $validatorIssues);
        $this->payload->appendAuthorIssue($canonical, $validatorIssues);
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
     * @param ApplyMode $mode Pokyn (výchozí) neodvozuje, návrh interpretuje (#166 D18).
     */
    public function preview(array $canonical, ApplyMode $mode = ApplyMode::Instruction): ApplyResult
    {
        $this->instructionPayload = null;
        $this->appliedDefaults = null;
        $this->defaultUnitIds = [];
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

        // 1b. Tvary hodnot, v návrhu i oprava IBAN ↔ číslo účtu (#118 D2) — náhled
        //     vrací normalizovaný canonical; `_resolve.valueRepairs` přenáší withResolve().
        $resolveIgnored = self::ignoresResolve($canonical, $mode);
        $canonical = self::normalizeValues($canonical, $mode);
        // 1c. Pokyn: výchozí hodnoty, které payload neuvádí (#166 D40) — dál
        //     běží vše nad doplněnou kopií, stejně jako v apply.
        $canonical = $this->applyInstructionDefaults($canonical, $mode);

        // 2. Semantic checks + resolve. Both contribute to _resolve.issues.
        //    Vynechané řádky (#111 D9, D12) z klientského _resolve jednou pro
        //    všechny kroky: důvod přepočtu rekapitulace, náhledový plán,
        //    bloky řádků.
        $clientResolve = is_array($canonical['_resolve'] ?? null) ? $canonical['_resolve'] : [];
        $rowSkips = $this->skippedRowIndices($canonical, $clientResolve, $mode);
        $issues = $this->documentValidator->validate($canonical, $mode);
        self::appendResolveIgnoredIssue($resolveIgnored, $issues);
        $this->payload->appendAuthorIssue($canonical, $issues);
        $this->appendVatModeIssue($canonical, $issues, $mode);
        $this->appendVatHeaderIssues($canonical, $issues, $mode);
        $this->payload->appendVatRateDateIssue($canonical, $issues);
        $this->appendRecapSourceIssue($canonical, $issues, $rowSkips, $mode);
        $this->payload->appendExchangeRateIssue($canonical, $issues);
        $this->payload->appendCorrectionIssues($canonical, $issues);
        $this->payload->appendVatRegistrationIssue($canonical, $issues);
        // Idempotence (#166 U4): doklad se stejným `source.ref` už existuje —
        // apply ho vrátí beze změny. Náhled to říká, zbytek běží jako obvykle.
        $this->appendSourceRefIssue($canonical, $issues);
        $resolved = $this->withVatBlocks($canonical, $this->resolveAll($canonical, $issues, $mode), $mode);
        // 2b. Efektivní položka a účet řádku pro review modal (#111 D3, D7b)
        //     — čte uložené volby z klientského _resolve, ale nerozhoduje
        //     (reconcile se v náhledu nevolá).
        $resolved = $this->proposalReport->annotateRowDisplay($canonical, $resolved, $rowSkips);
        // 3. Rekapitulace a součty, jak skončí na dokladu — stejným kódem
        //    jako uložení (tasks/exchange-preview-vat-recompute.md D2–D4, D6);
        //    vynechané řádky v nich nejsou (#111 D9).
        $resolved['computed'] = $this->computePreviewAmounts($canonical, $resolved, $issues, $rowSkips, $mode);
        // 4. Náhled pokynu (#166 D21, U6): validace dokladu pro cílový stav —
        //    nález je vidět před uložením. Návrh ji v náhledu nemá (review
        //    pracuje s konceptem; chybu uložení dostane až apply).
        if (!$mode->derives()) {
            $this->appendDocumentValidation($canonical, $resolved, $clientResolve, $rowSkips, $issues, $mode);
        }
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
     * D4: v návrhu se částka k úhradě porovná s dokladem dodavatele →
     * warning `computed_total_mismatch` (u samovyměření se liší daň,
     * k úhradě sedí). Heuristický `totals_mismatch` validátoru (odhad
     * z canonicalu) skutečný výpočet nahrazuje — z issues se vyřadí, dvě
     * hlášky o tomtéž by mátly. Bez `canonical.totals.totalAmount` se
     * neporovnává. V pokynu jsou `totals` kontrolní součet (#166 D27):
     * každé uvedené pole proti výpočtu, neshoda je chyba
     * `totals_check_failed` ({@see TotalsCheck}).
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
    private function computePreviewAmounts(
        array $canonical,
        array $resolved,
        array &$issues,
        array $rowSkips,
        ApplyMode $mode,
    ): ?array {
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
            $data = $this->transform($canonical, $plan, self::NO_SIDE_IDS, null, $mode);
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

        $recapSource = $this->resolveRecapSource($canonical, $rowSkips, $mode);
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
                    // Předmět plnění skupiny (#89 D10) — klíč jen u skupiny
                    // tuzemského přenesení, ostatní řádky beze změny.
                ] + (isset($r['reverse_charge_code']) && $r['reverse_charge_code'] !== ''
                    ? ['reverseChargeCode' => (string) $r['reverse_charge_code']]
                    : []),
                array_values($computed['recap']),
            ),
            'totals' => [
                'totalBase'     => (float) ($data['total_base'] ?? 0),
                'totalVat'      => (float) ($data['total_vat'] ?? 0),
                'totalAmount'   => (float) ($data['total_amount'] ?? 0),
                'totalRounding' => (float) ($data['total_rounding'] ?? 0),
                // Mód, který skončí na dokladu — při automatice (#73 D8) ho
                // určil computeAmounts, jinak výslovný mód z payloadu,
                // v návrhu bez něj odvození z čísel.
                'totalRoundingMode' => (int) ($data['total_rounding_mode'] ?? 0),
            ],
            'rows' => $computedRows,
        ];

        // D4: skutečný výpočet nahrazuje heuristiku validátoru.
        $issues = array_values(array_filter(
            $issues,
            static fn (array $issue): bool => ($issue['code'] ?? null) !== 'totals_mismatch',
        ));
        if (!$mode->derives()) {
            // Pokyn (#166 D27): `totals` jsou kontrolní součet — náhled hlásí
            // týž nález, kterým by uložení skončilo, jako chybu; varování
            // o nepoužitém módu zaokrouhlení (D26) jako apply.
            foreach (TotalsCheck::mismatches($canonical, $data) as $issue) {
                $issues[] = $issue;
            }
            $ignored = TotalsCheck::ignoredMode($canonical, $data);
            if ($ignored !== null) {
                $issues[] = $ignored;
            }
            return $result;
        }
        $mismatch = ProposalReport::computedTotalIssue($canonical, $result['totals']['totalAmount']);
        if ($mismatch !== null) {
            $issues[] = $mismatch;
        }

        return $result;
    }

    /**
     * Kontrolní součet pokynu při uložení ({@see TotalsCheck}, #166 D27):
     * neshoda `totals` s hlavičkou uloženého dokladu = `ApplyAbortedException`
     * `totals_check_failed` (422) s nálezem za každé pole. Varování
     * o nepoužitém módu zaokrouhlení (D26) uložení neblokuje.
     *
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $head data vrácená `saveDocument()`
     * @param array<int, array<string, mixed>> $issues
     * @throws ApplyAbortedException
     */
    private function checkDeclaredTotals(array $canonical, array $head, array &$issues): void
    {
        $mismatches = TotalsCheck::mismatches($canonical, $head);
        if ($mismatches !== []) {
            throw new ApplyAbortedException(
                TotalsCheck::CODE_FAILED,
                'Kontrolní součet dokladu nesedí: ' . implode(', ', array_column($mismatches, 'path'))
                    . ' se liší od výpočtu dokladu. Doklad se neuložil.',
                422,
                $mismatches,
            );
        }
        $ignored = TotalsCheck::ignoredMode($canonical, $head);
        if ($ignored !== null) {
            $issues[] = $ignored;
        }
    }

    /**
     * Náhled pokynu (#166 D21, U6): spustí `DocDocument::validate()` pro
     * cílový stav z `applyOptions.targetDocState` nad daty, která by apply
     * uložil, a nálezy (chyby i varování) přidá do issues. Nic se neukládá.
     *
     * Data skládá stejnou cestou jako apply — {@see reconcile()} (spárovaná
     * id stran a položek), {@see resolveSaveTargets()} bez zakládání řady,
     * výchozí pohyby řádků a {@see transform()} — a validuje **před**
     * `computeAmounts()`, v pořadí `TableGateway::saveDocument()`. Chyba
     * dohledání pokladny, řady nebo vlastního účtu je nález s týmž kódem,
     * jaký vrátí apply.
     *
     * Co náhled posoudit nemůže, nehlásí: nálezy na referenci, kterou apply
     * teprve založí nebo která čeká na rozhodnutí (partner, položka řádku
     * a její pohyb), a na řadě, kterou apply teprve zkusí založit. Zámky
     * záznamu (`TableGateway::enforceLock`) se v náhledu nevyhodnocují.
     *
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $resolved      Výstup {@see resolveAll}.
     * @param array<string, mixed> $clientResolve Klientské `_resolve`.
     * @param list<int> $rowSkips
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    private function appendDocumentValidation(
        array $canonical,
        array $resolved,
        array $clientResolve,
        array $rowSkips,
        array &$issues,
        ApplyMode $mode,
    ): void {
        // reconcile() čte autoCreateMode; jeho nálezy (reference čekající na
        // rozhodnutí) náhled nehlásí — stav referencí nesou bloky `_resolve`.
        $this->applyOptionsCache = is_array($canonical['applyOptions'] ?? null) ? $canonical['applyOptions'] : [];
        $discarded = [];
        $plan = $this->reconcile($resolved, $clientResolve, $discarded, $rowSkips);

        $targets = $this->resolveSaveTargets($canonical, $issues, provision: false);
        if ($targets['error'] !== null && !$targets['error']['reported']) {
            $issues[] = [
                'severity' => 'error',
                'path'     => $targets['error']['path'],
                'code'     => $targets['error']['code'],
                'message'  => $targets['error']['message'],
            ];
        }
        $plan['cashDeskId'] = $targets['cashDeskId'];
        $plan['ownBankAccountId'] = $targets['ownBankAccountId'];

        try {
            // Pohyb řádku pokyn dostal mezi výchozími hodnotami
            // ({@see applyInstructionDefaults()}); řádek bez něj je záměrně
            // prázdný — mapa návrhu se tu nevolá.
            $plan['rowOperationDefaults'] = [];
            $data = $this->transform($canonical, $plan, self::NO_SIDE_IDS, $targets['numberSeriesId'], $mode);
            $doc = $this->headsGateway->createDocument($data);
            // validate() data mění (denormalizace z řady, DPPD) — jen nad kopií.
            $copy = $data;
            $validation = $doc->validate($copy);
        } catch (\Throwable $e) {
            ErrorLogger::logException($e, 'DocumentApplier::preview: document validation failed');
            $issues[] = [
                'severity' => 'info',
                'path'     => '',
                'code'     => 'document_validation_unavailable',
                'message'  => 'Validaci dokladu pro cílový stav nešlo v náhledu spustit — nálezy dokladu ukáže až uložení.',
            ];
            return;
        }

        $rowIndices = $this->transformedRowIndices(is_array($canonical['rows'] ?? null) ? $canonical['rows'] : [], $plan);
        $pending = $this->pendingReferenceColumns($canonical, $resolved, $plan, $data, $targets['numberSeriesId'], $rowIndices);
        $kept = new ValidationResult();
        foreach ($validation->getErrors() as $error) {
            if (!in_array($error->column, $pending, true)) {
                $kept->addError($error->column, $error->message, $error->code);
            }
        }
        foreach ($validation->getWarnings() as $warning) {
            if (!in_array($warning->column, $pending, true)) {
                $kept->addWarning($warning->column, $warning->message, $warning->code);
            }
        }
        foreach (DocumentIssuePaths::issues($kept, $canonical, $rowIndices) as $issue) {
            $issues[] = $issue;
        }
    }

    /**
     * Sloupce dokladu, jejichž nález náhled pokynu nehlásí, protože hodnotu
     * doplní až apply ({@see appendDocumentValidation()}): řada, kterou
     * náhled nedohledal; partner, kterého payload nese, ale ještě nemá id
     * (založí se, nebo čeká na rozhodnutí); položka řádku bez id a pohyb,
     * který se z ní odvodí.
     *
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $resolved
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $data    Výstup {@see transform()}.
     * @param list<int> $rowIndices pozice řádku na dokladu → index canonicalu
     * @return list<string>
     */
    private function pendingReferenceColumns(
        array $canonical,
        array $resolved,
        array $plan,
        array $data,
        ?int $numberSeriesId,
        array $rowIndices,
    ): array {
        $pending = [];
        if ($numberSeriesId === null) {
            $pending[] = 'number_series';
        }
        if (empty($data['partner'])) {
            $carried = match ($canonical['selfParty'] ?? null) {
                'customer' => isset($resolved['supplier']),
                'supplier' => isset($resolved['customer']),
                default    => isset($resolved['supplier']) || isset($resolved['customer']),
            };
            if ($carried || isset($resolved['partner'])) {
                $pending[] = 'partner';
            }
        }
        foreach ($rowIndices as $position => $index) {
            $carriesItem = isset($resolved['rows'][$index]['item']);
            if ($carriesItem && ($plan['resolvedRowItems'][$index] ?? null) === null) {
                $pending[] = "rows.{$position}.item";
                $pending[] = "rows.{$position}.operation";
            }
            // Partner řádku, jehož reference se nenašla — chybu už nese nález.
            if (isset($resolved['rows'][$index]['partner']) && ($plan['resolvedRowPartners'][$index] ?? null) === null) {
                $pending[] = "rows.{$position}.partner";
            }
        }
        return $pending;
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
            'rowItemPins'          => [],
            'resolvedSupplier'     => null,
            'resolvedCustomer'     => null,
            'resolvedBalanceParty' => null,
            'resolvedSupplierBank' => null,
            'resolvedRowItems'     => [],
            'resolvedRowUnits'     => [],
            'resolvedRowVatCodes'  => [],
            'resolvedRowReverseChargeCodes' => [],
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
            $plan['resolvedRowReverseChargeCodes'][$i] = PayloadResolve::matchedReverseChargeCode($rowResolve);
        }
        return $plan;
    }

    /**
     * @param array<string, mixed> $canonical
     * @param ApplyMode $mode Pokyn (výchozí) neodvozuje, návrh interpretuje (#166 D18).
     */
    public function apply(array $canonical, ApplyMode $mode = ApplyMode::Instruction): ApplyResult
    {
        $this->instructionPayload = null;
        $this->appliedDefaults = null;
        $this->defaultUnitIds = [];
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

        // 0b. Idempotence pokynu (#166 D46): živý doklad se stejným
        //     `source.kind` + `source.ref` se vrací beze změny — dřív, než se
        //     obsah posuzuje, aby opakování po výpadku spojení prošlo i poté,
        //     co se ve zdroji dat něco změnilo. Obsah se neporovnává. Při
        //     přegenerování se cílový koncept z dohledání vylučuje.
        $sourceKey = SourceRef::fromCanonical($canonical);
        if ($sourceKey !== null) {
            $existing = $this->findBySourceRef($sourceKey, $replaceId);
            if ($existing !== null) {
                return $this->existingBySourceRef($canonical, $existing);
            }
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
        // Tvary hodnot (#118 D2) — i v importním módu; na hlavičku i do
        // založených účtů jdou normalizované hodnoty. Oprava IBAN ↔ číslo
        // účtu je interpretace, běží jen v návrhu (#166 D19).
        $resolveIgnored = self::ignoresResolve($canonical, $mode);
        $canonical = self::normalizeValues($canonical, $mode);
        // Pokyn: výchozí hodnoty, které payload neuvádí (#166 D40) — po
        // normalizaci, před kontrolami; stejné místo jako v náhledu.
        $canonical = $this->applyInstructionDefaults($canonical, $mode);
        $clientResolve = is_array($canonical['_resolve'] ?? null) ? $canonical['_resolve'] : [];
        // Vynechané řádky (#111 D9, D12) — tentýž seznam pro důvod přepočtu
        // rekapitulace i pro plán.
        $rowSkips = $this->skippedRowIndices($canonical, $clientResolve, $mode);
        $validatorIssues = $this->documentValidator->validate($canonical, $mode);
        self::appendResolveIgnoredIssue($resolveIgnored, $validatorIssues);
        $this->payload->appendAuthorIssue($canonical, $validatorIssues);
        $this->appendVatModeIssue($canonical, $validatorIssues, $mode);
        $this->appendVatHeaderIssues($canonical, $validatorIssues, $mode);
        $this->payload->appendVatRateDateIssue($canonical, $validatorIssues);
        $this->appendRecapSourceIssue($canonical, $validatorIssues, $rowSkips, $mode);
        $this->payload->appendExchangeRateIssue($canonical, $validatorIssues);
        $this->payload->appendCorrectionIssues($canonical, $validatorIssues);
        $this->payload->appendVatRegistrationIssue($canonical, $validatorIssues);

        // 3. Re-run resolve (fresh DB read; client's _resolve might be stale).
        $resolved = $this->withVatBlocks($canonical, $this->resolveAll($canonical, $validatorIssues, $mode), $mode);

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

        // 5a–5c. Pokladna, číselná řada a vlastní bankovní účet — čisté chyby
        //        apply (422) před transakcí, ne pád uvnitř ní.
        $docTypeCode = $this->payload->mapDocType($canonical);
        $targets = $this->resolveSaveTargets($canonical, $validatorIssues, provision: true);
        if ($targets['error'] !== null) {
            // Nenalezená reference už je v nálezech — odpověď je nese.
            return ApplyResult::error(
                $targets['error']['code'],
                $targets['error']['message'],
                $targets['error']['reported'] ? $this->withResolve($canonical, $resolved, $validatorIssues) : $enriched,
                statusCode: 422,
            );
        }
        $numberSeriesId = $targets['numberSeriesId'];
        $plan['cashDeskId'] = $targets['cashDeskId'];
        $plan['ownBankAccountId'] = $targets['ownBankAccountId'];

        // 6–11. Transactional save — NestedTransaction: vlastní transakce,
        //       nebo SAVEPOINT uvnitř cizí (periodická fakturace volá apply()
        //       ve své transakci a atomicky navazuje doklad na období, #110).
        //       Neúspěch closure = rollback vlastní práce; vnější transakce
        //       zůstává volajícímu.
        //
        //       Souběh pokynů se stejným `source.ref` (#166 U3): pojmenovaný
        //       zámek podle klíče držený od chvíle před transakcí do jejího
        //       commitu; první dotaz transakce doklad dohledá znovu (čerstvý
        //       snímek), takže druhý pokyn po uvolnění zámku najde doklad
        //       prvního. Zámek uvolňuje `finally` níž.
        if ($sourceKey !== null && !$this->acquireSourceLock($sourceKey)) {
            return ApplyResult::error(
                'source_ref_busy',
                'Jiný pokyn se stejným identifikátorem ve zdroji (source.ref) se právě ukládá — zkuste to znovu.',
                $enriched,
                statusCode: 409,
            );
        }
        try {
        try {
            $savedValidation = null;
            [$savedDocId, $sideCreatedIds] = NestedTransaction::run($this->db, function () use ($canonical, $resolved, &$plan, &$validatorIssues, &$savedValidation, $numberSeriesId, $replaceId, $docTypeCode, $mode, $sourceKey): array {
            // Idempotence pod zámkem (#166 U3): doklad mohl vzniknout mezi
            // prvním dohledáním a zámkem.
            if ($sourceKey !== null) {
                $existing = $this->findBySourceRef($sourceKey, $replaceId);
                if ($existing !== null) {
                    throw new SourceRefExistsException($existing);
                }
            }

            // Přegenerovat (Q2): zámek cílového konceptu — zavře závod
            // „koncept mezitím potvrzený“ (ApplyAbortedException → 409/422).
            $this->lockReplaceTarget($replaceId, $docTypeCode);

            // Side-creates first so we have ids to link in the doc.
            $sideCreatedIds = $this->runSideCreates($plan, $resolved);

            // Doplnění pohybu (operation) item řádkům — až po side-creates,
            // kdy jsou finální ID položek v DB (matched i právě založené),
            // takže item_type se čte jednotně přes ID. Issues se propíší do
            // finální response přes withResolve() po commitu.
            // Jen návrh: pokyn pohyb dostal mezi výchozími hodnotami
            // společným pravidlem ({@see applyInstructionDefaults()}) a řádek
            // bez něj je záměrně prázdný (#166 D50).
            $plan['rowOperationDefaults'] = $mode->derives()
                ? $this->defaultRowOperationsForApply($canonical, $plan, $sideCreatedIds, $validatorIssues)
                : [];

            // Transform canonical → internal $data.
            $data = $this->transform($canonical, $plan, $sideCreatedIds, $numberSeriesId, $mode);
            if ($replaceId !== null) {
                $data = $this->applyReplaceConcept($data, $replaceId, $validatorIssues);
            }

            // Save doc head + rows + vat_recap through DocDocument.
            $result = $this->headsGateway->saveDocument($data);
            if (!$result->isSuccess()) {
                // Doklad neprošel validací DocDocument (#166 D21): chyba apply
                // s nálezy dokladu, ne internal_error. Výjimka vrátí transakci
                // (založené entity); nálezy z ní poskládá catch níž.
                $validation = $result->getValidation();
                if ($result->getErrorMessage() === null && $validation !== null && !$validation->isValid()) {
                    throw new DocumentValidationFailedException($validation);
                }
                throw new \RuntimeException('Save failed: ' . ($result->getErrorMessage() ?? 'unknown error'));
            }
            $savedValidation = $result->getValidation();
            $savedDocId = (int) $result->getData()['id'];

            // Kontrolní součet (#166 D4, D27): `totals` pokynu proti tomu, co
            // doklad skutečně spočítal a uložil. Neshoda vrátí transakci —
            // doklad, založené entity ani číslo z řady nezůstanou. Návrh
            // `totals` nekontroluje (D28), jsou tam signálem pro review.
            if (!$mode->derives()) {
                $this->checkDeclaredTotals($canonical, $result->getData(), $validatorIssues);
            }

            // Per-partner item-code mapping learning — only after we know
            // which row.item ids ended up actually linked. Učení patří
            // interpretaci (#166 D6, D19): pokyn mapování kódů nezapisuje.
            if ($mode->derives()) {
                $this->supplierCodeLearning->learn($canonical, $plan, $sideCreatedIds);
            }

            // Lineage targets only — analysis resolution / message docState
            // update lives in MessageProposalApplier so the verdict write
            // stays one place. See tasks/mail-message-centric.md D6.
            $this->writeLineageTargets($canonical, $savedDocId);

            return [$savedDocId, $sideCreatedIds];
            });
        } catch (ApplyAbortedException $e) {
            if ($e->issues !== []) {
                foreach ($e->issues as $issue) {
                    $validatorIssues[] = $issue;
                }
                $enriched = $this->withResolve($canonical, $resolved, $validatorIssues);
            }
            return ApplyResult::error($e->errorCode, $e->getMessage(), $enriched, statusCode: $e->statusCode);
        } catch (SourceRefExistsException $e) {
            return $this->existingBySourceRef($canonical, $e->existing);
        } catch (DocumentValidationFailedException $e) {
            // D21 (#166), oba režimy: nálezy dokladu do `_resolve.issues`
            // s cestou v canonicalu; nic se neuložilo.
            $rowIndices = $this->transformedRowIndices(is_array($canonical['rows'] ?? null) ? $canonical['rows'] : [], $plan);
            foreach (DocumentIssuePaths::issues($e->validation, $canonical, $rowIndices) as $issue) {
                $validatorIssues[] = $issue;
            }
            return ApplyResult::error(
                'validation_failed',
                'Validace dokladu selhala.',
                $this->withResolve($canonical, $resolved, $validatorIssues),
                statusCode: 422,
            );
        } catch (\Throwable $e) {
            return ApplyResult::error('internal_error', $e->getMessage(), $enriched, statusCode: 500);
        }
        } finally {
            if ($sourceKey !== null) {
                $this->releaseSourceLock($sourceKey);
            }
        }

        // Varování dokladu z úspěšného uložení (#166 U6) — jen v pokynu:
        // výstup apply návrhu se nemění, jeho varování ukazuje review.
        if (!$mode->derives() && $savedValidation !== null) {
            $rowIndices = $this->transformedRowIndices(is_array($canonical['rows'] ?? null) ? $canonical['rows'] : [], $plan);
            foreach (DocumentIssuePaths::warnings($savedValidation, $canonical, $rowIndices) as $issue) {
                $validatorIssues[] = $issue;
            }
        }

        // Mark canCreate references as matched (with newly-assigned ids).
        $resolved = $this->annotateSideCreated($resolved, $sideCreatedIds);
        $finalCanonical = $this->withResolve($canonical, $resolved, $validatorIssues);
        $finalCanonical['savedDocId'] = $savedDocId;

        return ApplyResult::ok($finalCanonical, $savedDocId);
    }

    // ── Resolve all references ──────────────────────────────────────────────

    // ── Vynechané řádky (#111 D9, D12) ──────────────────────────────────────

    /**
     * Cíle uložení, které apply dohledává před transakcí: pokladna, číselná
     * řada a vlastní bankovní účet (kroky 5a–5c) — každý referencí (#166
     * D30). Společné pro apply a pro náhled pokynu (U6), aby validace
     * cílového stavu v náhledu dostala tutéž řadu a pokladnu jako uložení.
     *
     * Reference, která se nenajde, je **nález** (`$issues`, kód resolveru)
     * a `error` s `reported: true` — volající vrací 422 `validation_failed`.
     * Ostatní chyby (řada jiného typu, pokladna bez řady, cizí řada
     * u pokladního dokladu) jsou chyby apply s vlastním kódem.
     *
     * `$provision = false` (náhled) nic nezakládá: chybí-li vázaná řada
     * existující pokladny, vrátí řadu null **bez chyby** — apply by ji
     * teprve zkusil založit.
     *
     * @param array<string, mixed> $canonical
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     * @return array{numberSeriesId: ?int, cashDeskId: ?int, ownBankAccountId: ?int,
     *     error: ?array{code: string, message: string, path: string, reported: bool}}
     */
    private function resolveSaveTargets(array $canonical, array &$issues, bool $provision): array
    {
        $docTypeCode = $this->payload->mapDocType($canonical);
        $fail = static fn (string $code, string $message, string $path): array => [
            'numberSeriesId' => null, 'cashDeskId' => null, 'ownBankAccountId' => null,
            'error' => ['code' => $code, 'message' => $message, 'path' => $path, 'reported' => false],
        ];
        // Tři reference najednou — nález za každou, která se nenašla.
        $failed = false;
        $find = function (string $table, mixed $value, string $path) use (&$issues, &$failed): ?int {
            if (!CanonicalReferences::isSet($value)) {
                return null;
            }
            try {
                return $this->payload->references()->resolve($table, $value);
            } catch (ReferenceException $e) {
                $issues[] = PayloadContext::referenceIssue($e, $path);
                $failed = true;
                return null;
            }
        };
        // 5a. Pokladna (#59 D12): u typů s řadou vázanou na pokladnu (cash,
        //     cashreg) určuje řadu; u faktur jde do cash_desk hlavičky, ale
        //     jen při platbě hotově. Archivovanou pokladnu najde taky — zda
        //     se na ni dá doklad zařadit, rozhoduje až řada.
        $cashDeskId = $find('economy_codebooks_cash_desks', $canonical['cashDesk'] ?? null, 'cashDesk');
        $seriesRefId = $find('docs_core_number_series', $canonical['numberSeries'] ?? null, 'numberSeries');
        // 5c. Vlastní bankovní účet dokladu.
        $ownBankId = $find(
            'economy_codebooks_bank_accounts',
            is_array($canonical['payment'] ?? null) ? ($canonical['payment']['ownBankAccount'] ?? null) : null,
            'payment.ownBankAccount',
        );
        if ($failed) {
            return [
                'numberSeriesId' => null, 'cashDeskId' => null, 'ownBankAccountId' => null,
                'error' => ['code' => 'validation_failed', 'message' => 'Validace dokumentu selhala.', 'path' => '', 'reported' => true],
            ];
        }

        // 5b. Číselná řada. Vázaný typ: řada = (doc_type, pokladna). Chybí-li,
        //     nejdřív ji zkusí založit provisioner (idempotentní; pokladna
        //     importovaná přes generický CRUD nespustí afterSave handler,
        //     takže řady může dostat až tady) — teprve pokladna mimo stav 40
        //     nebo neexistující je chyba. Archivovaná pokladna (70) má řady
        //     v archivu a přijme ji jen import (applyOptions.importNumber) —
        //     historické doklady jsou legitimní, živý doklad na ni založit
        //     nejde (#59 Task E, E2). Uvedená jiná řada je chyba
        //     number_series_conflict (#166 U3).
        if ($this->payload->isCashDeskBoundDocType($docTypeCode)) {
            if ($cashDeskId === null) {
                // Pokladna výchozí hodnotu nemá — řada dokladu pokladny se
                // nikdy nevybírá potichu. Chybějící `cashDesk` hlásí
                // validátor (`required`); tady už jen bez druhého nálezu
                // na totéž pole (#166 D45 — jeden nález na pole).
                return [
                    'numberSeriesId' => null, 'cashDeskId' => null, 'ownBankAccountId' => null,
                    'error' => ['code' => 'validation_failed', 'message' => 'Validace dokumentu selhala.', 'path' => 'cashDesk', 'reported' => true],
                ];
            }
            $importMode = PayloadContext::isImportMode($canonical);
            $numberSeriesId = $cashDeskId !== null
                ? $this->resolveBoundNumberSeries($docTypeCode, $cashDeskId, $importMode)
                : null;
            if ($numberSeriesId === null && $cashDeskId !== null && $provision) {
                (new BoundNumberSeriesProvisioner(new DataSourceConnection($this->db), $this->config))
                    ->provisionForCashDesk($cashDeskId);
                $numberSeriesId = $this->resolveBoundNumberSeries($docTypeCode, $cashDeskId, $importMode);
            }
            if ($numberSeriesId === null && ($provision || $cashDeskId === null)) {
                return $fail(
                    'cash_desk_not_found',
                    "Pokladna nemá číselnou řadu typu {$docTypeCode}"
                    . ' a nelze ji založit — pokladna není ve stavu V pořádku (40); archivovanou pokladnu přijme jen import.',
                    'cashDesk',
                );
            }
            if ($seriesRefId !== null && $numberSeriesId !== null && $seriesRefId !== $numberSeriesId) {
                return $fail(
                    'number_series_conflict',
                    'Řadu dokladu vázaného na pokladnu určuje pokladna — pole numberSeries uvádí jinou řadu.',
                    'numberSeries',
                );
            }
        } else {
            // Řada z payloadu musí být aktivní a typu dokladu; bez ní výchozí
            // řada typu (první aktivní). Žádný tichý fallback z neplatné řady.
            try {
                $numberSeriesId = $seriesRefId !== null
                    ? $this->resolveNumberSeriesById($docTypeCode, $seriesRefId)
                    : $this->resolveNumberSeriesFor($docTypeCode);
            } catch (NumberSeriesNotFoundException $e) {
                return $fail('number_series_not_found', $e->getMessage(), 'numberSeries');
            }
            if ($cashDeskId !== null && ($canonical['payment']['method'] ?? null) !== 'cash') {
                // DocDocument::validate by odmítl (cash_desk_requires_cash_payment).
                $issues[] = [
                    'severity' => 'warning',
                    'path'     => 'cashDesk',
                    'code'     => 'cash_desk_ignored',
                    'message'  => 'Pokladna má na faktuře smysl jen při platbě hotově — ignorováno.',
                ];
                $cashDeskId = null;
            } elseif ($cashDeskId === null && ($canonical['payment']['method'] ?? null) === 'cash') {
                // Doklad placený hotově bez `cashDesk` (návrh z AI pokladnu
                // nezná): výchozí pokladna zdroje dat (`is_default`), aby se
                // účtoval na 211 místo chybové masky `211???` (#103 F3 nález E).
                // Bez výchozí pokladny zůstává prázdná — účtování pak ohlásí
                // cash_desk_account_missing jako dnes.
                $cashDeskId = $this->resolveDefaultCashDeskId();
                if ($cashDeskId !== null) {
                    $issues[] = [
                        'severity' => 'info',
                        'path'     => 'cashDesk',
                        'code'     => 'cash_desk_defaulted',
                        'message'  => 'Doklad placený hotově dostal výchozí pokladnu zdroje dat.',
                    ];
                }
            }
        }

        return [
            'numberSeriesId'   => $numberSeriesId,
            'cashDeskId'       => $cashDeskId,
            'ownBankAccountId' => $ownBankId,
            'error'            => null,
        ];
    }

    // ── Reconcile: validate userAction → execution plan ─────────────────────

    // ── Side-creates ────────────────────────────────────────────────────────

    /**
     * Thin wrapper around `Connection::query()` so subclasses (especially
     * testing doubles) can intercept. `Connection::query()` itself is
     * final and cannot be mocked directly. Pattern matches DocDocument.
     */
    protected function executeSql(mixed ...$args): void
    {
        $this->db->query(...$args);
    }

    // ── Transform canonical → internal $data ───────────────────────────────

    /**
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $plan
     * @param array{supplier: ?int, customer: ?int, supplierBank: ?int, rowItems: array<int, int>} $sideIds
     * @param ?int $numberSeriesId Pre-resolved by apply() ({@see resolveSaveTargets()}).
     * @return array<string, mixed>
     */
    private function transform(
        array $canonical,
        array $plan,
        array $sideIds,
        ?int $numberSeriesId,
        ApplyMode $mode,
    ): array {
        $docType = $this->payload->mapDocType($canonical);
        $selfParty = $canonical['selfParty'] ?? null;
        $targetDocState = (int) ($canonical['applyOptions']['targetDocState'] ?? 10);
        // Nedaňový typ (docTypes[].tax_document: false, #79 D1): DUZP/DPPD
        // kanonický doklad nenese — ignorují se už tady (DocDocument je
        // při uložení stejně vynuluje).
        $taxDocument = DocTypes::isTaxDocument($this->config, $docType);

        // Účetní doklad (cmnbkp): hlavičkový partner je nepovinný a žije per
        // řádek; bere se z pole `partner` (resolvedHeadPartner, v návrhu
        // i z volby), bez selfParty resolution. Faktura: partner = ta druhá
        // strana. Pokladní doklad / prodejka: nepovinný — pole `partner`,
        // jinak strana z payloadu.
        $partyPartner = match ($selfParty) {
            'customer' => $sideIds['supplier'] ?? $plan['resolvedSupplier'] ?? null,
            'supplier' => $sideIds['customer'] ?? $plan['resolvedCustomer'] ?? null,
            default    => $sideIds['supplier'] ?? $plan['resolvedSupplier']
                           ?? ($sideIds['customer'] ?? $plan['resolvedCustomer'] ?? null),
        };
        $isCashDeskBound = $this->payload->isCashDeskBoundDocType($docType);
        $partnerId = match (true) {
            $docType === 'cmnbkp' => $plan['resolvedHeadPartner'] ?? null,
            $isCashDeskBound      => $plan['resolvedHeadPartner'] ?? $partyPartner,
            default               => $partyPartner,
        };
        // Směr pokladního dokladu (jen cashDocument; validátor hlídá 1/2).
        $cashDir = isset($canonical['cashDirection']) ? (int) $canonical['cashDirection'] : 0;
        $totalRoundingAuto = $this->resolveTotalRoundingAuto($canonical, $docType, $cashDir, $mode);
        $totalRoundingMode = RoundingModeNames::totalCode($canonical['totals']['totalRoundingMode'] ?? null);
        // Ruční plátce (#72): poslaná strana = partner_balance s příznakem
        // ručního zadání, aby ho odvození (terminál / dopravce) nepřepsalo.
        $balancePartyId = $sideIds['balanceParty'] ?? $plan['resolvedBalanceParty'] ?? null;

        $vatRegistrationId = $this->resolveVatRegistrationFor($canonical, $mode);
        $vatCtx = $this->vatContext($canonical, $mode);
        // Režim výpočtu: volba uživatele → derivace → canonical → default,
        // s ochranou „Bez DPH“ + samovyměření ({@see effectiveVatMode}).
        // Koriguje se jen interní vat_mode, canonical vč. totals zůstává
        // nedotčený a DocDocument si base/VAT/totals přepočítá sám.
        $vatMode = self::VAT_MODE_MAP[$this->effectiveVatMode($canonical, $vatCtx)['value']];
        // Přijatý doklad neplátce DPH (#97) a doklad mimo české DPH (#89):
        // ceny řádků včetně daně dodavatele (prázdné = nic se nepřevádí).
        // Doklad bez rekapitulace plyne z téhož kontextu v resolveRecapSource();
        // registraci nenese jen neplátce (resolveVatRegistrationFor()).
        $supplierVatGross = $mode->derives()
            ? $this->vatInterpretation->supplierVatGrossTotals($canonical, $vatCtx)
            : [];
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
        $recapSource = $this->resolveRecapSource($canonical, $plan['rowSkips'] ?? [], $mode);
        $calcSource = self::VAT_CALC_SOURCE_MAP[(string) ($canonical['vat']['calcSource'] ?? 'header')] ?? 0;
        $csMode = self::CS_MODE_MAP[(string) ($canonical['vat']['controlStatementMode'] ?? 'auto')] ?? 0;
        // Způsob úhrady z payloadu. Pokyn ho bez hodnoty dostal mezi
        // výchozími hodnotami ({@see applyInstructionDefaults()}); když tu
        // chybí, je záměrně prázdný a platí výchozí hodnota sloupce. Návrh
        // se ptá téhož pravidla jako formulář: doklad pokladny Hotově,
        // ostatní Převodem.
        $paymentMethod = self::PAYMENT_METHOD_MAP[(string) ($canonical['payment']['method'] ?? '')]
            ?? ($mode->derives() ? $this->proposalDefaults->paymentMethod($docType) : null);

        // AI extractors sometimes omit accountingDate even when issueDate
        // is present. DocDocument::beforeSave (applyDateDefaults) would
        // backfill it, but its validate() runs first and rejects an empty
        // accounting_date — so default it here by the same rule
        // (AccountingDateRule, #103 D78: přijatá faktura DUZP, jinak datum
        // vystavení). The form-based flow still requires accounting_date
        // explicitly (DocsHeadsForm marks it required); this fallback
        // applies only to the Exchange apply path.
        $issueDate = $canonical['dates']['issueDate'] ?? null;
        $accountingDate = AccountingDateRule::fromCanonical($canonical);

        // Import mode (legacy migration): the client supplies the document's own
        // number + sequence. Opt-in — without it apply() behaves as before.
        $importNumber = $canonical['applyOptions']['importNumber'] ?? null;

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
                ? $this->buildImportPartnerSnapshot($canonical, $docType, $cashDir, $partnerId !== null ? (int) $partnerId : null)
                : null,
            // Pokladna (#59 D12): u vázaných typů ji DocDocument stejně
            // přepíše z řady; u faktur = platba hotově na této pokladně.
            // Null (nepokladní typ bez kódu) → vypadne přes array_filter.
            'cash_desk'            => $plan['cashDeskId'] ?? null,
            // 0 = typ bez směru; validátor u cashDocument vynucuje 1/2.
            'cash_dir'             => $cashDir !== 0 ? $cashDir : null,
            // Vlastní bankovní účet dokladu (`payment.ownBankAccount`, #166
            // D30) — vydaná faktura ho ve stavu V pořádku vyžaduje.
            'bank_account'         => $plan['ownBankAccountId'] ?? null,
            'partner_bank'         => $sideIds['supplierBank'] ?? $plan['resolvedSupplierBank'] ?? null,
            'partner_bank_account' => $canonical['supplier']['bankAccount']['accountNumber'] ?? null,
            'partner_bank_iban'    => $canonical['supplier']['bankAccount']['iban'] ?? null,
            'partner_bank_bic'     => $canonical['supplier']['bankAccount']['bic'] ?? null,
            'issue_date'           => $issueDate,
            'due_date'             => $canonical['dates']['dueDate'] ?? null,
            'accounting_date'      => $accountingDate,
            'vat_duzp'             => $taxDocument ? ($canonical['dates']['taxPointDate'] ?? null) : null,
            'vat_dppd'             => $taxDocument ? ($canonical['dates']['vatObligationDate'] ?? null) : null,
            // Datum sazby DPH (#159 D11, U4): přepínač a vlastní datum se jen
            // předají — mazání podle režimu a zdroj 0 u nedaňového dokladu
            // a dokladu bez DPH dělá dokument (VatRateDates::normalizeHead).
            VatRateDates::SOURCE_COLUMN => $vatCtx['rateHead'][VatRateDates::SOURCE_COLUMN],
            VatRateDates::COLUMN   => $vatCtx['rateHead'][VatRateDates::COLUMN],
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
            // Kurz z dokladu vyhrává; bez něj kurz z lístku k účetnímu datu
            // (#121 D6) — mimo import mód, viz exchangeRateDecision().
            'exchange_rate'        => $canonical['exchangeRate'] ?? ($this->payload->exchangeRateDecision($canonical)['value'] ?? null),
            // Automatické zaokrouhlení podle způsobu úhrady (#73 D8), viz
            // resolveTotalRoundingAuto(). Při zapnuté automatice mód určí
            // DocDocument::computeAmounts (klíč vypadne). Jinak platí
            // výslovný `totals.totalRoundingMode` (#166 D25, D26); bez něj
            // je pokyn na haléře (mód neodhaduje, D19) a návrh mód odvodí
            // z čísel (computed vs declared) — extrahovaný
            // totals.totalRounding je jen signál; null → klíč vypadne přes
            // array_filter níže a platí default 0.
            'total_rounding_auto'  => $totalRoundingAuto ? 1 : 0,
            'total_rounding_mode'  => match (true) {
                $totalRoundingAuto          => null,
                $totalRoundingMode !== null => $totalRoundingMode,
                !$mode->derives()           => RoundingModes::ON_CENT,
                default                     => RoundingInterpretation::deriveTotalRoundingMode(
                    $canonical,
                    $supplierVatGross !== [] ? round(array_sum($supplierVatGross), 2) : null,
                ),
            },
            // Zaokrouhlení DPH (#166 D25): záměr z payloadu v obou režimech,
            // odhad neexistuje; null → klíč vypadne a platí default 0.
            'vat_rounding_mode'    => RoundingModeNames::vatCode($canonical['vat']['roundingMode'] ?? null),
            'payment_method'       => $paymentMethod,
            'payment_reference'    => $canonical['payment']['paymentReference'] ?? null,
            'specific_symbol'      => $canonical['payment']['specificSymbol'] ?? null,
            'constant_symbol'      => $canonical['payment']['constantSymbol'] ?? null,
            'notice'               => $canonical['notes']['internal'] ?? null,
            'doc_notice'           => $canonical['notes']['onDocument'] ?? null,
            'source_kind'          => $canonical['source']['kind'] ?? null,
            'source_ref'           => $canonical['source']['ref'] ?? null,
            'source_message'       => $canonical['source']['message'] ?? null,
            'source_extracted_at'  => $this->mapExtractedAt($canonical['source']['extractedAt'] ?? null),
            // Opravný daňový doklad (#92 D10, task B U2–U3): jen u typu
            // s příznakem. Číslo a datum plnění původního dokladu jdou už
            // odsud — validate() i sazby DPH je čtou z dat před beforeSave()
            // (VatRateDates::automaticDate), dokument pak z odkazu potvrdí
            // tytéž hodnoty. Bez bloku žádný klíč — příznak zůstane na 0.
            ...$this->payload->correctionColumns($canonical),
            // Dimenze deníku (#110 T2): sloupce hlavičky podle deklarace
            // (`headColumn`); chybějící objekt = sloupce se nezapíší (NULL).
            ...$this->dimensionColumns($plan['resolvedHeadDimensions'] ?? [], head: true),
            'docState'             => $targetDocState,
            'rows'                 => $this->transformRows(
                $canonical['rows'] ?? [],
                $plan,
                $sideIds,
                self::savedWithoutVat($vatCtx),
                $supplierVatGross,
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
     * selfParty) do tvaru snapshotu z formuláře — {@see PersonSnapshotBuilder::shape()}
     * a `buildPartner()`: stejné klíče, bez bankovního spojení (#166 D23),
     * prázdná hodnota vždy `null` (D29).
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
    private function buildImportPartnerSnapshot(array $canonical, string $docType, int $cashDir = 0, ?int $partnerId = null): ?array
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
        // Pokladní doklad s polem `partner` (#166 D30): partnerem hlavičky
        // je osoba z pole — dobová data nese ono.
        $headPartner = CanonicalReferences::carriesHeadPartner($canonical) ? $canonical[CanonicalReferences::PARTNER] : [];
        if (is_array($headPartner) && CanonicalReferences::isSet($headPartner['ref'] ?? null)) {
            $party = $headPartner;
        }
        if ($party === []) {
            return null;
        }
        if (!CanonicalReferences::partyHasData($party)) {
            // Strana jen referencí (#166 D34): payload dobová data nenese —
            // snapshot z adresáře jako mimo importní mód.
            return $partnerId !== null
                ? (new PersonSnapshotBuilder($this->db))->buildPartner($partnerId, null)
                : null;
        }

        // Hodnoty z payloadu se ořezávají; prázdná je `null` u všech polí —
        // jedno pravidlo se snapshotem z formuláře (#166 D29). Klíče
        // i pravidlo prázdné hodnoty dává PersonSnapshotBuilder::shape().
        $s = static function (mixed $v): ?string {
            if ($v === null) {
                return null;
            }
            $t = trim((string) $v);
            return $t === '' ? null : $t;
        };

        $addr = is_array($party['address'] ?? null) ? $party['address'] : [];
        $country = $s($addr['country'] ?? null);

        // Bankovní spojení partnera do snapshotu nepatří: formulářový
        // snapshot partnera ho nemá (`PersonSnapshotBuilder::buildPartner()`),
        // účet dodavatele nese hlavička (`partner_bank*`).
        return PersonSnapshotBuilder::shape(
            [
                'full_name'          => (string) ($party['name'] ?? ''),
                'company_id'         => $s($party['companyId'] ?? null),
                'tax_id'             => $s($party['taxId'] ?? null),
                'vat_id'             => $s($party['vatId'] ?? null),
                'court_registration' => $s($party['courtRegistration'] ?? null),
                'email'              => $s($party['contact']['email'] ?? null),
                'phone'              => $s($party['contact']['phone'] ?? null),
                'web'                => $s($party['contact']['web'] ?? null),
                // Kanonická strana dokladu ID datové schránky nenese — klíč
                // je ve snapshotu přítomný a prázdný (#162 D133).
                'gov_e_box_id'       => null,
            ],
            $addr !== [] ? [
                'street'        => $s($addr['street'] ?? null),
                'house_number'  => $s($addr['houseNumber'] ?? null),
                'city'          => $s($addr['city'] ?? null),
                'city_part'     => $s($addr['cityPart'] ?? null),
                'zip'           => $s($addr['zip'] ?? null),
                'country'       => $country !== null ? strtolower($country) : null,
                'display_block' => $s($addr['displayBlock'] ?? null),
                'display_line'  => $s($addr['displayLine'] ?? null),
            ] : null,
        );
    }

    // ── Kód DPH řádků přijatého dokladu (VatCodeDerivation, D1–D3) ─────────

    // ── Režim: návrh se ptá interpretace, pokyn payloadu (#166 D51) ─────────

    /**
     * Kontext DPH dokladu. Návrh: odvození z papírové podoby
     * ({@see VatInterpretation::context()}); pokyn: jen co říká payload
     * ({@see PayloadContext::vatContext()}).
     *
     * @param array<string, mixed> $canonical
     * @return array<string, mixed>
     */
    private function vatContext(array $canonical, ApplyMode $mode): array
    {
        return $mode->derives()
            ? $this->vatInterpretation->context($canonical)
            : $this->payload->vatContext($canonical);
    }

    /**
     * Režim výpočtu DPH, který skončí na dokladu — jediné místo pro
     * transform() i blok `_resolve.vat.mode`. Pokyn (#166 D20): režim je
     * záměr — bez volby uživatele, bez korekce ze součtů a bez přepnutí kvůli
     * samovyměření; návrh {@see VatInterpretation::effectiveVatMode()}.
     *
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $vatCtx {@see vatContext()}
     * @return array{value: string, source: string, reason: ?string, pinned: bool, auto: string}
     */
    private function effectiveVatMode(array $canonical, array $vatCtx): array
    {
        if ($vatCtx['mode']->derives()) {
            return $this->vatInterpretation->effectiveVatMode($canonical, $vatCtx);
        }
        ['mode' => $value, 'source' => $source] = $this->payload->declaredVatMode($canonical);
        // Režim doplněný výchozí hodnotou není hodnota z payloadu.
        $source = $this->isDefaulted('vat.mode') ? 'default' : $source;
        return ['value' => $value, 'source' => $source, 'reason' => null, 'pinned' => false, 'auto' => $value];
    }

    /**
     * Nálezy k režimu DPH (odvozený režim, neplátce, doklad mimo české DPH)
     * — jen návrh; pokyn režim neodvozuje.
     *
     * @param array<string, mixed> $canonical
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    private function appendVatModeIssue(array $canonical, array &$issues, ApplyMode $mode): void
    {
        if ($mode->derives()) {
            $this->vatInterpretation->appendVatModeIssue($canonical, $issues);
        }
    }

    /**
     * Hlavičkové nálezy DPH: neznámé `vat.mode` / `vat.place` v obou
     * režimech; návrh k nim přidává volby uživatele a odvozené místo plnění
     * ({@see VatInterpretation::appendVatHeaderIssues()}).
     *
     * @param array<string, mixed> $canonical
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    private function appendVatHeaderIssues(array $canonical, array &$issues, ApplyMode $mode): void
    {
        if ($mode->derives()) {
            $this->vatInterpretation->appendVatHeaderIssues($canonical, $issues);
            return;
        }
        foreach ([PayloadContext::unknownVatModeIssue($canonical), PayloadContext::unknownVatPlaceIssue($canonical)] as $issue) {
            if ($issue !== null) {
                $issues[] = $issue;
            }
        }
    }

    /**
     * `_resolve.rows[].vatCode` jednoho řádku. Návrh o kódu rozhoduje
     * ({@see VatInterpretation::rowVatCode()}); kde nerozhoduje, a v pokynu
     * vždy, se kód z payloadu jen ověří ({@see PayloadResolve::rowVatCode()}).
     * Doklad Bez DPH v pokynu kódy nenese — neověřují se.
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
        $fallbackPct = null;
        if ($vatCtx['mode']->derives()) {
            $decided = $this->vatInterpretation->rowVatCode($idx, $row, $vatCtx, $vatCountry, $issues);
            if ($decided !== null) {
                return $decided['block'];
            }
            $pct = is_array($row['vat'] ?? null) ? ($row['vat']['pct'] ?? null) : null;
            $fallbackPct = is_numeric($pct) ? (float) $pct : null;
        } elseif ($vatCtx['withoutVat']) {
            return null;
        }
        return $this->payloadResolve->rowVatCode($idx, $row, $vatCtx, $vatCountry, $supplierCountry, $issues, $fallbackPct);
    }

    /**
     * Registrace DPH dokladu: pokyn z reference `vat.registration` (#166
     * D20, D36), návrh {@see VatInterpretation::registrationId()}.
     *
     * @param array<string, mixed> $canonical
     */
    private function resolveVatRegistrationFor(array $canonical, ApplyMode $mode): ?int
    {
        return $mode->derives()
            ? $this->vatInterpretation->registrationId($canonical)
            : $this->payload->vatContext($canonical)['ownRegistrationId'];
    }

    /**
     * Zdroj rekapitulace pro `docs_core_heads.vat_recap_source` a řádky
     * rekapitulace k převzetí. Pokyn: `vat.recapSource` je záměr
     * ({@see instructionRecapSource()}); návrh rozhoduje z předlohy
     * ({@see RecapInterpretation::recapSource()}). Doklad Bez DPH
     * rekapitulaci nemá.
     *
     * @param array<string, mixed> $canonical
     * @param list<int> $rowSkips Vynechané řádky ({@see skippedRowIndices}).
     * @return array{source: int, recap: array<int, array<string, mixed>>, fallback: ?string,
     *     issues?: list<array{severity: string, path: string, code: string, message: string}>}
     */
    private function resolveRecapSource(array $canonical, array $rowSkips, ApplyMode $mode): array
    {
        if ($mode->derives()) {
            return $this->recapInterpretation->recapSource($canonical, $rowSkips);
        }
        $vatCtx = $this->payload->vatContext($canonical);
        if (self::savedWithoutVat($vatCtx)) {
            return ['source' => 0, 'recap' => [], 'fallback' => null];
        }
        return $this->instructionRecapSource($canonical, $vatCtx);
    }

    /**
     * Doklad vznikne **Bez DPH** bez ohledu na to, co říká canonical:
     * přijatý doklad neplátce (#97), nebo doklad mimo české DPH (#89, vč.
     * dokladu od neplátce v tuzemsku — #164 D5a).
     * Jedna podmínka pro režim, kódy řádků, rekapitulaci i převod cen.
     * V pokynu (#166 D20) je to doklad, který Bez DPH **říká** (`vat.mode`
     * none). Rozhoduje se jednou ve {@see vatContext()} (`withoutVat`).
     *
     * @param array<string, mixed> $vatCtx {@see vatContext()}
     */
    private static function savedWithoutVat(array $vatCtx): bool
    {
        return $vatCtx['withoutVat'];
    }

    // ── Datum sazby DPH (#159 D11, tasks/vat-rate-date-exchange.md) ─────────

    // ── Autorita rekapitulace DPH (vat.recapSource) ─────────────────────────

    /**
     * Převzatá rekapitulace v pokynu (#166 D20): `vat.recapSource` je záměr.
     * Chybí nebo `computed` → dokument si rekapitulaci spočítá z řádků.
     * `declared` vyžaduje `vatRecap` s kódem DPH na každém řádku, ověřeným
     * proti číselníku země registrace k datu sazby řádku — chybějící
     * rekapitulace (`vat_recap_missing`), chybějící kód
     * (`vat_recap_code_missing`) i neznámý kód (`vat_code_unknown`) je
     * chyba, která apply zastaví; žádný tichý přepočet z řádků, žádné
     * dohledání kódu z řádků ani kontrola aritmetiky.
     *
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $vatCtx {@see vatContext()}
     * @return array{source: int, recap: array<int, array<string, mixed>>, fallback: ?string,
     *     issues?: list<array{severity: string, path: string, code: string, message: string}>}
     */
    private function instructionRecapSource(array $canonical, array $vatCtx): array
    {
        $computed = ['source' => 0, 'recap' => [], 'fallback' => null];
        if ((string) ($canonical['vat']['recapSource'] ?? 'computed') !== 'declared') {
            return $computed;
        }
        $entries = $canonical['vatRecap'] ?? null;
        if (!is_array($entries) || $entries === []) {
            return $computed + ['issues' => [[
                'severity' => 'error',
                'path'     => 'vatRecap',
                'code'     => 'vat_recap_missing',
                'message'  => 'Převzatá rekapitulace DPH (vat.recapSource: declared) vyžaduje řádky vatRecap.',
            ]]];
        }

        $vatCountry = $this->payload->vatCodeCountry($vatCtx, $canonical);
        $supplierCountry = strtolower((string) ($canonical['supplier']['country'] ?? ''));
        $recap = [];
        $errors = [];
        $subjectIssues = [];
        foreach ($entries as $position => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $rateDate = $this->payload->lineRateDate($vatCtx, $entry['rateDate'] ?? null);
            $code = trim((string) ($entry['vatCode'] ?? ''));
            if ($code === '') {
                $errors[] = [
                    'severity' => 'error',
                    'path'     => "vatRecap.{$position}.vatCode",
                    'code'     => 'vat_recap_code_missing',
                    'message'  => 'Řádek převzaté rekapitulace DPH nemá kód DPH.',
                ];
                continue;
            }
            $country = $this->payload->vatCountryForCode($code, $vatCountry, $supplierCountry);
            $codeR = $this->vatCodeResolver->resolve($code, $country !== '' ? $country : null, $rateDate, null);
            if ($codeR->status === ResolveStatus::NotFound) {
                $errors[] = [
                    'severity' => 'error',
                    'path'     => "vatRecap.{$position}.vatCode",
                    'code'     => 'vat_code_unknown',
                    'message'  => "Neznámý kód DPH „{$code}\" v převzaté rekapitulaci.",
                ];
                continue;
            }
            $this->payloadResolve->appendRecapEntry(
                $recap,
                $subjectIssues,
                $entry,
                $position,
                $code,
                (float) ($entry['vatPct'] ?? 0),
                $rateDate,
                $country !== '' ? $country : $vatCountry,
            );
        }
        if ($errors !== []) {
            return $computed + ['issues' => $errors];
        }
        return ['source' => 1, 'recap' => $recap, 'fallback' => null, 'issues' => $subjectIssues];
    }

    /**
     * Info issue, když rekapitulace nešla převzít a spočítá se z řádků —
     * bez něj by uživatel nepoznal, proč je na dokladu jiná rekapitulace
     * než na předloze. Přenesení daňové povinnosti není chyba předlohy
     * („nešlo převzít“ by mátlo): dodavatel správně uvádí 0 %, daň
     * přiznáváme my, proto vlastní text; ostatní důvody šablonou.
     *
     * @param array<string, mixed> $canonical
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     * @param list<int> $rowSkips Vynechané řádky ({@see skippedRowIndices}, D12).
     */
    private function appendRecapSourceIssue(array $canonical, array &$issues, array $rowSkips, ApplyMode $mode): void
    {
        $resolved = $this->resolveRecapSource($canonical, $rowSkips, $mode);
        foreach ($resolved['issues'] ?? [] as $issue) {
            $issues[] = $issue;
        }
        // Důvod přepočtu hlásí jen návrh — pokyn rekapitulaci z řádků nepřepočítává potichu.
        $fallback = $mode->derives() ? RecapInterpretation::fallbackIssue($resolved) : null;
        if ($fallback !== null) {
            $issues[] = $fallback;
        }
    }

    // ── Kurz cizí měny (#121 D6, U5) ───────────────────────────────────────

    // ── Opravný daňový doklad (#92 D10, task B U2–U4) ───────────────────────

    // ── Doklad Bez DPH s daní dodavatele v cenách řádků (#97, #89) ─────────

    // ── Doplnění pohybu (operation) na item řádcích ─────────────────────────

    /**
     * Příznak automatického zaokrouhlení nového dokladu (#73 D8, U9): jen
     * doklad, kde automatika platí ({@see TotalRoundingAuto::applies()} —
     * prodejní typ, u pokladního dokladu příjem). Explicitní
     * `totals.totalRoundingAuto` vyhrává (dump → seed zachová stav dokladu).
     * Bez něj: pokyn ji zapne všude, kde platí (#166 D26) — na `totals`
     * nezáleží, mód při vypnuté automatice říká `totals.totalRoundingMode`;
     * návrh ji zapne právě tehdy, když canonical nemá deklarovanou
     * `totals.totalAmount`. Pošta i ISDOC částku nesou — zůstávají u módu
     * uvedeného, jinak odvozeného z čísel ({@see RoundingInterpretation::deriveTotalRoundingMode()}).
     *
     * @param array<string, mixed> $canonical
     */
    private function resolveTotalRoundingAuto(array $canonical, string $docType, int $cashDir, ApplyMode $mode): bool
    {
        if (!TotalRoundingAuto::applies($this->config, $docType, $cashDir)) {
            return false;
        }
        $totals = $canonical['totals'] ?? null;
        $explicit = is_array($totals) ? ($totals['totalRoundingAuto'] ?? null) : null;
        if (is_bool($explicit)) {
            return $explicit;
        }
        if (!$mode->derives()) {
            // Pokyn (#166 U4, D26, D50): automatika nezávisí na `totals`.
            // Chybějící klíč = výchozí hodnota jako ve formuláři (zapnutá
            // u typu s automatikou, `DocHeadDefaults`); výslovné `null` =
            // záměrně bez automatiky.
            return !(is_array($totals) && array_key_exists('totalRoundingAuto', $totals))
                && $this->payload->headDefaults()->totalRoundingAuto($docType);
        }
        return RoundingInterpretation::totalRoundingAuto($canonical);
    }

    /**
     * @param array<int, mixed> $rows
     * @param array<string, mixed> $plan
     * @param array{supplier: ?int, customer: ?int, supplierBank: ?int, rowItems: array<int, int>} $sideIds
     * @param bool $withoutVat Doklad vznikne Bez DPH z rozhodnutí applieru
     *        ({@see savedWithoutVat()}): kód z historie řádků ani sazba
     *        dodavatele se na řádky nepropisují.
     * @param array<int, float> $grossTotals {@see VatInterpretation::supplierVatGrossTotals()} — řádek
     *        s cenou včetně daně jde na doklad z celkové ceny: bez jednotkové
     *        ceny bez daně (dopočítá ji DocRowCalculator) a bez slev, které
     *        cena už obsahuje.
     * @return array<int, array<string, mixed>>
     */
    private function transformRows(
        array $rows,
        array $plan,
        array $sideIds,
        bool $withoutVat = false,
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
            if (!$withoutVat && is_array($vat) && ($vat['status'] ?? null) === 'matched') {
                $vatPct = $vat['createPayload']['pct'] ?? null;
                $vatCode = $vat['createPayload']['code'] ?? null;
            }
            // Předmět plnění jen u řádku, na kterém skončil kód přenesení
            // (#89 D10) — o tom rozhodl resolveRowReverseChargeCode().
            $reverseChargeCode = $vatCode !== null
                ? ($plan['resolvedRowReverseChargeCodes'][$i] ?? null)
                : null;
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
                // Vlastní datum sazby řádku (#159 U4) — jen se předá.
                VatRateDates::COLUMN => PayloadContext::dateText($row['vat']['rateDate'] ?? null),
                'reverse_charge_code' => $reverseChargeCode,
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
     * Výchozí řada typu dokladu — první aktivní; payload bez `numberSeries`.
     */
    private function resolveNumberSeriesFor(string $docType): ?int
    {
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
     * Řada z reference `numberSeries` (#166 D30; id už našel resolver): musí
     * být aktivní a typu dokladu, jinak number_series_not_found.
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
        'source_kind' => null, 'source_ref' => null, 'source_message' => null, 'source_extracted_at' => null,
        // Opravný doklad (#92 task B): bez bloku `correction` běžný doklad.
        'corrective' => 0, 'corrected_doc' => null, 'corrected_doc_number' => null,
        'corrected_doc_date' => null, 'correction_reason' => null,
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

    /** Archivovaná entita (V archívu) — pokladna s historickými doklady (#59 Task E). */
    private const ARCHIVED_STATE = 70;

    /**
     * Výchozí pokladna zdroje dat (`is_default`, ve stavu V pořádku / Koncept)
     * pro doklad placený hotově bez `cashDesk` v canonicalu (#103 F3 nález E).
     */
    private function resolveDefaultCashDeskId(): ?int
    {
        $row = $this->db->fetch(
            'SELECT [id] FROM [economy_codebooks_cash_desks]
             WHERE [is_default] = 1 AND [docState] IN %in
             ORDER BY [sort_order], [id] LIMIT 1',
            self::ACTIVE_STATES,
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

    /** docs_core_heads.vat_place → canonical `vat.place` (opravný doklad, #92 task B). */
    public static function canonicalVatPlace(int $vatPlace): ?string
    {
        $name = array_search($vatPlace, self::VAT_PLACE_MAP, true);
        return $name === false ? null : $name;
    }

    /** docs_core_heads.vat_calc_source → canonical `vat.calcSource`. */
    public static function canonicalVatCalcSource(int $calcSource): ?string
    {
        $name = array_search($calcSource, self::VAT_CALC_SOURCE_MAP, true);
        return $name === false ? null : $name;
    }

    /**
     * Kanonický kód → interní hodnota pro importy, které nesou kódy
     * dokladového formátu mimo doklad (fakturační předpis zakázky, #110).
     * Neznámý kód = null; tvar kódu hlídá schéma volajícího.
     */
    public static function vatModeFromCanonical(string $mode): ?int
    {
        return self::VAT_MODE_MAP[$mode] ?? null;
    }

    public static function paymentMethodFromCanonical(string $method): ?int
    {
        return self::PAYMENT_METHOD_MAP[$method] ?? null;
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
        return self::alreadyAppliedResult($canonical, (int) $row['target_row']);
    }

    /**
     * Odpověď opakovaného apply: payload, jak přišel, `savedDocId`
     * existujícího dokladu a `_resolve.summary.status = "alreadyApplied"`.
     * Společný tvar idempotence podle zprávy pošty i podle `source.ref`.
     *
     * @param array<string, mixed> $canonical
     * @param list<array<string, mixed>> $issues
     */
    private static function alreadyAppliedResult(array $canonical, int $existingId, array $issues = []): ApplyResult
    {
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
            'issues' => $issues,
        ];
        return ApplyResult::ok($enriched, $existingId);
    }

    // ── Idempotence podle source.kind + source.ref (#166 D46) ──────────────

    /** Koš — doklad v koši nový pokyn neblokuje (#166 D46). */
    private const TRASH_STATE = 90;

    /** Jak dlouho čeká pokyn na souběžný pokyn se stejným klíčem (s). */
    private const SOURCE_LOCK_TIMEOUT = 10;

    /**
     * Živý doklad se stejným druhem zdroje a identifikátorem ve zdroji.
     * Index `idx_source_ref` dá kandidáty podle collation sloupce (bez
     * ohledu na velikost písmen), shodu rozhoduje {@see SourceRef::matches()}
     * přesně. Víc živých dokladů (doklad obnovený z koše vedle novějšího)
     * = nejnovější — ten, který pokyn vrátil naposledy.
     *
     * @param int|null $excludeId Cílový koncept přegenerování (U5) —
     *        `replaceConcept` nahrazuje právě jeho, nesmí se najít sám.
     * @return array{id: int, docType: string, docNumber: ?string}|null
     */
    private function findBySourceRef(SourceRef $key, ?int $excludeId): ?array
    {
        $rows = $this->db->fetchAll(
            'SELECT [id], [doc_type], [doc_number], [source_kind], [source_ref]
             FROM [docs_core_heads]
             WHERE [source_kind] = %s AND [source_ref] = %s AND [docState] <> %i
             ORDER BY [id] DESC',
            $key->kind, $key->ref, self::TRASH_STATE,
        );
        foreach ($rows as $row) {
            $row = is_array($row) ? $row : $row->toArray();
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0 || $id === $excludeId || !$key->matches($row['source_kind'] ?? null, $row['source_ref'] ?? null)) {
                continue;
            }
            // Koncept nese zástupné číslo `!<id>` — skutečné číslo nemá.
            $number = $row['doc_number'] ?? null;
            return [
                'id'        => $id,
                'docType'   => (string) ($row['doc_type'] ?? ''),
                'docNumber' => is_string($number) && $number !== '' && !str_starts_with($number, '!') ? $number : null,
            ];
        }
        return null;
    }

    /**
     * Odpověď apply, když doklad se stejným klíčem existuje: tentýž typ →
     * existující doklad beze změny (200, `alreadyApplied`); jiný typ než
     * `docType` payloadu → `source_ref_conflict` (422) — stejný identifikátor
     * pro jiný doklad je chyba volajícího.
     *
     * @param array<string, mixed> $canonical
     * @param array{id: int, docType: string, docNumber: ?string} $existing
     */
    private function existingBySourceRef(array $canonical, array $existing): ApplyResult
    {
        // Pokyn odpovídá payloadem, jak přišel.
        $canonical = $this->instructionPayload ?? $canonical;
        $issue = $this->sourceRefIssue($canonical, $existing);
        if ($issue['severity'] === 'error') {
            return ApplyResult::error(
                'source_ref_conflict',
                $issue['message'],
                $this->withResolveIssues($canonical, [$issue]),
                statusCode: 422,
            );
        }
        return self::alreadyAppliedResult($canonical, $existing['id'], [$issue]);
    }

    /**
     * Nález k existujícímu dokladu: info `already_applied`, nebo chyba
     * `source_ref_conflict` při jiném typu dokladu. Nese `docId`
     * a `docNumber` (koncept číslo nemá → null).
     *
     * @param array<string, mixed> $canonical
     * @param array{id: int, docType: string, docNumber: ?string} $existing
     * @return array{severity: string, path: string, code: string, message: string, docId: int, docNumber: ?string}
     */
    private function sourceRefIssue(array $canonical, array $existing): array
    {
        $label = '#' . $existing['id'] . ($existing['docNumber'] !== null ? ' (' . $existing['docNumber'] . ')' : '');
        $docType = $canonical['docType'] ?? null;
        $payloadType = is_string($docType) ? self::mapDocTypeValue($docType) : '';
        if ($payloadType !== $existing['docType']) {
            return [
                'severity'  => 'error',
                'path'      => 'source.ref',
                'code'      => 'source_ref_conflict',
                'message'   => "Identifikátor ve zdroji (source.ref) už nese doklad {$label} typu {$existing['docType']}, payload je typu {$payloadType}.",
                'docId'     => $existing['id'],
                'docNumber' => $existing['docNumber'],
            ];
        }
        return [
            'severity'  => 'info',
            'path'      => 'source.ref',
            'code'      => 'already_applied',
            'message'   => "Doklad se stejným identifikátorem ve zdroji už existuje: {$label}. Apply ho vrátí beze změny.",
            'docId'     => $existing['id'],
            'docNumber' => $existing['docNumber'],
        ];
    }

    /**
     * Náhled (#166 U4): existující doklad se stejným klíčem jako nález —
     * info `already_applied`, při jiném typu chyba `source_ref_conflict`.
     *
     * @param array<string, mixed> $canonical
     * @param array<int, array<string, mixed>> $issues
     */
    private function appendSourceRefIssue(array $canonical, array &$issues): void
    {
        $key = SourceRef::fromCanonical($canonical);
        if ($key === null) {
            return;
        }
        $options = is_array($canonical['applyOptions'] ?? null) ? $canonical['applyOptions'] : [];
        $existing = $this->findBySourceRef($key, self::replaceConceptId($options));
        if ($existing !== null) {
            $issues[] = $this->sourceRefIssue($canonical, $existing);
        }
    }

    /**
     * Pojmenovaný zámek MariaDB podle klíče (U3). Jména zámků jsou společná
     * celému serveru, proto nesou i databázi. Zámek patří spojení, ne
     * transakci: drží se přes commit vlastní transakce apply. Běží-li apply
     * uvnitř transakce volajícího, uvolní se před jejím commitem — souběh
     * pak hlídá volající (periodická fakturace zámkem řádku období).
     * Chráněné kvůli testům (`Connection::query()` je final).
     */
    protected function acquireSourceLock(SourceRef $key): bool
    {
        return (int) $this->db->fetchSingle(
            'SELECT GET_LOCK(CONCAT(DATABASE(), %s), %i)',
            ':' . $key->lockKey(), self::SOURCE_LOCK_TIMEOUT,
        ) === 1;
    }

    protected function releaseSourceLock(SourceRef $key): void
    {
        try {
            $this->db->fetchSingle('SELECT RELEASE_LOCK(CONCAT(DATABASE(), %s))', ':' . $key->lockKey());
        } catch (\Throwable) {
            // Spojení spadlo — zámek zanikl s ním.
        }
    }

    // ── Output enrichment helpers ──────────────────────────────────────────

    /**
     * @param array<string, mixed> $canonical
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     * @return array<string, mixed>
     */
    private function withResolveIssues(array $canonical, array $issues): array
    {
        // Pokyn odpovídá payloadem, jak přišel — doplněná pole nese `defaults`.
        $canonical = $this->instructionPayload ?? $canonical;
        $resolve = is_array($canonical['_resolve'] ?? null) ? $canonical['_resolve'] : [];
        if ($this->appliedDefaults !== null) {
            $resolve['defaults'] = $this->appliedDefaults;
        }
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
     * vatId | user | derived | default | nonPayer | outOfScope; u outOfScope
     * navíc `reason`) — náhled zobrazuje tohle, ne canonical;
     * `vatCodeOptions` jen ve větvi derive (jinde není z čeho vybírat).
     * buildSummary() čte pevné klíče, bloky navíc ho nerozhodí.
     *
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $resolved  Výstup {@see resolveAll}.
     * @return array<string, mixed>
     */
    private function withVatBlocks(array $canonical, array $resolved, ApplyMode $mode): array
    {
        $vatCtx = $this->vatContext($canonical, $mode);
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
            'mode' => ['value' => $mode['value'], 'source' => $mode['source'], 'auto' => $mode['auto']]
                // Důvod jen u dokladu mimo české DPH (#89) — náhled z něj skládá popisek.
                + ($mode['source'] === 'outOfScope' ? ['reason' => $mode['reason']] : []),
        ];
        if ($vatCtx['derive']) {
            $resolved['vatCodeOptions'] = $vatCtx['codeOptions'];
        }
        // Nabídka předmětů plnění (#89 D12) — jednou na doklad k datu sazby
        // dokladu, jen když má některý řádek kód tuzemského přenesení (blok
        // `reverseChargeCode`). Volbu validuje resolveRowReverseChargeCode()
        // týmž číselníkem k datu sazby řádku (#159 U3).
        foreach ($resolved['rows'] ?? [] as $rowResolve) {
            if (!is_array($rowResolve) || !isset($rowResolve['reverseChargeCode'])) {
                continue;
            }
            $country = $vatCtx['derive']
                ? (string) $vatCtx['ownCountry']
                : $this->payload->vatCountryForCode(
                    (string) ($rowResolve['vatCode']['createPayload']['code'] ?? ''),
                    $this->payload->vatCodeCountry($vatCtx, $canonical),
                    strtolower((string) ($canonical['supplier']['country'] ?? '')),
                );
            $resolved['reverseChargeOptions'] = $this->vatCodeDerivation->reverseChargeSubjectOptions(
                $country,
                is_string($vatCtx['rateDate'] ?? null) ? $vatCtx['rateDate'] : null,
            );
            break;
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
        // Pokyn odpovídá payloadem, jak přišel (#166 D44) — applier pracoval
        // s kopií doplněnou o výchozí hodnoty; která pole doplnil, říká
        // `_resolve.defaults` (cesta a hodnota). Návrh klíč nenese.
        $canonical = $this->instructionPayload ?? $canonical;
        $resolve = $resolved + ['issues' => $issues];
        if ($this->appliedDefaults !== null) {
            $resolve['defaults'] = $this->appliedDefaults;
        }
        $resolve['summary'] = $this->buildSummary($resolved, $issues);
        // Per-row enrichment audit (RowHistoryEnricher) is not part of the
        // fresh resolve — carry it over from the incoming _resolve by index,
        // otherwise preview/apply responses would drop it.
        if (is_array($resolve['rows'] ?? null)) {
            $resolve['rows'] = ProposalReport::carryOverEnrichment(
                $canonical['_resolve']['rows'] ?? null,
                $resolve['rows'],
            );
        }
        // Auditní blok oprav hodnot (#118 D14) — zapsal ho normalizátor při
        // /result nebo v tomto volání; čerstvý resolve ho nezná, přenáší se.
        $repairs = $canonical['_resolve'][CanonicalValueNormalizer::REPAIRS_KEY] ?? null;
        if (is_array($repairs) && $repairs !== []) {
            $resolve[CanonicalValueNormalizer::REPAIRS_KEY] = $repairs;
        }
        $canonical['_resolve'] = $resolve;
        return $canonical;
    }

    // ── Náhled: efektivní položka a účet řádku (#111) ──────────────────────

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
        foreach (['supplier', 'customer', 'balanceParty', 'partner', 'supplierBank'] as $key) {
            $status = $resolved[$key]['status'] ?? null;
            if ($status === 'matched') $matched++;
            elseif ($status === 'ambiguous') $ambiguous++;
            elseif ($status === 'notFound' || $status === 'canCreate') $unresolved++;
        }
        foreach ($resolved['rows'] ?? [] as $rowR) {
            foreach (['item', 'partner', 'unit', 'vatCode', 'reverseChargeCode'] as $k) {
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

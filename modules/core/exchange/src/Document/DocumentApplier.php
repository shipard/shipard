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
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Module\Docs\Core\AccountingDateRule;
use Shipard\Module\Docs\Core\BoundNumberSeriesProvisioner;
use Shipard\Module\Docs\Core\Defaults\DocHeadDefaults;
use Shipard\Module\Docs\Core\Defaults\DocRowDefaults;
use Shipard\Module\Docs\Core\DocDocument;
use Shipard\Module\Docs\Core\DocTypes;
use Shipard\Module\Docs\Core\OwnCompanyResolver;
use Shipard\Module\Docs\Core\PersonSnapshotBuilder;
use Shipard\Module\Docs\Core\RoundingModes;
use Shipard\Module\Docs\Core\TotalRoundingAuto;
use Shipard\Module\Docs\Core\VatRateDates;
use Shipard\Core\Accounting\JournalDimensionSet;
use Shipard\Module\Core\Exchange\Resolve\DimensionResolver;
use Shipard\Module\Core\Exchange\Resolve\BankAccountResolver;
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

/**
 * **Pokyn**: uloží doklad výměnného formátu tak, jak ho payload říká
 * (#166 D18, D53). Viz docs/exchange-format.md § 3 a § 10.
 *
 *   /validate  — schéma + DocumentValidator, bez čtení databáze.
 *   /preview   — validate + výchozí hodnoty + hledání referencí + částky
 *                spočítané dokladem + validace dokladu pro cílový stav.
 *   /apply     — totéž + uložení v `NestedTransaction` — vlastní transakce,
 *                nebo SAVEPOINT uvnitř transakce volajícího (generátory
 *                dokladů #110, vstup návrhu #166).
 *
 * Applier **nic neodvozuje a nic nezakládá**: záznamy hledá jen přes
 * reference, co payload neuvádí, doplní výchozími hodnotami jako formulář,
 * a hodnoty DPH i zaokrouhlení bere jako záměr. Doklad z papírové podoby
 * (pošta, ISDOC) připraví interpretace — `Interpret\DocumentProposal` z něj
 * udělá hotový doklad a pošle ho sem; applier na jmenném prostoru
 * interpretace nezávisí (D62).
 *
 * Pod vrstvu Document applier nesahá — číslo dokladu, snapshoty, součty
 * a rekapitulaci drží `DocDocument::beforeSave`. Applier jen přeloží
 * canonical → data dokladu a zavolá TableGateway.
 */
class DocumentApplier
{
    public const FORMAT_ID = 'shpd.docs.document';
    public const FORMAT_VERSION = '1';

    private const ACTIVE_STATES = [10, 40, 80];

    /**
     * Kontext dokladu z payloadu — reference, data, registrace DPH, oprava,
     * kurz ({@see PayloadContext}; týž kód čte i interpretace návrhu).
     */
    private readonly PayloadContext $payload;

    /** Společné kroky resolve nad tím, co payload říká ({@see PayloadResolve}). */
    private readonly PayloadResolve $payloadResolve;

    /** Dohledání dokladu podle `source.ref` ({@see SourceRefLookup}). */
    private readonly SourceRefLookup $sourceRefs;

    /**
     * Varování dokladu z posledního úspěšného `apply()` — {@see savedDocumentWarnings()}.
     *
     * @var list<array<string, mixed>>
     */
    private array $savedWarnings = [];

    /** Adaptér výchozích hodnot pokynu — viz applyInstructionDefaults(). */
    private ?InstructionDefaults $instructionDefaults = null;

    /**
     * Pokyn (#166 D44): payload, jak přišel (po normalizaci tvarů) — z něj
     * se skládá odpověď; applier dál pracuje s kopií doplněnou o výchozí
     * hodnoty. Null = `/validate` (statická kontrola nic nedoplňuje).
     *
     * @var array<string, mixed>|null
     */
    private ?array $instructionPayload = null;

    /**
     * Pole, která pokyn doplnil sám — `_resolve.defaults`. Null =
     * `/validate` (klíč se nevypisuje).
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

    /** D6: výpočet částek v náhledu se nepovedl — náhled ukáže údaje z předlohy. */
    public const COMPUTED_UNAVAILABLE_ISSUE = [
        'severity' => 'info',
        'path'     => 'totals',
        'code'     => 'computed_unavailable',
        'message'  => 'Rekapitulaci DPH a součty dokladu nešlo spočítat'
            . ' — náhled ukazuje údaje přečtené z dokladu, ne výsledek výpočtu.',
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

    public function __construct(
        private readonly Connection $db,
        private readonly ConfigRuntime $config,
        private readonly TransactionlessTableGateway $headsGateway,
        private readonly SchemaValidator $schemaValidator,
        private readonly DocumentValidator $documentValidator,
        /** Strana vlastní firmy (`selfParty`) — jediné, co pokyn nehledá referencí. */
        private readonly PartyResolver $partyResolver,
        private readonly UnitResolver $unitResolver,
        private readonly VatCodeResolver $vatCodeResolver,
        private readonly BankAccountResolver $bankAccountResolver,
        VatCodeDerivation $vatCodeDerivation,
        /**
         * Dimenze deníku z objektu `dimensions` (#110 T2) — přirozený klíč →
         * id záznamu. Null = DS bez dimenzí s přirozeným klíčem, objekt se ignoruje
         * (neznámé klíče hlásí DocumentValidator).
         */
        ?DimensionResolver $dimensionResolver = null,
        /**
         * Kurz z lístku pro doklad v cizí měně bez kurzu (#121 D6) — náhled
         * i apply. Null = DS bez modulu kurzů (tabulka chybí): nic se
         * nedohledává, kurz zůstane, jak přišel.
         */
        ?ExchangeRateResolver $exchangeRateResolver = null,
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
        $this->sourceRefs = new SourceRefLookup($db);
    }

    /**
     * Applier nad zdrojem dat — resolvery a gateway hlaviček z prostředí
     * zdroje dat.
     *
     * @param array<string, TableDefinition> $tables aktivní tabulky zdroje dat
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

        return new self(
            db: $db,
            config: $config,
            // Gateway hlaviček nese dispatcher událostí — doklad uložený ve
            // stavu 40 musí spustit DocsHeadsEventHandler (účtování).
            headsGateway: TransactionlessTableGateway::forTable('docs_core_heads', $db, $registry, $config, $dsConfig, $tables, $eventDispatcher),
            schemaValidator: new SchemaValidator(SchemaLoader::default()),
            documentValidator: new DocumentValidator(JournalDimensionSet::fromConfig($config), $tables),
            partyResolver: new PartyResolver($db, new OwnCompanyResolver($db)),
            unitResolver: new UnitResolver($db),
            vatCodeResolver: new VatCodeResolver($vatRateResolver),
            bankAccountResolver: new BankAccountResolver($db),
            vatCodeDerivation: new VatCodeDerivation($vatRateResolver),
            dimensionResolver: new DimensionResolver($db, JournalDimensionSet::fromConfig($config), $tables),
            exchangeRateResolver: isset($tables[ExchangeRateResolver::TABLE_LISTS])
                ? ExchangeRateResolver::create($db, $config, $dsConfig)
                : null,
            references: new ReferenceResolver($db, $tables),
        );
    }

    /**
     * Normalizace hodnot canonicalu (#118 D2): pokyn jen sjednotí **tvary**.
     * Oprava bankovního spojení z redundance (IBAN ↔ číslo účtu) je
     * interpretace předlohy a pokynu nepatří (#166 D19, U5).
     *
     * @param array<string, mixed> $canonical
     * @return array<string, mixed>
     */
    private static function normalizeValues(array $canonical): array
    {
        // Reference (#166 D30, D32): strana, partner a položka zadané jen
        // referencí → objekt s `ref`, aby zbytek applieru četl jeden tvar;
        // `_resolve` pokyn nečte vůbec — blok se zahodí hned tady a volající
        // ho ohlásí ({@see appendResolveIgnoredIssue()}).
        $canonical = CanonicalReferences::withoutResolve(CanonicalReferences::expand($canonical));
        return CanonicalValueNormalizer::normalizeShapes($canonical);
    }

    // ── Výchozí hodnoty pokynu (#166 D40–D44, D48–D50) ──────────────────────

    /**
     * Pokyn: co payload neuvádí, doplní se týmž kódem jako ve formuláři
     * ({@see InstructionDefaults}) — do **pracovní kopie**, se kterou běží
     * zbytek náhledu i uložení. Odpověď se skládá z payloadu, jak přišel,
     * a doplněná pole vyjmenuje `_resolve.defaults`. Hotový doklad z
     * interpretace je úplný — nic se na něm nedoplní.
     *
     * Hlavička jde první — registrace DPH, datum a režim DPH určují kontext,
     * ve kterém se řádku vybírá kód DPH.
     *
     * @param array<string, mixed> $canonical payload po normalizaci tvarů
     * @return array<string, mixed> pracovní kopie
     */
    private function applyInstructionDefaults(array $canonical): array
    {
        $this->defaultUnitIds = [];
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
     * a dostane jeden info nález — volby uživatele patří vstupu návrhu.
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
    private static function ignoresResolve(array $canonical): bool
    {
        return CanonicalReferences::carriesResolve($canonical);
    }

    // ── Hledání záznamů pokynu — jen přes reference (#166 D31) ──────────────

    /**
     * Strana dokladu v pokynu: `ref`, jinak nic — objekt s názvem, IČO nebo
     * DIČ bez reference je chyba `reference_missing` (ne tiché „bez
     * partnera“), prázdný objekt strana bez osoby. Podle údajů z papíru
     * hledá jen interpretace návrhu.
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
    private function resolveAll(array $canonical, array &$issues): array
    {
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

            // Strana vlastní firmy referenci nenese; druhá strana jen přes
            // `ref` ({@see resolveParty()}).
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
            // Pokyn účet partnera nezakládá (#166 D31). Vazbu na účet osoby
            // říká reference `bankAccount.ref` (D61): id = ten účet, výslovné
            // null = bez vazby; chybí-li klíč, vznikne vazba jen přesnou
            // shodou s existujícím účtem osoby. Údaje účtu jdou do sloupců
            // hlavičky vždy.
            $bank = is_array($supplier['bankAccount'] ?? null) ? $supplier['bankAccount'] : [];
            if (array_key_exists('ref', $bank)) {
                if (CanonicalReferences::isSet($bank['ref'])) {
                    $supplierBankResult = $this->payloadResolve->partnerBankReference(
                        $bank['ref'],
                        $supplierPersonId !== null ? (int) $supplierPersonId : null,
                        $this->bankAccountResolver->partnerAccountBelongsTo(...),
                        $issues,
                    );
                }
            } elseif (BankAccountResolver::hasIdentifier($bank)) {
                $bankResult = $this->bankAccountResolver->resolvePartnerBank($bank, $supplierPersonId);
                if ($bankResult->status === ResolveStatus::Matched) {
                    $supplierBankResult = $bankResult->toArray();
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
                // Pokyn (#166 D37, D60): text = přesný systémový kód, číslo
                // = id jednotky; neznámá jednotka je chyba. Zkratky a aliasy
                // zná jen návrh.
                $byId = is_int($row['unit']);
                $unitR = $byId
                    ? $this->unitResolver->resolveId($row['unit'])
                    : $this->unitResolver->resolve((string) $row['unit'], true);
                $rowResolve['unit'] = $unitR->toArray();
                if ($unitR->status === ResolveStatus::NotFound) {
                    $issues[] = [
                        'severity' => 'error',
                        'path'     => "rows.{$idx}.unit",
                        'code'     => 'unit_not_found',
                        'message'  => $byId
                            ? "Jednotka #{$row['unit']} není."
                            : "Jednotka „{$row['unit']}\" není systémový kód jednotky.",
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
            $resolved['supplierBank'] = $supplierBankResult;
        }
        // Partner hlavičky (#166 D30) — jen účetní doklad a typy vázané na
        // pokladnu; u faktur je partnerem strana (nález dává validátor).
        $headPartner = CanonicalReferences::carriesHeadPartner($canonical)
            ? $this->payloadResolve->resolvePartner(CanonicalReferences::PARTNER, $canonical[CanonicalReferences::PARTNER] ?? null, false, $issues)
            : null;
        if ($headPartner !== null) {
            $resolved['partner'] = $headPartner;
            // Pokladní doklad: partnera hlavičky nese i strana podle směru —
            // dvě různé osoby pokyn neuloží.
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

    // ── Public entry points ─────────────────────────────────────────────────

    /**
     * Statická kontrola pokynu: schéma a kontroly payloadu, bez hledání
     * záznamů a bez výchozích hodnot.
     *
     * @param array<string, mixed> $canonical
     */
    public function validate(array $canonical): ApplyResult
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

        // Tvary hodnot (#118 D2) — po schématu, před DocumentValidator.
        $resolveIgnored = self::ignoresResolve($canonical);
        $canonical = self::normalizeValues($canonical);
        $validatorIssues = $this->documentValidator->validate($canonical);
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
     * Náhled pokynu: co by `apply()` uložil — nalezené reference, doplněné
     * výchozí hodnoty, částky spočítané dokladem (`computed`) a nálezy
     * včetně validace dokladu pro cílový stav. Nic nezapisuje.
     *
     * @param array<string, mixed> $canonical
     */
    public function preview(array $canonical): ApplyResult
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

        // 1b. Tvary hodnot (#118 D2) — náhled vrací normalizovaný canonical.
        $resolveIgnored = self::ignoresResolve($canonical);
        $canonical = self::normalizeValues($canonical);
        // 1c. Výchozí hodnoty, které payload neuvádí (#166 D40) — dál běží
        //     vše nad doplněnou kopií, stejně jako v apply.
        $canonical = $this->applyInstructionDefaults($canonical);

        // 2. Semantic checks + resolve. Both contribute to _resolve.issues.
        $issues = $this->documentValidator->validate($canonical);
        self::appendResolveIgnoredIssue($resolveIgnored, $issues);
        $this->payload->appendAuthorIssue($canonical, $issues);
        $this->appendVatHeaderIssues($canonical, $issues);
        $this->payload->appendVatRateDateIssue($canonical, $issues);
        $this->appendRecapSourceIssue($canonical, $issues);
        $this->payload->appendExchangeRateIssue($canonical, $issues);
        $this->payload->appendCorrectionIssues($canonical, $issues);
        $this->payload->appendVatRegistrationIssue($canonical, $issues);
        // Idempotence (#166 U4): doklad se stejným `source.ref` už existuje —
        // apply ho vrátí beze změny. Náhled to říká, zbytek běží jako obvykle.
        $this->sourceRefs->appendIssue($canonical, $issues);
        $resolved = $this->withVatBlocks($canonical, $this->resolveAll($canonical, $issues));
        // 3. Rekapitulace a součty, jak skončí na dokladu — stejným kódem
        //    jako uložení (tasks/exchange-preview-vat-recompute.md D2–D4, D6).
        $resolved['computed'] = $this->computePreviewAmounts($canonical, $resolved, $issues);
        // 4. Validace dokladu pro cílový stav (#166 D21, U6) — nález je vidět
        //    před uložením.
        $this->appendDocumentValidation($canonical, $resolved, $issues);
        $enriched = $this->withResolve($canonical, $resolved, $issues);

        // preview always succeeds even with errors — client renders the
        // payload and decides what to do.
        return ApplyResult::ok($enriched);
    }

    /**
     * Náhled: rekapitulace DPH, součty a sazby řádků, **které skončí na
     * dokladu** — spočítané `DocDocument::computeAmounts()`, tedy stejným
     * kódem jako `beforeSave()` při apply
     * (tasks/exchange-preview-vat-recompute.md D2–D4, D6; #87 task A).
     *
     * Vstup staví tentýž `transform()` jako apply, s náhledovým plánem
     * ({@see previewPlan}): kódy DPH a jednotky z čerstvého resolve, bez
     * řady. Instanci dokumentu dává gateway podle typu dokladu, takže platí
     * přetížení podtříd (účetní doklad sčítá z řádků, rekapitulaci nemá).
     *
     * `totals` pokynu jsou kontrolní součet (#166 D27): každé uvedené pole
     * proti výpočtu, neshoda je chyba `totals_check_failed`
     * ({@see TotalsCheck}). Porovnání s částkou z papíru
     * (`computed_total_mismatch`) dělá vstup návrhu nad tímto blokem.
     *
     * D6: výjimka náhled neshodí — `null` + info `computed_unavailable`
     * (zalogováno).
     *
     * `rows` (#97): cena za jednotku a cena položkových řádků, jak je
     * spočítal doklad, klíčované indexem řádku payloadu (`index`).
     *
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $resolved  Výstup {@see resolveAll}.
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     * @return array{recapSource: string, recapFallback: ?string, vatRecap: list<array<string, mixed>>, totals: array<string, float>, rows: list<array{index: int, unitPrice: ?float, totalPrice: ?float}>}|null
     *         Blok `_resolve.computed` (D3) v měně dokladu; domácí měna se nevrací.
     */
    private function computePreviewAmounts(array $canonical, array $resolved, array &$issues): ?array
    {
        $unavailable = static function (array &$issues): void {
            $issues[] = self::COMPUTED_UNAVAILABLE_ISSUE;
        };
        $plan = $this->previewPlan($resolved);
        try {
            $data = $this->transform($canonical, $plan, null);
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

        $recapSource = $this->resolveRecapSource($canonical);
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
                // určil computeAmounts, jinak výslovný mód z payloadu.
                'totalRoundingMode' => (int) ($data['total_rounding_mode'] ?? 0),
            ],
            'rows' => $computedRows,
        ];

        // `totals` jsou kontrolní součet (#166 D27) — náhled hlásí týž nález,
        // kterým by uložení skončilo, jako chybu; varování o nepoužitém módu
        // zaokrouhlení (D26) jako apply.
        foreach (TotalsCheck::mismatches($canonical, $data) as $issue) {
            $issues[] = $issue;
        }
        $ignored = TotalsCheck::ignoredMode($canonical, $data);
        if ($ignored !== null) {
            $issues[] = $ignored;
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
     * Data skládá stejnou cestou jako apply — plán z nalezených referencí
     * ({@see PayloadResolve::plan()}), {@see resolveSaveTargets()} bez
     * zakládání řady a {@see transform()} — a validuje **před**
     * `computeAmounts()`, v pořadí `TableGateway::saveDocument()`. Chyba
     * dohledání pokladny, řady nebo vlastního účtu je nález s týmž kódem,
     * jaký vrátí apply.
     *
     * Co náhled posoudit nemůže, nehlásí: nálezy na referenci, která se
     * nenašla (partner, položka řádku a její pohyb — chybu už nese nález),
     * a na řadě, kterou apply teprve zkusí založit. Zámky záznamu
     * (`TableGateway::enforceLock`) se v náhledu nevyhodnocují.
     *
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $resolved Výstup {@see resolveAll}.
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    private function appendDocumentValidation(array $canonical, array $resolved, array &$issues): void
    {
        // Plán pokynu je projekce nalezených referencí; jeho nálezy náhled
        // nehlásí — stav referencí nesou bloky `_resolve`.
        $discarded = [];
        $plan = PayloadResolve::plan($resolved, $discarded);

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
            $data = $this->transform($canonical, $plan, $targets['numberSeriesId']);
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
     * Sloupce dokladu, jejichž nález náhled pokynu nehlásí
     * ({@see appendDocumentValidation()}): řada, kterou náhled nedohledal
     * (apply ji teprve zkusí založit); partner a položka řádku, které payload
     * nese, ale jejichž reference se nenašla — chybu už nese nález.
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
     * Plán pro výpočet částek v náhledu (D2): jen to, co výpočet potřebuje
     * — kódy DPH, předmět plnění a jednotky z čerstvého resolve; strany,
     * položky a účty `null`. Číselná řada je parametr `transform()` (null).
     *
     * @param array<string, mixed> $resolved  Výstup {@see resolveAll}.
     * @return array<string, mixed>
     */
    private function previewPlan(array $resolved): array
    {
        $plan = PayloadResolve::basePlan($resolved, []);
        $plan['resolvedHeadPartner'] = null;
        $plan['resolvedHeadDimensions'] = [];
        foreach (array_keys($plan['resolvedRowItems']) as $i) {
            $plan['resolvedRowAccounts'][$i] = null;
            $plan['resolvedRowPartners'][$i] = null;
            $plan['resolvedRowDimensions'][$i] = [];
        }
        return $plan;
    }

    /**
     * Uloží doklad, jak ho payload říká: reference, výchozí hodnoty jako ve
     * formuláři, validace a výpočet `DocDocument`. Nic neodvozuje a nic
     * nezakládá — doklad z papírové podoby připraví interpretace
     * (`Interpret\DocumentProposal`) a pošle sem hotový.
     *
     * @param array<string, mixed> $canonical
     */
    public function apply(array $canonical): ApplyResult
    {
        $this->instructionPayload = null;
        $this->appliedDefaults = null;
        $this->defaultUnitIds = [];
        $this->savedWarnings = [];

        // Přegenerovat v místě (#110 D24, Q2): cílový koncept se nahradí
        // z payloadu.
        $replaceId = self::replaceConceptId(is_array($canonical['applyOptions'] ?? null) ? $canonical['applyOptions'] : []);

        // 0. Idempotence pokynu (#166 D46): živý doklad se stejným
        //    `source.kind` + `source.ref` se vrací beze změny — dřív, než se
        //    obsah posuzuje, aby opakování po výpadku spojení prošlo i poté,
        //    co se ve zdroji dat něco změnilo. Obsah se neporovnává. Při
        //    přegenerování se cílový koncept z dohledání vylučuje.
        $sourceKey = SourceRef::fromCanonical($canonical);
        if ($sourceKey !== null) {
            $existing = $this->sourceRefs->find($sourceKey, $replaceId);
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
        // Tvary hodnot (#118 D2) — i v importním módu; na hlavičku jdou
        // normalizované hodnoty.
        $resolveIgnored = self::ignoresResolve($canonical);
        $canonical = self::normalizeValues($canonical);
        // Výchozí hodnoty, které payload neuvádí (#166 D40) — po normalizaci,
        // před kontrolami; stejné místo jako v náhledu.
        $canonical = $this->applyInstructionDefaults($canonical);
        $validatorIssues = $this->documentValidator->validate($canonical);
        self::appendResolveIgnoredIssue($resolveIgnored, $validatorIssues);
        $this->payload->appendAuthorIssue($canonical, $validatorIssues);
        $this->appendVatHeaderIssues($canonical, $validatorIssues);
        $this->payload->appendVatRateDateIssue($canonical, $validatorIssues);
        $this->appendRecapSourceIssue($canonical, $validatorIssues);
        $this->payload->appendExchangeRateIssue($canonical, $validatorIssues);
        $this->payload->appendCorrectionIssues($canonical, $validatorIssues);
        $this->payload->appendVatRegistrationIssue($canonical, $validatorIssues);

        // 3. Hledání záznamů podle referencí (čerstvé čtení databáze).
        $resolved = $this->withVatBlocks($canonical, $this->resolveAll($canonical, $validatorIssues));

        // 4. Plán uložení: projekce nalezených referencí.
        $plan = PayloadResolve::plan($resolved, $validatorIssues);

        $enriched = $this->withResolve($canonical, $resolved, $validatorIssues);

        if ($plan['errorCode'] !== null) {
            return ApplyResult::error(
                $plan['errorCode'],
                $plan['errorMessage'] ?? 'Doklad nelze uložit.',
                $enriched,
                statusCode: 422,
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

        // 6–9. Transactional save — NestedTransaction: vlastní transakce,
        //      nebo SAVEPOINT uvnitř cizí (periodická fakturace i vstup
        //      návrhu volají apply() ve své transakci, #110, #166 D53).
        //      Neúspěch closure = rollback vlastní práce; vnější transakce
        //      zůstává volajícímu.
        //
        //      Souběh pokynů se stejným `source.ref` (#166 U3): pojmenovaný
        //      zámek podle klíče držený od chvíle před transakcí do jejího
        //      commitu; první dotaz transakce doklad dohledá znovu (čerstvý
        //      snímek), takže druhý pokyn po uvolnění zámku najde doklad
        //      prvního. Zámek uvolňuje `finally` níž.
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
            $savedDocId = NestedTransaction::run($this->db, function () use ($canonical, $plan, &$validatorIssues, &$savedValidation, $numberSeriesId, $replaceId, $docTypeCode, $sourceKey): int {
            // Idempotence pod zámkem (#166 U3): doklad mohl vzniknout mezi
            // prvním dohledáním a zámkem.
            if ($sourceKey !== null) {
                $existing = $this->sourceRefs->find($sourceKey, $replaceId);
                if ($existing !== null) {
                    throw new SourceRefExistsException($existing);
                }
            }

            // Přegenerovat (Q2): zámek cílového konceptu — zavře závod
            // „koncept mezitím potvrzený“ (ApplyAbortedException → 409/422).
            $this->lockReplaceTarget($replaceId, $docTypeCode);

            // Transform canonical → internal $data.
            $data = $this->transform($canonical, $plan, $numberSeriesId);
            if ($replaceId !== null) {
                $data = $this->applyReplaceConcept($data, $replaceId, $validatorIssues);
            }

            // Save doc head + rows + vat_recap through DocDocument.
            $result = $this->headsGateway->saveDocument($data);
            if (!$result->isSuccess()) {
                // Doklad neprošel validací DocDocument (#166 D21): chyba apply
                // s nálezy dokladu, ne internal_error. Výjimka vrátí transakci;
                // nálezy z ní poskládá catch níž.
                $validation = $result->getValidation();
                if ($result->getErrorMessage() === null && $validation !== null && !$validation->isValid()) {
                    throw new DocumentValidationFailedException($validation);
                }
                throw new \RuntimeException('Save failed: ' . ($result->getErrorMessage() ?? 'unknown error'));
            }
            $savedValidation = $result->getValidation();

            // Kontrolní součet (#166 D4, D27): `totals` pokynu proti tomu, co
            // doklad skutečně spočítal a uložil. Neshoda vrátí transakci —
            // doklad ani číslo z řady nezůstanou.
            $this->checkDeclaredTotals($canonical, $result->getData(), $validatorIssues);

            return (int) $result->getData()['id'];
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
            // D21 (#166): nálezy dokladu do `_resolve.issues` s cestou
            // v canonicalu; nic se neuložilo.
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

        // Varování dokladu z úspěšného uložení (#166 U6).
        if ($savedValidation !== null) {
            $rowIndices = $this->transformedRowIndices(is_array($canonical['rows'] ?? null) ? $canonical['rows'] : [], $plan);
            $this->savedWarnings = DocumentIssuePaths::warnings($savedValidation, $canonical, $rowIndices);
            foreach ($this->savedWarnings as $issue) {
                $validatorIssues[] = $issue;
            }
        }

        $finalCanonical = $this->withResolve($canonical, $resolved, $validatorIssues);
        $finalCanonical['savedDocId'] = $savedDocId;

        return ApplyResult::ok($finalCanonical, $savedDocId);
    }

    // ── Cíle uložení: pokladna, řada, vlastní účet ──────────────────────────

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

    // ── Transform canonical → internal $data ───────────────────────────────

    /**
     * Payload pokynu → data dokladu pro `DocDocument`. Jen překládá: co
     * payload říká (po doplnění výchozích hodnot) a co se našlo referencí.
     *
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $plan {@see PayloadResolve::plan()}
     * @param ?int $numberSeriesId Pre-resolved by apply() ({@see resolveSaveTargets()}).
     * @return array<string, mixed>
     */
    private function transform(array $canonical, array $plan, ?int $numberSeriesId): array
    {
        $docType = $this->payload->mapDocType($canonical);
        $selfParty = $canonical['selfParty'] ?? null;
        $targetDocState = (int) ($canonical['applyOptions']['targetDocState'] ?? 10);
        // Nedaňový typ (docTypes[].tax_document: false, #79 D1): DUZP/DPPD
        // kanonický doklad nenese — ignorují se už tady (DocDocument je
        // při uložení stejně vynuluje).
        $taxDocument = DocTypes::isTaxDocument($this->config, $docType);

        // Účetní doklad (cmnbkp): hlavičkový partner je nepovinný a žije per
        // řádek; bere se z pole `partner` (resolvedHeadPartner), bez selfParty
        // resolution. Faktura: partner = ta druhá strana. Pokladní doklad /
        // prodejka: nepovinný — pole `partner`, jinak strana z payloadu.
        $partyPartner = match ($selfParty) {
            'customer' => $plan['resolvedSupplier'] ?? null,
            'supplier' => $plan['resolvedCustomer'] ?? null,
            default    => $plan['resolvedSupplier'] ?? ($plan['resolvedCustomer'] ?? null),
        };
        $isCashDeskBound = $this->payload->isCashDeskBoundDocType($docType);
        $partnerId = match (true) {
            $docType === 'cmnbkp' => $plan['resolvedHeadPartner'] ?? null,
            $isCashDeskBound      => $plan['resolvedHeadPartner'] ?? $partyPartner,
            default               => $partyPartner,
        };
        // Směr pokladního dokladu (jen cashDocument; validátor hlídá 1/2).
        $cashDir = isset($canonical['cashDirection']) ? (int) $canonical['cashDirection'] : 0;
        $totalRoundingAuto = $this->resolveTotalRoundingAuto($canonical, $docType, $cashDir);
        $totalRoundingMode = RoundingModeNames::totalCode($canonical['totals']['totalRoundingMode'] ?? null);
        // Ruční plátce (#72): poslaná strana = partner_balance s příznakem
        // ručního zadání, aby ho odvození (terminál / dopravce) nepřepsalo.
        $balancePartyId = $plan['resolvedBalanceParty'] ?? null;

        // Kontext DPH pokynu (#166 D19, D20): nic se neodvozuje — režim,
        // místo plnění a registrace jsou záměr z payloadu. Canonical vč.
        // totals zůstává nedotčený a DocDocument si base/VAT/totals přepočítá
        // sám.
        $vatCtx = $this->payload->vatContext($canonical);
        $vatRegistrationId = $vatCtx['ownRegistrationId'];
        $vatMode = self::VAT_MODE_MAP[$this->effectiveVatMode($canonical)['value']];
        $vatPlace = self::VAT_PLACE_MAP[(string) ($vatCtx['place'] ?? 'domestic')] ?? 0;
        // Autorita rekapitulace + řádky k převzetí (R3/I4/I7). Přepočítanou
        // rekapitulaci si DocDocument spočítá z řádků sám, `vatRecap` se pak
        // do payloadu nedává — prázdný child set by u nového dokladu nic
        // nezměnil, ale u převzaté je to jediná cesta, jak se data dostanou
        // do docs_core_vat_recap.
        $recapSource = $this->resolveRecapSource($canonical);
        $calcSource = self::VAT_CALC_SOURCE_MAP[(string) ($canonical['vat']['calcSource'] ?? 'header')] ?? 0;
        $csMode = self::CS_MODE_MAP[(string) ($canonical['vat']['controlStatementMode'] ?? 'auto')] ?? 0;
        // Způsob úhrady z payloadu. Bez hodnoty ho pokyn dostal mezi
        // výchozími hodnotami ({@see applyInstructionDefaults()}); když tu
        // chybí, je záměrně prázdný a platí výchozí hodnota sloupce.
        $paymentMethod = self::PAYMENT_METHOD_MAP[(string) ($canonical['payment']['method'] ?? '')] ?? null;

        // Účetní datum, které payload neuvádí: DocDocument::beforeSave
        // (applyDateDefaults) by ho doplnil, ale jeho validate() běží dřív
        // a prázdné účetní datum odmítne — proto se doplní už tady týmž
        // pravidlem (AccountingDateRule, #103 D78: přijatá faktura DUZP,
        // jinak datum vystavení).
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
            'partner_bank'         => $plan['resolvedSupplierBank'] ?? null,
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
            // DocDocument::computeAmounts (klíč vypadne přes array_filter
            // níže). Jinak platí výslovný `totals.totalRoundingMode` (#166
            // D25, D26); bez něj je doklad na haléře — mód se neodhaduje
            // (D19), odhad z čísel předlohy dělá interpretace.
            'total_rounding_auto'  => $totalRoundingAuto ? 1 : 0,
            'total_rounding_mode'  => match (true) {
                $totalRoundingAuto          => null,
                $totalRoundingMode !== null => $totalRoundingMode,
                default                     => RoundingModes::ON_CENT,
            },
            // Zaokrouhlení DPH (#166 D25): záměr z payloadu, odhad
            // neexistuje; null → klíč vypadne a platí default 0.
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
            'rows'                 => $this->transformRows($canonical['rows'] ?? [], $plan, $vatCtx['withoutVat']),
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

    // ── DPH pokynu: hodnoty jsou záměr (#166 D19, D20) ──────────────────────

    /**
     * Režim výpočtu DPH, který skončí na dokladu — jediné místo pro
     * transform() i blok `_resolve.vat.mode`. Režim je záměr z payloadu:
     * bez korekce ze součtů a bez přepnutí kvůli samovyměření (odvození
     * z předlohy dělá interpretace návrhu).
     *
     * @param array<string, mixed> $canonical
     * @return array{value: string, source: string, reason: ?string, pinned: bool, auto: string}
     */
    private function effectiveVatMode(array $canonical): array
    {
        ['mode' => $value, 'source' => $source] = $this->payload->declaredVatMode($canonical);
        // Režim doplněný výchozí hodnotou není hodnota z payloadu.
        $source = $this->isDefaulted('vat.mode') ? 'default' : $source;
        return ['value' => $value, 'source' => $source, 'reason' => null, 'pinned' => false, 'auto' => $value];
    }

    /**
     * Hlavičkové nálezy DPH: neznámé `vat.mode` / `vat.place` (schéma má
     * pole jako enum — pojistka pro volání mimo schema validaci).
     *
     * @param array<string, mixed> $canonical
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    private function appendVatHeaderIssues(array $canonical, array &$issues): void
    {
        foreach ([PayloadContext::unknownVatModeIssue($canonical), PayloadContext::unknownVatPlaceIssue($canonical)] as $issue) {
            if ($issue !== null) {
                $issues[] = $issue;
            }
        }
    }

    /**
     * `_resolve.rows[].vatCode` jednoho řádku: kód z payloadu se jen ověří
     * proti číselníku ({@see PayloadResolve::rowVatCode()}). Doklad Bez DPH
     * kódy nenese — neověřují se.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $vatCtx {@see PayloadContext::vatContext()}
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
        if ($vatCtx['withoutVat']) {
            return null;
        }
        return $this->payloadResolve->rowVatCode($idx, $row, $vatCtx, $vatCountry, $supplierCountry, $issues, null);
    }

    /**
     * Zdroj rekapitulace pro `docs_core_heads.vat_recap_source` a řádky
     * rekapitulace k převzetí: `vat.recapSource` je záměr
     * ({@see instructionRecapSource()}). Doklad Bez DPH — ten, který to říká
     * (`vat.mode` none) — rekapitulaci nemá.
     *
     * @param array<string, mixed> $canonical
     * @return array{source: int, recap: array<int, array<string, mixed>>, fallback: ?string,
     *     issues?: list<array{severity: string, path: string, code: string, message: string}>}
     */
    private function resolveRecapSource(array $canonical): array
    {
        $vatCtx = $this->payload->vatContext($canonical);
        if ($vatCtx['withoutVat']) {
            return ['source' => 0, 'recap' => [], 'fallback' => null];
        }
        return $this->instructionRecapSource($canonical, $vatCtx);
    }

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
     * Nálezy k převzaté rekapitulaci (chybějící řádky, kód, neznámý kód) —
     * pokyn rekapitulaci z řádků nepřepočítává potichu, je to chyba.
     *
     * @param array<string, mixed> $canonical
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    private function appendRecapSourceIssue(array $canonical, array &$issues): void
    {
        foreach ($this->resolveRecapSource($canonical)['issues'] ?? [] as $issue) {
            $issues[] = $issue;
        }
    }

    // ── Zaokrouhlení a řádky ────────────────────────────────────────────────

    /**
     * Příznak automatického zaokrouhlení nového dokladu (#73 D8, U9): jen
     * doklad, kde automatika platí ({@see TotalRoundingAuto::applies()} —
     * prodejní typ, u pokladního dokladu příjem). Explicitní
     * `totals.totalRoundingAuto` vyhrává (dump → seed zachová stav dokladu).
     * Bez něj je zapnutá všude, kde platí (#166 U4, D26, D50) — na `totals`
     * nezáleží, mód při vypnuté automatice říká `totals.totalRoundingMode`.
     * Chybějící klíč = výchozí hodnota jako ve formuláři (`DocHeadDefaults`);
     * výslovné `null` = záměrně bez automatiky.
     *
     * @param array<string, mixed> $canonical
     */
    private function resolveTotalRoundingAuto(array $canonical, string $docType, int $cashDir): bool
    {
        if (!TotalRoundingAuto::applies($this->config, $docType, $cashDir)) {
            return false;
        }
        $totals = $canonical['totals'] ?? null;
        $explicit = is_array($totals) ? ($totals['totalRoundingAuto'] ?? null) : null;
        if (is_bool($explicit)) {
            return $explicit;
        }
        return !(is_array($totals) && array_key_exists('totalRoundingAuto', $totals))
            && $this->payload->headDefaults()->totalRoundingAuto($docType);
    }

    /**
     * @param array<int, mixed> $rows
     * @param array<string, mixed> $plan
     * @param bool $withoutVat Doklad Bez DPH (`vat.mode` none): kód ani sazba
     *        se na řádky nepropisují.
     * @return array<int, array<string, mixed>>
     */
    private function transformRows(array $rows, array $plan, bool $withoutVat = false): array
    {
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
            $itemId = $plan['resolvedRowItems'][$i] ?? null;
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

            $out[] = array_filter([
                'row_kind'        => self::ROW_KIND_MAP[(string) ($row['rowKind'] ?? 'item')] ?? 1,
                // Pohyb řádku (docs.core.rowOperations) z payloadu — bez hodnoty
                // ho pokyn dostal mezi výchozími hodnotami; řádek bez něj je
                // záměrně prázdný (#166 D50).
                'operation'       => ($row['operation'] ?? null) ?: null,
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
        return SourceRefLookup::replaceConceptId($applyOptions);
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

    /**
     * Odpověď opakovaného apply: payload, jak přišel, `savedDocId`
     * existujícího dokladu a `_resolve.summary.status = "alreadyApplied"`.
     * Společný tvar idempotence podle `source.ref` i podle zprávy pošty
     * (tu hlídá vstup návrhu).
     *
     * @param array<string, mixed> $canonical
     * @param list<array<string, mixed>> $issues
     */
    public static function alreadyAppliedResult(array $canonical, int $existingId, array $issues = []): ApplyResult
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

    /** Jak dlouho čeká pokyn na souběžný pokyn se stejným klíčem (s). */
    private const SOURCE_LOCK_TIMEOUT = 10;

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
        $issue = SourceRefLookup::issue($canonical, $existing);
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
     * Varování, která doklad ohlásil při posledním **úspěšném** `apply()`
     * (#166 U6) — tatáž, jaká nese odpověď v `_resolve.issues`. Pro
     * volajícího, který z odpovědi pokynu skládá vlastní (vstup návrhu:
     * varování uloženého dokladu ukazuje review, odpověď použití návrhu je
     * nenese).
     *
     * @return list<array<string, mixed>>
     */
    public function savedDocumentWarnings(): array
    {
        return $this->savedWarnings;
    }

    // ── Idempotence a zámek pro volajícího s vlastní transakcí ──────────────

    /**
     * Odpověď za doklad, který se stejným `source.ref` už existuje — pro
     * volajícího, který `apply()` balí do vlastní transakce (vstup návrhu)
     * a potřebuje idempotenci vyhodnotit dřív, než cokoli založí. Null =
     * doklad není (nebo payload klíč nenese).
     *
     * @param array<string, mixed> $canonical payload, jak přišel
     */
    public function existingBySource(array $canonical): ?ApplyResult
    {
        $this->instructionPayload = null;
        $this->appliedDefaults = null;
        $existing = $this->sourceRefs->existingFor($canonical);
        return $existing !== null ? $this->existingBySourceRef($canonical, $existing) : null;
    }

    /**
     * Zámek souběhu podle `source.ref` pro volajícího s vlastní transakcí:
     * drží ho od chvíle před svou transakcí do jejího commitu (zámek, který
     * si bere `apply()` sám, se uvnitř cizí transakce uvolní před commitem).
     * Zámek je pro jedno spojení opakovatelný — `apply()` uvnitř projde.
     *
     * @param array<string, mixed> $canonical
     * @return bool false = zámek drží jiný pokyn (`source_ref_busy`); true
     *         i pro payload bez klíče
     */
    public function lockSource(array $canonical): bool
    {
        $key = SourceRef::fromCanonical($canonical);
        return $key === null || $this->acquireSourceLock($key);
    }

    /** @param array<string, mixed> $canonical */
    public function unlockSource(array $canonical): void
    {
        $key = SourceRef::fromCanonical($canonical);
        if ($key !== null) {
            $this->releaseSourceLock($key);
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
     * Efektivní hlavička DPH a nabídka kódů do `_resolve`
     * ({@see PayloadResolve::vatBlocks()}).
     *
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $resolved  Výstup {@see resolveAll}.
     * @return array<string, mixed>
     */
    private function withVatBlocks(array $canonical, array $resolved): array
    {
        return $this->payloadResolve->vatBlocks(
            $canonical,
            $resolved,
            $this->payload->vatContext($canonical),
            $this->effectiveVatMode($canonical),
        );
    }

    /**
     * Indexy canonicalu řádků, které {@see transformRows()} převede, v pořadí
     * výstupu ({@see PayloadResolve::keptRowIndices()}).
     *
     * @param array<int, mixed> $rows
     * @param array<string, mixed> $plan
     * @return list<int>
     */
    private function transformedRowIndices(array $rows, array $plan): array
    {
        return PayloadResolve::keptRowIndices($rows, $plan['rowSkips'] ?? []);
    }

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
        $resolve['summary'] = PayloadResolve::summary([], $resolve['issues']);
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
        // Pokyn odpovídá payloadem, jak přišel (#166 D44) — applier pracoval
        // s kopií doplněnou o výchozí hodnoty; která pole doplnil, říká
        // `_resolve.defaults` (cesta a hodnota).
        $canonical = $this->instructionPayload ?? $canonical;
        $resolve = $resolved + ['issues' => $issues];
        if ($this->appliedDefaults !== null) {
            $resolve['defaults'] = $this->appliedDefaults;
        }
        $resolve['summary'] = PayloadResolve::summary($resolved, $issues);
        // Auditní blok oprav hodnot (#118 D14) — zapsal ho normalizátor při
        // /result nebo v tomto volání; čerstvý resolve ho nezná, přenáší se.
        $repairs = $canonical['_resolve'][CanonicalValueNormalizer::REPAIRS_KEY] ?? null;
        if (is_array($repairs) && $repairs !== []) {
            $resolve[CanonicalValueNormalizer::REPAIRS_KEY] = $repairs;
        }
        $canonical['_resolve'] = $resolve;
        return $canonical;
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
}

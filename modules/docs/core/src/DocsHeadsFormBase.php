<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

use Shipard\Core\Form\EnumOptionsHelper;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormHeaderInfo;
use Shipard\Core\Form\FormTab;
use Shipard\Core\Form\RecalculateResult;
use Shipard\Core\Form\SubtableCellFormatter;
use Shipard\Core\Form\TabBuilder;
use Shipard\Core\Form\TableForm;
use Shipard\Core\Settings\SettingsStore;

/**
 * Abstraktní base formulář nad `docs_core_heads`.
 *
 * Drží společnou logiku pro všechny typy dokladů: build tabů, recalculate
 * cascade (partner → adresa/banka, issue_date → splatnost), resolve options
 * z DB / cfgItem, render rekapitulace DPH a fakturačních snapshotů.
 *
 * Per-typ subclassy (`DocsHeadsForm`, `ReceivedInvoiceForm`) přepisují
 * titulky modalu přes `getFormTitle()` / `getNewFormTitle()` a vlastní
 * `buildHeaderTab()` / `buildExtraTabs()`. Rodiny typů se společným layoutem
 * mají mezistupeň: `IssuedInvoiceFormBase` (FVB `IssuedInvoiceForm` + FVZ
 * `ProformaOutForm`), `CashDeskFormBase` (pokladní doklad + prodejka).
 *
 * Tabbed layout:
 *   - basic       — header v 8 sekcích
 *   - rows        — subtable referencující DocRowsForm
 *   - recap       — pre-rendered VAT recap HTML (vždy)
 *   - snapshots   — supplier/customer snapshoty (jen když jsou vyplněné)
 *   - notes       — interní + on-document poznámky
 *   - attachments
 *   - + extra taby z `buildExtraTabs()` (např. FPB „Nastavení“)
 */
abstract class DocsHeadsFormBase extends TableForm
{
    /**
     * Hint u selectu `vat_calc_source` — sdílený hlavičkou i per-typ
     * formuláři (FPB má select v hlavičce, FVB v tabu Nastavení).
     * Vysvětluje volbu, kterou uživatel skoro nikdy nemění: default je
     * norma, druhá možnost je historický režim (docs/vat-calculation.md § 3).
     */
    protected const VAT_CALC_SOURCE_HINT =
        'Z hlavičky = daň ze součtu řádků v sazbě (norma). '
        . 'Z řádků = součet řádkových daní (historický režim).';

    /**
     * Ruční zařazení do kontrolního hlášení (`cs_mode`, extension
     * economy.vat, #77): automatika = limit 10 000 Kč vč. daně; ruční režim
     * je pro opakovaná či dílčí plnění a pro doklady mimo hlášení.
     */
    protected const CS_MODE_HINT =
        'Automaticky = podle limitu 10 000 Kč vč. daně. Ručně u opakovaných '
        . 'či dílčích plnění (vždy jednotlivě) nebo u dokladu mimo hlášení.';

    /**
     * Hint u selectu `vat_recap_source` — vysvětluje, co znamená převzít
     * rekapitulaci (docs/vat-calculation.md § 5).
     */
    protected const VAT_RECAP_SOURCE_HINT =
        'Přepočítaná = vzniká z řádků při každém uložení. '
        . 'Převzatá = rekapitulace z dokladu, uložení ji nepřepočítá; '
        . 'po uložení ji jde upravit v tabu Rekapitulace DPH.';

    /** Per-instance cache — viz vatAgendaDisabled(). */
    private ?bool $vatAgendaDisabled = null;

    /** Per-instance cache — viz homeCurrency(). */
    private ?string $homeCurrency = null;

    /**
     * True, když je DS vědomě nastavený jako neplátce
     * (`economy.vatAgenda === false`). Řídí JEN default nového dokladu
     * a skrytí sekce „DPH" u dokladů bez DPH — renderování existujících
     * dat řídí vat_mode dokladu (ds-setup.md D10), proto se přes tenhle
     * příznak NIKDY nepřepojuje $hasVat. Nerozhodnutý klíč (null) se
     * chová jako dnešek, ne jako false.
     */
    protected function vatAgendaDisabled(): bool
    {
        if ($this->vatAgendaDisabled === null) {
            $this->vatAgendaDisabled = $this->db !== null
                && (new SettingsStore($this->db))->get('economy.vatAgenda') === false;
        }
        return $this->vatAgendaDisabled;
    }

    /**
     * Domácí měna DS ze settings `economy.homeCurrency` (ds-setup.md §5.2).
     * Nerozhodnutý klíč (null) → 'czk', tedy dnešní chování — nerozhodnuto
     * nesmí měnit sémantiku existujících dokladů. Řídí JEN default nového
     * dokladu; existující data nesou měnu ve sloupcích.
     */
    protected function homeCurrency(): string
    {
        if ($this->homeCurrency === null) {
            $value = $this->db !== null
                ? (new SettingsStore($this->db))->get('economy.homeCurrency')
                : null;
            $this->homeCurrency = is_string($value) && $value !== '' ? $value : 'czk';
        }
        return $this->homeCurrency;
    }

    /**
     * HTTP cesta nového dokladu (GET /meta bez id): FormController před tímhle
     * hookem předvyplní column defaults ze schématu a klientský prefill
     * (`defaults[...]`); mutace odsud se propíší do response `data`. Naproti
     * tomu applyClientDefaults běží nad kopií a řídí jen renderování
     * (issue #24 A, #60). Cokoli, co má uživatel vidět předvyplněné hned po
     * otevření modalu, patří sem.
     *
     * Pořadí je podstatné: registrace DPH se rozhoduje podle vat_mode,
     * subclass (účetní doklad) ho proto nastaví PŘED parent::. Explicitní
     * hodnota v datech (import, kopie dokladu, prefill) vždy vyhrává.
     */
    public function applyNewRecordDefaults(array &$data): void
    {
        // 1. Neplátce (economy.vatAgenda === false): přepisuje právě a jen
        //    schéma default vat_mode = 1.
        if ($this->vatAgendaDisabled() && (int) ($data['vat_mode'] ?? 1) === 1) {
            $data['vat_mode'] = 0;
        }
        // 2. Datum vystavení = dnes (#24 A.1) a z něj Účetní datum + DUZP,
        //    stejně jako recalculate('issue_date'). Recalculate běží jen při
        //    změně pole; kdo předvyplněné datum nechá být, by bez tohohle
        //    narazil na povinné Účetní datum ve validate() (#24 B, D6).
        //    DUZP jen u daňového typu — nedaňový ho nemá (#79 D1).
        if (empty($data['issue_date'])) {
            $data['issue_date'] = date('Y-m-d');
        }
        if (empty($data['accounting_date'])) {
            $data['accounting_date'] = $data['issue_date'];
        }
        if (empty($data['vat_duzp']) && $this->isTaxDocument($data)) {
            $data['vat_duzp'] = $data['issue_date'];
        }
        // 3. Registrace DPH: první podle country, id — totéž pořadí, jaké
        //    uživatel vidí v roletce. Jen u dokladu s DPH; vat_mode = 0 je
        //    platná hodnota, proto (int) a ne empty().
        if ((int) ($data['vat_mode'] ?? 1) !== 0 && empty($data['vat_registration'])) {
            $options = $this->resolveVatRegistrationOptions();
            if ($options !== []) {
                $data['vat_registration'] = (int) $options[0]['value'];
            }
        }
        // 4. Náš bankovní účet: is_default ve stavu V pořádku, bez ohledu na
        //    měnu dokladu (FPB v EUR z českého účtu je běžná). Jen formuláře,
        //    které pole renderují (základní, FVB, FPB).
        if ($this->newRecordUsesBankAccount() && empty($data['bank_account'])) {
            $account = $this->resolveDefaultBankAccount();
            if ($account !== null) {
                $data['bank_account'] = $account;
            }
        }
    }

    /**
     * Renderuje formulář pole `bank_account`? Základní doklad, FVB a FPB ano;
     * pokladní a účetní doklad ne (přepisují na false). Přes payment_method
     * to odvodit nejde — účetní doklad má ze schématu 1 (Převodem) a pole
     * přesto nemá.
     */
    protected function newRecordUsesBankAccount(): bool
    {
        return true;
    }

    /**
     * Výchozí bankovní účet (`is_default`) ve stavu V pořádku; null bez něj.
     * Víc výchozích účtů nic nebrání — ORDER BY je jen determinismus.
     */
    protected function resolveDefaultBankAccount(): ?int
    {
        if ($this->db === null) {
            return null;
        }
        $row = $this->db->fetchRow(
            'SELECT `id` FROM `economy_codebooks_bank_accounts`'
            . ' WHERE `is_default` = 1 AND `docState` = 40'
            . ' ORDER BY `sort_order` ASC, `id` ASC LIMIT 1',
        );
        return $row !== null ? (int) $row['id'] : null;
    }

    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        $this->applyClientDefaults($data, $isNew);

        $tabs = [
            $this->buildHeaderTab($data, $isNew),
            $this->buildRowsTab(),
            $this->buildRecapTab($data),
        ];

        if (!empty($data['supplier_snapshot']) || !empty($data['customer_snapshot'])) {
            $tabs[] = $this->buildSnapshotsTab($data);
        }

        $tabs[] = $this->buildNotesTab();
        $tabs[] = $this->attachmentsTab();

        // Per-type extra taby na konci formuláře (např. FPB „Nastavení“).
        // Default v base třídě je prázdné pole — subclassy přepisují
        // `buildExtraTabs()`, pokud chtějí přidat per-typ taby.
        foreach ($this->buildExtraTabs($data, $isNew) as $extraTab) {
            $tabs[] = $extraTab;
        }

        return new FormDefinition(
            table: $this->table,
            title: $this->getFormTitle(),
            titleNew: $this->getNewFormTitle(),
            tabs: $tabs,
        );
    }

    /**
     * Titulek modalu pro existující záznam. Per-typ subclassy přepisují
     * (např. „Faktura vydaná").
     */
    protected function getFormTitle(): string
    {
        return 'Doklad';
    }

    /**
     * Titulek modalu pro nový záznam. Per-typ subclassy přepisují
     * (např. „Nová faktura vydaná").
     */
    protected function getNewFormTitle(): string
    {
        return 'Nový doklad';
    }

    /**
     * Hook pro per-typ subclassy — vrací pole tabů, které se přidají
     * na konec formuláře (za Přílohy). Default: žádné extra taby.
     *
     * Vzor: `ReceivedInvoiceForm` přepisuje a vrací `[buildSettingsTab($data)]`
     * (FPB má vlastní tab „Nastavení“ s registrací DPH, naším bankovním účtem
     * a readOnly domácí měnou).
     *
     * @param array<string, mixed> $data
     * @return list<FormTab>
     */
    protected function buildExtraTabs(array $data, bool $isNew): array
    {
        return [];
    }

    // ── Header info hooks ───────────────────────────────────────────────

    /**
     * Krátký popis typu dokladu pro subtitle hlavičky modalu (např.
     * „Přijatá faktura", „Vydaná faktura"). Liší se od `getFormTitle()`:
     * ten dává formální titulek modalu („Faktura přijatá"), tady je popisek
     * vedle čísla dokladu v subtitle („Přijatá faktura · 2024-0001").
     */
    protected function getDocTypeLabel(): string
    {
        return 'Doklad';
    }

    /**
     * Klíč ikony (z `icons.js::iconMap`) pro levý okraj hlavičky modalu.
     * Typicky shodný s `viewers[].icon` v `module.jsonc` daného modulu —
     * jeden klíč pro viewer i hlavičku formuláře. `null` = bez ikony.
     */
    protected function getHeaderIcon(): ?string
    {
        return null;
    }

    /**
     * Klíč ve `$data`, kde žije snapshot partnera. Přijaté doklady mají
     * partnera = dodavatele (`supplier_snapshot`), vydané = odběratele
     * (`customer_snapshot`). Default je `supplier_snapshot` — nejčastější
     * případ (přijaté doklady, výdaje, platby ven).
     */
    protected function getPartnerSnapshotKey(): string
    {
        return 'supplier_snapshot';
    }

    /**
     * Strukturovaná hlavička modalu pro všechny doklady nad `docs_core_heads`.
     *
     *   ┌──┐ Beta Software, a.s.                Bez DPH:    10 000,00  [×]
     *   │📄│ [Koncept] Přijatá faktura · Číslo  DPH:         2 100,00
     *   └──┘                     2024-0001    Celkem CZK: 12 100,00
     *
     *   - title    = jméno partnera. Není-li k dispozici (nový nezavedený
     *                záznam), vrátíme null → modal použije fallback
     *                `formDef.title`.
     *   - info     = typ dokladu (z `getDocTypeLabel()`, label prázdný —
     *                hodnota se zobrazí bez prefixu) + číslo dokladu
     *                (vynecháno, dokud ho server nepřidělil).
     *   - icon     = z `getHeaderIcon()`.
     *   - summary  = Bez DPH / DPH / Celkem v měně dokladu. Skryto, pokud
     *                doklad ještě nemá vypočítané totals.
     *
     * @param array<string, mixed> $data
     */
    public function buildHeaderInfo(array $data): ?FormHeaderInfo
    {
        $partnerName = $this->resolvePartnerName($data);
        if ($partnerName === '') {
            return null;
        }

        // Info řádka: typ dokladu (bez labelu) + volitelně číslo dokladu
        // + Plátce, liší-li se osoba pro saldokonto od partnera (#72).
        $info = [['label' => '', 'value' => $this->getDocTypeLabel()]];
        $docNumber = trim((string) ($data['doc_number'] ?? ''));
        if ($docNumber !== '') {
            $info[] = ['label' => 'Číslo', 'value' => $docNumber];
        }
        $payerName = $this->resolvePayerName($data);
        if ($payerName !== '') {
            $info[] = ['label' => 'Plátce', 'value' => $payerName];
        }

        return new FormHeaderInfo(
            title: $partnerName,
            info: $info,
            icon: $this->getHeaderIcon(),
            summary: $this->buildHeaderSummary($data),
        );
    }

    /**
     * Najde jméno partnera z partner-snapshotu. Preferuje uložený snapshot
     * (`supplier_snapshot` / `customer_snapshot` podle `getPartnerSnapshotKey()`),
     * fallback je SELECT z `base_persons_persons` přes `partner` FK.
     *
     * @param array<string, mixed> $data
     */
    protected function resolvePartnerName(array $data): string
    {
        $snap = $this->decodeSnapshot($data[$this->getPartnerSnapshotKey()] ?? null);
        if ($snap !== null && !empty($snap['name'])) {
            return trim((string) $snap['name']);
        }

        $partnerId = (int) ($data['partner'] ?? 0);
        if ($partnerId === 0 || $this->db === null) {
            return '';
        }
        $row = $this->db->fetchRow(
            'SELECT `full_name` FROM `base_persons_persons` WHERE `id` = %i',
            $partnerId,
        );
        if (!is_array($row) || empty($row['full_name'])) {
            return '';
        }
        return trim((string) $row['full_name']);
    }

    /**
     * Jméno plátce (osoby pro saldokonto), jen pokud se liší od partnera
     * hlavičky — jinak prázdný řetězec (pruh ho neukazuje).
     *
     * @param array<string, mixed> $data
     */
    protected function resolvePayerName(array $data): string
    {
        $payerId = (int) ($data['partner_balance'] ?? 0);
        if ($payerId === 0 || $payerId === (int) ($data['partner'] ?? 0) || $this->db === null) {
            return '';
        }
        $row = $this->db->fetchRow(
            'SELECT `full_name` FROM `base_persons_persons` WHERE `id` = %i',
            $payerId,
        );
        return is_array($row) && !empty($row['full_name']) ? trim((string) $row['full_name']) : '';
    }

    /**
     * Pravý souhrn (Bez DPH / DPH / Celkem v měně dokladu). Měna jde do labelu
     * „Celkem", ne do hodnoty — všechny tři částky pak stojí v jednom sloupci
     * zarovnané vpravo (jinak by „CZK" za částkou posunulo číslo doleva
     * a Celkem by se rozjelo s Bez DPH / DPH).
     *
     * Vrací prázdné pole, pokud doklad ještě nemá vypočítané totals — typicky
     * nový záznam bez řádků.
     *
     * @param array<string, mixed> $data
     * @return list<array{label: string, value: string}>
     */
    protected function buildHeaderSummary(array $data): array
    {
        $totalAmount = (float) ($data['total_amount'] ?? 0);
        if ($totalAmount === 0.0) {
            return [];
        }

        $currency = strtoupper((string) ($data['doc_currency'] ?? ''));
        $totalBase = (float) ($data['total_base'] ?? 0);
        $totalVat  = (float) ($data['total_vat'] ?? 0);
        $celkemLabel = $currency !== '' ? 'Celkem ' . $currency : 'Celkem';

        return [
            ['label' => 'Bez DPH',    'value' => $this->formatMoney($totalBase)],
            ['label' => 'DPH',        'value' => $this->formatMoney($totalVat)],
            ['label' => $celkemLabel, 'value' => $this->formatMoney($totalAmount)],
        ];
    }

    /**
     * Defaulty JEN pro renderování. Běží v buildFormDefinition nad kopií dat
     * (GET meta i recalculate, kde applyNewRecordDefaults neběží), takže se
     * do response `data` nepropíší — řídí `hidden`/options ($hasVat,
     * $hasForeignCurrency, měnový filtr účtů). Co má uživatel vidět v inputu,
     * patří do applyNewRecordDefaults; hodnoty, které řídí obojí (vat_mode
     * účetního dokladu, payment_method pokladního), stojí na obou místech.
     * Server-side defaulty (fiscal_year, vat_period, snapshots, součty)
     * počítá DocDocument::beforeSave.
     *
     * @param array<string, mixed> $data
     */
    protected function applyClientDefaults(array &$data, bool $isNew): void
    {
        if (!$isNew) {
            return;
        }
        if (!isset($data['vat_mode'])) {
            // Neplátce (economy.vatAgenda === false) → „Bez DPH"; nerozhodnutý
            // klíč zachovává dnešní default. Jen default — explicitní hodnota
            // v datech vyhrává (import, kopie dokladu).
            $data['vat_mode'] = $this->vatAgendaDisabled() ? 0 : 1;
        }
        if (!isset($data['vat_calc_source'])) {
            $data['vat_calc_source'] = 0;
        }
        if (!isset($data['vat_place'])) {
            $data['vat_place'] = 0;
        }
        if (!isset($data['payment_method'])) {
            $data['payment_method'] = 1;
        }
        if (!isset($data['total_rounding_mode'])) {
            $data['total_rounding_mode'] = 1;
        }
        if (!isset($data['vat_rounding_mode'])) {
            $data['vat_rounding_mode'] = 0;
        }
        if (empty($data['home_currency'])) {
            $data['home_currency'] = $this->homeCurrency();
        }
        if (empty($data['doc_currency'])) {
            // Domácí doklad je v domácí měně; explicitní hodnota vyhrává.
            $data['doc_currency'] = $data['home_currency'];
        }
    }

    /** @param array<string, mixed> $data */
    protected function buildHeaderTab(array $data, bool $isNew): FormTab
    {
        $vatMode = (int) ($data['vat_mode'] ?? 1);
        $hasVat = $vatMode !== 0;
        // Nedaňový typ (#79 D1): DUZP, DPPD a zařazení do tvrzení nemá.
        $taxDocument = $this->isTaxDocument($data);
        // Sekce „DPH" zmizí jen u neplátce (economy.vatAgenda === false)
        // A ZÁROVEŇ dokladu bez DPH — doklad z doby plátcovství musí svůj
        // režim dál ukazovat (ds-setup.md D10), proto podmínka na $hasVat
        // a proto se $hasVat samotné na příznak nikdy nepřepojuje.
        $vatSectionHidden = !$hasVat && $this->vatAgendaDisabled();
        $docCurrency = strtolower((string) ($data['doc_currency'] ?? 'czk'));
        $homeCurrency = strtolower((string) ($data['home_currency'] ?? 'czk'));
        $hasForeignCurrency = $docCurrency !== '' && $homeCurrency !== ''
            && $docCurrency !== $homeCurrency;
        $partnerId = (int) ($data['partner'] ?? 0);
        $reportPeriodOptions = $hasVat && $taxDocument && !$isNew
            ? $this->resolveReportPeriodOptions((int) ($data['vat_registration'] ?? 0))
            : ['return' => [], 'cs' => [], 'rs' => []];
        $isCashPayment = $this->isCashPayment($data);
        $cashDeskHint = $this->cashDeskHint($data, $docCurrency);

        $tab = $this->tab('basic', 'Hlavička')
            ->section()
                ->col()
                    ->separator('Identifikace')
                    ->select('number_series',
                        options: $this->resolveNumberSeriesOptions(
                            !empty($data['doc_type']) ? (string) $data['doc_type'] : null,
                        ),
                        required: true,
                        readOnly: !$isNew,
                    )
                    ->input('doc_number', readOnly: true)
                    ->input('doc_text')

                    ->separator('Partner')
                    ->lookup('partner',
                        table: 'base_persons_persons',
                        placeholder: 'Hledat partnera…',
                        triggers: 'reload',
                        editForm: true,
                        createForm: true,
                    )
                    ->lookup('partner_address',
                        table: 'base_persons_addresses',
                        filter: $partnerId !== 0 ? ['person' => $partnerId] : null,
                        placeholder: $partnerId !== 0 ? 'Vyberte adresu…' : 'Nejdřív vyberte partnera',
                        readOnly: $partnerId === 0,
                    )
                    ->lookup('partner_bank',
                        table: 'base_persons_bank_accounts',
                        filter: $partnerId !== 0 ? ['person' => $partnerId] : null,
                        placeholder: $partnerId !== 0 ? 'Vyberte bankovní účet…' : 'Nejdřív vyberte partnera',
                        readOnly: $partnerId === 0,
                    )
                    ->input('partner_bank_account', label: 'Číslo účtu')
                    ->input('partner_bank_iban', label: 'IBAN')
                    ->input('partner_bank_bic', label: 'BIC/SWIFT')

                    ->separator('Datumy')
                    ->date('issue_date', required: true, triggers: 'reload')
                    ->date('due_date')
                    ->date('accounting_date', required: true)
                    ->date('vat_duzp', hidden: !$hasVat || !$taxDocument)
                    ->date('vat_dppd', hidden: !$hasVat || !$taxDocument)
                    ->date('period_from', hint: 'Volitelné, např. pronájem za období')
                    ->date('period_to')

                    ->separator('DPH', hidden: $vatSectionHidden)
                    ->select('vat_mode',
                        options: $this->resolveCfgItemOptions('docs.core.vatModes'),
                        triggers: 'reload',
                        hidden: $vatSectionHidden,
                    )
                    ->select('vat_calc_source',
                        options: $this->resolveCfgItemOptions('docs.core.vatCalcSources'),
                        hidden: !$hasVat,
                        hint: self::VAT_CALC_SOURCE_HINT,
                    )
                    ->select('vat_recap_source',
                        options: $this->resolveCfgItemOptions('docs.core.vatRecapSources'),
                        hidden: !$hasVat,
                        hint: self::VAT_RECAP_SOURCE_HINT,
                    )
                    ->select('vat_place',
                        options: $this->resolveCfgItemOptions('docs.core.vatPlaces'),
                        triggers: 'reload',
                        hidden: !$hasVat,
                    )
                    ->select('vat_registration',
                        // U skryté sekce nemá smysl dotaz na registrace do DB.
                        options: $vatSectionHidden ? [] : $this->resolveVatRegistrationOptions(),
                        triggers: 'reload',
                        // Povinná u dokladu s DPH (DocDocument::validate) —
                        // bez prázdné možnosti, hodnotu dodá applyNewRecordDefaults.
                        required: $hasVat,
                        hidden: !$hasVat,
                    )
                    // Zařazení do instancí tvrzení (economy.vat extension) —
                    // server je při uložení přepočítá, ručně změněné pole
                    // respektuje (přesun dokladu mezi měsíci KH).
                    ->select('vat_period',
                        options: $reportPeriodOptions['return'],
                        hidden: !$hasVat || !$taxDocument || $isNew,
                    )
                    ->select('cs_period',
                        options: $reportPeriodOptions['cs'],
                        hidden: !$hasVat || !$taxDocument || $isNew,
                    )
                    ->select('rs_period',
                        options: $reportPeriodOptions['rs'],
                        hidden: !$hasVat || !$taxDocument || $isNew,
                    )

                    ->separator('Měna')
                    ->select('doc_currency',
                        options: $this->resolveCurrencyOptions(),
                        triggers: 'reload',
                    )
                    ->input('home_currency', readOnly: true)
                    ->number('exchange_rate', hidden: !$hasForeignCurrency)

                    ->separator('Zaokrouhlení')
                    ->select('total_rounding_mode',
                        options: $this->resolveCfgItemOptions('docs.core.roundingModes'),
                    )
                    ->select('vat_rounding_mode',
                        options: $this->resolveCfgItemOptions('docs.core.vatRoundingModes'),
                        hidden: !$hasVat,
                    )

                    ->separator('Platba')
                    ->select('payment_method',
                        options: $this->resolveCfgItemOptions('docs.core.paymentMethods'),
                        triggers: 'reload',
                    )
                    ->lookup('cash_desk',
                        table: 'economy_codebooks_cash_desks',
                        placeholder: 'Hledat pokladnu…',
                        hidden: !$isCashPayment,
                        hint: $cashDeskHint,
                    );
        $this->addPaymentIntermediaryElements($tab, $data);
        return $tab
                    ->select('bank_account',
                        options: $this->resolveBankAccountOptions($docCurrency),
                    )
                    ->input('payment_reference')
                    ->input('specific_symbol')
                    ->input('constant_symbol')

                    ->separator('Součty')
                    ->number('total_base', readOnly: true, label: 'Základ DPH')
                    ->number('total_vat', readOnly: true, label: 'DPH')
                    ->number('total_amount', readOnly: true, label: 'Celkem')
                    ->number('total_rounding', readOnly: true, label: 'Zaokrouhlení')
            ->build();
    }

    protected function buildRowsTab(): FormTab
    {
        return $this->subtableTab(
            'rows',
            'Řádky',
            'docs_core_rows',
            'doc_head',
            formId: 'docs.core.rows',
            orderColumn: 'order_pos',
        );
    }

    // ── Sub-tabulka řádků (tab `rows`) ─────────────────────────────────────

    /**
     * Sloupce a buňky sub-tabulky řádků dokladu (issue #53, fáze 1).
     * Položková sada; doklad bez DPH (`vat_mode = 0` rodiče) DPH sloupce
     * nemá a Celkem bere z `total_price`. Kontační sadu (účetní doklad)
     * řeší `AccountingDocsForm::renderSubtable()` — všech osm operací
     * s vlajkou `rowSide` je v `docs.core.rowOperations` povoleno výhradně
     * pro `cmnbkp`, a ten má vlastní form třídu, takže sada je jednoznačná
     * z hlavičky; per-řádkové rozhodování není potřeba.
     *
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $parentData
     * @return array{columns: list<array<string, mixed>>, rows: list<array<string, mixed>>, order_column: ?string}
     */
    public function renderSubtable(FormTab $tab, array $rows, array $parentData): array
    {
        if ($tab->id === 'recap') {
            return $this->renderRecapRows($rows, $parentData);
        }
        if ($tab->id !== 'rows') {
            return parent::renderSubtable($tab, $rows, $parentData);
        }
        return $this->renderItemRows($rows, $parentData);
    }

    /**
     * Sada řádků **převzaté** rekapitulace: # · Sazba · % · Základ · Daň ·
     * Celkem, u cizí měny navíc trojice v domácí měně (ke čtení —
     * dopočítává je uložení z kurzu dokladu). Zešedivělé jsou částky, které
     * do součtů hlavičky nevstupují (`sum_*` z definice kódu — typicky
     * oddaňovací pár samovyměření), stejně jako v přehledu u přepočítané
     * rekapitulace.
     *
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $parentData
     * @return array{columns: list<array<string, mixed>>, rows: list<array<string, mixed>>, order_column: ?string}
     */
    protected function renderRecapRows(array $rows, array $parentData): array
    {
        $t = 'docs_core_vat_recap';
        $currency = strtolower((string) ($parentData['doc_currency'] ?? 'czk'));
        $homeCurrency = strtolower((string) ($parentData['home_currency'] ?? 'czk'));
        $hasExchange = $currency !== $homeCurrency && $homeCurrency !== '';

        $columns = [
            ['id' => 'order_pos', 'label' => '#', 'align' => 'right', 'width' => 44],
            ['id' => 'vat_code',  'label' => $this->subtableLabel($t, 'vat_code', 'Kód DPH'), 'grow' => true],
            ['id' => 'vat_pct',   'label' => $this->subtableLabel($t, 'vat_pct', 'DPH %'), 'align' => 'right'],
            ['id' => 'base',      'label' => $this->subtableLabel($t, 'base', 'Základ'), 'align' => 'right'],
            ['id' => 'tax',       'label' => $this->subtableLabel($t, 'tax', 'Daň'), 'align' => 'right'],
            ['id' => 'total',     'label' => $this->subtableLabel($t, 'total', 'Celkem'), 'align' => 'right'],
        ];
        if ($hasExchange) {
            $home = strtoupper($homeCurrency);
            $columns[] = ['id' => 'base_dom',  'label' => "Základ ({$home})", 'align' => 'right'];
            $columns[] = ['id' => 'tax_dom',   'label' => "Daň ({$home})", 'align' => 'right'];
            $columns[] = ['id' => 'total_dom', 'label' => "Celkem ({$home})", 'align' => 'right'];
        }

        $out = [];
        foreach (array_values($rows) as $i => $row) {
            $cells = ['order_pos' => $this->rowNumber($row, $i)];
            $cells['vat_code'] = $this->vatCodeLabel((string) ($row['vat_code'] ?? ''), $parentData);
            $this->putCell($cells, 'vat_pct', SubtableCellFormatter::trimmedNumber($row['vat_pct'] ?? null, 2));

            foreach (['base' => 'sum_base', 'tax' => 'sum_tax', 'total' => 'sum_total'] as $col => $flag) {
                $text = SubtableCellFormatter::money($row[$col] ?? null);
                if ($text === null) {
                    continue;
                }
                $cells[$col] = empty($row[$flag]) ? ['text' => $text, 'class' => 'muted'] : $text;
                if ($hasExchange) {
                    $domText = SubtableCellFormatter::money($row[$col . '_dom'] ?? null);
                    if ($domText !== null) {
                        $cells[$col . '_dom'] = empty($row[$flag])
                            ? ['text' => $domText, 'class' => 'muted']
                            : $domText;
                    }
                }
            }

            $out[] = ['id' => (int) ($row['id'] ?? 0), 'cells' => $cells];
        }

        return ['columns' => $columns, 'rows' => $out, 'order_column' => null];
    }

    /**
     * Položková sada: # · Popis · Množství · MJ · Cena/MJ · [Bez DPH · DPH % ·
     * DPH · Celkem s DPH] | [Cena celkem]. Textový řádek (`row_kind = 0`)
     * má jen popis tlumeně, žádné číselné buňky (množství/cena tam bývá
     * NULL nebo 0 — neukazujeme „0,00"). Částky bez měny (měna je
     * v hlavičce). Labely z definice `docs_core_rows` (lokalizované
     * TableLoaderem), fallback česky.
     *
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $parentData
     * @return array{columns: list<array<string, mixed>>, rows: list<array<string, mixed>>, order_column: ?string}
     */
    protected function renderItemRows(array $rows, array $parentData): array
    {
        $hasVat = (int) ($parentData['vat_mode'] ?? 1) !== 0;
        $t = 'docs_core_rows';

        $columns = [
            ['id' => 'order_pos',   'label' => '#', 'align' => 'right', 'width' => 44],
            ['id' => 'description', 'label' => $this->subtableLabel($t, 'description', 'Popis'), 'grow' => true],
            ['id' => 'quantity',    'label' => $this->subtableLabel($t, 'quantity', 'Množství'), 'align' => 'right'],
            ['id' => 'unit',        'label' => $this->subtableLabel($t, 'unit', 'Jednotka')],
            ['id' => 'unit_price',  'label' => $this->subtableLabel($t, 'unit_price', 'Cena/jednotka'), 'align' => 'right'],
        ];
        if ($hasVat) {
            $columns[] = ['id' => 'vat_base',   'label' => $this->subtableLabel($t, 'vat_base', 'Základ DPH'), 'align' => 'right'];
            $columns[] = ['id' => 'vat_pct',    'label' => $this->subtableLabel($t, 'vat_pct', 'DPH %'), 'align' => 'right'];
            $columns[] = ['id' => 'vat_amount', 'label' => $this->subtableLabel($t, 'vat_amount', 'DPH'), 'align' => 'right'];
            $columns[] = ['id' => 'vat_total',  'label' => $this->subtableLabel($t, 'vat_total', 'Celkem s DPH'), 'align' => 'right'];
        } else {
            $columns[] = ['id' => 'total_price', 'label' => $this->subtableLabel($t, 'total_price', 'Cena celkem'), 'align' => 'right'];
        }

        $units = $this->loadUnitShortcuts($rows);

        $out = [];
        foreach (array_values($rows) as $i => $row) {
            $cells = ['order_pos' => $this->rowNumber($row, $i)];
            $description = trim((string) ($row['description'] ?? ''));
            $isText = (int) ($row['row_kind'] ?? 1) === 0;

            if ($isText) {
                if ($description !== '') {
                    $cells['description'] = ['text' => $description, 'class' => 'muted'];
                }
                $out[] = ['id' => (int) ($row['id'] ?? 0), 'cells' => $cells];
                continue;
            }

            if ($description !== '') {
                $cells['description'] = $description;
            }
            $this->putCell($cells, 'quantity', SubtableCellFormatter::trimmedNumber($row['quantity'] ?? null, 4));
            $unitId = (int) ($row['unit'] ?? 0);
            if ($unitId > 0 && isset($units[$unitId])) {
                $cells['unit'] = $units[$unitId];
            }
            $this->putCell($cells, 'unit_price', SubtableCellFormatter::price($row['unit_price'] ?? null));
            if ($hasVat) {
                $this->putCell($cells, 'vat_base', SubtableCellFormatter::money($row['vat_base'] ?? null));
                $this->putCell($cells, 'vat_pct', SubtableCellFormatter::trimmedNumber($row['vat_pct'] ?? null, 2));
                $this->putCell($cells, 'vat_amount', SubtableCellFormatter::money($row['vat_amount'] ?? null));
                $this->putCell($cells, 'vat_total', SubtableCellFormatter::money($row['vat_total'] ?? null));
            } else {
                $this->putCell($cells, 'total_price', SubtableCellFormatter::money($row['total_price'] ?? null));
            }

            $out[] = ['id' => (int) ($row['id'] ?? 0), 'cells' => $cells];
        }

        return ['columns' => $columns, 'rows' => $out, 'order_column' => null];
    }

    /**
     * Kontační sada (účetní doklad): # · Pohyb · Účet · Popis · Strana ·
     * Částka. Pohyb = `name` z `docs.core.rowOperations`; Účet = číslo
     * a název z `economy_accounting_accounts` (jen řádky s přímým účtem —
     * saldokontní operace mají účet implicitní z předpisu, buňka je
     * prázdná); Strana jen u operací s `rowSide: 1` (u `rowSide: 0`,
     * kurzové rozdíly, stranu nesou kroky předpisu — buňka prázdná).
     * Textový řádek jako v položkové sadě.
     *
     * @param list<array<string, mixed>> $rows
     * @return array{columns: list<array<string, mixed>>, rows: list<array<string, mixed>>, order_column: ?string}
     */
    protected function renderContationRows(array $rows): array
    {
        $t = 'docs_core_rows';
        $columns = [
            ['id' => 'order_pos',   'label' => '#', 'align' => 'right', 'width' => 44],
            ['id' => 'operation',   'label' => $this->subtableLabel($t, 'operation', 'Pohyb')],
            ['id' => 'account',     'label' => $this->subtableLabel($t, 'account', 'Účet')],
            ['id' => 'description', 'label' => $this->subtableLabel($t, 'description', 'Popis'), 'grow' => true],
            ['id' => 'acc_side',    'label' => $this->subtableLabel($t, 'acc_side', 'Strana')],
            ['id' => 'total_price', 'label' => 'Částka', 'align' => 'right'],
        ];

        $operations = $this->config?->cfgItem('docs.core.rowOperations');
        $operations = is_array($operations) ? $operations : [];
        $accounts = $this->loadAccountLabels($rows);

        $out = [];
        foreach (array_values($rows) as $i => $row) {
            $cells = ['order_pos' => $this->rowNumber($row, $i)];
            $description = trim((string) ($row['description'] ?? ''));
            $isText = (int) ($row['row_kind'] ?? 1) === 0;

            if ($isText) {
                if ($description !== '') {
                    $cells['description'] = ['text' => $description, 'class' => 'muted'];
                }
                $out[] = ['id' => (int) ($row['id'] ?? 0), 'cells' => $cells];
                continue;
            }

            $operation = (string) ($row['operation'] ?? '');
            $opAttrs = is_array($operations[$operation] ?? null) ? $operations[$operation] : [];
            if ($operation !== '') {
                $cells['operation'] = (string) ($opAttrs['name'] ?? $operation);
            }
            $accountId = (int) ($row['account'] ?? 0);
            if ($accountId > 0 && isset($accounts[$accountId])) {
                $cells['account'] = $accounts[$accountId];
            }
            if ($description !== '') {
                $cells['description'] = $description;
            }
            if (!empty($opAttrs['rowSide']) && isset($row['acc_side']) && $row['acc_side'] !== '') {
                $cells['acc_side'] = $this->cfgItemLabel('docs.core.accSides', $row['acc_side']);
            }
            $this->putCell($cells, 'total_price', SubtableCellFormatter::money($row['total_price'] ?? null));

            $out[] = ['id' => (int) ($row['id'] ?? 0), 'cells' => $cells];
        }

        return ['columns' => $columns, 'rows' => $out, 'order_column' => null];
    }

    /**
     * Číslo řádku pro sloupec #: `order_pos`, je-li > 0, jinak pořadí
     * v seřazeném seznamu (řádky přidané sub-formulářem mají dnes
     * `order_pos = 0` — automatické číslování řeší fáze 3).
     *
     * @param array<string, mixed> $row
     */
    private function rowNumber(array $row, int $index): string
    {
        $pos = (int) ($row['order_pos'] ?? 0);
        return (string) ($pos > 0 ? $pos : $index + 1);
    }

    /** @param array<string, mixed> $cells */
    private function putCell(array &$cells, string $id, ?string $text): void
    {
        if ($text !== null) {
            $cells[$id] = $text;
        }
    }

    /**
     * Zkratky jednotek jedním dotazem `IN` nad id z řádků (žádný N+1).
     *
     * @param list<array<string, mixed>> $rows
     * @return array<int, string> unit id → shortcut
     */
    private function loadUnitShortcuts(array $rows): array
    {
        $ids = $this->collectIds($rows, 'unit');
        if ($ids === [] || $this->db === null) {
            return [];
        }
        $out = [];
        foreach ($this->db->fetchAll('SELECT `id`, `shortcut`, `name` FROM `core_units` WHERE `id` IN %in', $ids) as $u) {
            $label = trim((string) ($u['shortcut'] ?? ''));
            if ($label === '') {
                $label = trim((string) ($u['name'] ?? ''));
            }
            if ($label !== '') {
                $out[(int) $u['id']] = $label;
            }
        }
        return $out;
    }

    /**
     * „číslo název" účtů jedním dotazem `IN` nad id z řádků.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<int, string> account id → label
     */
    private function loadAccountLabels(array $rows): array
    {
        $ids = $this->collectIds($rows, 'account');
        if ($ids === [] || $this->db === null) {
            return [];
        }
        $out = [];
        foreach ($this->db->fetchAll('SELECT `id`, `number`, `name` FROM `economy_accounting_accounts` WHERE `id` IN %in', $ids) as $a) {
            $label = trim(trim((string) ($a['number'] ?? '')) . ' ' . trim((string) ($a['name'] ?? '')));
            if ($label !== '') {
                $out[(int) $a['id']] = $label;
            }
        }
        return $out;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<int>
     */
    private function collectIds(array $rows, string $column): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $id = (int) ($row[$column] ?? 0);
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        return array_keys($ids);
    }

    /**
     * Tab „Rekapitulace DPH" má dvě podoby podle autority rekapitulace
     * (`vat_recap_source`, docs/vat-calculation.md § 5):
     *
     * - **přepočítaná** — přehled ke čtení: rekapitulace vzniká z řádků,
     *   editovat ji nemá smysl (uložení by změnu zahodilo). Dvouřádkový
     *   HTML rozpis umí ukázat i domácí měnu a zešedivělé částky, které
     *   do součtů hlavičky nevstupují;
     * - **převzatá** — sub-tabulka `docs_core_vat_recap` s vlastním
     *   formulářem (`docs.core.vatRecap`): účetní opisuje, co je na
     *   dokladu, a uložení jí to nepřepíše.
     *
     * Rozhoduje **uložený** zdroj ({@see storedRecapSource}), ne hodnota
     * přepínače — sub-tabulka pracuje s tím, co je v DB, a startovní
     * převzatá rekapitulace vzniká kopií přepočítané až při uložení (I3).
     *
     * @param array<string, mixed> $data
     */
    protected function buildRecapTab(array $data): FormTab
    {
        if ($this->storedRecapSource($data) === 1) {
            return $this->subtableTab(
                'recap',
                'Rekapitulace DPH',
                'docs_core_vat_recap',
                'doc_head',
                formId: 'docs.core.vatRecap',
                orderColumn: 'order_pos',
            );
        }

        // FormController::meta loads only the head row (SELECT * FROM heads),
        // so $data doesn't carry the recap from the docs_core_vat_recap child
        // table. Load it here — same pattern as DocDocument::resolveRowsForCompute.
        $recap = $this->resolveRecapForRender($data);
        return $this->tab('recap', 'Rekapitulace DPH')
            ->section()
                ->col()
                    ->html($this->renderRecapHtml($data, $recap))
            ->build();
    }

    /**
     * Zdroj rekapitulace tak, jak je **uložený**. Neuložená hodnota
     * z přepínače by tab přepnula dřív, než se v DB objeví jakákoli
     * převzatá rekapitulace: sub-tabulka by neměla co načíst (endpoint
     * `/subtable/recap/{id}` staví taby z uloženého záznamu) a přidané
     * řádky by přepsala kopie přepočítané rekapitulace při uložení.
     * U nového záznamu a bez DB padá na hodnotu z payloadu — sub-tabulka
     * u nového záznamu stejně jen vyzve k uložení.
     *
     * @param array<string, mixed> $data
     */
    protected function storedRecapSource(array $data): int
    {
        $payloadValue = (int) ($data['vat_recap_source'] ?? 0);
        if (empty($data['id']) || $this->db === null) {
            return $payloadValue;
        }
        $stored = $this->db->fetchSingle(
            'SELECT `vat_recap_source` FROM `docs_core_heads` WHERE `id` = %i',
            (int) $data['id'],
        );
        return $stored === null ? $payloadValue : (int) $stored;
    }

    /**
     * Get recap rows: prefer payload (server-computed during recalculate),
     * else load current state from DB.
     *
     * @return list<array<string, mixed>>
     */
    protected function resolveRecapForRender(array $data): array
    {
        if (isset($data['vatRecap']) && is_array($data['vatRecap'])) {
            return $data['vatRecap'];
        }
        if (empty($data['id']) || $this->db === null) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT * FROM `docs_core_vat_recap`'
            . ' WHERE `doc_head` = %i'
            . ' ORDER BY `order_pos` ASC',
            (int) $data['id'],
        );
        return $rows;
    }

    /** @param array<string, mixed> $data */
    protected function buildSnapshotsTab(array $data): FormTab
    {
        return $this->tab('snapshots', 'Fakturační údaje')
            ->section()
                ->col()
                    ->html($this->renderSnapshotsHtml($data))
            ->build();
    }

    protected function buildNotesTab(): FormTab
    {
        return $this->tab('notes', 'Poznámky')
            ->section()
                ->col()
                    ->textarea('notice', label: 'Interní poznámka',
                        hint: 'Vidíme jen my, netiskne se')
                    ->textarea('doc_notice', label: 'Poznámka na doklad',
                        hint: 'Bude na PDF dokladu')
            ->build();
    }

    public function recalculate(string $changedColumn, array $data): RecalculateResult
    {
        if ($changedColumn === 'partner') {
            // Cascading reset: změna partnera invaliduje dříve vybranou adresu
            // a bankovní účet — ty patřily bývalému partnerovi. Uživatel musí
            // vybrat znovu z dropdownu (který je už filtrovaný na nového partnera).
            $data['partner_address'] = null;
            $data['partner_bank'] = null;

            // Auto-fill due_date podle payment_term_days partnera (pouze pokud
            // je nový partner zadaný a due_date ještě není vyplněný).
            if (!empty($data['partner']) && $this->db !== null
                && !empty($data['issue_date']) && empty($data['due_date'])
            ) {
                $partner = $this->db->fetchRow(
                    'SELECT `payment_term_days` FROM `base_persons_persons` WHERE `id` = %i',
                    (int) $data['partner'],
                );
                $days = (int) ($partner['payment_term_days'] ?? 14);
                if ($days < 0) {
                    $days = 14;
                }
                $issue = (string) $data['issue_date'];
                try {
                    $data['due_date'] = (new \DateTimeImmutable($issue))
                        ->modify("+{$days} days")
                        ->format('Y-m-d');
                } catch (\Exception) {
                    // leave due_date alone if issue_date is unparseable
                }
            }
        }

        if ($changedColumn === 'issue_date' && !empty($data['issue_date'])) {
            // Nový doklad (bez id, stejně jako $isNew v FormController): Účetní
            // datum a DUZP jdou za Datem vystavení vždy — applyNewRecordDefaults
            // je předvyplnil dneškem, takže „jen prázdné“ by už nikdy nic
            // nezměnilo a zpětně datovaná faktura by chtěla tři ruční přepisy
            // (#24 B, D7). U uloženého dokladu se doplňují jen prázdná pole.
            $followIssueDate = empty($data['id']);
            if ($followIssueDate || empty($data['accounting_date'])) {
                $data['accounting_date'] = $data['issue_date'];
            }
            if (($followIssueDate || empty($data['vat_duzp'])) && $this->isTaxDocument($data)) {
                $data['vat_duzp'] = $data['issue_date'];
            }
        }

        if ($changedColumn === 'payment_method') {
            if ((int) ($data['payment_method'] ?? 1) === 0) {
                // Hotovost: předvyplnit výchozí pokladnu měny dokladu (is_default,
                // stav V pořádku); není-li, pole zůstane prázdné s hintem.
                if (empty($data['cash_desk'])) {
                    $data['cash_desk'] = $this->resolveDefaultCashDesk(
                        (string) ($data['doc_currency'] ?? $data['home_currency'] ?? 'czk'),
                    );
                }
            } else {
                // Jiná platba: pokladnu smazat, DocDocument::validate by ji
                // odmítl (cash_desk_requires_cash_payment).
                $data['cash_desk'] = null;
            }
        }

        if (in_array($changedColumn, self::PAYER_RECALC_COLUMNS, true)) {
            $this->applyPartnerBalancePreview($data);
        }

        $isNew = !isset($data['id']) || $data['id'] === null || $data['id'] === '';
        return new RecalculateResult(
            $this->buildFormDefinition($data, $isNew),
            $data,
        );
    }

    /** Sloupce, jejichž změna přepočítá náhled plátce (PartnerBalanceResolver). */
    protected const PAYER_RECALC_COLUMNS = [
        'payment_method', 'payment_terminal', 'transport', 'partner',
        'partner_balance_manual', 'cash_desk', 'number_series', 'cash_dir',
    ];

    // ── Prostředník platby a plátce (#72) ───────────────────────────────────

    /**
     * Terminál / brána (jen prodejní směr s kartou / bránou), způsob dopravy
     * (prodejní směr) a Plátce (`partner_balance`) s přepínačem ručního
     * zadání. Sdílené všemi hlavičkovými formuláři — každý per-typ
     * buildHeaderTab helper volá za pokladnou / způsobem úhrady, aby
     * viditelnost i filtry byly všude stejné. Odvozený plátce (terminál,
     * dopravce) je read-only a checkbox se schová: odvození má přednost
     * (D2); ruční plátce se zapíná checkboxem u převodu / zápočtu / FP.
     *
     * @param array<string, mixed> $data
     */
    protected function addPaymentIntermediaryElements(TabBuilder $tab, array $data): TabBuilder
    {
        $data = $this->intermediaryData($data);
        $sales = PartnerBalanceResolver::isSalesDirection($data, $this->config);
        $usesTerminal = PartnerBalanceResolver::usesTerminal($data, $this->config);
        $isGateway = (int) ($data['payment_method'] ?? 1) === PartnerBalanceResolver::METHOD_GATEWAY;
        $source = $this->partnerBalanceSource($data);
        $derived = PartnerBalanceResolver::isDerived($source);
        $manual = !empty($data['partner_balance_manual']);

        $terminalFilter = $isGateway
            ? ['kind' => PartnerBalanceResolver::KIND_GATEWAY]
            : ['kind' => PartnerBalanceResolver::KIND_TERMINAL]
                + (!empty($data['cash_desk']) ? ['cash_desk' => (int) $data['cash_desk']] : []);
        $terminalHint = null;
        if ($usesTerminal && empty($data['payment_terminal'])) {
            $terminalHint = $isGateway
                ? 'Platba bránou vyžaduje vybranou bránu — založ ji v číselníku Platební terminály a brány'
                : 'Bez terminálu s protistranou je plátcem partner dokladu';
        }
        $payerHint = match ($source) {
            PartnerBalanceResolver::SOURCE_TERMINAL  => 'Protistrana terminálu / brány — pohledávka vzniká za ní',
            PartnerBalanceResolver::SOURCE_TRANSPORT => 'Protistrana dopravce — pohledávka z dobírky vzniká za ní',
            default                                  => null,
        };

        return $tab
            ->lookup('payment_terminal',
                table: 'economy_codebooks_payment_terminals',
                filter: $terminalFilter,
                placeholder: $isGateway ? 'Hledat platební bránu…' : 'Hledat terminál…',
                hidden: !$usesTerminal,
                triggers: 'reload',
                hint: $terminalHint,
            )
            ->lookup('transport',
                table: 'economy_codebooks_transports',
                placeholder: 'Hledat způsob dopravy…',
                hidden: !$sales,
                triggers: 'reload',
            )
            ->lookup('partner_balance',
                table: 'base_persons_persons',
                placeholder: $manual && !$derived ? 'Hledat plátce…' : 'Odvozeno z dokladu',
                readOnly: $derived || !$manual,
                hint: $payerHint,
            )
            ->checkbox('partner_balance_manual', triggers: 'reload', hidden: $derived);
    }

    /**
     * Data pro odvození prostředníka — base vrací vstup; formuláře nad
     * pokladnou doplní `cash_desk` z řady (na uložení ji denormalizuje
     * DocDocument, ale náhled a filtr terminálu ji chtějí už teď).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function intermediaryData(array $data): array
    {
        return $data;
    }

    /** Zdroj plátce (PartnerBalanceResolver::SOURCE_*) nad kopií dat — jen pro renderování. */
    protected function partnerBalanceSource(array $data): string
    {
        $copy = $data;
        return $this->partnerBalanceResolver()->resolve($copy);
    }

    /**
     * Živý náhled plátce po změně pole (recalculate): odvozená hodnota jde
     * do response `data`, stejnou logikou jako při uložení. Terminál mimo
     * kartu / bránu na prodejním dokladu se vyprázdní.
     *
     * @param array<string, mixed> $data
     */
    protected function applyPartnerBalancePreview(array &$data): void
    {
        $data = $this->intermediaryData($data);
        if (!PartnerBalanceResolver::usesTerminal($data, $this->config)) {
            $data['payment_terminal'] = null;
        }
        $this->partnerBalanceResolver()->resolve($data);
    }

    protected function partnerBalanceResolver(): PartnerBalanceResolver
    {
        return new PartnerBalanceResolver($this->db?->getDibiConnection(), $this->config);
    }

    /** Má typ dokladu řadu vázanou na entitu (`docTypes[].series_binding`)? */
    protected function isBoundDocType(string $docType): bool
    {
        $cfg = $this->config?->cfgItem('docs.core.docTypes');
        return is_array($cfg) && !empty($cfg[$docType]['series_binding']);
    }

    /**
     * Je doklad daňový (`docTypes[].tax_document`, #79 D1)? Nedaňový typ
     * (zálohová faktura) nemá DUZP/DPPD ani zařazení do tvrzení DPH —
     * formulář ta pole skrývá a nedoplňuje. Bez configu / neznámý typ =
     * daňový (dnešní chování). Jediná autorita: `DocTypes::isTaxDocument()`.
     *
     * @param array<string, mixed> $data
     */
    protected function isTaxDocument(array $data): bool
    {
        return DocTypes::isTaxDocument($this->config, (string) ($data['doc_type'] ?? ''));
    }

    /**
     * Pokladna (`cash_desk`) patří do hlavičky jen při platbě v hotovosti
     * (payment_method 0) — jinak ji DocDocument::validate odmítne. Default
     * doplňuje recalculate('payment_method'); bez výchozí pokladny měny
     * zůstane prázdná s nápovědou. Sdílené base + per-typ formuláři
     * (IssuedInvoiceForm / ReceivedInvoiceForm mají vlastní buildHeaderTab).
     */
    protected function isCashPayment(array $data): bool
    {
        return (int) ($data['payment_method'] ?? 1) === 0;
    }

    /**
     * Bankovní účet partnera a IBAN mají smysl jen při platbě převodem
     * (payment_method 1) — u hotovosti, karty, dobírky i zápočtu je formulář
     * skrývá (#62). Hodnoty se při skrytí NEMAžÍ (na rozdíl od `cash_desk`):
     * IBAN je informace o dodavateli (často z AI extrakce), ne o úhradě, a
     * žádné validační pravidlo ho na způsob platby neváže.
     */
    protected function isBankTransferPayment(array $data): bool
    {
        return (int) ($data['payment_method'] ?? 1) === 1;
    }

    protected function cashDeskHint(array $data, string $docCurrency): ?string
    {
        return $this->isCashPayment($data) && empty($data['cash_desk'])
            ? 'Pro měnu ' . strtoupper($docCurrency) . ' není výchozí pokladna — vyber ji ručně'
            : null;
    }

    /** Výchozí pokladna (`is_default`) měny ve stavu V pořádku; null bez ní. */
    protected function resolveDefaultCashDesk(string $currency): ?int
    {
        if ($this->db === null) {
            return null;
        }
        $row = $this->db->fetchRow(
            'SELECT `id` FROM `economy_codebooks_cash_desks`'
            . ' WHERE `currency` = %s AND `is_default` = 1 AND `docState` = 40'
            . ' ORDER BY `sort_order` ASC, `id` ASC LIMIT 1',
            strtolower(trim($currency)),
        );
        return $row !== null ? (int) $row['id'] : null;
    }

    // ── Options resolvers ───────────────────────────────────────────────────

    /**
     * Build options for the `number_series` select.
     *
     * When `$docType` is supplied (per-type form context, e.g. issued invoices),
     * the result is restricted to series of that type. Otherwise all active
     * series are listed (generic "Documents" form).
     *
     * @return list<array{value: int, label: string}>
     */
    protected function resolveNumberSeriesOptions(?string $docType = null): array
    {
        if ($this->db === null) {
            return [];
        }
        if ($docType !== null && $docType !== '') {
            $rows = $this->db->fetchAll(
                'SELECT `id`, `name`, `doc_type` FROM `docs_core_number_series`'
                . ' WHERE `doc_type` = %s AND `docState` IN (10, 40, 80)'
                . ' ORDER BY `name` ASC',
                $docType,
            );
        } else {
            $rows = $this->db->fetchAll(
                'SELECT `id`, `name`, `doc_type` FROM `docs_core_number_series`'
                . ' WHERE `docState` IN (10, 40, 80)'
                . ' ORDER BY `doc_type` ASC, `name` ASC',
            );
        }
        $options = [];
        foreach ($rows as $row) {
            $rowDocType = (string) ($row['doc_type'] ?? '');
            $label = (string) ($row['name'] ?? '');
            // For the generic form (no $docType filter) keep the type tag
            // in the label so users can tell series apart.
            if ($docType === null && $rowDocType !== '') {
                $label .= ' (' . $rowDocType . ')';
            }
            $options[] = ['value' => (int) $row['id'], 'label' => $label];
        }
        return $options;
    }

    /** @return list<array{value: int, label: string}> */
    /**
     * Instance daňových tvrzení registrace dokladu per typ (return/cs/rs) —
     * options selectů vat_period/cs_period/rs_period. Tabulka patří modulu
     * economy.vat (extension sloupců), docs.core na něm nezávisí: bez
     * tabulky (DS bez economy.vat) vrací prázdné options místo pádu.
     *
     * @return array{return: list<array{value: int, label: string}>, cs: list<array{value: int, label: string}>, rs: list<array{value: int, label: string}>}
     */
    protected function resolveReportPeriodOptions(int $vatRegistrationId): array
    {
        $options = ['return' => [], 'cs' => [], 'rs' => []];
        if ($this->db === null || $vatRegistrationId <= 0) {
            return $options;
        }
        try {
            $rows = $this->db->fetchAll(
                'SELECT `id`, `report_type`, `name`, `date_begin`, `date_end` FROM `economy_vat_report_periods`'
                . ' WHERE `vat_registration` = %i AND `docState` != 90'
                . ' ORDER BY `date_begin` DESC, `id` DESC',
                $vatRegistrationId,
            );
        } catch (\Dibi\Exception) {
            return $options;
        }
        foreach ($rows as $row) {
            $type = (string) $row['report_type'];
            if (!isset($options[$type])) {
                continue;
            }
            $begin = $row['date_begin'] instanceof \DateTimeInterface ? $row['date_begin']->format('d.m.Y') : (string) $row['date_begin'];
            $end = $row['date_end'] instanceof \DateTimeInterface ? $row['date_end']->format('d.m.Y') : (string) $row['date_end'];
            $options[$type][] = [
                'value' => (int) $row['id'],
                'label' => $row['name'] . ' (' . $begin . ' – ' . $end . ')',
            ];
        }
        return $options;
    }

    protected function resolveVatRegistrationOptions(): array
    {
        if ($this->db === null) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT `id`, `country`, `vat_id` FROM `economy_codebooks_vat_registrations`'
            . ' WHERE `docState` IN (10, 40, 80)'
            . ' ORDER BY `country` ASC, `id` ASC',
        );
        $options = [];
        foreach ($rows as $row) {
            $country = strtoupper((string) ($row['country'] ?? ''));
            $vatId = (string) ($row['vat_id'] ?? '');
            $label = $country;
            if ($vatId !== '') {
                $label = $label !== '' ? "{$country} — {$vatId}" : $vatId;
            }
            if ($label === '') {
                $label = '#' . $row['id'];
            }
            $options[] = ['value' => (int) $row['id'], 'label' => $label];
        }
        return $options;
    }

    /** @return list<array{value: int, label: string}> */
    protected function resolveBankAccountOptions(string $docCurrency): array
    {
        if ($this->db === null) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT `id`, `code`, `name`, `account_number`, `iban`, `currency`'
            . ' FROM `economy_codebooks_bank_accounts`'
            . ' WHERE `docState` IN (10, 40, 80)'
            . ' ORDER BY `code` ASC, `name` ASC',
        );
        $options = [];
        $docCurrencyLc = strtolower($docCurrency);
        foreach ($rows as $row) {
            $rowCurrency = strtolower((string) ($row['currency'] ?? ''));
            // Soft-filter: prefer matching currency, but include all so users
            // can still pick mismatched accounts manually if needed.
            $label = trim((string) ($row['code'] ?? '') . ' — ' . (string) ($row['name'] ?? ''), ' —');
            if ($rowCurrency !== '' && $rowCurrency !== $docCurrencyLc) {
                $label .= ' (' . strtoupper($rowCurrency) . ')';
            }
            $options[] = ['value' => (int) $row['id'], 'label' => $label];
        }
        return $options;
    }

    /** @return list<array{value: string, label: string}> */
    protected function resolveCurrencyOptions(): array
    {
        if ($this->config === null) {
            return [];
        }
        $cfg = $this->config->cfgItem('world.base.currencies');
        if (!is_array($cfg)) {
            return [];
        }
        return EnumOptionsHelper::fromCfgData($cfg, 'enumString', 'world.base.currencies');
    }

    /** @return list<array{value: int, label: string}> */
    protected function resolveCfgItemOptions(string $cfgItemId): array
    {
        if ($this->config === null) {
            return [];
        }
        $cfg = $this->config->cfgItem($cfgItemId);
        if (!is_array($cfg)) {
            return [];
        }
        $options = [];
        foreach ($cfg as $key => $entry) {
            if (is_array($entry) && isset($entry['name'])) {
                $options[] = ['value' => (int) $key, 'label' => (string) $entry['name']];
            }
        }
        return $options;
    }

    // ── HTML renderers ──────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $data
     * @param list<array<string, mixed>> $recap
     */
    protected function renderRecapHtml(array $data, array $recap = []): string
    {
        if ($recap === []) {
            return '<p class="muted"><em>Doklad zatím nemá rekapitulaci'
                . ' — přidejte řádky a uložte doklad pro přepočet.</em></p>';
        }

        $currency = strtolower((string) ($data['doc_currency'] ?? 'czk'));
        $homeCurrency = strtolower((string) ($data['home_currency'] ?? 'czk'));
        $hasExchange = $currency !== $homeCurrency && $homeCurrency !== '';

        $html = '<table class="vat-recap">';
        $html .= '<thead><tr>'
            . '<th>#</th><th>Sazba</th><th>%</th>'
            . '<th>Měna</th><th>Základ</th><th>Daň</th><th>Celkem</th>'
            . '</tr></thead><tbody>';

        $lineNo = 1;
        foreach ($recap as $r) {
            if (!is_array($r)) {
                continue;
            }
            $code = (string) ($r['vat_code'] ?? '');
            $pct = number_format((float) ($r['vat_pct'] ?? 0), 0, ',', ' ');
            $isPair = !empty($r['is_reverse_pair']);
            $sumBase  = !empty($r['sum_base']);
            $sumTax   = !empty($r['sum_tax']);
            $sumTotal = !empty($r['sum_total']);

            $rowClass = $isPair ? 'reverse-pair' : 'primary';
            $rowSpan = $hasExchange ? 2 : 1;

            $html .= '<tr class="' . $rowClass . '">';
            $html .= "<td rowspan=\"{$rowSpan}\">{$lineNo}.</td>";
            $html .= "<td rowspan=\"{$rowSpan}\">"
                . htmlspecialchars($this->vatCodeLabel($code, $data)) . '</td>';
            $html .= "<td rowspan=\"{$rowSpan}\">{$pct} %</td>";
            $html .= '<td>' . strtoupper(htmlspecialchars($currency)) . '</td>';
            $html .= '<td' . ($sumBase ? '' : ' class="grayed"') . '>'
                . $this->formatMoney($r['base'] ?? 0) . '</td>';
            $html .= '<td' . ($sumTax ? '' : ' class="grayed"') . '>'
                . $this->formatMoney($r['tax'] ?? 0) . '</td>';
            $html .= '<td' . ($sumTotal ? '' : ' class="grayed"') . '>'
                . $this->formatMoney($r['total'] ?? 0) . '</td>';
            $html .= '</tr>';

            if ($hasExchange) {
                $html .= '<tr class="' . $rowClass . '">';
                $html .= '<td>' . strtoupper(htmlspecialchars($homeCurrency)) . '</td>';
                $html .= '<td' . ($sumBase ? '' : ' class="grayed"') . '>'
                    . $this->formatMoney($r['base_dom'] ?? 0) . '</td>';
                $html .= '<td' . ($sumTax ? '' : ' class="grayed"') . '>'
                    . $this->formatMoney($r['tax_dom'] ?? 0) . '</td>';
                $html .= '<td' . ($sumTotal ? '' : ' class="grayed"') . '>'
                    . $this->formatMoney($r['total_dom'] ?? 0) . '</td>';
                $html .= '</tr>';
            }

            $lineNo++;
        }

        $html .= '<tr class="total"><td></td><td colspan="2"><strong>Celkem'
            . ($hasExchange ? ' (' . htmlspecialchars($this->formatExchangeRate($data)) . ')' : '')
            . '</strong></td>';
        $html .= '<td>' . strtoupper(htmlspecialchars($currency)) . '</td>';
        $html .= '<td><strong>' . $this->formatMoney($data['total_base']   ?? 0) . '</strong></td>';
        $html .= '<td><strong>' . $this->formatMoney($data['total_vat']    ?? 0) . '</strong></td>';
        $html .= '<td><strong>' . $this->formatMoney($data['total_amount'] ?? 0) . '</strong></td>';
        $html .= '</tr>';

        if ($hasExchange) {
            $html .= '<tr class="total"><td></td><td colspan="2"></td>';
            $html .= '<td>' . strtoupper(htmlspecialchars($homeCurrency)) . '</td>';
            $html .= '<td><strong>' . $this->formatMoney($data['total_base_dom']   ?? 0) . '</strong></td>';
            $html .= '<td><strong>' . $this->formatMoney($data['total_vat_dom']    ?? 0) . '</strong></td>';
            $html .= '<td><strong>' . $this->formatMoney($data['total_amount_dom'] ?? 0) . '</strong></td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';

        return $html;
    }

    /** @param array<string, mixed> $data */
    protected function renderSnapshotsHtml(array $data): string
    {
        $supplier = $this->decodeSnapshot($data['supplier_snapshot'] ?? null);
        $customer = $this->decodeSnapshot($data['customer_snapshot'] ?? null);

        $html = '<div class="snapshots-container">';
        $html .= '<div class="snapshot-block">';
        $html .= '<h4>Dodavatel</h4>';
        $html .= $this->renderPersonSnapshot($supplier);
        $html .= '</div>';
        $html .= '<div class="snapshot-block">';
        $html .= '<h4>Odběratel</h4>';
        $html .= $this->renderPersonSnapshot($customer);
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    /** @return array<string, mixed>|null */
    protected function decodeSnapshot(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : null;
        }
        return null;
    }

    /** @param array<string, mixed>|null $snap */
    protected function renderPersonSnapshot(?array $snap): string
    {
        if ($snap === null || $snap === []) {
            return '<p class="muted"><em>Není vyplněno</em></p>';
        }

        $h = '';
        if (!empty($snap['name'])) {
            $h .= '<p class="name"><strong>' . htmlspecialchars((string) $snap['name']) . '</strong></p>';
        }

        $idLines = [];
        if (!empty($snap['company_id'])) {
            $idLines[] = 'IČO: ' . htmlspecialchars((string) $snap['company_id']);
        }
        if (!empty($snap['tax_id'])) {
            $idLines[] = 'DIČ: ' . htmlspecialchars((string) $snap['tax_id']);
        }
        if (!empty($snap['vat_id']) && ($snap['vat_id'] ?? null) !== ($snap['tax_id'] ?? null)) {
            $idLines[] = 'DIČ DPH: ' . htmlspecialchars((string) $snap['vat_id']);
        }
        if ($idLines !== []) {
            $h .= '<p>' . implode('<br>', $idLines) . '</p>';
        }

        $address = $snap['address'] ?? null;
        if (is_array($address) && !empty($address['display_block'])) {
            $h .= '<pre class="address">'
                . htmlspecialchars((string) $address['display_block']) . '</pre>';
        }

        if (!empty($snap['court_registration'])) {
            $h .= '<p class="muted">' . htmlspecialchars((string) $snap['court_registration']) . '</p>';
        }

        $contact = $snap['contact'] ?? null;
        if (is_array($contact)) {
            $contactLines = [];
            if (!empty($contact['email'])) {
                $contactLines[] = 'E-mail: ' . htmlspecialchars((string) $contact['email']);
            }
            if (!empty($contact['phone'])) {
                $contactLines[] = 'Tel: ' . htmlspecialchars((string) $contact['phone']);
            }
            if ($contactLines !== []) {
                $h .= '<p class="contact">' . implode('<br>', $contactLines) . '</p>';
            }
        }

        $bank = $snap['bank_account'] ?? null;
        if (is_array($bank)) {
            $bankLines = [];
            if (!empty($bank['account_number'])) {
                $bankLines[] = htmlspecialchars((string) $bank['account_number']);
            }
            if (!empty($bank['iban'])) {
                $bankLines[] = 'IBAN: ' . htmlspecialchars((string) $bank['iban']);
            }
            if (!empty($bank['bic'])) {
                $bankLines[] = 'BIC: ' . htmlspecialchars((string) $bank['bic']);
            }
            if ($bankLines !== []) {
                $h .= '<p class="bank"><strong>Bankovní spojení:</strong><br>'
                    . implode('<br>', $bankLines) . '</p>';
            }
        }

        $vatReg = $snap['vat_registration'] ?? null;
        if (is_array($vatReg) && !empty($vatReg['vat_id'])) {
            $h .= '<p class="vat-reg"><strong>DIČ pro DPH:</strong> '
                . htmlspecialchars((string) $vatReg['vat_id']) . '</p>';
        }

        return $h;
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    protected function formatMoney(mixed $amount): string
    {
        return number_format((float) ($amount ?? 0), 2, ',', ' ');
    }

    /** @param array<string, mixed> $data */
    protected function formatExchangeRate(array $data): string
    {
        $rate = (float) ($data['exchange_rate'] ?? 1.0);
        $doc = strtoupper((string) ($data['doc_currency'] ?? ''));
        $home = strtoupper((string) ($data['home_currency'] ?? ''));
        return "1 {$doc} = " . rtrim(rtrim(number_format($rate, 4, '.', ''), '0'), '.') . " {$home}";
    }

    /** @param array<string, mixed> $data */
    protected function vatCodeLabel(string $code, array $data): string
    {
        if ($code === '') {
            return $code;
        }
        $vatRegId = $data['vat_registration'] ?? null;
        if ($vatRegId === null || $this->db === null) {
            return $code;
        }
        $reg = $this->db->fetchRow(
            'SELECT `country` FROM `economy_codebooks_vat_registrations` WHERE `id` = %i',
            (int) $vatRegId,
        );
        if ($reg === null || empty($reg['country'])) {
            return $code;
        }
        $cfg = $this->config?->cfgItem('world.vat.' . (string) $reg['country']);
        if (!is_array($cfg) || !isset($cfg['vatCodes'][$code])) {
            return $code;
        }
        $codeDef = $cfg['vatCodes'][$code];
        return (string) ($codeDef['fullName'] ?? $codeDef['name'] ?? $code);
    }
}

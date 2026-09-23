<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\TabBuilder;
use Shipard\Core\Form\RecalculateResult;
use Shipard\Core\Form\SubtableCellFormatter;
use Shipard\Core\Form\TableForm;
use Shipard\Module\World\Vat\VatRateResolver;

/**
 * Sub-form for docs_core_rows (Phase 3).
 *
 * Loads parent header context (vat_registration → country, doc_type →
 * direction, vat_place, vat_rate_date = DUZP or issue date, vat_mode,
 * doc_currency) on every render in order to filter VAT codes and resolve
 * vat_pct.
 *
 * Cena, základ, DPH a celkem řádku se přepočítávají živě při každém
 * recalculate stejným kódem jako při uložení (`DocRowCalculator`, #71) —
 * viz applyLiveCalculation.
 */
class DocRowsForm extends TableForm
{
    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        $headContext = $this->loadHeadContext($data['doc_head'] ?? null);

        $rowKind = (int) ($data['row_kind'] ?? 1);
        $isText = $rowKind === 0;
        $headHasVat = $this->headHasVat($headContext);

        $operationOptions = $this->buildOperationOptions($headContext);

        if ($isNew) {
            $data['row_kind'] = $rowKind;
            if (!isset($data['price_calc_mode'])) {
                $data['price_calc_mode'] = 0;
            }
        }

        // Kontační řádek účetního dokladu: operace s deklarovanou vlajkou
        // `rowSide` (1 = strana na řádku, protějšek sideSrc: "row" předpisu;
        // 0 = strany fixní z kroků předpisu — kurzové rozdíly). Vlastní layout
        // bez položkového bloku; faktury — včetně operací s přímým účtem
        // (zálohy, majetek), kde stranu určuje krok předpisu — jdou položkovou
        // větví níže.
        $opAttrs = $this->resolveOperationAttrs((string) ($data['operation'] ?? ''));
        if (!$isText && $this->hasRowSideLayout($opAttrs)) {
            $rowAccount = $opAttrs['rowAccount'] ?? null;
            return $this->buildContationDefinition(
                $operationOptions,
                $opAttrs,
                $rowAccount !== null ? (string) $rowAccount : null,
            );
        }

        $showVat = $headHasVat && !$isText;
        // Způsob výpočtu řídí, které cenové pole je read-only (#71): z ceny za
        // jednotku (0) se dopočítává Cena celkem, z celkové (1) Cena/jednotka.
        $calcMode = (int) ($data['price_calc_mode'] ?? 0);
        $directAccount = is_array($opAttrs)
            && ($opAttrs['rowAccount'] ?? null) === 'direct';

        $col = $this->tab('basic', 'Řádek')
            ->section()
                ->col()
                    ->select('row_kind',
                        options: $this->resolveCfgItemOptions('docs.core.rowKinds'),
                        triggers: 'reload',
                        required: true,
                    )
                    ->select('operation',
                        options: $operationOptions,
                        triggers: 'reload',
                        required: !$isText,
                        hidden: $isText,
                    );

        // Přímý účet (zálohy, majetek) nahrazuje položku — účet definuje
        // zaúčtování, položka by s ním soupeřila.
        if ($directAccount) {
            $col->lookup('account',
                table: 'economy_accounting_accounts',
                filter: ['account_level' => 4],
                placeholder: 'Hledat účet…',
                required: true,
            );
        } else {
            $col->lookup('item',
                table: 'economy_items',
                placeholder: 'Hledat položku…',
                triggers: 'reload',
                hidden: $isText,
                editForm: true,
                createForm: true,
                editTriggers: true,
            );
        }

        $col->input('description')

                    ->separator('Množství a cena', hidden: $isText)
                    // triggers: 'reload' na cenových polích = živý přepočet
                    // (applyLiveCalculation v recalculate, #71); NumberInput
                    // trigger spouští při opuštění pole (#24 B).
                    ->number('quantity', hidden: $isText, triggers: 'reload')
                    ->select('unit',
                        options: $this->resolveUnitOptions(),
                        hidden: $isText,
                    )
                    ->number('unit_price', hidden: $isText, readOnly: $calcMode === 1, triggers: 'reload')
                    ->number('total_price', hidden: $isText, readOnly: $calcMode === 0, triggers: 'reload')
                    ->select('price_calc_mode',
                        options: $this->resolveCfgItemOptions('docs.core.priceCalcModes'),
                        hidden: $isText,
                        triggers: 'reload',
                    )

                    ->separator('Sleva', hidden: $isText)
                    ->number('discount_pct', hidden: $isText, hint: 'Sleva v %', triggers: 'reload')
                    ->number('discount_amount', hidden: $isText, hint: 'Sleva absolutně', triggers: 'reload')

                    ->separator('DPH', hidden: !$showVat)
                    ->select('vat_code',
                        options: $this->buildVatCodeOptions($headContext),
                        triggers: 'reload',
                        required: $showVat,
                        hidden: !$showVat,
                    )
                    ->number('vat_pct',
                        hidden: !$showVat,
                        hint: 'Lze přepsat pro doklady z jiného státu',
                        triggers: 'reload',
                    );
        // Vypočtené vat_base / vat_amount / vat_total nejsou pole formuláře —
        // ukazuje je živý pruh (buildLiveSummary). V $data dál žijí a save
        // je pošle; autoritativně je zapisuje persistRowComputedColumns.

        $this->appendRowIdentityFields($col, $opAttrs);

        // Pořadí řádku se needituje ručně: nový řádek dostane order_pos
        // automaticky (DocRowsDocument::beforeSave), přesun řeší šipky
        // v sub-tabulce (issue #53, fáze 3).

        return new FormDefinition(
            table: $this->table,
            title: 'Řádek dokladu',
            titleNew: 'Nový řádek',
            tabs: [$col->build()],
            liveSummary: $this->buildLiveSummary($data, $headContext),
        );
    }

    /**
     * Živý pruh součtů nad obsahem formuláře (`FormDefinition::$liveSummary`,
     * #71): Základ · DPH · Celkem <měna> z vat_* aktuálních $data. Sestavuje
     * se při každém buildFormDefinition (load i recalculate), takže na rozdíl
     * od header_info odráží neuložený stav; hlavička modalu zůstává uložený
     * stav. Bez DPH na hlavičce jen Celkem. Jen položkový řádek s vypočteným
     * celkem — jinak prázdné pole a klient nic nerenderuje.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $headContext
     * @return list<array{label: string, value: string}>
     */
    private function buildLiveSummary(array $data, ?array $headContext): array
    {
        if ((int) ($data['row_kind'] ?? 1) !== 1) {
            return [];
        }
        $total = SubtableCellFormatter::money($data['vat_total'] ?? null);
        if ($total === null) {
            return [];
        }
        $currency = strtoupper((string) ($headContext['doc_currency'] ?? ''));
        $totalLabel = $currency !== '' ? 'Celkem ' . $currency : 'Celkem';
        if (!$this->headHasVat($headContext)) {
            return [['label' => $totalLabel, 'value' => $total]];
        }
        return [
            ['label' => 'Základ', 'value' => SubtableCellFormatter::money($data['vat_base'] ?? null) ?? '0,00'],
            ['label' => 'DPH',    'value' => SubtableCellFormatter::money($data['vat_amount'] ?? null) ?? '0,00'],
            ['label' => $totalLabel, 'value' => $total],
        ];
    }

    /**
     * Definice formuláře pro kontační řádek účetního dokladu. Skrývá položkový
     * blok (množství/cena/sleva/DPH) a zobrazuje: stranu MD/DAL, částku, popis
     * a per-řádkovou saldo identitu dle vlajek operace. `price_calc_mode` je
     * skryté a fixní 1 (z celkové) — částka se zadává přímo, nedopočítává se.
     *
     * Vstup účtu/položky se zobrazí jen u operací s `rowAccount`
     * (`direct`/`item`). U saldokontních operací (`$rowAccount === null`) je
     * účet implicitní z kategorie účtovacího předpisu (311/321) — vstup se
     * nestaví.
     *
     * Select strany MD/DAL se zobrazí jen při `rowSide: 1`. Operace s
     * `rowSide: 0` (kurzové rozdíly) mají strany fixní z kroků předpisu —
     * řádek nese jen kladnou částku, směr určuje volba operace.
     *
     * @param list<array{value: string, label: string}> $operationOptions
     * @param array<string, mixed> $opAttrs
     */
    private function buildContationDefinition(
        array $operationOptions,
        array $opAttrs,
        ?string $rowAccount,
    ): FormDefinition {
        $section = $this->tab('basic', 'Řádek kontace')
            ->section()
                ->col()
                    ->select('row_kind',
                        options: $this->resolveCfgItemOptions('docs.core.rowKinds'),
                        triggers: 'reload',
                        required: true,
                    )
                    ->select('operation',
                        options: $operationOptions,
                        triggers: 'reload',
                        required: true,
                    );

        if ($rowAccount === 'item') {
            $section->lookup('item',
                table: 'economy_items',
                filter: ['item_type' => 2],
                placeholder: 'Hledat účetní položku…',
                triggers: 'reload',
                required: true,
            );
        } elseif ($rowAccount === 'direct') {
            $section->lookup('account',
                table: 'economy_accounting_accounts',
                filter: ['account_level' => 4],
                placeholder: 'Hledat účet…',
                required: true,
            );
        }

        if (!empty($opAttrs['rowSide'])) {
            $section->select('acc_side',
                options: $this->resolveCfgItemOptions('docs.core.accSides'),
                required: true,
            );
        }

        $section
            ->number('total_price', label: 'Částka', required: true)
            ->input('description')
            ->number('price_calc_mode', hidden: true);

        $this->appendRowIdentityFields($section, $opAttrs);

        return new FormDefinition(
            table: $this->table,
            title: 'Řádek kontace',
            titleNew: 'Nový řádek kontace',
            tabs: [$section->build()],
        );
    }

    /**
     * Defaulty nového řádku z kontextu hlavičky (prefill `defaults[doc_head]`):
     *  - pohyb = první povolený pro doc_type hlavičky (nejnižší order),
     *  - Kód DPH = první z nabídky pro zemi registrace / směr / místo plnění
     *    hlavičky (CZ tuzemsko → „Základní") včetně dopočtu vat_pct stejnou
     *    cestou jako recalculate('vat_code') (issue #60).
     *  - Množství = 1 (položkový řádek bez množství nemá v módu „z ceny za
     *    jednotku" cenu celkem — po výběru položky tak Cena celkem hned ukáže
     *    1 × cena) a živý přepočet, aby vat_* byly konzistentní od prvního
     *    zobrazení (#71).
     * Textový řádek nemá nic z toho; kontační řádek (rowSide) nemá DPH blok,
     * default by zapsal hodnotu do skrytého pole. Explicitní prefill vyhrává
     * a prefillnutý pohyb neblokuje ostatní defaulty.
     */
    public function applyNewRecordDefaults(array &$data): void
    {
        if ((int) ($data['row_kind'] ?? 1) !== 1) {
            return;
        }
        $headContext = $this->loadHeadContext($data['doc_head'] ?? null);

        if (empty($data['operation'])) {
            $options = $this->buildOperationOptions($headContext);
            if ($options !== []) {
                $data['operation'] = $options[0]['value'];
                $this->applyContationRowDefaults($data);
            }
        }

        $opAttrs = $this->resolveOperationAttrs((string) ($data['operation'] ?? ''));
        if ($this->hasRowSideLayout($opAttrs)) {
            return;
        }
        if ($this->headHasVat($headContext) && empty($data['vat_code'])) {
            $vatOptions = $this->buildVatCodeOptions($headContext);
            if ($vatOptions !== []) {
                $data['vat_code'] = $vatOptions[0]['value'];
                $this->deriveVatPct($data, $headContext);
            }
        }
        if (!isset($data['quantity']) || $data['quantity'] === '') {
            $data['quantity'] = 1;
        }
        $this->applyLiveCalculation($data, $headContext);
    }

    /**
     * Má hlavička DPH (vat_mode !== 0)? Bez kontextu hlavičky ne. Jediné
     * místo pravidla pro build i hook.
     *
     * @param array<string, mixed>|null $headContext
     */
    private function headHasVat(?array $headContext): bool
    {
        return $headContext !== null && (int) ($headContext['vat_mode'] ?? 0) !== 0;
    }

    /**
     * Sazba DPH z kódu podle země registrace hlavičky a data sazby
     * (`vat_rate_date`: DUZP, u nedaňového dokladu datum vystavení). Bez
     * data (doklad před uložením) nebo bez známé sazby zůstává ruční zadání
     * — UI ukáže varování. Sdílené hookem a recalculate('vat_code').
     *
     * @param array<string, mixed>|null $headContext
     */
    private function deriveVatPct(array &$data, ?array $headContext): void
    {
        if (empty($data['vat_code'])
            || $headContext === null
            || empty($headContext['country'])
            || empty($headContext['vat_rate_date'])
            || $this->config === null
        ) {
            return;
        }
        $resolver = new VatRateResolver($this->config);
        try {
            $data['vat_pct'] = $resolver->resolveVatPct(
                (string) $headContext['country'],
                (string) $data['vat_code'],
                (string) $headContext['vat_rate_date'],
            );
        } catch (\LogicException) {
            // Unknown rate / no period — leave manual entry; UI shows warning.
        }
    }

    /**
     * Kontační řádek má `price_calc_mode = 1` (z celkové), aby
     * `calculateRowPrice` nepřepsal ručně zadanou `total_price` výpočtem
     * z množství × cena.
     */
    private function applyContationRowDefaults(array &$data): void
    {
        $attrs = $this->resolveOperationAttrs((string) ($data['operation'] ?? ''));
        if ($this->hasRowSideLayout($attrs)) {
            $data['price_calc_mode'] = 1;
        }
    }

    /**
     * Kontační layout = operace s deklarovanou vlajkou `rowSide` — účetní
     * doklad (cmnbkp). `rowSide: 1` = strana MD/DAL na řádku (protějšek
     * sideSrc: "row" předpisu); `rowSide: 0` = strany fixní z kroků předpisu
     * (kurzové rozdíly), select strany se nestaví. Operace s
     * `rowAccount`/`rowPartner`/`rowPaymentId` bez `rowSide` (zálohy,
     * majetek na fakturách) zůstávají v položkovém layoutu.
     *
     * @param array<string, mixed>|null $attrs
     */
    private function hasRowSideLayout(?array $attrs): bool
    {
        return is_array($attrs) && isset($attrs['rowSide']);
    }

    /**
     * Per-řádková saldo identita dle vlajek operace (`rowPartner` /
     * `rowPaymentId`) — sdílené kontačním i položkovým layoutem. Bez vlajek
     * (běžné fakturní operace, text) nepřidá nic.
     *
     * @param array<string, mixed>|null $opAttrs
     */
    private function appendRowIdentityFields(TabBuilder $col, ?array $opAttrs): void
    {
        if (!is_array($opAttrs)) {
            return;
        }
        if (!empty($opAttrs['rowPartner']) || !empty($opAttrs['rowPaymentId'])) {
            $col->separator('Saldo identita');
        }
        if (!empty($opAttrs['rowPartner'])) {
            $col->lookup('partner',
                table: 'base_persons_persons',
                placeholder: 'Hledat partnera…',
            );
        }
        if (!empty($opAttrs['rowPaymentId'])) {
            $col
                ->input('payment_reference', label: 'Variabilní symbol')
                ->input('specific_symbol', label: 'Specifický symbol')
                ->input('constant_symbol', label: 'Konstantní symbol')
                ->date('due_date', label: 'Splatnost');
        }
    }

    /**
     * Atributy operace z cfgItem `docs.core.rowOperations` (vlajky rowPartner /
     * rowPaymentId / rowAccount). Null, když operace není známá.
     *
     * @return array<string, mixed>|null
     */
    private function resolveOperationAttrs(string $operation): ?array
    {
        if ($operation === '' || $this->config === null) {
            return null;
        }
        $cfg = $this->config->cfgItem('docs.core.rowOperations');
        if (!is_array($cfg)) {
            return null;
        }
        $entry = $cfg[$operation] ?? null;
        return is_array($entry) ? $entry : null;
    }

    public function recalculate(string $changedColumn, array $data): RecalculateResult
    {
        $headContext = $this->loadHeadContext($data['doc_head'] ?? null);

        if ($changedColumn === 'row_kind') {
            if ((int) ($data['row_kind'] ?? 1) !== 1) {
                $data['operation'] = null;
            } elseif (empty($data['operation'])) {
                $options = $this->buildOperationOptions($headContext);
                if ($options !== []) {
                    $data['operation'] = $options[0]['value'];
                }
            }
        }

        if ($changedColumn === 'item' && !empty($data['item']) && $this->db !== null) {
            $item = $this->db->fetchRow(
                'SELECT `name`, `sales_price_no_vat`, `unit` FROM `economy_items` WHERE `id` = %i',
                (int) $data['item'],
            );
            if ($item !== null) {
                // Položka = řádek: name, sales_price_no_vat a unit z položky se vždy
                // propisují do řádku. Platí pro výběr z dropdownu, vytvoření nové položky,
                // i edit existující položky (editTriggers: true na lookup). Uživateláňské
                // úpravy unit_price (sleva) se řeší přes samostatná pole
                // discount_pct / discount_amount, ne přepisem unit_price.
                $data['description'] = (string) ($item['name'] ?? '');
                if (!empty($item['sales_price_no_vat'])) {
                    $data['unit_price'] = (float) $item['sales_price_no_vat'];
                }
                if (!empty($item['unit'])) {
                    $data['unit'] = (int) $item['unit'];
                }
            }
        }

        if ($changedColumn === 'vat_code') {
            $this->deriveVatPct($data, $headContext);
        }

        // Kontační řádek (cmnbkp): při změně operace / typu řádku zajisti
        // price_calc_mode = 1, ať se ručně zadaná částka nepřepíše výpočtem.
        if ($changedColumn === 'operation' || $changedColumn === 'row_kind') {
            $this->applyContationRowDefaults($data);
        }

        // Živý přepočet vždy, ne per sloupec — je idempotentní a levný,
        // větvení podle sloupce by přineslo jen chyby z opomenutí.
        $this->applyLiveCalculation($data, $headContext);

        $isNew = !isset($data['id']) || $data['id'] === null || $data['id'] === '';
        return new RecalculateResult(
            $this->buildFormDefinition($data, $isNew),
            $data,
        );
    }

    /**
     * Živý přepočet řádku stejným kódem jako save (`DocRowCalculator`, #71):
     * cena podle způsobu výpočtu, základ / DPH / celkem podle DPH režimu
     * hlavičky. Volá se z recalculate (každý trigger) a z defaultů nového
     * řádku.
     *
     *  - Textový řádek dostane null jako při uložení (calculateRowPrice /
     *    calculateRowVat pro row_kind 0), aby ve skrytých polích nezůstaly
     *    a neuložily se zbytky z doby, kdy byl položkový.
     *  - Kontační řádek (rowSide) se přeskakuje: částka je zadaná ručně,
     *    price_calc_mode fixní 1 a žádné DPH — mód 1 by mu přepsal unit_price
     *    a computeVat zapsal vat_* do řádku bez DPH bloku.
     *  - `total_price` se zapisuje PŘED slevou (past P1) — sleva se promítne
     *    jen do vat_* přes net_total.
     *  - Bez kontextu hlavičky se počítá jako bez DPH (základ = celkem),
     *    stejně degradovaně jako save bez země.
     *
     * @param array<string, mixed>|null $headContext
     */
    private function applyLiveCalculation(array &$data, ?array $headContext): void
    {
        if ((int) ($data['row_kind'] ?? 1) !== 1) {
            $data['total_price'] = null;
            $data['vat_base']    = null;
            $data['vat_amount']  = null;
            $data['vat_total']   = null;
            return;
        }
        $opAttrs = $this->resolveOperationAttrs((string) ($data['operation'] ?? ''));
        if ($this->hasRowSideLayout($opAttrs)) {
            return;
        }

        $price = DocRowCalculator::computePrice($data);
        $data['unit_price']  = $price['unit_price'];
        $data['total_price'] = $price['total_price'];

        $vat = DocRowCalculator::computeVat(
            $price['net_total'],
            $data,
            (int) ($headContext['vat_mode'] ?? 0),
            $this->resolveVatCodesForRow($headContext),
        );
        $data['vat_base']   = $vat['vat_base'];
        $data['vat_amount'] = $vat['vat_amount'];
        $data['vat_total']  = $vat['vat_total'];
    }

    /**
     * Definice DPH kódů země registrace hlavičky pro živý výpočet. Volání je
     * shodné s `DocDocument::resolveVatCodesForDoc` — bez směru a místa,
     * `includeHidden: true` — jinak by noPayTax kódy vyšly ve formuláři jinak
     * než při uložení. `buildVatCodeOptions` filtruje pro nabídku a pro výpočet
     * se nepoužívá. Null bez země / configu / konfigurace země = výpočet bez
     * sémantiky kódů.
     *
     * @param array<string, mixed>|null $headContext
     * @return array<string, array<string, mixed>>|null
     */
    private function resolveVatCodesForRow(?array $headContext): ?array
    {
        if ($headContext === null || empty($headContext['country']) || $this->config === null) {
            return null;
        }
        try {
            return (new VatRateResolver($this->config))->getVatCodes(
                (string) $headContext['country'],
                direction: null,
                place: null,
                includeHidden: true,
            );
        } catch (\LogicException) {
            return null;
        }
    }

    /**
     * @return array{
     *     country: ?string,
     *     direction: ?string,
     *     place: string,
     *     vat_duzp: ?string,
     *     vat_rate_date: ?string,
     *     vat_mode: int,
     *     doc_type: string,
     *     cash_dir: int,
     *     vat_place: int,
     *     doc_currency: string,
     * }|null
     */
    private function loadHeadContext(mixed $docHeadId): ?array
    {
        return DocHeadVatContext::load($this->db, $this->config, $docHeadId);
    }

    /**
     * @param array<string, mixed>|null $context
     * @return list<array{value: string, label: string}>
     */
    private function buildVatCodeOptions(?array $context): array
    {
        return DocHeadVatContext::vatCodeOptions($context, $this->config);
    }

    /** @return list<array{value: int, label: string}> */
    private function resolveUnitOptions(): array
    {
        if ($this->db === null) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT `id`, `name`, `shortcut` FROM `core_units`'
            . ' WHERE `docState` IN (10, 40, 80)'
            . ' ORDER BY `name` ASC',
        );
        $options = [];
        foreach ($rows as $row) {
            $name = (string) ($row['name'] ?? '');
            $shortcut = (string) ($row['shortcut'] ?? '');
            $label = $shortcut !== '' ? "{$name} ({$shortcut})" : $name;
            $options[] = ['value' => (int) $row['id'], 'label' => $label];
        }
        return $options;
    }

    /**
     * Options pohybů filtrované podle `doc_type` hlavičky (a u pokladního
     * dokladu podle `cash_dir` — pohyb s `cashDir` se nabízí jen pro shodný
     * směr), řazené dle `docTypes[docType].order` vzestupně (první = default
     * pro nový řádek). `name` z cfgItem je už lokalizované compiled configem.
     *
     * @param array<string, mixed>|null $headContext
     * @return list<array{value: string, label: string}>
     */
    private function buildOperationOptions(?array $headContext): array
    {
        $docType = (string) ($headContext['doc_type'] ?? '');
        if ($docType === '' || $this->config === null) {
            return [];
        }
        $cfg = $this->config->cfgItem('docs.core.rowOperations');
        if (!is_array($cfg)) {
            return [];
        }
        $cashDir = (int) ($headContext['cash_dir'] ?? 0);

        $entries = [];
        foreach ($cfg as $key => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $docTypeAttrs = $entry['docTypes'][$docType] ?? null;
            if (!is_array($docTypeAttrs)) {
                continue;
            }
            if (isset($docTypeAttrs['cashDir']) && (int) $docTypeAttrs['cashDir'] !== $cashDir) {
                continue;
            }
            $entries[] = [
                'value' => (string) $key,
                'label' => (string) ($entry['name'] ?? $key),
                'order' => (int) ($docTypeAttrs['order'] ?? 0),
            ];
        }
        usort($entries, fn(array $a, array $b) => $a['order'] <=> $b['order']);

        return array_map(
            fn(array $e) => ['value' => $e['value'], 'label' => $e['label']],
            $entries,
        );
    }

    /** @return list<array{value: int, label: string}> */
    private function resolveCfgItemOptions(string $cfgItemId): array
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
}

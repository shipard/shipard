<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets;

use Shipard\Core\Form\EnumOptionsHelper;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormTab;
use Shipard\Core\Form\RecalculateResult;
use Shipard\Core\Form\SubtableCellFormatter;
use Shipard\Core\Form\TableForm;
use Shipard\Core\Settings\SettingsStore;
use Shipard\Module\Economy\Assets\Depreciation\DepreciationSettings;
use Shipard\Module\World\Assets\TaxDepreciationRules;

/**
 * Formulář karty majetku (docs/assets.md D19–D23, D30, D38;
 * tasks/assets-phase1.md, assets-phase2b.md).
 *
 * Podmíněné chování řídí recalculate (trigger `reload`):
 *   - typ → předvyplní účetní skupinu do prázdného pole a druh, dokud
 *     u nové karty drží výchozí hodnotu ze schématu (druh nikdy není
 *     prázdný, jinak by výběr typu nemohl nic předvyplnit);
 *   - dlouhodobý druh (příznak longTerm) skrývá cenu a zároveň ji
 *     v datech nuluje — skryté pole se posílá do uložení a AssetDocument
 *     by vrátil chybu na poli, které uživatel nevidí; datum pořízení
 *     a vyřazení jsou u něj jen ke čtení (plní je události, D38);
 *   - cizí majetek odkrývá povinného vlastníka, odškrtnutí ho vyprázdní;
 *   - odepisovaný druh přidává tab Odpisy: daňová metoda z pravidel země
 *     (nabídka podle data zařazení, před zařazením všechny), skupina /
 *     pravidlo podle metody, účetní metoda a délka jen u časové.
 *
 * Tab Události (jen uložená dlouhodobá karta) je sub-tabulka
 * s nezávislými řádky: události vznikají z akcí detailu, tady se
 * otevírají vlastním dialogem (AssetEventsForm) i nad kartou V pořádku.
 */
class AssetsForm extends TableForm
{
    public const EVENTS_TAB = 'events';
    public const EVENTS_TABLE = 'economy_assets_events';

    private ?AssetPlanService $planService = null;

    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        $categories = new AssetCategories($this->config);
        $category   = (string) ($data['category'] ?? '');
        $longTerm   = $category !== '' && $categories->isLongTerm($category);
        $isForeign  = !empty($data['is_foreign']);

        $card = $this->tab('card', 'Karta')
            ->section()
                ->col()
                    ->input(
                        'asset_number',
                        placeholder: $isNew ? 'Přidělí se při potvrzení karty' : null,
                        hint: 'Prázdné číslo dostane při potvrzení prefix podle druhu a pořadové číslo.',
                    )
                    ->input('name', required: true)
                    ->input('short_name')
                    ->separator('Zařazení')
                    ->lookup(
                        'asset_type',
                        table: 'economy_assets_types',
                        placeholder: 'Hledat typ majetku…',
                        triggers: 'reload',
                        editForm: true,
                        createForm: true,
                    )
                    ->select(
                        'category',
                        options: $this->cfgOptions(AssetCategories::CFG_ITEM),
                        triggers: 'reload',
                        required: true,
                    )
                    ->select(
                        'tracking',
                        options: $this->cfgOptions('economy.assets.trackingKinds'),
                        required: true,
                    )
                    ->lookup(
                        'accounting_group',
                        table: 'economy_assets_accounting_groups',
                        placeholder: 'Hledat účetní skupinu…',
                        required: $longTerm,
                        hint: $longTerm ? 'U dlouhodobého majetku povinná — určuje účty pro zařazení, odpisy a vyřazení.' : null,
                    )
                    ->separator('Vlastnictví')
                    ->checkbox('is_foreign', triggers: 'reload')
                    ->lookup(
                        'owner',
                        table: 'base_persons_persons',
                        placeholder: 'Hledat osobu…',
                        required: $isForeign,
                        hidden: !$isForeign,
                        editForm: true,
                        createForm: true,
                    )
                    ->separator('Pořízení a vyřazení')
                    ->date(
                        'acquired_date',
                        readOnly: $longTerm,
                        hint: $longTerm ? 'U dlouhodobého majetku plní datum událost zařazení nebo počáteční stav.' : null,
                    )
                    ->date(
                        'disposed_date',
                        readOnly: $longTerm,
                        hint: $longTerm
                            ? 'U dlouhodobého majetku plní datum událost vyřazení — akce Vyřadit na kartě.'
                            : 'Vyplň před ukončením platnosti karty — bez data se karta vyřadit nedá.',
                    )
                    ->number(
                        'price',
                        hidden: $longTerm,
                        hint: 'Jen u drobného majetku. Cena dlouhodobého majetku vzniká ze zařazení a technického zhodnocení.',
                    )
                    ->separator('Poznámka')
                    ->textarea('note')
            ->build();

        $tabs = [$card];
        if ($category !== '' && $categories->isDepreciable($category)) {
            $tabs[] = $this->buildDepreciationTab($data, $categories->isIntangible($category));
        }
        if ($longTerm && !$isNew) {
            $tabs[] = $this->subtableTab(
                self::EVENTS_TAB,
                'Události',
                self::EVENTS_TABLE,
                'asset',
                sort: 'event_date:asc',
                independentRows: true,
            );
        }
        $tabs[] = $this->attachmentsTab();

        return new FormDefinition(
            table: $this->table,
            title: 'Karta majetku',
            titleNew: 'Nová karta majetku',
            tabs: $tabs,
        );
    }

    /**
     * Tab Odpisy (D30): nabídka daňových metod a pravidel podle data
     * zařazení; před zařazením všechny (platnost se ověří při zařazení).
     *
     * @param array<string, mixed> $data
     */
    private function buildDepreciationTab(array $data, bool $intangible): FormTab
    {
        $rules = $this->planService()->rules();
        $acquired = self::isoDate($data['acquired_date'] ?? null);
        $taxMethod = (string) ($data['tax_method'] ?? '');
        $kind = $taxMethod !== '' ? $rules->methodKind($taxMethod) : null;
        $usesRule = $kind === TaxDepreciationRules::KIND_ANNUAL || $kind === TaxDepreciationRules::KIND_MONTHLY;
        $accTime = (string) ($data['acc_method'] ?? '') === DepreciationSettings::ACC_TIME;

        $methodOptions = [];
        foreach ($rules->availableMethods($acquired, $intangible) as $method) {
            $methodOptions[] = ['value' => $method, 'label' => $rules->methodName($method)];
        }
        $ruleOptions = [];
        if ($usesRule) {
            foreach ($rules->rules($taxMethod, $acquired) as $rule) {
                $ruleOptions[] = ['value' => $rule['code'], 'label' => $rule['name']];
            }
        }

        return $this->tab('depreciation', 'Odpisy')
            ->section()
                ->col()
                    ->separator('Daňové odpisy')
                    ->select(
                        'tax_method',
                        options: $methodOptions,
                        triggers: 'reload',
                        required: true,
                        hint: $acquired === null
                            ? 'Nabídka platí pro dnešní zařazení; platnost k datu zařazení se ověří při zařazení.'
                            : 'Nabídka platí pro zařazení ' . (new \DateTimeImmutable($acquired))->format('j. n. Y') . '.',
                    )
                    ->select(
                        'tax_rule',
                        options: $ruleOptions,
                        required: $usesRule,
                        hidden: !$usesRule,
                    )
                    ->separator('Účetní odpisy')
                    ->select(
                        'acc_method',
                        options: $this->cfgOptions('economy.assets.accMethods'),
                        triggers: 'reload',
                        required: true,
                    )
                    ->number(
                        'acc_months',
                        required: $accTime,
                        hidden: !$accTime,
                        hint: 'Doba odpisování v měsících (roky × 12, např. 5 let = 60).',
                    )
            ->build();
    }

    public function recalculate(string $changedColumn, array $data): RecalculateResult
    {
        $categories = new AssetCategories($this->config);

        if ($changedColumn === 'asset_type') {
            $this->applyTypeDefaults($data, $categories);
        }

        if ($changedColumn === 'category' || $changedColumn === 'asset_type') {
            $category = (string) ($data['category'] ?? '');
            if ($category !== '' && $categories->isLongTerm($category)) {
                $data['price'] = null;
            }
        }

        if ($changedColumn === 'is_foreign' && empty($data['is_foreign'])) {
            $data['owner'] = null;
        }

        if ($changedColumn === 'tax_method') {
            // Pravidlo předchozí metody v nové nabídce nemusí být.
            $rules = $this->planService()->rules();
            $taxMethod = (string) ($data['tax_method'] ?? '');
            $codes = $taxMethod !== ''
                ? array_column($rules->rules($taxMethod, self::isoDate($data['acquired_date'] ?? null)), 'code')
                : [];
            if (!in_array((string) ($data['tax_rule'] ?? ''), $codes, true)) {
                $data['tax_rule'] = count($codes) === 1 ? $codes[0] : null;
            }
        }
        if ($changedColumn === 'acc_method' && (string) ($data['acc_method'] ?? '') !== DepreciationSettings::ACC_TIME) {
            $data['acc_months'] = null;
        }

        return new RecalculateResult($this->buildFormDefinition($data, empty($data['id'])), $data);
    }

    /**
     * Sub-tabulka Události: datum, druh, okruh, období, částka, původ,
     * poznámka; stav řádku přes `stateStyle`.
     */
    public function renderSubtable(FormTab $tab, array $rows, array $parentData): array
    {
        if ($tab->id !== self::EVENTS_TAB) {
            return parent::renderSubtable($tab, $rows, $parentData);
        }
        $childDef = $this->tables[self::EVENTS_TABLE] ?? null;
        $label = fn(string $column, string $fallback): string => $this->subtableLabel(self::EVENTS_TABLE, $column, $fallback);

        $columns = [
            ['id' => 'event_date', 'label' => $label('event_date', 'Datum'), 'width' => 100],
            ['id' => 'event_kind', 'label' => $label('event_kind', 'Druh'), 'width' => 170],
            ['id' => 'scope', 'label' => $label('scope', 'Okruh'), 'width' => 90],
            ['id' => 'period', 'label' => 'Období', 'width' => 180],
            ['id' => 'amount', 'label' => $label('amount', 'Částka'), 'align' => 'right', 'width' => 120],
            ['id' => 'origin', 'label' => $label('origin', 'Původ'), 'width' => 80],
            ['id' => 'note', 'label' => $label('note', 'Poznámka'), 'grow' => true],
        ];

        $out = [];
        foreach ($rows as $row) {
            $cells = [
                'event_date' => SubtableCellFormatter::date($row['event_date'] ?? null) ?? '',
                'event_kind' => $this->cfgItemLabel('economy.assets.eventKinds', $row['event_kind'] ?? '')
                    . (!empty($row['half_year']) ? ' (½)' : ''),
                'scope'      => $this->cfgItemLabel('economy.assets.eventScopes', $row['scope'] ?? ''),
                'origin'     => $this->cfgItemLabel('economy.assets.eventOrigins', $row['origin'] ?? ''),
            ];
            if (!empty($row['period_begin']) && !empty($row['period_end'])) {
                $cells['period'] = SubtableCellFormatter::date($row['period_begin']) . ' – ' . SubtableCellFormatter::date($row['period_end']);
            }
            if ((string) ($row['event_kind'] ?? '') !== 'interruption' && (string) ($row['event_kind'] ?? '') !== 'disposal') {
                $cells['amount'] = SubtableCellFormatter::number($row['amount'] ?? 0, 2) ?? '';
            }
            if (!empty($row['note'])) {
                $cells['note'] = (string) $row['note'];
            }
            $entry = ['id' => (int) ($row['id'] ?? 0), 'cells' => $cells];
            $style = $childDef !== null ? $this->subtableRowStateStyle($childDef, $row) : null;
            if ($style !== null) {
                $entry['stateStyle'] = $style;
            }
            $out[] = $entry;
        }

        return ['columns' => $columns, 'rows' => $out, 'order_column' => null];
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Výchozí hodnoty z typu (D25). Účetní skupina jen do prázdného pole;
     * druh jen u nové karty, která ještě drží výchozí druh ze schématu.
     *
     * @param array<string, mixed> $data
     */
    private function applyTypeDefaults(array &$data, AssetCategories $categories): void
    {
        if (empty($data['asset_type']) || $this->db === null) {
            return;
        }
        $type = $this->db->fetchRow(
            'SELECT `default_category`, `default_accounting_group` FROM `economy_assets_types` WHERE `id` = %i',
            (int) $data['asset_type'],
        );
        if ($type === null) {
            return;
        }

        $defaultCategory = (string) ($type['default_category'] ?? '');
        if ($defaultCategory !== ''
            && !$categories->isUnknown($defaultCategory)
            && empty($data['id'])
            && (string) ($data['category'] ?? '') === $this->schemaDefaultCategory()
        ) {
            $data['category'] = $defaultCategory;
        }

        if (empty($data['accounting_group']) && !empty($type['default_accounting_group'])) {
            $data['accounting_group'] = (int) $type['default_accounting_group'];
        }
    }

    private function schemaDefaultCategory(): string
    {
        if ($this->tableDef !== null) {
            foreach ($this->tableDef->columns as $col) {
                if ($col->id === 'category') {
                    return (string) ($col->default ?? '');
                }
            }
        }
        return 'small';
    }

    private function planService(): AssetPlanService
    {
        return $this->planService ??= new AssetPlanService(
            $this->db?->getDibiConnection(),
            $this->config,
            $this->dsConfig?->getCountry() ?? 'cz',
            $this->db !== null ? new SettingsStore($this->db) : null,
        );
    }

    private static function isoDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        $string = trim((string) ($value ?? ''));
        return $string !== '' ? substr($string, 0, 10) : null;
    }

    /** @return list<array{value: string, label: string}> */
    private function cfgOptions(string $cfgItemId): array
    {
        $cfg = $this->config?->cfgItem($cfgItemId);
        return is_array($cfg) ? EnumOptionsHelper::fromCfgData($cfg, 'enumString', $cfgItemId) : [];
    }
}

<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets;

use Shipard\Core\Form\EnumOptionsHelper;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\RecalculateResult;
use Shipard\Core\Form\TableForm;

/**
 * Formulář karty majetku (docs/assets.md D19–D23, tasks/assets-phase1.md).
 *
 * Podmíněné chování řídí recalculate (trigger `reload`):
 *   - typ → předvyplní účetní skupinu do prázdného pole a druh, dokud
 *     u nové karty drží výchozí hodnotu ze schématu (druh nikdy není
 *     prázdný, jinak by výběr typu nemohl nic předvyplnit);
 *   - dlouhodobý druh (příznak longTerm) skrývá cenu a zároveň ji
 *     v datech nuluje — skryté pole se posílá do uložení a AssetDocument
 *     by vrátil chybu na poli, které uživatel nevidí;
 *   - cizí majetek odkrývá povinného vlastníka, odškrtnutí ho vyprázdní.
 */
class AssetsForm extends TableForm
{
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
                    ->date('acquired_date')
                    ->date('disposed_date', hint: 'Vyplň před ukončením platnosti karty — bez data se karta vyřadit nedá.')
                    ->number(
                        'price',
                        hidden: $longTerm,
                        hint: 'Jen u drobného majetku. Cena dlouhodobého majetku vzniká ze zařazení a technického zhodnocení.',
                    )
                    ->separator('Poznámka')
                    ->textarea('note')
            ->build();

        return new FormDefinition(
            table: $this->table,
            title: 'Karta majetku',
            titleNew: 'Nová karta majetku',
            tabs: [$card, $this->attachmentsTab()],
        );
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

        return new RecalculateResult($this->buildFormDefinition($data, empty($data['id'])), $data);
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

    /** @return list<array{value: string, label: string}> */
    private function cfgOptions(string $cfgItemId): array
    {
        $cfg = $this->config?->cfgItem($cfgItemId);
        return is_array($cfg) ? EnumOptionsHelper::fromCfgData($cfg, 'enumString', $cfgItemId) : [];
    }
}

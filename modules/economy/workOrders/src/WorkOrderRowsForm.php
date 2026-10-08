<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\WorkOrders;

use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\RecalculateResult;
use Shipard\Core\Form\TableForm;
use Shipard\Module\Docs\Core\DocDocument;
use Shipard\Module\Docs\Core\DocHeadVatContext;
use Shipard\Module\Docs\Core\DocRowOperationRules;
use Shipard\Module\Economy\WorkOrders\Invoicing\Contributor\InvoiceContributorRegistry;
use Shipard\Module\Economy\WorkOrders\Invoicing\InvoicingSettingsResolver;

/**
 * Sub-formulář řádku předpisu periodické zakázky (tab Řádky ve
 * WorkOrdersForm, docs/work-orders.md §5.3). Nabídky závisí na efektivním
 * typu dokladu zakázky (InvoicingSettingsResolver): kódy DPH pro směr
 * a zemi výchozí registrace DPH (DocHeadVatContext::vatCodeOptions), pohyby
 * cílového dokladu z `docs.core.rowOperations` (prázdný = výchozí pohyb
 * dokladu, systémové pohyby se nenabízí). Výběr položky dosadí popis,
 * jednotku a prodejní cenu jako u řádku dokladu (DocRowsForm).
 *
 * Pole Přispěvatel obsahu se ukáže jen s registrovaným přispěvatelem
 * (`contributorOptions()`, workOrderInvoiceContributors).
 */
class WorkOrderRowsForm extends TableForm
{
    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        $parent = $this->loadParentContext($data['work_order'] ?? null);
        $docType = $parent['doc_type'] ?? null;
        $contributors = $this->contributorOptions();
        $noContributors = $contributors === [];

        $tab = $this->tab('basic', 'Řádek')
            ->section()
                ->col()
                    ->lookup(
                        'item',
                        table: 'economy_items',
                        placeholder: 'Hledat položku…',
                        triggers: 'reload',
                        editForm: true,
                        createForm: true,
                        editTriggers: true,
                    )
                    ->input('description', required: true)
                    ->separator('Množství a cena')
                    ->number('quantity', required: true)
                    ->select('unit', options: $this->unitOptions(), required: false)
                    ->number('unit_price', required: true, hint: 'Cena za jednotku bez DPH, nebo s DPH podle režimu DPH předpisu.')
                    ->separator('DPH a pohyb')
                    ->select(
                        'vat_code',
                        options: $this->vatCodeOptions($parent),
                        required: false,
                        hint: $docType === null ? 'Nabídka kódů DPH se řídí typem dokladu zakázky nebo druhu.' : null,
                    )
                    ->select(
                        'operation',
                        options: $this->operationOptions($docType, (string) ($data['operation'] ?? '')),
                        required: false,
                        hint: 'Prázdné = výchozí pohyb cílového dokladu.',
                    )
                    ->separator('Platnost')
                    ->date('valid_from', hint: 'Řádek se fakturuje, když platnost pokrývá DUZP období; prázdné = neomezeno.')
                    ->date('valid_to')
                    ->separator('Podklady', hidden: $noContributors)
                    ->select(
                        'contributor',
                        options: $contributors,
                        required: false,
                        hidden: $noContributors,
                        hint: 'Obsah řádku doplní přispěvatel podle podkladů; prázdné = pevný řádek.',
                    )
            ->build();

        return new FormDefinition(
            table: $this->table,
            title: 'Řádek zakázky',
            titleNew: 'Nový řádek zakázky',
            tabs: [$tab],
        );
    }

    public function recalculate(string $changedColumn, array $data): RecalculateResult
    {
        if ($changedColumn === 'item' && !empty($data['item'])) {
            $item = $this->loadItem((int) $data['item']);
            if ($item !== null) {
                $data['description'] = (string) ($item['name'] ?? '');
                if (!empty($item['sales_price_no_vat'])) {
                    $data['unit_price'] = (float) $item['sales_price_no_vat'];
                }
                if (!empty($item['unit'])) {
                    $data['unit'] = (int) $item['unit'];
                }
            }
        }
        return new RecalculateResult($this->buildFormDefinition($data, empty($data['id'])), $data);
    }

    /**
     * Kontext rodičovské zakázky pro nabídky: efektivní typ dokladu
     * a kontext DPH (země výchozí registrace, směr podle typu dokladu).
     *
     * @return array{doc_type: ?string, country: ?string, direction: ?string, place: string}|null
     */
    protected function loadParentContext(mixed $workOrderId): ?array
    {
        if ($workOrderId === null || $workOrderId === '' || $this->db === null) {
            return null;
        }
        $head = $this->db->fetchRow(
            'SELECT * FROM `' . WorkOrderDocument::TABLE . '` WHERE `id` = %i',
            (int) $workOrderId,
        );
        if ($head === null) {
            return null;
        }
        $kindId = (int) ($head['kind'] ?? 0);
        $kind = $kindId > 0
            ? $this->db->fetchRow('SELECT * FROM `' . KindDocument::TABLE . '` WHERE `id` = %i', $kindId)
            : null;
        $docType = InvoicingSettingsResolver::resolve($head, $kind)->docType;

        $direction = null;
        if ($docType !== null) {
            $direction = match (DocDocument::resolveTradeDir(['doc_type' => $docType], $this->config)) {
                1 => 'output',
                2 => 'input',
                default => null,
            };
        }
        $country = $this->db->fetchSingle(
            'SELECT `country` FROM `economy_codebooks_vat_registrations`'
            . ' WHERE `docState` IN (10, 40, 80) ORDER BY `country` ASC, `id` ASC LIMIT 1',
        );

        return [
            'doc_type'  => $docType,
            'country'   => is_string($country) && $country !== '' ? $country : null,
            'direction' => $direction,
            'place'     => 'domestic',
        ];
    }

    /** Položka (name, sales_price_no_vat, unit), null = neexistuje. */
    protected function loadItem(int $itemId): ?array
    {
        if ($this->db === null) {
            return null;
        }
        return $this->db->fetchRow(
            'SELECT `name`, `sales_price_no_vat`, `unit` FROM `economy_items` WHERE `id` = %i',
            $itemId,
        );
    }

    /**
     * Registrovaní přispěvatelé obsahu (cfgItem z `workOrderInvoiceContributors`)
     * jako options; prázdné = pole se nezobrazí.
     *
     * @return list<array{value: string, label: string}>
     */
    protected function contributorOptions(): array
    {
        return InvoiceContributorRegistry::options($this->config);
    }

    /** @return list<array{value: int, label: string}> */
    protected function unitOptions(): array
    {
        if ($this->db === null) {
            return [];
        }
        $options = [];
        foreach ($this->db->fetchAll(
            'SELECT `id`, `name`, `shortcut` FROM `core_units` WHERE `docState` IN (10, 40, 80) ORDER BY `name` ASC',
        ) as $row) {
            $name = (string) ($row['name'] ?? '');
            $shortcut = (string) ($row['shortcut'] ?? '');
            $options[] = ['value' => (int) $row['id'], 'label' => $shortcut !== '' ? "{$name} ({$shortcut})" : $name];
        }
        return $options;
    }

    /**
     * @param array<string, mixed>|null $parent
     * @return list<array{value: string, label: string}>
     */
    private function vatCodeOptions(?array $parent): array
    {
        return DocHeadVatContext::vatCodeOptions($parent, $this->config);
    }

    /**
     * Pohyby cílového dokladu řazené podle `docTypes[docType].order` (vzor
     * DocRowsForm::buildOperationOptions); systémové jen jako uložená
     * hodnota řádku.
     *
     * @return list<array{value: string, label: string}>
     */
    private function operationOptions(?string $docType, string $current): array
    {
        $cfg = $this->config?->cfgItem('docs.core.rowOperations');
        if ($docType === null || !is_array($cfg)) {
            return [];
        }
        $entries = [];
        foreach ($cfg as $key => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $attrs = $entry['docTypes'][$docType] ?? null;
            if (!is_array($attrs)) {
                continue;
            }
            if (DocRowOperationRules::isSystem((string) $key, $cfg) && (string) $key !== $current) {
                continue;
            }
            $entries[] = [
                'value' => (string) $key,
                'label' => (string) ($entry['name'] ?? $key),
                'order' => (int) ($attrs['order'] ?? 0),
            ];
        }
        usort($entries, static fn(array $a, array $b): int => $a['order'] <=> $b['order']);
        return array_map(static fn(array $e): array => ['value' => $e['value'], 'label' => $e['label']], $entries);
    }
}

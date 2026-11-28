<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\PeriodicInvoicing\Rows;

use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\RecalculateResult;
use Shipard\Core\Form\TableForm;
use Shipard\Module\Docs\Core\DocDocument;
use Shipard\Module\Docs\Core\DocHeadVatContext;
use Shipard\Module\Docs\Core\DocRowOperationRules;
use Shipard\Module\Economy\PeriodicInvoicing\Contributor\InvoiceContributorRegistry;
use Shipard\Module\Economy\PeriodicInvoicing\InvoicingSettingsResolver;

/**
 * Společný základ sub-formuláře řádku předpisu periodické fakturace
 * (#174 D31, tvar řádku #175 U2) — dědí ho formulář řádku každého zdroje
 * fakturace a formulář řádku šablony na druhu; potomek dodá jen rodiče.
 *
 * Nabídky závisí na efektivním typu dokladu rodiče
 * (InvoicingSettingsResolver: záznam → druh): kódy DPH pro směr a zemi
 * výchozí registrace DPH (DocHeadVatContext::vatCodeOptions), pohyby
 * cílového dokladu z `docs.core.rowOperations` (prázdný = výchozí pohyb
 * dokladu, systémové pohyby se nenabízí). Výběr položky dosadí popis,
 * jednotku a prodejní cenu jako u řádku dokladu (DocRowsForm).
 *
 * Řádek šablony na druhu nemá záznam ani platnost: `loadParentHeadAndKind()`
 * vrací `head` null (typ dokladu jde jen z druhu) a `hasValidity()` false
 * (pole platnosti se nestaví — tabulka šablony je nemá).
 *
 * Pole Přispěvatel obsahu se ukáže jen s registrovaným přispěvatelem
 * (`contributorOptions()`, periodicInvoicingContributors). Nápovědy jdou
 * z katalogu `economy.periodicInvoicing.formTexts`.
 */
abstract class AbstractPrescriptionRowsForm extends TableForm
{
    /** Sloupec řádku s id rodiče (záznam zdroje, u šablony druh). */
    abstract protected function parentColumn(): string;

    /**
     * Rodič řádku pro nabídky: řádek záznamu (`head`, sloupce `inv_*`; null
     * u šablony na druhu) a řádek jeho druhu (`kind`); null = rodič
     * neexistuje.
     *
     * @return array{head: array<string, mixed>|null, kind: array<string, mixed>|null}|null
     */
    abstract protected function loadParentHeadAndKind(int $parentId): ?array;

    /** Titulek formuláře existujícího řádku. */
    abstract protected function rowTitle(): string;

    /** Titulek formuláře nového řádku. */
    abstract protected function rowTitleNew(): string;

    /** Má řádek platnost od / do? Šablona na druhu ne. */
    protected function hasValidity(): bool
    {
        return true;
    }

    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        $parent = $this->loadParentContext($data[$this->parentColumn()] ?? null);
        $docType = $parent['doc_type'] ?? null;
        $contributors = $this->contributorOptions();
        $noContributors = $contributors === [];

        $col = $this->tab('basic', 'Řádek')
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
                    ->number(
                        'unit_price',
                        required: true,
                        hint: $this->formText('economy.periodicInvoicing.formTexts', 'row.unitPriceHint', 'Unit price without VAT, or with VAT, depending on the VAT mode of the prescription.'),
                    )
                    ->separator('DPH a pohyb')
                    ->select(
                        'vat_code',
                        options: $this->vatCodeOptions($parent),
                        required: false,
                        hint: $docType === null
                            ? $this->formText('economy.periodicInvoicing.formTexts', 'row.vatCodeHint', 'The VAT codes on offer follow the document type of the record or its kind.')
                            : null,
                    )
                    ->select(
                        'operation',
                        options: $this->operationOptions($docType, (string) ($data['operation'] ?? '')),
                        required: false,
                        hint: $this->formText('economy.periodicInvoicing.formTexts', 'row.operationHint', 'Empty = the default operation of the target document.'),
                    );

        if ($this->hasValidity()) {
            $col = $col
                ->separator('Platnost')
                ->date(
                    'valid_from',
                    hint: $this->formText('economy.periodicInvoicing.formTexts', 'row.validFromHint', 'The row is invoiced when its validity covers the tax point date of the period; empty = unlimited.'),
                )
                ->date('valid_to');
        }

        $tab = $col
            ->separator('Podklady', hidden: $noContributors)
            ->select(
                'contributor',
                options: $contributors,
                required: false,
                hidden: $noContributors,
                hint: $this->formText('economy.periodicInvoicing.formTexts', 'row.contributorHint', 'The contributor fills the row from its source data; empty = a fixed row.'),
            )
            ->build();

        return new FormDefinition(
            table: $this->table,
            title: $this->rowTitle(),
            titleNew: $this->rowTitleNew(),
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
     * Kontext rodiče pro nabídky: efektivní typ dokladu a kontext DPH (země
     * výchozí registrace, směr podle typu dokladu).
     *
     * @return array{doc_type: ?string, country: ?string, direction: ?string, place: string}|null
     */
    protected function loadParentContext(mixed $parentId): ?array
    {
        if ($parentId === null || $parentId === '' || $this->db === null) {
            return null;
        }
        $parent = $this->loadParentHeadAndKind((int) $parentId);
        if ($parent === null) {
            return null;
        }
        $docType = InvoicingSettingsResolver::resolve($parent['head'] ?? [], $parent['kind'] ?? null)->docType;

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
     * Registrovaní přispěvatelé obsahu (cfgItem z `periodicInvoicingContributors`)
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

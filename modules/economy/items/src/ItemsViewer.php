<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Items;

use Shipard\Core\Database\SearchCondition;
use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Settings\SettingsStore;
use Shipard\Core\Viewer\TableViewer;

class ItemsViewer extends TableViewer
{
    protected ?string $docStatesCfgItem = 'core.system.docStatesArchive';

    private const LABELS_CFG_ITEM = 'economy.items.viewerDetailLabels';

    /**
     * Přípona ceny se symbolem domácí měny (` Kč`); prázdná bez nastavené
     * domácí měny. Načítá se jednou na instanci — ne dotaz na každý řádek.
     */
    private ?string $priceSuffix = null;

    private const STATE_SPAN_CLASS = [
        'concept'   => 'warning',
        'confirmed' => 'primary',
        'done'      => 'success',
        'edit'      => 'warning',
        'archive'   => 'muted',
        'trash'     => 'muted',
        'cancelled' => 'danger',
    ];

    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        $sql = 'SELECT i.`id`, i.`code`, i.`name`, i.`description`, i.`item_kind`, i.`item_type`,'
            . ' i.`unit`, i.`sales_price_no_vat`, i.`docState`, i.`docStateMain`,'
            . ' k.`name` AS kind_name, u.`shortcut` AS unit_shortcut'
            . ' FROM `' . $this->table . '` i'
            . ' LEFT JOIN `economy_items_kinds` k ON k.`id` = i.`item_kind`'
            . ' LEFT JOIN `core_units` u ON u.`id` = i.`unit`';

        $conditions = [];
        $params = [];

        $viewGroup = 'active';
        foreach ($filters as $filter) {
            if ($filter['id'] === 'viewGroup') {
                $viewGroup = (string) $filter['value'];
            }
        }

        if ($viewGroup !== 'all' && $this->docStatesCfgItem !== null && $this->config !== null) {
            $cfg = DocStateConfig::fromCfgItem($this->config->cfgItem($this->docStatesCfgItem));
            $states = $cfg->getViewGroupStates($viewGroup);
            if ($states !== []) {
                $placeholders = implode(', ', array_fill(0, count($states), '%i'));
                $conditions[] = 'i.`docState` IN (' . $placeholders . ')';
                $params = array_merge($params, $states);
            } elseif ($viewGroup !== 'active') {
                $conditions[] = '1=0';
            }
        }

        if ($search !== null && $search !== '') {
            [$searchSql, $searchParams] = SearchCondition::anyContains([
                'i.`name`', 'i.`code`', 'i.`description`',
            ], $search);
            $conditions[] = $searchSql;
            $params = array_merge($params, $searchParams);
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY i.`docStateMain` ASC, i.`name` ASC, i.`id` ASC';

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        return $this->db->fetchAll($sql, ...$params);
    }

    public function renderRow(array $rowData): array
    {
        $docState = (int) ($rowData['docState'] ?? 10);
        $stateStyle = $this->resolveStateStyle($docState);

        $row = [
            'id'         => (int) $rowData['id'],
            't1'         => (string) ($rowData['name'] ?? ''),
            'i1'         => $rowData['code'] ?? null,
            'stateStyle' => $stateStyle,
        ];

        $t2 = [];
        $itemTypeLabel = $this->resolveItemTypeLabel((int) ($rowData['item_type'] ?? 3));
        if ($itemTypeLabel !== '') {
            $t2[] = ['text' => $itemTypeLabel, 'class' => 'muted'];
        }
        if (!empty($rowData['kind_name'])) {
            $t2[] = ['text' => (string) $rowData['kind_name']];
        }
        if ($docState !== 10) {
            $cfg = DocStateConfig::fromCfgItem($this->config?->cfgItem($this->docStatesCfgItem));
            $stateData = $cfg->getState($docState);
            $t2[] = [
                'text'  => $stateData['stateName'] ?? '',
                'class' => self::STATE_SPAN_CLASS[$stateStyle] ?? 'muted',
            ];
        }
        $row['t2'] = $t2 !== [] ? $t2 : null;

        $price = $rowData['sales_price_no_vat'] ?? null;
        if ($price !== null && $price !== '') {
            $shortcut = (string) ($rowData['unit_shortcut'] ?? '');
            $formatted = number_format((float) $price, 2, ',', ' ') . $this->priceSuffix();
            if ($shortcut !== '') {
                $formatted .= ' / ' . $shortcut;
            }
            $row['t3'] = $formatted;
        } else {
            $row['t3'] = null;
        }

        return $row;
    }

    protected function loadDetailRecord(int $recordId): ?array
    {
        return $this->db->fetchRow(
            'SELECT i.*, k.`name` AS kind_name, u.`name` AS unit_name, u.`shortcut` AS unit_shortcut'
            . ' FROM `' . $this->table . '` i'
            . ' LEFT JOIN `economy_items_kinds` k ON k.`id` = i.`item_kind`'
            . ' LEFT JOIN `core_units` u ON u.`id` = i.`unit`'
            . ' WHERE i.`id` = %i',
            $recordId,
        );
    }

    /**
     * Přehled: klasifikace, cena, detaily. Kód a název jsou v hlavičce
     * (deklarace `detailHeader`), přílohy položky dodá builder na konec
     * Přehledu (#136 D21).
     */
    protected function detailTabs(array $record): array
    {
        $classificationItems = [];
        $this->addItem($classificationItems, $this->columnLabel('item_kind', 'Item kind'), $record['kind_name'] ?? null);
        $this->addItem($classificationItems, $this->columnLabel('item_type', 'Item type'), $this->resolveItemTypeLabel((int) ($record['item_type'] ?? 3)));

        $pricingItems = [];
        $price = $record['sales_price_no_vat'] ?? null;
        if ($price !== null && $price !== '') {
            $this->addItem(
                $pricingItems,
                $this->columnLabel('sales_price_no_vat', 'Sales price (no VAT)'),
                number_format((float) $price, 2, ',', ' ') . $this->priceSuffix(),
            );
        }
        $unitName = trim((string) ($record['unit_name'] ?? ''));
        $unitShort = trim((string) ($record['unit_shortcut'] ?? ''));
        $unitLabel = $unitShort !== '' && $unitName !== '' ? "{$unitName} ({$unitShort})" : ($unitName !== '' ? $unitName : $unitShort);
        $this->addItem($pricingItems, $this->columnLabel('unit', 'Unit'), $unitLabel);

        $detailItems = [];
        $this->addItem($detailItems, $this->columnLabel('description', 'Description'), $record['description'] ?? null);
        $this->addItem($detailItems, $this->columnLabel('valid_from', 'Valid from'), $this->formatDate($record['valid_from'] ?? null));
        $this->addItem($detailItems, $this->columnLabel('valid_to', 'Valid to'), $this->formatDate($record['valid_to'] ?? null));

        return [$this->propertiesTab([
            ['title' => $this->text('groupClassification', 'Classification'), 'items' => $classificationItems],
            ['title' => $this->text('groupPrice', 'Price'), 'items' => $pricingItems],
            ['title' => $this->text('groupDetails', 'Details'), 'items' => $detailItems],
        ])];
    }

    private function resolveStateStyle(int $docState): string
    {
        if ($this->config === null || $this->docStatesCfgItem === null) {
            return 'concept';
        }
        $cfg = DocStateConfig::fromCfgItem($this->config->cfgItem($this->docStatesCfgItem));
        return $cfg->getState($docState)['stateStyle'] ?? 'concept';
    }

    private function resolveItemTypeLabel(int $key): string
    {
        if ($this->config !== null) {
            $cfg = $this->config->cfgItem('economy.items.itemTypes');
            if (is_array($cfg) && isset($cfg[(string) $key]['name'])) {
                return (string) $cfg[(string) $key]['name'];
            }
        }
        // Bez zkompilované konfigurace anglicky — holá pole `economy.items.itemTypes`.
        return match ($key) {
            0 => 'Service',
            1 => 'Stock',
            2 => 'Accounting',
            default => 'Other',
        };
    }

    /**
     * Mezera + symbol domácí měny za cenu: kód z nastavení
     * `economy.homeCurrency`, symbol z `world.base.currencies` (bez symbolu
     * kód měny velkými písmeny). Bez nastavené domácí měny prázdný řetězec
     * — cena se ukáže jen jako číslo.
     */
    private function priceSuffix(): string
    {
        if ($this->priceSuffix === null) {
            $value = (new SettingsStore($this->db))->get('economy.homeCurrency');
            $code = is_string($value) ? strtolower(trim($value)) : '';
            if ($code === '') {
                $this->priceSuffix = '';
            } else {
                $currencies = $this->config?->cfgItem('world.base.currencies');
                $symbol = is_array($currencies) ? ($currencies[$code]['symbol'] ?? null) : null;
                $this->priceSuffix = ' ' . (is_string($symbol) && $symbol !== '' ? $symbol : strtoupper($code));
            }
        }
        return $this->priceSuffix;
    }

    /**
     * Text vieweru z katalogu `economy.items.viewerDetailLabels` (sekce `texts`).
     *
     * @param array<string, scalar> $params
     */
    private function text(string $key, string $englishFallback, array $params = []): string
    {
        return $this->detailText(self::LABELS_CFG_ITEM, $key, $englishFallback, $params);
    }

    /** @param array<int, array{label: string, value: string}> $items */
    private function addItem(array &$items, string $label, mixed $value): void
    {
        if ($value !== null && $value !== '') {
            $items[] = ['label' => $label, 'value' => (string) $value];
        }
    }
}

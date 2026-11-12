<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Items;

use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Viewer\TableViewer;

class ItemKindsViewer extends TableViewer
{
    protected ?string $docStatesCfgItem = 'core.system.docStatesArchive';

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
        $sql = 'SELECT `id`, `name`, `item_type`, `system_code`, `valid_from`, `valid_to`,'
            . ' `docState`, `docStateMain`'
            . ' FROM `' . $this->table . '`';

        $conditions = [];
        $params = [];

        $viewGroup = 'active';
        foreach ($filters as $filter) {
            if ($filter['id'] === 'viewGroup') {
                $viewGroup = (string) $filter['value'];
            }
        }

        if ($viewGroup !== 'all') {
            [$vgSql, $vgParams] = $this->buildViewGroupFilter($this->docStatesCfgItem, $viewGroup);
            if ($vgSql !== '') {
                $conditions[] = $vgSql;
                $params = array_merge($params, $vgParams);
            }
        }

        if ($search !== null && $search !== '') {
            [$searchSql, $searchParams] = $this->buildSearchCondition(
                ['name', 'system_code'],
                $search,
            );
            if ($searchSql !== '') {
                $conditions[] = $searchSql;
                $params = array_merge($params, $searchParams);
            }
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY `docStateMain` ASC, `name` ASC, `id` ASC';

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
            'i1'         => $this->resolveItemTypeLabel((int) ($rowData['item_type'] ?? 3)),
            'stateStyle' => $stateStyle,
        ];

        $t2 = [];
        if (!empty($rowData['system_code'])) {
            $t2[] = ['text' => $this->sharedText('statusSystem', 'system'), 'class' => 'muted'];
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

        return $row;
    }

    /** Přehled a tab Položky (prvních 100 položek druhu). */
    protected function detailTabs(array $record): array
    {
        $recordId = (int) $record['id'];

        $items = [];
        $this->addItem($items, $this->columnLabel('item_type', 'Item type'), $this->resolveItemTypeLabel((int) ($record['item_type'] ?? 3)));
        $this->addItem($items, $this->columnLabel('valid_from', 'Valid from'), $this->formatDate($record['valid_from'] ?? null));
        $this->addItem($items, $this->columnLabel('valid_to', 'Valid to'), $this->formatDate($record['valid_to'] ?? null));
        if (!empty($record['system_code'])) {
            $this->addItem($items, $this->columnLabel('system_code', 'System code'), (string) $record['system_code']);
        }

        $tabs = [[
            'id'      => 'overview',
            'label'   => $this->defaultOverviewLabel(),
            'content' => [
                'type'   => 'properties',
                'groups' => [['title' => $this->detailText('economy.items.viewerDetailLabels', 'groupItemKind', 'Item kind'), 'items' => $items]],
            ],
        ]];

        $items100 = $this->db->fetchAll(
            'SELECT `id`, `code`, `name` FROM `economy_items` WHERE `item_kind` = %i'
            . ' ORDER BY `name` ASC LIMIT 100',
            $recordId,
        );
        if ($items100 !== []) {
            $rows = [];
            foreach ($items100 as $it) {
                $rows[] = [
                    'code' => (string) ($it['code'] ?? ''),
                    'name' => (string) ($it['name'] ?? ''),
                ];
            }
            $tabs[] = [
                'id'      => 'items',
                'label'   => $this->detailTabLabel('economy.items.viewerDetailLabels', 'items', 'Items'),
                'content' => [
                    'type'    => 'table',
                    'columns' => [
                        ['id' => 'code', 'label' => $this->columnLabel('code', 'Code', 'economy_items')],
                        ['id' => 'name', 'label' => $this->columnLabel('name', 'Name', 'economy_items')],
                    ],
                    'rows' => $rows,
                ],
            ];
        }

        return $tabs;
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

    /** @param array<int, array{label: string, value: string}> $items */
    private function addItem(array &$items, string $label, mixed $value): void
    {
        if ($value !== null && $value !== '') {
            $items[] = ['label' => $label, 'value' => (string) $value];
        }
    }
}

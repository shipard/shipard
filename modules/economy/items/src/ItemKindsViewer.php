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
            $t2[] = ['text' => 'systémový', 'class' => 'muted'];
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
        $this->addItem($items, 'Název', $record['name'] ?? null);
        $this->addItem($items, 'Typ položky', $this->resolveItemTypeLabel((int) ($record['item_type'] ?? 3)));
        $this->addItem($items, 'Platnost od', $this->formatDate($record['valid_from'] ?? null));
        $this->addItem($items, 'Platnost do', $this->formatDate($record['valid_to'] ?? null));
        if (!empty($record['system_code'])) {
            $this->addItem($items, 'Systémový kód', (string) $record['system_code']);
        }

        $tabs = [[
            'id'      => 'overview',
            'label'   => $this->defaultOverviewLabel(),
            'content' => [
                'type'   => 'properties',
                'groups' => [['title' => 'Druh položky', 'items' => $items]],
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
                        ['id' => 'code', 'label' => 'Kód'],
                        ['id' => 'name', 'label' => 'Název'],
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
        return match ($key) {
            0 => 'Služba',
            1 => 'Zásoba',
            2 => 'Účetní položka',
            default => 'Ostatní',
        };
    }

    private function formatDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('j. n. Y');
        }
        $ts = is_string($value) ? strtotime($value) : null;
        return $ts !== null && $ts !== false ? date('j. n. Y', $ts) : null;
    }

    /** @param array<int, array{label: string, value: string}> $items */
    private function addItem(array &$items, string $label, mixed $value): void
    {
        if ($value !== null && $value !== '') {
            $items[] = ['label' => $label, 'value' => (string) $value];
        }
    }
}

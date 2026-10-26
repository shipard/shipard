<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Units;

use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Viewer\TableViewer;

class UnitsViewer extends TableViewer
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
        $sql = 'SELECT `id`, `name`, `shortcut`, `system_code`, `quantity`, `coefficient`, `is_base`,'
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
                ['name', 'shortcut', 'system_code'],
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

        $sql .= ' ORDER BY `docStateMain` ASC, `quantity` ASC, `is_base` DESC, `name` ASC, `id` ASC';

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
            'i1'         => $rowData['shortcut'] ?? null,
            'stateStyle' => $stateStyle,
        ];

        $t2 = [];
        $quantityLabel = $this->resolveQuantityLabel((string) ($rowData['quantity'] ?? ''));
        if ($quantityLabel !== '') {
            $t2[] = ['text' => $quantityLabel, 'class' => 'muted'];
        }
        if (!empty($rowData['is_base'])) {
            $t2[] = ['text' => 'základní', 'class' => 'success'];
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

        $coefficient = $rowData['coefficient'] ?? null;
        if ($coefficient !== null && $coefficient !== '') {
            $row['t3'] = 'Koef.: ' . $this->formatCoefficient((float) $coefficient);
        } else {
            $row['t3'] = null;
        }

        return $row;
    }

    protected function loadDetailRecord(int $recordId): ?array
    {
        return $this->db->fetchRow(
            'SELECT * FROM `' . $this->table . '` WHERE `id` = %i',
            $recordId,
        );
    }

    protected function detailTabs(array $record): array
    {
        $items = [];
        $this->addItem($items, 'Veličina', $this->resolveQuantityLabel((string) ($record['quantity'] ?? '')));
        $coefficient = $record['coefficient'] ?? null;
        if ($coefficient !== null && $coefficient !== '') {
            $this->addItem($items, 'Koeficient', $this->formatCoefficient((float) $coefficient));
        }
        $this->addItem($items, 'Základní jednotka', !empty($record['is_base']) ? 'Ano' : 'Ne');
        if (!empty($record['system_code'])) {
            $this->addItem($items, 'Systémový kód', (string) $record['system_code']);
        }

        return [[
            'id'      => 'overview',
            'label'   => $this->defaultOverviewLabel(),
            'content' => [
                'type'   => 'properties',
                'groups' => [['title' => 'Měrná jednotka', 'items' => $items]],
            ],
        ]];
    }

    private function resolveStateStyle(int $docState): string
    {
        if ($this->config === null || $this->docStatesCfgItem === null) {
            return 'concept';
        }
        $cfg = DocStateConfig::fromCfgItem($this->config->cfgItem($this->docStatesCfgItem));
        return $cfg->getState($docState)['stateStyle'] ?? 'concept';
    }

    private function resolveQuantityLabel(string $key): string
    {
        if ($key === '' || $this->config === null) {
            return $key;
        }
        $cfg = $this->config->cfgItem('core.units.quantities');
        if (is_array($cfg) && isset($cfg[$key]['name'])) {
            return (string) $cfg[$key]['name'];
        }
        return $key;
    }

    private function formatCoefficient(float $value): string
    {
        // Show up to 4 decimals but trim trailing zeros for human-readable output.
        $formatted = rtrim(rtrim(number_format($value, 4, ',', ' '), '0'), ',');
        return $formatted !== '' ? $formatted : '0';
    }

    /** @param array<int, array{label: string, value: string}> $items */
    private function addItem(array &$items, string $label, mixed $value): void
    {
        if ($value !== null && $value !== '') {
            $items[] = ['label' => $label, 'value' => (string) $value];
        }
    }
}

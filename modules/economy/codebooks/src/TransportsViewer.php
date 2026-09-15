<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Codebooks;

use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Viewer\TableViewer;

class TransportsViewer extends TableViewer
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
        $sql = 'SELECT t.`id`, t.`code`, t.`name`, t.`notice`,'
            . ' t.`valid_from`, t.`valid_to`, t.`sort_order`, t.`docState`, t.`docStateMain`,'
            . ' p.`full_name` AS partner_name'
            . ' FROM `' . $this->table . '` t'
            . ' LEFT JOIN `base_persons_persons` p ON p.`id` = t.`partner`';

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
                $conditions[] = 't.' . $vgSql;
                $params = array_merge($params, $vgParams);
            }
        }

        if ($search !== null && $search !== '') {
            $term = '%' . $search . '%';
            $conditions[] = '(t.`code` LIKE %s OR t.`name` LIKE %s)';
            $params[] = $term;
            $params[] = $term;
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY t.`docStateMain` ASC, t.`sort_order` ASC, t.`name` ASC, t.`id` ASC';

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        return $this->db->fetchAll($sql, ...$params);
    }

    public function renderRow(array $rowData): array
    {
        $row = [
            'id' => (int) $rowData['id'],
            't1' => $rowData['name'] ?? '',
            'i1' => $rowData['code'] ?? null,
        ];

        $t2 = [];

        if (!empty($rowData['partner_name'])) {
            $t2[] = ['text' => (string) $rowData['partner_name'], 'class' => 'muted'];
        } else {
            $t2[] = ['text' => 'bez protistrany', 'class' => 'muted'];
        }

        $validFrom = $this->formatDate($rowData['valid_from'] ?? null);
        $validTo = $this->formatDate($rowData['valid_to'] ?? null);
        if ($validFrom !== null && $validTo !== null) {
            $t2[] = ['text' => $validFrom . ' – ' . $validTo, 'class' => 'muted'];
        } elseif ($validFrom !== null) {
            $t2[] = ['text' => 'od ' . $validFrom, 'class' => 'muted'];
        } elseif ($validTo !== null) {
            $t2[] = ['text' => 'do ' . $validTo, 'class' => 'muted'];
        }

        $docState = (int) ($rowData['docState'] ?? 10);
        $cfg = DocStateConfig::fromCfgItem($this->config?->cfgItem($this->docStatesCfgItem));
        $stateData = $cfg->getState($docState);
        $stateStyle = $stateData['stateStyle'] ?? 'concept';

        if ($docState !== 10) {
            $t2[] = [
                'text'  => $stateData['stateName'] ?? '',
                'class' => self::STATE_SPAN_CLASS[$stateStyle] ?? 'muted',
            ];
        }

        $row['t2'] = $t2 !== [] ? $t2 : null;

        if (!empty($rowData['notice'])) {
            $row['t3'] = (string) $rowData['notice'];
        }

        $row['stateStyle'] = $stateStyle;

        return $row;
    }

    public function renderDetail(int $recordId): array
    {
        $record = $this->db->fetchRow(
            'SELECT t.*, p.`full_name` AS partner_name'
            . ' FROM `' . $this->table . '` t'
            . ' LEFT JOIN `base_persons_persons` p ON p.`id` = t.`partner`'
            . ' WHERE t.`id` = %i',
            $recordId,
        );

        if ($record === null) {
            return ['tabs' => []];
        }

        return [
            'tabs' => [
                [
                    'id'      => 'overview',
                    'label'   => $this->defaultOverviewLabel(),
                    'content' => $this->buildOverviewContent($record),
                ],
            ],
        ];
    }

    private function buildOverviewContent(array $record): array
    {
        $identityItems = [];
        $this->addItem($identityItems, 'Kód', $record['code'] ?? null);
        $this->addItem($identityItems, 'Název', $record['name'] ?? null);
        $this->addItem($identityItems, 'Poznámka', $record['notice'] ?? null);

        $settingsItems = [];
        $this->addItem($settingsItems, 'Osoba pro saldokonto', $record['partner_name'] ?? 'bez protistrany');
        $this->addItem($settingsItems, 'Platnost od', $this->formatDate($record['valid_from'] ?? null));
        $this->addItem(
            $settingsItems,
            'Platnost do',
            $this->formatDate($record['valid_to'] ?? null) ?? 'bez konce',
        );
        $this->addItem($settingsItems, 'Pořadí', (string) ($record['sort_order'] ?? 0));

        $groups = [];
        if ($identityItems !== []) {
            $groups[] = ['title' => 'Identifikace', 'items' => $identityItems];
        }
        if ($settingsItems !== []) {
            $groups[] = ['title' => 'Nastavení', 'items' => $settingsItems];
        }

        return ['type' => 'properties', 'groups' => $groups];
    }

    /** @param array<int, array{label: string, value: string}> $items */
    private function addItem(array &$items, string $label, mixed $value): void
    {
        if ($value !== null && $value !== '') {
            $items[] = ['label' => $label, 'value' => (string) $value];
        }
    }

    private function formatDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        return (string) $value;
    }
}

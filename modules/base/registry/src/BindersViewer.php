<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Registry;

use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Viewer\TableViewer;

class BindersViewer extends TableViewer
{
    protected ?string $docStatesCfgItem = 'core.system.docStatesArchive';

    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        $sql = 'SELECT b.`id`, b.`name`, b.`icon`, b.`order_pos`, b.`notice`,'
            . ' b.`docState`, b.`docStateMain`,'
            . ' (SELECT COUNT(*) FROM `base_registry_documents` d'
            . '  WHERE d.`binder` = b.`id` AND d.`docState` != 90) AS `doc_count`'
            . ' FROM `' . $this->table . '` b';

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
                $conditions[] = 'b.' . $vgSql;
                $params = array_merge($params, $vgParams);
            }
        }

        if ($search !== null && $search !== '') {
            [$searchSql, $searchParams] = $this->buildSearchCondition(['name', 'notice'], $search);
            if ($searchSql !== '') {
                $conditions[] = $searchSql;
                $params = array_merge($params, $searchParams);
            }
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY b.`docStateMain` ASC, b.`order_pos` ASC, b.`name` ASC, b.`id` ASC';

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        return $this->db->fetchAll($sql, ...$params);
    }

    public function renderRow(array $rowData): array
    {
        $row = [
            'id'         => (int) $rowData['id'],
            't1'         => (string) ($rowData['name'] ?? ''),
            'stateStyle' => $this->resolveStateStyle((int) ($rowData['docState'] ?? 10)),
        ];

        if (!empty($rowData['icon'])) {
            $row['icon'] = (string) $rowData['icon'];
        }

        $docCount = (int) ($rowData['doc_count'] ?? 0);
        $row['i1'] = $docCount > 0
            ? [['text' => (string) $docCount, 'class' => 'muted']]
            : null;

        $notice = (string) ($rowData['notice'] ?? '');
        $row['t2'] = $notice !== '' ? ['text' => $notice, 'class' => 'muted'] : null;

        return $row;
    }

    protected function loadDetailRecord(int $recordId): ?array
    {
        return $this->db->fetchRow(
            'SELECT b.*, (SELECT COUNT(*) FROM `base_registry_documents` d'
            . '  WHERE d.`binder` = b.`id` AND d.`docState` != 90) AS `doc_count`'
            . ' FROM `' . $this->table . '` b WHERE b.`id` = %i',
            $recordId,
        );
    }

    protected function detailTabs(array $record): array
    {
        $items = [];
        $this->addItem($items, 'Ikona', $record['icon'] ?? null);
        $this->addItem($items, 'Pořadí', (string) ($record['order_pos'] ?? 0));
        $this->addItem($items, 'Poznámka', $record['notice'] ?? null);
        $this->addItem($items, 'Počet dokumentů', (string) ($record['doc_count'] ?? 0));

        return [$this->propertiesTab([['title' => 'Šanon', 'items' => $items]])];
    }

    /** @param array<int, array{label: string, value: string}> $items */
    private function addItem(array &$items, string $label, mixed $value): void
    {
        if ($value !== null && $value !== '') {
            $items[] = ['label' => $label, 'value' => (string) $value];
        }
    }

    private function resolveStateStyle(int $docState): string
    {
        if ($this->config === null || $this->docStatesCfgItem === null) {
            return 'concept';
        }
        $cfg = DocStateConfig::fromCfgItem($this->config->cfgItem($this->docStatesCfgItem));
        return $cfg->getState($docState)['stateStyle'] ?? 'concept';
    }
}

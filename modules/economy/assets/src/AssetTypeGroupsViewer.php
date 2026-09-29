<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets;

/** Viewer skupin typů majetku (Nastavení → Majetek). */
class AssetTypeGroupsViewer extends AssetsViewerBase
{
    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        $sql = 'SELECT g.`id`, g.`name`, g.`note`, g.`sort_order`, g.`docState`, g.`docStateMain`,'
            . ' (SELECT COUNT(*) FROM `economy_assets_types` t WHERE t.`type_group` = g.`id` AND t.`docState` IN (10, 40, 80)) AS `types_count`'
            . ' FROM `' . $this->table . '` g';

        [$conditions, $params] = $this->viewGroupCondition($filters, 'g');

        if ($search !== null && $search !== '') {
            [$searchSql, $searchParams] = $this->buildSearchCondition(['name'], $search);
            $conditions[] = str_replace('`name`', 'g.`name`', $searchSql);
            $params = array_merge($params, $searchParams);
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $sql .= ' ORDER BY g.`docStateMain` ASC, g.`sort_order` ASC, g.`name` ASC, g.`id` ASC';

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        return $this->db->fetchAll($sql, ...$params);
    }

    public function renderRow(array $rowData): array
    {
        $docState = (int) ($rowData['docState'] ?? 10);
        $row = [
            'id' => (int) $rowData['id'],
            't1' => (string) ($rowData['name'] ?? ''),
        ];

        $t2 = [];
        $count = (int) ($rowData['types_count'] ?? 0);
        if ($count > 0) {
            $t2[] = ['text' => $this->cs() ? "Typů: {$count}" : "Types: {$count}", 'class' => 'muted'];
        }
        $badge = $this->stateBadge($docState);
        if ($badge !== null) {
            $t2[] = $badge;
        }
        $row['t2'] = $t2 !== [] ? $t2 : null;

        if (!empty($rowData['note'])) {
            $row['t3'] = (string) $rowData['note'];
        }
        $row['stateStyle'] = $this->stateStyleOf($docState);

        return $row;
    }

    public function renderDetail(int $recordId): array
    {
        $record = $this->db->fetchRow('SELECT * FROM `' . $this->table . '` WHERE `id` = %i', $recordId);
        if ($record === null) {
            return ['tabs' => []];
        }
        $cs = $this->cs();
        $items = [];
        $this->addItem($items, $cs ? 'Název' : 'Name', $record['name'] ?? null);
        $this->addItem($items, $cs ? 'Pořadí' : 'Order', (string) ($record['sort_order'] ?? 0));
        $this->addItem($items, $cs ? 'Poznámka' : 'Note', $record['note'] ?? null);

        return $this->overviewDetail([
            ['title' => $cs ? 'Identifikace' : 'Identity', 'items' => $items],
        ]);
    }
}

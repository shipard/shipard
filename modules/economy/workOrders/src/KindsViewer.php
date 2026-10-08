<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\WorkOrders;

/** Viewer druhů zakázek (Nastavení → Zakázky). */
class KindsViewer extends WorkOrdersViewerBase
{
    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        $sql = 'SELECT k.`id`, k.`name`, k.`type`, k.`notice`, k.`docState`, k.`docStateMain`'
            . ' FROM `' . $this->table . '` k';

        [$conditions, $params] = $this->viewGroupCondition($filters, 'k');

        if ($search !== null && $search !== '') {
            [$searchSql, $searchParams] = $this->buildSearchCondition(['name'], $search);
            $conditions[] = str_replace('`name`', 'k.`name`', $searchSql);
            $params = array_merge($params, $searchParams);
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $sql .= ' ORDER BY k.`docStateMain` ASC, k.`name` ASC, k.`id` ASC';

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
        $type = (string) ($rowData['type'] ?? '');
        if ($type !== '') {
            $t2[] = ['text' => $this->types()->label($type), 'class' => 'primary'];
        }
        $badge = $this->stateBadge($docState);
        if ($badge !== null) {
            $t2[] = $badge;
        }
        $row['t2'] = $t2 !== [] ? $t2 : null;

        if (!empty($rowData['notice'])) {
            $row['t3'] = (string) $rowData['notice'];
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

        $identity = [];
        $this->addItem($identity, $this->text('label.name', 'Name'), $record['name'] ?? null);
        $type = (string) ($record['type'] ?? '');
        $this->addItem($identity, $this->text('label.type', 'Type'), $type !== '' ? $this->types()->label($type) : null);
        $this->addItem($identity, $this->text('label.notice', 'Notice'), $record['notice'] ?? null);

        return $this->overviewDetail([
            ['title' => $this->text('group.identity', 'Identity'), 'items' => $identity],
        ]);
    }
}

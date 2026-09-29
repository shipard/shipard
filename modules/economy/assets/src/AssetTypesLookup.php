<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets;

use Shipard\Core\Database\SearchCondition;
use Shipard\Core\Form\Lookup\LookupItem;
use Shipard\Core\Form\Lookup\TableLookup;

/** Lookup typů majetku — hledá v názvu a zkráceném názvu; sekundárně skupina typů. */
class AssetTypesLookup extends TableLookup
{
    public function getAllowedFilterKeys(): array
    {
        return [];
    }

    public function search(string $q, array $filter, int $limit): array
    {
        if ($this->db === null) {
            return [];
        }
        $q = trim($q);

        $sql = 'SELECT t.`id`, t.`name`, t.`short_name`, tg.`name` AS `group_name`'
            . ' FROM `economy_assets_types` t'
            . ' LEFT JOIN `economy_assets_type_groups` tg ON tg.`id` = t.`type_group`'
            . ' WHERE t.`docState` IN (10, 40, 80)';
        $args = [];

        if ($q !== '') {
            [$searchSql, $searchArgs] = SearchCondition::anyContains(['t.`name`', 't.`short_name`'], $q);
            $sql .= ' AND ' . $searchSql;
            $args = array_merge($args, $searchArgs);
        }
        $sql .= ' ORDER BY t.`sort_order` ASC, t.`name` ASC LIMIT %i';
        $args[] = $limit;

        return array_map(fn($r) => $this->buildItem($r), $this->db->fetchAll($sql, ...$args));
    }

    public function resolve(array $ids): array
    {
        if ($this->db === null || $ids === []) {
            return [];
        }
        $intIds = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));
        if ($intIds === []) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT t.`id`, t.`name`, t.`short_name`, tg.`name` AS `group_name`'
            . ' FROM `economy_assets_types` t'
            . ' LEFT JOIN `economy_assets_type_groups` tg ON tg.`id` = t.`type_group`'
            . ' WHERE t.`id` IN %in',
            $intIds,
        );
        return array_map(fn($r) => $this->buildItem($r), $rows);
    }

    /** @param array<string, mixed> $row */
    private function buildItem(array $row): LookupItem
    {
        $name  = trim((string) ($row['name'] ?? ''));
        $group = trim((string) ($row['group_name'] ?? ''));
        return new LookupItem(
            id: (int) $row['id'],
            primary: $name !== '' ? $name : ('#' . $row['id']),
            secondary: $group !== '' ? $group : null,
        );
    }
}

<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets;

use Shipard\Core\Database\SearchCondition;
use Shipard\Core\Form\Lookup\LookupItem;
use Shipard\Core\Form\Lookup\TableLookup;

/** Lookup účetních skupin majetku — `kód — název`, sekundárně účet majetku. */
class AccountingGroupsLookup extends TableLookup
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

        $sql = 'SELECT g.`id`, g.`code`, g.`name`, a.`number` AS `asset_account`'
            . ' FROM `economy_assets_accounting_groups` g'
            . ' LEFT JOIN `economy_accounting_accounts` a ON a.`id` = g.`account_asset`'
            . ' WHERE g.`docState` IN (10, 40, 80)';
        $args = [];

        if ($q !== '') {
            [$searchSql, $searchArgs] = SearchCondition::anyContains(['g.`code`', 'g.`name`'], $q);
            $sql .= ' AND ' . $searchSql;
            $args = array_merge($args, $searchArgs);
        }
        $sql .= ' ORDER BY g.`sort_order` ASC, g.`code` ASC LIMIT %i';
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
            'SELECT g.`id`, g.`code`, g.`name`, a.`number` AS `asset_account`'
            . ' FROM `economy_assets_accounting_groups` g'
            . ' LEFT JOIN `economy_accounting_accounts` a ON a.`id` = g.`account_asset`'
            . ' WHERE g.`id` IN %in',
            $intIds,
        );
        return array_map(fn($r) => $this->buildItem($r), $rows);
    }

    /** @param array<string, mixed> $row */
    private function buildItem(array $row): LookupItem
    {
        $code = trim((string) ($row['code'] ?? ''));
        $name = trim((string) ($row['name'] ?? ''));
        $primary = $code !== '' && $name !== '' ? "{$code} — {$name}" : ($name !== '' ? $name : ('#' . $row['id']));
        $account = trim((string) ($row['asset_account'] ?? ''));
        return new LookupItem(
            id: (int) $row['id'],
            primary: $primary,
            secondary: $account !== '' ? $account : null,
        );
    }
}

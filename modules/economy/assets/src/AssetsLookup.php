<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets;

use Shipard\Core\Database\SearchCondition;
use Shipard\Core\Form\Lookup\LookupItem;
use Shipard\Core\Form\Lookup\TableLookup;

/**
 * Lookup karet majetku — hledá v inventárním čísle, názvu a zkráceném
 * názvu; display `inv. číslo — název`, sekundárně druh. Konzumenti přijdou
 * s Fází 4 (řádek dokladu) a Fází 7 (příslušenství).
 */
class AssetsLookup extends TableLookup
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

        $sql = 'SELECT `id`, `asset_number`, `name`, `category` FROM `economy_assets_assets`'
            . ' WHERE `docState` IN (10, 40, 80)';
        $args = [];

        if ($q !== '') {
            [$searchSql, $searchArgs] = SearchCondition::anyContains(['`asset_number`', '`name`', '`short_name`'], $q);
            $sql .= ' AND ' . $searchSql;
            $args = array_merge($args, $searchArgs);
        }
        $sql .= ' ORDER BY `asset_number` ASC, `name` ASC LIMIT %i';
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
            'SELECT `id`, `asset_number`, `name`, `category` FROM `economy_assets_assets` WHERE `id` IN %in',
            $intIds,
        );
        return array_map(fn($r) => $this->buildItem($r), $rows);
    }

    /** @param array<string, mixed> $row */
    private function buildItem(array $row): LookupItem
    {
        $number = trim((string) ($row['asset_number'] ?? ''));
        $name   = trim((string) ($row['name'] ?? ''));
        $primary = $number !== '' && $name !== '' ? "{$number} — {$name}" : ($name !== '' ? $name : ('#' . $row['id']));
        $category = (string) ($row['category'] ?? '');
        return new LookupItem(
            id: (int) $row['id'],
            primary: $primary,
            secondary: $category !== '' ? new AssetCategories($this->config)->label($category) : null,
        );
    }
}

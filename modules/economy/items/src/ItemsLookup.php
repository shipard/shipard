<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Items;

use Shipard\Core\Database\SearchCondition;
use Shipard\Core\Form\Lookup\LookupItem;
use Shipard\Core\Form\Lookup\TableLookup;

/**
 * Lookup pro tabulku položek (`economy_items`). Hledá průběžně v kódu, názvu,
 * SKU a EAN. Display popis: primary = `code — name` (nebo jen `name`), bez
 * secondary řádku — položky mají dostatečně výmluvný název samy o sobě.
 */
class ItemsLookup extends TableLookup
{
    /**
     * Filter `item_type` omezí nabídku na daný typ položky (`item_type = N`) —
     * např. řádek kontace „Účetní položka" vybírá jen z položek typu 2.
     * Klientský filtr není bezpečnostní hranice; tvrdé omezení je v dokumentu.
     */
    public function getAllowedFilterKeys(): array
    {
        return ['item_type'];
    }

    public function search(string $q, array $filter, int $limit): array
    {
        if ($this->db === null) {
            return [];
        }
        $q = trim($q);

        $typeSql = '';
        $typeArgs = [];
        if (isset($filter['item_type']) && (string) $filter['item_type'] !== '') {
            $typeSql = ' AND `item_type` = %i';
            $typeArgs[] = (int) $filter['item_type'];
        }

        if ($q === '') {
            $rows = $this->db->fetchAll(
                'SELECT `id`, `code`, `name` FROM `economy_items`'
                . ' WHERE `docState` IN (10, 40, 80)' . $typeSql
                . ' ORDER BY `name` ASC'
                . ' LIMIT %i',
                ...$typeArgs, ...[$limit],
            );
        } else {
            // OR přes hlavní hledatelné sloupce — uživatel může hledat podle
            // čehokoliv, co má na položce/dokladu k dispozici.
            [$searchSql, $searchArgs] = SearchCondition::anyContains(['`name`', '`code`', '`sku`', '`ean`'], $q);
            $rows = $this->db->fetchAll(
                'SELECT `id`, `code`, `name` FROM `economy_items`'
                . ' WHERE `docState` IN (10, 40, 80)' . $typeSql
                . '   AND ' . $searchSql
                . ' ORDER BY `name` ASC'
                . ' LIMIT %i',
                ...$typeArgs, ...$searchArgs, ...[$limit],
            );
        }
        return array_map(fn($r) => $this->buildItem($r), $rows);
    }

    public function resolve(array $ids): array
    {
        if ($this->db === null || $ids === []) {
            return [];
        }
        $intIds = array_filter(array_map('intval', $ids), fn($v) => $v > 0);
        if ($intIds === []) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT `id`, `code`, `name` FROM `economy_items`'
            . ' WHERE `id` IN %in',
            array_values($intIds),
        );
        return array_map(fn($r) => $this->buildItem($r), $rows);
    }

    /** @param array<string, mixed> $row */
    private function buildItem(array $row): LookupItem
    {
        $code = trim((string) ($row['code'] ?? ''));
        $name = trim((string) ($row['name'] ?? ''));
        $primary = $code !== '' && $name !== ''
            ? "{$code} — {$name}"
            : ($name !== '' ? $name : ('#' . $row['id']));

        return new LookupItem(
            id: (int) $row['id'],
            primary: $primary,
            secondary: null,
        );
    }
}

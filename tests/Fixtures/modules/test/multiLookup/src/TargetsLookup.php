<?php

declare(strict_types=1);

namespace Shipard\Module\Test\MultiLookup;

use Shipard\Core\Database\SearchCondition;
use Shipard\Core\Form\Lookup\LookupItem;
use Shipard\Core\Form\Lookup\TableLookup;

/**
 * Fixture (#176): lookup cílové tabulky prvku `multilookup`. Hledá v názvu,
 * filtr `kind` je jediný klíč whitelistu. `resolve()` vrací záznamy
 * **sestupně podle id** — pořadí výstupu lookupu se s pořadím vstupu
 * shodovat nemusí a formulář si ho musí srovnat sám.
 */
class TargetsLookup extends TableLookup
{
    public const TABLE = 'test_multilookup_targets';
    public const FILTER_KIND = 'kind';

    public function getAllowedFilterKeys(): array
    {
        return [self::FILTER_KIND];
    }

    public function search(string $q, array $filter, int $limit): array
    {
        if ($this->db === null) {
            return [];
        }
        $sql = 'SELECT `id`, `name`, `kind` FROM `' . self::TABLE . '` WHERE 1 = 1';
        $args = [];
        $q = trim($q);
        if ($q !== '') {
            [$searchSql, $searchArgs] = SearchCondition::anyContains(['`name`'], $q);
            $sql .= ' AND ' . $searchSql;
            $args = array_merge($args, $searchArgs);
        }
        $kind = trim((string) ($filter[self::FILTER_KIND] ?? ''));
        if ($kind !== '') {
            $sql .= ' AND `kind` = %s';
            $args[] = $kind;
        }
        $sql .= ' ORDER BY `name`, `id` LIMIT %i';
        $args[] = $limit;

        return array_map(self::item(...), $this->db->fetchAll($sql, ...$args));
    }

    public function resolve(array $ids): array
    {
        $intIds = array_values(array_filter(array_map('intval', $ids), static fn (int $v): bool => $v > 0));
        if ($this->db === null || $intIds === []) {
            return [];
        }
        return array_map(self::item(...), $this->db->fetchAll(
            'SELECT `id`, `name`, `kind` FROM `' . self::TABLE . '` WHERE `id` IN %in ORDER BY `id` DESC',
            $intIds,
        ));
    }

    /** @param array<string, mixed> $row */
    private static function item(array $row): LookupItem
    {
        $kind = (string) ($row['kind'] ?? '');
        return new LookupItem((int) $row['id'], (string) ($row['name'] ?? ''), $kind !== '' ? $kind : null);
    }
}

<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accounting;

use Shipard\Core\Database\SearchCondition;
use Shipard\Core\Form\Lookup\LookupItem;
use Shipard\Core\Form\Lookup\TableLookup;

/**
 * Lookup pro účtový rozvrh (`economy_accounting_accounts`). Hledá v čísle
 * a názvu účtu, display: `číslo — název`.
 *
 * Filter `account_level` umožňuje formulářům omezit nabídku na analytické
 * účty (`account_level = 4`) — např. pole Účet na položce typu Účetní
 * položka. Filter `number_prefix` omezí nabídku na řadu účtů (`number LIKE
 * "prefix%"`) — např. bankovní spojení vybírá jen z řady 221. Klientský
 * filtr není bezpečnostní hranice; tvrdé omezení vynucuje validace
 * v dokumentu (ItemDocument, BankAccountDocument).
 */
class AccountsLookup extends TableLookup
{
    public function getAllowedFilterKeys(): array
    {
        return ['account_level', 'number_prefix'];
    }

    public function search(string $q, array $filter, int $limit): array
    {
        if ($this->db === null) {
            return [];
        }
        $q = trim($q);

        $sql = 'SELECT `id`, `number`, `name` FROM `economy_accounting_accounts`'
            . ' WHERE `docState` IN (10, 40, 80)';
        $args = [];

        if (isset($filter['account_level'])) {
            $sql .= ' AND `account_level` = %i';
            $args[] = (int) $filter['account_level'];
        }
        if (isset($filter['number_prefix']) && (string) $filter['number_prefix'] !== '') {
            $sql .= ' AND `number` LIKE %s';
            $args[] = (string) $filter['number_prefix'] . '%';
        }
        if ($q !== '') {
            [$searchSql, $searchArgs] = SearchCondition::anyContains(['`number`', '`name`'], $q);
            $sql .= ' AND ' . $searchSql;
            $args = array_merge($args, $searchArgs);
        }
        $sql .= ' ORDER BY `number` ASC LIMIT %i';
        $args[] = $limit;

        $rows = $this->db->fetchAll($sql, ...$args);
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
            'SELECT `id`, `number`, `name` FROM `economy_accounting_accounts`'
            . ' WHERE `id` IN %in',
            array_values($intIds),
        );
        return array_map(fn($r) => $this->buildItem($r), $rows);
    }

    /** @param array<string, mixed> $row */
    private function buildItem(array $row): LookupItem
    {
        $number = trim((string) ($row['number'] ?? ''));
        $name   = trim((string) ($row['name'] ?? ''));
        $primary = $number !== '' && $name !== ''
            ? "{$number} — {$name}"
            : ($name !== '' ? $name : ('#' . $row['id']));

        return new LookupItem(
            id: (int) $row['id'],
            primary: $primary,
            secondary: null,
        );
    }
}

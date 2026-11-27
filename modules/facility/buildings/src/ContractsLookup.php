<?php

declare(strict_types=1);

namespace Shipard\Module\Facility\Buildings;

use Shipard\Core\Database\SearchCondition;
use Shipard\Core\Form\Lookup\LookupItem;
use Shipard\Core\Form\Lookup\TableLookup;

/**
 * Lookup smluv — hledá v čísle, názvu a jménu nájemce; display
 * `číslo — název`, sekundárně nájemce. Nabízí platné smlouvy (V pořádku
 * a V opravě); koncepty, ukončené, zrušené a smazané ne. Zatím ho žádný
 * formulář nepoužívá — je připravený pro odkaz na smlouvu z dalších agend
 * (#174 fáze 2 a 3).
 */
class ContractsLookup extends TableLookup
{
    public function search(string $q, array $filter, int $limit): array
    {
        if ($this->db === null) {
            return [];
        }
        $q = trim($q);

        $sql = 'SELECT c.`id`, c.`number`, c.`title`, p.`full_name` AS `customer_name`'
            . ' FROM `' . ContractDocument::TABLE . '` c'
            . ' LEFT JOIN `base_persons_persons` p ON p.`id` = c.`customer`'
            . ' WHERE c.`docState` IN %in';
        $args = [ContractDocument::VALID_STATES];

        if ($q !== '') {
            [$searchSql, $searchArgs] = SearchCondition::anyContains(['c.`number`', 'c.`title`', 'p.`full_name`'], $q);
            $sql .= ' AND ' . $searchSql;
            $args = array_merge($args, $searchArgs);
        }
        $sql .= ' ORDER BY c.`number` DESC, c.`title` ASC LIMIT %i';
        $args[] = $limit;

        return array_map(fn ($row) => $this->buildItem($row), $this->db->fetchAll($sql, ...$args));
    }

    public function resolve(array $ids): array
    {
        if ($this->db === null || $ids === []) {
            return [];
        }
        $intIds = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
        if ($intIds === []) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT c.`id`, c.`number`, c.`title`, p.`full_name` AS `customer_name`'
            . ' FROM `' . ContractDocument::TABLE . '` c'
            . ' LEFT JOIN `base_persons_persons` p ON p.`id` = c.`customer`'
            . ' WHERE c.`id` IN %in',
            $intIds,
        );
        return array_map(fn ($row) => $this->buildItem($row), $rows);
    }

    /** @param array<string, mixed> $row */
    private function buildItem(array $row): LookupItem
    {
        $primary = self::label($row);
        $customer = trim((string) ($row['customer_name'] ?? ''));
        return new LookupItem(
            id: (int) $row['id'],
            primary: $primary !== '' ? $primary : ('#' . $row['id']),
            secondary: $customer !== '' ? $customer : null,
        );
    }

    /**
     * Popisek smlouvy `číslo — název` (displayPattern tabulky); koncept bez
     * čísla jen název.
     *
     * @param array<string, mixed> $row
     */
    public static function label(array $row): string
    {
        $number = trim((string) ($row['number'] ?? ''));
        $title = trim((string) ($row['title'] ?? ''));
        if ($number !== '' && $title !== '') {
            return "{$number} — {$title}";
        }
        return $title !== '' ? $title : $number;
    }
}

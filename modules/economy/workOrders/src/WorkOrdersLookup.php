<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\WorkOrders;

use Shipard\Core\Database\SearchCondition;
use Shipard\Core\Form\Lookup\LookupItem;
use Shipard\Core\Form\Lookup\TableLookup;

/**
 * Lookup zakázek — hledá v čísle, názvu a jménu zákazníka; display
 * `číslo — název`, sekundárně zákazník. Výchozí nabídka = zakázky
 * V pořádku a V opravě (pole dimenze Zakázka na dokladech, #110 D23);
 * filtr `role=parent` (pole Nadřazená zakázka) přidá i koncepty, aby šel
 * strom rozpracovat před potvrzením. Ukončené, zrušené a smazané nikdy.
 */
class WorkOrdersLookup extends TableLookup
{
    public const FILTER_ROLE = 'role';
    public const ROLE_PARENT = 'parent';

    private const STATES_DEFAULT = [40, 80];
    private const STATES_PARENT = [10, 40, 80];

    public function getAllowedFilterKeys(): array
    {
        return [self::FILTER_ROLE];
    }

    public function search(string $q, array $filter, int $limit): array
    {
        if ($this->db === null) {
            return [];
        }
        $q = trim($q);
        $states = (string) ($filter[self::FILTER_ROLE] ?? '') === self::ROLE_PARENT ? self::STATES_PARENT : self::STATES_DEFAULT;

        $sql = 'SELECT w.`id`, w.`number`, w.`title`, p.`full_name` AS `customer_name`'
            . ' FROM `' . WorkOrderDocument::TABLE . '` w'
            . ' LEFT JOIN `base_persons_persons` p ON p.`id` = w.`customer`'
            . ' WHERE w.`docState` IN %in';
        $args = [$states];

        if ($q !== '') {
            [$searchSql, $searchArgs] = SearchCondition::anyContains(['w.`number`', 'w.`title`', 'p.`full_name`'], $q);
            $sql .= ' AND ' . $searchSql;
            $args = array_merge($args, $searchArgs);
        }
        $sql .= ' ORDER BY w.`number` DESC, w.`title` ASC LIMIT %i';
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
            'SELECT w.`id`, w.`number`, w.`title`, p.`full_name` AS `customer_name`'
            . ' FROM `' . WorkOrderDocument::TABLE . '` w'
            . ' LEFT JOIN `base_persons_persons` p ON p.`id` = w.`customer`'
            . ' WHERE w.`id` IN %in',
            $intIds,
        );
        return array_map(fn($r) => $this->buildItem($r), $rows);
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
     * Popisek zakázky `číslo — název` (displayPattern tabulky); koncept bez
     * čísla jen název. Sdílí viewer i detail.
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

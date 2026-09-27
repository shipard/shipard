<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Persons;

use Shipard\Core\Database\SearchCondition;
use Shipard\Core\Form\Lookup\LookupItem;
use Shipard\Core\Form\Lookup\TableLookup;

/**
 * Lookup pro tabulku osob s vyhledáváním přes několik polí (full_name,
 * company_id, person_id) a typově citlivým secondary řádkem (firma = IČO,
 * fyzická osoba = datum narození).
 */
class PersonsLookup extends TableLookup
{
    public function search(string $q, array $filter, int $limit): array
    {
        if ($this->db === null) {
            return [];
        }
        $q = trim($q);

        if ($q === '') {
            $rows = $this->db->fetchAll(
                'SELECT `id`, `full_name`, `person_type`, `company_id`, `birth_date`, `person_id`'
                . ' FROM `base_persons_persons`'
                . ' WHERE `docState` IN (10, 40, 80)'
                . ' ORDER BY `full_name` ASC'
                . ' LIMIT %i',
                $limit,
            );
        } else {
            [$searchSql, $searchParams] = SearchCondition::anyContains(['`full_name`', '`company_id`', '`person_id`'], $q);
            $rows = $this->db->fetchAll(
                'SELECT `id`, `full_name`, `person_type`, `company_id`, `birth_date`, `person_id`'
                . ' FROM `base_persons_persons`'
                . ' WHERE `docState` IN (10, 40, 80)'
                . '   AND ' . $searchSql
                . ' ORDER BY `full_name` ASC'
                . ' LIMIT %i',
                ...[...$searchParams, $limit],
            );
        }
        return array_map(fn(array $r) => self::buildItem($r), $rows);
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
            'SELECT `id`, `full_name`, `person_type`, `company_id`, `birth_date`, `person_id`'
            . ' FROM `base_persons_persons`'
            . ' WHERE `id` IN %in',
            $intIds,
        );
        return array_map(fn(array $r) => self::buildItem($r), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function buildItem(array $row): LookupItem
    {
        $primary = trim((string) ($row['full_name'] ?? '')) ?: ('#' . (string) $row['id']);
        $personType = (int) ($row['person_type'] ?? 0);

        $secondary = null;
        if ($personType === PersonType::Company->value && !empty($row['company_id'])) {
            $secondary = 'IČO ' . (string) $row['company_id'];
        } elseif ($personType === PersonType::Person->value && !empty($row['birth_date'])) {
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $row['birth_date']);
            if ($dt instanceof \DateTimeImmutable) {
                $secondary = 'Datum narození ' . $dt->format('d.m.Y');
            }
        }

        return new LookupItem(
            id: (int) $row['id'],
            primary: $primary,
            secondary: $secondary,
        );
    }
}

<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Codebooks;

use Shipard\Core\Form\Lookup\LookupItem;
use Shipard\Core\Form\Lookup\TableLookup;

/**
 * Lookup pro způsoby dopravy (economy_codebooks_transports). Hledá v kódu
 * a názvu; display `kód — název`, sekundárně jméno dopravce (protistrany).
 */
class TransportsLookup extends TableLookup
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

        $sql = self::SELECT . ' WHERE t.`docState` IN (10, 40, 80)';
        $args = [];

        if ($q !== '') {
            $like = '%' . $q . '%';
            $sql .= ' AND (t.`code` LIKE %s OR t.`name` LIKE %s)';
            $args[] = $like;
            $args[] = $like;
        }
        $sql .= ' ORDER BY t.`sort_order` ASC, t.`name` ASC LIMIT %i';
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
        $rows = $this->db->fetchAll(self::SELECT . ' WHERE t.`id` IN %in', array_values($intIds));
        return array_map(fn($r) => $this->buildItem($r), $rows);
    }

    private const SELECT = 'SELECT t.`id`, t.`code`, t.`name`, p.`full_name` AS partner_name'
        . ' FROM `economy_codebooks_transports` t'
        . ' LEFT JOIN `base_persons_persons` p ON p.`id` = t.`partner`';

    /** @param array<string, mixed> $row */
    private function buildItem(array $row): LookupItem
    {
        $code = trim((string) ($row['code'] ?? ''));
        $name = trim((string) ($row['name'] ?? ''));
        $primary = $code !== '' && $name !== ''
            ? "{$code} — {$name}"
            : ($name !== '' ? $name : ('#' . $row['id']));
        $partner = trim((string) ($row['partner_name'] ?? ''));

        return new LookupItem(
            id: (int) $row['id'],
            primary: $primary,
            secondary: $partner !== '' ? $partner : null,
        );
    }
}

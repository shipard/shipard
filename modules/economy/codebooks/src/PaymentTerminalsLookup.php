<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Codebooks;

use Shipard\Core\Form\Lookup\LookupItem;
use Shipard\Core\Form\Lookup\TableLookup;

/**
 * Lookup pro platební terminály a brány (economy_codebooks_payment_terminals).
 * Hledá v kódu a názvu; display `kód — název`, sekundárně druh + pokladna.
 *
 * Cascade filtry `kind` (0 terminál / 1 brána) a `cash_desk` — hlavička
 * dokladu u karty nabízí terminály své pokladny, u brány jen brány.
 */
class PaymentTerminalsLookup extends TableLookup
{
    public function getAllowedFilterKeys(): array
    {
        return ['kind', 'cash_desk'];
    }

    public function search(string $q, array $filter, int $limit): array
    {
        if ($this->db === null) {
            return [];
        }
        $q = trim($q);

        $sql = self::SELECT . ' WHERE t.`docState` IN (10, 40, 80)';
        $args = [];

        if (isset($filter['kind']) && $filter['kind'] !== '') {
            $sql .= ' AND t.`kind` = %i';
            $args[] = (int) $filter['kind'];
        }
        if (isset($filter['cash_desk']) && (int) $filter['cash_desk'] > 0) {
            $sql .= ' AND t.`cash_desk` = %i';
            $args[] = (int) $filter['cash_desk'];
        }
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

    private const SELECT = 'SELECT t.`id`, t.`code`, t.`name`, t.`kind`,'
        . ' cd.`code` AS cash_desk_code'
        . ' FROM `economy_codebooks_payment_terminals` t'
        . ' LEFT JOIN `economy_codebooks_cash_desks` cd ON cd.`id` = t.`cash_desk`';

    /** @param array<string, mixed> $row */
    private function buildItem(array $row): LookupItem
    {
        $code = trim((string) ($row['code'] ?? ''));
        $name = trim((string) ($row['name'] ?? ''));
        $primary = $code !== '' && $name !== ''
            ? "{$code} — {$name}"
            : ($name !== '' ? $name : ('#' . $row['id']));

        $parts = [];
        $kindLabel = $this->kindLabel($row['kind'] ?? null);
        if ($kindLabel !== null) {
            $parts[] = $kindLabel;
        }
        $deskCode = trim((string) ($row['cash_desk_code'] ?? ''));
        if ($deskCode !== '') {
            $parts[] = $deskCode;
        }

        return new LookupItem(
            id: (int) $row['id'],
            primary: $primary,
            secondary: $parts !== [] ? implode(' · ', $parts) : null,
        );
    }

    private function kindLabel(mixed $kind): ?string
    {
        if ($kind === null || $this->config === null) {
            return null;
        }
        $cfg = $this->config->cfgItem('economy.codebooks.paymentTerminalKinds');
        $key = (string) (int) $kind;
        return is_array($cfg) && isset($cfg[$key]['name']) ? (string) $cfg[$key]['name'] : null;
    }
}

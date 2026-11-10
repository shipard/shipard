<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

use Shipard\Core\Database\SearchCondition;
use Shipard\Core\Form\Lookup\LookupItem;
use Shipard\Core\Form\Lookup\TableLookup;

/**
 * Lookup dokladů (`docs_core_heads`, #92 U9) — výběr původního dokladu
 * na opravném dokladu. Nabízí jen doklady V pořádku, hledá v čísle
 * dokladu, variabilním symbolu a názvu partnera; filtry `doc_type` (typ
 * opravovaného dokladu) a `partner`. Řádek: `číslo — partner`, pod ním
 * datum vystavení a částka s měnou. `resolve()` stav neomezuje — vybraný
 * původní doklad se musí zobrazit, i když později změnil stav.
 *
 * Že doklad neodkazuje sám na sebe, hlídá `DocDocument::validateCorrective`
 * (nový doklad id ještě nemá) — lookup ne.
 */
class DocsHeadsLookup extends TableLookup
{
    public const FILTER_DOC_TYPE = 'doc_type';
    public const FILTER_PARTNER = 'partner';

    private const STATE_DONE = 40;

    private const SELECT = 'SELECT h.`id`, h.`doc_number`, h.`issue_date`, h.`total_amount`, h.`doc_currency`,'
        . ' p.`full_name` AS `partner_name`'
        . ' FROM `docs_core_heads` h'
        . ' LEFT JOIN `base_persons_persons` p ON p.`id` = h.`partner`';

    public function getAllowedFilterKeys(): array
    {
        return [self::FILTER_DOC_TYPE, self::FILTER_PARTNER];
    }

    public function search(string $q, array $filter, int $limit): array
    {
        if ($this->db === null) {
            return [];
        }
        $sql = self::SELECT . ' WHERE h.`docState` = %i';
        $args = [self::STATE_DONE];

        $docType = trim((string) ($filter[self::FILTER_DOC_TYPE] ?? ''));
        if ($docType !== '') {
            $sql .= ' AND h.`doc_type` = %s';
            $args[] = $docType;
        }
        $partner = (int) ($filter[self::FILTER_PARTNER] ?? 0);
        if ($partner > 0) {
            $sql .= ' AND h.`partner` = %i';
            $args[] = $partner;
        }
        $q = trim($q);
        if ($q !== '') {
            [$searchSql, $searchArgs] = SearchCondition::anyContains(
                ['h.`doc_number`', 'h.`payment_reference`', 'p.`full_name`'],
                $q,
            );
            $sql .= ' AND ' . $searchSql;
            $args = array_merge($args, $searchArgs);
        }
        $sql .= ' ORDER BY h.`issue_date` DESC, h.`id` DESC LIMIT %i';
        $args[] = $limit;

        return array_map(static fn (array $r) => self::buildItem($r), $this->db->fetchAll($sql, ...$args));
    }

    public function resolve(array $ids): array
    {
        if ($this->db === null || $ids === []) {
            return [];
        }
        $intIds = array_values(array_filter(array_map('intval', $ids), static fn ($v) => $v > 0));
        if ($intIds === []) {
            return [];
        }
        $rows = $this->db->fetchAll(self::SELECT . ' WHERE h.`id` IN %in', $intIds);
        return array_map(static fn (array $r) => self::buildItem($r), $rows);
    }

    /**
     * `číslo — partner`; pod ním `datum vystavení · částka měna`.
     *
     * @param array<string, mixed> $row
     */
    public static function buildItem(array $row): LookupItem
    {
        $number = trim((string) ($row['doc_number'] ?? ''));
        $partner = trim((string) ($row['partner_name'] ?? ''));
        $primary = match (true) {
            $number !== '' && $partner !== '' => "{$number} — {$partner}",
            $number !== ''                    => $number,
            $partner !== ''                   => $partner,
            default                           => '#' . (int) ($row['id'] ?? 0),
        };

        $parts = [];
        $date = self::formatDate($row['issue_date'] ?? null);
        if ($date !== null) {
            $parts[] = $date;
        }
        $amount = $row['total_amount'] ?? null;
        if ($amount !== null && $amount !== '') {
            $text = number_format((float) $amount, 2, ',', ' ');
            $currency = strtoupper(trim((string) ($row['doc_currency'] ?? '')));
            $parts[] = $currency !== '' ? "{$text} {$currency}" : $text;
        }

        return new LookupItem(
            id: (int) ($row['id'] ?? 0),
            primary: $primary,
            secondary: $parts !== [] ? implode(' · ', $parts) : null,
        );
    }

    private static function formatDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('j. n. Y');
        }
        $text = trim((string) ($value ?? ''));
        if ($text === '') {
            return null;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($text, 0, 10));
        return $parsed !== false ? $parsed->format('j. n. Y') : $text;
    }
}

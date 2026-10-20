<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\ExchangeRates\Download;

use Dibi\Connection;
use Shipard\Module\Economy\ExchangeRates\ExchangeRateResolver;

/** Která data lístků typ ve zdroji dat má — jeden dotaz na typ (#121 task B §4.2). Chráněné metody = seam pro testy. */
class ExchangeRateListPresence
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array<string, true> valid_from (Y-m-d) lístků typu v rozsahu včetně */
    public function datesFor(string $listType, string $from, string $to): array
    {
        $dates = [];
        $rows = $this->db->fetchAll(
            'SELECT [valid_from] FROM %n WHERE [list_type] = %s AND [valid_from] BETWEEN %d AND %d',
            ExchangeRateResolver::TABLE_LISTS,
            $listType,
            new \DateTimeImmutable($from),
            new \DateTimeImmutable($to),
        );
        foreach ($rows as $row) {
            $date = ExchangeRateResolver::dateString($row['valid_from']);
            if ($date !== null) {
                $dates[$date] = true;
            }
        }
        return $dates;
    }

    /** Má typ ve zdroji dat aspoň jeden lístek? (upozornění, §6) */
    public function hasAny(string $listType): bool
    {
        $id = $this->db->fetchSingle('SELECT [id] FROM %n WHERE [list_type] = %s LIMIT 1', ExchangeRateResolver::TABLE_LISTS, $listType);
        return $id !== null && $id !== false;
    }
}

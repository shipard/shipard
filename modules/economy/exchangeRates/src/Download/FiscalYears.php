<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\ExchangeRates\Download;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Utils\IsoDate;

/**
 * Fiskální roky pro okno stahování (#121 task B §4.1). Modul
 * economy.exchangeRates na economy.codebooks nezávisí — chybějící tabulka
 * = žádné roky (okno od 1. 1. předchozího kalendářního roku).
 */
final class FiscalYears
{
    public const string TABLE = 'economy_codebooks_fiscal_years';

    /** @return list<array{begin: string, end: string}> vzestupně podle začátku, bez smazaných */
    public static function load(DataSourceConnection $db): array
    {
        if ($db->getTableColumns(self::TABLE) === []) {
            return [];
        }
        $years = [];
        foreach ($db->fetchAll('SELECT [date_begin], [date_end] FROM %n WHERE [docState] != %i ORDER BY [date_begin]', self::TABLE, 90) as $row) {
            $begin = IsoDate::fromDb($row['date_begin']);
            $end = IsoDate::fromDb($row['date_end']);
            if ($begin !== null && $end !== null) {
                $years[] = ['begin' => $begin, 'end' => $end];
            }
        }
        return $years;
    }
}

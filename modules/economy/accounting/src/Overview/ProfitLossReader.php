<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accounting\Overview;

use Shipard\Core\Reports\ReportResult;
use Shipard\Module\Economy\Accounting\Reports\ProfitLossBuilder;

/**
 * Čtení výsledovky pro Přehled firmy (#120 D8) — čistá funkce nad
 * `ReportResult`, bez DB: hodnoty sloupce `ytd` řádků podle stabilního
 * klíče (`result`, `costs`, `revenues`), nikdy podle lokalizovaného
 * popisku. Náklady a výnosy se vrací jako kladná čísla (součet třídy 6
 * má `balance = md − d` záporné); výsledek kladný = zisk.
 */
final class ProfitLossReader
{
    public const COLUMN_YTD = 'ytd';

    /** @return array{result: ?float, costs: ?float, revenues: ?float} null = řádek v reportu není */
    public static function read(ReportResult $report): array
    {
        $out = ['result' => null, 'costs' => null, 'revenues' => null];
        foreach ($report->rows as $row) {
            $balance = $row->values[self::COLUMN_YTD]['balance'] ?? null;
            if ($row->key === null || !is_numeric($balance)) {
                continue;
            }
            match ($row->key) {
                ProfitLossBuilder::KEY_RESULT   => $out['result']   = round((float) $balance, 2),
                ProfitLossBuilder::KEY_COSTS    => $out['costs']    = round((float) $balance, 2),
                ProfitLossBuilder::KEY_REVENUES => $out['revenues'] = round(-(float) $balance, 2),
                default                         => null,
            };
        }
        return $out;
    }
}

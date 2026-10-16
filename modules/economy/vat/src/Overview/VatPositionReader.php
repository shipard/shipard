<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat\Overview;

use Shipard\Core\Reports\ReportResult;

/**
 * Pozice přiznání ze živého reportu (`economy.vat.returnLive`) — čistá
 * funkce nad `ReportResult`: řádek 64 (vlastní daň) nebo 65 (nadměrný
 * odpočet) podle `account`, sloupec `tax`. Report bez řádků = období bez
 * dokladů (`none`); oba řádky nulové = `zero`.
 */
final class VatPositionReader
{
    public const KIND_TAX    = 'tax';
    public const KIND_REFUND = 'refund';
    public const KIND_ZERO   = 'zero';
    public const KIND_NONE   = 'none';

    public const ROW_TAX    = '64';
    public const ROW_REFUND = '65';
    public const COLUMN_TAX = 'tax';

    /** @return array{kind: string, amount: float} částka kladná, druh říká směr */
    public static function read(ReportResult $report): array
    {
        $tax = $refund = null;
        foreach ($report->rows as $row) {
            $value = $row->values[self::COLUMN_TAX]['balance'] ?? null;
            if (!is_numeric($value)) {
                continue;
            }
            if ($row->account === self::ROW_TAX) {
                $tax = round((float) $value, 2);
            } elseif ($row->account === self::ROW_REFUND) {
                $refund = round((float) $value, 2);
            }
        }
        if ($tax === null && $refund === null) {
            return ['kind' => self::KIND_NONE, 'amount' => 0.0];
        }
        if (($tax ?? 0.0) > 0.0) {
            return ['kind' => self::KIND_TAX, 'amount' => $tax];
        }
        if (($refund ?? 0.0) > 0.0) {
            return ['kind' => self::KIND_REFUND, 'amount' => $refund];
        }
        return ['kind' => self::KIND_ZERO, 'amount' => 0.0];
    }
}

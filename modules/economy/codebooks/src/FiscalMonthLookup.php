<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Codebooks;

/**
 * Mapování data → fiskální měsíc (`economy_codebooks_fiscal_months`).
 * Jediné místo dotazu, sdílené DocDocument::resolveFiscalMonthId (dopočet
 * `fiscal_month` při uložení dokladu) a FiscalMonthLockProvider (nový
 * měsíc dokladu před uložením, #55 D27) — aby se pravidlo nerozjelo.
 *
 * Bere jen **běžné** měsíce (`period_type` 1); Otevření (0) a Uzavření (2)
 * jsou jednodenní a slouží počátečním stavům a závěrce — doklad se do nich
 * zařadí jen výslovně (`docs_core_heads.fiscal_period_type`, #69 D20)
 * přes {@see monthIdForYearAndType}.
 */
final class FiscalMonthLookup
{
    public const PERIOD_TYPE_OPENING = 0;
    public const PERIOD_TYPE_REGULAR = 1;
    public const PERIOD_TYPE_CLOSING = 2;

    /** Kód `fiscal_period_type` dokladu (cfgItem docs.core.fiscalPeriodTypes) → period_type měsíce. */
    public const PERIOD_TYPE_BY_CODE = [
        'opening' => self::PERIOD_TYPE_OPENING,
        'closing' => self::PERIOD_TYPE_CLOSING,
    ];

    public static function monthIdForDate(\Dibi\Connection $db, string $date): ?int
    {
        $date = self::isoDate($date);
        if ($date === null) {
            return null;
        }
        $row = $db->fetch(
            'SELECT [id] FROM [economy_codebooks_fiscal_months]
             WHERE [date_begin] <= %d AND [date_end] >= %d AND [period_type] = %i
             LIMIT 1',
            $date, $date, self::PERIOD_TYPE_REGULAR,
        );
        return $row !== null ? (int) $row['id'] : null;
    }

    /**
     * Měsíc daného typu ve fiskálním roce — pro doklady otevíracího /
     * uzávěrkového období (jednodenní měsíce na hranici roku se hledají
     * rokem, ne datem; datum by trefilo i běžný měsíc).
     */
    public static function monthIdForYearAndType(\Dibi\Connection $db, int $fiscalYearId, int $periodType): ?int
    {
        $row = $db->fetch(
            'SELECT [id] FROM [economy_codebooks_fiscal_months]
             WHERE [fiscal_year] = %i AND [period_type] = %i
             ORDER BY [id] LIMIT 1',
            $fiscalYearId, $periodType,
        );
        return $row !== null ? (int) $row['id'] : null;
    }

    public static function isoDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        $string = trim((string) ($value ?? ''));
        return $string !== '' ? substr($string, 0, 10) : null;
    }
}

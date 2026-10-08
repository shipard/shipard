<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Codebooks;

/**
 * Fiskální rok (`economy_codebooks_fiscal_years`) pro číslování záznamů —
 * id roku podle data a popisek roku pro `%y` / `%Y` ve vzorci čísla
 * ({@see \Shipard\Core\Numbering\NumberPattern}). Jádro číslování o fiskálních
 * letech neví; doklady (`DocDocument`) i zakázky si rok a popisek určí tudy.
 * Vzor pro měsíce: {@see FiscalMonthLookup}.
 */
final class FiscalYearLookup
{
    /** Smazané roky (90) se přeskakují; archivované zůstávají dohledatelné datem. */
    private const DOC_STATE_DELETED = 90;

    /** Fiskální rok obsahující datum (při překryvu ten s pozdějším začátkem). */
    public static function yearIdForDate(\Dibi\Connection $db, string $date): ?int
    {
        if ($date === '') {
            return null;
        }
        $row = $db->fetch(
            'SELECT [id] FROM [economy_codebooks_fiscal_years]
             WHERE [date_begin] <= %d AND [date_end] >= %d
               AND [docState] != %i
             ORDER BY [date_begin] DESC
             LIMIT 1',
            $date, $date,
            self::DOC_STATE_DELETED,
        );
        return $row !== null ? (int) $row['id'] : null;
    }

    /**
     * Popisek roku pro vzorec čísla: první čtyři číslice názvu fiskálního
     * roku. Neznámý rok nebo název bez roku na začátku → aktuální kalendářní
     * rok (ne rok data — tak se chová číslování dokladů od Fáze 2).
     */
    public static function yearLabel(\Dibi\Connection $db, int $fiscalYearId): string
    {
        $row = $db->fetch(
            'SELECT [doc_number_prefix], [name] FROM [economy_codebooks_fiscal_years] WHERE [id] = %i',
            $fiscalYearId,
        );
        if ($row === null) {
            return date('Y');
        }
        $name = (string) ($row['name'] ?? '');
        if (preg_match('/^(\d{4})/', $name, $matches)) {
            return $matches[1];
        }
        return date('Y');
    }

    /** Popisek roku bez fiskálního roku: rok z data, bez data aktuální rok. */
    public static function labelFromDate(?string $date): string
    {
        if ($date !== null && $date !== '') {
            return substr($date, 0, 4);
        }
        return date('Y');
    }
}

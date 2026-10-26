<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Bank\Import\Parsers;

use Shipard\Module\World\Validations\DomesticAccount;

/**
 * Sdílené helpery parserů — port z `ebankingImportDoc` (parseNumber, substr,
 * mod11, getAccountNumber) + normalizace symbolů a slučování memo.
 *
 * Pracuje nad dekódovaným UTF-8; fixní-šířkové offsety jsou ZNAKOVÉ pozice
 * (mb_substr), takže jsou stabilní napříč CP1250 → UTF-8 konverzí.
 */
final class ParserUtils
{
    /** Desetinné číslo s tečkou nebo čárkou (ISO / FIO); bez tisícových oddělovačů kromě mezer. */
    public static function parseAmount(string $v): float
    {
        $s = str_replace([' ', "\xC2\xA0"], '', trim($v));
        if (str_contains($s, ',') && !str_contains($s, '.')) {
            $s = str_replace(',', '.', $s);
        }
        return (float) $s;
    }

    /** CZ formát: mezera/tečka = tisíce, čárka = desetinná (pro CSV formáty později). */
    public static function parseNumberCz(string $v): float
    {
        $s = str_replace([' ', "\xC2\xA0", '.'], '', trim($v));
        return (float) str_replace(',', '.', $s);
    }

    /** mb-aware výřez + trim (port `substr`). */
    public static function sub(string $str, int $from, int $len = 0): string
    {
        return $len === 0
            ? trim(mb_substr($str, $from, null, 'UTF-8'))
            : trim(mb_substr($str, $from, $len, 'UTF-8'));
    }

    /** Symbol bez leading nul; prázdný / samé nuly → null. */
    public static function normalizeSymbol(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = ltrim(trim($v), '0');
        return $s === '' ? null : $s;
    }

    public static function nullIfEmpty(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim($v);
        return $s === '' ? null : $s;
    }

    /**
     * Sloučí memo řádky: zahodí prázdné, přeskočí duplicitní PO SOBĚ jdoucí
     * (port `setRowInfo` memo logiky), spojí mezerou.
     *
     * @param array<int, ?string> $lines
     */
    public static function mergeMemo(array $lines): ?string
    {
        $out = [];
        $last = null;
        foreach ($lines as $line) {
            $v = trim((string) ($line ?? ''));
            if ($v === '') {
                continue; // prázdné přeskoč, neresetuj $last (jinak by se nesloučily stejné řádky oddělené prázdným)
            }
            if ($v !== $last) {
                $out[] = $v;
                $last = $v;
            }
        }
        $merged = trim(implode(' ', $out));
        return $merged === '' ? null : $merged;
    }

    public static function parseDate(string $format, string $value): ?\DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!' . $format, $value);
        return $d === false ? null : $d;
    }

    /**
     * ABO mod11 kontrola (CNB vyhl. 169/2011) — váhy od pravého kraje dává
     * knihovna (`DomesticAccount::mod11`, #118 D23). Prázdný řetězec = platný;
     * číslice nad deset míst zleva se nepočítají (jako dřív).
     */
    public static function mod11(string $n): bool
    {
        return DomesticAccount::mod11(strlen($n) > 10 ? substr($n, -10) : $n);
    }

    /**
     * Dekódování čísla účtu z ABO vnitřního formátu (16 znaků) — port
     * `getAccountNumber`. Naivní rozdělení (předčíslí 0-5, číslo 6-15);
     * když neprojde mod11, aplikuje permutaci C0C8C9C6C1C2C3C4C5C7P1..P6.
     * Vrací `předčíslí-číslo` (bez leading nul).
     */
    public static function decodeAboAccount(string $str): string
    {
        $number = ltrim(self::sub($str, 6, 10), ' 0');
        $prefix = ltrim(self::sub($str, 0, 6), ' 0');

        if (!self::mod11($number) || !self::mod11($prefix)) {
            $ap = self::sub($str, 10, 6);
            $an = self::sub($str, 4, 5)
                . self::sub($str, 3, 1)
                . self::sub($str, 9, 1)
                . self::sub($str, 1, 1)
                . self::sub($str, 2, 1)
                . self::sub($str, 0, 1);
            $number = ltrim($an, ' 0');
            $prefix = ltrim($ap, ' 0');
        }

        return ($prefix !== '' ? $prefix . '-' : '') . $number;
    }
}

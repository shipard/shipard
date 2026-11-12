<?php

declare(strict_types=1);

namespace Shipard\Core\I18n;

/**
 * Datum a čas na obrazovce — jediné místo v backendu, které tvoří jejich
 * zápis (#133 D73).
 *
 * Formát se řídí jazykem požadavku: česky `01.01.2026` (s časem
 * `01.01.2026 14:05`), anglicky a v jakémkoli jiném jazyce ISO `2026-01-01`
 * (s časem `2026-01-01 14:05`). Oba zápisy mají pevnou délku a jsou
 * jednoznačné; jazyky dokumentů (`sk`, `de`) na obrazovku nechodí a neznámý
 * jazyk dostane anglický zápis jako fallback konfigurace.
 *
 * Vstup: `DateTimeInterface`, řetězec `Y-m-d`, `Y-m-d H:i[:s]` nebo ISO 8601
 * (`Y-m-d\TH:i:s`, i s posunem pásma). `null` a prázdný řetězec → `null`;
 * řetězec, který datem není, se vrací beze změny, aby se neztratila hodnota.
 *
 * Časové pásmo se nepřevádí: zapíše se čas tak, jak v hodnotě stojí (hodnoty
 * z databáze posun nenesou). Tisk, export, výměnný formát a texty uložené
 * v datech tuhle třídu nepoužívají — viz `docs/design-system.md` → Datum
 * a čas.
 */
final class DisplayDate
{
    private const PATTERN = '/^(\d{4})-(\d{2})-(\d{2})'
        . '(?:[T ](\d{2}):(\d{2})(?::\d{2}(?:[.,]\d+)?)?)?'
        . '(?:Z|[+-]\d{2}(?::?\d{2})?)?$/';

    /** Datum bez času; hodnota s časem se o čas zkrátí. */
    public static function date(mixed $value, string $language): ?string
    {
        $parts = self::parts($value);
        if ($parts === null) {
            return self::passThrough($value);
        }
        return self::datePart($parts, $language);
    }

    /** Datum s časem na minuty; hodnota bez času dostane `00:00`. */
    public static function dateTime(mixed $value, string $language): ?string
    {
        $parts = self::parts($value);
        if ($parts === null) {
            return self::passThrough($value);
        }
        return self::datePart($parts, $language) . ' ' . $parts[3] . ':' . $parts[4];
    }

    /**
     * @param array{0: string, 1: string, 2: string, 3: string, 4: string} $parts
     */
    private static function datePart(array $parts, string $language): string
    {
        [$year, $month, $day] = $parts;
        return $language === 'cs'
            ? $day . '.' . $month . '.' . $year
            : $year . '-' . $month . '-' . $day;
    }

    /**
     * Rok, měsíc, den, hodina, minuta — vždy s úvodními nulami; null, když
     * hodnota datem není (nebo je prázdná).
     *
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string}|null
     */
    private static function parts(mixed $value): ?array
    {
        if ($value instanceof \DateTimeInterface) {
            return explode(' ', $value->format('Y m d H i'));
        }
        if (!is_string($value)) {
            return null;
        }
        if (!preg_match(self::PATTERN, trim($value), $m)) {
            return null;
        }
        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        $hour = $m[4] ?? '';
        $minute = $m[5] ?? '';
        if ($hour === '') {
            return [$m[1], $m[2], $m[3], '00', '00'];
        }
        if ((int) $hour > 23 || (int) $minute > 59) {
            return null;
        }
        return [$m[1], $m[2], $m[3], $hour, $minute];
    }

    /** Prázdná hodnota → null, jinak text beze změny. */
    private static function passThrough(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value instanceof \DateTimeInterface) {
            return null;
        }
        return is_scalar($value) || $value instanceof \Stringable ? (string) $value : null;
    }
}

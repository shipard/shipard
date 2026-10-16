<?php

declare(strict_types=1);

namespace Shipard\Core\Utils;

/**
 * Datum z databáze jako ISO `YYYY-MM-DD`. Dibi vrací sloupce typu date
 * jako `Dibi\DateTime`, mock v testech řetězec — oba tvary se normalizují
 * na totéž; prázdná hodnota = null.
 */
final class IsoDate
{
    public static function fromDb(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        $string = trim((string) ($value ?? ''));
        return $string !== '' ? substr($string, 0, 10) : null;
    }
}

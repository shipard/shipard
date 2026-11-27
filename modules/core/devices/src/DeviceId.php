<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Devices;

/**
 * Tvar `deviceId` (kontrakt docs/native-bridge.md §3.1): UUID, které si
 * aplikace vytváří pro každý zdroj dat zvlášť. Server ho nepřepisuje ani
 * nesjednocuje velikost písmen — je součástí podepisovaného řetězce
 * a porovnává se přesně (sloupce mají collation `utf8mb4_bin`).
 */
final class DeviceId
{
    private const string PATTERN = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/D';

    public static function isValid(mixed $value): bool
    {
        return is_string($value) && preg_match(self::PATTERN, $value) === 1;
    }
}

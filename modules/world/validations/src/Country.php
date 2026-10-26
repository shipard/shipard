<?php

declare(strict_types=1);

namespace Shipard\Module\World\Validations;

/** Kód země: ISO 3166-1 alpha-2 malými, bez okrajových mezer. */
final class Country
{
    public static function normalize(string $country): string
    {
        return strtolower(trim($country));
    }
}

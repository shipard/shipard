<?php

declare(strict_types=1);

namespace Shipard\Module\World\Validations;

/** BIC / SWIFT: velkými bez mezer, 8 nebo 11 znaků. */
final class Bic
{
    public const SHAPE = '/^[A-Z]{6}[A-Z0-9]{2}(?:[A-Z0-9]{3})?$/';

    public static function normalize(string $bic): string
    {
        return strtoupper((string) preg_replace('/\s+/u', '', trim($bic)));
    }

    public static function isValid(string $bic): bool
    {
        return preg_match(self::SHAPE, $bic) === 1;
    }
}

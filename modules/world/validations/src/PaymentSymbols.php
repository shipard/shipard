<?php

declare(strict_types=1);

namespace Shipard\Module\World\Validations;

/** Platební symboly: VS / KS / SS bez mezer, platební reference bez mezer velkými. */
final class PaymentSymbols
{
    public static function symbol(string $symbol): string
    {
        return (string) preg_replace('/\s+/u', '', trim($symbol));
    }

    public static function reference(string $reference): string
    {
        return strtoupper(self::symbol($reference));
    }
}

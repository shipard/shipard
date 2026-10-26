<?php

declare(strict_types=1);

namespace Shipard\Module\World\Validations;

/**
 * DIČ / VAT ID: velkými, bez mezer, teček a pomlček; zapsané jen
 * číslicemi dostane prefix státu, pokud ho třída státu doplňuje (cz).
 * Platnost = obecný tvar prefix + 2–13 znaků a pravidlo státu podle
 * prefixu (cz 8–10 číslic, sk 10, de kontrolní číslice).
 */
final class VatId
{
    public const SHAPE = '/^[A-Z]{2}[0-9A-Z]{2,13}$/';

    public static function normalize(string $vatId, ?CountryValueRules $rules = null): string
    {
        $s = strtoupper((string) preg_replace('/[\s.\-]+/u', '', trim($vatId)));
        if ($rules !== null && $s !== '' && ctype_digit($s)) {
            return $rules->completeVatId($s) ?? $s;
        }
        return $s;
    }

    /** Dvoupísmenný prefix normalizovaného DIČ; bez něj `null`. */
    public static function prefixOf(string $vatId): ?string
    {
        return preg_match('/^[A-Z]{2}/', $vatId) === 1 ? substr($vatId, 0, 2) : null;
    }

    /** Část za prefixem; bez prefixu celá hodnota. */
    public static function numberOf(string $vatId): string
    {
        return self::prefixOf($vatId) !== null ? substr($vatId, 2) : $vatId;
    }

    public static function isValid(string $vatId): bool
    {
        if (preg_match(self::SHAPE, $vatId) !== 1) {
            return false;
        }
        return ValueRulesRegistry::forVatPrefix((string) self::prefixOf($vatId))->isValidVatNumber(self::numberOf($vatId));
    }
}

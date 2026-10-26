<?php

declare(strict_types=1);

namespace Shipard\Module\World\Validations;

/**
 * IČO při párování s uloženými daty (#118 D16): data z doby před
 * normalizací mohou nést IČO bez vedoucích nul. Resolvery hledají
 * normalizovaný tvar a — liší-li se — i tvar bez vedoucích nul.
 */
final class CompanyId
{
    /** @return list<string> normalizovaný tvar, případně i tvar bez vedoucích nul */
    public static function lookupVariants(string $companyId): array
    {
        $stripped = ltrim($companyId, '0');
        return $stripped !== '' && $stripped !== $companyId ? [$companyId, $stripped] : [$companyId];
    }

    /** Shoda bez ohledu na vedoucí nuly. */
    public static function same(string $a, string $b): bool
    {
        return $a === $b || (ltrim($a, '0') === ltrim($b, '0') && ltrim($a, '0') !== '');
    }
}

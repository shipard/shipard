<?php

declare(strict_types=1);

namespace Shipard\Module\World\Validations;

/**
 * Česko: předvolba 420 bez národního předčíslí; DIČ `CZ` + 8–10 číslic
 * (8místné = `CZ` + IČO, 9–10místné má fyzická osoba a vazba na IČO
 * neplatí); DIČ zapsané jen číslicemi dostane prefix `CZ`.
 */
final class CzValueRules extends CzSkValueRulesBase
{
    private const VAT_NUMBER_SHAPE = '/^[0-9]{8,10}$/';

    public function country(): string
    {
        return 'cz';
    }

    public function phonePrefix(): ?string
    {
        return '420';
    }

    public function completeVatId(string $digits): ?string
    {
        return preg_match(self::VAT_NUMBER_SHAPE, $digits) === 1 ? 'CZ' . $digits : null;
    }

    public function isValidVatNumber(string $number): bool
    {
        return preg_match(self::VAT_NUMBER_SHAPE, $number) === 1;
    }

    public function companyIdFromVatId(string $vatId): ?string
    {
        if (VatId::prefixOf($vatId) !== 'CZ') {
            return null;
        }
        $number = VatId::numberOf($vatId);
        return preg_match('/^[0-9]{8}$/', $number) === 1 ? $number : null;
    }
}

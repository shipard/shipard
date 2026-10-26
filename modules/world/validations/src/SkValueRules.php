<?php

declare(strict_types=1);

namespace Shipard\Module\World\Validations;

/**
 * Slovensko: předvolba 421, národní předčíslí `0`; IČO a účet jako
 * v Česku; DIČ `SK` + 10 číslic bez vazby na IČO.
 */
final class SkValueRules extends CzSkValueRulesBase
{
    private const VAT_NUMBER_SHAPE = '/^[0-9]{10}$/';

    public function country(): string
    {
        return 'sk';
    }

    public function phonePrefix(): ?string
    {
        return '421';
    }

    public function trunkPrefix(): ?string
    {
        return '0';
    }

    public function isValidVatNumber(string $number): bool
    {
        return preg_match(self::VAT_NUMBER_SHAPE, $number) === 1;
    }
}

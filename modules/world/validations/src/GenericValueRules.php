<?php

declare(strict_types=1);

namespace Shipard\Module\World\Validations;

/**
 * Pravidla státu bez vlastní třídy (vzor `AccountingOnlyTaxRules`
 * ve `world.assets`): jen obecná pravidla — IČO a PSČ jak na dokladu,
 * telefon bez předvolby, účet bez převodu, DIČ jen obecným tvarem.
 */
final class GenericValueRules extends AbstractCountryValueRules
{
    public function __construct(private readonly string $country = '')
    {
    }

    public function country(): string
    {
        return $this->country;
    }

    public function isGeneric(): bool
    {
        return true;
    }
}

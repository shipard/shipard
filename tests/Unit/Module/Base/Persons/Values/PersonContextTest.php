<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Base\Persons\Values;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Base\Persons\Values\PersonContext;

/** Země osoby podle D18 (sídlo → první adresa → zdroj dat) a vlastní firma. */
class PersonContextTest extends TestCase
{
    public function testCountryFromAddressesPrefersHeadquarters(): void
    {
        $this->assertSame('sk', PersonContext::countryFromAddresses([
            ['address_type' => 2, 'country' => 'de'],
            ['address_type' => 1, 'country' => ' SK'],
        ]));
        $this->assertSame('de', PersonContext::countryFromAddresses([
            ['address_type' => 1, 'country' => ''],
            ['address_type' => 2, 'country' => 'de'],
        ]), 'sídlo bez země → první adresa se zemí');
        $this->assertNull(PersonContext::countryFromAddresses([['address_type' => 1, 'city' => 'Zlín']]));
        $this->assertNull(PersonContext::countryFromAddresses([]));
    }

    public function testWithoutDatabaseFallsBackToDataSourceCountry(): void
    {
        $context = new PersonContext(null, 'sk');
        $this->assertSame('sk', $context->dsCountry());
        $this->assertSame('sk', $context->country(42));
        $this->assertFalse($context->isOwn(42));
    }

    public function testStoredCountryWinsOverDataSourceCountry(): void
    {
        $context = new class (null, 'cz') extends PersonContext {
            protected function storedCountry(int $personId): ?string
            {
                return $personId === 7 ? 'de' : null;
            }
        };
        $this->assertSame('de', $context->country(7));
        $this->assertSame('cz', $context->country(8), 'osoba bez adresy se zemí');
    }
}

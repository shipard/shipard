<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\World\Validations;

use PHPUnit\Framework\TestCase;
use Shipard\Module\World\Validations\CzValueRules;
use Shipard\Module\World\Validations\DeValueRules;
use Shipard\Module\World\Validations\GenericValueRules;
use Shipard\Module\World\Validations\SkValueRules;
use Shipard\Module\World\Validations\ValueRulesRegistry;

class ValueRulesRegistryTest extends TestCase
{
    public function testCountryWithClassGetsItsRules(): void
    {
        $this->assertInstanceOf(CzValueRules::class, ValueRulesRegistry::forCountry('CZ'));
        $this->assertInstanceOf(SkValueRules::class, ValueRulesRegistry::forCountry(' sk '));
        $this->assertInstanceOf(DeValueRules::class, ValueRulesRegistry::forCountry('de'));
        $this->assertSame('cz', ValueRulesRegistry::forCountry('CZ')->country());
        $this->assertSame(['cz', 'sk', 'de'], ValueRulesRegistry::countries());
    }

    public function testUnknownOrMissingCountryFallsBackToGeneric(): void
    {
        $us = ValueRulesRegistry::forCountry('us');
        $this->assertInstanceOf(GenericValueRules::class, $us);
        $this->assertTrue($us->isGeneric());
        $this->assertSame('us', $us->country());
        $this->assertNull($us->phonePrefix());
        $this->assertFalse($us->supportsDomesticAccount());
        $this->assertTrue($us->isValidCompanyId('anything'));
        $this->assertTrue($us->isValidZip('94105-1234'));

        $none = ValueRulesRegistry::forCountry(null);
        $this->assertTrue($none->isGeneric());
        $this->assertSame('', $none->country());
        $this->assertFalse(ValueRulesRegistry::hasRules(null));
        $this->assertFalse(ValueRulesRegistry::hasRules('us'));
        $this->assertTrue(ValueRulesRegistry::hasRules('CZ'));
    }

    public function testInstancesAreMemoized(): void
    {
        $this->assertSame(ValueRulesRegistry::forCountry('cz'), ValueRulesRegistry::forCountry('Cz'));
    }

    public function testByIbanAndVatPrefix(): void
    {
        $this->assertInstanceOf(CzValueRules::class, ValueRulesRegistry::forIban('cz65 0800 0000 1920 0014 5399'));
        $this->assertInstanceOf(DeValueRules::class, ValueRulesRegistry::forIban('DE72100700000532013000'));
        $this->assertTrue(ValueRulesRegistry::forIban('nonsense')->isGeneric());
        $this->assertInstanceOf(SkValueRules::class, ValueRulesRegistry::forVatPrefix('SK'));
        $this->assertTrue(ValueRulesRegistry::forVatPrefix('EL')->isGeneric());
    }
}

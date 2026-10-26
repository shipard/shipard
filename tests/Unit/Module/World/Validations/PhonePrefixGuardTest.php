<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\World\Validations;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\World\Validations\ValueRulesRegistry;

/**
 * Strážce D12: předvolba každé třídy země je konstanta a musí se shodovat
 * s `phonePrefixes[0]` v číselníku `world.base.countries`.
 */
class PhonePrefixGuardTest extends TestCase
{
    private const COUNTRIES = __DIR__ . '/../../../../../modules/world/base/config/countries.jsonc';

    public function testEveryCountryClassMatchesBaseCountries(): void
    {
        $countries = JsoncParser::parseFile(self::COUNTRIES);
        $this->assertNotEmpty(ValueRulesRegistry::countries());

        foreach (ValueRulesRegistry::countries() as $code) {
            $rules = ValueRulesRegistry::forCountry($code);
            $this->assertFalse($rules->isGeneric(), $code);
            $this->assertSame($code, $rules->country());
            $this->assertArrayHasKey($code, $countries, "země {$code} chybí ve world.base.countries");
            $this->assertSame(
                (string) $countries[$code]['phonePrefixes'][0],
                $rules->phonePrefix(),
                "předvolba třídy {$code} se liší od world.base.countries",
            );
        }
    }

    public function testModuleIsPureLibrary(): void
    {
        $module = JsoncParser::parseFile(__DIR__ . '/../../../../../modules/world/validations/module.jsonc');
        $this->assertSame('world.validations', $module['id']);
        $this->assertSame(['world.base'], $module['dependencies']);
        $this->assertArrayNotHasKey('tables', $module);
        $this->assertArrayNotHasKey('config', $module);
    }
}

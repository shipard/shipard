<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\SalesRegistration;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\I18n\ConfigLocalizer;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Economy\SalesRegistration\SalesRegistrationTypes;

/**
 * Registr typů evidence (#171 D2, D17): typ = data země × engine doplňku.
 * Typ bez enginu se nenabízí, záznam mapy bez dat typu taky ne, vadná
 * položka se přeskočí.
 */
class SalesRegistrationTypesTest extends TestCase
{
    private const CZ_TYPES = __DIR__ . '/../../../../../modules/world/cz/config/salesRegistrationTypes.jsonc';

    /** @param array<string, mixed> $items cfgItem id → hodnota */
    private function config(array $items): ConfigRuntime
    {
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(static fn(string $id): mixed => $items[$id] ?? null);
        return $config;
    }

    /** @return array<string, mixed> skutečná data world.cz, jak je dá kompilace v daném jazyce */
    private function czTypes(string $language = 'cs'): array
    {
        return ConfigLocalizer::localize(JsoncParser::parseFile(self::CZ_TYPES), $language);
    }

    public function testComposesTypeFromCountryDataAndEngineMap(): void
    {
        $types = SalesRegistrationTypes::fromConfig($this->config([
            'economy.salesRegistration.engines' => ['cz.eet2' => 'Some\\Engine'],
            'world.cz.salesRegistrationTypes'   => $this->czTypes(),
        ]));

        $this->assertFalse($types->isEmpty());
        $type = $types->get('cz.eet2');
        $this->assertNotNull($type);
        $this->assertSame('EET 2.0 (Česko)', $type->name);
        $this->assertSame('EET 2.0 (Česko)', $types->labelOf('cz.eet2'));
        $this->assertSame('cz', $type->country);
        $this->assertSame('2027-01-01', $type->validFrom);
        $this->assertSame('czk', $type->currency);
        $this->assertSame('EIČ', $type->taxpayerIdLabel);
        $this->assertSame('Some\\Engine', $type->engineClass);
        $this->assertSame('https://trzbyeet.gov.cz/eet/services/EETServiceSOAP/v4', $type->environmentUrl('production'));
        $this->assertSame('https://pg.trzbyeet.gov.cz/eet/services/EETServiceSOAP/v4', $type->environmentUrl('playground'));
        $this->assertNull($type->environmentUrl('staging'));

        $this->assertTrue($type->isValidTaxpayerId('CZ00000019'));
        $this->assertFalse($type->isValidTaxpayerId('00000019'));
        $this->assertTrue($type->isValidUnitId('181'));
        $this->assertFalse($type->isValidUnitId('0181'));
        $this->assertTrue($type->isValidCashRegisterId('SHIPARD-TEST'));
        $this->assertFalse($type->isValidSerialNumber(str_repeat('1', 26)));
    }

    public function testEnglishLabelsComeFromLocalizedConfig(): void
    {
        $types = SalesRegistrationTypes::fromConfig($this->config([
            'economy.salesRegistration.engines' => ['cz.eet2' => 'Some\\Engine'],
            'world.cz.salesRegistrationTypes'   => $this->czTypes('en'),
        ]));

        $this->assertSame('EET 2.0 (Czech Republic)', $types->get('cz.eet2')?->name);
        $this->assertSame('EIC', $types->get('cz.eet2')?->taxpayerIdLabel);
    }

    public function testTypeWithoutEngineIsNotOffered(): void
    {
        $types = SalesRegistrationTypes::fromConfig($this->config([
            'economy.salesRegistration.engines' => [],
            'world.cz.salesRegistrationTypes'   => $this->czTypes(),
        ]));

        $this->assertTrue($types->isEmpty());
        $this->assertNull($types->get('cz.eet2'));
        $this->assertSame('cz.eet2', $types->labelOf('cz.eet2'), 'neznámý typ = jeho id');
    }

    public function testEngineWithoutCountryDataIsIgnored(): void
    {
        // Modul země není ve zdroji dat aktivní → jeho cfgItem chybí.
        $types = SalesRegistrationTypes::fromConfig($this->config([
            'economy.salesRegistration.engines' => ['cz.eet2' => 'Some\\Engine', 'sk.ekasa' => 'Other\\Engine'],
        ]));

        $this->assertTrue($types->isEmpty());
    }

    public function testTypesOfSeveralCountriesLiveSideBySide(): void
    {
        $sk = $this->czTypes()['cz.eet2'];
        $sk['name'] = 'eKasa';
        $sk['taxpayerId'] = ['label' => 'DIČ', 'pattern' => '^[0-9]{10}$'];

        $types = SalesRegistrationTypes::fromConfig($this->config([
            'economy.salesRegistration.engines' => ['cz.eet2' => 'Cz\\Engine', 'sk.ekasa' => 'Sk\\Engine'],
            'world.cz.salesRegistrationTypes'   => $this->czTypes(),
            'world.sk.salesRegistrationTypes'   => ['sk.ekasa' => $sk],
        ]));

        $this->assertSame(['cz.eet2', 'sk.ekasa'], array_map(static fn($t) => $t->id, $types->all()));
        $this->assertSame('sk', $types->get('sk.ekasa')?->country);
        $this->assertSame('DIČ', $types->get('sk.ekasa')?->taxpayerIdLabel);
        $this->assertTrue($types->get('sk.ekasa')->isValidTaxpayerId('2020123456'));
    }

    public function testBrokenEntriesAreSkipped(): void
    {
        $valid = $this->czTypes()['cz.eet2'];
        $noEnvironment = ['environments' => []] + $valid;
        $plainHttp = ['environments' => ['production' => ['url' => 'http://example.test/x']]] + $valid;
        $badMask = ['taxpayerId' => ['label' => 'x', 'pattern' => '([']] + $valid;
        $noUnitMask = $valid;
        unset($noUnitMask['unitId']);

        $types = SalesRegistrationTypes::fromConfig($this->config([
            'economy.salesRegistration.engines' => [
                'cz.eet2'  => 'Some\\Engine',
                'cz.a'     => 'Some\\Engine',
                'cz.b'     => 'Some\\Engine',
                'cz.c'     => 'Some\\Engine',
                'cz.d'     => 'Some\\Engine',
                'cz.e'     => '',
                'cz.f'     => ['not' => 'a class'],
                'CZ.upper' => 'Some\\Engine',
                'nodot'    => 'Some\\Engine',
            ],
            'world.cz.salesRegistrationTypes' => [
                'cz.eet2'  => $valid,
                'cz.a'     => $noEnvironment,
                'cz.b'     => $plainHttp,
                'cz.c'     => $badMask,
                'cz.d'     => $noUnitMask,
                'cz.e'     => $valid,
                'cz.f'     => $valid,
                'CZ.upper' => $valid,
                'nodot'    => $valid,
            ],
        ]));

        $this->assertSame(['cz.eet2'], array_map(static fn($t) => $t->id, $types->all()));
    }

    public function testMissingConfigMeansNoTypes(): void
    {
        $this->assertTrue(SalesRegistrationTypes::fromConfig(null)->isEmpty());
        $this->assertTrue(SalesRegistrationTypes::fromConfig($this->config([]))->isEmpty());
        $this->assertTrue(SalesRegistrationTypes::fromConfig($this->config([
            'economy.salesRegistration.engines' => 'broken',
        ]))->isEmpty());
    }

    public function testOptionalMasksDoNotRestrictWhenMissing(): void
    {
        $entry = $this->czTypes()['cz.eet2'];
        unset($entry['cashRegisterId'], $entry['serialNumber'], $entry['validFrom']);

        $type = SalesRegistrationTypes::fromConfig($this->config([
            'economy.salesRegistration.engines' => ['cz.eet2' => 'Some\\Engine'],
            'world.cz.salesRegistrationTypes'   => ['cz.eet2' => $entry],
        ]))->get('cz.eet2');

        $this->assertNotNull($type);
        $this->assertNull($type->validFrom);
        $this->assertTrue($type->isValidCashRegisterId('cokoli — i s diakritikou'));
        $this->assertTrue($type->isValidSerialNumber(str_repeat('1', 99)));
    }
}

<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Facility\Buildings;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Facility\Buildings\ContractTypes;

/**
 * Typy smluv (#174 D17) — jediné čtení cfgItem, kód se ptá jen na vlastnosti.
 */
class ContractTypesTest extends TestCase
{
    use BuildingsConfig;

    private function types(): ContractTypes
    {
        return new ContractTypes($this->buildingsConfig());
    }

    public function testSevenTypesInTheAgreedOrder(): void
    {
        $this->assertSame(
            ['office', 'flat', 'retail', 'storage', 'parking', 'virtualOffice', 'other'],
            array_keys($this->types()->all()),
        );
        $this->assertSame(
            ['Kancelář', 'Byt', 'Obchodní prostor', 'Sklad', 'Parkování', 'Virtuální kancelář', 'Ostatní'],
            array_column($this->types()->options(), 'label'),
        );
        $this->assertSame('office', $this->types()->options()[0]['value']);
    }

    public function testPlacesAndAllowedPlaceTypesPerType(): void
    {
        $types = $this->types();
        $expected = [
            'office'        => ['required', ['room', 'section']],
            'flat'          => ['required', ['section', 'room']],
            'retail'        => ['required', ['section', 'room', 'outdoor']],
            'storage'       => ['required', ['room', 'section', 'outdoor']],
            'parking'       => ['required', ['spot', 'outdoor']],
            'virtualOffice' => ['required', ['building', 'site']],
            'other'         => ['optional', null],
        ];
        foreach ($expected as $key => [$places, $placeTypes]) {
            $this->assertSame($places, $types->places($key), $key);
            $this->assertSame($placeTypes, $types->placeTypes($key), $key);
        }

        $this->assertTrue($types->allowsPlaceType('office', 'room'));
        $this->assertFalse($types->allowsPlaceType('office', 'floor'));
        $this->assertTrue($types->allowsPlaceType('other', 'floor'), 'null = libovolný typ místa');
    }

    public function testEveryTypeHasAnIconAndASeedCode(): void
    {
        $types = $this->types();
        $codes = [];
        foreach (array_keys($types->all()) as $key) {
            $this->assertNotNull($types->icon($key), $key);
            $codes[$key] = $types->seedCode($key);
        }
        $this->assertSame(
            ['office' => 'KA', 'flat' => 'BY', 'retail' => 'OP', 'storage' => 'SK', 'parking' => 'PA', 'virtualOffice' => 'VK', 'other' => 'OS'],
            $codes,
        );
    }

    public function testPlaceTypesOfContractTypesExistAmongPlaceTypes(): void
    {
        $placeTypes = array_keys(self::czech('base/places/config/types.jsonc'));
        foreach (array_keys($this->types()->all()) as $key) {
            foreach ($this->types()->placeTypes($key) ?? [] as $placeType) {
                $this->assertContains($placeType, $placeTypes, "{$key}: {$placeType}");
            }
        }
    }

    public function testUnknownTypeFallsBackToOptionalPlacesOfAnyType(): void
    {
        $types = $this->types();

        $this->assertTrue($types->isUnknown('cellar'));
        $this->assertFalse($types->isUnknown('office'));
        $this->assertSame('cellar', $types->label('cellar'));
        $this->assertNull($types->icon('cellar'));
        $this->assertSame(ContractTypes::PLACES_OPTIONAL, $types->places('cellar'));
        $this->assertNull($types->placeTypes('cellar'));
        $this->assertNull($types->seedCode('cellar'));
    }

    public function testPropertiesComeFromTheConfigNotFromTheKey(): void
    {
        $types = new ContractTypes($this->buildingsConfig([
            ContractTypes::CFG_ITEM => [
                'locker' => ['name' => 'Skříňka', 'places' => 'none', 'order' => 20],
                'desk'   => ['name' => 'Stůl', 'places' => 'bogus', 'placeTypes' => ['spot', '', 5], 'order' => 10],
            ],
        ]));

        $this->assertSame(['desk', 'locker'], array_keys($types->all()));
        $this->assertSame(ContractTypes::PLACES_NONE, $types->places('locker'));
        $this->assertSame(ContractTypes::PLACES_OPTIONAL, $types->places('desk'), 'neznámá hodnota = volitelná');
        $this->assertSame(['spot'], $types->placeTypes('desk'));
    }

    public function testWithoutConfigNothingIsUnknown(): void
    {
        $types = new ContractTypes(null);

        $this->assertSame([], $types->all());
        $this->assertFalse($types->isUnknown('office'));
        $this->assertSame(ContractTypes::PLACES_OPTIONAL, $types->places('office'));
        $this->assertTrue($types->allowsPlaceType('office', 'floor'));
        $this->assertSame([], $types->options());
    }
}

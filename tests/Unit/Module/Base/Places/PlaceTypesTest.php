<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Base\Places;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Base\Places\PlaceTypes;

/**
 * PlaceTypes nad skutečným cfgItemem `base.places.types` (#169 D2, D3):
 * úrovně, příznak adresy, ikony a matice „kdo smí být nadřazený komu“.
 */
class PlaceTypesTest extends TestCase
{
    private const TYPES_FILE = __DIR__ . '/../../../../../modules/base/places/config/types.jsonc';

    private function types(): PlaceTypes
    {
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([
            [PlaceTypes::CFG_ITEM, JsoncParser::parseFile(self::TYPES_FILE)],
        ]);
        return new PlaceTypes($config);
    }

    public function testConfigDeclaresTheEightTypesInOrder(): void
    {
        $types = $this->types();

        $this->assertSame(
            ['site', 'building', 'section', 'floor', 'room', 'outdoor', 'spot', 'other'],
            array_keys($types->all()),
        );
        $this->assertSame(
            ['site' => 10, 'building' => 20, 'section' => 30, 'floor' => 40, 'room' => 50, 'outdoor' => 50, 'spot' => 60, 'other' => null],
            array_map(static fn (string $key): ?int => $types->level($key), array_combine(array_keys($types->all()), array_keys($types->all()))),
        );
    }

    public function testAddressFlagAndIcons(): void
    {
        $types = $this->types();

        $withAddress = array_values(array_filter(
            array_keys($types->all()),
            static fn (string $key): bool => $types->hasAddress($key),
        ));
        $this->assertSame(['site', 'building', 'outdoor'], $withAddress);

        foreach (array_keys($types->all()) as $key) {
            $this->assertSame('place-' . $key, $types->icon($key), $key);
        }
    }

    public function testLabelOptionsAndUnknownType(): void
    {
        $types = $this->types();

        $this->assertSame('Room', $types->label('room'));
        $this->assertSame('nope', $types->label('nope'));
        $this->assertTrue($types->isUnknown('nope'));
        $this->assertFalse($types->isUnknown('room'));
        $this->assertSame(['value' => 'site', 'label' => 'Site'], $types->options()[0]);
        $this->assertCount(8, $types->options());
    }

    /**
     * Nadřazené místo musí mít nižší úroveň; přeskakování je povolené.
     *
     * @return list<array{string, string, bool}>
     */
    public static function parentMatrix(): array
    {
        return [
            // běžný sestup i přeskočení úrovní
            ['site', 'building', true],
            ['building', 'floor', true],
            ['floor', 'room', true],
            ['building', 'room', true],
            ['site', 'spot', true],
            ['room', 'spot', true],
            ['outdoor', 'spot', true],
            // stejný typ a stejná úroveň
            ['room', 'room', false],
            ['building', 'building', false],
            ['room', 'outdoor', false],
            ['outdoor', 'room', false],
            // opačný směr
            ['floor', 'building', false],
            ['room', 'floor', false],
            ['spot', 'room', false],
            ['building', 'site', false],
            // Ostatní pravidlo vypíná na obou stranách
            ['other', 'site', true],
            ['spot', 'other', true],
            ['other', 'other', true],
        ];
    }

    #[DataProvider('parentMatrix')]
    public function testCanBeParent(string $parent, string $child, bool $expected): void
    {
        $this->assertSame($expected, $this->types()->canBeParent($parent, $child), "{$parent} > {$child}");
    }

    public function testWithoutConfigNothingIsUnknownAndLevelsDoNotApply(): void
    {
        $types = new PlaceTypes(null);

        $this->assertSame([], $types->all());
        $this->assertFalse($types->isUnknown('room'));
        $this->assertNull($types->level('room'));
        $this->assertNull($types->icon('room'));
        $this->assertFalse($types->hasAddress('building'));
        $this->assertTrue($types->canBeParent('room', 'building'));
        $this->assertSame([], $types->options());
    }
}

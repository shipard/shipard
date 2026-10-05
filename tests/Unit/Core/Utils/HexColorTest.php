<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Utils;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Utils\HexColor;

class HexColorTest extends TestCase
{
    public function testSixDigitColorIsNormalizedToLowercase(): void
    {
        $this->assertSame('#0a5c8f', HexColor::normalize('#0a5c8f'));
        $this->assertSame('#0a5c8f', HexColor::normalize('#0A5C8F'));
        $this->assertSame('#ffffff', HexColor::normalize("  #FFFFFF\n"));
    }

    /** @return array<string, array{mixed}> */
    public static function notColors(): array
    {
        return [
            'prázdný řetězec'  => [''],
            'zkrácený zápis'   => ['#abc'],
            'osm číslic'       => ['#0a5c8fff'],
            'bez mřížky'       => ['0a5c8f'],
            'název barvy'      => ['red'],
            'rgb()'            => ['rgb(0, 0, 0)'],
            'CSS za barvou'    => ['#0a5c8f; color: red'],
            'řádek za barvou'  => ["#0a5c8f\n}"],
            'ne-hex znak'      => ['#0a5c8g'],
            'null'             => [null],
            'číslo'            => [123456],
            'pole'             => [['#0a5c8f']],
        ];
    }

    #[DataProvider('notColors')]
    public function testAnythingElseIsNotAColor(mixed $value): void
    {
        $this->assertNull(HexColor::normalize($value));
    }
}

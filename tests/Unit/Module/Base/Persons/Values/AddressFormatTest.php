<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Base\Persons\Values;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Base\Persons\Values\AddressFormat;

/**
 * Čitelný tvar adresy ze sloupců — sdílí ho sub-tabulka adres osoby
 * a detail místa (#169 D13).
 */
class AddressFormatTest extends TestCase
{
    public function testStreetWithNumbers(): void
    {
        $this->assertSame('Dlouhá 12/3', AddressFormat::street(['street' => 'Dlouhá', 'house_number' => '12', 'orientation_number' => '3']));
        $this->assertSame('Dlouhá 12', AddressFormat::street(['street' => ' Dlouhá ', 'house_number' => '12']));
        $this->assertSame('Dlouhá 3', AddressFormat::street(['street' => 'Dlouhá', 'orientation_number' => '3']));
        $this->assertSame('12/3', AddressFormat::street(['house_number' => '12', 'orientation_number' => '3']));
        $this->assertSame('Dlouhá', AddressFormat::street(['street' => 'Dlouhá']));
        $this->assertNull(AddressFormat::street(['street' => '  ', 'house_number' => null]));
        $this->assertNull(AddressFormat::street([]));
    }

    public function testCityWithReadableZip(): void
    {
        $this->assertSame('110 00 Praha', AddressFormat::city(['zip' => '11000', 'city' => 'Praha', 'country' => 'cz']));
        // Adresa bez země: PSČ podle náhradní země.
        $this->assertSame('110 00 Praha', AddressFormat::city(['zip' => '11000', 'city' => 'Praha'], 'cz'));
        $this->assertSame('110 00 Praha', AddressFormat::city(['zip' => '11000', 'city' => 'Praha', 'country' => ''], 'CZ'));
        // Bez jakékoli země zůstává uložený tvar.
        $this->assertSame('11000 Praha', AddressFormat::city(['zip' => '11000', 'city' => 'Praha']));
        $this->assertSame('Praha', AddressFormat::city(['city' => 'Praha'], 'cz'));
        $this->assertSame('110 00', AddressFormat::city(['zip' => '11000'], 'cz'));
        $this->assertNull(AddressFormat::city(['zip' => ' ', 'city' => ''], 'cz'));
    }

    public function testOneLineAddress(): void
    {
        $row = ['street' => 'Dlouhá', 'house_number' => '12', 'orientation_number' => '3', 'zip' => '11000', 'city' => 'Praha', 'country' => 'cz'];

        $this->assertSame('Dlouhá 12/3, 110 00 Praha', AddressFormat::line($row));
        $this->assertSame('Dlouhá 12/3', AddressFormat::line(['street' => 'Dlouhá', 'house_number' => '12', 'orientation_number' => '3']));
        $this->assertSame('110 00 Praha', AddressFormat::line(['zip' => '11000', 'city' => 'Praha'], 'cz'));
        $this->assertNull(AddressFormat::line(['country' => 'cz', 'latitude' => '50.1']));
    }
}

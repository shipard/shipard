<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Mail;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Mail\AddressList;

class AddressListTest extends TestCase
{
    public function testParseAcceptsTextAndList(): void
    {
        $this->assertSame(['a@x.cz', 'b@x.cz'], AddressList::parse('a@x.cz, b@x.cz'));
        $this->assertSame(['a@x.cz', 'b@x.cz', 'c@x.cz'], AddressList::parse(['a@x.cz', 'b@x.cz,c@x.cz']));
        $this->assertSame([], AddressList::parse(null));
        $this->assertSame([], AddressList::parse(' , '));
    }

    public function testParseDropsDuplicatesCaseInsensitively(): void
    {
        $this->assertSame(['Ucetni@X.cz', 'b@x.cz'], AddressList::parse(['Ucetni@X.cz', 'b@x.cz', 'ucetni@x.cz']));
    }

    public function testFormatRoundTrips(): void
    {
        $addresses = ['a@x.cz', 'b@x.cz'];

        $this->assertSame('a@x.cz, b@x.cz', AddressList::format($addresses));
        $this->assertSame($addresses, AddressList::parse(AddressList::format($addresses)));
    }

    public function testInvalidReturnsFirstBrokenAddress(): void
    {
        $this->assertNull(AddressList::invalid(['a@x.cz', 'b@x.cz']));
        $this->assertSame('spatne', AddressList::invalid(['a@x.cz', 'spatne', 'taky spatne']));
    }
}

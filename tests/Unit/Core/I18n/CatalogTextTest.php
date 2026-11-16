<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\I18n;

use PHPUnit\Framework\TestCase;
use Shipard\Core\I18n\CatalogText;

/**
 * Text z katalogu popisků (`{<klíč>: {name}}`) — sdílí viewery i formuláře
 * (#150 D87): text klíče, náhrada parametrů, fallback bez katalogu i klíče.
 */
class CatalogTextTest extends TestCase
{
    private const DEFS = [
        'title' => ['name' => 'Karta'],
        'count' => ['name' => '{n} z {total} karet'],
    ];

    public function testReadsNameOfKey(): void
    {
        $this->assertSame('Karta', CatalogText::resolve(self::DEFS, 'title', 'Card'));
    }

    public function testReplacesParams(): void
    {
        $this->assertSame('2 z 5 karet', CatalogText::resolve(self::DEFS, 'count', '{n} of {total}', ['n' => 2, 'total' => 5]));
    }

    public function testMissingKeyFallsBackWithParams(): void
    {
        $this->assertSame('Hello Eva', CatalogText::resolve(self::DEFS, 'missing', 'Hello {who}', ['who' => 'Eva']));
    }

    public function testMissingCatalogFallsBack(): void
    {
        $this->assertSame('Card', CatalogText::resolve(null, 'title', 'Card'));
        $this->assertSame('Card', CatalogText::resolve('not-a-catalog', 'title', 'Card'));
    }
}

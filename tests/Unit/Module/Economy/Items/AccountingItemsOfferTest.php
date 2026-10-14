<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Items;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Economy\Items\AccountingItemsOffer;

class AccountingItemsOfferTest extends TestCase
{
    private function offer(?string $variant): AccountingItemsOffer
    {
        $resolver = new ModulePathResolver([dirname(__DIR__, 5) . '/modules']);

        $offer = $this->getMockBuilder(AccountingItemsOffer::class)
            ->setConstructorArgs([$this->createMock(DataSourceConnection::class), $resolver])
            ->onlyMethods(['variant'])
            ->getMock();
        $offer->method('variant')->willReturn($variant);
        return $offer;
    }

    public function testLoadSeedReturnsItemsKeyedByCode(): void
    {
        $seed = $this->offer('default')->loadSeed('default');

        $this->assertNotNull($seed);
        $this->assertArrayHasKey('503100', $seed['items']);
        $this->assertSame('503100', $seed['items']['503100']['account']);
    }

    public function testLoadSeedUnknownVariantReturnsNull(): void
    {
        $this->assertNull($this->offer('default')->loadSeed('bogus'));
    }

    public function testDefaultAccountForTagExactMatch(): void
    {
        $this->assertSame('503100', $this->offer('default')->defaultAccountForTag('vehicle.fuel'));
        $this->assertSame('538200', $this->offer('default')->defaultAccountForTag('admin.fees'));
    }

    public function testDefaultAccountForTagPrefixFallback(): void
    {
        // office.equipment nese 501201; hypotetický office.somethingNew bez
        // přímého mapování spadne na první office.* položku v pořadí nabídky.
        $account = $this->offer('default')->defaultAccountForTag('office.newThing');

        $this->assertNotNull($account);
        $this->assertContains($account, ['501100', '501201']);
    }

    public function testDefaultAccountForTagUnmapped(): void
    {
        // Nabídka pokrývá každý štítek taxonomie kromě admin.other
        // (ds-setup Task 12, AccountingItemsOfferCoverageTest) — i ten ale
        // prefix fallback dovede na první admin.* položku. Opravdu bez účtu
        // zůstává jen štítek s prefixem, který nabídka nezná.
        $this->assertSame('518100', $this->offer('default')->defaultAccountForTag('admin.other'));
        $this->assertNull($this->offer('default')->defaultAccountForTag('unknown.tag'));
    }

    public function testGoodsStockMapsToMaterials(): void
    {
        // #103 D31: goods.stock = Spotřeba materiálu, žádné skladové položky.
        $this->assertSame('501100', $this->offer('default')->defaultAccountForTag('goods.stock'));
        $this->assertSame('501100', $this->offer('npo')->defaultAccountForTag('goods.stock'));
        $this->assertSame('501100', $this->offer('npo')->entryForTag('goods.stock')['code']);
    }

    public function testDefaultAccountForTagNpoVariant(): void
    {
        $this->assertSame('538100', $this->offer('npo')->defaultAccountForTag('vehicle.toll'));
        // #103 D32: nezisková sada nese stejné nákladové analytiky jako
        // podnikatelská — it.* a office.supplies mají přímé mapování.
        $this->assertSame('518206', $this->offer('npo')->defaultAccountForTag('it.software'));
        $this->assertSame('501100S', $this->offer('npo')->entryForTag('office.supplies')['code']);
        // Pojištění jde v NNO pod 549, ne 548 (Manka a škody).
        $this->assertSame('549201', $this->offer('npo')->defaultAccountForTag('admin.insurance'));
    }

    public function testUndecidedVariantYieldsNoAccount(): void
    {
        $this->assertNull($this->offer(null)->defaultAccountForTag('vehicle.fuel'));
    }
}

<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\BookingHistory;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Exchange\BookingHistory\AccountTagMap;
use Shipard\Module\Core\Exchange\BookingHistory\AccountTagMatch;
use Shipard\Module\Economy\Items\AccountingItemsOffer;

/**
 * Reverz účet → obsahový štítek: přesná shoda, syntetika, kolize
 * (tasks/booking-history-import.md, Scope 3).
 */
class AccountTagMapTest extends TestCase
{
    private function map(): AccountTagMap
    {
        return new AccountTagMap([
            '501100' => ['office.supplies'],
            '501200' => ['office.cleaning'],
            '518202' => ['it.internet'],
            '518300' => ['services.postage'],
            '512100' => ['travel.accommodation', 'travel.fares'], // kolizní účet
            '648900' => ['admin.other'],
        ]);
    }

    public function testExactMatch(): void
    {
        $match = $this->map()->resolve('518202');
        $this->assertSame('it.internet', $match->tag);
        $this->assertSame(AccountTagMatch::KIND_EXACT, $match->kind);
        $this->assertTrue($match->isHit());
    }

    public function testSyntheticMatchWhenUnique(): void
    {
        // 501xxx nese v nabídce dva různé štítky → syntetika nerozhoduje.
        $ambiguous = $this->map()->resolve('501999');
        $this->assertNull($ambiguous->tag);
        $this->assertSame(AccountTagMatch::KIND_AMBIGUOUS, $ambiguous->kind);
        $this->assertSame(['office.supplies', 'office.cleaning'], $ambiguous->candidates);

        // 512xxx nese dva štítky téže skupiny, ale pořád dva → bez zásahu.
        $this->assertNull($this->map()->resolve('512999')->tag);

        // 648xxx nese jediný štítek → syntetika zasáhne.
        $synthetic = $this->map()->resolve('648123');
        $this->assertSame('admin.other', $synthetic->tag);
        $this->assertSame(AccountTagMatch::KIND_SYNTHETIC, $synthetic->kind);
    }

    public function testAmbiguousExactAccountIsNotResolvedBySynthetic(): void
    {
        $match = $this->map()->resolve('512100');
        $this->assertNull($match->tag);
        $this->assertSame(AccountTagMatch::KIND_AMBIGUOUS, $match->kind);
        $this->assertSame(['travel.accommodation', 'travel.fares'], $match->candidates);
    }

    public function testUnmappedAndMissingAccount(): void
    {
        $unmapped = $this->map()->resolve('999888');
        $this->assertSame(AccountTagMatch::KIND_UNMAPPED, $unmapped->kind);
        $this->assertNull($unmapped->tag);

        foreach ([null, '', '   '] as $empty) {
            $this->assertSame(AccountTagMatch::KIND_NO_ACCOUNT, $this->map()->resolve($empty)->kind);
        }
    }

    public function testSyntheticNeedsThreeDigits(): void
    {
        $this->assertSame('518', AccountTagMap::synthetic('518202'));
        $this->assertSame('518', AccountTagMap::synthetic('518-202'));
        $this->assertNull(AccountTagMap::synthetic('51'));
        $this->assertNull(AccountTagMap::synthetic('AB'));
    }

    public function testEmptyMap(): void
    {
        $map = new AccountTagMap([]);
        $this->assertTrue($map->isEmpty());
        $this->assertSame(AccountTagMatch::KIND_UNMAPPED, $map->resolve('518202')->kind);
    }

    /**
     * Reálné nabídky: obě varianty osnovy vedou vlastní čísla účtů, takže
     * mapa `npo` a `default` se musí rozcházet — a neznámá varianta nesmí
     * spadnout, jen vrátit prázdno.
     */
    public function testRealOffersDifferPerVariant(): void
    {
        $offer = new AccountingItemsOffer($this->createStub(DataSourceConnection::class));

        $default = AccountTagMap::fromOffer($offer, 'default');
        $npo     = AccountTagMap::fromOffer($offer, 'npo');
        $unknown = AccountTagMap::fromOffer($offer, 'nonsense');

        $this->assertFalse($default->isEmpty());
        $this->assertFalse($npo->isEmpty());
        $this->assertTrue($unknown->isEmpty(), 'neznámá varianta → prázdná mapa, ne výjimka');
        $this->assertNotSame($offer->tagsByAccount('default'), $offer->tagsByAccount('npo'));

        // Bankovní poplatky: podnikatelská osnova 568201, nezisková 549301 (#122 D7).
        $this->assertSame('services.banking', $default->resolve('568201')->tag);
        $this->assertSame('services.banking', $npo->resolve('549301')->tag);
        $this->assertNull($npo->resolve('568201')->tag);
        $this->assertNull($npo->resolve('549100')->tag);
        $this->assertNull($default->resolve('549301')->tag);

        // Účet s víc štítky v nabídce (501100 nese materiálové skupiny)
        // zůstává nejednoznačný — kurátorská volba nabídky, ne chyba reverzu.
        $this->assertSame(AccountTagMatch::KIND_AMBIGUOUS, $default->resolve('501100')->kind);
    }
}

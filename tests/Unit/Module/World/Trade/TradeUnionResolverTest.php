<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\World\Trade;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\World\Trade\TradeUnionResolver;

/**
 * Čtení unií nad **skutečným** `tradeUnions.jsonc` (vzor
 * VatReverseCodeRatesTest) — testy hlídají i data: členství ČR, mapování
 * `EL` → `gr`, Brexit (`GB`), Severní Irsko (`XI` jen zboží).
 * tasks/exchange-received-vat-place.md D2.
 */
class TradeUnionResolverTest extends TestCase
{
    private const UNIONS = __DIR__ . '/../../../../../modules/world/trade/config/tradeUnions.jsonc';

    private static ?array $unions = null;

    private function resolver(mixed $cfgItem = false): TradeUnionResolver
    {
        self::$unions ??= JsoncParser::parseFile(self::UNIONS);
        $data = $cfgItem === false ? self::$unions : $cfgItem;
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn (string $id): mixed => $id === 'world.trade.unions' ? $data : null,
        );
        return new TradeUnionResolver($config);
    }

    public function testCzechiaIsEuMemberIn2026ButNotIn2003(): void
    {
        $r = $this->resolver();
        $this->assertSame(['eu'], $r->unionsOf('cz', '2026-04-15'));
        $this->assertSame(['eu'], $r->unionsOf('CZ', '2004-05-01'));
        $this->assertSame([], $r->unionsOf('cz', '2003-12-31'));
    }

    public function testUnitedKingdomLeftEuAfterBrexit(): void
    {
        $r = $this->resolver();
        $this->assertSame(['eu'], $r->unionsOf('gb', '2020-12-31'));
        $this->assertSame([], $r->unionsOf('gb', '2021-01-01'));
    }

    public function testCountryOutsideAnyUnion(): void
    {
        $this->assertSame([], $this->resolver()->unionsOf('us', '2026-04-15'));
    }

    public function testGreekPrefixMapsToGr(): void
    {
        $entry = $this->resolver()->taxPrefix('eu', 'EL');
        $this->assertNotNull($entry);
        $this->assertSame('gr', $entry['country']);
        // Prefix se normalizuje na velká písmena.
        $this->assertSame('gr', $this->resolver()->taxPrefix('eu', 'el')['country'] ?? null);
    }

    public function testGbPrefixValidUntilBrexit(): void
    {
        $r = $this->resolver();
        $gb = $r->taxPrefix('eu', 'GB');
        $this->assertNotNull($gb);
        $this->assertSame('gb', $gb['country']);
        $this->assertTrue($r->isPrefixValid($gb, '2020-06-30'));
        $this->assertTrue($r->isPrefixValid($gb, '2020-12-31'));
        $this->assertFalse($r->isPrefixValid($gb, '2021-01-01'));
    }

    public function testNorthernIrelandPrefixIsGoodsOnlyFrom2021(): void
    {
        $r = $this->resolver();
        $xi = $r->taxPrefix('eu', 'XI');
        $this->assertNotNull($xi);
        $this->assertSame('gb', $xi['country']);
        $this->assertSame(['goods'], $xi['supplyKinds']);
        $this->assertFalse($r->isPrefixValid($xi, '2020-12-31'));
        $this->assertTrue($r->isPrefixValid($xi, '2021-01-01'));
    }

    public function testOrdinaryPrefixHasNoSupplyKindsRestriction(): void
    {
        $de = $this->resolver()->taxPrefix('eu', 'DE');
        $this->assertNotNull($de);
        $this->assertSame('de', $de['country']);
        $this->assertArrayNotHasKey('supplyKinds', $de);
        $this->assertTrue($this->resolver()->isPrefixValid($de, '1999-01-01'));
    }

    public function testUnknownPrefixOrUnionIsNull(): void
    {
        $r = $this->resolver();
        $this->assertNull($r->taxPrefix('eu', 'US'));
        $this->assertNull($r->taxPrefix('eu', 'EU'));
        $this->assertNull($r->taxPrefix('gcc', 'CZ'));
        $this->assertNull($r->taxPrefix('nafta', 'CZ'));
    }

    public function testMissingCfgItemMeansNoUnions(): void
    {
        $r = $this->resolver(null);
        $this->assertSame([], $r->unionsOf('cz', '2026-04-15'));
        $this->assertNull($r->taxPrefix('eu', 'CZ'));
    }
}

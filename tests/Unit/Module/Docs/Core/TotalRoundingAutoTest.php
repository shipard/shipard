<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Docs\Core\RoundingModes;
use Shipard\Module\Docs\Core\TotalRoundingAuto;

/**
 * Pravidlo automatického zaokrouhlení celkové částky (#73 D2, D5, D6) nad
 * reálnou konfigurací: typy dokladů a způsoby úhrady z docs.core, kroky
 * hotovosti z world.base.
 */
class TotalRoundingAutoTest extends TestCase
{
    private const CASH     = 0;
    private const TRANSFER = 1;
    private const CARD     = 2;
    private const COD      = 3;
    private const SET_OFF  = 4;
    private const GATEWAY  = 5;

    /** @param array<string, mixed> $override klíč cfgItem → hodnota; null = cfgItem chybí */
    private function config(array $override = []): ConfigRuntime
    {
        $root = dirname(__DIR__, 5);
        $items = [
            'docs.core.docTypes'       => JsoncParser::parseFile($root . '/modules/docs/core/config/docTypes.jsonc'),
            'docs.core.paymentMethods' => JsoncParser::parseFile($root . '/modules/docs/core/config/paymentMethods.jsonc'),
            'world.base.cashRounding'  => JsoncParser::parseFile($root . '/modules/world/base/config/cashRounding.jsonc'),
        ];
        foreach ($override as $id => $value) {
            $items[$id] = $value;
        }
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn (string $id): mixed => $items[$id] ?? null,
        );
        return $config;
    }

    // ── modeFor() ──────────────────────────────────────────────────────

    /** @return iterable<string, array{int, string, string, int}> */
    public static function modeCases(): iterable
    {
        yield 'CZ/CZK hotově → na koruny'     => [self::CASH, 'czk', 'cz', RoundingModes::MATH_UNIT];
        yield 'CZ/CZK dobírka → na koruny'    => [self::COD, 'czk', 'cz', RoundingModes::MATH_UNIT];
        yield 'CZ/CZK převodem → haléře'      => [self::TRANSFER, 'czk', 'cz', RoundingModes::ON_CENT];
        yield 'CZ/CZK kartou → haléře'        => [self::CARD, 'czk', 'cz', RoundingModes::ON_CENT];
        yield 'CZ/CZK zápočtem → haléře'      => [self::SET_OFF, 'czk', 'cz', RoundingModes::ON_CENT];
        yield 'CZ/CZK bránou → haléře'        => [self::GATEWAY, 'czk', 'cz', RoundingModes::ON_CENT];
        yield 'SK/EUR hotově → 0,05'          => [self::CASH, 'eur', 'sk', RoundingModes::MATH_FIVE_CENT];
        yield 'SK/EUR dobírka → 0,05'         => [self::COD, 'eur', 'sk', RoundingModes::MATH_FIVE_CENT];
        yield 'NL/EUR hotově → 0,05'          => [self::CASH, 'eur', 'nl', RoundingModes::MATH_FIVE_CENT];
        yield 'SK/EUR kartou → centy'         => [self::CARD, 'eur', 'sk', RoundingModes::ON_CENT];
        yield 'CZ/EUR hotově → centy'         => [self::CASH, 'eur', 'cz', RoundingModes::ON_CENT];
        yield 'DE/EUR hotově → centy'         => [self::CASH, 'eur', 'de', RoundingModes::ON_CENT];
        yield 'SK/CZK hotově → haléře'        => [self::CASH, 'czk', 'sk', RoundingModes::ON_CENT];
        yield 'neznámá měna hotově → centy'   => [self::CASH, 'usd', 'cz', RoundingModes::ON_CENT];
        yield 'neznámý způsob úhrady → haléře' => [99, 'czk', 'cz', RoundingModes::ON_CENT];
        yield 'velká písmena měny a země'     => [self::CASH, 'CZK', 'CZ', RoundingModes::MATH_UNIT];
    }

    #[DataProvider('modeCases')]
    public function testModeFor(int $paymentMethod, string $currency, string $country, int $expected): void
    {
        $this->assertSame($expected, TotalRoundingAuto::modeFor($this->config(), $paymentMethod, $currency, $country));
    }

    public function testModeForIsNullWithoutConfigurationOrCountry(): void
    {
        $this->assertNull(TotalRoundingAuto::modeFor(null, self::CASH, 'czk', 'cz'), 'bez konfigurace');
        $this->assertNull(
            TotalRoundingAuto::modeFor($this->config(['world.base.cashRounding' => null]), self::CASH, 'czk', 'cz'),
            'bez pravidel hotovosti (zdroj dat před ds-upgrade)',
        );
        $this->assertNull(
            TotalRoundingAuto::modeFor($this->config(['docs.core.paymentMethods' => null]), self::TRANSFER, 'czk', 'cz'),
            'bez způsobů úhrady ani nehotovostní úhrada nerozhodne',
        );
        $this->assertNull(TotalRoundingAuto::modeFor($this->config(), self::CASH, 'czk', null), 'bez země');
        $this->assertNull(TotalRoundingAuto::modeFor($this->config(), self::TRANSFER, 'czk', ''), 'prázdná země');
    }

    public function testModeForStepWithoutModeFallsBackToCents(): void
    {
        $config = $this->config(['world.base.cashRounding' => [
            'dkk' => ['step' => 0.5, 'countries' => ['dk']],
            'czk' => ['countries' => ['cz']],
        ]]);

        $this->assertSame(RoundingModes::ON_CENT, TotalRoundingAuto::modeFor($config, self::CASH, 'dkk', 'dk'), 'krok 0,50 nemá mód');
        $this->assertSame(RoundingModes::ON_CENT, TotalRoundingAuto::modeFor($config, self::CASH, 'czk', 'cz'), 'pravidlo bez kroku');
    }

    // ── isCashPayment() ────────────────────────────────────────────────

    public function testIsCashPaymentFollowsCashRoundingFlag(): void
    {
        $config = $this->config();

        $this->assertTrue(TotalRoundingAuto::isCashPayment($config, self::CASH));
        $this->assertTrue(TotalRoundingAuto::isCashPayment($config, self::COD));
        foreach ([self::TRANSFER, self::CARD, self::SET_OFF, self::GATEWAY, 99] as $method) {
            $this->assertFalse(TotalRoundingAuto::isCashPayment($config, $method), "způsob {$method}");
        }
        $this->assertFalse(TotalRoundingAuto::isCashPayment(null, self::CASH), 'bez konfigurace');
        $this->assertFalse(
            TotalRoundingAuto::isCashPayment($this->config(['docs.core.paymentMethods' => [0 => ['cashRounding' => 1]]]), self::CASH),
            'jen striktní true',
        );
    }

    // ── applies() ──────────────────────────────────────────────────────

    /** @return iterable<string, array{string, int, bool}> */
    public static function appliesCases(): iterable
    {
        yield 'faktura vydaná'            => ['invno', 0, true];
        yield 'zálohová faktura vydaná'   => ['invpo', 0, true];
        yield 'prodejka'                  => ['cashreg', 0, true];
        yield 'pokladní doklad příjem'    => ['cash', 1, true];
        yield 'pokladní doklad výdej'     => ['cash', 2, false];
        yield 'pokladní doklad bez směru' => ['cash', 0, false];
        yield 'faktura přijatá'           => ['invni', 0, false];
        yield 'zálohová faktura přijatá'  => ['invpi', 0, false];
        yield 'účetní doklad'             => ['cmnbkp', 0, false];
        yield 'neznámý typ'               => ['unknown', 0, false];
        yield 'prázdný typ'               => ['', 0, false];
    }

    #[DataProvider('appliesCases')]
    public function testApplies(string $docType, int $cashDir, bool $expected): void
    {
        $this->assertSame($expected, TotalRoundingAuto::applies($this->config(), $docType, $cashDir));
    }

    public function testAppliesIsFalseWithoutConfiguration(): void
    {
        $this->assertFalse(TotalRoundingAuto::applies(null, 'invno', 0));
        $this->assertFalse(TotalRoundingAuto::applies($this->config(['docs.core.docTypes' => null]), 'invno', 0));
    }

    public function testAppliesRequiresSalesDirectionEvenWithFlag(): void
    {
        // Příznak na nákupním typu automatiku nezapne — pravidlo je pro prodejní doklady.
        $config = $this->config(['docs.core.docTypes' => [
            'invni' => ['trade_dir' => 2, 'total_rounding_auto' => true],
            'cmnbkp' => ['trade_dir' => 0, 'total_rounding_auto' => true],
        ]]);

        $this->assertFalse(TotalRoundingAuto::applies($config, 'invni', 0));
        $this->assertFalse(TotalRoundingAuto::applies($config, 'cmnbkp', 0));
    }
}

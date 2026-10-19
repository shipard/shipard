<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\ExchangeRates;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Economy\ExchangeRates\ExchangeRateListTypes;

/** Typy lístků z cfgItem world.<země>.exchangeRateLists — pořadí, filtr podle měny, degradace. */
class ExchangeRateListTypesTest extends TestCase
{
    private function config(?array $cz): ConfigRuntime
    {
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn(string $id): mixed => $id === 'world.cz.exchangeRateLists' ? $cz : null,
        );
        return $config;
    }

    public function testReadsTypesInConfigOrderWithMaxAge(): void
    {
        $types = ExchangeRateListTypes::fromConfig($this->config([
            'cz.cnb.daily' => ['name' => 'ČNB denní', 'homeCurrency' => 'CZK', 'maxAgeDays' => 7],
            'cz.cnb.other' => ['name' => 'ČNB ostatní', 'homeCurrency' => 'czk'],
            'broken'       => ['name' => 'bez měny'],
            'alsoBroken'   => 'string',
        ]), 'CZ');

        $this->assertSame(['cz.cnb.daily', 'cz.cnb.other'], array_map(static fn($t) => $t->id, $types->all()));
        $this->assertSame(7, $types->get('cz.cnb.daily')?->maxAgeDays);
        $this->assertFalse($types->get('cz.cnb.daily')->requiresValidTo());
        $this->assertNull($types->get('cz.cnb.other')?->maxAgeDays);
        $this->assertTrue($types->get('cz.cnb.other')->requiresValidTo());
        $this->assertSame('czk', $types->get('cz.cnb.daily')->homeCurrency, 'měna malými');
        $this->assertSame('ČNB ostatní', $types->labelOf('cz.cnb.other'));
        $this->assertSame('x.y', $types->labelOf('x.y'), 'neznámý typ = jeho id');
        $this->assertNull($types->get('broken'));
    }

    public function testFiltersByHomeCurrency(): void
    {
        $types = ExchangeRateListTypes::fromConfig($this->config([
            'cz.cnb.daily' => ['name' => 'a', 'homeCurrency' => 'czk', 'maxAgeDays' => 7],
            'eu.ecb.daily' => ['name' => 'b', 'homeCurrency' => 'eur', 'maxAgeDays' => 7],
        ]), 'cz');

        $this->assertSame(['cz.cnb.daily'], array_map(static fn($t) => $t->id, $types->forHomeCurrency('CZK')));
        $this->assertSame([], $types->forHomeCurrency('usd'));
    }

    public function testMissingConfigMeansNoTypes(): void
    {
        $this->assertTrue(ExchangeRateListTypes::fromConfig($this->config(null), 'cz')->isEmpty());
        $this->assertTrue(ExchangeRateListTypes::fromConfig(null, 'cz')->isEmpty());
        $this->assertTrue(ExchangeRateListTypes::fromConfig($this->config(['x' => ['homeCurrency' => 'czk']]), 'sk')->isEmpty(), 'jiná země');
        $this->assertSame('world.sk.exchangeRateLists', ExchangeRateListTypes::cfgItemId('SK'));
    }
}

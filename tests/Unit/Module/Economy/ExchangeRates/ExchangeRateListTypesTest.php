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

    /** Task B U2: `download` se parsuje shovívavě — neplatná hodnota typ nezruší, jen se nestahuje. */
    public function testParsesDownloadAndKeepsTypeWithInvalidDownload(): void
    {
        $types = ExchangeRateListTypes::fromConfig($this->config([
            'cz.cnb.daily' => ['name' => 'a', 'homeCurrency' => 'czk', 'maxAgeDays' => 7, 'download' => ['serviceListType' => 1, 'frequency' => 'workingDays', 'calendar' => 'cz', 'publishAfter' => '14:35', 'timezone' => 'Europe/Prague']],
            'cz.cnb.other' => ['name' => 'b', 'homeCurrency' => 'czk', 'download' => ['serviceListType' => 2, 'frequency' => 'monthly']],
            'cz.manual'    => ['name' => 'c', 'homeCurrency' => 'czk', 'maxAgeDays' => 7],
            'cz.badTime'   => ['name' => 'd', 'homeCurrency' => 'czk', 'maxAgeDays' => 7, 'download' => ['serviceListType' => 3, 'frequency' => 'workingDays', 'calendar' => 'cz', 'publishAfter' => '25:00']],
            'cz.badZone'   => ['name' => 'e', 'homeCurrency' => 'czk', 'maxAgeDays' => 7, 'download' => ['serviceListType' => 4, 'frequency' => 'monthly', 'timezone' => 'Mars/Olympus']],
            'cz.badFreq'   => ['name' => 'f', 'homeCurrency' => 'czk', 'maxAgeDays' => 7, 'download' => ['serviceListType' => 5, 'frequency' => 'weekly']],
            'cz.noCal'     => ['name' => 'g', 'homeCurrency' => 'czk', 'maxAgeDays' => 7, 'download' => ['serviceListType' => 6, 'frequency' => 'workingDays', 'publishAfter' => '14:35']],
            'cz.notObject' => ['name' => 'h', 'homeCurrency' => 'czk', 'maxAgeDays' => 7, 'download' => 'yes'],
        ]), 'cz');

        $daily = $types->get('cz.cnb.daily');
        $this->assertTrue($daily->isDownloadable());
        $this->assertSame(1, $daily->download->serviceListType);
        $this->assertTrue($daily->download->isWorkingDays());
        $this->assertSame('cz', $daily->download->calendar);
        $this->assertSame('14:35', $daily->download->publishAfter);
        $this->assertSame('Europe/Prague', $daily->download->timezone);
        $this->assertNull($daily->downloadIssue);

        $other = $types->get('cz.cnb.other');
        $this->assertTrue($other->download->isMonthly());
        $this->assertNull($other->download->calendar);
        $this->assertNull($other->download->publishAfter);
        $this->assertSame('Europe/Prague', $other->download->timezone, 'výchozí zóna');

        $this->assertFalse($types->get('cz.manual')->isDownloadable());
        $this->assertNull($types->get('cz.manual')->downloadIssue, 'bez download = bez problému');

        foreach (['cz.badTime' => 'publishAfter', 'cz.badZone' => 'timezone', 'cz.badFreq' => 'frequency', 'cz.noCal' => 'calendar', 'cz.notObject' => 'objekt'] as $id => $needle) {
            $type = $types->get($id);
            $this->assertNotNull($type, "{$id}: typ zůstává pro resolver");
            $this->assertFalse($type->isDownloadable(), $id);
            $this->assertStringContainsString($needle, (string) $type->downloadIssue, $id);
        }
        $this->assertCount(8, $types->all());
    }

    public function testMissingConfigMeansNoTypes(): void
    {
        $this->assertTrue(ExchangeRateListTypes::fromConfig($this->config(null), 'cz')->isEmpty());
        $this->assertTrue(ExchangeRateListTypes::fromConfig(null, 'cz')->isEmpty());
        $this->assertTrue(ExchangeRateListTypes::fromConfig($this->config(['x' => ['homeCurrency' => 'czk']]), 'sk')->isEmpty(), 'jiná země');
        $this->assertSame('world.sk.exchangeRateLists', ExchangeRateListTypes::cfgItemId('SK'));
    }
}

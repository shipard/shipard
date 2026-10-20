<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\World\Cz;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Economy\ExchangeRates\Download\ListDownloadConfig;

/**
 * Typy kurzovních lístků (`world.cz.exchangeRateLists`, #121 D1). Na klíčích
 * stojí `listType` ve formátu shpd.exchangeRates.list.v1, na pořadí priorita
 * dohledání (U2) a na `maxAgeDays` pojistka proti tichému starému kurzu (U1):
 * typ bez něj musí nést platnost na lístku (`validTo`).
 */
class ExchangeRateListsTest extends TestCase
{
    private const MODULE = __DIR__ . '/../../../../../modules/world/cz';
    private const CURRENCIES = __DIR__ . '/../../../../../modules/world/base/config/currencies.jsonc';

    public function testIsRegisteredInModule(): void
    {
        $module = JsoncParser::parseFile(self::MODULE . '/module.jsonc');
        $files  = array_column($module['config'] ?? [], 'file', 'id');

        $this->assertSame('config/exchangeRateLists.jsonc', $files['world.cz.exchangeRateLists'] ?? null);
    }

    public function testDailyListComesFirstAndMonthlySecond(): void
    {
        $this->assertSame(['cz.cnb.daily', 'cz.cnb.other'], array_keys($this->types()));
    }

    public function testEveryTypeHasNamesHomeCurrencyAndValidityRule(): void
    {
        $currencies = JsoncParser::parseFile(self::CURRENCIES);

        foreach ($this->types() as $id => $type) {
            $this->assertMatchesRegularExpression('/^cz(\.[a-z0-9]+)+$/', (string) $id, 'id typu je tečková notace začínající zemí');
            foreach (['name', 'name:cs', 'name:en'] as $key) {
                $this->assertIsString($type[$key] ?? null, "{$id}: chybí {$key}");
                $this->assertNotSame('', trim($type[$key]), "{$id}: prázdné {$key}");
            }
            $this->assertSame('czk', $type['homeCurrency'] ?? null, "{$id}: lístky ČNB vyhlašují kurzy v Kč");
            $this->assertArrayHasKey($type['homeCurrency'], $currencies, "{$id}: homeCurrency musí být ve world.base.currencies");
            if (array_key_exists('maxAgeDays', $type)) {
                $this->assertIsInt($type['maxAgeDays'], "{$id}: maxAgeDays je celé číslo dní");
                $this->assertGreaterThanOrEqual(1, $type['maxAgeDays']);
            }
        }
    }

    public function testDailyListExpiresAfterSevenDays(): void
    {
        $this->assertSame(7, $this->types()['cz.cnb.daily']['maxAgeDays'] ?? null, 'U1: denní lístek nejvýš 7 dní');
    }

    public function testMonthlyListCarriesValidityOnTheListItself(): void
    {
        $this->assertArrayNotHasKey('maxAgeDays', $this->types()['cz.cnb.other'], 'měsíční lístek nese validTo sám');
    }

    /** Task B U2: každý typ ČNB se stahuje; konfigurace projde parserem a typy služby se neopakují. */
    public function testEveryTypeDeclaresValidDownload(): void
    {
        $serviceTypes = [];
        foreach ($this->types() as $id => $type) {
            $this->assertIsArray($type['download'] ?? null, "{$id}: chybí download");
            $config = ListDownloadConfig::fromArray($type['download']);
            $serviceTypes[] = $config->serviceListType;
        }
        $this->assertSame($serviceTypes, array_unique($serviceTypes), 'serviceListType je per typ jedinečný');
    }

    public function testDailyListIsDownloadedEveryCzechWorkingDayAfterPublication(): void
    {
        $this->assertSame(
            ['serviceListType' => 1, 'frequency' => 'workingDays', 'calendar' => 'cz', 'publishAfter' => '14:35', 'timezone' => 'Europe/Prague'],
            $this->types()['cz.cnb.daily']['download'],
        );
    }

    public function testMonthlyListIsDownloadedMonthly(): void
    {
        $this->assertSame(['serviceListType' => 2, 'frequency' => 'monthly'], $this->types()['cz.cnb.other']['download']);
    }

    /** @return array<string, array<string, mixed>> */
    private function types(): array
    {
        return JsoncParser::parseFile(self::MODULE . '/config/exchangeRateLists.jsonc');
    }
}

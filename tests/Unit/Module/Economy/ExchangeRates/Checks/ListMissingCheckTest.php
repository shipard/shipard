<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\ExchangeRates\Checks;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Alerts\AlertFinding;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Economy\ExchangeRates\Checks\ListMissingCheck;
use Shipard\Module\Economy\ExchangeRates\Download\CzechWorkingDayCalendar;
use Shipard\Module\Economy\ExchangeRates\Download\DownloadSchedule;
use Shipard\Module\Economy\ExchangeRates\Download\ExchangeRateListPresence;
use Shipard\Module\Economy\ExchangeRates\Download\ListDownloadConfig;
use Shipard\Module\Economy\ExchangeRates\Download\WorkingDayCalendars;
use Shipard\Tests\Fixtures\Module\Economy\ExchangeRates\Download\ArrayExchangeRateListPresence;

/**
 * Upozornění na chybějící lístek (#121 D9, task B §6): včerejší chybí →
 * nález; dnešní před vyhlášením → nic; víkend → nic; měsíční 1. den → nic,
 * 2. den → nález; před začátkem okna → nic; nejvýš 5 dat; typ bez lístků → nic.
 */
class ListMissingCheckTest extends TestCase
{
    private const CFG = [
        'cz.cnb.daily' => ['name' => 'ČNB — kurzy devizového trhu', 'homeCurrency' => 'czk', 'maxAgeDays' => 7, 'download' => ['serviceListType' => 1, 'frequency' => 'workingDays', 'calendar' => 'cz', 'publishAfter' => '14:35', 'timezone' => 'Europe/Prague']],
        'cz.cnb.other' => ['name' => 'ČNB — kurzy ostatních měn', 'homeCurrency' => 'czk', 'download' => ['serviceListType' => 2, 'frequency' => 'monthly']],
        'cz.manual'    => ['name' => 'ruční', 'homeCurrency' => 'czk', 'maxAgeDays' => 7],
    ];

    /** @return ListMissingCheck */
    private function check(string $nowUtc, ArrayExchangeRateListPresence $presence, string $language = 'cs', ?string $country = 'cz', array $years = [], string $home = 'czk'): ListMissingCheck
    {
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(static fn(string $id): mixed => $id === 'world.cz.exchangeRateLists' ? self::CFG : null);
        $dsConfig = null;
        if ($country !== null) {
            $dsConfig = $this->createMock(DataSourceConfig::class);
            $dsConfig->method('getCountry')->willReturn($country);
        }
        return new class($this->createMock(DataSourceConnection::class), $config, $language, $dsConfig, $nowUtc, $presence, $years, $home) extends ListMissingCheck {
            public function __construct(DataSourceConnection $db, ConfigRuntime $config, string $language, ?DataSourceConfig $dsConfig, private readonly string $nowUtc, private readonly ArrayExchangeRateListPresence $presenceFake, private readonly array $years, private readonly string $home)
            {
                parent::__construct($db, $config, $language, $dsConfig);
            }

            protected function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable($this->nowUtc, new \DateTimeZone('UTC'));
            }

            protected function presence(): ExchangeRateListPresence
            {
                return $this->presenceFake;
            }

            protected function fiscalYears(): array
            {
                return $this->years;
            }

            protected function calendars(): WorkingDayCalendars
            {
                return new WorkingDayCalendars(['cz' => new CzechWorkingDayCalendar()]);
            }

            protected function homeCurrency(): string
            {
                return $this->home;
            }
        };
    }

    /** Všechny pracovní dny od `from` po `to` včetně. @return list<string> */
    private static function workingDays(string $from, string $to): array
    {
        $config = ListDownloadConfig::fromArray(self::CFG['cz.cnb.daily']['download']);
        return DownloadSchedule::expectedDates($config, new CzechWorkingDayCalendar(), $from, $to, new \DateTimeImmutable('2030-01-01', new \DateTimeZone('UTC')));
    }

    private function presence(array $dailyMissing = [], array $monthly = ['2026-09-01', '2026-10-01']): ArrayExchangeRateListPresence
    {
        return new ArrayExchangeRateListPresence([
            'cz.cnb.daily' => array_values(array_diff(self::workingDays('2026-08-01', '2026-10-20'), $dailyMissing)),
            'cz.cnb.other' => $monthly,
        ]);
    }

    public function testCompleteListsGiveNoFinding(): void
    {
        // Úterý 20. 10. 2026 15:00 v Praze.
        $this->assertSame([], $this->check('2026-10-20 13:00:00', $this->presence())->run());
    }

    public function testMissingYesterdayIsReportedTodayIsNot(): void
    {
        $findings = $this->check('2026-10-20 13:00:00', $this->presence(['2026-10-19', '2026-10-20']))->run();

        $this->assertCount(1, $findings);
        $f = $findings[0];
        $this->assertInstanceOf(AlertFinding::class, $f);
        $this->assertSame('cz.cnb.daily', $f->findingKey);
        $this->assertSame('warning', $f->severity);
        $this->assertSame('Chybí kurzovní lístek ČNB — kurzy devizového trhu', $f->title);
        $this->assertSame('Lístek chybí za 1 pracovní den (19. 10.). K těmto datům se kurz nedoplní.', $f->message);
        $this->assertSame(['list_type' => 'cz.cnb.daily', 'missing' => ['2026-10-19']], $f->context);
        $this->assertSame([['id' => 'open_lists', 'label' => 'Otevřít kurzovní lístky', 'kind' => 'open_viewer', 'primary' => true, 'target' => ['viewerId' => 'economy.exchangeRates.lists']]], $f->actions);
    }

    public function testTodayBeforePublicationAndWeekendGiveNothing(): void
    {
        // 20. 10. ve 14:00 v Praze: dnešek se nehlídá nikdy, včerejšek je.
        $this->assertSame([], $this->check('2026-10-20 12:00:00', $this->presence(['2026-10-20']))->run());
        // Sobota 17. 10.: pátek je, víkend se neočekává.
        $this->assertSame([], $this->check('2026-10-17 10:00:00', $this->presence(['2026-10-17', '2026-10-19', '2026-10-20']))->run());
        // Pondělí 19. 10. ráno: chybí pátek 16. 10. → nález, sobota a neděle ne.
        $findings = $this->check('2026-10-19 06:00:00', $this->presence(['2026-10-16', '2026-10-19', '2026-10-20']))->run();
        $this->assertSame(['2026-10-16'], $findings[0]->context['missing']);
    }

    public function testCountsAndNewestFiveDatesInCzechAndEnglish(): void
    {
        $missing = ['2026-10-19', '2026-10-16', '2026-10-15', '2026-10-14', '2026-10-13', '2026-10-12', '2026-10-09'];
        $cs = $this->check('2026-10-20 13:00:00', $this->presence($missing))->run()[0];
        $this->assertSame('Lístek chybí za 7 pracovních dnů (19. 10., 16. 10., 15. 10., 14. 10., 13. 10., …). K těmto datům se kurz nedoplní.', $cs->message);
        $this->assertSame(array_reverse($missing), $cs->context['missing'], 'kontext nese všechna data vzestupně');

        $en = $this->check('2026-10-20 13:00:00', $this->presence(['2026-10-19', '2026-10-16', '2026-10-15']), 'en')->run()[0];
        $this->assertSame('Exchange rate list ČNB — kurzy devizového trhu is missing', $en->title);
        $this->assertSame('No list for 3 working days (19. 10., 16. 10., 15. 10.). No rate will be filled in for these dates.', $en->message);
        $this->assertSame('Open exchange rate lists', $en->actions[0]['label']);

        $three = $this->check('2026-10-20 13:00:00', $this->presence(['2026-10-19', '2026-10-16', '2026-10-15']))->run()[0];
        $this->assertStringStartsWith('Lístek chybí za 3 pracovní dny', $three->message);
    }

    public function testLookbackIsThirtyDaysAndNotBeforeWindowStart(): void
    {
        // 31 dní zpět (19. 9.) se nehlídá, 30 dní (21. 9., pondělí) ano.
        $findings = $this->check('2026-10-20 13:00:00', $this->presence(['2026-09-18', '2026-09-21']))->run();
        $this->assertSame(['2026-09-21'], $findings[0]->context['missing']);

        // Okno začíná 1. 10. 2026 (začátek předchozího fiskálního roku) → září se nehlídá.
        $years = [['begin' => '2026-10-01', 'end' => '2026-10-14'], ['begin' => '2026-10-15', 'end' => '2027-10-14']];
        $this->assertSame([], $this->check('2026-10-20 13:00:00', $this->presence(['2026-09-21', '2026-09-30']), years: $years)->run());
    }

    public function testMonthlyListIsExpectedFromTheSecondDay(): void
    {
        $this->assertSame([], $this->check('2026-10-01 20:00:00', $this->presence(monthly: ['2026-09-01']))->run(), '1. den měsíce ještě nic');

        $findings = $this->check('2026-10-02 06:00:00', $this->presence(monthly: ['2026-09-01']))->run();
        $this->assertCount(1, $findings);
        $this->assertSame('cz.cnb.other', $findings[0]->findingKey);
        $this->assertSame('Chybí kurzovní lístek ČNB — kurzy ostatních měn', $findings[0]->title);
        $this->assertSame('Lístek platný od 1. 10. 2026 ve zdroji dat není. K datům tohoto měsíce se kurz nedoplní.', $findings[0]->message);
        $this->assertSame(['2026-10-01'], $findings[0]->context['missing']);

        $en = $this->check('2026-10-02 06:00:00', $this->presence(monthly: ['2026-09-01']), 'en')->run()[0];
        $this->assertSame('The list valid from 2026-10-01 is missing. No rate will be filled in for this month.', $en->message);

        // 1. 10. ve 23:30 UTC je v Praze už 2. 10. 01:30 → nález.
        $this->assertCount(1, $this->check('2026-10-01 23:30:00', $this->presence(monthly: ['2026-09-01']))->run());
    }

    public function testTypeWithoutAnyListOrWithoutDownloadIsIgnored(): void
    {
        $presence = new ArrayExchangeRateListPresence(['cz.cnb.daily' => [], 'cz.cnb.other' => [], 'cz.manual' => []]);
        $this->assertSame([], $this->check('2026-10-20 13:00:00', $presence)->run(), 'zdroj dat bez lístků = bez nálezu');

        $presence = new ArrayExchangeRateListPresence(['cz.cnb.daily' => ['2026-10-16'], 'cz.cnb.other' => [], 'cz.manual' => []]);
        $findings = $this->check('2026-10-20 13:00:00', $presence)->run();
        $this->assertSame(['cz.cnb.daily'], array_map(static fn(AlertFinding $f): string => $f->findingKey, $findings), 'měsíční typ bez jediného lístku se nehlídá, ruční typ nikdy');
    }

    public function testCountryDefaultsToCzWithoutDataSourceConfigAndOtherHomeCurrencyHasNoTypes(): void
    {
        $this->assertCount(1, $this->check('2026-10-20 13:00:00', $this->presence(['2026-10-19']), country: null)->run());
        $this->assertSame([], $this->check('2026-10-20 13:00:00', $this->presence(['2026-10-19']), country: 'sk')->run(), 'jiná země = žádné typy');
        $this->assertSame([], $this->check('2026-10-20 13:00:00', $this->presence(['2026-10-19']), home: 'eur')->run(), 'typy jen domácí měny');
    }
}

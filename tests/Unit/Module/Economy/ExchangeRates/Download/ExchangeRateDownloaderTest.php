<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\ExchangeRates\Download;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\ExchangeRates\Download\CzechWorkingDayCalendar;
use Shipard\Module\Economy\ExchangeRates\Download\DownloadOptions;
use Shipard\Module\Economy\ExchangeRates\Download\DownloadQuery;
use Shipard\Module\Economy\ExchangeRates\Download\DownloadReport;
use Shipard\Module\Economy\ExchangeRates\Download\DownloadSchedule;
use Shipard\Module\Economy\ExchangeRates\Download\DownloadState;
use Shipard\Module\Economy\ExchangeRates\Download\ExchangeRateDownloader;
use Shipard\Module\Economy\ExchangeRates\Download\ExchangeRateServiceClient;
use Shipard\Module\Economy\ExchangeRates\Download\ListDownloadConfig;
use Shipard\Module\Economy\ExchangeRates\Download\WorkingDayCalendars;
use Shipard\Module\Economy\ExchangeRates\ExchangeRateListImporter;
use Shipard\Module\Economy\ExchangeRates\ExchangeRateListImportResult;
use Shipard\Module\Economy\ExchangeRates\ExchangeRateListType;
use Shipard\Tests\Fixtures\Core\Settings\InMemoryKeyValueStore;
use Shipard\Tests\Fixtures\Module\Economy\ExchangeRates\Download\ArrayExchangeRateListPresence;
use Shipard\Tests\Fixtures\Module\Economy\ExchangeRates\Download\FakeExchangeRateListImporter;
use Shipard\Tests\Fixtures\Module\Economy\ExchangeRates\Service\FakeExchangeRateServiceTransport;

/**
 * Stahovač (#121 task B §4): okno, chybějící data, dotazy po měsících od
 * nejnovějšího, limit 6, pauza 1 h a výjimka pro dnešek, chyba ukončí
 * běh a pozastaví další, nic nechybí → nula dotazů, přítomné datum se
 * ignoruje, konflikt nepřepisuje, stav se prořezává.
 */
class ExchangeRateDownloaderTest extends TestCase
{
    private const URL = 'https://service.test/feed';

    /** Úterý 20. 10. 2026 15:00 v Praze (po vyhlášení). */
    private const NOW = '2026-10-20 13:00:00';

    /** Běžný rok od 1. 10., předchozí jen květen–září → okno od 1. 5. 2026. */
    private const YEARS = [['begin' => '2026-05-01', 'end' => '2026-09-30'], ['begin' => '2026-10-01', 'end' => '2027-09-30']];

    private FakeExchangeRateListImporter $importer;
    private FakeExchangeRateServiceTransport $transport;
    private ArrayExchangeRateListPresence $presence;
    private InMemoryKeyValueStore $settings;

    protected function setUp(): void
    {
        $this->importer = new FakeExchangeRateListImporter();
        $this->transport = new FakeExchangeRateServiceTransport();
        $this->presence = new ArrayExchangeRateListPresence();
        $this->settings = new InMemoryKeyValueStore();
    }

    private static function utc(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone('UTC'));
    }

    /** @return list<ExchangeRateListType> */
    private function types(): array
    {
        return [
            new ExchangeRateListType('cz.cnb.daily', 'ČNB denní', 'czk', 7, ListDownloadConfig::fromArray(['serviceListType' => 1, 'frequency' => 'workingDays', 'calendar' => 'cz', 'publishAfter' => '14:35', 'timezone' => 'Europe/Prague'])),
            new ExchangeRateListType('cz.cnb.other', 'ČNB ostatní', 'czk', null, ListDownloadConfig::fromArray(['serviceListType' => 2, 'frequency' => 'monthly'])),
        ];
    }

    /** @param list<ExchangeRateListType>|null $types */
    private function downloader(string $now = self::NOW, ?array $types = null, array $years = self::YEARS, ?WorkingDayCalendars $calendars = null): ExchangeRateDownloader
    {
        return new ExchangeRateDownloader(
            $types ?? $this->types(),
            $this->importer,
            new ExchangeRateServiceClient(self::URL, $this->transport),
            $this->presence,
            new DownloadState($this->settings),
            $calendars ?? new WorkingDayCalendars(['cz' => new CzechWorkingDayCalendar()]),
            $years,
            self::utc($now),
        );
    }

    /** Všechna očekávaná data typu v okně [from, to] k času NOW — pro „nic nechybí kromě …“. @return list<string> */
    private function expected(string $listType, string $from, string $to, string $now = self::NOW): array
    {
        $type = array_values(array_filter($this->types(), static fn(ExchangeRateListType $t): bool => $t->id === $listType))[0];
        return DownloadSchedule::expectedDates($type->download, new CzechWorkingDayCalendar(), $from, $to, self::utc($now));
    }

    /** @return list<string> */
    private static function labels(array $queries): array
    {
        return array_map(static fn(DownloadQuery $q): string => $q->label() . ($q->paused() ? ' [paused]' : '') . ($q->todayPoll ? ' [today]' : ''), $queries);
    }

    public function testNothingMissingMeansNoQueriesNoNetworkNoStateWrite(): void
    {
        $this->presence->dates = ['cz.cnb.daily' => $this->expected('cz.cnb.daily', '2026-05-01', '2026-10-20'), 'cz.cnb.other' => $this->expected('cz.cnb.other', '2026-05-01', '2026-10-20')];
        $downloader = $this->downloader();

        $plan = $downloader->plan(new DownloadOptions());
        $this->assertSame('2026-05-01', $plan->from);
        $this->assertSame('2026-10-20', $plan->to);
        $this->assertSame([], $plan->queries);
        $this->assertSame(0, $plan->missingTotal());
        $this->assertSame([119, 6], [$plan->types[0]->expected, $plan->types[1]->expected], 'pracovní dny 1. 5. – 20. 10. 2026 (bez 1. 5., 8. 5., 6. 7., 28. 9.) a šest měsíčních');

        $report = $downloader->run(new DownloadOptions());
        $this->assertSame(0, $report->sent);
        $this->assertSame([], $this->transport->urls);
        $this->assertSame([], $this->importer->calls);
        $this->assertSame(0, $this->settings->writes, 'bez dotazu se stav nezapisuje');
        $this->assertFalse($report->failed());
    }

    public function testMissingDatesAreGroupedByMonthNewestFirstAndCappedAtSixPerRun(): void
    {
        $downloader = $this->downloader();
        $plan = $downloader->plan(new DownloadOptions());

        $this->assertSame([
            'cz.cnb.daily 2026-10-01..2026-10-20', 'cz.cnb.other 2026-10-01..2026-10-01',
            'cz.cnb.daily 2026-09-01..2026-09-30', 'cz.cnb.other 2026-09-01..2026-09-01',
            'cz.cnb.daily 2026-08-03..2026-08-31', 'cz.cnb.other 2026-08-01..2026-08-01',
            'cz.cnb.daily 2026-07-01..2026-07-31', 'cz.cnb.other 2026-07-01..2026-07-01',
            'cz.cnb.daily 2026-06-01..2026-06-30', 'cz.cnb.other 2026-06-01..2026-06-01',
            'cz.cnb.daily 2026-05-04..2026-05-29', 'cz.cnb.other 2026-05-01..2026-05-01',
        ], self::labels($plan->queries), 'měsíc sestupně, uvnitř pořadí typů; dotaz = první..poslední chybějící datum měsíce');
        $this->assertSame(6, $plan->limit);
        $this->assertCount(6, $plan->queriesToSend());
        $this->assertSame('cz.cnb.daily 2026-10-01..2026-10-20', $plan->queriesToSend()[0]->label());
        $this->assertSame([['cz.cnb.daily', '2026-05-04', '2026-10-20'], ['cz.cnb.other', '2026-05-01', '2026-10-01']], $this->presence->ranges, 'jeden dotaz na typ přes rozsah očekávaných dat');

        for ($i = 0; $i < 6; $i++) {
            $this->transport->queueFixture('none.json');
        }
        $report = $downloader->run(new DownloadOptions());

        $this->assertSame(6, $report->sent);
        $this->assertSame(6, $report->pending());
        $this->assertSame(self::URL . '?dateFrom=2026-10-01&dateTo=2026-10-20&listType=1', $this->transport->urls[0]);
        $this->assertSame(self::URL . '?dateFrom=2026-10-01&dateTo=2026-10-01&listType=2', $this->transport->urls[1]);
        $this->assertSame(self::URL . '?dateFrom=2026-08-01&dateTo=2026-08-01&listType=2', $this->transport->urls[5]);
        $state = $this->settings->values[DownloadState::KEY];
        $this->assertSame(['cz.cnb.daily|2026-10', 'cz.cnb.other|2026-10', 'cz.cnb.daily|2026-09', 'cz.cnb.other|2026-09', 'cz.cnb.daily|2026-08', 'cz.cnb.other|2026-08'], array_keys($state['attempts']));
        $this->assertSame(self::utc(self::NOW)->getTimestamp(), $state['attempts']['cz.cnb.daily|2026-10']);
        $this->assertNull($state['lastError']);
        $this->assertFalse($report->failed(), 'prázdná odpověď není chyba');
    }

    public function testRunImportsReturnedListsIgnoresPresentDatesAndReportsOutcomes(): void
    {
        // Všechno do konce září je, v říjnu je jen 1. 10.; chybí 2.–20. 10.
        $this->presence->dates = [
            'cz.cnb.daily' => [...$this->expected('cz.cnb.daily', '2026-05-01', '2026-09-30'), '2026-10-01'],
            'cz.cnb.other' => $this->expected('cz.cnb.other', '2026-05-01', '2026-10-20'),
        ];
        $this->importer->outcomes['cz.cnb.daily 2026-10-05'] = ExchangeRateListImportResult::CONFLICT;
        $this->importer->skippedCurrencies['cz.cnb.daily 2026-10-06'] = ['XDR'];
        $this->importer->failFirst['cz.cnb.daily 2026-10-07'] = 1;
        $this->transport->queueFixture('daily-2026-10.json');
        $downloader = $this->downloader();

        $plan = $downloader->plan(new DownloadOptions());
        $this->assertSame(['cz.cnb.daily 2026-10-02..2026-10-20'], self::labels($plan->queries));
        $this->assertSame(['2026-10-02', '2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09', '2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15', '2026-10-16', '2026-10-19', '2026-10-20'], $plan->types[0]->missing);

        $report = $downloader->run(new DownloadOptions());

        $this->assertSame(1, $report->sent);
        $line = $report->results[0];
        $this->assertSame(DownloadReport::KIND_LISTS, $line['kind']);
        $this->assertSame([13, 11, 0, 1, 1, 0], [$line['lists'], $line['created'], $line['skipped'], $line['ignored'], $line['conflict'], $line['failed']], '1. 10. je přítomný → ignorován bez importu; 5. 10. konflikt');
        $this->assertSame(['cz.cnb.daily 2026-10-05: lístek už existuje s jiným obsahem — nepřepsán'], $report->warnings);
        $this->assertSame(['cz.cnb.daily 2026-10-06: přeskočené měny XDR'], $report->notices);
        $this->assertTrue($report->failed(), 'konflikt = exit 1');
        $this->assertNull($report->error);

        $calls = array_column($this->importer->calls, 'validFrom');
        $this->assertNotContains('2026-10-01', $calls);
        $this->assertSame(2, count(array_keys($calls, '2026-10-07', true)), 'selhání zápisu se jednou opakuje (souběh na unikátním klíči)');
        $this->assertSame([ExchangeRateListImporter::SOURCE_SERVICE], array_values(array_unique(array_column($this->importer->calls, 'source'))));
        $this->assertSame(self::utc(self::NOW)->getTimestamp(), $this->settings->values[DownloadState::KEY]['attempts']['cz.cnb.daily|2026-10']);
    }

    public function testListsOnUnexpectedDatesAreImportedFutureDatesSkippedWithOneWarning(): void
    {
        $this->presence->dates = [
            'cz.cnb.daily' => array_values(array_diff($this->expected('cz.cnb.daily', '2026-05-01', '2026-10-20'), ['2026-10-20'])),
            'cz.cnb.other' => $this->expected('cz.cnb.other', '2026-05-01', '2026-10-20'),
        ];
        $list = static fn(string $date): array => ['format' => 'shpd.exchangeRates.list.v1', 'listType' => 'cz.cnb.daily', 'homeCurrency' => 'CZK', 'validFrom' => $date, 'validTo' => null, 'rates' => []];
        // Služba vrátí lístek k sobotě 17. 10. (kalendář ho neočekává) a dva s budoucím datem.
        $this->transport->queueBody(json_encode(['valid' => 1, 'data' => [$list('2026-10-17'), $list('2026-10-20'), $list('2026-10-21'), $list('2026-10-22')]]));
        $report = $this->downloader()->run(new DownloadOptions());

        $this->assertSame(['2026-10-17', '2026-10-20'], array_column($this->importer->calls, 'validFrom'), 'sobotní lístek se uloží, budoucí ne');
        $this->assertSame(2, $report->created);
        $this->assertSame(['cz.cnb.daily: služba vrátila lístek s budoucím datem 2026-10-21 — přeskočen'], $report->warnings, 'jen jedno varování na typ');
        $this->assertFalse($report->failed());
    }

    public function testTodayIsPolledEveryRunWithoutRecordingAnAttemptOtherDaysPauseForAnHour(): void
    {
        $nowTs = self::utc(self::NOW)->getTimestamp();
        $missingTodayAndYesterday = static fn(array $dates): array => array_values(array_diff($dates, ['2026-10-19', '2026-10-20']));
        $this->presence->dates = [
            'cz.cnb.daily' => $missingTodayAndYesterday($this->expected('cz.cnb.daily', '2026-05-01', '2026-10-20')),
            'cz.cnb.other' => $this->expected('cz.cnb.other', '2026-05-01', '2026-10-20'),
        ];
        // Před 10 minutami proběhl dotaz na říjen.
        $this->settings->values[DownloadState::KEY] = ['lastError' => null, 'attempts' => ['cz.cnb.daily|2026-10' => $nowTs - 600]];

        $downloader = $this->downloader();
        $this->assertSame(['cz.cnb.daily 2026-10-20..2026-10-20 [today]'], self::labels($downloader->plan(new DownloadOptions())->queries), 'včerejšek čeká hodinu, dnešek se zkouší hned');

        $this->transport->queueFixture('none.json');
        $report = $downloader->run(new DownloadOptions());
        $this->assertSame(1, $report->sent);
        $this->assertSame(0, $this->settings->writes, 'dnešní dotaz pokus nezapisuje');
        $this->assertSame($nowTs - 600, $this->settings->values[DownloadState::KEY]['attempts']['cz.cnb.daily|2026-10']);

        // O hodinu později: celý měsíc je splatný — jeden dotaz 19.–20. 10. a pokus se zapíše.
        $this->settings->values[DownloadState::KEY]['attempts']['cz.cnb.daily|2026-10'] = $nowTs - 3600;
        $downloader = $this->downloader();
        $this->assertSame(['cz.cnb.daily 2026-10-19..2026-10-20'], self::labels($downloader->plan(new DownloadOptions())->queries));
        $this->transport->queueFixture('none.json');
        $downloader->run(new DownloadOptions());
        $this->assertSame($nowTs, $this->settings->values[DownloadState::KEY]['attempts']['cz.cnb.daily|2026-10']);

        // Před vyhlášením (14:00 v Praze): dnešek se neočekává, včerejšek je v pauze → dotaz pozastaven.
        $this->settings->values[DownloadState::KEY]['attempts']['cz.cnb.daily|2026-10'] = $nowTs - 600;
        $plan = $this->downloader('2026-10-20 12:00:00')->plan(new DownloadOptions());
        $this->assertSame(['cz.cnb.daily 2026-10-19..2026-10-19 [paused]'], self::labels($plan->queries));
        $this->assertStringContainsString('další pokus po 15:50', (string) $plan->queries[0]->pausedReason);
        $this->assertSame([], $plan->queriesToSend());
        $this->assertFalse($plan->types[0]->todayPublished);
    }

    public function testMonthlyTypeHasNoTodayExceptionAndPausesLikeAnyMonth(): void
    {
        $nowTs = self::utc('2026-10-01 10:00:00')->getTimestamp();
        $this->presence->dates = ['cz.cnb.daily' => $this->expected('cz.cnb.daily', '2026-05-01', '2026-10-01', '2026-10-01 10:00:00'), 'cz.cnb.other' => $this->expected('cz.cnb.other', '2026-05-01', '2026-09-30')];
        $this->settings->values[DownloadState::KEY] = ['lastError' => null, 'attempts' => ['cz.cnb.other|2026-10' => $nowTs - 300]];

        $plan = $this->downloader('2026-10-01 10:00:00')->plan(new DownloadOptions());

        $this->assertSame(['cz.cnb.other 2026-10-01..2026-10-01 [paused]'], self::labels($plan->queries), '1. den měsíce se měsíční lístek očekává, ale po pokusu čeká hodinu');
    }

    public function testServiceErrorStopsTheRunAndPausesFollowingRunsForAnHour(): void
    {
        $this->transport->queueBody('oops', 500);
        $downloader = $this->downloader();
        $report = $downloader->run(new DownloadOptions());

        $this->assertSame(1, $report->sent, 'po chybě se další dotazy neposílají');
        $this->assertSame('cz.cnb.daily 2026-10-01..2026-10-20: HTTP 500', $report->error);
        $this->assertSame(DownloadReport::KIND_ERROR, $report->results[0]['kind']);
        $this->assertTrue($report->failed());
        $this->assertSame(0, $report->created);
        $nowTs = self::utc(self::NOW)->getTimestamp();
        $this->assertSame($nowTs, $this->settings->values[DownloadState::KEY]['lastError']);
        $this->assertSame([], $this->importer->calls);

        // Za 10 minut: celý běh pozastaven, i dnešní lístek.
        $plan = $this->downloader('2026-10-20 13:10:00')->plan(new DownloadOptions());
        $this->assertSame($nowTs + 3600, $plan->pausedUntil);
        $this->assertCount(12, $plan->queries);
        $this->assertSame([], $plan->queriesToSend());
        $this->assertStringContainsString('po chybě služby do 16:00', (string) $plan->queries[0]->pausedReason);
        $report = $this->downloader('2026-10-20 13:10:00')->run(new DownloadOptions());
        $this->assertSame(0, $report->sent);
        $this->assertSame([], array_slice($this->transport->urls, 1));

        // Po hodině se jede dál (měsíc říjen má pokus z chybného běhu, ale už starší než hodina).
        $plan = $this->downloader('2026-10-20 14:00:00')->plan(new DownloadOptions());
        $this->assertNull($plan->pausedUntil);
        $this->assertCount(6, $plan->queriesToSend());
    }

    public function testManualWindowIgnoresLimitPauseAndState(): void
    {
        $nowTs = self::utc(self::NOW)->getTimestamp();
        $this->settings->values[DownloadState::KEY] = ['lastError' => $nowTs - 60, 'attempts' => ['cz.cnb.daily|2026-10' => $nowTs - 60, 'cz.cnb.other|2026-10' => $nowTs - 60]];
        $downloader = $this->downloader();

        $plan = $downloader->plan(new DownloadOptions(from: '2026-09-15', to: '2026-10-20'));
        $this->assertNull($plan->limit);
        $this->assertNull($plan->pausedUntil);
        $this->assertSame(['2026-09-15', '2026-10-20'], [$plan->from, $plan->to]);
        $this->assertSame([
            'cz.cnb.daily 2026-10-01..2026-10-20', 'cz.cnb.other 2026-10-01..2026-10-01',
            'cz.cnb.daily 2026-09-15..2026-09-30', 'cz.cnb.other 2026-09-01..2026-09-01',
        ], self::labels($plan->queries), 'bez pauzy; měsíční lístek září se očekává i při začátku uprostřed měsíce');
        $this->assertCount(4, $plan->queriesToSend());

        for ($i = 0; $i < 4; $i++) {
            $this->transport->queueFixture('none.json');
        }
        $downloader->run(new DownloadOptions(from: '2026-09-15', to: '2026-10-20'));
        $this->assertSame(0, $this->settings->writes, 'ruční běh stav nečte ani nepíše');

        $plan = $downloader->plan(new DownloadOptions(from: '2026-10-19'));
        $this->assertSame(['cz.cnb.daily 2026-10-19..2026-10-20', 'cz.cnb.other 2026-10-01..2026-10-01'], self::labels($plan->queries), 'jen --from = ruční mód po dnešek');
    }

    public function testTypeOptionLimitsTheRunButStatePruningKeepsOtherTypes(): void
    {
        $nowTs = self::utc(self::NOW)->getTimestamp();
        $this->settings->values[DownloadState::KEY] = ['lastError' => null, 'attempts' => ['cz.cnb.other|2026-10' => $nowTs - 60, 'cz.cnb.daily|2024-01' => 5]];
        $downloader = $this->downloader();

        $plan = $downloader->plan(new DownloadOptions(type: 'cz.cnb.other'));
        $this->assertSame(['cz.cnb.other'], array_map(static fn($t) => $t->listType, $plan->types));
        $this->assertSame(6, $plan->limit, '--type bez okna zůstává v cron módu');
        $this->assertCount(5, $plan->queriesToSend(), 'říjen je v pauze');

        for ($i = 0; $i < 5; $i++) {
            $this->transport->queueFixture('none.json');
        }
        $downloader->run(new DownloadOptions(type: 'cz.cnb.other'));
        $attempts = $this->settings->values[DownloadState::KEY]['attempts'];
        $this->assertArrayHasKey('cz.cnb.other|2026-10', $attempts, 'pokus cizího měsíce v okně zůstal');
        $this->assertArrayNotHasKey('cz.cnb.daily|2024-01', $attempts, 'měsíc mimo okno pryč');
        $this->assertArrayHasKey('cz.cnb.other|2026-09', $attempts);
    }

    public function testTypesWithoutOrWithBrokenDownloadAreSkippedWithWarnings(): void
    {
        $types = [
            new ExchangeRateListType('cz.manual', 'ruční', 'czk', 7),
            new ExchangeRateListType('cz.broken', 'rozbitý', 'czk', 7, null, 'download.publishAfter musí být čas HH:MM'),
            new ExchangeRateListType('cz.noCal', 'bez kalendáře', 'czk', 7, ListDownloadConfig::fromArray(['serviceListType' => 9, 'frequency' => 'workingDays', 'calendar' => 'xx', 'publishAfter' => '14:35'])),
            $this->types()[1],
        ];
        $this->presence->dates = ['cz.cnb.other' => $this->expected('cz.cnb.other', '2026-05-01', '2026-10-20')];
        $downloader = $this->downloader(types: $types);

        $plan = $downloader->plan(new DownloadOptions());
        $this->assertSame(['cz.broken: download.publishAfter musí být čas HH:MM', 'cz.noCal: neznámý kalendář „xx“'], $plan->configWarnings);
        $this->assertSame(['cz.cnb.other'], array_map(static fn($t) => $t->listType, $plan->types));
        $this->assertSame([], $plan->queries);
        $this->assertSame(['cz.manual', 'cz.broken', 'cz.noCal', 'cz.cnb.other'], $downloader->typeIds());
    }

    public function testWindowFromFiscalYearsAndFallbackWithoutThem(): void
    {
        $this->presence->dates = ['cz.cnb.daily' => $this->expected('cz.cnb.daily', '2025-01-01', '2026-10-20'), 'cz.cnb.other' => $this->expected('cz.cnb.other', '2025-01-01', '2026-10-20')];

        $plan = $this->downloader(years: [])->plan(new DownloadOptions());
        $this->assertSame('2025-01-01', $plan->from, 'bez fiskálních let 1. 1. předchozího kalendářního roku');
        $this->assertSame([], $plan->queries);

        $plan = $this->downloader(years: [['begin' => '2025-10-15', 'end' => '2026-10-14'], ['begin' => '2026-10-15', 'end' => '2027-10-14']])->plan(new DownloadOptions());
        $this->assertSame('2025-10-15', $plan->from);
        $this->assertSame(['cz.cnb.other', '2025-10-01', '2026-10-01'], $this->presence->ranges[3], 'měsíční rozsah od 1. dne měsíce začátku okna');
        $this->assertSame([], $plan->queries);
    }
}

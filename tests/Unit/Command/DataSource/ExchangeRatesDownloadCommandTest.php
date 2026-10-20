<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\DataSource;

use PHPUnit\Framework\TestCase;
use Shipard\Command\DataSource\ExchangeRatesDownloadCommand;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Module\Economy\ExchangeRates\Download\CzechWorkingDayCalendar;
use Shipard\Module\Economy\ExchangeRates\Download\DownloadState;
use Shipard\Module\Economy\ExchangeRates\Download\ExchangeRateDownloader;
use Shipard\Module\Economy\ExchangeRates\Download\ExchangeRateServiceClient;
use Shipard\Module\Economy\ExchangeRates\Download\ListDownloadConfig;
use Shipard\Module\Economy\ExchangeRates\Download\WorkingDayCalendars;
use Shipard\Module\Economy\ExchangeRates\ExchangeRateListImportResult;
use Shipard\Module\Economy\ExchangeRates\ExchangeRateListType;
use Shipard\Tests\Fixtures\Core\Settings\InMemoryKeyValueStore;
use Shipard\Tests\Fixtures\Module\Economy\ExchangeRates\Download\ArrayExchangeRateListPresence;
use Shipard\Tests\Fixtures\Module\Economy\ExchangeRates\Download\FakeExchangeRateListImporter;
use Shipard\Tests\Fixtures\Module\Economy\ExchangeRates\Service\FakeExchangeRateServiceTransport;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `shpd-ds exchange-rates-download` (#121 task B §5): volby, vypnuté
 * stahování, neaktivní modul, --dry-run bez sítě, výpis běhu, exit kódy,
 * log jen v běhu s dotazem.
 */
class ExchangeRatesDownloadCommandTest extends TestCase
{
    /** Úterý 20. 10. 2026 15:00 v Praze. */
    private const NOW = '2026-10-20 13:00:00';

    private FakeExchangeRateListImporter $importer;
    private FakeExchangeRateServiceTransport $transport;
    private ArrayExchangeRateListPresence $presence;
    private InMemoryKeyValueStore $settings;
    private string $logPath;

    protected function setUp(): void
    {
        $this->importer = new FakeExchangeRateListImporter();
        $this->transport = new FakeExchangeRateServiceTransport();
        $this->presence = new ArrayExchangeRateListPresence();
        $this->settings = new InMemoryKeyValueStore();
        $this->logPath = sys_get_temp_dir() . '/shpd-xrd-' . bin2hex(random_bytes(4)) . '.log';
        ErrorLogger::resetForTesting();
        ErrorLogger::setLogPath($this->logPath);
    }

    protected function tearDown(): void
    {
        ErrorLogger::resetForTesting();
        @unlink($this->logPath);
    }

    /** @param array{url: ?string, error: ?string} $service */
    private function tester(bool $moduleActive = true, array $service = ['url' => 'https://service.test/feed', 'error' => null], string $now = self::NOW): CommandTester
    {
        $dsConfig = $this->createMock(DataSourceConfig::class);
        $dsConfig->method('getId')->willReturn('aaaa-bbbb-cccc-dddd');
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('getAllTableNames')->willReturn($moduleActive ? ['economy_exchange_rates_lists'] : ['docs_core_heads']);

        $test = $this;
        $command = new class($dsConfig, $db, $service, $now, $test) extends ExchangeRatesDownloadCommand {
            public function __construct(DataSourceConfig $dsConfig, DataSourceConnection $db, private readonly array $service, private readonly string $nowString, private readonly ExchangeRatesDownloadCommandTest $test)
            {
                parent::__construct($dsConfig, $db);
            }

            protected function getDataSourceDir(): string
            {
                return '/nonexistent';
            }

            protected function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable($this->nowString, new \DateTimeZone('UTC'));
            }

            protected function resolveServiceUrl(string $dsId): array
            {
                return $this->service;
            }

            protected function createDownloader(string $dsDir, DataSourceConfig $dsConfig, DataSourceConnection $dsConnection, string $serviceUrl, \DateTimeImmutable $now): ExchangeRateDownloader
            {
                return $this->test->downloader($serviceUrl, $now);
            }
        };
        $app = new Application();
        $app->add($command);
        return new CommandTester($app->find('exchange-rates-download'));
    }

    public function downloader(string $serviceUrl, \DateTimeImmutable $now): ExchangeRateDownloader
    {
        $types = [
            new ExchangeRateListType('cz.cnb.daily', 'ČNB denní', 'czk', 7, ListDownloadConfig::fromArray(['serviceListType' => 1, 'frequency' => 'workingDays', 'calendar' => 'cz', 'publishAfter' => '14:35'])),
            new ExchangeRateListType('cz.cnb.other', 'ČNB ostatní', 'czk', null, ListDownloadConfig::fromArray(['serviceListType' => 2, 'frequency' => 'monthly'])),
        ];
        return new ExchangeRateDownloader(
            $types,
            $this->importer,
            new ExchangeRateServiceClient($serviceUrl, $this->transport),
            $this->presence,
            new DownloadState($this->settings),
            new WorkingDayCalendars(['cz' => new CzechWorkingDayCalendar()]),
            [['begin' => '2026-10-01', 'end' => '2026-10-31'], ['begin' => '2026-09-01', 'end' => '2026-09-30']],
            $now,
        );
    }

    /** Říjen 2026 kompletní kromě 19. a 20. 10., září kompletní. */
    private function presentAllButYesterdayAndToday(): void
    {
        $this->presence->dates = [
            'cz.cnb.daily' => ['2026-09-01', '2026-09-02', '2026-09-03', '2026-09-04', '2026-09-07', '2026-09-08', '2026-09-09', '2026-09-10', '2026-09-11', '2026-09-14', '2026-09-15', '2026-09-16', '2026-09-17', '2026-09-18', '2026-09-21', '2026-09-22', '2026-09-23', '2026-09-24', '2026-09-25', '2026-09-29', '2026-09-30', '2026-10-01', '2026-10-02', '2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09', '2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15', '2026-10-16'],
            'cz.cnb.other' => ['2026-09-01', '2026-10-01'],
        ];
    }

    public function testInvalidDates(): void
    {
        $tester = $this->tester();
        $this->assertSame(2, $tester->execute(['--from' => '2026-13-01']));
        $this->assertStringContainsString('YYYY-MM-DD', $tester->getDisplay());
        $this->assertSame(2, $tester->execute(['--to' => '2026-10-21']));
        $this->assertStringContainsString('nesmí být po dnešku (2026-10-20)', $tester->getDisplay());
        $this->assertSame(2, $tester->execute(['--from' => '2026-10-21']));
        $this->assertStringContainsString('--from nesmí být po --to', $tester->getDisplay());
        $this->assertSame([], $this->transport->urls);
    }

    public function testInactiveModuleIsNotAnError(): void
    {
        $tester = $this->tester(moduleActive: false);
        $this->assertSame(0, $tester->execute([]));
        $this->assertStringContainsString('not active', $tester->getDisplay());
    }

    public function testDisabledDownloadingAndServerConfigError(): void
    {
        $tester = $this->tester(service: ['url' => null, 'error' => null]);
        $this->assertSame(0, $tester->execute([]));
        $this->assertStringContainsString('Stahování je vypnuté', $tester->getDisplay());

        $tester = $this->tester(service: ['url' => null, 'error' => "Server config 'exchangeRates.serviceUrl' must start with http:// or https://, got 'ftp://x'"]);
        $this->assertSame(1, $tester->execute([]));
        $this->assertStringContainsString('exchangeRates.serviceUrl', $tester->getDisplay());
        $this->assertSame([], $this->transport->urls);
    }

    public function testUnknownTypeIsInvalid(): void
    {
        $tester = $this->tester();
        $this->assertSame(2, $tester->execute(['--type' => 'cz.customs']));
        $this->assertStringContainsString('Neznámý typ lístku „cz.customs“ — typy zdroje dat: cz.cnb.daily, cz.cnb.other', $tester->getDisplay());
    }

    public function testDryRunPrintsPlanWithoutNetworkOrWrites(): void
    {
        $this->presentAllButYesterdayAndToday();
        $tester = $this->tester();

        $this->assertSame(0, $tester->execute(['--dry-run' => true]));
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Okno: 2026-09-01 – 2026-10-20 (dnes 2026-10-20 15:00 Europe/Prague)', $display);
        $this->assertStringContainsString('ČNB denní (cz.cnb.daily): očekáváno 35, přítomno 33, chybí 2: 2026-10-20, 2026-10-19', $display);
        $this->assertStringContainsString('ČNB ostatní (cz.cnb.other): očekáváno 2, přítomno 2, nic nechybí', $display);
        $this->assertStringContainsString('Dotazy (nejvýš 6 za běh, pauza 1 h na měsíc):', $display);
        $this->assertStringContainsString('cz.cnb.daily 2026-10-19..2026-10-20 (listType 1)', $display);
        $this->assertStringContainsString('--dry-run', $display);
        $this->assertSame([], $this->transport->urls);
        $this->assertSame(0, $this->settings->writes);
        $this->assertFileDoesNotExist($this->logPath);
    }

    public function testDryRunBeforePublicationExplainsToday(): void
    {
        $this->presentAllButYesterdayAndToday();
        $tester = $this->tester(now: '2026-10-20 12:00:00');

        $this->assertSame(0, $tester->execute(['--dry-run' => true]));
        $display = $tester->getDisplay();
        $this->assertStringContainsString('chybí 1: 2026-10-19', $display);
        $this->assertStringContainsString('dnešní lístek (2026-10-20) se očekává až po vyhlášení', $display);
    }

    public function testRunPrintsResultsLogsWarningsAndExitsOneOnConflict(): void
    {
        $this->presentAllButYesterdayAndToday();
        $this->importer->outcomes['cz.cnb.daily 2026-10-19'] = ExchangeRateListImportResult::CONFLICT;
        $this->importer->skippedCurrencies['cz.cnb.daily 2026-10-20'] = ['XDR'];
        $list = static fn(string $date): array => ['listType' => 'cz.cnb.daily', 'validFrom' => $date];
        $this->transport->queueBody(json_encode(['valid' => 1, 'data' => [$list('2026-10-19'), $list('2026-10-20')]]));
        $tester = $this->tester();

        $this->assertSame(1, $tester->execute([]));
        $display = $tester->getDisplay();
        $this->assertStringContainsString('cz.cnb.daily 2026-10-19..2026-10-20 → 2 lístků: 1 založeno, 0 beze změny, 0 ignorováno, 1 jiný obsah, 0 selhalo', $display);
        $this->assertStringContainsString('cz.cnb.daily 2026-10-20: přeskočené měny XDR', $display);
        $this->assertStringContainsString('varování: cz.cnb.daily 2026-10-19: lístek už existuje s jiným obsahem — nepřepsán', $display);
        $this->assertStringContainsString('Souhrn: 1 dotazů; 1 založeno, 0 beze změny, 0 ignorováno, 1 jiný obsah, 0 selhalo; čeká 0 dotazů', $display);
        $log = (string) file_get_contents($this->logPath);
        $this->assertStringContainsString('"level":"warn"', $log);
        $this->assertStringContainsString('jiným obsahem', $log);
        $this->assertStringContainsString('přeskočené měny XDR', $log);
        $this->assertArrayHasKey('cz.cnb.daily|2026-10', $this->settings->values[DownloadState::KEY]['attempts']);
    }

    public function testNothingMissingIsQuiet(): void
    {
        $this->presentAllButYesterdayAndToday();
        $this->presence->dates['cz.cnb.daily'][] = '2026-10-19';
        $this->presence->dates['cz.cnb.daily'][] = '2026-10-20';
        $tester = $this->tester();

        $this->assertSame(0, $tester->execute([]));
        $this->assertSame("Nic nechybí.\n", $tester->getDisplay());
        $this->assertSame([], $this->transport->urls);
        $this->assertFileDoesNotExist($this->logPath);
    }

    public function testServiceErrorExitsOneAndIsLogged(): void
    {
        $this->presentAllButYesterdayAndToday();
        $this->transport->queueNetworkError('Could not resolve host');
        $tester = $this->tester();

        $this->assertSame(1, $tester->execute([]));
        $display = $tester->getDisplay();
        $this->assertStringContainsString('cz.cnb.daily 2026-10-19..2026-10-20 → chyba: síť: Could not resolve host', $display);
        $this->assertStringContainsString('služba nedostupná', (string) file_get_contents($this->logPath));
        $this->assertNotNull($this->settings->values[DownloadState::KEY]['lastError']);
    }

    public function testManualRangeRunsWithoutStateAndPrintsEmptyResult(): void
    {
        $this->presentAllButYesterdayAndToday();
        $this->transport->queueFixture('none.json');
        $tester = $this->tester();

        $this->assertSame(0, $tester->execute(['--from' => '2026-10-19', '--to' => '2026-10-20']));
        $display = $tester->getDisplay();
        $this->assertStringContainsString('cz.cnb.daily 2026-10-19..2026-10-20 → žádný lístek', $display);
        $this->assertSame(0, $this->settings->writes);
    }
}

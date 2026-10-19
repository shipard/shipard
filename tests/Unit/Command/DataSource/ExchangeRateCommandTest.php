<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\DataSource;

use PHPUnit\Framework\TestCase;
use Shipard\Command\DataSource\ExchangeRateCommand;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Economy\ExchangeRates\ExchangeRate;
use Shipard\Module\Economy\ExchangeRates\ExchangeRateResolver;
use Shipard\Tests\Fixtures\Module\Economy\ExchangeRates\FakeExchangeRateResolver;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/** `shpd-ds exchange-rate <měna> [<datum>]`: validace, nalezený kurz, domácí měna, důvody nenalezení. */
class ExchangeRateCommandTest extends TestCase
{
    private function tester(ExchangeRateResolver $resolver, bool $moduleActive = true): CommandTester
    {
        $dsConfig = $this->createMock(DataSourceConfig::class);
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('getAllTableNames')->willReturn($moduleActive ? ['economy_exchange_rates_lists'] : []);

        $command = new class($dsConfig, $db, $resolver) extends ExchangeRateCommand {
            public function __construct(DataSourceConfig $dsConfig, DataSourceConnection $db, private readonly ExchangeRateResolver $resolver)
            {
                parent::__construct($dsConfig, $db);
            }

            protected function getDataSourceDir(): string
            {
                return '/nonexistent';
            }

            protected function createResolver(string $dsDir, DataSourceConfig $dsConfig, DataSourceConnection $dsConnection): ExchangeRateResolver
            {
                return $this->resolver;
            }
        };
        $app = new Application();
        $app->add($command);
        return new CommandTester($app->find('exchange-rate'));
    }

    public function testInvalidCurrencyOrDate(): void
    {
        $tester = $this->tester(new FakeExchangeRateResolver());
        $this->assertSame(2, $tester->execute(['currency' => 'euro']));
        $this->assertStringContainsString('ISO 4217', $tester->getDisplay());
        $this->assertSame(2, $tester->execute(['currency' => 'eur', 'date' => '16.10.2026']));
        $this->assertStringContainsString('YYYY-MM-DD', $tester->getDisplay());
    }

    public function testInactiveModuleFails(): void
    {
        $tester = $this->tester(new FakeExchangeRateResolver(), moduleActive: false);
        $this->assertSame(1, $tester->execute(['currency' => 'eur']));
    }

    public function testFoundRatePrintsListDetails(): void
    {
        $resolver = new FakeExchangeRateResolver(['eur|2026-10-18' => new ExchangeRate(24.325, 'cz.cnb.daily', '2026-10-16', '201', 2)]);
        $tester = $this->tester($resolver);

        $this->assertSame(0, $tester->execute(['currency' => 'EUR', 'date' => '2026-10-18']));
        $this->assertStringContainsString('1 EUR = 24.325 CZK — ČNB — kurzy devizového trhu, platný od 2026-10-16, č. 201', $tester->getDisplay());
        $this->assertSame([['eur', '2026-10-18']], $resolver->lookups);
    }

    public function testHomeCurrencyIsOne(): void
    {
        $tester = $this->tester(new FakeExchangeRateResolver());
        $this->assertSame(0, $tester->execute(['currency' => 'czk']));
        $this->assertStringContainsString('CZK je domácí měna zdroje dat — kurz 1.', $tester->getDisplay());
    }

    public function testNotFoundListsReasonsPerType(): void
    {
        $tester = $this->tester(new FakeExchangeRateResolver());
        $this->assertSame(1, $tester->execute(['currency' => 'usd', 'date' => '2026-10-18']));
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Kurz USD k 2026-10-18 nenalezen.', $display);
        $this->assertStringContainsString('ČNB — kurzy devizového trhu: žádný lístek platný k datu', $display);
    }
}

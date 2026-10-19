<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Bank\Import;

use Dibi\Connection;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Document\TableGateway;
use Shipard\Module\Economy\Bank\Import\ImportException;
use Shipard\Module\Economy\Bank\Import\ParsedStatement;
use Shipard\Module\Economy\Bank\Import\ParsedTransaction;
use Shipard\Module\Economy\Bank\Import\StatementImportService;
use Shipard\Module\Economy\ExchangeRates\ExchangeRateResolver;
use Shipard\Tests\Fixtures\Module\Economy\ExchangeRates\FakeExchangeRateResolver;

/**
 * Kurz transakce při importu výpisu (#121 D7, docs/bank.md §4.6): domácí
 * měna 1 (kurz z výpisu se ignoruje), cizí měna z výpisu nebo z lístku
 * k datu transakce, chybějící lístek zastaví celý výpis před transakcí.
 */
class StatementImportServiceExchangeRateTest extends TestCase
{
    private const STATES = [
        '10' => ['stateName' => 'Nová', 'stateStyle' => 'concept', 'mainState' => 1, 'viewGroup' => 'active', 'goto' => [40]],
        '40' => ['stateName' => 'Zaúčtováno', 'stateStyle' => 'done', 'mainState' => 3, 'viewGroup' => 'active', 'goto' => [], 'readOnly' => 1],
    ];

    /** @return StatementImportService&object{ratesPub: callable} */
    private function service(?ExchangeRateResolver $resolver, string $accountCurrency, ?Connection $db = null, ?TableGateway $gateway = null): StatementImportService
    {
        $db ??= $this->createMock(Connection::class);
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(static fn(string $id): mixed => $id === 'economy.bank.txStates' ? self::STATES : null);
        $gateway ??= $this->createMock(TableGateway::class);

        return new class($db, $config, $gateway, $resolver, $accountCurrency) extends StatementImportService {
            public function __construct(Connection $db, ConfigRuntime $config, TableGateway $gateway, ?ExchangeRateResolver $resolver, private readonly string $accountCurrency)
            {
                parent::__construct($db, $config, null, $gateway, $resolver);
            }

            protected function loadBankAccount(int $id): array
            {
                return ['id' => $id, 'currency' => $this->accountCurrency, 'code' => 'ACC'];
            }

            /** @return array<int, float> */
            public function ratesPub(ParsedStatement $stmt, string $currency): array
            {
                return $this->resolveExchangeRates($stmt, $currency);
            }
        };
    }

    /** @param list<ParsedTransaction> $transactions */
    private static function statement(array $transactions, ?string $currency = null): ParsedStatement
    {
        return new ParsedStatement('ACC', 'S1', new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31'), 0.0, 0.0, $currency, $transactions);
    }

    private static function tx(string $date, ?float $rate = null, float $amount = 100.0): ParsedTransaction
    {
        return new ParsedTransaction(null, $amount, new \DateTimeImmutable($date), exchangeRate: $rate);
    }

    public function testHomeCurrencyAccountAlwaysGetsOneAndNeverAsks(): void
    {
        $resolver = new FakeExchangeRateResolver(['eur' => 24.325]);
        $rates = $this->service($resolver, 'czk')->ratesPub(self::statement([self::tx('2026-10-16', 25.3), self::tx('2026-10-17')]), 'czk');

        $this->assertSame([1.0, 1.0], $rates, 'kurz z výpisu se na domácím účtu ignoruje');
        $this->assertSame([], $resolver->lookups);
    }

    public function testForeignAccountUsesStatementRateOrList(): void
    {
        $resolver = new FakeExchangeRateResolver(['eur|2026-10-16' => 24.325, 'eur|2026-10-19' => 24.4]);
        $rates = $this->service($resolver, 'eur')->ratesPub(
            self::statement([self::tx('2026-10-16'), self::tx('2026-10-17', 25.3), self::tx('2026-10-19')]),
            'EUR',
        );

        $this->assertSame([24.325, 25.3, 24.4], $rates);
        $this->assertSame([['eur', '2026-10-16'], ['eur', '2026-10-19']], $resolver->lookups, 'kurz z výpisu bez dotazu');
    }

    public function testMissingListsListUniqueSortedDates(): void
    {
        $resolver = new FakeExchangeRateResolver(['eur|2026-10-16' => 24.325]);
        $service = $this->service($resolver, 'eur');

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('Kurz EUR chybí pro data: 2026-10-03, 2026-10-06 — nahraj kurzovní lístky (shpd-ds exchange-rates-import).');
        $service->ratesPub(self::statement([self::tx('2026-10-06'), self::tx('2026-10-16'), self::tx('2026-10-03'), self::tx('2026-10-06')]), 'eur');
    }

    public function testWithoutResolverForeignAccountFails(): void
    {
        $this->expectException(ImportException::class);
        $this->service(null, 'eur')->ratesPub(self::statement([self::tx('2026-10-16')]), 'eur');
    }

    public function testMissingRateStopsStatementBeforeAnyWrite(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('begin');
        $gateway = $this->createMock(TableGateway::class);
        $gateway->expects($this->never())->method('saveDocument');
        $service = $this->service(new FakeExchangeRateResolver([]), 'eur', $db, $gateway);

        try {
            $service->applyParsedStatement(self::statement([self::tx('2026-10-16')], 'EUR'), 7);
            $this->fail('ImportException expected');
        } catch (ImportException $e) {
            $this->assertStringContainsString('Kurz EUR chybí pro data: 2026-10-16', $e->getMessage());
        }
    }
}

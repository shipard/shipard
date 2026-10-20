<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\ExchangeRates\Dataset;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Exchange\Compare\Projection;
use Shipard\Module\Core\Exchange\Dataset\DatasetManifest;
use Shipard\Module\Core\Exchange\Dataset\DatasetReader;
use Shipard\Module\Core\Exchange\Dataset\DatasetWriter;
use Shipard\Module\Core\Exchange\Dataset\Verify\ApplyReport;
use Shipard\Module\Core\Exchange\Dataset\Verify\CheckStatus;
use Shipard\Module\Core\Exchange\Dataset\Verify\SetDocument;
use Shipard\Module\Core\Exchange\Dataset\Verify\VerifyContext;
use Shipard\Module\Core\Exchange\Dataset\Verify\VerifyExpectations;
use Shipard\Module\Core\Exchange\Dataset\Verify\VerifyWiring;
use Shipard\Module\Economy\ExchangeRates\Dataset\ExchangeRateCheck;
use Shipard\Module\Economy\ExchangeRates\ExchangeRate;
use Shipard\Tests\Fixtures\Module\Economy\ExchangeRates\FakeExchangeRateResolver;

/**
 * Kontrola Kurzy (#103 D79): uložené účetní datum a kurz dokladu v cizí
 * měně ≟ expected/exchange-rates.json a kurz resolveru k témuž datu ≟ totéž.
 */
class ExchangeRateCheckTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/shpd_rate_check_' . bin2hex(random_bytes(4));
        DatasetWriter::create($this->tmp)->writeManifest(new DatasetManifest('x', 'X', null, 'fixed', '2026-10-14T00:00:00Z', []));
    }

    protected function tearDown(): void
    {
        DatasetReader::removeTree($this->tmp);
    }

    /**
     * @param array<int, array<string, mixed>>  $heads   id dokladu → řádek docs_core_heads
     * @param list<array<string, mixed>>|null   $records expected/exchange-rates.json
     * @param list<SetDocument>                 $docs
     */
    private function context(array $heads, ?array $records, array $docs): VerifyContext
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturnCallback(static fn (mixed ...$args): ?Row => isset($heads[$args[1] ?? 0]) ? new Row($heads[$args[1]]) : null);
        $dsConfig = $this->createMock(DataSourceConfig::class);
        $dsConfig->method('getCountry')->willReturn('cz');
        $dsConfig->method('getDefaultLanguage')->willReturn('cs');
        $wiring = new VerifyWiring($db, $this->createMock(DataSourceConnection::class), $dsConfig, '/nonexistent');
        $projection = Projection::load(dirname(__DIR__, 6) . '/tests/corpus/projection.jsonc');
        $expectations = new VerifyExpectations($projection, [], [], null, $records);
        return new VerifyContext($wiring, DatasetReader::open($this->tmp), $expectations, $docs, ApplyReport::notRun(), 'CZK');
    }

    private static function doc(string $id, string $currency, int $docId): SetDocument
    {
        return new SetDocument($id, crc32($id), '2026-09-02 08:00:00', 5, 40, ['currency' => $currency], $docId, 40, "FP-{$docId}");
    }

    /** @return array<string, mixed> */
    private static function record(string $id, string $date = '2026-08-31', float $rate = 24.325, string $validFrom = '2026-08-31'): array
    {
        return ['messageId' => $id, 'currency' => 'EUR', 'rateDate' => $date, 'listType' => 'cz.cnb.daily', 'validFrom' => $validFrom, 'rate' => $rate];
    }

    private static function resolver(): FakeExchangeRateResolver
    {
        return new FakeExchangeRateResolver([
            'eur|2026-08-31' => new ExchangeRate(24.325, 'cz.cnb.daily', '2026-08-31', '168', 3),
            'eur|2026-09-02' => new ExchangeRate(24.4, 'cz.cnb.daily', '2026-09-02', '170', 5),
        ]);
    }

    public function testSkippedWithoutExpectationsFile(): void
    {
        $result = (new ExchangeRateCheck(self::resolver()))->run($this->context([], null, [self::doc('A', 'EUR', 7)]));
        $this->assertSame(CheckStatus::Skipped, $result->status);
        $this->assertStringContainsString('exchange-rates.json', (string) $result->reason);
    }

    public function testSkippedWithoutForeignCurrencyDocuments(): void
    {
        $result = (new ExchangeRateCheck(self::resolver()))->run($this->context([], [], [self::doc('A', 'CZK', 7)]));
        $this->assertSame(CheckStatus::Skipped, $result->status);
        $this->assertSame('žádný použitý doklad v cizí měně', $result->reason);
    }

    public function testOkWhenDocumentExpectationAndResolverAgree(): void
    {
        $resolver = self::resolver();
        $heads = [7 => ['accounting_date' => new \DateTimeImmutable('2026-08-31'), 'exchange_rate' => '24.325000']];
        $ctx = $this->context($heads, [self::record('A')], [self::doc('A', 'EUR', 7), self::doc('B', 'CZK', 8)]);

        $result = (new ExchangeRateCheck($resolver))->run($ctx);

        $this->assertSame(CheckStatus::Ok, $result->status, implode("\n", $result->differences));
        $this->assertSame(['1 dokladů porovnáno'], $result->notes);
        $this->assertSame([['eur', '2026-08-31']], $resolver->lookups, 'doklad v domácí měně se neptá');
    }

    public function testReportsDateRateResolverAndMissingRecordDifferences(): void
    {
        $heads = [
            7 => ['accounting_date' => '2026-09-02', 'exchange_rate' => 24.4],            // jiné datum i kurz než očekávání
            9 => ['accounting_date' => '2026-08-31', 'exchange_rate' => '24.300000'],     // doklad = očekávání, resolver dává jiný kurz
            11 => ['accounting_date' => '2026-09-05', 'exchange_rate' => 24.4],           // resolver k datu nic nemá
            13 => ['accounting_date' => '2026-08-31', 'exchange_rate' => null],           // bez kurzu
        ];
        $records = [self::record('A'), self::record('C', rate: 24.3), self::record('E', date: '2026-09-05', rate: 24.4, validFrom: '2026-09-05'), self::record('F'), self::record('G')];
        $docs = [self::doc('A', 'EUR', 7), self::doc('C', 'EUR', 9), self::doc('D', 'EUR', 10), self::doc('E', 'EUR', 11), self::doc('F', 'EUR', 13), self::doc('G', 'EUR', 15)];

        $result = (new ExchangeRateCheck(self::resolver()))->run($this->context($heads, $records, $docs));

        $this->assertSame(CheckStatus::Diff, $result->status);
        $this->assertSame([
            'A (FP-7): účetní datum očekáváno 2026-08-31, doklad 2026-09-02',
            'A (FP-7): kurz očekáváno 24.325, doklad 24.4',
            'C (FP-9): resolver dává kurz 24.325 z lístku cz.cnb.daily od 2026-08-31, očekáváno 24.3 z lístku cz.cnb.daily od 2026-08-31',
            'D (FP-10): bez záznamu v exchange-rates.json',
            'E (FP-11): resolver kurz EUR k 2026-09-05 nenašel (cz.cnb.daily: no_list)',
            'F (FP-13): doklad nemá kurz',
            'G (FP-15): doklad #15 nenalezen',
        ], $result->differences);
        $this->assertSame(['4 dokladů porovnáno'], $result->notes);
    }
}

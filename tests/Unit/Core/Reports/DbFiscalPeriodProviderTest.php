<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Reports;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Reports\DbFiscalPeriodProvider;

/**
 * Tvar dotazů `yearForDate` / `years` nad mockem DB (nesmazané roky,
 * rozsah dat, řazení). Chování nad reálnými roky (hranice, mimo roky)
 * kryje integrační DbFiscalPeriodProviderTest.
 */
class DbFiscalPeriodProviderTest extends TestCase
{
    /** @var list<array{sql: string, params: array}> */
    private array $queries = [];

    private function makeProvider(?array $fetchRowResult = null, array $fetchAllRows = []): DbFiscalPeriodProvider
    {
        $this->queries = [];
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(
            function (string $sql, ...$params) use ($fetchRowResult): ?array {
                $this->queries[] = ['sql' => $sql, 'params' => $params];
                return $fetchRowResult;
            },
        );
        $db->method('fetchAll')->willReturnCallback(
            function (string $sql, ...$params) use ($fetchAllRows): array {
                $this->queries[] = ['sql' => $sql, 'params' => $params];
                return $fetchAllRows;
            },
        );
        return new DbFiscalPeriodProvider($db);
    }

    public function testYearForDateQueriesRangeOfLiveYears(): void
    {
        $provider = $this->makeProvider(['id' => '1', 'name' => '2026']);

        $this->assertSame(['id' => 1, 'name' => '2026'], $provider->yearForDate('2026-09-14'));

        $q = $this->queries[0];
        $this->assertStringContainsString('[economy_codebooks_fiscal_years]', $q['sql']);
        $this->assertStringContainsString('[docState] != %i', $q['sql']);
        $this->assertStringContainsString('[date_begin] <= %s AND [date_end] >= %s', $q['sql']);
        $this->assertStringContainsString('ORDER BY [date_begin] DESC LIMIT 1', $q['sql']);
        $this->assertSame([90, '2026-09-14', '2026-09-14'], $q['params']);
    }

    public function testYearForDateReturnsNullWithoutMatch(): void
    {
        $this->assertNull($this->makeProvider(null)->yearForDate('2019-01-01'));
    }

    public function testYearsAreLiveAndNewestFirst(): void
    {
        $provider = $this->makeProvider(null, [['id' => '2', 'name' => '2027'], ['id' => '1', 'name' => '2026']]);

        $this->assertSame([['id' => 2, 'name' => '2027'], ['id' => 1, 'name' => '2026']], $provider->years());

        $q = $this->queries[0];
        $this->assertStringContainsString('[docState] != %i', $q['sql']);
        $this->assertStringContainsString('ORDER BY [date_begin] DESC', $q['sql']);
        $this->assertSame([90], $q['params']);
    }
}

<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Codebooks;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Economy\Codebooks\FiscalYearsProvisioner;

class FiscalYearsProvisionerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/shpd_test_' . uniqid();
        mkdir($this->tmpDir . '/config/configuration', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = "$path/$entry";
            is_dir($full) ? $this->removeDir($full) : unlink($full);
        }
        rmdir($path);
    }

    private function buildConfig(int $yearStartMonth): ConfigRuntime
    {
        $items = [
            'economy.codebooks.fiscalConfig' => ['yearStartMonth' => $yearStartMonth],
        ];
        $data = ['_meta' => ['language' => 'cs'], 'items' => $items];
        file_put_contents(
            $this->tmpDir . '/config/configuration/compiled.cs.json',
            json_encode($data),
        );
        return ConfigRuntime::load($this->tmpDir, 'cs');
    }

    /**
     * Build a recording mock that captures inserts into in-memory tables.
     *
     * Returns a wrapper object whose `db` field is the mock and `tables` field
     * holds the in-memory rows. Using an object avoids the loss-of-reference
     * trap that array destructuring has with `&$tables`.
     *
     * @param list<array{date_begin: string, date_end: string}> $existingYears
     */
    private function recordingDb(array $existingYears = []): object
    {
        $store = new \stdClass();
        $store->tables = [
            'economy_codebooks_fiscal_years'  => $existingYears,
            'economy_codebooks_fiscal_months' => [],
        ];
        $store->autoIncrement = count($existingYears);

        $db = $this->createMock(DataSourceConnection::class);

        $db->method('fetchRow')->willReturnCallback(
            function (string $sql, mixed ...$params) use ($store): ?array {
                if (str_contains($sql, 'economy_codebooks_fiscal_years')
                    && str_contains($sql, 'date_begin = ')
                ) {
                    $needle = (string) ($params[0] ?? '');
                    foreach ($store->tables['economy_codebooks_fiscal_years'] as $row) {
                        if (($row['date_begin'] ?? '') === $needle) {
                            return $row;
                        }
                    }
                    return null;
                }
                return null;
            }
        );

        $db->method('insertRow')->willReturnCallback(
            function (string $table, array $data) use ($store): int {
                $store->autoIncrement++;
                $row = $data;
                $row['id'] = $store->autoIncrement;
                $store->tables[$table][] = $row;
                return $store->autoIncrement;
            }
        );

        $store->db = $db;
        return $store;
    }

    public function testEmptyDsYearStartJanuaryGeneratesCurrentYear(): void
    {
        $store = $this->recordingDb();
        $config = $this->buildConfig(1);

        $provisioner = new FiscalYearsProvisioner($store->db, $config);
        $result = $provisioner->provision(new \DateTimeImmutable('2026-04-15'));

        $this->assertSame(1, $result['fiscalYears']['created']);
        $this->assertSame(0, $result['fiscalYears']['existing']);
        $this->assertCount(1, $store->tables['economy_codebooks_fiscal_years']);

        $year = $store->tables['economy_codebooks_fiscal_years'][0];
        $this->assertSame('2026', $year['name']);
        $this->assertSame('26', $year['doc_number_prefix']);
        $this->assertSame('2026-01-01', $year['date_begin']);
        $this->assertSame('2026-12-31', $year['date_end']);
        $this->assertSame('czk', $year['currency']);
        $this->assertSame(40, $year['docState']);
        $this->assertSame(3, $year['docStateMain']);

        $this->assertCount(14, $store->tables['economy_codebooks_fiscal_months']);
    }

    public function testYearStartJulyMidYearGeneratesCrossYearRange(): void
    {
        $store = $this->recordingDb();
        $config = $this->buildConfig(7);

        $provisioner = new FiscalYearsProvisioner($store->db, $config);
        $result = $provisioner->provision(new \DateTimeImmutable('2026-09-15'));

        $this->assertSame(1, $result['fiscalYears']['created']);
        $year = $store->tables['economy_codebooks_fiscal_years'][0];

        $this->assertSame('2026-2027', $year['name']);
        $this->assertSame('27', $year['doc_number_prefix']);
        $this->assertSame('2026-07-01', $year['date_begin']);
        $this->assertSame('2027-06-30', $year['date_end']);
    }

    public function testYearStartJulyBeforeStartGeneratesPreviousFiscalYear(): void
    {
        $store = $this->recordingDb();
        $config = $this->buildConfig(7);

        $provisioner = new FiscalYearsProvisioner($store->db, $config);
        $result = $provisioner->provision(new \DateTimeImmutable('2026-03-15'));

        $this->assertSame(1, $result['fiscalYears']['created']);
        $year = $store->tables['economy_codebooks_fiscal_years'][0];

        $this->assertSame('2025-2026', $year['name']);
        $this->assertSame('26', $year['doc_number_prefix']);
        $this->assertSame('2025-07-01', $year['date_begin']);
        $this->assertSame('2026-06-30', $year['date_end']);
    }

    public function testCurrentYearExistsGeneratesNextYear(): void
    {
        $store = $this->recordingDb([
            ['id' => 1, 'date_begin' => '2026-01-01', 'date_end' => '2026-12-31'],
        ]);
        $config = $this->buildConfig(1);

        $provisioner = new FiscalYearsProvisioner($store->db, $config);
        $result = $provisioner->provision(new \DateTimeImmutable('2026-04-15'));

        $this->assertSame(1, $result['fiscalYears']['created']);
        $this->assertSame(1, $result['fiscalYears']['existing']);
        $this->assertCount(2, $store->tables['economy_codebooks_fiscal_years']);

        $next = $store->tables['economy_codebooks_fiscal_years'][1];
        $this->assertSame('2027', $next['name']);
        $this->assertSame('2027-01-01', $next['date_begin']);
    }

    public function testBothYearsExistIsNoOp(): void
    {
        $store = $this->recordingDb([
            ['id' => 1, 'date_begin' => '2026-01-01', 'date_end' => '2026-12-31'],
            ['id' => 2, 'date_begin' => '2027-01-01', 'date_end' => '2027-12-31'],
        ]);
        $config = $this->buildConfig(1);

        $provisioner = new FiscalYearsProvisioner($store->db, $config);
        $result = $provisioner->provision(new \DateTimeImmutable('2026-04-15'));

        $this->assertSame(0, $result['fiscalYears']['created']);
        $this->assertSame(2, $result['fiscalYears']['existing']);
        $this->assertCount(2, $store->tables['economy_codebooks_fiscal_years']);
        $this->assertCount(0, $store->tables['economy_codebooks_fiscal_months']);
    }

    public function testExplicitCurrencySetsSeededYearCurrency(): void
    {
        $store = $this->recordingDb();
        $config = $this->buildConfig(1);

        $provisioner = new FiscalYearsProvisioner($store->db, $config, 1, 'eur');
        $provisioner->provision(new \DateTimeImmutable('2026-04-15'));

        $year = $store->tables['economy_codebooks_fiscal_years'][0];
        $this->assertSame('eur', $year['currency']);
    }

    public function testExplicitYearStartMonthWinsOverCfgItem(): void
    {
        $store = $this->recordingDb();
        $config = $this->buildConfig(1); // cfgItem říká leden

        $provisioner = new FiscalYearsProvisioner($store->db, $config, 7);
        $provisioner->provision(new \DateTimeImmutable('2026-04-15'));

        $year = $store->tables['economy_codebooks_fiscal_years'][0];
        $this->assertSame('2025-07-01', $year['date_begin']);
        $this->assertSame('2025-2026', $year['name']);
    }

    public function testNullYearStartMonthFallsBackToCfgItem(): void
    {
        $store = $this->recordingDb();
        $config = $this->buildConfig(7);

        $provisioner = new FiscalYearsProvisioner($store->db, $config, null);
        $provisioner->provision(new \DateTimeImmutable('2026-04-15'));

        $year = $store->tables['economy_codebooks_fiscal_years'][0];
        $this->assertSame('2025-07-01', $year['date_begin']);
    }

    public function testExplicitYearStartMonthOutOfRangeClampsToJanuary(): void
    {
        $store = $this->recordingDb();
        $config = $this->buildConfig(7);

        $provisioner = new FiscalYearsProvisioner($store->db, $config, 0);
        $provisioner->provision(new \DateTimeImmutable('2026-04-15'));

        $year = $store->tables['economy_codebooks_fiscal_years'][0];
        $this->assertSame('2026-01-01', $year['date_begin']);
    }

    public function testGeneratedMonthsHaveCorrectShape(): void
    {
        $store = $this->recordingDb();
        $config = $this->buildConfig(1);

        $provisioner = new FiscalYearsProvisioner($store->db, $config);
        $provisioner->provision(new \DateTimeImmutable('2026-04-15'));

        $months = $store->tables['economy_codebooks_fiscal_months'];
        $this->assertCount(14, $months);

        $byType = ['0' => [], '1' => [], '2' => []];
        foreach ($months as $m) {
            $byType[(string) $m['period_type']][] = $m;
        }

        $this->assertCount(1, $byType['0'], 'exactly one Opening month');
        $this->assertCount(12, $byType['1'], 'twelve Regular months');
        $this->assertCount(1, $byType['2'], 'exactly one Closing month');

        // Opening: single-day, equals year.date_begin
        $opening = $byType['0'][0];
        $this->assertSame('2026-01-01', $opening['date_begin']);
        $this->assertSame('2026-01-01', $opening['date_end']);
        $this->assertSame(2026, $opening['calendar_year']);
        $this->assertSame(1, $opening['calendar_month']);

        // Closing: single-day, equals year.date_end
        $closing = $byType['2'][0];
        $this->assertSame('2026-12-31', $closing['date_begin']);
        $this->assertSame('2026-12-31', $closing['date_end']);
        $this->assertSame(2026, $closing['calendar_year']);
        $this->assertSame(12, $closing['calendar_month']);

        // Regular months: spans full month, calendar fields denormalized
        $regular = $byType['1'];
        $this->assertSame('2026-01-01', $regular[0]['date_begin']);
        $this->assertSame('2026-01-31', $regular[0]['date_end']);
        $this->assertSame(1, $regular[0]['calendar_month']);

        $this->assertSame('2026-02-01', $regular[1]['date_begin']);
        $this->assertSame('2026-02-28', $regular[1]['date_end']);
        $this->assertSame(2, $regular[1]['calendar_month']);

        $this->assertSame('2026-12-01', $regular[11]['date_begin']);
        $this->assertSame('2026-12-31', $regular[11]['date_end']);
        $this->assertSame(12, $regular[11]['calendar_month']);
    }

    // ── ensureYearForDate (#103 D23) ───────────────────────────────────────

    public function testEnsureYearForDateCreatesMissingYearWithMonths(): void
    {
        $store = $this->recordingDb();
        $provisioner = new FiscalYearsProvisioner($store->db, $this->buildConfig(1), 1, 'eur');

        $result = $provisioner->ensureYearForDate(new \DateTimeImmutable('2024-08-25'));

        $this->assertSame(['created' => true, 'name' => '2024', 'dateBegin' => '2024-01-01'], $result);
        $this->assertCount(1, $store->tables['economy_codebooks_fiscal_years']);
        $this->assertSame('eur', $store->tables['economy_codebooks_fiscal_years'][0]['currency']);
        $this->assertCount(14, $store->tables['economy_codebooks_fiscal_months']);
    }

    public function testEnsureYearForDateIsNoOpWhenYearExistsAndNeverCreatesNextYear(): void
    {
        $store = $this->recordingDb([
            ['date_begin' => '2026-01-01', 'date_end' => '2026-12-31', 'name' => '2026'],
        ]);
        $provisioner = new FiscalYearsProvisioner($store->db, $this->buildConfig(1), 1, 'czk');

        $result = $provisioner->ensureYearForDate(new \DateTimeImmutable('2026-10-14'));

        $this->assertSame(['created' => false, 'name' => '2026', 'dateBegin' => '2026-01-01'], $result);
        $this->assertCount(1, $store->tables['economy_codebooks_fiscal_years'], 'provision() would add 2027 here');
        $this->assertCount(0, $store->tables['economy_codebooks_fiscal_months']);
    }

    public function testEnsureYearForDateHonoursYearStartMonth(): void
    {
        $store = $this->recordingDb();
        $provisioner = new FiscalYearsProvisioner($store->db, $this->buildConfig(1), 7, 'czk');

        $result = $provisioner->ensureYearForDate(new \DateTimeImmutable('2026-03-15'));

        $this->assertSame('2025-2026', $result['name']);
        $this->assertSame('2025-07-01', $result['dateBegin']);
        $this->assertSame('2026-06-30', $store->tables['economy_codebooks_fiscal_years'][0]['date_end']);
    }
}

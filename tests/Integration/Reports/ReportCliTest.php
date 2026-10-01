<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Reports;

use Shipard\Command\DataSource\ReportDiffCommand;
use Shipard\Command\DataSource\ReportRunCommand;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Tests\Integration\IntegrationTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class TestableReportRunCommand extends ReportRunCommand
{
    public function __construct(
        DataSourceConfig $dsConfig,
        DataSourceConnection $dsConnection,
        private readonly string $modulesPath,
    ) {
        parent::__construct($dsConfig, $dsConnection);
    }

    protected function getModulePathResolver(): ModulePathResolver
    {
        return new ModulePathResolver([$this->modulesPath]);
    }
}

/**
 * CLI `report-run` + `report-diff` nad reálným dev DS (report-run) a temp
 * soubory (report-diff). Commandy se spouštějí přes CommandTester
 * s `capture_stderr_separately` — stdout musí zůstat čistý JSON.
 */
class ReportCliTest extends IntegrationTestCase
{
    private string $yearName;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $year = $this->db->fetchRow(
            'SELECT [y].[name] FROM [economy_codebooks_fiscal_years] [y]'
            . ' JOIN [economy_codebooks_fiscal_months] [m] ON [m].[fiscal_year] = [y].[id]'
            . ' WHERE [y].[docState] != 90 AND [m].[period_type] = 1'
            . ' GROUP BY [y].[id], [y].[name] ORDER BY [y].[name] LIMIT 1',
        );
        if ($year === null) {
            $this->markTestSkipped('Integration DS has no fiscal year with regular months.');
        }
        $this->yearName = (string) $year['name'];
    }

    protected function onTearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
    }

    private function runCommandTester(): CommandTester
    {
        return new CommandTester(new TestableReportRunCommand(
            $this->dsConfig,
            $this->db,
            dirname(__DIR__, 3) . '/modules',
        ));
    }

    /** @param array<string, mixed> $data */
    private function tempJson(array $data): string
    {
        $file = tempnam(sys_get_temp_dir(), 'shpd_reportdiff_');
        file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE));
        $this->tempFiles[] = $file;
        return $file;
    }

    // ── report-run ──────────────────────────────────────────────────────────

    public function testReportRunPrintsParsableJson(): void
    {
        $tester = $this->runCommandTester();
        $exit = $tester->execute([
            'reportId'      => 'economy.accounting.generalLedger',
            '--fiscal-year' => $this->yearName,
            '--month-from'  => '1',
            '--month-to'    => '1',
        ], ['capture_stderr_separately' => true]);

        $this->assertSame(Command::SUCCESS, $exit);
        $result = json_decode($tester->getDisplay(), true);
        $this->assertIsArray($result, 'stdout must be clean parsable JSON');
        $this->assertSame('economy.accounting.generalLedger', $result['reportId']);
        $this->assertContains($result['status'], ['ok', 'warnings', 'errors']);
        $this->assertArrayHasKey('columns', $result);
        $this->assertArrayHasKey('rows', $result);
        // CLI default detailu je analytic (plný detail pro diff).
        $this->assertSame('analytic', $result['params']['detail']);
    }

    public function testReportRunCsvGoesToStdout(): void
    {
        $tester = $this->runCommandTester();
        $exit = $tester->execute([
            'reportId'      => 'economy.accounting.generalLedger',
            '--fiscal-year' => $this->yearName,
            '--month-from'  => '1',
            '--month-to'    => '1',
            '--format'      => 'csv',
        ], ['capture_stderr_separately' => true]);

        $this->assertSame(Command::SUCCESS, $exit);
        $csv = $tester->getDisplay();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        // Hlavička tabulky: sloupce oddělené středníkem (sides → 3 sloupce).
        $header = explode("\r\n", substr($csv, 3))[0];
        $this->assertGreaterThanOrEqual(6, count(str_getcsv($header, ';', '"', '')));
    }

    public function testReportRunXlsxWritesOutputFile(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'shpd_reportrun_');
        $this->tempFiles[] = $file;

        $tester = $this->runCommandTester();
        $exit = $tester->execute([
            'reportId'      => 'economy.accounting.generalLedger',
            '--fiscal-year' => $this->yearName,
            '--month-from'  => '1',
            '--month-to'    => '1',
            '--format'      => 'xlsx',
            '--output'      => $file,
        ], ['capture_stderr_separately' => true]);

        $this->assertSame(Command::SUCCESS, $exit);
        // Soubor je zip (XLSX), stdout zůstává prázdný.
        $this->assertStringStartsWith('PK', (string) file_get_contents($file));
        $this->assertSame('', $tester->getDisplay());
    }

    public function testReportRunXlsxRequiresOutputAndKnownFormat(): void
    {
        $base = [
            'reportId'      => 'economy.accounting.generalLedger',
            '--fiscal-year' => $this->yearName,
            '--month-from'  => '1',
            '--month-to'    => '1',
        ];

        $tester = $this->runCommandTester();
        $exit = $tester->execute($base + ['--format' => 'xlsx'], ['capture_stderr_separately' => true]);
        $this->assertSame(Command::INVALID, $exit);
        $this->assertStringContainsString('--output', $tester->getErrorOutput());

        $tester = $this->runCommandTester();
        $exit = $tester->execute($base + ['--format' => 'pdf'], ['capture_stderr_separately' => true]);
        $this->assertSame(Command::INVALID, $exit);
        $this->assertStringContainsString('--format', $tester->getErrorOutput());
    }

    public function testReportRunUnknownReportFails(): void
    {
        $tester = $this->runCommandTester();
        $exit = $tester->execute([
            'reportId'      => 'economy.accounting.nonsense',
            '--fiscal-year' => $this->yearName,
            '--month-from'  => '1',
            '--month-to'    => '1',
        ], ['capture_stderr_separately' => true]);

        $this->assertSame(Command::INVALID, $exit);
        $this->assertStringContainsString('economy.accounting.nonsense', $tester->getErrorOutput());
    }

    public function testReportRunMissingOptionFails(): void
    {
        $tester = $this->runCommandTester();
        $exit = $tester->execute([
            'reportId'      => 'economy.accounting.generalLedger',
            '--fiscal-year' => $this->yearName,
        ], ['capture_stderr_separately' => true]);

        $this->assertSame(Command::INVALID, $exit);
        $this->assertStringContainsString('--month-from', $tester->getErrorOutput());
    }

    public function testReportRunParamOptionSetsDeclaredParameter(): void
    {
        $tester = $this->runCommandTester();
        $exit = $tester->execute([
            'reportId'      => 'economy.accounting.generalLedger',
            '--fiscal-year' => $this->yearName,
            '--month-from'  => '1',
            '--month-to'    => '1',
            '--param'       => ['detail=synthetic'],
        ], ['capture_stderr_separately' => true]);

        $this->assertSame(Command::SUCCESS, $exit);
        $result = json_decode($tester->getDisplay(), true);
        // `--param detail=…` má přednost před výchozím `--detail`.
        $this->assertSame('synthetic', $result['params']['detail']);
    }

    public function testReportRunParamOptionRejectsUnknownAndMalformed(): void
    {
        $base = [
            'reportId'      => 'economy.accounting.generalLedger',
            '--fiscal-year' => $this->yearName,
            '--month-from'  => '1',
            '--month-to'    => '1',
        ];

        $tester = $this->runCommandTester();
        $exit = $tester->execute($base + ['--param' => ['groupBy=type']], ['capture_stderr_separately' => true]);
        $this->assertSame(Command::INVALID, $exit);
        $this->assertStringContainsString("Unknown parameter 'groupBy'", $tester->getErrorOutput());

        $tester = $this->runCommandTester();
        $exit = $tester->execute($base + ['--param' => ['detail']], ['capture_stderr_separately' => true]);
        $this->assertSame(Command::INVALID, $exit);
        $this->assertStringContainsString('id=value', $tester->getErrorOutput());
    }

    // ── report-diff ─────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function sampleResult(float $md311, string $status = 'ok'): array
    {
        return [
            'reportId' => 'economy.accounting.generalLedger',
            'status'   => $status,
            'messages' => [],
            'columns'  => [['id' => 'closing', 'type' => 'money', 'label' => 'Closing', 'display' => 'sides']],
            'rows'     => [
                ['kind' => 'detail', 'level' => 4, 'account' => '311001', 'label' => 'Odběratelé',
                    'values' => ['closing' => ['md' => $md311, 'd' => 0.0, 'balance' => $md311]]],
            ],
        ];
    }

    public function testReportDiffIdenticalExitsZero(): void
    {
        $file = $this->tempJson($this->sampleResult(1000.0));
        $tester = new CommandTester(new ReportDiffCommand());
        $exit = $tester->execute(['fileA' => $file, 'fileB' => $file], ['capture_stderr_separately' => true]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('Identical', $tester->getDisplay());
    }

    public function testReportDiffDifferenceExitsOneAndPrintsDelta(): void
    {
        $fileA = $this->tempJson($this->sampleResult(1000.0));
        $fileB = $this->tempJson($this->sampleResult(1250.5));
        $tester = new CommandTester(new ReportDiffCommand());
        $exit = $tester->execute(['fileA' => $fileA, 'fileB' => $fileB], ['capture_stderr_separately' => true]);

        $this->assertSame(Command::FAILURE, $exit);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('311001', $display);
        $this->assertStringContainsString('closing', $display);
        $this->assertStringContainsString('250,50', $display);
    }

    public function testReportDiffJsonOutputIsMachineReadable(): void
    {
        $fileA = $this->tempJson($this->sampleResult(1000.0));
        $fileB = $this->tempJson($this->sampleResult(1250.5));
        $tester = new CommandTester(new ReportDiffCommand());
        $exit = $tester->execute(
            ['fileA' => $fileA, 'fileB' => $fileB, '--json' => true],
            ['capture_stderr_separately' => true],
        );

        $this->assertSame(Command::FAILURE, $exit);
        $diff = json_decode($tester->getDisplay(), true);
        $this->assertIsArray($diff);
        $this->assertFalse($diff['identical']);
        $this->assertCount(2, $diff['differences']); // md + balance
    }

    public function testReportDiffStrictRefusesErrorsStatus(): void
    {
        $fileA = $this->tempJson($this->sampleResult(1000.0, 'errors'));
        $fileB = $this->tempJson($this->sampleResult(1000.0));
        $tester = new CommandTester(new ReportDiffCommand());

        // Bez --strict: statusy se propagují, porovnání proběhne.
        $exit = $tester->execute(['fileA' => $fileA, 'fileB' => $fileB], ['capture_stderr_separately' => true]);
        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('errors', $tester->getDisplay());

        $exit = $tester->execute(
            ['fileA' => $fileA, 'fileB' => $fileB, '--strict' => true],
            ['capture_stderr_separately' => true],
        );
        $this->assertSame(Command::INVALID, $exit);
    }

    public function testReportDiffInvalidJsonExitsTwo(): void
    {
        $broken = tempnam(sys_get_temp_dir(), 'shpd_reportdiff_');
        file_put_contents($broken, '{not json');
        $this->tempFiles[] = $broken;
        $fileB = $this->tempJson($this->sampleResult(1000.0));

        $tester = new CommandTester(new ReportDiffCommand());
        $exit = $tester->execute(['fileA' => $broken, 'fileB' => $fileB], ['capture_stderr_separately' => true]);

        $this->assertSame(Command::INVALID, $exit);
        $this->assertStringContainsString('not a valid JSON', $tester->getErrorOutput());
    }
}

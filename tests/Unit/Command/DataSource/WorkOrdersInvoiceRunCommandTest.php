<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\DataSource;

use PHPUnit\Framework\TestCase;
use Shipard\Command\DataSource\WorkOrdersInvoiceRunCommand;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Economy\WorkOrders\Invoicing\InvoicingRunService;
use Shipard\Module\Economy\WorkOrders\Invoicing\RunLine;
use Shipard\Module\Economy\WorkOrders\Invoicing\RunOptions;
use Shipard\Module\Economy\WorkOrders\Invoicing\RunReport;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `shpd-ds work-orders-invoice-run`: validace voleb, guard modulu,
 * předání voleb službě (datum, zakázka = force, dry-run), výstup a exit.
 */
class WorkOrdersInvoiceRunCommandTest extends TestCase
{
    /** @var list<RunOptions> */
    private array $runs = [];

    private function tester(bool $moduleActive = true, ?int $workOrderId = 6, array $lines = []): CommandTester
    {
        $dsConfig = $this->createMock(DataSourceConfig::class);
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('getAllTableNames')->willReturn($moduleActive ? ['economy_work_orders_periods'] : ['docs_core_heads']);
        $db->method('fetchRow')->willReturn($workOrderId !== null ? ['id' => $workOrderId] : null);

        $runs = &$this->runs;
        $service = new class($lines, $runs) extends InvoicingRunService {
            public function __construct(private readonly array $lines, private array &$runs)
            {
            }

            public function run(RunOptions $options): RunReport
            {
                $this->runs[] = $options;
                $report = new RunReport($options);
                foreach ($this->lines as $line) {
                    $report->add($line);
                }
                return $report;
            }
        };

        $command = new class($dsConfig, $db, $service) extends WorkOrdersInvoiceRunCommand {
            public function __construct(DataSourceConfig $dsConfig, DataSourceConnection $db, private readonly InvoicingRunService $service)
            {
                parent::__construct($dsConfig, $db);
            }

            protected function getDataSourceDir(): string
            {
                return '/nonexistent';
            }

            protected function createService(string $dsDir, DataSourceConfig $dsConfig, DataSourceConnection $dsConnection): InvoicingRunService
            {
                return $this->service;
            }
        };
        $app = new Application();
        $app->add($command);
        return new CommandTester($app->find('work-orders-invoice-run'));
    }

    public function testInvalidDateAndUnknownWorkOrderAreInvalid(): void
    {
        $tester = $this->tester();
        $this->assertSame(2, $tester->execute(['--date' => '2026-13-01']));
        $this->assertStringContainsString('--date must be YYYY-MM-DD', $tester->getDisplay());

        $tester = $this->tester(workOrderId: null);
        $this->assertSame(2, $tester->execute(['--work-order' => 'S999']));
        $this->assertStringContainsString("Zakázka 'S999' neexistuje", $tester->getDisplay());
        $this->assertSame([], $this->runs);
    }

    public function testInactiveModuleExitsZeroWithoutRunning(): void
    {
        $tester = $this->tester(moduleActive: false);
        $this->assertSame(0, $tester->execute([]));
        $this->assertStringContainsString('not active', $tester->getDisplay());
        $this->assertSame([], $this->runs);
    }

    public function testOptionsReachTheServiceAndReportIsPrinted(): void
    {
        $lines = [
            new RunLine(6, 'S260001', 'Nájem kanceláře', '2026-08-01', '2026-08-31', RunReport::OUTCOME_ISSUED, 1001, 'Nájem srpen 2026', 1),
            new RunLine(6, 'S260001', 'Nájem kanceláře', '2026-09-01', '2026-09-30', RunReport::OUTCOME_FAILED, null, 'number_series_not_found', 2),
        ];
        $tester = $this->tester(lines: $lines);
        $exit = $tester->execute(['--date' => '2026-10-08', '--work-order' => 'S260001']);

        $this->assertSame(1, $exit, 'selhané období = exit 1');
        $this->assertCount(1, $this->runs);
        $options = $this->runs[0];
        $this->assertSame('2026-10-08', $options->date);
        $this->assertSame(6, $options->workOrderId);
        $this->assertTrue($options->force);
        $this->assertFalse($options->dryRun);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('S260001 — Nájem kanceláře', $display);
        $this->assertStringContainsString('2026-08-01 – 2026-08-31', $display);
        $this->assertStringContainsString('doklad #1001', $display);
        $this->assertStringContainsString('number_series_not_found', $display);
        $this->assertStringContainsString('1 vystaveno, 0 čeká na podklady, 1 selhalo', $display);
    }

    public function testDryRunIsPassedThroughAndAnnounced(): void
    {
        $tester = $this->tester(lines: [
            new RunLine(6, 'S260001', 'Nájem', '2026-10-01', '2026-10-31', RunReport::OUTCOME_PLANNED, null, 'Nájem říjen 2026'),
        ]);
        $this->assertSame(0, $tester->execute(['--dry-run' => true]));
        $this->assertTrue($this->runs[0]->dryRun);
        $this->assertFalse($this->runs[0]->force);
        $this->assertNull($this->runs[0]->workOrderId);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('1 k vystavení', $display);
        $this->assertStringContainsString('Dry-run — nic se nezapsalo.', $display);
    }
}

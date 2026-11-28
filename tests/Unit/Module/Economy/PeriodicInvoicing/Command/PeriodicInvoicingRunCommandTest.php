<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\PeriodicInvoicing\Command;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Economy\PeriodicInvoicing\Command\PeriodicInvoicingRunCommand;
use Shipard\Module\Economy\PeriodicInvoicing\InvoicingRunService;
use Shipard\Module\Economy\PeriodicInvoicing\RunLine;
use Shipard\Module\Economy\PeriodicInvoicing\RunOptions;
use Shipard\Module\Economy\PeriodicInvoicing\RunReport;
use Shipard\Module\Economy\PeriodicInvoicing\Source\InvoicingSources;
use Shipard\Module\Economy\WorkOrders\Command\WorkOrdersInvoiceRunCommand;
use Shipard\Tests\Unit\Module\Economy\PeriodicInvoicing\FakeInvoicingSource;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * CLI `periodic-invoicing-run` (#175 D6) a jeho alias pro zakázky
 * `work-orders-invoice-run`: volby → parametry běhu, výpis, exit kódy.
 */
class PeriodicInvoicingRunCommandTest extends TestCase
{
    /** @var list<RunOptions> */
    private array $runs = [];

    /**
     * @param list<RunLine> $lines
     * @param class-string<PeriodicInvoicingRunCommand> $commandClass
     */
    private function tester(array $lines = [], bool $withSources = true, string $commandClass = PeriodicInvoicingRunCommand::class): CommandTester
    {
        $sources = [];
        if ($withSources) {
            $workOrders = new FakeInvoicingSource();
            $workOrders->records = [['id' => 6, 'number' => 'S260001', 'title' => 'Nájem kanceláře']];
            $contracts = new FakeInvoicingSource(id: 'contracts', table: 'test_contracts_heads', periodsTable: 'test_contracts_periods', recordColumn: 'contract', noun: 'Smlouva');
            $contracts->records = [['id' => 6, 'number' => 'N-1', 'title' => 'Byt 12']];
            $sources = [$workOrders, $contracts];
        }

        $runs = &$this->runs;
        $service = new class($lines, $runs, new InvoicingSources($sources)) extends InvoicingRunService {
            public function __construct(private readonly array $lines, private array &$runs, private readonly InvoicingSources $fakeSources)
            {
            }

            public function sources(): InvoicingSources
            {
                return $this->fakeSources;
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

        $dsConfig = $this->createMock(DataSourceConfig::class);
        $db = $this->createMock(DataSourceConnection::class);
        $command = $commandClass === WorkOrdersInvoiceRunCommand::class
            ? new class($dsConfig, $db, $service) extends WorkOrdersInvoiceRunCommand {
                use FakeServiceCommand;
            }
            : new class($dsConfig, $db, $service) extends PeriodicInvoicingRunCommand {
                use FakeServiceCommand;
            };
        $app = new Application();
        $app->add($command);
        return new CommandTester($app->find((string) $command->getName()));
    }

    public function testInvalidInputIsRejectedBeforeTheRun(): void
    {
        $tester = $this->tester();
        $this->assertSame(2, $tester->execute(['--date' => '2026-13-01']));
        $this->assertStringContainsString('--date must be YYYY-MM-DD', $tester->getDisplay());

        // Id záznamu je jednoznačné jen ve zdroji.
        $tester = $this->tester();
        $this->assertSame(2, $tester->execute(['--record' => 'S260001']));
        $this->assertStringContainsString('--record requires --source', $tester->getDisplay());

        $tester = $this->tester();
        $this->assertSame(2, $tester->execute(['--source' => 'nope']));
        $this->assertStringContainsString("Zdroj fakturace 'nope' neexistuje. Zdroje: workOrders, contracts.", $tester->getDisplay());

        $tester = $this->tester();
        $this->assertSame(2, $tester->execute(['--source' => 'workOrders', '--record' => 'S999']));
        $this->assertStringContainsString("Zakázka 'S999' neexistuje", $tester->getDisplay());

        // Číslo se hledá jen ve zvoleném zdroji; pojmenování záznamu dává zdroj.
        $tester = $this->tester();
        $this->assertSame(2, $tester->execute(['--source' => 'contracts', '--record' => 'S260001']));
        $this->assertStringContainsString("Smlouva 'S260001' neexistuje", $tester->getDisplay());
        $this->assertSame([], $this->runs);
    }

    public function testNoRegisteredSourceIsANoop(): void
    {
        $tester = $this->tester(withSources: false);
        $this->assertSame(0, $tester->execute([]));
        $this->assertStringContainsString('nothing to do', $tester->getDisplay());
        $this->assertSame([], $this->runs);
    }

    public function testRunForOneRecordIsForcedAndPrintsReport(): void
    {
        $tester = $this->tester([
            new RunLine('workOrders', 6, 'S260001 Nájem kanceláře', '2026-08-01', '2026-08-31', RunReport::OUTCOME_ISSUED, 1001, 'Nájem srpen 2026', 1),
            new RunLine('workOrders', 6, 'S260001 Nájem kanceláře', '2026-09-01', '2026-09-30', RunReport::OUTCOME_FAILED, null, 'number_series_not_found', 2),
        ]);
        $exit = $tester->execute(['--date' => '2026-10-08', '--source' => 'workOrders', '--record' => 'S260001']);

        $this->assertSame(1, $exit, 'selhané období = exit 1');
        $this->assertCount(1, $this->runs);
        $options = $this->runs[0];
        $this->assertSame('2026-10-08', $options->date);
        $this->assertSame(['workOrders', 6], [$options->sourceId, $options->recordId]);
        $this->assertTrue($options->force);
        $this->assertFalse($options->dryRun);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('S260001 Nájem kanceláře [workOrders]', $display);
        $this->assertStringContainsString('2026-08-01 – 2026-08-31', $display);
        $this->assertStringContainsString('doklad #1001', $display);
        $this->assertStringContainsString('number_series_not_found', $display);
        $this->assertStringContainsString('1 vystaveno, 0 čeká na podklady, 1 selhalo', $display);
    }

    public function testDailyRunCoversAllSourcesWithTheCatchUpGuard(): void
    {
        $tester = $this->tester([
            new RunLine('workOrders', 6, 'S260001 Nájem', '2026-10-01', '2026-10-31', RunReport::OUTCOME_PLANNED, null, 'Nájem říjen 2026'),
            new RunLine('contracts', 6, 'N-1 Byt 12', '2026-10-01', '2026-10-31', RunReport::OUTCOME_PLANNED, null, 'Byt říjen 2026'),
        ]);
        $this->assertSame(0, $tester->execute(['--dry-run' => true]));
        $this->assertTrue($this->runs[0]->dryRun);
        $this->assertFalse($this->runs[0]->force);
        $this->assertSame([null, null], [$this->runs[0]->sourceId, $this->runs[0]->recordId]);
        $display = $tester->getDisplay();
        // Stejné id záznamu ve dvou zdrojích = dvě hlavičky.
        $this->assertStringContainsString('S260001 Nájem [workOrders]', $display);
        $this->assertStringContainsString('N-1 Byt 12 [contracts]', $display);
        $this->assertStringContainsString('2 k vystavení', $display);
        $this->assertStringContainsString('Dry-run — nic se nezapsalo.', $display);

        // Jen jeden zdroj, bez záznamu = s pojistkou dohánění.
        $tester = $this->tester();
        $this->assertSame(0, $tester->execute(['--source' => 'contracts']));
        $this->assertSame(['contracts', null, false], [$this->runs[1]->sourceId, $this->runs[1]->recordId, $this->runs[1]->force]);
    }

    // ── alias work-orders-invoice-run (zanikne v dalším vydání) ─────────────

    public function testAliasRunsTheWorkOrdersSource(): void
    {
        $tester = $this->tester([
            new RunLine('workOrders', 6, 'S260001 Nájem kanceláře', '2026-08-01', '2026-08-31', RunReport::OUTCOME_ISSUED, 1001, 'Nájem srpen 2026', 1),
        ], commandClass: WorkOrdersInvoiceRunCommand::class);
        $this->assertSame(0, $tester->execute(['--date' => '2026-10-08', '--work-order' => 'S260001']));

        $options = $this->runs[0];
        $this->assertSame(['2026-10-08', 'workOrders', 6, true, false], [$options->date, $options->sourceId, $options->recordId, $options->force, $options->dryRun]);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('S260001 Nájem kanceláře', $display);
        $this->assertStringContainsString('doklad #1001', $display);
        $this->assertStringContainsString('1 vystaveno, 0 čeká na podklady, 0 selhalo', $display);
        $this->assertStringContainsString('zanikne v dalším vydání', $display);

        // Bez čísla = všechny zakázky s pojistkou dohánění, jiné zdroje ne.
        $tester = $this->tester(commandClass: WorkOrdersInvoiceRunCommand::class);
        $this->assertSame(0, $tester->execute(['--dry-run' => true]));
        $this->assertSame(['workOrders', null, false, true], [$this->runs[1]->sourceId, $this->runs[1]->recordId, $this->runs[1]->force, $this->runs[1]->dryRun]);
    }

    public function testAliasRejectsInvalidDateAndUnknownWorkOrder(): void
    {
        $tester = $this->tester(commandClass: WorkOrdersInvoiceRunCommand::class);
        $this->assertSame(2, $tester->execute(['--date' => '2026-13-01']));
        $this->assertStringContainsString('--date must be YYYY-MM-DD', $tester->getDisplay());

        $tester = $this->tester(commandClass: WorkOrdersInvoiceRunCommand::class);
        $this->assertSame(2, $tester->execute(['--work-order' => 'S999']));
        $this->assertStringContainsString("Zakázka 'S999' neexistuje", $tester->getDisplay());
        $this->assertSame([], $this->runs);
    }

    public function testCommandsHaveDistinctNamesAndOptions(): void
    {
        $run = new PeriodicInvoicingRunCommand();
        $alias = new WorkOrdersInvoiceRunCommand();
        $this->assertSame('periodic-invoicing-run', $run->getName());
        $this->assertSame('work-orders-invoice-run', $alias->getName());
        $this->assertSame([], $run->getAliases());
        $this->assertSame(['date', 'source', 'record', 'dry-run'], array_keys($run->getDefinition()->getOptions()));
        $this->assertSame(['date', 'work-order', 'dry-run'], array_keys($alias->getDefinition()->getOptions()));
    }
}

/** Příkaz nad podvrženou službou běhu, mimo adresář zdroje dat. */
trait FakeServiceCommand
{
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
}

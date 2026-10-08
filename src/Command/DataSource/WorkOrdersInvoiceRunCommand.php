<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Shipard\Api\DocumentEventHandlerLoader;
use Shipard\Api\DocumentLoader;
use Shipard\Api\JournalEventHandlerLoader;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Api\TableLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Economy\WorkOrders\Invoicing\InvoicingRunFactory;
use Shipard\Module\Economy\WorkOrders\Invoicing\InvoicingRunService;
use Shipard\Module\Economy\WorkOrders\Invoicing\RunLine;
use Shipard\Module\Economy\WorkOrders\Invoicing\RunOptions;
use Shipard\Module\Economy\WorkOrders\Invoicing\RunReport;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Běh periodické fakturace (docs/work-orders.md D5, tasks/work-orders-phase2.md
 * §3): denní cron (CronCommand::SLOT_JOBS['daily'], stav DS active)
 * a ruční spuštění s náhledem.
 *
 *   --date=YYYY-MM-DD   simuluje datum běhu (splatnost období)
 *   --work-order=<číslo> jen jedna zakázka — bez pojistky dohánění (Q4)
 *   --dry-run           vypíše období a doklady, které by vznikly, bez zápisu
 *
 * Exit 0; 1 když se některé období nepodařilo vystavit; INVALID při
 * špatném datu nebo neznámém čísle zakázky.
 */
class WorkOrdersInvoiceRunCommand extends Command
{
    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('work-orders-invoice-run')
            ->setDescription('Issue periodic work order invoices (drafts) for due periods — daily cron; --dry-run previews')
            ->addOption('date', null, InputOption::VALUE_REQUIRED, 'Run date YYYY-MM-DD (default today)')
            ->addOption('work-order', null, InputOption::VALUE_REQUIRED, 'Only this work order (number); issues all due periods regardless of the catch-up guard')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List periods and documents that would be issued, write nothing');
    }

    protected function getDataSourceDir(): string
    {
        return (string) getcwd();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dsDir = $this->getDataSourceDir();
        if ($this->dsConfig === null && !file_exists($dsDir . '/config/main.json')) {
            $output->writeln('<error>Error: Not a Shipard data source directory</error>');
            return Command::FAILURE;
        }

        $date = date('Y-m-d');
        $dateRaw = $input->getOption('date');
        if (is_string($dateRaw) && $dateRaw !== '') {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $dateRaw);
            if ($parsed === false || $parsed->format('Y-m-d') !== $dateRaw) {
                $output->writeln('<error>--date must be YYYY-MM-DD</error>');
                return Command::INVALID;
            }
            $date = $dateRaw;
        }
        $dryRun = (bool) $input->getOption('dry-run');

        $dsConfig     = $this->dsConfig ?? new DataSourceConfig($dsDir);
        $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);

        if (!in_array('economy_work_orders_periods', $dsConnection->getAllTableNames(), true)) {
            $output->writeln('economy.workOrders not active — nothing to do');
            return Command::SUCCESS;
        }

        $workOrderId = null;
        $numberRaw = $input->getOption('work-order');
        if (is_string($numberRaw) && $numberRaw !== '') {
            $workOrderId = $this->findWorkOrderId($dsConnection, $numberRaw);
            if ($workOrderId === null) {
                $output->writeln("<error>Zakázka '{$numberRaw}' neexistuje.</error>");
                return Command::INVALID;
            }
        }

        $options = new RunOptions(
            date: $date,
            workOrderId: $workOrderId,
            dryRun: $dryRun,
            force: $workOrderId !== null,
            now: date('Y-m-d H:i:s'),
        );

        try {
            $report = $this->createService($dsDir, $dsConfig, $dsConnection)->run($options);
        } catch (\RuntimeException $e) {
            $output->writeln("<error>{$e->getMessage()}</error>");
            return Command::FAILURE;
        }

        $this->printReport($output, $report);
        return $report->hasFailures() ? Command::FAILURE : Command::SUCCESS;
    }

    /** Produkční wiring (přepsatelné v testech). */
    protected function createService(string $dsDir, DataSourceConfig $dsConfig, DataSourceConnection $dsConnection): InvoicingRunService
    {
        $language = $dsConfig->getDefaultLanguage();
        $config   = ConfigRuntime::load($dsDir, $language);
        $resolver = $this->buildResolver();
        $tables   = TableLoader::load($dsConfig, $resolver, $language);
        $registry = DocumentLoader::load($dsConfig, $resolver);
        $dibi     = $dsConnection->getDibiConnection();

        $journalEvents = JournalEventHandlerLoader::load($dsConfig, $resolver, $dibi, $config);
        $dispatcher    = DocumentEventHandlerLoader::load($dsConfig, $resolver, $dibi, $config, $journalEvents);

        return InvoicingRunFactory::create($dibi, $config, $dsConfig, $registry, $tables, $dispatcher);
    }

    protected function findWorkOrderId(DataSourceConnection $db, string $number): ?int
    {
        $row = $db->fetchRow('SELECT `id` FROM `economy_work_orders_heads` WHERE `number` = %s', $number);
        return $row !== null ? (int) $row['id'] : null;
    }

    private function buildResolver(): ModulePathResolver
    {
        try {
            $sc = new ServerConfig();
            $sc->load();
            return ModulePathResolver::fromServerConfig($sc, dirname(__DIR__, 3) . '/modules');
        } catch (\Throwable) {
            return new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        }
    }

    private function printReport(OutputInterface $output, RunReport $report): void
    {
        $current = null;
        foreach ($report->lines as $line) {
            if ($current !== $line->workOrderId) {
                $current = $line->workOrderId;
                $output->writeln(sprintf('<info>%s</info> — %s', $line->workOrderNumber !== '' ? $line->workOrderNumber : '#' . $line->workOrderId, $line->workOrderTitle));
            }
            $output->writeln('  ' . $this->formatLine($line));
        }

        $counts = $report->counts();
        $output->writeln('');
        $output->writeln(sprintf(
            'Období: %d %s, %d čeká na podklady, %d selhalo, %d zablokováno pojistkou dohánění, %d přeskočeno',
            $report->options->dryRun ? $counts[RunReport::OUTCOME_PLANNED] : $counts[RunReport::OUTCOME_ISSUED],
            $report->options->dryRun ? 'k vystavení' : 'vystaveno',
            $counts[RunReport::OUTCOME_WAITING],
            $counts[RunReport::OUTCOME_FAILED],
            $counts[RunReport::OUTCOME_CATCHUP],
            $counts[RunReport::OUTCOME_SKIPPED],
        ));
        if ($report->options->dryRun) {
            $output->writeln('<comment>Dry-run — nic se nezapsalo.</comment>');
        }
    }

    private function formatLine(RunLine $line): string
    {
        $period = $line->periodFrom !== null ? "{$line->periodFrom} – {$line->periodTo}" : '(bez období)';
        $label = match ($line->outcome) {
            RunReport::OUTCOME_ISSUED  => 'vystaveno',
            RunReport::OUTCOME_WAITING => 'čeká na podklady',
            RunReport::OUTCOME_PLANNED => 'k vystavení',
            RunReport::OUTCOME_FAILED  => '<error>selhalo</error>',
            RunReport::OUTCOME_CATCHUP => '<comment>pojistka dohánění</comment>',
            default                    => $line->outcome,
        };
        $tail = $line->docId !== null ? "doklad #{$line->docId}" : '';
        if ($line->message !== null && $line->message !== '') {
            $tail .= ($tail !== '' ? ' — ' : '') . $line->message;
        }
        return sprintf('%-25s %-22s %s', $period, $label, $tail);
    }
}

<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\PeriodicInvoicing\Command;

use Shipard\Api\DocumentEventHandlerLoader;
use Shipard\Api\DocumentLoader;
use Shipard\Api\JournalEventHandlerLoader;
use Shipard\Api\TableLoader;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Economy\PeriodicInvoicing\InvoicingRunFactory;
use Shipard\Module\Economy\PeriodicInvoicing\InvoicingRunService;
use Shipard\Module\Economy\PeriodicInvoicing\RunLine;
use Shipard\Module\Economy\PeriodicInvoicing\RunOptions;
use Shipard\Module\Economy\PeriodicInvoicing\RunReport;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Běh periodické fakturace (docs/work-orders.md D5, #175 D6): jeden běh pro
 * všechny zdroje fakturace — denní cron (`cronJobs` v module.jsonc modulu,
 * stav DS active) a ruční spuštění s náhledem.
 *
 *   --date=YYYY-MM-DD  simuluje datum běhu (splatnost období)
 *   --source=<id>      jen jeden zdroj fakturace
 *   --record=<číslo>   jen jeden záznam zdroje (vyžaduje --source) — bez
 *                      pojistky dohánění (Q4)
 *   --dry-run          vypíše období a doklady, které by vznikly, bez zápisu
 *
 * Exit 0; 1 když se některé období nepodařilo vystavit; INVALID při
 * špatném datu, neznámém zdroji nebo čísle záznamu a u `--record` bez
 * `--source`.
 */
class PeriodicInvoicingRunCommand extends Command
{
    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('periodic-invoicing-run')
            ->setDescription('Vystaví koncepty dokladů periodické fakturace za splatná období všech zdrojů — denní cron; --date=YYYY-MM-DD simuluje datum běhu, --source=<id> jen jeden zdroj, --record=<číslo> jen jeden záznam zdroje bez pojistky dohánění, --dry-run jen vypíše')
            ->addOption('date', null, InputOption::VALUE_REQUIRED, 'Run date YYYY-MM-DD (default today)')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Only this invoicing source (id)')
            ->addOption('record', null, InputOption::VALUE_REQUIRED, 'Only this record of the source (number); requires --source, issues all due periods regardless of the catch-up guard')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List periods and documents that would be issued, write nothing');
    }

    protected function getDataSourceDir(): string
    {
        return (string) getcwd();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sourceId = self::stringOption($input, 'source');
        $recordNumber = self::stringOption($input, 'record');
        if ($recordNumber !== null && $sourceId === null) {
            $output->writeln('<error>--record requires --source</error>');
            return Command::INVALID;
        }

        return $this->runInvoicing($input, $output, $sourceId, $recordNumber);
    }

    /**
     * Společné tělo běhu (i pro alias `work-orders-invoice-run`): datum,
     * služba, zdroj a záznam, běh a výpis.
     */
    protected function runInvoicing(InputInterface $input, OutputInterface $output, ?string $sourceId, ?string $recordNumber): int
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

        try {
            $service = $this->createService($dsDir, $dsConfig, $dsConnection);
        } catch (\RuntimeException $e) {
            $output->writeln("<error>{$e->getMessage()}</error>");
            return Command::FAILURE;
        }

        $sources = $service->sources();
        if ($sources->isEmpty()) {
            $output->writeln('no periodic invoicing source registered — nothing to do');
            return Command::SUCCESS;
        }

        $recordId = null;
        if ($sourceId !== null) {
            $source = $sources->get($sourceId);
            if ($source === null) {
                $output->writeln("<error>Zdroj fakturace '{$sourceId}' neexistuje. Zdroje: " . implode(', ', $sources->ids()) . '.</error>');
                return Command::INVALID;
            }
            if ($recordNumber !== null) {
                $recordId = $source->recordIdByNumber($recordNumber);
                if ($recordId === null) {
                    $output->writeln("<error>{$source->recordNoun()} '{$recordNumber}' neexistuje.</error>");
                    return Command::INVALID;
                }
            }
        }

        $options = new RunOptions(
            date: $date,
            sourceId: $sourceId,
            recordId: $recordId,
            dryRun: $dryRun,
            force: $recordId !== null,
            now: date('Y-m-d H:i:s'),
        );

        try {
            $report = $service->run($options);
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

    protected static function stringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function buildResolver(): ModulePathResolver
    {
        try {
            $sc = new ServerConfig();
            $sc->load();
            return ModulePathResolver::fromServerConfig($sc, dirname(__DIR__, 5) . '/modules');
        } catch (\Throwable) {
            return new ModulePathResolver([dirname(__DIR__, 5) . '/modules']);
        }
    }

    private function printReport(OutputInterface $output, RunReport $report): void
    {
        $current = null;
        foreach ($report->lines as $line) {
            $key = $line->sourceId . ':' . $line->recordId;
            if ($current !== $key) {
                $current = $key;
                $output->writeln(sprintf('<info>%s</info> [%s]', $line->recordLabel, $line->sourceId));
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

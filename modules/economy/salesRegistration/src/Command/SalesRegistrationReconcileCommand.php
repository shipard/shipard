<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Command;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Economy\SalesRegistration\Sales\ReconcileScanner;
use Shipard\Module\Economy\SalesRegistration\Sales\SyncResult;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Záchranná síť evidence tržeb (#171 D7) — úloha cronu `five-minutes`:
 * doklady V pořádku a Storno se změnou stavu v posledních dnech projde
 * rozdílovým výpočtem a založí tržby, které handler přechodu nevytvořil
 * (běží po commitu a výjimky polyká). Tržba dostane čas přechodu dokladu
 * a odešle ji úloha `sales-registration-run`.
 *
 * `--dry-run` vypíše, co by vzniklo, a nic nezapíše.
 */
class SalesRegistrationReconcileCommand extends Command
{
    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
        private readonly ?ReconcileScanner $scanner = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('sales-registration-reconcile')
            ->setDescription('Záchranná síť evidence tržeb: dorovná chybějící tržby dokladů V pořádku a Storno z posledních dní — úloha cronu five-minutes; --days, --dry-run')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'How many days back to look', (string) ReconcileScanner::DEFAULT_DAYS)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only print what would be created');
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
        $days = (int) $input->getOption('days');
        if ($days < 1) {
            $output->writeln('<error>Error: --days must be a positive integer</error>');
            return Command::FAILURE;
        }
        $dryRun = (bool) $input->getOption('dry-run');

        try {
            $results = ($this->scanner ?? $this->createScanner($dsDir))->run($days, null, $dryRun);
        } catch (\Throwable $e) {
            $output->writeln('<error>Error: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $counts = [SyncResult::CREATED => 0, SyncResult::WOULD_CREATE => 0, SyncResult::UNREGISTRABLE => 0, SyncResult::BUSY => 0];
        foreach ($results as $result) {
            $counts[$result->status] = ($counts[$result->status] ?? 0) + 1;
            $output->writeln(match ($result->status) {
                SyncResult::CREATED, SyncResult::WOULD_CREATE => sprintf(
                    '  doc %d: %s %s %s (sold at %s)',
                    $result->docId,
                    $result->status === SyncResult::CREATED ? 'created' : 'would create',
                    $result->sale['serial_number'],
                    $result->sale['amount'],
                    $result->sale['sold_at'],
                ),
                SyncResult::UNREGISTRABLE => sprintf('  doc %d: cannot be registered (%s)', $result->docId, $result->reason),
                default => sprintf('  doc %d: %s', $result->docId, $result->status),
            });
        }
        $output->writeln(sprintf(
            '%s: %d created, %d cannot be registered, %d busy',
            $dryRun ? 'Dry run' : 'Reconciled',
            $counts[SyncResult::CREATED] + $counts[SyncResult::WOULD_CREATE],
            $counts[SyncResult::UNREGISTRABLE],
            $counts[SyncResult::BUSY],
        ));
        return Command::SUCCESS;
    }

    private function createScanner(string $dsDir): ReconcileScanner
    {
        $dsConfig = $this->dsConfig ?? new DataSourceConfig($dsDir);
        $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);
        return ReconcileScanner::create(
            $dsConnection->getDibiConnection(),
            ConfigRuntime::load($dsConfig->getDataSourceDir(), $dsConfig->getDefaultLanguage()),
            $dsConfig,
        );
    }
}

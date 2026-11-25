<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Alerts\Command;

use Shipard\Core\Alerts\AlertReconciler;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Maže staré uzavřené alerty (resolved/dismissed) podle retenční doby.
 * Reconciler sám nikdy nemaže — to dělá tento příkaz.
 *
 * Spec: tasks/alerts-01.md §8.2.
 */
class AlertsPruneCommand extends Command
{
    public const DEFAULT_RETENTION_DAYS = 90;

    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('alerts-prune')
            ->setDescription('Delete resolved/dismissed alerts older than the retention window')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Retention window in days', (string) self::DEFAULT_RETENTION_DAYS)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print what would be deleted without touching the DB');
    }

    protected function getDataSourceDir(): string
    {
        return getcwd();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dsDir = $this->getDataSourceDir();

        if ($this->dsConfig === null && !file_exists($dsDir . '/config/main.json')) {
            $output->writeln('<error>Error: Not a Shipard data source directory</error>');
            return Command::FAILURE;
        }

        $daysRaw = $input->getOption('days');
        $days    = is_numeric($daysRaw) ? (int) $daysRaw : self::DEFAULT_RETENTION_DAYS;
        if ($days <= 0) {
            $output->writeln('<error>--days must be a positive integer</error>');
            return Command::FAILURE;
        }
        $dryRun = (bool) $input->getOption('dry-run');

        $dsConfig     = $this->dsConfig ?? new DataSourceConfig($dsDir);
        $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);

        $cutoff = (new \DateTimeImmutable())
            ->modify("-{$days} days")
            ->format('Y-m-d H:i:s');

        // Resolved: where resolved_at < cutoff
        // Dismissed: where dismissed_at < cutoff
        $count = (int) $dsConnection->fetchSingle(
            'SELECT COUNT(*) FROM %n WHERE'
            . ' (alert_state = %i AND resolved_at IS NOT NULL AND resolved_at < %s)'
            . ' OR (alert_state = %i AND dismissed_at IS NOT NULL AND dismissed_at < %s)',
            AlertReconciler::ALERTS_TABLE,
            AlertReconciler::STATE_RESOLVED, $cutoff,
            AlertReconciler::STATE_DISMISSED, $cutoff,
        );

        $output->writeln("Cutoff: {$cutoff} (older than {$days} days)");
        $output->writeln(sprintf('Found %d alert(s) eligible for pruning.', $count));

        if ($count === 0) {
            return Command::SUCCESS;
        }

        if ($dryRun) {
            $output->writeln('<comment>Dry run — no rows deleted.</comment>');
            return Command::SUCCESS;
        }

        $dsConnection->execute(
            'DELETE FROM %n WHERE'
            . ' (alert_state = %i AND resolved_at IS NOT NULL AND resolved_at < %s)'
            . ' OR (alert_state = %i AND dismissed_at IS NOT NULL AND dismissed_at < %s)',
            AlertReconciler::ALERTS_TABLE,
            AlertReconciler::STATE_RESOLVED, $cutoff,
            AlertReconciler::STATE_DISMISSED, $cutoff,
        );

        $output->writeln(sprintf('<info>Deleted %d alert(s).</info>', $dsConnection->getAffectedRows()));
        return Command::SUCCESS;
    }
}

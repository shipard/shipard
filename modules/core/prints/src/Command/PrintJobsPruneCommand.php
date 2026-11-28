<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Prints\Command;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Prints\Jobs\PrintJobService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Úklid starých tiskových úloh (#177 U39): úlohy starší než 30 dní i s daty.
 * Spouští se jednou denně z cronu; když není co mazat, nic nevypíše.
 */
class PrintJobsPruneCommand extends Command
{
    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('print-jobs-prune')
             ->setDescription('Remove old print jobs of receipt printers')
             ->addOption(
                 'days',
                 null,
                 InputOption::VALUE_REQUIRED,
                 'Remove jobs older than this many days',
                 (string) PrintJobService::RETENTION_DAYS,
             );
    }

    protected function getDataSourceDir(): string
    {
        return (string) getcwd();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dsDir = $this->getDataSourceDir();
        if ($this->dsConnection === null && $this->dsConfig === null && !file_exists($dsDir . '/config/main.json')) {
            $output->writeln('<error>Error: Not a Shipard data source directory</error>');
            return Command::FAILURE;
        }

        $raw = (string) $input->getOption('days');
        if (preg_match('/^[1-9][0-9]{0,4}$/D', $raw) !== 1) {
            $output->writeln('<error>Error: --days must be a positive integer</error>');
            return Command::FAILURE;
        }
        $days = (int) $raw;

        $db = $this->dsConnection ?? new DataSourceConnection($this->dsConfig ?? new DataSourceConfig($dsDir));
        $removed = PrintJobService::forMaintenance($db)->prune($days);

        if ($removed > 0 || $output->isVerbose()) {
            $output->writeln("Removed {$removed} print jobs older than {$days} days.");
        }
        return Command::SUCCESS;
    }
}

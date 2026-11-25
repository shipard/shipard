<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Command;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Mail\IdempotencyStore;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Vymaže staré záznamy z `core_mail_incoming_idempotency`. Spouští se 1×/den z cronu.
 */
class MailIdempotencyPruneCommand extends Command
{
    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('mail-idempotency-prune')
             ->setDescription('Remove expired idempotency keys for incoming mail')
             ->addOption('days', null, InputOption::VALUE_REQUIRED, 'TTL threshold in days', (string) IdempotencyStore::TTL_DAYS);
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

        $days = (int) $input->getOption('days');
        if ($days < 1) {
            $output->writeln('<error>Error: --days must be a positive integer</error>');
            return Command::FAILURE;
        }

        $dsConfig = $this->dsConfig ?? new DataSourceConfig($dsDir);
        $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);

        $store = new IdempotencyStore($dsConnection);
        $removed = $store->prune($days);

        $output->writeln("Removed {$removed} idempotency keys older than {$days} days.");

        return Command::SUCCESS;
    }
}

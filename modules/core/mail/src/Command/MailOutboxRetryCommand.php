<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Command;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Mail\MailOutboxService;
use Shipard\Core\Mail\MailServiceFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Vrátí terminálně selhanou (`failed`) zprávu zpět do fronty s vynulovaným
 * počítadlem pokusů — ops nástroj po opravě transportu (viz runbook
 * docs/mail/outbound.md).
 */
class MailOutboxRetryCommand extends Command
{
    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
        private readonly ?MailOutboxService $service = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('mail-outbox-retry')
            ->setDescription('Re-queue a failed outbound message')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Outbox message id');
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

        $id = (int) $input->getOption('id');
        if ($id < 1) {
            $output->writeln('<error>Error: --id is required and must be a positive integer</error>');
            return Command::FAILURE;
        }

        try {
            $dsConfig     = $this->dsConfig ?? new DataSourceConfig($dsDir);
            $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);
            $service      = $this->service ?? MailServiceFactory::create($dsConfig, $dsConnection);

            if ($service->retry($id)) {
                $output->writeln("Outbox #{$id} re-queued.");
                return Command::SUCCESS;
            }

            $state = $dsConnection->fetchSingle(
                'SELECT state FROM core_mail_outbox WHERE id = %i',
                $id,
            );
            if ($state === null) {
                $output->writeln("<error>Error: Outbox #{$id} not found</error>");
            } else {
                $output->writeln(
                    "<error>Error: Outbox #{$id} is in state '{$state}' — only 'failed' messages can be retried</error>",
                );
            }
            return Command::FAILURE;
        } catch (\Throwable $e) {
            $output->writeln('<error>Error: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }
}

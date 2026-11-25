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
 * Worker fronty odchozí pošty — volá se z cronu per DS (à la alerts-run).
 * Selhané zprávy NEjsou chyba příkazu (reportuje je alert check a doctor,
 * cron nesmí spamovat MAILTO) — FAILURE jen při infra chybě.
 */
class MailOutboxRunCommand extends Command
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
        $this->setName('mail-outbox-run')
            ->setDescription('Process due messages in the outbound mail queue')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max messages to process in one run', '50');
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

        $limit = (int) $input->getOption('limit');
        if ($limit < 1) {
            $output->writeln('<error>Error: --limit must be a positive integer</error>');
            return Command::FAILURE;
        }

        try {
            $dsConfig     = $this->dsConfig ?? new DataSourceConfig($dsDir);
            $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);
            $service      = $this->service ?? MailServiceFactory::create($dsConfig, $dsConnection);

            $stats = $service->processQueue($limit);
        } catch (\Throwable $e) {
            $output->writeln('<error>Error: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            'Processed %d: %d sent, %d retried, %d failed (terminal), %d requeued from stale sending',
            $stats['processed'],
            $stats['sent'],
            $stats['retried'],
            $stats['failed'],
            $stats['requeued'],
        ));

        return Command::SUCCESS;
    }
}

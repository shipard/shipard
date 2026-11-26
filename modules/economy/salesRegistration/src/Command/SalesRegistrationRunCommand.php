<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Command;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Economy\SalesRegistration\Queue\SaleQueue;
use Shipard\Module\Economy\SalesRegistration\Safety\SalesRegistrationServerConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Worker fronty evidence tržeb (#171 D7) — úloha cronu `minute`: pošle
 * splatné tržby (`pending`, termín dalšího pokusu ≤ teď) a vrátí do fronty
 * ty, které zůstaly viset v `sending` po pádu procesu.
 *
 * Neodeslaná tržba není chyba příkazu (hlásí ji upozornění, cron nesmí
 * spamovat) — FAILURE jen při chybě infrastruktury.
 */
class SalesRegistrationRunCommand extends Command
{
    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
        private readonly ?SaleQueue $queue = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('sales-registration-run')
            ->setDescription('Odešle splatné tržby evidence tržeb (fronta s opakováním) a vrátí do fronty zaseknuté — úloha cronu minute; --limit')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max sales to send in one run', (string) SaleQueue::DEFAULT_LIMIT);
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
        $limit = (int) $input->getOption('limit');
        if ($limit < 1) {
            $output->writeln('<error>Error: --limit must be a positive integer</error>');
            return Command::FAILURE;
        }

        try {
            $stats = ($this->queue ?? $this->createQueue($dsDir))->processDue($limit);
        } catch (\Throwable $e) {
            $output->writeln('<error>Error: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            'Processed %d: %d confirmed, %d verified, %d dropped, %d failed, %d to retry; %d skipped after an outage, %d requeued from stale sending',
            $stats['processed'],
            $stats['confirmed'],
            $stats['verified'],
            $stats['dropped'],
            $stats['failed'],
            $stats['retried'],
            $stats['skipped'],
            $stats['requeued'],
        ));
        return Command::SUCCESS;
    }

    private function createQueue(string $dsDir): SaleQueue
    {
        $dsConfig = $this->dsConfig ?? new DataSourceConfig($dsDir);
        $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);
        return SaleQueue::create(
            $dsConnection->getDibiConnection(),
            ConfigRuntime::load($dsConfig->getDataSourceDir(), $dsConfig->getDefaultLanguage()),
            $dsConfig,
            SalesRegistrationServerConfig::forServer(),
        );
    }
}

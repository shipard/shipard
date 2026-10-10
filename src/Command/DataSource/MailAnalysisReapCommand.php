<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Mail\Analysis\AnalysisResultWriter;
use Shipard\Module\Core\Mail\AnalysisClaimReaper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reaper expirovaných AI claimů. Spouští se 1×/min z cronu. Spec §3.7;
 * třetí vypršení za hodinu = stav Analýza selhala (#85 D26).
 */
class MailAnalysisReapCommand extends Command
{
    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('mail-analysis-reap')
             ->setDescription('Release expired AI analysis claims and re-queue affected messages');
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

        $dsConfig = $this->dsConfig ?? new DataSourceConfig($dsDir);
        $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);

        $reaper = new AnalysisClaimReaper($dsConnection, new AnalysisResultWriter($dsConnection, $dsConfig));
        $reaped = $reaper->reapExpired();

        if ($reaped === []) {
            $output->writeln('No expired claims to reap.');
            return Command::SUCCESS;
        }

        $output->writeln('<info>Reaped ' . count($reaped) . ' expired claim(s):</info>');
        foreach ($reaped as $entry) {
            $output->writeln(sprintf(
                '  claim=%d message=%d analyzer=%s duration=%ds%s',
                $entry['claim_id'],
                $entry['message_id'],
                $entry['analyzer_id'],
                $entry['duration_seconds'],
                $entry['failed'] ? ' → analysis failed (claim expired 3 times within an hour)' : '',
            ));
        }

        return Command::SUCCESS;
    }
}

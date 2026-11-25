<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat\Command;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Economy\Vat\ReportPeriodsProvisioner;
use Shipard\Module\Economy\Vat\VatOutputsMapping;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Denní cron job (issue #55, D9): pro aktivní registrace DPH zajistí
 * instance daňových tvrzení všech tří typů pokrývající dnešek a zítřek.
 * Idempotentní — existující instance přeskočí. Dopředu nic negeneruje.
 *
 * Registrace v CronCommand::SLOT_JOBS['daily'] + JOB_ALLOWED_STATES.
 */
class VatPeriodsEnsureCommand extends Command
{
    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('vat-periods-ensure')
            ->setDescription('Ensure VAT report period instances (return/cs/rs) cover today and tomorrow for active registrations')
            ->addOption('date', null, InputOption::VALUE_REQUIRED, 'Reference date YYYY-MM-DD (default today)');
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

        $dateRaw = $input->getOption('date');
        $today = null;
        if (is_string($dateRaw) && $dateRaw !== '') {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $dateRaw);
            if ($parsed === false) {
                $output->writeln('<error>--date must be YYYY-MM-DD</error>');
                return Command::INVALID;
            }
            $today = $parsed;
        }

        $dsConfig     = $this->dsConfig ?? new DataSourceConfig($dsDir);
        $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);

        $tables = $dsConnection->getAllTableNames();
        if (!in_array('economy_vat_report_periods', $tables, true)) {
            $output->writeln('economy.vat not active — nothing to do');
            return Command::SUCCESS;
        }

        // Zákonné počátky výstupů (#58) — jen je-li config zkompilovaný;
        // bez něj prázdné (cron generuje jen dnešek/zítřek, omezení nehraje roli).
        $validFrom = [];
        if (is_file($dsDir . '/config/configuration/compiled.cs.json')) {
            $validFrom = VatOutputsMapping::fromConfig(ConfigRuntime::load($dsDir, 'cs'))?->validFromByType() ?? [];
        }
        $result = (new ReportPeriodsProvisioner($dsConnection, $validFrom))->ensureAll($today);
        $output->writeln(sprintf(
            'VAT report periods: %d created, %d already present',
            $result['created'],
            $result['existing'],
        ));
        return Command::SUCCESS;
    }
}

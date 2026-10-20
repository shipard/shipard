<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Shipard\Api\AlertCheckLoader;
use Shipard\Core\Alerts\AlertReconciler;
use Shipard\Core\Alerts\AlertRunResult;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Module\ModuleClassLoader;
use Shipard\Core\Module\ModulePathResolver;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Spustí všechny due alert checky (nebo jeden konkrétní). Volá se z cronu
 * každých 5 minut. Jednotlivý check má vlastní `interval` (typicky 1h+),
 * runner přeskakuje checky kde `next_run_at > NOW`.
 *
 * Spec: tasks/alerts-01.md §8.1.
 */
class AlertsRunCommand extends Command
{
    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('alerts-run')
            ->setDescription('Run due alert checks (or a single check)')
            ->addOption('check', null, InputOption::VALUE_REQUIRED, 'Run only this check ID (ignores schedule)')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Run all enabled checks, ignoring schedule');
        // -v/-vv/-vvv is handled by Symfony Console — $output->isVerbose() reads it.
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

        $dsConfig     = $this->dsConfig ?? new DataSourceConfig($dsDir);
        $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);

        // Build alert check registry — same wiring as the HTTP boot.
        $modulePathResolver = $this->buildModulePathResolver();
        $language           = $dsConfig->getDefaultLanguage();
        $registry           = AlertCheckLoader::load($dsConfig, $modulePathResolver, $language);

        try {
            $configRuntime = ConfigRuntime::load($dsConfig->getDataSourceDir(), $language);
        } catch (\Throwable $e) {
            $output->writeln('<error>Failed to load compiled config: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $reconciler = new AlertReconciler($dsConnection, $registry, $configRuntime, $language, $dsConfig);

        $singleCheck = $input->getOption('check');
        $runAll      = (bool) $input->getOption('all');

        // Compose work list.
        $checkIds = [];
        if ($singleCheck !== null) {
            if ($registry->get((string) $singleCheck) === null) {
                $output->writeln("<error>Unknown check id: {$singleCheck}</error>");
                return Command::FAILURE;
            }
            $checkIds = [(string) $singleCheck];
        } elseif ($runAll) {
            $checkIds = array_map(fn ($d) => $d->id, $registry->getEnabled());
        } else {
            $checkIds = $reconciler->getDueCheckIds();
        }

        $output->writeln('<info>Shipard alerts run</info>');
        $output->writeln('');

        if ($checkIds === []) {
            $output->writeln('No due checks.');
            return Command::SUCCESS;
        }

        $output->writeln('Running ' . count($checkIds) . ' check(s):');

        $stats = ['ok' => 0, 'found' => 0, 'error' => 0, 'skipped' => 0];
        $totalFindings = 0;
        $totalNew      = 0;

        foreach ($checkIds as $id) {
            $r = $reconciler->runCheck($id);
            $stats[$r->status] = ($stats[$r->status] ?? 0) + 1;
            $totalFindings    += $r->findingsCount;
            $totalNew         += $r->newCount;

            $output->writeln($this->formatLine($r));
        }

        $output->writeln('');
        $output->writeln(sprintf(
            'Summary: %d ok, %d found, %d error, %d skipped. %d alerts found (%d new).',
            $stats['ok'], $stats['found'], $stats['error'], $stats['skipped'],
            $totalFindings, $totalNew,
        ));

        return $stats['error'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function formatLine(AlertRunResult $r): string
    {
        $label = match ($r->status) {
            AlertRunResult::STATUS_OK      => '<info>[OK]</info>    ',
            AlertRunResult::STATUS_FOUND   => '<comment>[FOUND]</comment> ',
            AlertRunResult::STATUS_ERROR   => '<error>[ERROR]</error> ',
            AlertRunResult::STATUS_SKIPPED => '[SKIP]  ',
            default                        => '[?????] ',
        };

        $idCol = str_pad($r->checkId, 40);

        switch ($r->status) {
            case AlertRunResult::STATUS_OK:
                $detail = "(0 findings, {$r->durationMs}ms)";
                break;
            case AlertRunResult::STATUS_FOUND:
                $detail = sprintf(
                    '(%d findings — %d new, %d updated, %d resolved, %dms)',
                    $r->findingsCount, $r->newCount, $r->updatedCount, $r->resolvedCount,
                    $r->durationMs,
                );
                break;
            case AlertRunResult::STATUS_ERROR:
                $detail = '— ' . ($r->errorMessage ?? 'unknown error');
                break;
            case AlertRunResult::STATUS_SKIPPED:
                $detail = '— ' . ($r->skippedReason ?? 'skipped');
                break;
            default:
                $detail = '';
        }

        return $label . $idCol . $detail;
    }

    private function buildModulePathResolver(): ModulePathResolver
    {
        try {
            $sc = new ServerConfig();
            $sc->load();
            $resolver = ModulePathResolver::fromServerConfig($sc, dirname(__DIR__, 3) . '/modules');
        } catch (\Throwable) {
            $resolver = new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        }
        ModuleClassLoader::register($resolver);
        return $resolver;
    }
}

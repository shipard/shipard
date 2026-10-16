<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Module\ModuleLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Module\ModuleResolver;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Reset a data source to a clean state for repeated import testing.
 *
 * Drops all "data" tables (codebooks, persons, items, documents, mail, …)
 * while keeping "system" tables declared via `keepOnReset` in module.jsonc
 * (users, API keys, AI backend with its encrypted key), then delegates to
 * `ds-upgrade` to recreate the schema and re-run all idempotent provisioners.
 *
 * Shipard uses no database foreign keys, so tables can be dropped in any
 * order with a single DROP TABLE IF EXISTS.
 */
class DsResetCommand extends Command
{
    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
        private readonly ?ServerConfig $serverConfig = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('ds-reset')
             ->setDescription('Reset data source — drop all data tables and recreate them via ds-upgrade, keeping users, API keys and other protected tables')
             ->addOption('keep', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Additional table(s) to keep (repeatable); additive to declarative keepOnReset')
             ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List drop/keep tables without changing anything')
             ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip the confirmation prompt');
    }

    protected function getDataSourceDir(): string
    {
        return getcwd();
    }

    protected function getModulePathResolver(): ModulePathResolver
    {
        $cfg = $this->serverConfig;
        if ($cfg === null) {
            $cfg = new ServerConfig();
            $cfg->load();
        }
        return ModulePathResolver::fromServerConfig($cfg, dirname(__DIR__, 3) . '/modules');
    }

    /** Server mode; 'development' when server.json is missing (production always has it). */
    protected function getServerMode(): string
    {
        try {
            $cfg = $this->serverConfig;
            if ($cfg === null) {
                $cfg = new ServerConfig();
                $cfg->load();
            }
            return $cfg->getMode();
        } catch (\Throwable) {
            return 'development';
        }
    }

    /** Run ds-upgrade in the same process. Separate method so tests can override. */
    protected function runUpgrade(OutputInterface $output): int
    {
        $app = $this->getApplication();
        if ($app === null) {
            $output->writeln('<error>Cannot run ds-upgrade: no application context</error>');
            return Command::FAILURE;
        }
        return $app->find('ds-upgrade')->run(new ArrayInput([]), $output);
    }

    /** Recursively delete the *contents* of a directory, keeping the directory itself. */
    protected function cleanDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->cleanDir($path);
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dsDir = $this->getDataSourceDir();

        // 1. Guard: must be a data source directory.
        if ($this->dsConfig === null && !file_exists($dsDir . '/config/main.json')) {
            $output->writeln('<error>Not a Shipard data source directory (config/main.json not found)</error>');
            return Command::FAILURE;
        }

        // 2. Production safety net — refuse hard unless the DS explicitly opts in
        //    via "enableReset": true in config/main.json (disposable testing DS).
        $dsConfig = $this->dsConfig ?? new DataSourceConfig($dsDir);
        $isProduction = $this->getServerMode() === 'production';
        if ($isProduction && !$dsConfig->allowsReset()) {
            $output->writeln('<error>Refusing to reset a data source in production mode.</error>');
            $output->writeln('ds-reset is a destructive development/testing tool.');
            $output->writeln('For a disposable testing data source, set "enableReset": true in config/main.json.');
            return Command::FAILURE;
        }
        if ($isProduction) {
            $output->writeln('<comment>enableReset is set in config/main.json — resetting a PRODUCTION data source.</comment>');
        }

        // 3. Build connection / module resolver (lazy, as in ds-upgrade).
        $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);
        $modulePathResolver = $this->getModulePathResolver();

        // 4. Resolve modules and collect keepSet.
        $allModules = ModuleLoader::loadAllModules($modulePathResolver);
        $errors = [];
        $resolvedModules = ModuleResolver::resolve($allModules, $dsConfig->getModules(), $errors);
        foreach ($errors as $error) {
            $output->writeln('<error>' . $error . '</error>');
        }

        $keepSet = [];
        foreach ($resolvedModules as $module) {
            foreach ($module->keepOnReset as $t) {
                $keepSet[$t] = true;
            }
        }
        foreach ($input->getOption('keep') as $t) {
            $keepSet[(string) $t] = true;
        }

        // 5. Compute dropSet / keptList from the live database.
        $existing = $dsConnection->getAllTableNames();
        sort($existing);
        $dropList = array_values(array_filter($existing, fn(string $t) => !isset($keepSet[$t])));
        $keptList = array_values(array_filter($existing, fn(string $t) =>  isset($keepSet[$t])));

        // 6. Report. Třída dat (#126 D11) i s --yes a --dry-run — u kopie
        // reálných dat ji zopakuje dotaz; chování --yes se nemění.
        $output->writeln('<info>Data source: ' . $dsConfig->getName() . ' (' . $dsConfig->getId() . ')</info>');
        $isFree = $dsConfig->isFree();
        $purpose = $dsConfig->getPurpose();
        $output->writeln($isFree
            ? 'Data class: free'
            : '<comment>Data class: realCopy — ' . ($purpose ?? 'no purpose set') . '</comment>');
        $output->writeln('Keeping ' . count($keptList) . ' table(s):');
        foreach ($keptList as $t) {
            $output->writeln('  <comment>[keep]</comment> ' . $t);
        }
        $output->writeln('Dropping ' . count($dropList) . ' table(s):');
        foreach ($dropList as $t) {
            $output->writeln('  [drop] ' . $t);
        }

        $cleansAttachments = in_array('core_attachments_files', $dropList, true);
        if ($cleansAttachments) {
            $output->writeln('<comment>Will also clear att/ and cache/thumbnails/ (core_attachments_files is dropped).</comment>');
        }

        // 7. Dry run.
        if ($input->getOption('dry-run')) {
            $output->writeln('<info>--dry-run: nothing changed.</info>');
            return Command::SUCCESS;
        }

        // 8. Nothing to drop — still ensure schema via ds-upgrade.
        if (empty($dropList)) {
            $output->writeln('Nothing to drop. Running ds-upgrade to ensure schema...');
            return $this->runUpgrade($output);
        }

        // 9. Confirmation.
        if (!$input->getOption('yes')) {
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion(
                ($isFree ? '' : 'This data source is a REAL COPY (purpose: ' . ($purpose ?? 'none') . '). ')
                . 'Drop ' . count($dropList) . ' table(s) and recreate? [y/N] ',
                false,
            );
            if (!$helper->ask($input, $output, $question)) {
                $output->writeln('Aborted.');
                return Command::SUCCESS;
            }
        }

        // 10. Drop.
        $quoted = array_map(fn(string $t) => '`' . $t . '`', $dropList);
        $dsConnection->executeSQL('DROP TABLE IF EXISTS ' . implode(', ', $quoted));
        $output->writeln('<info>Dropped ' . count($dropList) . ' table(s).</info>');

        // 11. Clean attachment folders (only when core_attachments_files was dropped).
        if ($cleansAttachments) {
            $this->cleanDir($dsDir . '/att');
            $this->cleanDir($dsDir . '/cache/thumbnails');
            $output->writeln('Cleared att/ and cache/thumbnails/.');
        }

        // 12. Recreate via ds-upgrade.
        $output->writeln('Running ds-upgrade to recreate schema...');
        $upgradeResult = $this->runUpgrade($output);
        if ($upgradeResult !== Command::SUCCESS) {
            $output->writeln('<error>ds-upgrade failed. Re-run ds-upgrade to recover.</error>');
            return Command::FAILURE;
        }

        // 13. Done.
        $output->writeln('<info>Data source reset complete.</info>');
        return Command::SUCCESS;
    }
}

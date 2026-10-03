<?php

declare(strict_types=1);

namespace Shipard\Command\Server;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Database\DatabaseManager;
use Shipard\Core\Module\InstallModuleRegistry;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Security\DsSecretCipher;
use Shipard\Core\Server\PermissionSpec;
use Shipard\Core\Utils\IdGenerator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DsCreateCommand extends Command
{
    public function __construct(
        private readonly ?ServerConfig $serverConfig = null,
        private readonly ?DatabaseManager $databaseManager = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('ds-create')
             ->setDescription('Create a new data source')
             ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Name of the data source')
             ->addOption(
                 'language',
                 null,
                 InputOption::VALUE_REQUIRED,
                 'Default language, ISO 639-1 (cs|en)',
             )
             ->addOption(
                 'country',
                 null,
                 InputOption::VALUE_REQUIRED,
                 'Country of the legal entity, ISO 3166-1 alpha-2 (e.g. cz, sk)',
             )
             ->addOption(
                 'module',
                 null,
                 InputOption::VALUE_REQUIRED,
                 'Install module id (e.g. install.base)',
                 'install.base',
             )
             ->addOption(
                 'ds-id',
                 null,
                 InputOption::VALUE_REQUIRED,
                 'Explicit data source ID (xxxx-xxxx-xxxx-xxxx) — used by the hosting provisioning agent',
             );
    }

    protected function getDataSourcesDir(): string
    {
        return '/opt/shipard/data-sources';
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

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getOption('name');

        if (empty($name)) {
            $output->writeln('<error>Option --name is required</error>');
            return Command::FAILURE;
        }

        // Layer A parameters (docs/ds-setup.md D1) — both mandatory, validated
        // before any mutation below (mkdir, createDatabase). Shape-only checks:
        // the world.base.countries cfgItem is not compiled yet at ds-create
        // time, so semantic validation belongs to the callers (hosting form,
        // dev dashboard).
        $language = (string) ($input->getOption('language') ?? '');
        if ($language === '') {
            $output->writeln('<error>Option --language is required (cs|en)</error>');
            return Command::FAILURE;
        }
        if (!in_array($language, ['cs', 'en'], true)) {
            $output->writeln('<error>Invalid --language: ' . $language . '</error>');
            $output->writeln('<comment>Must be one of: cs, en</comment>');
            return Command::FAILURE;
        }

        $country = (string) ($input->getOption('country') ?? '');
        if ($country === '') {
            $output->writeln('<error>Option --country is required (ISO 3166-1 alpha-2, e.g. cz)</error>');
            return Command::FAILURE;
        }
        if (!preg_match('/^[a-z]{2}$/', $country)) {
            $output->writeln('<error>Invalid --country: ' . $country . '</error>');
            $output->writeln('<comment>Must be two lower-case letters (ISO 3166-1 alpha-2, e.g. cz)</comment>');
            return Command::FAILURE;
        }

        $moduleId = (string) $input->getOption('module');

        if (!preg_match('/^install\.[a-z][a-zA-Z0-9]*$/', $moduleId)) {
            $output->writeln('<error>Invalid install module id: ' . $moduleId . '</error>');
            $output->writeln('<comment>Must match pattern: install.<name></comment>');
            return Command::FAILURE;
        }

        // Load server config first — needed by both the module-path resolver
        // (extraModulesPath) and DatabaseManager.
        $config = $this->serverConfig ?? new ServerConfig();
        try {
            $config->load();
        } catch (\RuntimeException $e) {
            $output->writeln('<error>Failed to load server config: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $resolver = $this->getModulePathResolver();
        $registry = new InstallModuleRegistry($resolver);
        if (!$registry->exists($moduleId)) {
            $output->writeln('<error>Install module not found: ' . $moduleId . '</error>');
            $available = array_map(fn($m) => $m['id'], $registry->list());
            if ($available) {
                $output->writeln('<comment>Available: ' . implode(', ', $available) . '</comment>');
            } else {
                $output->writeln('<comment>No install modules found in ' . $resolver->getRoots()[0] . '/install/</comment>');
            }
            return Command::FAILURE;
        }

        // Explicit ID from the hosting agent (D3), otherwise generate one.
        $dataSourcesDir = $this->getDataSourcesDir();
        $dsIdOption = $input->getOption('ds-id');
        if ($dsIdOption !== null && $dsIdOption !== '') {
            $id = (string) $dsIdOption;
            if (!preg_match(IdGenerator::ID_PATTERN, $id)) {
                $output->writeln('<error>Invalid --ds-id: ' . $id . '</error>');
                $output->writeln('<comment>Must match pattern: xxxx-xxxx-xxxx-xxxx (a-z0-9)</comment>');
                return Command::FAILURE;
            }
            if (is_dir($dataSourcesDir . '/' . $id)) {
                $output->writeln('<error>Data source directory already exists: ' . $id . '</error>');
                return Command::FAILURE;
            }
        } else {
            $generator = new IdGenerator();
            $id = $generator->generate($dataSourcesDir);
        }

        $dbName = IdGenerator::toDatabaseName($id);
        $dbUser = IdGenerator::toDatabaseUser($id);

        // Create directory structure
        $dataSourceDir = $dataSourcesDir . '/' . $id;
        $configDir = $dataSourceDir . '/config';

        if (!PermissionSpec::ensureDsDir($configDir)) {
            $output->writeln('<error>Failed to create data source directory</error>');
            return Command::FAILURE;
        }

        // Create database and user
        $dbManager = $this->databaseManager ?? new DatabaseManager($config);

        // Create writable directories for attachments, branding and cache —
        // with the PermissionSpec mode, without relying on a later
        // fix-permissions run.
        foreach (['att', 'branding', 'cache/thumbnails', 'cache/oidc'] as $subdir) {
            PermissionSpec::ensureDsDir($dataSourceDir . '/' . $subdir);
        }

        $password = $dbManager->generatePassword();

        try {
            $dbManager->createDatabase($dbName);
            $dbManager->createUser($dbUser, $password, $dbName);
        } catch (\Exception $e) {
            $output->writeln('<error>Failed to create database: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        // Write config file
        $mainConfig = [
            'id'                => $id,
            'name'              => $name,
            'modules'           => [$moduleId],
            'defaultLanguage'   => $language,
            'country'           => $country,
            'database_name'     => $dbName,
            'database_user'     => $dbUser,
            'database_password' => $password,
            'created'           => date('c'),
        ];

        $configFile = $configDir . '/main.json';
        file_put_contents($configFile, json_encode($mainConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        chmod($configFile, 0600);

        // Generate per-DS secrets key for encrypted_text columns
        try {
            $warnings = DsSecretCipher::generateKey($dataSourceDir);
            $warnings = array_merge($warnings, DsSecretCipher::healthCheck(new DataSourceConfig($dataSourceDir)));
            foreach ($warnings as $warning) {
                $output->writeln('<comment>  [WARN] ' . $warning . '</comment>');
            }
        } catch (\RuntimeException $e) {
            $output->writeln('<error>Failed to initialise secrets key: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        // Hosting provisioning runs ds-create as root — without this chown the
        // whole DS tree stays root:root and PHP-FPM (shipard user) can't write
        // uploads into att/branding/cache.
        if ($this->getEuid() === 0) {
            $shipardUser = PermissionSpec::detectShipardUser($config->getMode());
            if (posix_getpwnam($shipardUser) === false) {
                $output->writeln("<comment>  [WARN] User '{$shipardUser}' not found — skipping chown, run fix-permissions</comment>");
            } else {
                $this->chownRecursive($dataSourceDir, $shipardUser);
            }
        }

        // Output summary
        $output->writeln('');
        $output->writeln('<info>Data source created successfully</info>');
        $output->writeln('');
        $output->writeln("  ID:            <comment>{$id}</comment>");
        $output->writeln("  Name:          <comment>{$name}</comment>");
        $output->writeln("  Module:        <comment>{$moduleId}</comment>");
        $output->writeln("  Language:      <comment>{$language}</comment>");
        $output->writeln("  Country:       <comment>{$country}</comment>");
        $output->writeln("  Database:      <comment>{$dbName}</comment>");
        $output->writeln("  DB User:       <comment>{$dbUser}</comment>");
        $output->writeln("  Directory:     <comment>{$dataSourceDir}</comment>");
        $output->writeln('');

        return Command::SUCCESS;
    }

    protected function getEuid(): int
    {
        return posix_geteuid();
    }

    private function chownRecursive(string $path, string $user): void
    {
        @chown($path, $user);
        @chgrp($path, $user);
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $this->chownRecursive($path . '/' . $entry, $user);
            }
        }
    }
}

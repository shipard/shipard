<?php

declare(strict_types=1);

namespace Shipard\Command\Server;

use Shipard\Core\Ai\ServerAiConnection;
use Shipard\Core\Config\DataClass;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Config\DataSourceSecrets;
use Shipard\Core\Config\DataSourceState;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Database\DatabaseManager;
use Shipard\Core\Module\InstallModuleRegistry;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Security\DsSecretCipher;
use Shipard\Core\Security\SecretsFileWriter;
use Shipard\Core\Server\MainConfigPatcher;
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
             )
             ->addOption(
                 'no-cron',
                 null,
                 InputOption::VALUE_NONE,
                 'Create the data source with periodic jobs switched off (config/state.json cron: false) — for import targets',
             )
             ->addOption(
                 'real-copy',
                 null,
                 InputOption::VALUE_NONE,
                 'Mark the data source as a copy of real data (main.json dataClass realCopy) — for import targets; on a development server it then gets no server AI connection',
             )
             ->addOption(
                 'purpose',
                 null,
                 InputOption::VALUE_REQUIRED,
                 'Purpose of the data source (one line, max 200 characters) — shown by shpd-server ds-list',
             )
             ->addOption(
                 'no-server-ai',
                 null,
                 InputOption::VALUE_NONE,
                 'Deprecated — use --real-copy',
             );
    }

    protected function getDataSourcesDir(): string
    {
        return '/opt/shipard/data-sources';
    }

    protected function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }

    /** Server nabízí serverové AI připojení (režim development + uložený klíč) — jen pro souhrn. */
    protected function serverOffersAi(ServerConfig $config): bool
    {
        return ServerAiConnection::detect($config) !== null;
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

        // Účel zdroje dat (#126 D6) — validace před jakoukoli mutací; jediné
        // pravidlo je MainConfigPatcher::normalizePurpose (sdílí ho ds-class
        // i vývojářský dashboard). Prázdný účel = bez účelu.
        $purpose = null;
        $purposeOption = $input->getOption('purpose');
        if (is_string($purposeOption)) {
            try {
                $purpose = MainConfigPatcher::normalizePurpose($purposeOption);
            } catch (\InvalidArgumentException $e) {
                $output->writeln('<error>Invalid --purpose: ' . $e->getMessage() . '</error>');
                return Command::FAILURE;
            }
        }

        // Třída dat (#126 D7): --real-copy = kopie reálných dat (cíl importu).
        // --no-server-ai zůstává jedno vydání jako zastaralý alias (U3).
        $legacyNoServerAi = (bool) $input->getOption('no-server-ai');
        if ($legacyNoServerAi) {
            $output->writeln('<comment>--no-server-ai is deprecated — use --real-copy</comment>');
        }
        $realCopyRequested = (bool) $input->getOption('real-copy') || $legacyNoServerAi;

        // Load server config first — needed by both the module-path resolver
        // (extraModulesPath) and DatabaseManager.
        $config = $this->serverConfig ?? new ServerConfig();
        try {
            $config->load();
        } catch (\RuntimeException $e) {
            $output->writeln('<error>Failed to load server config: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        // Ve vývoji je nový zdroj dat free (ukázková data), s --real-copy
        // realCopy; v produkci vždy realCopy — tam jsou všechna data ostrá.
        $isDevelopment = $config->getMode() === ServerAiConnection::MODE_DEVELOPMENT;
        $dataClass = $isDevelopment && !$realCopyRequested ? DataClass::Free : DataClass::RealCopy;
        $origin = $realCopyRequested ? 'ds-create --real-copy' : 'ds-create';

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

        // Heslo k databázi patří do secrets/db.password, ne do main.json
        // (#126 D1, D2) — zapsat ještě před založením databáze: selhání
        // zápisu tak nenechá založeného uživatele bez hesla v souboru.
        try {
            $warnings = SecretsFileWriter::write($dataSourceDir, DataSourceSecrets::DB_PASSWORD, $password);
            foreach ($warnings as $warning) {
                $output->writeln('<comment>  [WARN] ' . $warning . '</comment>');
            }
        } catch (\RuntimeException $e) {
            $output->writeln('<error>Failed to write secrets/' . DataSourceSecrets::DB_PASSWORD . ': ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        try {
            $dbManager->createDatabase($dbName);
            $dbManager->createUser($dbUser, $password, $dbName);
        } catch (\Exception $e) {
            $output->writeln('<error>Failed to create database: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        // Write config file — bez tajemství (heslo je v secrets/db.password).
        $mainConfig = [
            'id'              => $id,
            'name'            => $name,
            'modules'         => [$moduleId],
            'defaultLanguage' => $language,
            'country'         => $country,
            'database_name'   => $dbName,
            'database_user'   => $dbUser,
            'created'         => date('c'),
        ];
        // Třída dat, původ a čas (#126 D6) — stejné čisté funkce jako
        // dataset-seed a ds-class; serverové AI připojení ve vývoji se z třídy
        // odvozuje (D9), žádný vlastní příznak se nezapisuje.
        $mainConfig = MainConfigPatcher::withDataClass($mainConfig, $dataClass, $origin, DataSourceState::formatUtc($this->now()));
        $mainConfig = MainConfigPatcher::withPurpose($mainConfig, $purpose);

        $configFile = $configDir . '/main.json';
        file_put_contents($configFile, json_encode($mainConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        chmod($configFile, 0600);

        // --no-cron (#115 D14): state.json hned po main.json, před chown níže.
        // Volba existuje, aby cíl importu nikdy neběžel se zapnutým cronem —
        // selhání zápisu je proto chyba příkazu, ne varování.
        $noCron = (bool) $input->getOption('no-cron');
        if ($noCron) {
            try {
                DataSourceState::active()->withCron(false)->save($dataSourceDir, 'ds-create');
            } catch (\RuntimeException $e) {
                $output->writeln('<error>Failed to write config/state.json: ' . $e->getMessage() . '</error>');
                $output->writeln('<comment>  Switch periodic jobs off manually: cd ' . $dataSourceDir . ' && shpd-ds ds-state cron --off</comment>');
                return Command::FAILURE;
            }
        }

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
        $output->writeln("  Data class:    <comment>{$dataClass->value}</comment>"
            . ($dataClass === DataClass::RealCopy ? ' (copy of real data — change with shpd-ds ds-class)' : ''));
        if ($purpose !== null && $purpose !== '') {
            $output->writeln("  Purpose:       <comment>{$purpose}</comment>");
        }
        if ($noCron) {
            $output->writeln('  Cron:          <comment>off</comment> (periodic jobs disabled — shpd-ds ds-state cron --on to enable)');
        } elseif ($realCopyRequested) {
            // D7: třída cron nemění — jen připomínka pro cíl importu.
            $output->writeln('  Cron:          <comment>on</comment> (a real copy usually runs without periodic jobs — --no-cron or shpd-ds ds-state cron --off)');
        }
        if ($isDevelopment) {
            if ($dataClass !== DataClass::Free) {
                $output->writeln('  Server AI:     <comment>off</comment> (realCopy)');
            } elseif ($this->serverOffersAi($config)) {
                $output->writeln('  Server AI:     <comment>on</comment> (free data source)');
            } else {
                $output->writeln('  Server AI:     <comment>off</comment> (no server AI key — shpd-server dev-ai-key)');
            }
        }
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

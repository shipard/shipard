<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Module\ModuleDefinition;
use Shipard\Core\Module\ModuleKind;
use Shipard\Core\Module\ModuleLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Module\ModuleResolver;
use Shipard\Core\Server\MainConfigFile;
use Shipard\Core\Server\MainConfigPatcher;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Moduly zdroje dat — přehled a zapínání doplňků (#170 D5, tasks/addon-modules.md).
 *
 *   ds-module [list] [--json]            přímé moduly, aktivní moduly s druhem,
 *                                        dostupné doplňky a moduly z dalších kořenů
 *   ds-module enable <id> [--no-upgrade]
 *   ds-module disable <id> [--no-upgrade]
 *
 * `enable` přijme jen doplněk (`kind: addon`) nebo modul z dalšího kořene
 * (`extraModulesPath`) — jádro přichází s instalačním modulem a ten se
 * nemění (U2). `disable` jen modul uvedený přímo v `modules`, mimo
 * `install.*`, který žádný jiný aktivní modul nepotřebuje. Tabulky vypnutého
 * modulu v databázi zůstávají (docs/modules.md §9).
 *
 * Po zápisu `config/main.json` běží `ds-upgrade` ve stejném procesu (U1):
 * jinak by `main.json` tvrdil aktivní modul bez tabulek a dispatcher cronu,
 * který aktivní moduly čte z `main.json`, by pouštěl jeho úlohy.
 * `--no-upgrade` je pro dávkové použití. Selže-li upgrade, `main.json`
 * zůstává změněný — opravit chybu a pustit `shpd-ds ds-upgrade`.
 *
 * Bez interaktivního potvrzení; zápis přes {@see MainConfigFile::patch}.
 */
class DsModuleCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('ds-module')
             ->setDescription('List modules of the data source and enable or disable add-ons: list [--json] | enable <id> | disable <id> [--no-upgrade]')
             ->addArgument('action', InputArgument::OPTIONAL, 'list | enable | disable', 'list')
             ->addArgument('id', InputArgument::OPTIONAL, 'Module id for enable / disable (<group>.<module>)')
             ->addOption('json', null, InputOption::VALUE_NONE, 'list: machine-readable output')
             ->addOption('no-upgrade', null, InputOption::VALUE_NONE, 'enable / disable: only write config/main.json, do not run ds-upgrade');
    }

    protected function getDataSourceDir(): string
    {
        return getcwd();
    }

    /** Kořeny modulů ze server.json; bez čitelného server.json jen hlavní strom (vzor bin/shpd-ds). */
    protected function getModulePathResolver(): ModulePathResolver
    {
        $mainModulesPath = dirname(__DIR__, 3) . '/modules';
        try {
            $cfg = new ServerConfig();
            $cfg->load();
        } catch (\Throwable) {
            return new ModulePathResolver([$mainModulesPath]);
        }
        return ModulePathResolver::fromServerConfig($cfg, $mainModulesPath);
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

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dsDir = $this->getDataSourceDir();
        if (!file_exists($dsDir . '/config/main.json')) {
            $output->writeln('<error>Error: Not a Shipard data source directory</error>');
            return Command::FAILURE;
        }

        try {
            $config = new DataSourceConfig($dsDir);
        } catch (\RuntimeException $e) {
            return $this->fail($output, 'config/main.json is unusable: ' . $e->getMessage());
        }

        try {
            $resolver = $this->getModulePathResolver();
            $definitions = [];
            foreach (ModuleLoader::loadAllModules($resolver) as $definition) {
                $definitions[$definition->id] = $definition;
            }
        } catch (\Throwable $e) {
            return $this->fail($output, 'Module definitions cannot be loaded: ' . $e->getMessage());
        }

        $direct = [];
        foreach ($config->getModules() as $moduleId) {
            if (is_string($moduleId) && !in_array($moduleId, $direct, true)) {
                $direct[] = $moduleId;
            }
        }

        $action = (string) $input->getArgument('action');
        if ($action === 'list') {
            return $this->runList($direct, $definitions, $resolver, $input, $output);
        }
        if ($action !== 'enable' && $action !== 'disable') {
            return $this->fail($output, "Unknown action '{$action}'. Use: list [--json] | enable <id> | disable <id> [--no-upgrade]");
        }

        $id = $input->getArgument('id');
        if (!is_string($id) || $id === '') {
            return $this->fail($output, "Usage: ds-module {$action} <id> [--no-upgrade]");
        }
        $noUpgrade = (bool) $input->getOption('no-upgrade');

        return $action === 'enable'
            ? $this->runEnable($id, $direct, $definitions, $resolver, $dsDir, $noUpgrade, $output)
            : $this->runDisable($id, $direct, $definitions, $dsDir, $noUpgrade, $output);
    }

    /**
     * @param list<string> $direct
     * @param array<string, ModuleDefinition> $definitions
     */
    private function runList(
        array $direct,
        array $definitions,
        ModulePathResolver $resolver,
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $errors = [];
        $active = $this->resolve($definitions, $direct, $errors);

        $activeRows = [];
        foreach ($active as $module) {
            $activeRows[] = [
                'id' => $module->id,
                'name' => $module->name,
                'kind' => $module->kind->value,
                'direct' => in_array($module->id, $direct, true),
                'extraRoot' => !$resolver->isInMainRoot($module->id),
            ];
        }
        usort($activeRows, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));

        // Dostupné = neaktivní a zapnutelné: doplňky, a moduly z dalších
        // kořenů (doplněk z dalšího kořene je jen mezi doplňky; instalační
        // modul zapnout nejde).
        $addons = [];
        $extra = [];
        ksort($definitions, SORT_STRING);
        foreach ($definitions as $id => $module) {
            if (isset($active[$id]) || $module->kind === ModuleKind::Install) {
                continue;
            }
            $row = ['id' => $id, 'name' => $module->name, 'description' => $module->description];
            if ($module->kind === ModuleKind::Addon) {
                $addons[] = $row + ['extraRoot' => !$resolver->isInMainRoot($id)];
            } elseif (!$resolver->isInMainRoot($id)) {
                $extra[] = $row;
            }
        }

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode([
                'direct' => $direct,
                'active' => $activeRows,
                'availableAddons' => $addons,
                'availableExtraModules' => $extra,
                'errors' => $errors,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }

        foreach ($errors as $error) {
            $output->writeln("<comment>  [WARN] {$error}</comment>");
        }

        $output->writeln('<comment>Direct modules (config/main.json):</comment>');
        foreach ($direct as $id) {
            $output->writeln("  <info>{$id}</info>");
        }
        if ($direct === []) {
            $output->writeln('  (none)');
        }

        $output->writeln('');
        $output->writeln(sprintf('<comment>Active modules (%d):</comment>', count($activeRows)));
        foreach ($activeRows as $row) {
            $notes = array_filter([$row['direct'] ? 'direct' : null, $row['extraRoot'] ? 'extra root' : null]);
            $output->writeln(rtrim(sprintf('  %-28s %-8s %s', $row['id'], $row['kind'], implode(', ', $notes))));
        }

        $output->writeln('');
        $output->writeln('<comment>Available add-ons:</comment>');
        $this->writeAvailable($output, $addons);

        $output->writeln('');
        $output->writeln('<comment>Available modules from extra roots:</comment>');
        $this->writeAvailable($output, $extra);

        if ($addons !== [] || $extra !== []) {
            $output->writeln('');
            $output->writeln('Enable with: shpd-ds ds-module enable <id>');
        }

        return Command::SUCCESS;
    }

    /** @param list<array{id: string, name: string}> $rows */
    private function writeAvailable(OutputInterface $output, array $rows): void
    {
        if ($rows === []) {
            $output->writeln('  (none)');
            return;
        }
        foreach ($rows as $row) {
            $output->writeln(sprintf('  <info>%-28s</info> %s', $row['id'], $row['name']));
        }
    }

    /**
     * @param list<string> $direct
     * @param array<string, ModuleDefinition> $definitions
     */
    private function runEnable(
        string $id,
        array $direct,
        array $definitions,
        ModulePathResolver $resolver,
        string $dsDir,
        bool $noUpgrade,
        OutputInterface $output,
    ): int {
        $module = $definitions[$id] ?? null;
        if ($module === null) {
            return $this->fail($output, "Module '{$id}' not found in any module root.");
        }
        if ($module->kind === ModuleKind::Install) {
            return $this->fail($output, "'{$id}' is an install module — it defines the data source and cannot be enabled as an add-on.");
        }
        if ($module->kind === ModuleKind::Core && $resolver->isInMainRoot($id)) {
            return $this->fail($output, "'{$id}' is a core module — core modules come with the install module and are not enabled separately. Only add-ons (kind: addon) and modules from extra roots can be enabled.");
        }

        $currentErrors = [];
        $active = $this->resolve($definitions, $direct, $currentErrors);
        if (isset($active[$id])) {
            $output->writeln(sprintf(
                '<comment>unchanged</comment> — module %s is already %s.',
                $id,
                in_array($id, $direct, true) ? 'enabled' : 'active as a dependency of another module',
            ));
            return Command::SUCCESS;
        }

        // Nový seznam musí projít resolverem — chybějící závislost nebo
        // cyklus = chyba a nic se nezapíše.
        $errors = [];
        $this->resolve($definitions, [...$direct, $id], $errors);
        if ($errors !== []) {
            $output->writeln("<error>Cannot enable '{$id}' — the module list would not resolve; config/main.json is unchanged:</error>");
            foreach ($errors as $error) {
                $output->writeln("<error>  {$error}</error>");
            }
            return Command::FAILURE;
        }

        try {
            MainConfigFile::patch($dsDir, static function (array $raw) use ($id): array {
                $modules = self::rawModules($raw);
                if (!in_array($id, $modules, true)) {
                    $modules[] = $id;
                }
                return MainConfigPatcher::withModules($raw, $modules);
            });
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            return $this->fail($output, $e->getMessage());
        }

        $output->writeln("Module <info>{$id}</info> enabled (config/main.json).");
        return $this->upgrade($id, $noUpgrade, $output);
    }

    /**
     * @param list<string> $direct
     * @param array<string, ModuleDefinition> $definitions
     */
    private function runDisable(
        string $id,
        array $direct,
        array $definitions,
        string $dsDir,
        bool $noUpgrade,
        OutputInterface $output,
    ): int {
        if (str_starts_with($id, 'install.')) {
            return $this->fail($output, "'{$id}' is an install module — it defines the data source and cannot be disabled.");
        }

        if (!in_array($id, $direct, true)) {
            $errors = [];
            $active = $this->resolve($definitions, $direct, $errors);
            if (isset($active[$id])) {
                return $this->fail($output, sprintf(
                    "Module '%s' is not enabled directly — it is active as a dependency of %s and cannot be disabled.",
                    $id,
                    implode(', ', $this->dependentsOf($id, $active)),
                ));
            }
            return $this->fail($output, "Module '{$id}' is not enabled in this data source (not listed in config/main.json modules).");
        }

        $remaining = array_values(array_filter($direct, static fn (string $m): bool => $m !== $id));
        $errors = [];
        $stillActive = $this->resolve($definitions, $remaining, $errors);
        if (isset($stillActive[$id])) {
            return $this->fail($output, sprintf(
                "Module '%s' cannot be disabled — it would stay active, it is required by: %s.",
                $id,
                implode(', ', $this->dependentsOf($id, $stillActive)),
            ));
        }

        try {
            MainConfigFile::patch($dsDir, static function (array $raw) use ($id): array {
                $modules = array_values(array_filter(self::rawModules($raw), static fn (mixed $m): bool => $m !== $id));
                return MainConfigPatcher::withModules($raw, $modules);
            });
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            return $this->fail($output, $e->getMessage());
        }

        $output->writeln("Module <info>{$id}</info> disabled (config/main.json).");
        $output->writeln('Its database tables are kept — disabling a module never drops data (docs/modules.md §9).');
        return $this->upgrade($id, $noUpgrade, $output);
    }

    private function upgrade(string $id, bool $noUpgrade, OutputInterface $output): int
    {
        if ($noUpgrade) {
            $output->writeln('<comment>Schema and configuration are not upgraded yet (--no-upgrade). Run: shpd-ds ds-upgrade</comment>');
            return Command::SUCCESS;
        }

        if ($this->runUpgrade($output) !== Command::SUCCESS) {
            $output->writeln("<error>ds-upgrade failed — config/main.json is already changed ('{$id}'). Fix the error above and run: shpd-ds ds-upgrade</error>");
            return Command::FAILURE;
        }
        return Command::SUCCESS;
    }

    /**
     * Seznam `modules` ze surového `main.json` v původním pořadí; opakované
     * ID se sloučí do prvního výskytu (jiný typ nechá odmítnout patcher).
     *
     * @param array<string, mixed> $raw
     * @return list<mixed>
     */
    private static function rawModules(array $raw): array
    {
        $modules = [];
        foreach (is_array($raw['modules'] ?? null) ? $raw['modules'] : [] as $moduleId) {
            if (!is_string($moduleId) || !in_array($moduleId, $modules, true)) {
                $modules[] = $moduleId;
            }
        }
        return $modules;
    }

    /**
     * Aktivní moduly pro daný seznam přímých modulů, klíč = ID.
     *
     * @param array<string, ModuleDefinition> $definitions
     * @param list<string> $direct
     * @param list<string> $errors chyby resolveru (neznámý modul, cyklus)
     * @return array<string, ModuleDefinition>
     */
    private function resolve(array $definitions, array $direct, array &$errors): array
    {
        $active = [];
        foreach (ModuleResolver::resolve(array_values($definitions), $direct, $errors) as $module) {
            $active[$module->id] = $module;
        }
        return $active;
    }

    /**
     * Aktivní moduly, které modul přímo potřebují.
     *
     * @param array<string, ModuleDefinition> $active
     * @return list<string>
     */
    private function dependentsOf(string $id, array $active): array
    {
        $dependents = [];
        foreach ($active as $module) {
            if (in_array($id, $module->dependencies, true)) {
                $dependents[] = $module->id;
            }
        }
        sort($dependents, SORT_STRING);
        return $dependents;
    }

    private function fail(OutputInterface $output, string $message): int
    {
        $output->writeln("<error>{$message}</error>");
        return Command::FAILURE;
    }
}

<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Module\ModuleLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Module\ModuleResolver;
use Shipard\Core\Settings\LayerCParameters;
use Shipard\Core\Settings\SettingsStore;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI přístup ke klíčům `core_system_settings` (scope ds).
 *
 * Jediná cesta k parametrům vrstvy C (docs/ds-setup.md §5.2), dokud
 * neexistuje průvodce/settings stránka (Fáze 4). `set` drží whitelist
 * deklarovaných klíčů — překlep by založil klíč, který nikdo nečte.
 *
 * `list` vypisuje hodnoty na stdout — v core_system_settings dnes nejsou
 * žádná tajemství (app.*, hosting.baseDomain, hosting.oidc.issuer,
 * mail.defaultFrom, branding metadata); secrets žijí v encrypted_text
 * sloupcích vlastních tabulek. Kdyby sem někdy citlivý klíč přibyl,
 * musí `list` dostat maskování.
 */
class DsSettingCommand extends Command
{
    /** Field typy settings stránek, které nesou JSON metadata spravovaná aplikací. */
    private const STRUCTURED_FIELD_TYPES = ['image', 'avatar', 'theme', 'shell'];

    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
        private readonly ?ServerConfig $serverConfig = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('ds-setting')
             ->setDescription('Read and write data source settings (core_system_settings)')
             ->addArgument('action', InputArgument::REQUIRED, 'list | get | set')
             ->addArgument('key', InputArgument::OPTIONAL, 'Setting key (e.g. economy.accountChart)')
             ->addArgument('value', InputArgument::OPTIONAL, 'Value to set')
             ->addOption('unset', null, InputOption::VALUE_NONE, 'Delete the key (with action set)');
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

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dsDir = $this->getDataSourceDir();

        if ($this->dsConfig === null && !file_exists($dsDir . '/config/main.json')) {
            $output->writeln('<error>Error: Not a Shipard data source directory</error>');
            return Command::FAILURE;
        }

        $dsConfig = $this->dsConfig ?? new DataSourceConfig($dsDir);
        $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);
        $settings = new SettingsStore($dsConnection);

        $action = (string) $input->getArgument('action');
        $key = $input->getArgument('key');

        return match ($action) {
            'list' => $this->runList($dsConnection, $output),
            'get' => $this->runGet($settings, $key, $output),
            'set' => $this->runSet($dsConfig, $settings, $key, $input, $output),
            default => $this->fail($output, "Unknown action '{$action}'. Use: list | get | set"),
        };
    }

    private function runList(DataSourceConnection $dsConnection, OutputInterface $output): int
    {
        $rows = $dsConnection->fetchAll(
            'SELECT `key`, `value` FROM `core_system_settings` ORDER BY `key` ASC',
        );

        if ($rows === []) {
            $output->writeln('<comment>No settings stored.</comment>');
            return Command::SUCCESS;
        }

        foreach ($rows as $row) {
            $output->writeln(sprintf('  %-36s %s', (string) $row['key'], (string) $row['value']));
        }
        return Command::SUCCESS;
    }

    private function runGet(SettingsStore $settings, ?string $key, OutputInterface $output): int
    {
        if ($key === null || $key === '') {
            return $this->fail($output, 'Usage: ds-setting get <key>');
        }

        // set($key, null) klíč maže, takže null = klíč neexistuje.
        $value = $settings->get($key);
        if ($value === null) {
            $output->writeln("<error>Setting '{$key}' is not set.</error>");
            return Command::FAILURE;
        }

        $output->writeln($this->formatValue($value));
        return Command::SUCCESS;
    }

    private function runSet(
        DataSourceConfig $dsConfig,
        SettingsStore $settings,
        ?string $key,
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $unset = (bool) $input->getOption('unset');
        $raw = $input->getArgument('value');

        if ($key === null || $key === '') {
            return $this->fail($output, 'Usage: ds-setting set <key> <value> | ds-setting set <key> --unset');
        }
        if ($unset && $raw !== null) {
            return $this->fail($output, 'Use either a value or --unset, not both.');
        }
        if (!$unset && ($raw === null || $raw === '')) {
            return $this->fail($output, 'Usage: ds-setting set <key> <value> | ds-setting set <key> --unset');
        }

        $declared = $this->collectDeclaredKeys($dsConfig);
        if (!array_key_exists($key, $declared)) {
            $output->writeln("<error>Unknown setting key: {$key}</error>");
            $output->writeln('<comment>Allowed keys:</comment>');
            foreach (array_keys($declared) as $allowedKey) {
                $output->writeln('  ' . $allowedKey);
            }
            return Command::FAILURE;
        }

        if ($unset) {
            $settings->set($key, null);
            $output->writeln("Setting '{$key}' removed.");
            return Command::SUCCESS;
        }

        $fieldType = $declared[$key];
        if (in_array($fieldType, self::STRUCTURED_FIELD_TYPES, true)) {
            return $this->fail(
                $output,
                "Setting '{$key}' holds structured data managed by the application"
                . " (field type '{$fieldType}') — set it via the app UI, not the CLI.",
            );
        }

        if (isset(LayerCParameters::SPECS[$key])) {
            try {
                $value = LayerCParameters::validate($key, (string) $raw);
            } catch (\InvalidArgumentException $e) {
                return $this->fail($output, $e->getMessage());
            }
        } elseif ($fieldType === 'checkbox') {
            // Zaškrtávátko stránky nastavení drží boolean — řetězec "false"
            // by čtenář bral jako zapnuto.
            $value = match ((string) $raw) {
                'true'  => true,
                'false' => false,
                default => null,
            };
            if ($value === null) {
                return $this->fail($output, "Setting '{$key}' is a checkbox — allowed values: true, false.");
            }
        } else {
            // Ostatní klíče mimo vrstvu C se neinterpretují — ukládá se
            // string tak, jak přišel.
            $value = (string) $raw;
        }

        $settings->set($key, $value);
        $output->writeln("Setting '{$key}' = " . $this->formatValue($value));
        return Command::SUCCESS;
    }

    /**
     * Whitelist klíčů: parametry vrstvy C + field id settings stránek
     * scope `ds` z resolvovaných modulů DS. Mapa klíč => field type
     * (vrstva C bez stránky má typ 'text').
     *
     * @return array<string, string>
     */
    private function collectDeclaredKeys(DataSourceConfig $dsConfig): array
    {
        $declared = [];
        foreach (LayerCParameters::keys() as $key) {
            $declared[$key] = 'text';
        }

        $allModules = ModuleLoader::loadAllModules($this->getModulePathResolver());
        $errors = [];
        $resolvedModules = ModuleResolver::resolve($allModules, $dsConfig->getModules(), $errors);

        foreach ($resolvedModules as $module) {
            foreach ($module->settingsPages as $page) {
                if (($page['scope'] ?? 'ds') !== 'ds') {
                    continue;
                }
                foreach ($page['fields'] as $field) {
                    $declared[(string) $field['id']] = (string) ($field['type'] ?? 'text');
                }
            }
        }

        ksort($declared);
        return $declared;
    }

    private function formatValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function fail(OutputInterface $output, string $message): int
    {
        $output->writeln('<error>' . $message . '</error>');
        return Command::FAILURE;
    }
}

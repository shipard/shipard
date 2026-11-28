<?php

declare(strict_types=1);

namespace Shipard\Cli;

use Shipard\Core\Server\DataSourceModules;
use Shipard\Core\Version;
use Symfony\Component\Console\Application;

/**
 * Registrace příkazů shpd-ds — jediný zdroj pravdy pro binárku i testy
 * (drift test nápovědy staví Application odsud, ne z bin skriptu).
 */
final class DsApplicationFactory
{
    /** Varování, když moduly zdroje dat nejde určit (#172 D2). */
    public const string MODULES_UNKNOWN_WARNING = 'modules of the data source cannot be determined — offering commands of all modules; see shipard.log';

    /**
     * Příkazy jádra + příkazy modulů (`commands` v `module.jsonc`, #172 D2):
     *
     *  - aktuální adresář je zdroj dat (`config/main.json`) → příkazy modulů
     *    aktivních ve zdroji dat; moduly nejde určit → příkazy všech modulů
     *    a jeden řádek varování na chybový výstup;
     *  - mimo zdroj dat → jen jádro.
     *
     * Jméno příkazu modulu, který registrovaný není, končí chybou D4
     * ({@see ShipardApplication}).
     *
     * @param list<string>|null $activeModules aktivní moduly místo zjišťování
     *     z aktuálního adresáře (pro testy; `[]` = jen jádro)
     * @param DataSourceModules|null $modules zdroj definic modulů (testy,
     *     `bin/shpd-ds` předává resolver, který už má)
     */
    public static function create(?array $activeModules = null, ?DataSourceModules $modules = null): Application
    {
        $app = new ShipardApplication('Shipard Data Source Tool', Version::VERSION);
        $app->add(new \Shipard\Command\DataSource\VersionCommand());
        $help = new \Shipard\Command\DataSource\HelpCommand();
        $app->add($help);
        $app->add(new \Shipard\Command\DataSource\DsUpgradeCommand());
        $app->add(new \Shipard\Command\DataSource\DsResetCommand());
        $app->add(new \Shipard\Command\DataSource\DsSettingCommand());
        $app->add(new \Shipard\Command\DataSource\DsStateCommand());
        $app->add(new \Shipard\Command\DataSource\DsClassCommand());
        $app->add(new \Shipard\Command\DataSource\DsModuleCommand());
        $app->add(new \Shipard\Command\DataSource\UserCreateCommand());
        $app->add(new \Shipard\Command\DataSource\UserSetAdminCommand());
        $app->add(new \Shipard\Command\DataSource\AuthEmergencyLoginCommand());
        $app->add(new \Shipard\Command\DataSource\ApiKeyCreateCommand());
        $app->add(new \Shipard\Command\DataSource\ApiKeyListCommand());
        $app->add(new \Shipard\Command\DataSource\ApiKeyRevokeCommand());
        $app->add(new \Shipard\Command\DataSource\DatasetDumpCommand());
        $app->add(new \Shipard\Command\DataSource\DatasetSeedCommand());
        $app->add(new \Shipard\Command\DataSource\DatasetVerifyCommand());
        $app->add(new \Shipard\Command\DataSource\DatasetE2eCommand());
        $app->add(new \Shipard\Command\DataSource\VideoRunCommand());
        $app->add(new \Shipard\Command\DataSource\ReportRunCommand());
        $app->add(new \Shipard\Command\DataSource\ReportDiffCommand());
        $app->add(new \Shipard\Command\DataSource\PrintRunCommand());
        $app->add(new \Shipard\Command\DataSource\PrintEscposTestCommand());
        $app->add(new \Shipard\Command\DataSource\DsSecretCommand());
        $app->add(new \Shipard\Command\DataSource\DsSecretsHealthCommand());
        $app->add(new \Shipard\Command\DataSource\DsSecretsRotateCommand());
        $app->add(new \Shipard\Command\DataSource\HostingStatsCommand());
        $help->setModuleGroups(self::addModuleCommands($app, $activeModules, $modules ?? new DataSourceModules()));
        $app->setDefaultCommand('help');
        return $app;
    }

    /**
     * @param list<string>|null $activeModules
     * @return list<ModuleCommandGroup> registrované příkazy po modulech (pro nápovědu)
     */
    private static function addModuleCommands(ShipardApplication $app, ?array $activeModules, DataSourceModules $modules): array
    {
        $dsDir = (string) getcwd();
        $moduleCommands = new ModuleCommands($modules);

        // Mimo zdroj dat jen jádro; definice modulů se nenačtou, dokud
        // někdo nezadá příkaz, který jádro nezná (D4).
        if ($activeModules === null && !is_file($dsDir . '/config/main.json')) {
            $app->setModuleCommands($moduleCommands, false);
            return [];
        }

        $all = false;
        if ($activeModules === null) {
            $activeModules = $modules->activeIds($dsDir);
            $all = $activeModules === null;
        }

        $groups = $moduleCommands->dsGroups($all ? null : $activeModules);
        foreach ($groups as $group) {
            foreach ($group->commands as $command) {
                $app->add($command);
            }
        }
        if ($all) {
            $app->setStartupWarning('<comment>' . self::MODULES_UNKNOWN_WARNING . '</comment>');
        }
        $app->setModuleCommands($moduleCommands, true);
        return $groups;
    }
}

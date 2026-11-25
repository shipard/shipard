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
        $app->add(new \Shipard\Command\DataSource\HelpCommand());
        $app->add(new \Shipard\Command\DataSource\DsUpgradeCommand());
        $app->add(new \Shipard\Command\DataSource\DsResetCommand());
        $app->add(new \Shipard\Command\DataSource\DsSettingCommand());
        $app->add(new \Shipard\Command\DataSource\DsStateCommand());
        $app->add(new \Shipard\Command\DataSource\DsClassCommand());
        $app->add(new \Shipard\Command\DataSource\DsModuleCommand());
        $app->add(new \Shipard\Command\DataSource\ValuesNormalizeCommand());
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
        $app->add(new \Shipard\Command\DataSource\MailRouterBootstrapCommand());
        $app->add(new \Shipard\Command\DataSource\MailRouterSetupCommand());
        $app->add(new \Shipard\Command\DataSource\MailIdempotencyPruneCommand());
        $app->add(new \Shipard\Command\DataSource\MailOutboxRunCommand());
        $app->add(new \Shipard\Command\DataSource\MailOutboxRetryCommand());
        $app->add(new \Shipard\Command\DataSource\MailSendTestCommand());
        $app->add(new \Shipard\Command\DataSource\AiBackendSetKeyCommand());
        $app->add(new \Shipard\Command\DataSource\AiProfileReloadCommand());
        $app->add(new \Shipard\Command\DataSource\AiTestCommand());
        $app->add(new \Shipard\Command\DataSource\AiEvalCommand());
        $app->add(new \Shipard\Command\DataSource\AiEvalCompareCommand());
        $app->add(new \Shipard\Command\DataSource\MailAnalysisReapCommand());
        $app->add(new \Shipard\Command\DataSource\MailPreprocessCommand());
        $app->add(new \Shipard\Command\DataSource\MailAnalyzeCommand());
        $app->add(new \Shipard\Command\DataSource\MailTargetBackfillCommand());
        $app->add(new \Shipard\Command\DataSource\RegistryExtractTextsCommand());
        $app->add(new \Shipard\Command\DataSource\AlertsRunCommand());
        $app->add(new \Shipard\Command\DataSource\AlertsPruneCommand());
        $app->add(new \Shipard\Command\DataSource\BankImportStatementCommand());
        $app->add(new \Shipard\Command\DataSource\AccbalMatchCommand());
        $app->add(new \Shipard\Command\DataSource\AccbalRegenerateCommand());
        $app->add(new \Shipard\Command\DataSource\BookingHistoryCommand());
        $app->add(new \Shipard\Command\DataSource\ReportRunCommand());
        $app->add(new \Shipard\Command\DataSource\ReportDiffCommand());
        $app->add(new \Shipard\Command\DataSource\PrintRunCommand());
        $app->add(new \Shipard\Command\DataSource\PrintSendCommand());
        $app->add(new \Shipard\Command\DataSource\VatPeriodsEnsureCommand());
        $app->add(new \Shipard\Command\DataSource\VatFilingComposeCommand());
        $app->add(new \Shipard\Command\DataSource\VatFilingFilesCommand());
        $app->add(new \Shipard\Command\DataSource\VatFilingXmlDiffCommand());
        $app->add(new \Shipard\Command\DataSource\VatFilingAccountCommand());
        $app->add(new \Shipard\Command\DataSource\VatFilingImportCommand());
        $app->add(new \Shipard\Command\DataSource\DocReaccountCommand());
        $app->add(new \Shipard\Command\DataSource\AssetsImportVerifyCommand());
        $app->add(new \Shipard\Command\DataSource\WorkOrdersInvoiceRunCommand());
        $app->add(new \Shipard\Command\DataSource\ExchangeRatesImportCommand());
        $app->add(new \Shipard\Command\DataSource\ExchangeRateCommand());
        $app->add(new \Shipard\Command\DataSource\ExchangeRatesDownloadCommand());
        $app->add(new \Shipard\Command\DataSource\DsSecretCommand());
        $app->add(new \Shipard\Command\DataSource\DsSecretsHealthCommand());
        $app->add(new \Shipard\Command\DataSource\DsSecretsRotateCommand());
        $app->add(new \Shipard\Command\DataSource\HostingOidcInitCommand());
        $app->add(new \Shipard\Command\DataSource\HostingOidcClientCommand());
        $app->add(new \Shipard\Command\DataSource\HostingServerKeyCommand());
        $app->add(new \Shipard\Command\DataSource\HostingRouterKeyCommand());
        $app->add(new \Shipard\Command\DataSource\HostingAiGwInitCommand());
        $app->add(new \Shipard\Command\DataSource\HostingAiTokenCommand());
        $app->add(new \Shipard\Command\DataSource\HostingStatsCommand());
        self::addModuleCommands($app, $activeModules, $modules ?? new DataSourceModules());
        $app->setDefaultCommand('help');
        return $app;
    }

    /** @param list<string>|null $activeModules */
    private static function addModuleCommands(ShipardApplication $app, ?array $activeModules, DataSourceModules $modules): void
    {
        $dsDir = (string) getcwd();
        $moduleCommands = new ModuleCommands($modules);

        // Mimo zdroj dat jen jádro; definice modulů se nenačtou, dokud
        // někdo nezadá příkaz, který jádro nezná (D4).
        if ($activeModules === null && !is_file($dsDir . '/config/main.json')) {
            $app->setModuleCommands($moduleCommands, false);
            return;
        }

        $all = false;
        if ($activeModules === null) {
            $activeModules = $modules->activeIds($dsDir);
            $all = $activeModules === null;
        }

        foreach ($moduleCommands->dsGroups($all ? null : $activeModules) as $group) {
            foreach ($group->commands as $command) {
                $app->add($command);
            }
        }
        if ($all) {
            $app->setStartupWarning('<comment>' . self::MODULES_UNKNOWN_WARNING . '</comment>');
        }
        $app->setModuleCommands($moduleCommands, true);
    }
}

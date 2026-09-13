<?php

declare(strict_types=1);

namespace Shipard\Cli;

use Shipard\Core\Version;
use Symfony\Component\Console\Application;

/**
 * Registrace příkazů shpd-ds — jediný zdroj pravdy pro binárku i testy
 * (drift test nápovědy staví Application odsud, ne z bin skriptu).
 */
final class DsApplicationFactory
{
    public static function create(): Application
    {
        $app = new Application('Shipard Data Source Tool', Version::VERSION);
        $app->add(new \Shipard\Command\DataSource\VersionCommand());
        $app->add(new \Shipard\Command\DataSource\HelpCommand());
        $app->add(new \Shipard\Command\DataSource\DsUpgradeCommand());
        $app->add(new \Shipard\Command\DataSource\DsResetCommand());
        $app->add(new \Shipard\Command\DataSource\DsSettingCommand());
        $app->add(new \Shipard\Command\DataSource\DsStateCommand());
        $app->add(new \Shipard\Command\DataSource\UserCreateCommand());
        $app->add(new \Shipard\Command\DataSource\UserSetAdminCommand());
        $app->add(new \Shipard\Command\DataSource\AuthEmergencyLoginCommand());
        $app->add(new \Shipard\Command\DataSource\ApiKeyCreateCommand());
        $app->add(new \Shipard\Command\DataSource\ApiKeyListCommand());
        $app->add(new \Shipard\Command\DataSource\ApiKeyRevokeCommand());
        $app->add(new \Shipard\Command\DataSource\DatasetDumpCommand());
        $app->add(new \Shipard\Command\DataSource\DatasetSeedCommand());
        $app->add(new \Shipard\Command\DataSource\SeedPersonsCommand());
        $app->add(new \Shipard\Command\DataSource\SeedClearCommand());
        $app->add(new \Shipard\Command\DataSource\SeedMailCommand());
        $app->add(new \Shipard\Command\DataSource\SeedMailClearCommand());
        $app->add(new \Shipard\Command\DataSource\MailRouterBootstrapCommand());
        $app->add(new \Shipard\Command\DataSource\MailRouterSetupCommand());
        $app->add(new \Shipard\Command\DataSource\MailIdempotencyPruneCommand());
        $app->add(new \Shipard\Command\DataSource\MailOutboxRunCommand());
        $app->add(new \Shipard\Command\DataSource\MailOutboxRetryCommand());
        $app->add(new \Shipard\Command\DataSource\MailSendTestCommand());
        $app->add(new \Shipard\Command\DataSource\AiAnalyzerBootstrapCommand());
        $app->add(new \Shipard\Command\DataSource\AiAnalyzerSetupCommand());
        $app->add(new \Shipard\Command\DataSource\AiAnalyzerSetKeyCommand());
        $app->add(new \Shipard\Command\DataSource\AiProfileReloadCommand());
        $app->add(new \Shipard\Command\DataSource\MailAnalysisReapCommand());
        $app->add(new \Shipard\Command\DataSource\MailPreprocessCommand());
        $app->add(new \Shipard\Command\DataSource\MailTargetBackfillCommand());
        $app->add(new \Shipard\Command\DataSource\RegistryExtractTextsCommand());
        $app->add(new \Shipard\Command\DataSource\AlertsRunCommand());
        $app->add(new \Shipard\Command\DataSource\AlertsPruneCommand());
        $app->add(new \Shipard\Command\DataSource\BankImportStatementCommand());
        $app->add(new \Shipard\Command\DataSource\AccbalMatchCommand());
        $app->add(new \Shipard\Command\DataSource\BookingHistoryCommand());
        $app->add(new \Shipard\Command\DataSource\ReportRunCommand());
        $app->add(new \Shipard\Command\DataSource\ReportDiffCommand());
        $app->add(new \Shipard\Command\DataSource\VatPeriodsEnsureCommand());
        $app->add(new \Shipard\Command\DataSource\VatFilingComposeCommand());
        $app->add(new \Shipard\Command\DataSource\VatFilingFilesCommand());
        $app->add(new \Shipard\Command\DataSource\VatFilingXmlDiffCommand());
        $app->add(new \Shipard\Command\DataSource\VatFilingAccountCommand());
        $app->add(new \Shipard\Command\DataSource\VatFilingImportCommand());
        $app->add(new \Shipard\Command\DataSource\DocReaccountCommand());
        $app->add(new \Shipard\Command\DataSource\DsSecretsHealthCommand());
        $app->add(new \Shipard\Command\DataSource\DsSecretsRotateCommand());
        $app->add(new \Shipard\Command\DataSource\HostingOidcInitCommand());
        $app->add(new \Shipard\Command\DataSource\HostingOidcClientCommand());
        $app->add(new \Shipard\Command\DataSource\HostingServerKeyCommand());
        $app->add(new \Shipard\Command\DataSource\HostingRouterKeyCommand());
        $app->add(new \Shipard\Command\DataSource\HostingAnalyzerKeyCommand());
        $app->add(new \Shipard\Command\DataSource\HostingAiGwInitCommand());
        $app->add(new \Shipard\Command\DataSource\HostingAiTokenCommand());
        $app->add(new \Shipard\Command\DataSource\HostingStatsCommand());
        $app->setDefaultCommand('help');
        return $app;
    }
}

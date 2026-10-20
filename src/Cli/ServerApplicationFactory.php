<?php

declare(strict_types=1);

namespace Shipard\Cli;

use Shipard\Core\Version;
use Symfony\Component\Console\Application;

/**
 * Registrace příkazů shpd-server — jediný zdroj pravdy pro binárku i testy
 * (drift test nápovědy staví Application odsud, ne z bin skriptu).
 */
final class ServerApplicationFactory
{
    public static function create(): Application
    {
        $app = new Application('Shipard Server Management', Version::VERSION);
        $app->add(new \Shipard\Command\Server\VersionCommand());
        $app->add(new \Shipard\Command\Server\HelpCommand());
        $app->add(new \Shipard\Command\Server\ServerInitCommand());
        $app->add(new \Shipard\Command\Server\DsCreateCommand());
        $app->add(new \Shipard\Command\Server\DsDeleteCommand());
        $app->add(new \Shipard\Command\Server\DsUpgradeAllCommand());
        $app->add(new \Shipard\Command\Server\UpgradeCommand());
        $app->add(new \Shipard\Command\Server\NextTableIdCommand());
        $app->add(new \Shipard\Command\Server\EInvoiceRenderCommand());
        $app->add(new \Shipard\Command\Server\DomainAddCommand());
        $app->add(new \Shipard\Command\Server\DomainListCommand());
        $app->add(new \Shipard\Command\Server\DomainRemoveCommand());
        $app->add(new \Shipard\Command\Server\CronCommand());
        $app->add(new \Shipard\Command\Server\CronInstallCommand());
        $app->add(new \Shipard\Command\Server\CompletionInstallCommand());
        $app->add(new \Shipard\Command\Server\HostingSyncCommand());
        $app->add(new \Shipard\Command\Server\DsStateCheckCommand());
        $app->add(new \Shipard\Command\Server\DsCronCommand());
        $app->add(new \Shipard\Command\Server\DsListCommand());
        $app->add(new \Shipard\Command\Server\DevAiKeyCommand());
        $app->add(new \Shipard\Command\Server\DevJobRunCommand());
        $app->add(new \Shipard\Command\Server\DevUpdateCheckCommand());
        $app->add(new \Shipard\Command\Server\DevUpdateCommand());
        $app->add(new \Shipard\Command\Server\DoctorCommand());
        $app->add(new \Shipard\Command\Server\FixPermissionsCommand());
        $app->setDefaultCommand('help');
        return $app;
    }
}

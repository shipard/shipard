<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use Shipard\Cli\DsApplicationFactory;
use Shipard\Cli\ModuleCommands;
use Shipard\Cli\ServerApplicationFactory;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Server\DataSourceModules;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Hlídá nápovědu proti registraci ve factories (#172 D5): příkazy jádra jsou
 * v `HelpCommand` psané ručně, příkazy modulů se generují po modulech
 * z popisu příkazu. Každý viditelný registrovaný příkaz musí být v nápovědě,
 * příkaz modulu, který registrovaný není, v ní být nesmí.
 */
class HelpDriftTest extends TestCase
{
    /** Symfony built-ins, které v ručním helpu záměrně nejsou. */
    private const IGNORED = ['help', 'list', 'completion', '_complete'];

    /** Moduly zdroje dat hostingu (`install.hosting` rozvinutý resolverem). */
    private const HOSTING_MODULES = ['core.system', 'core.alerts', 'world.base', 'hosting.core', 'install.hosting'];

    public function testDsHelpListsAllCoreCommands(): void
    {
        $this->inDataSourceDir(function (): void {
            $app = DsApplicationFactory::create([], self::modules());
            $display = $this->assertHelpCoversCommands($app, 'shpd-ds');

            // Ruční část nezná žádný příkaz modulu — ty jsou jen generované.
            $this->assertStringNotContainsString('Modules:', $display);
            $this->assertSame([], $this->listedAmong($display, $this->moduleCommandNames(self::allModuleIds())));
        });
    }

    public function testDsHelpListsCommandsOfActiveModulesOnly(): void
    {
        $this->inDataSourceDir(function (): void {
            $app = DsApplicationFactory::create(self::HOSTING_MODULES, self::modules());
            $display = $this->assertHelpCoversCommands($app, 'shpd-ds');

            $this->assertStringContainsString('Modules:', $display);
            $this->assertStringContainsString('core.alerts — Alerts', $display);
            $this->assertStringContainsString('hosting.core — Hosting', $display);
            $this->assertMatchesRegularExpression('/^\s+alerts-run\s+Run due alert checks/m', $display, 'řádek = jméno a popis příkazu');

            $active = $this->moduleCommandNames(self::HOSTING_MODULES);
            $this->assertContains('hosting-oidc-init', $active);
            $this->assertContains('alerts-run', $active);

            $inactive = array_values(array_diff($this->moduleCommandNames(self::allModuleIds()), $active));
            $this->assertContains('vat-periods-ensure', $inactive);
            $this->assertContains('mail-outbox-run', $inactive);
            $this->assertSame([], $this->listedAmong($display, $inactive), 'příkazy neaktivních modulů v nápovědě nejsou');
        });
    }

    public function testDsHelpListsCommandsOfAllModules(): void
    {
        $this->inDataSourceDir(function (): void {
            $app = DsApplicationFactory::create(self::allModuleIds(), self::modules());
            $display = $this->assertHelpCoversCommands($app, 'shpd-ds');

            $this->assertSame(
                [],
                array_values(array_diff($this->moduleCommandNames(self::allModuleIds()), $this->listedAmong($display, $this->moduleCommandNames(self::allModuleIds())))),
            );
        });
    }

    public function testServerHelpListsAllRegisteredCommands(): void
    {
        $display = $this->assertHelpCoversCommands(ServerApplicationFactory::create(self::modules()), 'shpd-server');

        // serverCommands modulů jsou v generované části.
        $this->assertStringContainsString('Modules:', $display);
        $this->assertMatchesRegularExpression('/^\s+einvoice-render\s+Render a structured e-invoice/m', $display);
    }

    public function testDsHelpFailsOutsideDataSourceDir(): void
    {
        $tempDir = sys_get_temp_dir() . '/shpd-help-nods-' . uniqid();
        mkdir($tempDir, 0755, true);
        $oldCwd = (string) getcwd();
        chdir($tempDir);
        try {
            $app = DsApplicationFactory::create();
            $tester = new CommandTester($app->find('help'));
            $exitCode = $tester->execute([]);
            $this->assertSame(1, $exitCode);
            $this->assertStringContainsString('Not a Shipard data source directory', $tester->getDisplay());
        } finally {
            chdir($oldCwd);
            rmdir($tempDir);
        }
    }

    /** Definice jen z hlavního stromu repa — `extraModulesPath` stroje test neovlivní. */
    private static function modules(): DataSourceModules
    {
        return new DataSourceModules(new ModulePathResolver([dirname(__DIR__, 3) . '/modules']));
    }

    /** @return list<string> */
    private static function allModuleIds(): array
    {
        return (new ModulePathResolver([dirname(__DIR__, 3) . '/modules']))->allModuleIds();
    }

    /**
     * Jména příkazů `shpd-ds` deklarovaných danými moduly.
     *
     * @param list<string> $moduleIds
     * @return list<string>
     */
    private function moduleCommandNames(array $moduleIds): array
    {
        $names = [];
        foreach ((new ModuleCommands(self::modules()))->dsGroups($moduleIds) as $group) {
            foreach ($group->commands as $command) {
                $names[] = (string) $command->getName();
            }
        }
        return $names;
    }

    /**
     * Která z jmen nápověda vypisuje jako příkaz (jméno na začátku
     * odsazeného řádku).
     *
     * @param list<string> $names
     * @return list<string>
     */
    private function listedAmong(string $display, array $names): array
    {
        return array_values(array_filter(
            $names,
            static fn(string $name): bool => preg_match('/^\s+' . preg_quote($name, '/') . '(\s|$)/m', $display) === 1,
        ));
    }

    /** `shpd-ds help` vyžaduje aktuální adresář s `config/main.json`. */
    private function inDataSourceDir(\Closure $test): void
    {
        $tempDir = sys_get_temp_dir() . '/shpd-help-drift-' . uniqid();
        mkdir($tempDir . '/config', 0755, true);
        file_put_contents($tempDir . '/config/main.json', '{}');
        $oldCwd = (string) getcwd();
        chdir($tempDir);
        try {
            $test();
        } finally {
            chdir($oldCwd);
            unlink($tempDir . '/config/main.json');
            rmdir($tempDir . '/config');
            rmdir($tempDir);
        }
    }

    /** @return string výstup nápovědy */
    private function assertHelpCoversCommands(Application $app, string $binary): string
    {
        $tester = new CommandTester($app->find('help'));
        $exitCode = $tester->execute([]);
        $this->assertSame(0, $exitCode);
        $display = $tester->getDisplay();

        $missing = [];
        foreach ($app->all() as $name => $command) {
            if (in_array($name, self::IGNORED, true)
                || $command->isHidden()
                || $command->getName() !== $name  // alias, ne primární jméno
            ) {
                continue;
            }
            // Jméno příkazu na začátku odsazeného řádku, následované koncem
            // řádku nebo mezerou — prefixy (cron vs cron-install) nestačí.
            if (!preg_match('/^\s+' . preg_quote($name, '/') . '(\s|$)/m', $display)) {
                $missing[] = $name;
            }
        }

        $this->assertSame(
            [],
            $missing,
            sprintf(
                "%s help is out of sync — add these registered commands to %s:\n  %s",
                $binary,
                $binary === 'shpd-ds'
                    ? 'src/Command/DataSource/HelpCommand.php'
                    : 'src/Command/Server/HelpCommand.php',
                implode("\n  ", $missing),
            ),
        );

        return $display;
    }
}

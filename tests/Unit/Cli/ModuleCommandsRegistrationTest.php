<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use Shipard\Cli\DsApplicationFactory;
use Shipard\Cli\ServerApplicationFactory;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Module\ModuleClassLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Server\DataSourceModules;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * Registrace příkazů z `module.jsonc` (#172 D2) a chyba pro příkaz
 * neaktivního modulu (D4) — nad fixturovými moduly `test.commands`
 * a `test.commandsTwo` (`tests/Fixtures/modules`).
 */
class ModuleCommandsRegistrationTest extends TestCase
{
    private string $tmpDir;

    private string $oldCwd;

    private string|false $oldColumns = false;

    private static function mainModulesPath(): string
    {
        return dirname(__DIR__, 3) . '/modules';
    }

    private static function fixtureModulesPath(): string
    {
        return dirname(__DIR__, 2) . '/Fixtures/modules';
    }

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/shpd-module-commands-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        $this->oldCwd = (string) getcwd();
        // Symfony láme chybovou hlášku podle šířky terminálu — i uprostřed slova.
        $this->oldColumns = getenv('COLUMNS');
        putenv('COLUMNS=250');
        ErrorLogger::resetForTesting();
        ErrorLogger::setLogPath($this->tmpDir . '/shipard.log');
        ModuleClassLoader::register(new ModulePathResolver([self::mainModulesPath(), self::fixtureModulesPath()]));
    }

    protected function tearDown(): void
    {
        chdir($this->oldCwd);
        putenv($this->oldColumns === false ? 'COLUMNS' : 'COLUMNS=' . $this->oldColumns);
        ModuleClassLoader::register(new ModulePathResolver([self::mainModulesPath()]));
        ErrorLogger::resetForTesting();
        exec('rm -rf ' . escapeshellarg($this->tmpDir));
    }

    /** Definice jen z fixturového kořene — skutečný strom test neovlivní. */
    private function modules(): DataSourceModules
    {
        return new DataSourceModules(new ModulePathResolver([self::fixtureModulesPath()]));
    }

    /** @param list<string>|null $modules null = `main.json`, ze kterého moduly určit nejde */
    private function enterDataSource(?array $modules): void
    {
        $dir = $this->tmpDir . '/aaaa-bbbb-cccc-dddd';
        mkdir($dir . '/config', 0755, true);
        file_put_contents($dir . '/config/main.json', $modules === null ? '{}' : (string) json_encode([
            'id' => 'aaaa-bbbb-cccc-dddd',
            'name' => 'Test',
            'modules' => $modules,
            'database_name' => 'db',
            'database_user' => 'user',
            'created' => '2026-11-25T10:00:00+00:00',
        ]));
        chdir($dir);
    }

    /**
     * @param array<string, mixed> $extra další vstup (volby)
     * @return array{int, string} exit kód a výstup; bílé znaky sloučené —
     *     Symfony chybu zalamuje do rámečku podle šířky terminálu
     */
    private function run_(Application $app, string $command, array $extra = []): array
    {
        $app->setAutoExit(false);
        $tester = new ApplicationTester($app);
        $exit = $tester->run(['command' => $command] + $extra);
        return [$exit, trim((string) preg_replace('/\s+/', ' ', $tester->getDisplay()))];
    }

    public function testExplicitActiveModulesRegisterTheirCommands(): void
    {
        $app = DsApplicationFactory::create(['test.commands'], $this->modules());

        $this->assertTrue($app->has('fixture-echo'));
        $this->assertTrue($app->has('fixture-sweep'));
        $this->assertFalse($app->has('fixture-other'), 'příkaz neaktivního modulu');
        $this->assertFalse($app->has('fixture-server'), 'serverCommands do shpd-ds nepatří');
        $this->assertTrue($app->has('ds-upgrade'), 'jádro vždy');

        [$exit, $display] = $this->run_($app, 'fixture-echo');
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('fixture-echo ran', $display);
    }

    public function testEmptyActiveModulesMeanCoreOnly(): void
    {
        $app = DsApplicationFactory::create([], $this->modules());

        $this->assertFalse($app->has('fixture-echo'));
        $this->assertTrue($app->has('version'));
    }

    public function testCommandOfInactiveModuleFailsNamingTheModule(): void
    {
        $app = DsApplicationFactory::create(['test.commands'], $this->modules());

        [$exit, $display] = $this->run_($app, 'fixture-other');

        $this->assertSame(1, $exit);
        $this->assertStringContainsString(
            'Command "fixture-other" belongs to module test.commandsTwo, which is not active in this data source.',
            $display,
        );
    }

    public function testInactiveModuleCommandFailsEvenWithHelpOption(): void
    {
        $app = DsApplicationFactory::create(['test.commands'], $this->modules());

        [$exit, $display] = $this->run_($app, 'fixture-other', ['--help' => true]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('belongs to module test.commandsTwo', $display);
    }

    public function testUnknownCommandKeepsTheGenericError(): void
    {
        $app = DsApplicationFactory::create(['test.commands'], $this->modules());

        [$exit, $display] = $this->run_($app, 'no-such-command');

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Command "no-such-command" is not defined.', $display);
        $this->assertStringNotContainsString('belongs to module', $display);
    }

    public function testDataSourceDirectoryRegistersCommandsOfItsModules(): void
    {
        $this->enterDataSource(['test.commandsTwo']);

        $app = DsApplicationFactory::create(null, $this->modules());

        $this->assertTrue($app->has('fixture-other'));
        $this->assertFalse($app->has('fixture-echo'));

        [$exit, $display] = $this->run_($app, 'fixture-echo');
        $this->assertSame(1, $exit);
        $this->assertStringContainsString(
            'Command "fixture-echo" belongs to module test.commands, which is not active in this data source.',
            $display,
        );
    }

    public function testUndeterminableModulesOfferAllCommandsWithAWarning(): void
    {
        $this->enterDataSource(null);

        $app = DsApplicationFactory::create(null, $this->modules());

        $this->assertTrue($app->has('fixture-echo'));
        $this->assertTrue($app->has('fixture-other'));

        [$exit, $display] = $this->run_($app, 'fixture-echo');
        $this->assertSame(0, $exit);
        $this->assertStringContainsString(DsApplicationFactory::MODULES_UNKNOWN_WARNING, $display);
        $this->assertSame(1, substr_count($display, 'cannot be determined'), 'varování jen jednou');
        $this->assertStringContainsString('active modules unknown', (string) file_get_contents($this->tmpDir . '/shipard.log'));
    }

    public function testOutsideDataSourceOnlyCoreIsRegistered(): void
    {
        chdir($this->tmpDir);

        $app = DsApplicationFactory::create(null, $this->modules());

        $this->assertFalse($app->has('fixture-echo'));
        $this->assertTrue($app->has('version'));

        [$exit, $display] = $this->run_($app, 'fixture-echo');
        $this->assertSame(1, $exit);
        $this->assertStringContainsString(
            'Command "fixture-echo" belongs to module test.commands; run it from a data source directory where the module is active.',
            $display,
        );
        $this->assertStringNotContainsString('cannot be determined', $display);
    }

    public function testKnownActiveModulesGiveNoWarning(): void
    {
        $this->enterDataSource(['test.commands']);

        [$exit, $display] = $this->run_(DsApplicationFactory::create(null, $this->modules()), 'fixture-echo');

        $this->assertSame(0, $exit);
        $this->assertStringNotContainsString('cannot be determined', $display);
    }

    public function testServerApplicationRegistersServerCommandsOfAllModules(): void
    {
        $app = ServerApplicationFactory::create($this->modules());

        $this->assertTrue($app->has('fixture-server'));
        $this->assertFalse($app->has('fixture-echo'), 'commands do shpd-server nepatří');
        $this->assertTrue($app->has('ds-create'), 'jádro vždy');

        [$exit, $display] = $this->run_($app, 'fixture-server');
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('fixture-server ran', $display);
    }

    public function testServerApplicationSurvivesUnloadableModuleDefinitions(): void
    {
        $modules = new class extends DataSourceModules {
            protected function loadDefinitions(): array
            {
                throw new \RuntimeException('no modules directory');
            }
        };

        $app = ServerApplicationFactory::create($modules);

        $this->assertTrue($app->has('server-init'));
    }
}

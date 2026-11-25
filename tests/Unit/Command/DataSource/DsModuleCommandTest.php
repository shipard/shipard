<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\DataSource;

use PHPUnit\Framework\TestCase;
use Shipard\Command\DataSource\DsModuleCommand;
use Shipard\Core\Module\ModulePathResolver;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

class TestableDsModuleCommand extends DsModuleCommand
{
    public int $upgradeResult = Command::SUCCESS;
    public int $upgradeCalls = 0;
    /** Verbosity výstupu, se kterou byl upgrade zavolán. */
    public ?int $upgradeVerbosity = null;
    /** Seznam `modules` v main.json ve chvíli, kdy upgrade běžel. */
    public ?array $modulesAtUpgrade = null;

    /** @param list<string> $roots */
    public function __construct(private readonly string $dsDir, private readonly array $roots)
    {
        parent::__construct();
    }

    protected function getDataSourceDir(): string
    {
        return $this->dsDir;
    }

    protected function getModulePathResolver(): ModulePathResolver
    {
        return new ModulePathResolver($this->roots);
    }

    protected function runUpgrade(OutputInterface $output): int
    {
        $this->upgradeCalls++;
        $this->upgradeVerbosity = $output->getVerbosity();
        $raw = json_decode((string) file_get_contents($this->dsDir . '/config/main.json'), true);
        $this->modulesAtUpgrade = $raw['modules'] ?? null;
        $output->writeln('[upgrade ran]');
        return $this->upgradeResult;
    }
}

/**
 * `shpd-ds ds-module` (#170 D5, U1, U2): přehled modulů, zapnutí doplňku
 * a modulu z dalšího kořene, vypnutí přímo zapnutého modulu. Moduly jsou
 * z dočasných kořenů, `ds-upgrade` je šev.
 */
class DsModuleCommandTest extends TestCase
{
    private string $tmpDir;
    private string $dsDir;
    private string $mainRoot;
    private string $extraRoot;
    private TestableDsModuleCommand $command;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/shpd-ds-module-cmd-' . uniqid();
        $this->dsDir = $this->tmpDir . '/ds';
        $this->mainRoot = $this->tmpDir . '/modules';
        $this->extraRoot = $this->tmpDir . '/extra';
        mkdir($this->dsDir . '/config', 0755, true);
        mkdir($this->mainRoot, 0755, true);
        mkdir($this->extraRoot, 0755, true);

        $this->makeModule($this->mainRoot, 'install.base', ['core.system'], name: 'Base installation');
        $this->makeModule($this->mainRoot, 'install.hosting', ['core.system']);
        $this->makeModule($this->mainRoot, 'core.system');
        $this->makeModule($this->mainRoot, 'core.mail', ['core.system']);
        $this->makeModule($this->mainRoot, 'facility.core', ['core.system'], addon: true, name: 'Facility management');
        $this->makeModule($this->mainRoot, 'facility.rooms', ['facility.core'], addon: true, name: 'Rooms');
        $this->makeModule($this->mainRoot, 'facility.broken', ['nope.missing'], addon: true);
        $this->makeModule($this->extraRoot, 'custacme.reporting', ['core.system'], name: 'Acme reporting');
        $this->makeModule($this->extraRoot, 'custacme.addon', [], addon: true, name: 'Acme add-on');
        $this->makeModule($this->extraRoot, 'install.acme', ['core.system']);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpDir);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    /** @param list<string> $dependencies */
    private function makeModule(string $root, string $id, array $dependencies = [], bool $addon = false, ?string $name = null): void
    {
        [$group, $module] = explode('.', $id);
        $dir = $root . '/' . $group . '/' . $module;
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/module.jsonc', json_encode(
            ['id' => $id, 'name' => $name ?? $id, 'dependencies' => $dependencies] + ($addon ? ['kind' => 'addon'] : []),
        ));
    }

    /** @param list<mixed> $modules */
    private function writeMainJson(array $modules = ['install.base']): string
    {
        $json = (string) json_encode([
            'id'            => 'abcd-efgh-ijkl-mnop',
            'name'          => 'Test DS',
            'database_name' => 'abcd_efgh_ijkl_mnop',
            'database_user' => 'shpd_abcdefgh',
            'created'       => '2026-03-12T10:00:00+01:00',
            'modules'       => $modules,
            'defaultLanguage' => 'cs',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        file_put_contents($this->dsDir . '/config/main.json', $json);
        return $json;
    }

    /** @return array<string, mixed> */
    private function mainJson(): array
    {
        return json_decode((string) file_get_contents($this->dsDir . '/config/main.json'), true);
    }

    private function rawMainJson(): string
    {
        return (string) file_get_contents($this->dsDir . '/config/main.json');
    }

    private function makeTester(): CommandTester
    {
        $this->command = new TestableDsModuleCommand($this->dsDir, [$this->mainRoot, $this->extraRoot]);
        return new CommandTester($this->command);
    }

    // ── list ────────────────────────────────────────────────────────────────

    public function testListShowsDirectActiveAndAvailableModules(): void
    {
        $this->writeMainJson(['install.base', 'facility.rooms']);
        $tester = $this->makeTester();

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $display = $tester->getDisplay();

        $this->assertMatchesRegularExpression('/Direct modules.*\n  install\.base\n  facility\.rooms\n/', $display);
        $this->assertStringContainsString('Active modules (4):', $display);
        $this->assertMatchesRegularExpression('/^  core\.system\s+core$/m', $display);
        $this->assertMatchesRegularExpression('/^  facility\.core\s+addon$/m', $display, 'doplněk aktivní jen přes závislost');
        $this->assertMatchesRegularExpression('/^  facility\.rooms\s+addon\s+direct$/m', $display);
        $this->assertMatchesRegularExpression('/^  install\.base\s+install\s+direct$/m', $display);

        $addons = substr($display, (int) strpos($display, 'Available add-ons:'), (int) strpos($display, 'Available modules from extra roots:') - (int) strpos($display, 'Available add-ons:'));
        $this->assertStringContainsString('custacme.addon', $addons, 'doplněk z dalšího kořene je mezi doplňky');
        $this->assertStringContainsString('facility.broken', $addons);
        $this->assertStringNotContainsString('facility.core', $addons, 'aktivní doplněk se nenabízí');

        $extra = substr($display, (int) strpos($display, 'Available modules from extra roots:'));
        $this->assertStringContainsString('custacme.reporting', $extra);
        $this->assertStringContainsString('Acme reporting', $extra);
        $this->assertStringNotContainsString('custacme.addon', $extra);
        $this->assertStringNotContainsString('install.acme', $extra, 'instalační modul zapnout nejde');
        $this->assertStringNotContainsString('core.mail', $display, 'neaktivní jádro se nenabízí');
        $this->assertSame(0, $this->command->upgradeCalls);
    }

    public function testListIsTheDefaultActionAndWarnsAboutUnresolvableModules(): void
    {
        $this->writeMainJson(['install.base', 'gone.module']);
        $tester = $this->makeTester();

        $this->assertSame(Command::SUCCESS, $tester->execute(['action' => 'list']));
        $this->assertStringContainsString("[WARN] Warning: module 'gone.module' not found, skipping", $tester->getDisplay());
    }

    public function testListJson(): void
    {
        $this->writeMainJson(['install.base', 'custacme.reporting']);
        $tester = $this->makeTester();

        $this->assertSame(Command::SUCCESS, $tester->execute(['--json' => true]));
        $data = json_decode($tester->getDisplay(), true);

        $this->assertSame(['install.base', 'custacme.reporting'], $data['direct']);
        $this->assertSame(
            [
                ['id' => 'core.system', 'name' => 'core.system', 'kind' => 'core', 'direct' => false, 'extraRoot' => false],
                ['id' => 'custacme.reporting', 'name' => 'Acme reporting', 'kind' => 'core', 'direct' => true, 'extraRoot' => true],
                ['id' => 'install.base', 'name' => 'Base installation', 'kind' => 'install', 'direct' => true, 'extraRoot' => false],
            ],
            $data['active'],
        );
        $this->assertSame(
            ['custacme.addon', 'facility.broken', 'facility.core', 'facility.rooms'],
            array_column($data['availableAddons'], 'id'),
        );
        $this->assertSame([true, false, false, false], array_column($data['availableAddons'], 'extraRoot'));
        $this->assertSame([], $data['availableExtraModules']);
        $this->assertSame([], $data['errors']);
    }

    // ── enable ──────────────────────────────────────────────────────────────

    public function testEnableAddonAppendsItAndRunsUpgrade(): void
    {
        $this->writeMainJson(['install.base', 'custacme.reporting']);
        $tester = $this->makeTester();

        $exit = $tester->execute(['action' => 'enable', 'id' => 'facility.core'], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);

        $this->assertSame(Command::SUCCESS, $exit);
        $main = $this->mainJson();
        $this->assertSame(['install.base', 'custacme.reporting', 'facility.core'], $main['modules'], 'pořadí zůstává, nový na konec');
        $this->assertSame('cs', $main['defaultLanguage'], 'ostatní klíče beze změny');
        $this->assertSame('Test DS', $main['name']);
        $this->assertSame(1, $this->command->upgradeCalls);
        $this->assertSame($main['modules'], $this->command->modulesAtUpgrade, 'upgrade běží až po zápisu');
        $this->assertSame(OutputInterface::VERBOSITY_VERBOSE, $this->command->upgradeVerbosity, 'stejná verbosity');
        $this->assertStringContainsString('Module facility.core enabled', $tester->getDisplay());
        $this->assertStringContainsString('[upgrade ran]', $tester->getDisplay());
    }

    public function testEnableModuleFromExtraRoot(): void
    {
        $this->writeMainJson();
        $tester = $this->makeTester();

        $this->assertSame(Command::SUCCESS, $tester->execute(['action' => 'enable', 'id' => 'custacme.reporting']));
        $this->assertSame(['install.base', 'custacme.reporting'], $this->mainJson()['modules']);
        $this->assertSame(1, $this->command->upgradeCalls);
    }

    public function testEnableCoreModuleIsRefused(): void
    {
        $before = $this->writeMainJson();
        $tester = $this->makeTester();

        $this->assertSame(Command::FAILURE, $tester->execute(['action' => 'enable', 'id' => 'core.mail']));
        $this->assertStringContainsString("'core.mail' is a core module — core modules come with the install module", $tester->getDisplay());
        $this->assertSame($before, $this->rawMainJson());
        $this->assertSame(0, $this->command->upgradeCalls);
    }

    public function testEnableActiveCoreModuleIsRefusedNotUnchanged(): void
    {
        $before = $this->writeMainJson();
        $tester = $this->makeTester();

        $this->assertSame(Command::FAILURE, $tester->execute(['action' => 'enable', 'id' => 'core.system']));
        $this->assertStringContainsString('is a core module', $tester->getDisplay());
        $this->assertSame($before, $this->rawMainJson());
    }

    /** @param non-empty-string $id */
    #[\PHPUnit\Framework\Attributes\DataProvider('installModules')]
    public function testEnableInstallModuleIsRefused(string $id): void
    {
        $before = $this->writeMainJson();
        $tester = $this->makeTester();

        $this->assertSame(Command::FAILURE, $tester->execute(['action' => 'enable', 'id' => $id]));
        $this->assertStringContainsString("'{$id}' is an install module", $tester->getDisplay());
        $this->assertSame($before, $this->rawMainJson());
        $this->assertSame(0, $this->command->upgradeCalls);
    }

    /** @return array<string, array{string}> */
    public static function installModules(): array
    {
        return ['hlavní strom' => ['install.hosting'], 'další kořen' => ['install.acme']];
    }

    public function testEnableUnknownModuleFails(): void
    {
        $before = $this->writeMainJson();
        $tester = $this->makeTester();

        $this->assertSame(Command::FAILURE, $tester->execute(['action' => 'enable', 'id' => 'nope.missing']));
        $this->assertStringContainsString("Module 'nope.missing' not found in any module root", $tester->getDisplay());
        $this->assertSame($before, $this->rawMainJson());
    }

    public function testEnableAlreadyEnabledIsUnchanged(): void
    {
        $before = $this->writeMainJson(['install.base', 'facility.rooms']);
        $tester = $this->makeTester();

        $this->assertSame(Command::SUCCESS, $tester->execute(['action' => 'enable', 'id' => 'facility.rooms']));
        $this->assertStringContainsString('unchanged — module facility.rooms is already enabled', $tester->getDisplay());
        $this->assertSame($before, $this->rawMainJson(), 'main.json beze změny, ani přeformátování');
        $this->assertSame(0, $this->command->upgradeCalls);
    }

    public function testEnableModuleActiveThroughDependencyIsUnchanged(): void
    {
        $before = $this->writeMainJson(['install.base', 'facility.rooms']);
        $tester = $this->makeTester();

        $this->assertSame(Command::SUCCESS, $tester->execute(['action' => 'enable', 'id' => 'facility.core']));
        $this->assertStringContainsString('unchanged — module facility.core is already active as a dependency of another module', $tester->getDisplay());
        $this->assertSame($before, $this->rawMainJson());
        $this->assertSame(0, $this->command->upgradeCalls);
    }

    public function testEnableWithMissingDependencyWritesNothing(): void
    {
        $before = $this->writeMainJson();
        $tester = $this->makeTester();

        $this->assertSame(Command::FAILURE, $tester->execute(['action' => 'enable', 'id' => 'facility.broken']));
        $display = $tester->getDisplay();
        $this->assertStringContainsString("Cannot enable 'facility.broken'", $display);
        $this->assertStringContainsString("module 'nope.missing' not found", $display);
        $this->assertSame($before, $this->rawMainJson());
        $this->assertSame(0, $this->command->upgradeCalls);
    }

    public function testEnableIgnoresResolverErrorsThatWereThereBefore(): void
    {
        // Přímo uvedený modul, jehož definice už v žádném kořeni není.
        $this->writeMainJson(['install.base', 'gone.module']);
        $tester = $this->makeTester();

        $this->assertSame(Command::SUCCESS, $tester->execute(['action' => 'enable', 'id' => 'facility.core']));
        $this->assertSame(['install.base', 'gone.module', 'facility.core'], $this->mainJson()['modules']);
        $this->assertSame(1, $this->command->upgradeCalls);
        $display = $tester->getDisplay();
        $this->assertStringContainsString("[WARN] Warning: module 'gone.module' not found, skipping", $display);
        $this->assertStringNotContainsString('Cannot enable', $display);
        $this->assertStringContainsString('Module facility.core enabled', $display);
    }

    public function testEnableWithNewMissingDependencyFailsDespiteEarlierErrors(): void
    {
        $before = $this->writeMainJson(['install.base', 'gone.module']);
        $tester = $this->makeTester();

        $this->assertSame(Command::FAILURE, $tester->execute(['action' => 'enable', 'id' => 'facility.broken']));
        $display = $tester->getDisplay();
        $this->assertStringContainsString("Cannot enable 'facility.broken'", $display);
        $this->assertStringContainsString("module 'nope.missing' not found", $display);
        $this->assertStringNotContainsString("module 'gone.module' not found", $display, 'předchozí hláška mezi chybami není');
        $this->assertSame($before, $this->rawMainJson());
        $this->assertSame(0, $this->command->upgradeCalls);
    }

    public function testEnableModuleDependingOnAlreadyMissingModuleFails(): void
    {
        // Stejná hláška jako nad současným seznamem, ale podruhé — nová chyba.
        $before = $this->writeMainJson(['install.base', 'nope.missing']);
        $tester = $this->makeTester();

        $this->assertSame(Command::FAILURE, $tester->execute(['action' => 'enable', 'id' => 'facility.broken']));
        $this->assertStringContainsString("Cannot enable 'facility.broken'", $tester->getDisplay());
        $this->assertSame($before, $this->rawMainJson());
        $this->assertSame(0, $this->command->upgradeCalls);
    }

    public function testEnableWithDependencyCycleWritesNothing(): void
    {
        $this->makeModule($this->mainRoot, 'loop.a', ['loop.b'], addon: true);
        $this->makeModule($this->mainRoot, 'loop.b', ['loop.a'], addon: true);
        $before = $this->writeMainJson();
        $tester = $this->makeTester();

        $this->assertSame(Command::FAILURE, $tester->execute(['action' => 'enable', 'id' => 'loop.a']));
        $this->assertStringContainsString('circular dependency', $tester->getDisplay());
        $this->assertSame($before, $this->rawMainJson());
    }

    public function testEnableWithNoUpgradeWritesAndSaysWhatToRun(): void
    {
        $this->writeMainJson();
        $tester = $this->makeTester();

        $this->assertSame(Command::SUCCESS, $tester->execute(['action' => 'enable', 'id' => 'facility.core', '--no-upgrade' => true]));
        $this->assertSame(['install.base', 'facility.core'], $this->mainJson()['modules']);
        $this->assertSame(0, $this->command->upgradeCalls);
        $this->assertStringContainsString('Run: shpd-ds ds-upgrade', $tester->getDisplay());
    }

    public function testEnableWithFailingUpgradeFailsAndKeepsMainJsonChanged(): void
    {
        $this->writeMainJson();
        $tester = $this->makeTester();
        $this->command->upgradeResult = Command::FAILURE;

        $this->assertSame(Command::FAILURE, $tester->execute(['action' => 'enable', 'id' => 'facility.core']));
        $this->assertSame(['install.base', 'facility.core'], $this->mainJson()['modules'], 'main.json zůstává změněný');
        $this->assertSame(1, $this->command->upgradeCalls);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('ds-upgrade failed — config/main.json is already changed', $display);
        $this->assertStringContainsString('run: shpd-ds ds-upgrade', $display);
    }

    // ── disable ─────────────────────────────────────────────────────────────

    public function testDisableDirectModuleRemovesItAndRunsUpgrade(): void
    {
        $this->writeMainJson(['install.base', 'facility.core', 'custacme.reporting']);
        $tester = $this->makeTester();

        $this->assertSame(Command::SUCCESS, $tester->execute(['action' => 'disable', 'id' => 'facility.core']));
        $main = $this->mainJson();
        $this->assertSame(['install.base', 'custacme.reporting'], $main['modules']);
        $this->assertSame('cs', $main['defaultLanguage']);
        $this->assertSame(1, $this->command->upgradeCalls);
        $this->assertSame($main['modules'], $this->command->modulesAtUpgrade);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Module facility.core disabled', $display);
        $this->assertStringContainsString('Its database tables are kept', $display);
    }

    public function testDisableModuleStillRequiredByAnotherActiveModuleIsRefused(): void
    {
        $before = $this->writeMainJson(['install.base', 'facility.core', 'facility.rooms']);
        $tester = $this->makeTester();

        $this->assertSame(Command::FAILURE, $tester->execute(['action' => 'disable', 'id' => 'facility.core']));
        $this->assertStringContainsString("Module 'facility.core' cannot be disabled — it would stay active, it is required by: facility.rooms.", $tester->getDisplay());
        $this->assertSame($before, $this->rawMainJson());
        $this->assertSame(0, $this->command->upgradeCalls);
    }

    public function testDisableTransitivelyActiveModuleIsRefused(): void
    {
        $before = $this->writeMainJson(['install.base', 'facility.rooms']);
        $tester = $this->makeTester();

        $this->assertSame(Command::FAILURE, $tester->execute(['action' => 'disable', 'id' => 'facility.core']));
        $this->assertStringContainsString("Module 'facility.core' is not enabled directly — it is active as a dependency of facility.rooms", $tester->getDisplay());
        $this->assertSame($before, $this->rawMainJson());
        $this->assertSame(0, $this->command->upgradeCalls);
    }

    public function testDisableInstallModuleIsRefused(): void
    {
        $before = $this->writeMainJson();
        $tester = $this->makeTester();

        $this->assertSame(Command::FAILURE, $tester->execute(['action' => 'disable', 'id' => 'install.base']));
        $this->assertStringContainsString("'install.base' is an install module — it defines the data source and cannot be disabled", $tester->getDisplay());
        $this->assertSame($before, $this->rawMainJson());
        $this->assertSame(0, $this->command->upgradeCalls);
    }

    public function testDisableModuleThatIsNotEnabledFails(): void
    {
        $before = $this->writeMainJson();
        $tester = $this->makeTester();

        $this->assertSame(Command::FAILURE, $tester->execute(['action' => 'disable', 'id' => 'facility.core']));
        $this->assertStringContainsString("Module 'facility.core' is not enabled in this data source", $tester->getDisplay());
        $this->assertSame($before, $this->rawMainJson());
    }

    public function testDisableDirectModuleWhoseDefinitionIsGone(): void
    {
        $this->writeMainJson(['install.base', 'gone.module']);
        $tester = $this->makeTester();

        $this->assertSame(Command::SUCCESS, $tester->execute(['action' => 'disable', 'id' => 'gone.module', '--no-upgrade' => true]));
        $this->assertSame(['install.base'], $this->mainJson()['modules']);
        $this->assertSame(0, $this->command->upgradeCalls);
    }

    public function testDisableWithFailingUpgradeFails(): void
    {
        $this->writeMainJson(['install.base', 'facility.core']);
        $tester = $this->makeTester();
        $this->command->upgradeResult = Command::FAILURE;

        $this->assertSame(Command::FAILURE, $tester->execute(['action' => 'disable', 'id' => 'facility.core']));
        $this->assertSame(['install.base'], $this->mainJson()['modules']);
        $this->assertStringContainsString('ds-upgrade failed', $tester->getDisplay());
    }

    // ── vstup ───────────────────────────────────────────────────────────────

    public function testEnableWithoutIdAndUnknownActionFail(): void
    {
        $this->writeMainJson();

        $tester = $this->makeTester();
        $this->assertSame(Command::FAILURE, $tester->execute(['action' => 'enable']));
        $this->assertStringContainsString('Usage: ds-module enable <id>', $tester->getDisplay());

        $tester = $this->makeTester();
        $this->assertSame(Command::FAILURE, $tester->execute(['action' => 'toggle', 'id' => 'facility.core']));
        $this->assertStringContainsString("Unknown action 'toggle'", $tester->getDisplay());
    }

    public function testOutsideDataSourceDirectoryFails(): void
    {
        $tester = $this->makeTester();

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('Not a Shipard data source directory', $tester->getDisplay());
    }

    public function testInvalidModuleDirectoryNameIsReported(): void
    {
        $this->writeMainJson();
        mkdir($this->extraRoot . '/cust_acme/reporting', 0755, true);
        file_put_contents($this->extraRoot . '/cust_acme/reporting/module.jsonc', '{}');
        $tester = $this->makeTester();

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString("Module definitions cannot be loaded: Invalid module group name 'cust_acme'", $tester->getDisplay());
    }
}

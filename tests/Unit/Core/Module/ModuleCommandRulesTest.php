<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Module;

use PHPUnit\Framework\TestCase;
use Shipard\Cli\DsApplicationFactory;
use Shipard\Cli\ServerApplicationFactory;
use Shipard\Core\Module\ModuleClassLoader;
use Shipard\Core\Module\ModuleCommandRules;
use Shipard\Core\Module\ModuleDefinition;
use Shipard\Core\Module\ModuleLoader;
use Shipard\Core\Module\ModulePathResolver;

/**
 * Pravidla příkazů a úloh cronu z modulů (#172 D2, D6) — na syntetických
 * definicích nad fixturovými třídami (`tests/Fixtures/modules/test/commands*`)
 * a nad skutečným stromem `modules/`.
 */
class ModuleCommandRulesTest extends TestCase
{
    private const ECHO = 'Shipard\\Module\\Test\\Commands\\Command\\FixtureEchoCommand';
    private const SWEEP = 'Shipard\\Module\\Test\\Commands\\Command\\FixtureSweepCommand';
    private const SERVER = 'Shipard\\Module\\Test\\Commands\\Command\\FixtureServerCommand';
    private const OTHER = 'Shipard\\Module\\Test\\CommandsTwo\\Command\\FixtureOtherCommand';
    private const DUPLICATE = 'Shipard\\Module\\Test\\CommandsTwo\\Command\\DuplicateEchoCommand';

    private static function mainModulesPath(): string
    {
        return dirname(__DIR__, 4) . '/modules';
    }

    protected function setUp(): void
    {
        // Fixturové třídy leží ve vlastním kořeni modulů.
        ModuleClassLoader::register(new ModulePathResolver([
            self::mainModulesPath(),
            dirname(__DIR__, 3) . '/Fixtures/modules',
        ]));
    }

    protected function tearDown(): void
    {
        ModuleClassLoader::register(new ModulePathResolver([self::mainModulesPath()]));
    }

    /** @param array<string, mixed> $fields */
    private function module(string $id, array $fields = []): ModuleDefinition
    {
        return ModuleDefinition::fromArray(['id' => $id, 'name' => $id] + $fields);
    }

    /**
     * @param list<ModuleDefinition> $definitions
     * @return list<string>
     */
    private function violations(array $definitions, array $coreDs = ['version', 'help'], array $coreServer = ['version', 'help']): array
    {
        return ModuleCommandRules::violations($definitions, $coreDs, $coreServer);
    }

    public function testValidDeclarationsHaveNoViolations(): void
    {
        $this->assertSame([], $this->violations([
            $this->module('test.commands', [
                'commands' => [self::ECHO, self::SWEEP],
                'serverCommands' => [self::SERVER],
                'cronJobs' => [
                    ['command' => 'fixture-sweep --all', 'slot' => 'daily'],
                    ['command' => 'fixture-sweep', 'slot' => 'daily'],
                    ['command' => 'fixture-sweep --all', 'slot' => 'weekly'],
                ],
            ]),
            $this->module('test.commandsTwo', ['commands' => [self::OTHER]]),
        ]));
    }

    public function testFixtureTreeHasNoViolations(): void
    {
        $definitions = ModuleLoader::loadAllModules(
            new ModulePathResolver([dirname(__DIR__, 3) . '/Fixtures/modules']),
        );

        $this->assertSame([], $this->violations($definitions));
    }

    public function testCommandNamespaceOfModule(): void
    {
        $this->assertSame('Shipard\\Module\\Economy\\Vat\\Command\\', ModuleCommandRules::commandNamespace('economy.vat'));
        $this->assertSame('Shipard\\Module\\Economy\\WorkOrders\\Command\\', ModuleCommandRules::commandNamespace('economy.workOrders'));
    }

    public function testMissingClassIsAViolation(): void
    {
        $violations = $this->violations([
            $this->module('test.commands', ['commands' => ['Shipard\\Module\\Test\\Commands\\Command\\NoSuchCommand']]),
        ]);

        $this->assertCount(1, $violations);
        $this->assertStringContainsString("Module 'test.commands': commands class", $violations[0]);
        $this->assertStringContainsString('does not exist', $violations[0]);
    }

    public function testClassThatIsNotACommandIsAViolation(): void
    {
        $violations = $this->violations([
            $this->module('test.commands', ['serverCommands' => ['Shipard\\Module\\Test\\Commands\\Command\\NotACommand']]),
        ]);

        $this->assertCount(1, $violations);
        $this->assertStringContainsString("Module 'test.commands': serverCommands class", $violations[0]);
        $this->assertStringContainsString('is not a Symfony\\Component\\Console\\Command\\Command', $violations[0]);
    }

    public function testClassOutsideModuleNamespaceIsAViolation(): void
    {
        $violations = $this->violations([
            // třída jiného modulu
            $this->module('test.commands', ['commands' => [self::OTHER]]),
            // třída jádra
            $this->module('test.commandsTwo', ['commands' => [\Shipard\Command\DataSource\VersionCommand::class]]),
        ]);

        $this->assertCount(2, $violations);
        $this->assertStringContainsString('is outside the module namespace Shipard\\Module\\Test\\Commands\\Command\\', $violations[0]);
        $this->assertStringContainsString('is outside the module namespace Shipard\\Module\\Test\\CommandsTwo\\Command\\', $violations[1]);
    }

    public function testSameNameInTwoModulesIsAViolation(): void
    {
        $violations = $this->violations([
            $this->module('test.commands', ['commands' => [self::ECHO]]),
            $this->module('test.commandsTwo', ['commands' => [self::DUPLICATE]]),
        ]);

        $this->assertSame(
            ["Module 'test.commandsTwo': shpd-ds command 'fixture-echo' is already registered by module 'test.commands'"],
            $violations,
        );
    }

    public function testNameOfCoreCommandIsAViolation(): void
    {
        $definitions = [
            $this->module('test.commands', ['commands' => [self::ECHO], 'serverCommands' => [self::SERVER]]),
        ];

        $this->assertSame(
            ["Module 'test.commands': shpd-ds command 'fixture-echo' is already registered by the core"],
            $this->violations($definitions, ['version', 'fixture-echo']),
        );
        $this->assertSame(
            ["Module 'test.commands': shpd-server command 'fixture-server' is already registered by the core"],
            $this->violations($definitions, ['version'], ['fixture-server']),
        );
    }

    public function testSameNameInBothBinariesIsAllowed(): void
    {
        // shpd-ds a shpd-server se spolu neporovnávají (`version`, `help`).
        $this->assertSame([], $this->violations(
            [$this->module('test.commands', ['commands' => [self::ECHO], 'serverCommands' => [self::SERVER]])],
            ['fixture-server'],
            ['fixture-echo'],
        ));
    }

    public function testCronJobMustRunACommandOfTheSameModule(): void
    {
        $violations = $this->violations([
            $this->module('test.commands', [
                'commands' => [self::ECHO],
                'cronJobs' => [
                    ['command' => 'fixture-other', 'slot' => 'daily'],
                    ['command' => 'version', 'slot' => 'daily'],
                ],
            ]),
            $this->module('test.commandsTwo', ['commands' => [self::OTHER]]),
        ]);

        $this->assertCount(2, $violations);
        $this->assertStringContainsString("Module 'test.commands': cronJobs[0] runs 'fixture-other'", $violations[0]);
        $this->assertStringContainsString("Module 'test.commands': cronJobs[1] runs 'version'", $violations[1]);
    }

    public function testSameCronJobTwiceInASlotIsAViolation(): void
    {
        $violations = $this->violations([
            $this->module('test.commands', [
                'commands' => [self::SWEEP],
                'cronJobs' => [
                    ['command' => 'fixture-sweep --all', 'slot' => 'daily'],
                    ['command' => 'fixture-sweep --all', 'slot' => 'daily', 'order' => 900],
                ],
            ]),
        ]);

        $this->assertSame(
            ["Module 'test.commands': cronJobs[1] 'fixture-sweep --all' is already declared in slot 'daily' by module 'test.commands'"],
            $violations,
        );
    }

    public function testRealModuleTreeHasNoViolations(): void
    {
        $definitions = ModuleLoader::loadAllModules(new ModulePathResolver([self::mainModulesPath()]));
        $this->assertNotEmpty($definitions);

        $this->assertSame([], ModuleCommandRules::violations(
            $definitions,
            array_map(strval(...), array_keys(DsApplicationFactory::create()->all())),
            array_map(strval(...), array_keys(ServerApplicationFactory::create()->all())),
        ));
    }
}

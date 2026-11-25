<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use Shipard\Cli\DsApplicationFactory;
use Shipard\Cli\ServerApplicationFactory;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Hlídá drift ručně psaných HelpCommandů proti registraci ve factories:
 * každý viditelný registrovaný příkaz musí být v nápovědě zmíněn.
 */
class HelpDriftTest extends TestCase
{
    /** Symfony built-ins, které v ručním helpu záměrně nejsou. */
    private const IGNORED = ['help', 'list', 'completion', '_complete'];

    public function testDsHelpListsAllRegisteredCommands(): void
    {
        // shpd-ds help vyžaduje CWD s config/main.json
        $tempDir = sys_get_temp_dir() . '/shpd-help-drift-' . uniqid();
        mkdir($tempDir . '/config', 0755, true);
        file_put_contents($tempDir . '/config/main.json', '{}');
        $oldCwd = (string) getcwd();
        chdir($tempDir);
        try {
            $this->assertHelpCoversCommands(DsApplicationFactory::create(self::allModuleIds()), 'shpd-ds');
        } finally {
            chdir($oldCwd);
            unlink($tempDir . '/config/main.json');
            rmdir($tempDir . '/config');
            rmdir($tempDir);
        }
    }

    public function testServerHelpListsAllRegisteredCommands(): void
    {
        $this->assertHelpCoversCommands(ServerApplicationFactory::create(), 'shpd-server');
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

    /**
     * Všechny moduly hlavního stromu — nápověda zatím vypisuje příkazy
     * modulů ručně, takže test staví aplikaci se všemi (#172).
     *
     * @return list<string>
     */
    private static function allModuleIds(): array
    {
        return (new \Shipard\Core\Module\ModulePathResolver([dirname(__DIR__, 3) . '/modules']))->allModuleIds();
    }

    private function assertHelpCoversCommands(Application $app, string $binary): void
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
    }
}

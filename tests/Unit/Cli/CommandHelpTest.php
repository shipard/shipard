<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use Shipard\Cli\DsApplicationFactory;
use Shipard\Cli\ServerApplicationFactory;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * `<příkaz> --help` musí vypsat opce příkazu.
 *
 * Obě aplikace nahrazují vestavěný `help` ručně psaným přehledem; Symfony
 * na commandu jménem `help` volá `setCommand()`, takže bez té metody
 * skončil `--help` u **kteréhokoli** příkazu fatální chybou.
 */
class CommandHelpTest extends TestCase
{
    public function testDsCommandHelpListsItsOptions(): void
    {
        $output = $this->helpOutput(DsApplicationFactory::create(self::allModuleIds()), 'booking-history');

        $this->assertStringContainsString('--input', $output);
        $this->assertStringContainsString('--tag-items', $output);
        $this->assertStringContainsString('--seed-min-coverage', $output);
        $this->assertStringContainsString('--usage-min-share', $output);
        $this->assertStringNotContainsString('[default: false]', $output);
    }

    public function testServerCommandHelpListsItsOptions(): void
    {
        $output = $this->helpOutput(ServerApplicationFactory::create(), 'ds-create');
        $this->assertStringContainsString('--name', $output);
    }

    public function testBareHelpStillShowsCommandOverview(): void
    {
        $output = $this->helpOutput(ServerApplicationFactory::create(), null);
        $this->assertStringContainsString('ds-create', $output);
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

    private function helpOutput(Application $app, ?string $command): string
    {
        $app->setAutoExit(false);
        $app->setCatchExceptions(false);
        $output = new BufferedOutput();
        $app->run(
            new ArrayInput($command !== null ? ['command' => $command, '--help' => true] : []),
            $output,
        );
        return $output->fetch();
    }
}

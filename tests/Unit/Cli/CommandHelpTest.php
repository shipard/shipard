<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use Shipard\Cli\DsApplicationFactory;
use Shipard\Cli\ServerApplicationFactory;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Server\DataSourceModules;
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
        $output = $this->helpOutput(self::dsApplicationWithAllModules(), 'booking-history');

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

    /** Aplikace `shpd-ds` s příkazy všech modulů hlavního stromu repa. */
    private static function dsApplicationWithAllModules(): Application
    {
        $resolver = new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        return DsApplicationFactory::create($resolver->allModuleIds(), new DataSourceModules($resolver));
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

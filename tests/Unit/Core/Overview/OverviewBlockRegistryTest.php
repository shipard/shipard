<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Overview;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Overview\OverviewBlockRegistry;
use Shipard\Tests\Fixtures\Core\Config\ConfigRuntimeFactory;
use Shipard\Tests\Fixtures\Core\Overview\StaticBlock;

/** Registr bloků z cfgItemu `core.overview.blocks`: instance, řazení, tolerance chybějící třídy. */
final class OverviewBlockRegistryTest extends TestCase
{
    private string $logPath;

    protected function setUp(): void
    {
        $this->logPath = sys_get_temp_dir() . '/shpd_overview_registry_' . uniqid('', true) . '.log';
        ErrorLogger::resetForTesting();
        ErrorLogger::setLogPath($this->logPath);
    }

    protected function tearDown(): void
    {
        ErrorLogger::resetForTesting();
        if (is_file($this->logPath)) {
            unlink($this->logPath);
        }
    }

    public function testBuildsBlocksFromConfigSortedByOrderAndId(): void
    {
        $config = ConfigRuntimeFactory::fromItems([OverviewBlockRegistry::CFG_ITEM => [
            'z.late'  => ['section' => 'money', 'order' => 20, 'class' => StaticBlock::class],
            'b.early' => ['section' => 'money', 'order' => 10, 'class' => StaticBlock::class],
            'a.early' => ['section' => 'vat', 'order' => 10, 'class' => StaticBlock::class],
        ]]);

        $entries = OverviewBlockRegistry::fromConfig($config)->entries();

        $this->assertSame(['a.early', 'b.early', 'z.late'], array_column($entries, 'id'));
        $this->assertSame(['vat', 'money', 'money'], array_column($entries, 'section'));
        $this->assertInstanceOf(StaticBlock::class, $entries[0]['block']);
        $this->assertFileDoesNotExist($this->logPath);
    }

    public function testMissingOrForeignClassIsSkippedWithWarning(): void
    {
        $config = ConfigRuntimeFactory::fromItems([OverviewBlockRegistry::CFG_ITEM => [
            'gone'    => ['section' => 'money', 'order' => 10, 'class' => 'Shipard\\Nope\\Missing'],
            'foreign' => ['section' => 'money', 'order' => 10, 'class' => \stdClass::class],
            'ok'      => ['section' => 'money', 'order' => 10, 'class' => StaticBlock::class],
        ]]);

        $entries = OverviewBlockRegistry::fromConfig($config)->entries();

        $this->assertSame(['ok'], array_column($entries, 'id'));
        $log = (string) file_get_contents($this->logPath);
        $this->assertStringContainsString('Overview block class not found', $log);
        $this->assertStringContainsString('does not implement OverviewBlock', $log);
    }

    public function testNoConfigYieldsEmptyRegistry(): void
    {
        $this->assertSame([], OverviewBlockRegistry::fromConfig(null)->entries());
        $this->assertSame([], OverviewBlockRegistry::fromConfig(ConfigRuntimeFactory::fromItems([]))->entries());
    }
}

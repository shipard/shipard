<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Server;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Server\DataSourceModules;

class DataSourceModulesTest extends TestCase
{
    private string $tmpDir;
    private string $dsDir;
    private string $logPath;

    protected function setUp(): void
    {
        ErrorLogger::resetForTesting();
        $this->tmpDir = sys_get_temp_dir() . '/shpd-ds-modules-test-' . uniqid();
        $this->dsDir = $this->tmpDir . '/data-sources';
        $this->logPath = $this->tmpDir . '/shipard.log';
        mkdir($this->dsDir, 0755, true);
        ErrorLogger::setLogPath($this->logPath);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tmpDir));
        ErrorLogger::resetForTesting();
    }

    /** core.system ← core.mail ← install.x; core.alerts stranou. */
    private function moduleTree(): ModulePathResolver
    {
        $root = $this->tmpDir . '/modules';
        $defs = [
            'core/system'   => ['core.system', []],
            'core/mail'     => ['core.mail', ['core.system']],
            'core/alerts'   => ['core.alerts', ['core.system']],
            'install/x'     => ['install.x', ['core.mail']],
        ];
        foreach ($defs as $path => [$id, $deps]) {
            mkdir($root . '/' . $path, 0755, true);
            file_put_contents($root . '/' . $path . '/module.jsonc', json_encode([
                'id' => $id,
                'name' => $id,
                'dependencies' => $deps,
            ]));
        }
        return new ModulePathResolver([$root]);
    }

    /** @param list<string>|null $modules null = bez klíče `modules` */
    private function createDs(string $id, ?array $modules, bool $complete = true): string
    {
        $dir = $this->dsDir . '/' . $id;
        mkdir($dir . '/config', 0755, true);
        $main = ['id' => $id, 'name' => 'Test'];
        if ($complete) {
            $main += [
                'database_name' => 'db',
                'database_user' => 'user',
                'created' => '2026-10-13T10:00:00+00:00',
            ];
        }
        if ($modules !== null) {
            $main['modules'] = $modules;
        }
        file_put_contents($dir . '/config/main.json', (string) json_encode($main));
        return $dir;
    }

    private function log(): string
    {
        return is_file($this->logPath) ? (string) file_get_contents($this->logPath) : '';
    }

    public function testDirectInstallModuleExpandsToTransitiveDependencies(): void
    {
        $dir = $this->createDs('aaaa-aaaa-aaaa-aaaa', ['install.x']);
        $modules = new DataSourceModules($this->moduleTree());

        $this->assertSame(['core.system', 'core.mail', 'install.x'], $modules->activeIds($dir));
        $this->assertTrue($modules->isActive($dir, 'core.mail'));
        $this->assertFalse($modules->isActive($dir, 'core.alerts'));
        $this->assertStringNotContainsString('active modules unknown', $this->log());
    }

    public function testDirectModuleWithoutMailIsNotActive(): void
    {
        $dir = $this->createDs('aaaa-aaaa-aaaa-aaaa', ['core.system']);
        $modules = new DataSourceModules($this->moduleTree());

        $this->assertSame(['core.system'], $modules->activeIds($dir));
        $this->assertFalse($modules->isActive($dir, 'core.mail'));
    }

    public function testUnusableMainJsonYieldsNull(): void
    {
        $modules = new DataSourceModules($this->moduleTree());

        $missing = $this->dsDir . '/bbbb-bbbb-bbbb-bbbb';
        mkdir($missing . '/config', 0755, true);
        $this->assertNull($modules->activeIds($missing), 'chybějící main.json');

        $broken = $this->createDs('cccc-cccc-cccc-cccc', ['install.x']);
        file_put_contents($broken . '/config/main.json', '{broken');
        $this->assertNull($modules->activeIds($broken), 'poškozený JSON');

        $incomplete = $this->createDs('dddd-dddd-dddd-dddd', ['install.x'], complete: false);
        $this->assertNull($modules->activeIds($incomplete), 'bez povinných polí');

        $noModules = $this->createDs('eeee-eeee-eeee-eeee', null);
        $this->assertNull($modules->activeIds($noModules), 'bez klíče modules');

        $empty = $this->createDs('ffff-ffff-ffff-ffff', []);
        $this->assertNull($modules->activeIds($empty), 'prázdný seznam');
        $this->assertFalse($modules->isActive($empty, 'core.system'));

        // Každý dotaz s neurčitelnými moduly = jeden warning (5× activeIds + 1× isActive).
        $log = $this->log();
        $this->assertSame(6, substr_count($log, 'active modules unknown'));
        $this->assertStringContainsString('main.json unusable', $log);
        $this->assertStringContainsString('lists no modules', $log);
    }

    public function testUnknownModuleInMainJsonYieldsNull(): void
    {
        // Částečný výsledek by mohl tiše vynechat úlohy — chyba resolveru = nelze určit.
        $dir = $this->createDs('aaaa-aaaa-aaaa-aaaa', ['install.x', 'customer.gone']);
        $modules = new DataSourceModules($this->moduleTree());

        $this->assertNull($modules->activeIds($dir));
        $this->assertStringContainsString("module 'customer.gone' not found", $this->log());
    }

    public function testDefinitionsLoadOncePerInstance(): void
    {
        $a = $this->createDs('aaaa-aaaa-aaaa-aaaa', ['install.x']);
        $b = $this->createDs('bbbb-bbbb-bbbb-bbbb', ['core.alerts']);

        $modules = new class($this->moduleTree()) extends DataSourceModules {
            public int $loads = 0;

            protected function loadDefinitions(): array
            {
                $this->loads++;
                return parent::loadDefinitions();
            }
        };

        $this->assertSame(['core.system', 'core.mail', 'install.x'], $modules->activeIds($a));
        $this->assertSame(['core.system', 'core.alerts'], $modules->activeIds($b));
        $this->assertTrue($modules->isActive($a, 'core.mail'));
        $this->assertSame(1, $modules->loads);
    }

    public function testFailedDefinitionsLoadYieldsNullAndIsNotRetried(): void
    {
        $a = $this->createDs('aaaa-aaaa-aaaa-aaaa', ['install.x']);
        $b = $this->createDs('bbbb-bbbb-bbbb-bbbb', ['install.x']);

        $modules = new class(null) extends DataSourceModules {
            public int $loads = 0;

            protected function loadDefinitions(): array
            {
                $this->loads++;
                throw new \RuntimeException('modules root missing');
            }
        };

        $this->assertNull($modules->activeIds($a));
        $this->assertNull($modules->activeIds($b));
        $this->assertSame(1, $modules->loads);
        $log = $this->log();
        $this->assertStringContainsString('module definitions cannot be loaded', $log);
        $this->assertSame(2, substr_count($log, 'active modules unknown'));
        $this->assertStringContainsString('modules root missing', $log);
    }

    public function testDefaultResolverReadsRepoModules(): void
    {
        // Bez vlastního resolveru: vestavěný adresář modules/ (server.json
        // na testovacím stroji může chybět — fallback). install.base → core.mail.
        $dir = $this->createDs('aaaa-aaaa-aaaa-aaaa', ['install.base']);
        $modules = new DataSourceModules();

        $this->assertTrue($modules->isActive($dir, 'core.mail'));
        $this->assertTrue($modules->isActive($dir, 'core.alerts'));
    }
}

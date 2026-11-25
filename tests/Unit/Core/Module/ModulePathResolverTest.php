<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Module;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Module\ModulePathResolver;

class ModulePathResolverTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/shpd-mpr-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
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

    private function makeRoot(string $name): string
    {
        $path = $this->tmpDir . '/' . $name;
        mkdir($path, 0755, true);
        return $path;
    }

    /**
     * Creates `{root}/{group}/{module}/module.jsonc` (empty file).
     * Returns the absolute module directory path.
     */
    private function makeModule(string $root, string $group, string $module): string
    {
        $dir = $root . '/' . $group . '/' . $module;
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/module.jsonc', '');
        return $dir;
    }

    public function testEmptyRoots(): void
    {
        $r = new ModulePathResolver([]);
        $this->assertSame([], $r->allModuleIds());
        $this->assertSame([], $r->getRoots());
        $this->assertNull($r->getPath('anything.here'));
    }

    public function testSingleRootSingleModule(): void
    {
        $root = $this->makeRoot('main');
        $docsDir = $this->makeModule($root, 'economy', 'docs');

        $r = new ModulePathResolver([$root]);

        $this->assertSame($docsDir, $r->getPath('economy.docs'));
        $this->assertSame(['economy.docs'], $r->allModuleIds());
        $this->assertNull($r->getPath('economy.missing'));
    }

    public function testSingleRootMultipleModules(): void
    {
        $root = $this->makeRoot('main');
        $this->makeModule($root, 'economy', 'docs');
        $this->makeModule($root, 'core', 'system');
        $this->makeModule($root, 'base', 'people');

        $r = new ModulePathResolver([$root]);

        $this->assertSame(
            ['base.people', 'core.system', 'economy.docs'],
            $r->allModuleIds(),
        );
    }

    public function testMultipleRoots(): void
    {
        $mainRoot  = $this->makeRoot('main');
        $extraRoot = $this->makeRoot('extra');

        $coreDir  = $this->makeModule($mainRoot, 'core', 'system');
        $partyDir = $this->makeModule($extraRoot, 'partner', 'crm');

        $r = new ModulePathResolver([$mainRoot, $extraRoot]);

        $this->assertSame($coreDir,  $r->getPath('core.system'));
        $this->assertSame($partyDir, $r->getPath('partner.crm'));
        $this->assertSame(
            ['core.system', 'partner.crm'],
            $r->allModuleIds(),
        );
    }

    public function testCollisionAcrossRootsThrows(): void
    {
        $a = $this->makeRoot('a');
        $b = $this->makeRoot('b');
        $pathA = $this->makeModule($a, 'economy', 'docs');
        $pathB = $this->makeModule($b, 'economy', 'docs');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches(
            '/economy\.docs.*' . preg_quote($pathA, '/') . '.*' . preg_quote($pathB, '/') . '/s',
        );

        new ModulePathResolver([$a, $b]);
    }

    public function testNonexistentRootThrows(): void
    {
        $missing = $this->tmpDir . '/does-not-exist';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($missing, '/') . '/');

        new ModulePathResolver([$missing]);
    }

    public function testRootIsFileThrows(): void
    {
        $file = $this->tmpDir . '/iam-a-file';
        file_put_contents($file, 'not a dir');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($file, '/') . '/');

        new ModulePathResolver([$file]);
    }

    public function testRootWithoutModuleJsoncIsIgnored(): void
    {
        $root = $this->makeRoot('main');
        // Directory layout exists but no module.jsonc inside.
        mkdir($root . '/economy/docs', 0755, true);

        $r = new ModulePathResolver([$root]);

        $this->assertSame([], $r->allModuleIds());
        $this->assertNull($r->getPath('economy.docs'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidGroupNames')]
    public function testInvalidGroupNameWithModuleInsideThrows(string $group): void
    {
        $root = $this->makeRoot('main');
        $this->makeModule($root, 'core', 'system');
        $this->makeModule($root, $group, 'reporting');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Invalid module group name '{$group}' in '{$root}/{$group}/reporting'");
        new ModulePathResolver([$root]);
    }

    /** @return array<string, array{string}> */
    public static function invalidGroupNames(): array
    {
        return [
            'underscore'    => ['cust_acme'],
            'uppercase'     => ['Economy'],
            'camelCase'     => ['custAcme'],
            'leading digit' => ['123foo'],
        ];
    }

    public function testInvalidGroupNameMessageNamesExpectedFormat(): void
    {
        $root = $this->makeRoot('main');
        $this->makeModule($root, 'cust_acme', 'reporting');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('[a-z][a-z0-9]*');
        new ModulePathResolver([$root]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidModuleNames')]
    public function testInvalidModuleNameWithModuleJsoncThrows(string $module): void
    {
        $root = $this->makeRoot('main');
        $this->makeModule($root, 'core', 'system');
        $this->makeModule($root, 'core', $module);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Invalid module name '{$module}' in '{$root}/core/{$module}'");
        new ModulePathResolver([$root]);
    }

    /** @return array<string, array{string}> */
    public static function invalidModuleNames(): array
    {
        return [
            'uppercase first' => ['System'],
            'leading digit'   => ['123foo'],
            'underscore'      => ['work_orders'],
        ];
    }

    public function testInvalidModuleNameMessageNamesExpectedFormat(): void
    {
        $root = $this->makeRoot('main');
        $this->makeModule($root, 'core', 'work_orders');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('[a-z][a-zA-Z0-9]*');
        new ModulePathResolver([$root]);
    }

    public function testInvalidNamesWithoutModuleJsoncAreIgnored(): void
    {
        $root = $this->makeRoot('main');
        $this->makeModule($root, 'core', 'system');

        // Neplatná skupina bez modulu uvnitř (.git, node_modules, poznámky).
        mkdir($root . '/.git/hooks', 0755, true);
        mkdir($root . '/Notes_2026/drafts', 0755, true);
        file_put_contents($root . '/Notes_2026/drafts/readme.md', '');
        // Neplatné jméno pod platnou skupinou, ale bez module.jsonc.
        mkdir($root . '/core/_shared', 0755, true);
        // Podadresář uvnitř modulu (docs/ se specifikacemi) se neprochází.
        mkdir($root . '/core/system/docs/Old_Notes', 0755, true);
        file_put_contents($root . '/core/system/docs/Old_Notes/module.jsonc', '');

        $r = new ModulePathResolver([$root]);

        $this->assertSame(['core.system'], $r->allModuleIds());
    }

    public function testRootOfAndIsInMainRoot(): void
    {
        $main = $this->makeRoot('main');
        $extra = $this->makeRoot('extra');
        $this->makeModule($main, 'core', 'system');
        $this->makeModule($extra, 'custacme', 'reporting');

        $r = new ModulePathResolver([$main, $extra]);

        $this->assertSame($main, $r->rootOf('core.system'));
        $this->assertSame($extra, $r->rootOf('custacme.reporting'));
        $this->assertNull($r->rootOf('nope.missing'));
        $this->assertTrue($r->isInMainRoot('core.system'));
        $this->assertFalse($r->isInMainRoot('custacme.reporting'));
        $this->assertFalse($r->isInMainRoot('nope.missing'));
    }

    public function testNestedNonModuleDirectoriesIgnored(): void
    {
        $root = $this->makeRoot('main');
        $valid = $this->makeModule($root, 'economy', 'docs');

        // Loose file at root — not a directory at all.
        file_put_contents($root . '/README.txt', '');

        // Top-level dir without nested module subdir (group with module.jsonc directly).
        mkdir($root . '/lonelygroup', 0755, true);
        file_put_contents($root . '/lonelygroup/module.jsonc', '');

        // Extra-deep dir: {root}/{group}/{module}/{sub}/module.jsonc — must be ignored.
        mkdir($root . '/economy/docs/sub', 0755, true);
        file_put_contents($root . '/economy/docs/sub/module.jsonc', '');

        $r = new ModulePathResolver([$root]);

        $this->assertSame(['economy.docs'], $r->allModuleIds());
        $this->assertSame($valid, $r->getPath('economy.docs'));
    }

    public function testGetRootsPreservesOrder(): void
    {
        $a = $this->makeRoot('a');
        $b = $this->makeRoot('b');
        $c = $this->makeRoot('c');

        $r = new ModulePathResolver([$b, $c, $a]);

        $this->assertSame([$b, $c, $a], $r->getRoots());
    }

    /**
     * @param list<string> $extras
     */
    private function makeServerConfig(array $extras): ServerConfig
    {
        $path = $this->tmpDir . '/server.json';
        file_put_contents($path, (string) json_encode([
            'host'             => 'x',
            'port'             => 1,
            'admin_user'       => 'x',
            'admin_password'   => 'x',
            'mode'             => 'production',
            'extraModulesPath' => $extras,
        ]));
        $cfg = new ServerConfig($path);
        $cfg->load();
        return $cfg;
    }

    public function testFromServerConfigMainOnly(): void
    {
        $main = $this->makeRoot('main');
        $this->makeModule($main, 'core', 'system');
        $cfg  = $this->makeServerConfig([]);

        $r = ModulePathResolver::fromServerConfig($cfg, $main);

        $this->assertSame([$main], $r->getRoots());
        $this->assertSame(['core.system'], $r->allModuleIds());
    }

    public function testFromServerConfigCombinesMainAndExtras(): void
    {
        $main   = $this->makeRoot('main');
        $extra1 = $this->makeRoot('extra1');
        $extra2 = $this->makeRoot('extra2');

        $this->makeModule($main,   'core',    'system');
        $this->makeModule($extra1, 'partner', 'crm');
        $this->makeModule($extra2, 'customer', 'reports');

        $cfg = $this->makeServerConfig([$extra1, $extra2]);

        $r = ModulePathResolver::fromServerConfig($cfg, $main);

        $this->assertSame(
            ['core.system', 'customer.reports', 'partner.crm'],
            $r->allModuleIds(),
        );
    }

    public function testFromServerConfigOrderMainFirst(): void
    {
        $main   = $this->makeRoot('main');
        $extra1 = $this->makeRoot('extra1');
        $extra2 = $this->makeRoot('extra2');

        $cfg = $this->makeServerConfig([$extra1, $extra2]);

        $r = ModulePathResolver::fromServerConfig($cfg, $main);

        $this->assertSame([$main, $extra1, $extra2], $r->getRoots());
    }
}

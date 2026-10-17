<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\DevUpdate;

use PHPUnit\Framework\TestCase;
use Shipard\Core\DevUpdate\UpdateEnvironment;
use Shipard\Tests\Fixtures\Core\DevUpdate\GitFixture;

/** Prostředí podprocesů (pevný PATH, git bez dotazů) a pojistka `missingTool`. */
final class UpdateEnvironmentTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/shpd_env_' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/a', 0700, true);
        mkdir($this->dir . '/b', 0700, true);
    }

    protected function tearDown(): void
    {
        GitFixture::rrmdir($this->dir);
    }

    private function tool(string $dir, string $name, bool $executable = true): void
    {
        $path = $this->dir . '/' . $dir . '/' . $name;
        file_put_contents($path, "#!/bin/sh\nexit 0\n");
        chmod($path, $executable ? 0755 : 0644);
    }

    public function testEnvForcesPathAndSilencesGitPrompts(): void
    {
        $env = UpdateEnvironment::env();

        $this->assertSame(UpdateEnvironment::DEFAULT_PATH, $env['PATH']);
        $this->assertSame('0', $env['GIT_TERMINAL_PROMPT']);
        $this->assertSame('/bin/true', $env['GIT_ASKPASS']);
        $this->assertSame('C', $env['LC_ALL']);
        $this->assertSame('/custom/bin', UpdateEnvironment::env('/custom/bin')['PATH']);
        foreach ($env as $key => $value) {
            $this->assertIsString($key);
            $this->assertIsString($value);
        }
    }

    public function testFindToolWalksPathInOrderAndSkipsNonExecutables(): void
    {
        $this->tool('a', 'git', false);
        $this->tool('b', 'git');
        $path = $this->dir . '/a:' . $this->dir . '/b::' . $this->dir . '/missing';

        $this->assertSame($this->dir . '/b/git', UpdateEnvironment::findTool('git', $path));
        $this->assertNull(UpdateEnvironment::findTool('npm', $path));
    }

    public function testMissingToolsListsWhatIsAbsent(): void
    {
        $this->tool('a', 'git');
        $path = $this->dir . '/a';
        $this->assertSame(['composer', 'npm'], UpdateEnvironment::missingTools($path));

        $this->tool('a', 'composer');
        $this->tool('b', 'npm');
        $this->assertSame([], UpdateEnvironment::missingTools($path . ':' . $this->dir . '/b'));
    }
}

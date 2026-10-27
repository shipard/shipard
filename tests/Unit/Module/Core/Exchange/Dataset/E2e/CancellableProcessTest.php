<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Dataset\E2e;

use PHPUnit\Framework\TestCase;
use Shipard\Core\DevUpdate\UpdateEnvironment;
use Shipard\Module\Core\Exchange\Dataset\DatasetReader;
use Shipard\Module\Core\Exchange\Dataset\E2e\CancellableProcess;
use Shipard\Module\Core\Exchange\Dataset\E2e\E2eException;
use Shipard\Module\Core\Exchange\Dataset\Video\DatasetBuildProcess;
use Shipard\Module\Core\Exchange\Dataset\Video\VideoRunnerProcess;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Společný zrušitelný podproces (tasks/testing-phase7b.md § 2): whitelist
 * prefixů prostředí, argv potomků (video-runner, generátor sady), zrušení
 * zvoleným signálem (SIGTERM pro python) a SIGKILL po grace.
 */
final class CancellableProcessTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/shpd_cproc_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        DatasetReader::removeTree($this->dir);
    }

    private function script(string $body): string
    {
        $path = $this->dir . '/child.sh';
        file_put_contents($path, "#!/bin/sh\n" . $body);
        chmod($path, 0755);
        return $path;
    }

    public function testEnvironmentForAcceptsOnlyWhitelistedPrefixes(): void
    {
        $env = CancellableProcess::environmentFor(['VIDEO_FIXED_TIME' => 'x', 'SHPD_BASE_URL' => 'y'], ['SHPD_', 'VIDEO_'], '/home/svc', '/opt/bin');
        $this->assertSame('/opt/bin', $env['PATH']);
        $this->assertSame('x', $env['VIDEO_FIXED_TIME']);
        $this->assertSame('y', $env['SHPD_BASE_URL']);
        $this->assertArrayNotHasKey('CI', $env);
        $this->assertSame(['SHPD_', 'VIDEO_'], VideoRunnerProcess::ENV_PREFIXES);

        $runnerEnv = VideoRunnerProcess::environment(['VIDEO_HEADFUL' => '0'], '/home/svc');
        $this->assertSame(UpdateEnvironment::DEFAULT_PATH, $runnerEnv['PATH']);
        $this->assertSame('0', $runnerEnv['VIDEO_HEADFUL']);

        $buildEnv = DatasetBuildProcess::environment('http://10.0.0.1:3000', '/home/svc');
        $this->assertSame('http://10.0.0.1:3000', $buildEnv['GOTENBERG_URL']);
        $this->assertSame('1', $buildEnv['PYTHONUTF8']);
        $this->assertSame('C', $buildEnv['LC_ALL']);

        $this->expectException(\InvalidArgumentException::class);
        VideoRunnerProcess::environment(['PATH' => '/evil']);
    }

    public function testArgvOfTheChildren(): void
    {
        $runner = new VideoRunnerProcess('/usr/bin/node', '/repo/tools/video-runner/', 'record', '/repo/demo/scenarios/s1.jsonc', static fn (): bool => false);
        $this->assertSame(['/usr/bin/node', '/repo/tools/video-runner/bin/video-runner.mjs', 'record', '/repo/demo/scenarios/s1.jsonc'], $runner->argv());
        $this->assertSame('/repo/tools/video-runner', $runner->cwd());
        $this->assertSame(CancellableProcess::SIGINT, $runner->cancelSignal());
        $this->assertSame('record', $runner->verb());

        $build = new DatasetBuildProcess(['/usr/bin/python3', 'build.py', '--anchor', '2026-10-23'], '/repo', static fn (): bool => false);
        $this->assertSame(['/usr/bin/python3', 'build.py', '--anchor', '2026-10-23'], $build->argv());
        $this->assertSame(CancellableProcess::SIGTERM, $build->cancelSignal());

        $this->expectException(\InvalidArgumentException::class);
        new VideoRunnerProcess('/usr/bin/node', '/repo', 'login', '/x', static fn (): bool => false);
    }

    public function testCancelSendsTheConfiguredSignalThenKillsAfterGrace(): void
    {
        // SIGTERM: skript ho zachytí a skončí 143 — SIGKILL nepřijde.
        $script = $this->script("trap 'exit 143' TERM\necho started\nfor i in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18 19 20 21 22 23 24 25 26 27 28 29 30 31 32 33 34 35 36 37 38 39 40 41 42 43 44 45 46 47 48 49 50; do sleep 0.1; done\nexit 0\n");
        $cancel = false;
        $process = new DatasetBuildProcess([$script], $this->dir, function () use (&$cancel): bool {
            return $cancel;
        }, 5.0, 20_000);
        $log = new BufferedOutput();
        $cancel = true;
        $result = $process->run(['PATH' => '/usr/bin:/bin'], $log);
        $this->assertTrue($result['cancelled']);
        $this->assertSame(143, $result['exitCode']);
        $this->assertNull($result['termSignal']);
        $this->assertStringContainsString('started', $log->fetch());

        // Ignorovaný signál: po grace SIGKILL.
        $script = $this->script("trap '' TERM\nfor i in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18 19 20 21 22 23 24 25 26 27 28 29 30 31 32 33 34 35 36 37 38 39 40 41 42 43 44 45 46 47 48 49 50; do sleep 0.1; done\n");
        $process = new DatasetBuildProcess([$script], $this->dir, static fn (): bool => true, 0.3, 20_000);
        $result = $process->run(['PATH' => '/usr/bin:/bin'], new BufferedOutput());
        $this->assertTrue($result['cancelled']);
        $this->assertSame(CancellableProcess::SIGKILL, $result['termSignal']);
        $this->assertNull($result['exitCode']);
    }

    public function testUnstartableChildThrows(): void
    {
        $process = new DatasetBuildProcess([$this->dir . '/missing'], $this->dir, static fn (): bool => false);
        $this->expectException(E2eException::class);
        $this->expectExceptionMessage('Generátor demo sady nejde spustit');
        $process->run(['PATH' => '/usr/bin:/bin'], new BufferedOutput());
    }
}

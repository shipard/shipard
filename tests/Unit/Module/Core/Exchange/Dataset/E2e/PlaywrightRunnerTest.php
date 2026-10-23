<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Dataset\E2e;

use PHPUnit\Framework\TestCase;
use Shipard\Core\DevUpdate\UpdateEnvironment;
use Shipard\Module\Core\Exchange\Dataset\DatasetReader;
use Shipard\Module\Core\Exchange\Dataset\E2e\E2eException;
use Shipard\Module\Core\Exchange\Dataset\E2e\PlaywrightRunner;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Podproces Playwrightu (tasks/testing-phase7a.md I1, I3) nad falešným
 * `node` (shellový skript): oba proudy do logu, exit kód, prostředí,
 * zrušení SIGINT → graceful konec, po grace SIGKILL.
 */
final class PlaywrightRunnerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/shpd_e2e_run_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        DatasetReader::removeTree($this->dir);
    }

    private function fakeNode(string $body): string
    {
        $path = $this->dir . '/node';
        file_put_contents($path, "#!/bin/sh\n" . $body);
        chmod($path, 0755);
        return $path;
    }

    private function runner(string $node, \Closure $shouldCancel, float $grace = 5.0): PlaywrightRunner
    {
        return new PlaywrightRunner($node, $this->dir . '/cli.js', $this->dir . '/pw.config.mjs', $this->dir, $shouldCancel, $grace, 20_000);
    }

    public function testArgvAndEnvironment(): void
    {
        $runner = $this->runner('/usr/bin/node', static fn (): bool => false);
        $this->assertSame(['/usr/bin/node', $this->dir . '/cli.js', 'test', '--config', $this->dir . '/pw.config.mjs'], $runner->argv());

        $env = PlaywrightRunner::environment(['SHPD_E2E_APP_URL' => 'http://127.0.0.1/x/app/', 'SHPD_E2E_EXPECTED_CARDS' => '3'], '/home/svc');
        $this->assertSame(UpdateEnvironment::DEFAULT_PATH, $env['PATH']);
        $this->assertSame('1', $env['CI']);
        $this->assertSame('http://127.0.0.1/x/app/', $env['SHPD_E2E_APP_URL']);
        $this->assertSame('3', $env['SHPD_E2E_EXPECTED_CARDS']);
        $this->assertArrayHasKey('HOME', $env);
        $this->assertNotSame('', $env['HOME']);
        $this->assertSame('/opt/pw', PlaywrightRunner::environment([], null, '/opt/pw')['PATH']);

        $this->expectException(\InvalidArgumentException::class);
        PlaywrightRunner::environment(['PATH' => '/evil']);
    }

    public function testEnvironmentFillsHomeOnlyWhenMissing(): void
    {
        $previous = getenv('HOME');
        putenv('HOME');
        try {
            $this->assertSame('/home/svc', PlaywrightRunner::environment([], '/home/svc')['HOME']);
            $this->assertArrayNotHasKey('HOME', PlaywrightRunner::environment([], null));
        } finally {
            putenv($previous === false ? 'HOME' : 'HOME=' . $previous);
        }
        if ($previous !== false) {
            $this->assertSame($previous, PlaywrightRunner::environment([], '/home/other')['HOME'], 'zděděný HOME má přednost');
        }
    }

    public function testStreamsBothOutputsAndReturnsTheExitCode(): void
    {
        $node = $this->fakeNode("echo \"args: \$@\"\necho \"err line\" >&2\necho \"env: \$SHPD_E2E_OUTPUT_DIR \$CI\"\nexit 3\n");
        $log = new BufferedOutput();

        $result = $this->runner($node, static fn (): bool => false)->run(
            PlaywrightRunner::environment(['SHPD_E2E_OUTPUT_DIR' => '/tmp/out']),
            $log,
        );

        $this->assertSame(3, $result['exitCode']);
        $this->assertNull($result['termSignal']);
        $this->assertFalse($result['cancelled']);
        $this->assertGreaterThanOrEqual(0, $result['durationMs']);
        $text = $log->fetch();
        $this->assertStringContainsString('args: ' . $this->dir . '/cli.js test --config ' . $this->dir . '/pw.config.mjs', $text);
        $this->assertStringContainsString('err line', $text);
        $this->assertStringContainsString('env: /tmp/out 1', $text);
    }

    public function testCancelSendsSigintAndWaitsForAGracefulExit(): void
    {
        $node = $this->fakeNode("trap 'echo interrupted; exit 130' INT\necho started\nsleep 20 &\nwait \$!\necho not-reached\nexit 0\n");
        $log = new BufferedOutput();
        $polls = 0;
        $cancel = static function () use (&$polls): bool {
            return ++$polls >= 5;
        };

        $started = microtime(true);
        $result = $this->runner($node, $cancel)->run(PlaywrightRunner::environment([]), $log);

        $this->assertTrue($result['cancelled']);
        $this->assertSame(130, $result['exitCode'], 'SIGINT doručen → skript skončil sám (Playwright dopíše reportery)');
        $this->assertNull($result['termSignal']);
        $this->assertLessThan(4.0, microtime(true) - $started);
        $text = $log->fetch();
        $this->assertStringContainsString('started', $text);
        $this->assertStringContainsString('interrupted', $text);
        $this->assertStringNotContainsString('not-reached', $text);
    }

    public function testSigkillAfterGraceWhenSigintIsIgnored(): void
    {
        $node = $this->fakeNode("trap '' INT\necho ignoring\nexec sleep 20\n");
        $log = new BufferedOutput();

        $started = microtime(true);
        $result = $this->runner($node, static fn (): bool => true, 0.3)->run(PlaywrightRunner::environment([]), $log);

        $this->assertTrue($result['cancelled']);
        $this->assertNull($result['exitCode']);
        $this->assertSame(PlaywrightRunner::SIGKILL, $result['termSignal']);
        $this->assertLessThan(4.0, microtime(true) - $started);
        $this->assertStringContainsString('ignoring', $log->fetch());
    }

    public function testUnstartableBinaryThrows(): void
    {
        $this->expectException(E2eException::class);
        $this->runner($this->dir . '/missing-node', static fn (): bool => false)->run(PlaywrightRunner::environment([]), new BufferedOutput());
    }
}

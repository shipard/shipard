<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\Server;

use PHPUnit\Framework\TestCase;
use Shipard\Command\Server\UpgradeCommand;
use Symfony\Component\Console\Tester\CommandTester;

class TestableUpgradeCommand extends UpgradeCommand
{
    /** @var string[] */
    public array $captureLog = [];
    /** @var array<string, array{lines: string[], exitCode: int}> substring → result */
    public array $captureMap = [];
    /** @var string[] */
    public array $stepLog = [];
    /** @var array<string, int> substring → exit code */
    public array $stepExitCodes = [];

    public function __construct(
        private readonly string $repoRoot,
        private readonly string $serverMode = 'development',
        private readonly int $euid = 1000,
        private readonly string $currentUser = 'dev',
        private readonly string $shipardUser = 'dev',
    ) {
        parent::__construct();
    }

    protected function getRepoRoot(): string
    {
        return $this->repoRoot;
    }

    protected function getServerMode(): string
    {
        return $this->serverMode;
    }

    protected function getEuid(): int
    {
        return $this->euid;
    }

    protected function getCurrentUserName(): string
    {
        return $this->currentUser;
    }

    protected function detectShipardUser(string $mode): string
    {
        return $this->shipardUser;
    }

    protected function getPhpVersion(): string
    {
        return '8.5';
    }

    protected function capture(string $shellCmd): array
    {
        $this->captureLog[] = $shellCmd;
        foreach ($this->captureMap as $needle => $result) {
            if (str_contains($shellCmd, $needle)) {
                return $result;
            }
        }
        return ['lines' => [], 'exitCode' => 0];
    }

    protected function runStep(string $shellCmd): int
    {
        $this->stepLog[] = $shellCmd;
        foreach ($this->stepExitCodes as $needle => $code) {
            if (str_contains($shellCmd, $needle)) {
                return $code;
            }
        }
        return 0;
    }
}

class UpgradeCommandTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = sys_get_temp_dir() . '/shpd-upgrade-test-' . uniqid();
        mkdir($this->repoRoot . '/.git', 0755, true);
    }

    protected function tearDown(): void
    {
        @rmdir($this->repoRoot . '/.git');
        @rmdir($this->repoRoot);
    }

    /**
     * @param string[] $changedFiles
     * @return array<string, array{lines: string[], exitCode: int}>
     */
    private function happyCaptureMap(array $changedFiles = [], int $incoming = 3): array
    {
        return [
            'status --porcelain' => ['lines' => [], 'exitCode' => 0],
            'rev-parse --abbrev-ref HEAD' => ['lines' => ['stable'], 'exitCode' => 0],
            'rev-parse --short HEAD' => ['lines' => ['abc1234'], 'exitCode' => 0],
            'rev-list --count' => ['lines' => [(string) $incoming], 'exitCode' => 0],
            'log --oneline' => ['lines' => ['1111111 one', '2222222 two'], 'exitCode' => 0],
            'diff --name-only' => ['lines' => $changedFiles, 'exitCode' => 0],
            'command -v composer' => ['lines' => ['/usr/bin/composer'], 'exitCode' => 0],
            ' fetch' => ['lines' => [], 'exitCode' => 0],
        ];
    }

    // --- computePlan ---

    public function testComputePlanComposerLockOnly(): void
    {
        $cmd = new TestableUpgradeCommand($this->repoRoot);
        $plan = $cmd->computePlan(['composer.lock', 'src/Foo.php'], false, false);
        $this->assertTrue($plan['composer']);
        $this->assertFalse($plan['frontend']);
        $this->assertTrue($plan['dsUpgradeAll']);
    }

    public function testComputePlanFrontendOnly(): void
    {
        $cmd = new TestableUpgradeCommand($this->repoRoot);
        $plan = $cmd->computePlan(['frontend/src/App.svelte'], false, false);
        $this->assertFalse($plan['composer']);
        $this->assertTrue($plan['frontend']);
        $this->assertTrue($plan['dsUpgradeAll']);
    }

    public function testComputePlanNoRelevantChanges(): void
    {
        $cmd = new TestableUpgradeCommand($this->repoRoot);
        $plan = $cmd->computePlan(['src/Foo.php', 'docs/cli.md'], false, false);
        $this->assertFalse($plan['composer']);
        $this->assertFalse($plan['frontend']);
        $this->assertTrue($plan['dsUpgradeAll']);
        $this->assertFalse($plan['nginxReload']);
        $this->assertFalse($plan['fpmReload']);
    }

    public function testComputePlanFullForcesEverything(): void
    {
        $cmd = new TestableUpgradeCommand($this->repoRoot);
        $plan = $cmd->computePlan([], true, false);
        $this->assertTrue($plan['composer']);
        $this->assertTrue($plan['frontend']);
        $this->assertTrue($plan['dsUpgradeAll']);
        $this->assertTrue($plan['nginxReload']);
        $this->assertTrue($plan['fpmReload']);
    }

    public function testComputePlanNginxConfigOnly(): void
    {
        $cmd = new TestableUpgradeCommand($this->repoRoot);
        $plan = $cmd->computePlan(['docs/nginx/shipard-common.conf'], false, false);
        $this->assertTrue($plan['nginxReload']);
        $this->assertFalse($plan['fpmReload']);
        $this->assertFalse($plan['composer']);
        $this->assertFalse($plan['frontend']);
    }

    public function testComputePlanFpmConfigOnly(): void
    {
        $cmd = new TestableUpgradeCommand($this->repoRoot);
        $plan = $cmd->computePlan(['docs/php/shipard-fpm-common.conf'], false, false);
        $this->assertFalse($plan['nginxReload']);
        $this->assertTrue($plan['fpmReload']);
    }

    public function testComputePlanSkipDsUpgrade(): void
    {
        $cmd = new TestableUpgradeCommand($this->repoRoot);
        $plan = $cmd->computePlan(['composer.json'], false, true);
        $this->assertTrue($plan['composer']);
        $this->assertFalse($plan['dsUpgradeAll']);
    }

    public function testComputePlanAlwaysIncludesCronInstall(): void
    {
        $cmd = new TestableUpgradeCommand($this->repoRoot);
        $this->assertTrue($cmd->computePlan([], false, false)['cronInstall']);
        $this->assertTrue($cmd->computePlan(['src/Foo.php'], false, true)['cronInstall']);
        $this->assertTrue($cmd->computePlan([], true, false)['cronInstall']);
    }

    // --- pre-flight aborts ---

    public function testMissingGitDirAborts(): void
    {
        rmdir($this->repoRoot . '/.git');
        $cmd = new TestableUpgradeCommand($this->repoRoot);
        $tester = new CommandTester($cmd);

        $this->assertSame(1, $tester->execute([]));
        $this->assertStringContainsString('Not a git checkout', $tester->getDisplay());
        $this->assertSame([], $cmd->captureLog);
        $this->assertSame([], $cmd->stepLog);
    }

    public function testDirtyWorktreeAborts(): void
    {
        $cmd = new TestableUpgradeCommand($this->repoRoot);
        $cmd->captureMap = ['status --porcelain' => ['lines' => [' M src/Foo.php'], 'exitCode' => 0]];
        $tester = new CommandTester($cmd);

        $this->assertSame(1, $tester->execute([]));
        $display = $tester->getDisplay();
        $this->assertStringContainsString('not clean', $display);
        $this->assertStringContainsString('M src/Foo.php', $display);
        $this->assertSame([], $cmd->stepLog);
    }

    public function testDetachedHeadAborts(): void
    {
        $cmd = new TestableUpgradeCommand($this->repoRoot);
        $cmd->captureMap = $this->happyCaptureMap();
        $cmd->captureMap['rev-parse --abbrev-ref HEAD'] = ['lines' => ['HEAD'], 'exitCode' => 0];
        $tester = new CommandTester($cmd);

        $this->assertSame(1, $tester->execute([]));
        $this->assertStringContainsString('Detached HEAD', $tester->getDisplay());
        $this->assertSame([], $cmd->stepLog);
    }

    public function testNoIncomingCommitsIsEarlySuccess(): void
    {
        $cmd = new TestableUpgradeCommand($this->repoRoot);
        $cmd->captureMap = $this->happyCaptureMap([], 0);
        $tester = new CommandTester($cmd);

        $this->assertSame(0, $tester->execute([]));
        $this->assertStringContainsString('Already up to date.', $tester->getDisplay());
        $this->assertSame([], $cmd->stepLog);
    }

    // --- dry-run ---

    public function testDryRunShowsPlanAndChangesNothing(): void
    {
        $cmd = new TestableUpgradeCommand($this->repoRoot);
        $cmd->captureMap = $this->happyCaptureMap(['composer.lock']);
        $tester = new CommandTester($cmd);

        $this->assertSame(0, $tester->execute(['--dry-run' => true]));
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Incoming commits (3):', $display);
        $this->assertStringContainsString('1111111 one', $display);
        $this->assertStringContainsString('[run]  composer install', $display);
        $this->assertStringContainsString('[skip] frontend build', $display);
        $this->assertStringContainsString('[run]  ds-upgrade-all', $display);
        $this->assertStringContainsString('nothing changed', $display);
        $this->assertSame([], $cmd->stepLog);
    }

    // --- execution ---

    public function testHappyPathRunsPlannedStepsAndSummary(): void
    {
        $cmd = new TestableUpgradeCommand($this->repoRoot);
        $cmd->captureMap = $this->happyCaptureMap(['composer.lock', 'src/Foo.php']);
        $tester = new CommandTester($cmd);

        $this->assertSame(0, $tester->execute([]));
        $this->assertCount(3, $cmd->stepLog); // pull, composer, ds-upgrade-all
        $this->assertStringContainsString('git pull --ff-only', $cmd->stepLog[0]);
        $this->assertStringContainsString('composer', $cmd->stepLog[1]);
        $this->assertStringContainsString('ds-upgrade-all', $cmd->stepLog[2]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('Upgraded abc1234 → abc1234 (3 commits)', $display);
        $this->assertStringContainsString('Skipped: frontend build', $display);
    }

    public function testStepFailureAbortsRemainingSteps(): void
    {
        $cmd = new TestableUpgradeCommand($this->repoRoot);
        $cmd->captureMap = $this->happyCaptureMap(['composer.lock']);
        $cmd->stepExitCodes = ['composer' => 1];
        $tester = new CommandTester($cmd);

        $this->assertSame(1, $tester->execute([]));
        $this->assertCount(2, $cmd->stepLog); // pull + composer, nothing after
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Step "composer install" failed', $display);
        $this->assertStringContainsString('production.md', $display);
    }

    public function testDoctorFailureReportsCodeDeployed(): void
    {
        $cmd = new TestableUpgradeCommand($this->repoRoot, euid: 0, currentUser: 'root', shipardUser: 'shipard');
        $cmd->captureMap = $this->happyCaptureMap();
        $cmd->stepExitCodes = ['doctor' => 1];
        $tester = new CommandTester($cmd);

        $this->assertSame(1, $tester->execute([]));
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Code deployed, but doctor reported issues', $display);
        $this->assertStringContainsString('Upgraded abc1234', $display); // summary is still printed
    }

    // --- user handling ---

    public function testRootWrapsStepsInSudoAndRunsDoctorDirectly(): void
    {
        $cmd = new TestableUpgradeCommand($this->repoRoot, 'production', euid: 0, currentUser: 'root', shipardUser: 'shipard');
        $cmd->captureMap = $this->happyCaptureMap(['frontend/src/App.svelte']);
        $tester = new CommandTester($cmd);

        $this->assertSame(0, $tester->execute([]));
        // git pre-flight subprocesses run via sudo too
        $this->assertStringContainsString("sudo -u 'shipard' -H git", $cmd->captureLog[0]);
        // mutating steps: pull, frontend, ds-upgrade-all wrapped; cron install + completion install + doctor direct as root
        $this->assertCount(6, $cmd->stepLog);
        foreach (array_slice($cmd->stepLog, 0, 3) as $step) {
            $this->assertStringContainsString("sudo -u 'shipard' -H sh -c", $step);
        }
        $this->assertStringContainsString('cron-install', $cmd->stepLog[3]);
        $this->assertStringNotContainsString('sudo', $cmd->stepLog[3]);
        $this->assertStringContainsString('completion-install', $cmd->stepLog[4]);
        $this->assertStringNotContainsString('sudo', $cmd->stepLog[4]);
        $this->assertStringContainsString('doctor', $cmd->stepLog[5]);
        $this->assertStringNotContainsString('sudo', $cmd->stepLog[5]);
    }

    public function testShipardUserRunsWithoutSudoAndSkipsDoctor(): void
    {
        $cmd = new TestableUpgradeCommand($this->repoRoot, 'production', euid: 1001, currentUser: 'shipard', shipardUser: 'shipard');
        $cmd->captureMap = $this->happyCaptureMap();
        $tester = new CommandTester($cmd);

        $this->assertSame(0, $tester->execute([]));
        foreach ($cmd->stepLog as $step) {
            $this->assertStringNotContainsString('sudo', $step);
        }
        $display = $tester->getDisplay();
        $this->assertStringContainsString("run 'sudo shpd-server doctor' to verify", $display);
    }

    public function testProductionOtherUserAborts(): void
    {
        $cmd = new TestableUpgradeCommand($this->repoRoot, 'production', euid: 1000, currentUser: 'dev', shipardUser: 'shipard');
        $tester = new CommandTester($cmd);

        $this->assertSame(1, $tester->execute([]));
        $this->assertStringContainsString('must run as root or as shipard', $tester->getDisplay());
        $this->assertSame([], $cmd->captureLog);
        $this->assertSame([], $cmd->stepLog);
    }

    public function testVerbosityPropagatesToDsUpgradeAll(): void
    {
        $cmd = new TestableUpgradeCommand($this->repoRoot);
        $cmd->captureMap = $this->happyCaptureMap();
        $tester = new CommandTester($cmd);

        $this->assertSame(0, $tester->execute([], ['verbosity' => \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_VERBOSE]));
        $last = end($cmd->stepLog);
        $this->assertStringContainsString('ds-upgrade-all -v', $last);
    }

    // --- service reload steps ---

    public function testRootRunsReloadStepsAfterDsUpgradeAll(): void
    {
        $cmd = new TestableUpgradeCommand($this->repoRoot, euid: 0, currentUser: 'root', shipardUser: 'shipard');
        $cmd->captureMap = $this->happyCaptureMap(['docs/nginx/shipard-common.conf', 'docs/php/shipard-fpm-common.conf']);
        $tester = new CommandTester($cmd);

        $this->assertSame(0, $tester->execute([]));
        // pull, ds-upgrade-all, cron install, completion install, nginx reload, fpm reload,
        // dev https (development — poslední krok před doctorem, #183 U3), doctor
        $this->assertCount(8, $cmd->stepLog);
        $this->assertStringContainsString('ds-upgrade-all', $cmd->stepLog[1]);
        $this->assertStringContainsString('cron-install', $cmd->stepLog[2]);
        $this->assertStringContainsString('completion-install', $cmd->stepLog[3]);
        $this->assertSame('nginx -t && systemctl reload nginx', $cmd->stepLog[4]);
        $this->assertSame('systemctl reload php8.5-fpm', $cmd->stepLog[5]);
        $this->assertStringEndsWith("/bin/shpd-server' dev-https", $cmd->stepLog[6]);
        $this->assertStringNotContainsString('sudo', $cmd->stepLog[6]);
        $this->assertStringContainsString('doctor', $cmd->stepLog[7]);
    }

    // --- dev https (#183 U3, U22) ---

    public function testDevelopmentRootRunsDevHttpsRightBeforeDoctor(): void
    {
        $cmd = new TestableUpgradeCommand($this->repoRoot, euid: 0, currentUser: 'root', shipardUser: 'dev');
        $cmd->captureMap = $this->happyCaptureMap();
        $tester = new CommandTester($cmd);

        $this->assertSame(0, $tester->execute([]));
        $count = count($cmd->stepLog);
        $this->assertStringEndsWith("/bin/shpd-server' dev-https", $cmd->stepLog[$count - 2]);
        $this->assertStringEndsWith("/bin/shpd-server' doctor", $cmd->stepLog[$count - 1]);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('[run]  dev https', $display);
        $this->assertMatchesRegularExpression('/Steps: .*dev https, doctor/', $display);
    }

    public function testProductionNeverRunsDevHttps(): void
    {
        $cmd = new TestableUpgradeCommand($this->repoRoot, 'production', euid: 0, currentUser: 'root', shipardUser: 'shipard');
        $cmd->captureMap = $this->happyCaptureMap();
        $tester = new CommandTester($cmd);

        $this->assertSame(0, $tester->execute([]));
        foreach ($cmd->stepLog as $step) {
            $this->assertStringNotContainsString('dev-https', $step);
        }
        $this->assertStringNotContainsString('dev https', $tester->getDisplay());
    }

    public function testDevelopmentNonRootSkipsDevHttpsAndPrintsHint(): void
    {
        $cmd = new TestableUpgradeCommand($this->repoRoot);
        $cmd->captureMap = $this->happyCaptureMap();
        $tester = new CommandTester($cmd);

        $this->assertSame(0, $tester->execute([]));
        foreach ($cmd->stepLog as $step) {
            $this->assertStringNotContainsString('dev-https', $step);
        }
        $display = $tester->getDisplay();
        $this->assertStringContainsString('[skip] dev https (not running as root)', $display);
        $this->assertStringContainsString('sudo shpd-server dev-https', $display);
    }

    public function testDevHttpsFailureDoesNotStopTheUpgrade(): void
    {
        // U22: doctor proběhne, souhrn se vypíše, upgrade skončí nenulově.
        $cmd = new TestableUpgradeCommand($this->repoRoot, euid: 0, currentUser: 'root', shipardUser: 'dev');
        $cmd->captureMap = $this->happyCaptureMap();
        $cmd->stepExitCodes = ['dev-https' => 1];
        $tester = new CommandTester($cmd);

        $this->assertSame(1, $tester->execute([]));
        $this->assertStringEndsWith("/bin/shpd-server' doctor", $cmd->stepLog[count($cmd->stepLog) - 1]);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Upgraded abc1234', $display);
        $this->assertStringContainsString('Code deployed, but dev-https failed', $display);
        $this->assertStringContainsString('sudo shpd-server dev-https', $display);
        $this->assertStringNotContainsString('upgrade aborted', $display);
        $this->assertStringNotContainsString('doctor reported issues', $display);
        $this->assertDoesNotMatchRegularExpression('/Steps: .*dev https/', $display);
    }

    public function testNonRootSkipsReloadAndPrintsHint(): void
    {
        $cmd = new TestableUpgradeCommand($this->repoRoot);
        $cmd->captureMap = $this->happyCaptureMap(['docs/nginx/shipard-common.conf']);
        $tester = new CommandTester($cmd);

        $this->assertSame(0, $tester->execute([]));
        foreach ($cmd->stepLog as $step) {
            $this->assertStringNotContainsString('systemctl', $step);
        }
        $display = $tester->getDisplay();
        $this->assertStringContainsString('[skip] nginx reload (not running as root)', $display);
        $this->assertStringContainsString('sudo nginx -t && sudo systemctl reload nginx', $display);
        $this->assertStringNotContainsString('sudo systemctl reload php8.5-fpm', $display);
    }

    public function testNonRootSkipsCronInstallAndPrintsHint(): void
    {
        $cmd = new TestableUpgradeCommand($this->repoRoot);
        $cmd->captureMap = $this->happyCaptureMap();
        $tester = new CommandTester($cmd);

        $this->assertSame(0, $tester->execute([]));
        foreach ($cmd->stepLog as $step) {
            $this->assertStringNotContainsString('cron-install', $step);
        }
        $display = $tester->getDisplay();
        $this->assertStringContainsString('[skip] cron install (not running as root)', $display);
        $this->assertStringContainsString('sudo shpd-server cron-install', $display);
        $this->assertStringContainsString('[skip] completion install (not running as root)', $display);
        $this->assertStringContainsString('sudo shpd-server completion-install', $display);
    }

    public function testDryRunShowsCronInstallPlan(): void
    {
        $cmd = new TestableUpgradeCommand($this->repoRoot, euid: 0, currentUser: 'root', shipardUser: 'shipard');
        $cmd->captureMap = $this->happyCaptureMap();
        $tester = new CommandTester($cmd);

        $this->assertSame(0, $tester->execute(['--dry-run' => true]));
        $this->assertStringContainsString('[run]  cron install', $tester->getDisplay());
        $this->assertStringContainsString('[run]  completion install', $tester->getDisplay());
        $this->assertSame([], $cmd->stepLog);
    }

    public function testNginxReloadFailureReportsCodeDeployed(): void
    {
        $cmd = new TestableUpgradeCommand($this->repoRoot, euid: 0, currentUser: 'root', shipardUser: 'shipard');
        $cmd->captureMap = $this->happyCaptureMap(['docs/nginx/shipard-common.conf']);
        $cmd->stepExitCodes = ['nginx -t' => 1];
        $tester = new CommandTester($cmd);

        $this->assertSame(1, $tester->execute([]));
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Step "nginx reload" failed', $display);
        $this->assertStringContainsString('Code is deployed — only the service config/reload is broken.', $display);
        $this->assertStringContainsString('nginx -t && systemctl reload nginx', $display);
    }

    public function testDryRunShowsReloadPlan(): void
    {
        $cmd = new TestableUpgradeCommand($this->repoRoot, euid: 0, currentUser: 'root', shipardUser: 'shipard');
        $cmd->captureMap = $this->happyCaptureMap(['docs/nginx/shipard-common.conf']);
        $tester = new CommandTester($cmd);

        $this->assertSame(0, $tester->execute(['--dry-run' => true]));
        $display = $tester->getDisplay();
        $this->assertStringContainsString('[run]  nginx reload (docs/nginx/ changed)', $display);
        $this->assertStringContainsString('[skip] php-fpm reload (no docs/php/ changes)', $display);
        $this->assertSame([], $cmd->stepLog);
    }
}

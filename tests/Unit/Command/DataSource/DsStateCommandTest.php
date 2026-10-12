<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\DataSource;

use PHPUnit\Framework\TestCase;
use Shipard\Command\DataSource\DsStateCommand;
use Shipard\Core\Config\DataSourceState;
use Shipard\Core\Logging\ErrorLogger;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Tester\CommandTester;

class TestableDsStateCommand extends DsStateCommand
{
    public function __construct(private readonly string $dsDir)
    {
        parent::__construct();
    }

    protected function getDataSourceDir(): string
    {
        return $this->dsDir;
    }

    protected function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-09-02T12:00:00Z');
    }
}

class DsStateCommandTest extends TestCase
{
    private string $dsDir;

    protected function setUp(): void
    {
        ErrorLogger::resetForTesting();
        $this->dsDir = sys_get_temp_dir() . '/shpd-ds-state-cmd-' . uniqid();
        mkdir($this->dsDir . '/config', 0755, true);
        file_put_contents($this->dsDir . '/config/main.json', '{}');
        ErrorLogger::setLogPath($this->dsDir . '/shipard.log');
    }

    protected function tearDown(): void
    {
        ErrorLogger::resetForTesting();
        foreach (glob($this->dsDir . '/config/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->dsDir . '/config');
        @unlink($this->dsDir . '/shipard.log');
        @rmdir($this->dsDir);
    }

    private function makeTester(?string $dsDir = null): CommandTester
    {
        $cmd = new TestableDsStateCommand($dsDir ?? $this->dsDir);
        $cmd->setHelperSet(new HelperSet([new QuestionHelper()]));
        return new CommandTester($cmd);
    }

    private function stateFile(): array
    {
        return json_decode((string) file_get_contents(DataSourceState::filePath($this->dsDir)), true);
    }

    public function testShowWithoutFileReportsDefaultActive(): void
    {
        $tester = $this->makeTester();
        $this->assertSame(0, $tester->execute([]));
        $display = $tester->getDisplay();
        $this->assertStringContainsString('State:             active', $display);
        $this->assertStringContainsString('Maintenance:       off', $display);
        $this->assertStringContainsString('no state.json', $display);
    }

    public function testNotADataSourceDirFails(): void
    {
        $tester = $this->makeTester($this->dsDir . '/nonexistent');
        $this->assertSame(1, $tester->execute(['action' => 'show']));
        $this->assertStringContainsString('Not a Shipard data source directory', $tester->getDisplay());
    }

    public function testSetReadOnlyWritesFile(): void
    {
        $tester = $this->makeTester();
        $this->assertSame(0, $tester->execute(['action' => 'set', 'value' => 'read_only']));
        $display = $tester->getDisplay();
        $this->assertStringContainsString('State set to read_only', $display);
        // Fáze 2: HTTP read-only vynucuje ReadOnlyPolicy — žádné upozornění.
        $this->assertStringNotContainsString('phase 2', $display);
        $this->assertStringContainsString('Effective state: read_only', $display);

        $this->assertSame([
            'version' => 1,
            'state' => 'read_only',
            'changedBy' => 'cli',
            'changed' => '2026-09-02T12:00:00Z',
        ], $this->stateFile());
    }

    public function testSetInvalidStateFails(): void
    {
        $tester = $this->makeTester();
        $this->assertSame(1, $tester->execute(['action' => 'set', 'value' => 'frozen']));
        $this->assertStringContainsString('Usage: ds-state set', $tester->getDisplay());
        $this->assertFileDoesNotExist(DataSourceState::filePath($this->dsDir));
    }

    public function testMaintenanceOnDefaultsToManualAndOverridesState(): void
    {
        $tester = $this->makeTester();
        $this->assertSame(0, $tester->execute(['action' => 'maintenance', '--on' => true]));
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Maintenance on (reason: manual)', $display);
        $this->assertStringContainsString('Effective state: suspended', $display);
        $this->assertStringContainsString('maintenance --off', $display);

        $file = $this->stateFile();
        $this->assertSame('active', $file['state']);
        $this->assertSame(['reason' => 'manual', 'since' => '2026-09-02T12:00:00Z'], $file['maintenance']);

        // show odráží overlay
        $tester = $this->makeTester();
        $tester->execute([]);
        $this->assertStringContainsString('Maintenance:       on (reason: manual', $tester->getDisplay());
        $this->assertStringContainsString('Effective:         suspended', $tester->getDisplay());
    }

    public function testMaintenanceOnWithReasonThenOff(): void
    {
        $tester = $this->makeTester();
        $this->assertSame(0, $tester->execute(['action' => 'maintenance', '--on' => true, '--reason' => 'import']));
        $this->assertSame('import', $this->stateFile()['maintenance']['reason']);

        $tester = $this->makeTester();
        $this->assertSame(0, $tester->execute(['action' => 'maintenance', '--off' => true]));
        $this->assertStringContainsString('Maintenance off', $tester->getDisplay());
        $this->assertStringContainsString('Effective state: active', $tester->getDisplay());
        $this->assertArrayNotHasKey('maintenance', $this->stateFile());
    }

    public function testMaintenanceInvalidReasonFails(): void
    {
        $tester = $this->makeTester();
        $this->assertSame(1, $tester->execute(['action' => 'maintenance', '--on' => true, '--reason' => 'vacation']));
        $this->assertStringContainsString("Unknown --reason 'vacation'", $tester->getDisplay());
    }

    public function testMaintenanceWithoutOnOrOffFails(): void
    {
        $tester = $this->makeTester();
        $this->assertSame(1, $tester->execute(['action' => 'maintenance']));
        $this->assertSame(1, $this->makeTester()->execute(['action' => 'maintenance', '--on' => true, '--off' => true]));
    }

    public function testMaintenanceOffWhenNotActiveIsNoop(): void
    {
        $tester = $this->makeTester();
        $this->assertSame(0, $tester->execute(['action' => 'maintenance', '--off' => true]));
        $this->assertStringContainsString('not active', $tester->getDisplay());
        $this->assertFileDoesNotExist(DataSourceState::filePath($this->dsDir));
    }

    public function testSetActiveUnderMaintenanceKeepsEffectiveSuspended(): void
    {
        $this->makeTester()->execute(['action' => 'maintenance', '--on' => true]);
        $tester = $this->makeTester();
        $tester->execute(['action' => 'set', 'value' => 'active']);
        $this->assertStringContainsString('Effective state: suspended — maintenance overrides', $tester->getDisplay());
    }

    public function testPendingDeletionRequiresDeleteAfter(): void
    {
        $tester = $this->makeTester();
        $this->assertSame(1, $tester->execute(['action' => 'set', 'value' => 'pending_deletion', '--yes' => true]));
        $this->assertStringContainsString('requires --delete-after', $tester->getDisplay());
        $this->assertFileDoesNotExist(DataSourceState::filePath($this->dsDir));
    }

    public function testPendingDeletionRejectsInvalidOrPastDate(): void
    {
        $tester = $this->makeTester();
        $this->assertSame(1, $tester->execute(['action' => 'set', 'value' => 'pending_deletion', '--delete-after' => 'someday', '--yes' => true]));
        $this->assertStringContainsString('Invalid --delete-after', $tester->getDisplay());

        $tester = $this->makeTester();
        $this->assertSame(1, $tester->execute(['action' => 'set', 'value' => 'pending_deletion', '--delete-after' => '2026-01-01', '--yes' => true]));
        $this->assertStringContainsString('must be in the future', $tester->getDisplay());
    }

    public function testPendingDeletionDeclinedConfirmationAborts(): void
    {
        $tester = $this->makeTester();
        $tester->setInputs(['n']);
        $this->assertSame(1, $tester->execute(['action' => 'set', 'value' => 'pending_deletion', '--delete-after' => '2026-10-01']));
        $this->assertStringContainsString('Aborted', $tester->getDisplay());
        $this->assertFileDoesNotExist(DataSourceState::filePath($this->dsDir));
    }

    public function testPendingDeletionConfirmedWritesDeleteAfterUtc(): void
    {
        $tester = $this->makeTester();
        $tester->setInputs(['y']);
        $this->assertSame(0, $tester->execute(['action' => 'set', 'value' => 'pending_deletion', '--delete-after' => '2026-10-01T02:00:00+02:00']));
        $file = $this->stateFile();
        $this->assertSame('pending_deletion', $file['state']);
        $this->assertSame('2026-10-01T00:00:00Z', $file['deleteAfter']);
        $this->assertStringContainsString('HTTP returns 503', $tester->getDisplay());

        // Návrat do active deleteAfter zahodí.
        $this->makeTester()->execute(['action' => 'set', 'value' => 'active']);
        $this->assertArrayNotHasKey('deleteAfter', $this->stateFile());
    }

    public function testPendingDeletionWithYesSkipsPrompt(): void
    {
        $tester = $this->makeTester();
        $this->assertSame(0, $tester->execute(['action' => 'set', 'value' => 'pending_deletion', '--delete-after' => '2026-10-01', '--yes' => true]));
        $this->assertSame('pending_deletion', $this->stateFile()['state']);
    }

    public function testShowOnCorruptedFileFailsWithHint(): void
    {
        file_put_contents(DataSourceState::filePath($this->dsDir), '{broken');
        $tester = $this->makeTester();
        $this->assertSame(1, $tester->execute([]));
        $this->assertStringContainsString('fail-closed', $tester->getDisplay());
    }

    public function testSetRepairsCorruptedFile(): void
    {
        file_put_contents(DataSourceState::filePath($this->dsDir), '{broken');
        $tester = $this->makeTester();
        $this->assertSame(0, $tester->execute(['action' => 'set', 'value' => 'active']));
        $this->assertStringContainsString('overwriting', $tester->getDisplay());
        $this->assertSame('active', DataSourceState::load($this->dsDir)->getState());
    }

    public function testMaintenanceOnCorruptedFileRefuses(): void
    {
        file_put_contents(DataSourceState::filePath($this->dsDir), '{broken');
        $tester = $this->makeTester();
        $this->assertSame(1, $tester->execute(['action' => 'maintenance', '--on' => true]));
        $this->assertFileDoesNotExist(DataSourceState::filePath($this->dsDir) . '.tmp');
    }

    public function testUnknownActionFails(): void
    {
        $tester = $this->makeTester();
        $this->assertSame(1, $tester->execute(['action' => 'frobnicate']));
        $this->assertStringContainsString("Unknown action 'frobnicate'", $tester->getDisplay());
    }

    // ── cron --on | --off (#115 D14) ────────────────────────────────────────

    public function testCronOffWritesFileAndShowReportsIt(): void
    {
        $tester = $this->makeTester();
        $this->assertSame(0, $tester->execute(['action' => 'cron', '--off' => true]));
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Cron off', $display);
        $this->assertStringContainsString('Effective state: active', $display);

        $this->assertSame([
            'version' => 1,
            'state' => 'active',
            'cron' => false,
            'changedBy' => 'cli',
            'changed' => '2026-09-02T12:00:00Z',
        ], $this->stateFile());

        $tester = $this->makeTester();
        $this->assertSame(0, $tester->execute([]));
        $this->assertStringContainsString('Cron:              off (periodic jobs are not running)', $tester->getDisplay());
        $this->assertStringContainsString('State:             active', $tester->getDisplay());
    }

    public function testShowWithoutFileReportsCronOn(): void
    {
        $tester = $this->makeTester();
        $tester->execute([]);
        $this->assertStringContainsString('Cron:              on', $tester->getDisplay());
    }

    public function testCronOnRestoresDefaultAndDropsKey(): void
    {
        $this->makeTester()->execute(['action' => 'cron', '--off' => true]);

        $tester = $this->makeTester();
        $this->assertSame(0, $tester->execute(['action' => 'cron', '--on' => true]));
        $this->assertStringContainsString('Cron on', $tester->getDisplay());
        $this->assertArrayNotHasKey('cron', $this->stateFile());
        $this->assertTrue(DataSourceState::load($this->dsDir)->isCronEnabled());
    }

    public function testCronNoopDoesNotRewriteFile(): void
    {
        // Bez souboru je cron zapnutý — --on nic nezaloží.
        $tester = $this->makeTester();
        $this->assertSame(0, $tester->execute(['action' => 'cron', '--on' => true]));
        $this->assertStringContainsString('already on', $tester->getDisplay());
        $this->assertFileDoesNotExist(DataSourceState::filePath($this->dsDir));

        $this->makeTester()->execute(['action' => 'cron', '--off' => true]);
        $before = (string) file_get_contents(DataSourceState::filePath($this->dsDir));
        file_put_contents(DataSourceState::filePath($this->dsDir), str_replace('2026-09-02T12:00:00Z', '2026-01-01T00:00:00Z', $before));
        $marked = (string) file_get_contents(DataSourceState::filePath($this->dsDir));

        $tester = $this->makeTester();
        $this->assertSame(0, $tester->execute(['action' => 'cron', '--off' => true]));
        $this->assertStringContainsString('already off', $tester->getDisplay());
        $this->assertSame($marked, file_get_contents(DataSourceState::filePath($this->dsDir)));
    }

    public function testCronWithoutOnOrOffFails(): void
    {
        $tester = $this->makeTester();
        $this->assertSame(1, $tester->execute(['action' => 'cron']));
        $this->assertStringContainsString('Usage: ds-state cron --on | --off', $tester->getDisplay());
        $this->assertSame(1, $this->makeTester()->execute(['action' => 'cron', '--on' => true, '--off' => true]));
        $this->assertFileDoesNotExist(DataSourceState::filePath($this->dsDir));
    }

    public function testCronOnCorruptedFileRefuses(): void
    {
        file_put_contents(DataSourceState::filePath($this->dsDir), '{broken');
        $tester = $this->makeTester();
        $this->assertSame(1, $tester->execute(['action' => 'cron', '--off' => true]));
        $this->assertStringContainsString('repair it first', $tester->getDisplay());
        $this->assertSame('{broken', file_get_contents(DataSourceState::filePath($this->dsDir)));
    }

    public function testMaintenanceAndSetKeepCronSwitch(): void
    {
        $this->makeTester()->execute(['action' => 'cron', '--off' => true]);

        $this->assertSame(0, $this->makeTester()->execute(['action' => 'maintenance', '--on' => true, '--reason' => 'import']));
        $this->assertFalse($this->stateFile()['cron']);
        $this->assertSame('import', $this->stateFile()['maintenance']['reason']);

        $this->assertSame(0, $this->makeTester()->execute(['action' => 'maintenance', '--off' => true]));
        $this->assertFalse($this->stateFile()['cron']);

        $this->assertSame(0, $this->makeTester()->execute(['action' => 'set', 'value' => 'read_only']));
        $this->assertFalse($this->stateFile()['cron']);
        $this->assertSame('read_only', $this->stateFile()['state']);

        // A naopak: cron --on nesahá na stav.
        $this->assertSame(0, $this->makeTester()->execute(['action' => 'cron', '--on' => true]));
        $this->assertSame('read_only', $this->stateFile()['state']);
    }
}

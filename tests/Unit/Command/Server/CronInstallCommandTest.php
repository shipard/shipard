<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\Server;

use PHPUnit\Framework\TestCase;
use Shipard\Command\Server\CronInstallCommand;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Server\CronProvisioner;
use Symfony\Component\Console\Tester\CommandTester;

class TestableCronInstallCommand extends CronInstallCommand
{
    /** @var list<array{path: string, user: string}> */
    public array $ownershipLog = [];

    public function __construct(
        private readonly string $cronFilePath,
        private readonly string $runDir,
        private readonly int $euid = 0,
        private readonly string $mode = 'production',
        private readonly string $dataSourcesDir = '/nonexistent/shpd-data-sources',
    ) {
        parent::__construct();
    }

    protected function getCronFilePath(): string
    {
        return $this->cronFilePath;
    }

    protected function getRunDir(): string
    {
        return $this->runDir;
    }

    protected function getDataSourcesDir(): string
    {
        return $this->dataSourcesDir;
    }

    protected function getRepoRoot(): string
    {
        return '/opt/shipard/shpd';
    }

    protected function getPhpBinary(): string
    {
        return '/usr/bin/php8.5';
    }

    protected function getEuid(): int
    {
        return $this->euid;
    }

    protected function getServerMode(): string
    {
        return $this->mode;
    }

    protected function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-13T08:00:00Z');
    }

    protected function applyOwnership(string $path, string $user): void
    {
        $this->ownershipLog[] = ['path' => $path, 'user' => $user];
    }
}

class CronInstallCommandTest extends TestCase
{
    private const string DS_A = 'aaaa-aaaa-aaaa-aaaa';
    private const string DS_B = 'bbbb-bbbb-bbbb-bbbb';

    private string $tmpDir;
    private string $cronFile;
    private string $runDir;
    private string $dsDir;

    protected function setUp(): void
    {
        ErrorLogger::resetForTesting();
        $this->tmpDir = sys_get_temp_dir() . '/shpd-croninstall-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        $this->cronFile = $this->tmpDir . '/cron.d/shipard';
        mkdir($this->tmpDir . '/cron.d', 0755, true);
        $this->runDir = $this->tmpDir . '/run';
        $this->dsDir = $this->tmpDir . '/data-sources';
        mkdir($this->dsDir, 0755, true);
        ErrorLogger::setLogPath($this->tmpDir . '/shipard.log');
    }

    protected function tearDown(): void
    {
        // Test selhání zápisu nechává config/ bez práva zápisu — bez opravy by úklid selhal.
        exec('chmod -R u+rwx ' . escapeshellarg($this->tmpDir));
        $this->rmdirRecursive($this->tmpDir);
        ErrorLogger::resetForTesting();
    }

    /** @return array{TestableCronInstallCommand, CommandTester} */
    private function makeTester(int $euid = 0, string $mode = 'production'): array
    {
        $cmd = new TestableCronInstallCommand($this->cronFile, $this->runDir, $euid, $mode, $this->dsDir);
        return [$cmd, new CommandTester($cmd)];
    }

    private function createDs(string $id, ?string $stateJson = null, string $mainJson = '{"name":"DS"}'): string
    {
        $dir = $this->dsDir . '/' . $id;
        mkdir($dir . '/config', 0755, true);
        file_put_contents($dir . '/config/main.json', $mainJson);
        if ($stateJson !== null) {
            file_put_contents($dir . '/config/state.json', $stateJson);
        }
        return $dir;
    }

    /** @return array<string, mixed>|null null = soubor neexistuje */
    private function stateFile(string $id): ?array
    {
        $path = $this->dsDir . '/' . $id . '/config/state.json';
        if (!is_file($path)) {
            return null;
        }
        return json_decode((string) file_get_contents($path), true);
    }

    // ── Generátor souboru (beze změny chování) ──────────────────────────────

    public function testFreshInstallCreatesFileAndRunDir(): void
    {
        [$cmd, $tester] = $this->makeTester();

        $exit = $tester->execute([]);

        $this->assertSame(0, $exit);
        $this->assertFileExists($this->cronFile);
        $content = (string) file_get_contents($this->cronFile);
        $this->assertSame(CronProvisioner::TEMPLATE_VERSION, CronProvisioner::parseTemplateVersion($content));
        foreach (CronProvisioner::SLOTS as $slot) {
            $this->assertStringContainsString('shpd-server cron --slot=' . $slot, $content);
        }
        $this->assertStringContainsString('shipard /usr/bin/php8.5 /opt/shipard/shpd/bin/shpd-server', $content);

        $this->assertDirectoryExists($this->runDir);
        $this->assertSame(0750, fileperms($this->runDir) & 0777);
        $this->assertSame([['path' => $this->runDir, 'user' => 'shipard']], $cmd->ownershipLog);
        $this->assertStringContainsString('written (template version', $tester->getDisplay());
    }

    public function testSecondRunIsIdempotent(): void
    {
        [, $tester1] = $this->makeTester();
        $tester1->execute([]);
        $mtime = filemtime($this->cronFile);
        touch($this->cronFile, $mtime - 100);
        clearstatcache();
        $mtime = filemtime($this->cronFile);

        [, $tester2] = $this->makeTester();
        $exit = $tester2->execute([]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('up to date', $tester2->getDisplay());
        clearstatcache();
        $this->assertSame($mtime, filemtime($this->cronFile));
    }

    public function testTamperedFileIsRewritten(): void
    {
        file_put_contents($this->cronFile, "# template version 0 — old\n* * * * * root /bin/true\n");

        [, $tester] = $this->makeTester();
        $exit = $tester->execute([]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('written (template version', $tester->getDisplay());
        $content = (string) file_get_contents($this->cronFile);
        $this->assertSame(CronProvisioner::TEMPLATE_VERSION, CronProvisioner::parseTemplateVersion($content));
        $this->assertStringNotContainsString('/bin/true', $content);
    }

    public function testNonRootFails(): void
    {
        [, $tester] = $this->makeTester(euid: 1000);

        $exit = $tester->execute([]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('must run as root', $tester->getDisplay());
        $this->assertFileDoesNotExist($this->cronFile);
    }

    public function testDryRunWritesNothing(): void
    {
        [, $tester] = $this->makeTester(euid: 1000);

        $exit = $tester->execute(['--dry-run' => true]);

        $this->assertSame(0, $exit);
        $this->assertFileDoesNotExist($this->cronFile);
        $this->assertDirectoryDoesNotExist($this->runDir);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Would create the file', $display);
        $this->assertStringContainsString('cron --slot=minute', $display);
    }

    public function testDryRunReportsUpToDate(): void
    {
        [, $tester1] = $this->makeTester();
        $tester1->execute([]);

        [, $tester2] = $this->makeTester(euid: 1000);
        $exit = $tester2->execute(['--dry-run' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Up to date', $tester2->getDisplay());
    }

    // ── První instalace mimo produkci (#115 D10) ────────────────────────────

    public function testFirstInstallOutsideProductionSwitchesCronOffBeforeWritingFile(): void
    {
        $this->createDs(self::DS_A, null, '{"name":"Alpha"}');
        $this->createDs(self::DS_B, '{"version":1,"state":"active","cron":false}', '{"name":"Beta"}');

        [, $tester] = $this->makeTester(mode: 'development');
        $exit = $tester->execute([]);

        $this->assertSame(0, $exit);
        $this->assertFileExists($this->cronFile);
        $this->assertFalse($this->stateFile(self::DS_A)['cron'] ?? null, 'cron off written for the data source without state.json');
        $this->assertSame('cli', $this->stateFile(self::DS_A)['changedBy']);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('switching periodic jobs off for 2 existing data source(s)', $display);
        $this->assertStringContainsString('✓ ' . self::DS_A . ': cron off   (Alpha)', $display);
        $this->assertStringContainsString('· ' . self::DS_B . ': unchanged (cron off)   (Beta)', $display);
        $this->assertStringContainsString('Periodic jobs are off for 2 data source(s).', $display);
        $this->assertStringContainsString('sudo shpd-server ds-cron --on <id>', $display);
        $this->assertStringContainsString('written (template version', $display);
        // Nejdřív vypnout, potom zapsat — řádky o zdrojích dat předcházejí zápisu souboru.
        $this->assertLessThan(
            strpos($display, 'written (template version'),
            strpos($display, ': cron off'),
        );
    }

    public function testFirstInstallOnProductionLeavesDataSourcesAlone(): void
    {
        $this->createDs(self::DS_A);

        [, $tester] = $this->makeTester(mode: 'production');
        $exit = $tester->execute([]);

        $this->assertSame(0, $exit);
        $this->assertFileExists($this->cronFile);
        $this->assertNull($this->stateFile(self::DS_A));
        $this->assertStringNotContainsString('First install', $tester->getDisplay());
    }

    public function testKeepDsCronLeavesDataSourcesAlone(): void
    {
        $this->createDs(self::DS_A);

        [, $tester] = $this->makeTester(mode: 'development');
        $exit = $tester->execute(['--keep-ds-cron' => true]);

        $this->assertSame(0, $exit);
        $this->assertFileExists($this->cronFile);
        $this->assertNull($this->stateFile(self::DS_A));
        $this->assertStringNotContainsString('switching periodic jobs off', $tester->getDisplay());
    }

    public function testRegeneratingExistingFileLeavesDataSourcesAlone(): void
    {
        file_put_contents($this->cronFile, "# template version 0 — old\n* * * * * root /bin/true\n");
        $this->createDs(self::DS_A);

        [, $tester] = $this->makeTester(mode: 'development');
        $exit = $tester->execute([]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('written (template version', $tester->getDisplay());
        $this->assertNull($this->stateFile(self::DS_A));
        $this->assertStringNotContainsString('First install', $tester->getDisplay());
    }

    public function testFirstInstallWithoutDataSourcesJustWritesFile(): void
    {
        [, $tester] = $this->makeTester(mode: 'development');
        $exit = $tester->execute([]);

        $this->assertSame(0, $exit);
        $this->assertFileExists($this->cronFile);
        $this->assertStringContainsString('no data sources — nothing to switch off', $tester->getDisplay());
    }

    public function testFirstInstallFailsAndWritesNoCronFileWhenSwitchingFails(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root ignores directory modes — the write cannot be made to fail');
        }
        $this->createDs(self::DS_A);
        $failing = $this->createDs(self::DS_B, null, '{"name":"Locked"}');
        chmod($failing . '/config', 0500);

        [, $tester] = $this->makeTester(mode: 'development');
        $exit = $tester->execute([]);

        $this->assertSame(1, $exit);
        $this->assertFileDoesNotExist($this->cronFile);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('✓ ' . self::DS_A . ': cron off', $display);
        $this->assertStringContainsString('✗ ' . self::DS_B . ':', $display);
        $this->assertStringContainsString('Could not switch periodic jobs off for 1 data source(s)', $display);
        $this->assertStringContainsString('NOT written', $display);
    }

    public function testCorruptedStateFileDoesNotBlockFirstInstall(): void
    {
        $corrupted = '{"version":1,"state":"nope"}';
        $this->createDs(self::DS_A, $corrupted);
        $this->createDs(self::DS_B);

        [, $tester] = $this->makeTester(mode: 'development');
        $exit = $tester->execute([]);

        $this->assertSame(0, $exit);
        $this->assertFileExists($this->cronFile);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('· ' . self::DS_A . ': state.json unusable — skipped', $display);
        $this->assertStringContainsString('✓ ' . self::DS_B . ': cron off', $display);
        $this->assertStringContainsString('Periodic jobs are off for 1 data source(s) (1 skipped).', $display);
        $this->assertSame($corrupted, file_get_contents($this->dsDir . '/' . self::DS_A . '/config/state.json'));
        $this->assertFalse($this->stateFile(self::DS_B)['cron'] ?? null);
    }

    public function testDryRunFirstInstallOutsideProductionListsDataSourcesAndWritesNothing(): void
    {
        $this->createDs(self::DS_A, null, '{"name":"Alpha"}');
        $this->createDs(self::DS_B, '{"version":1,"state":"active","cron":false}', '{"name":"Beta"}');

        [, $tester] = $this->makeTester(euid: 1000, mode: 'development');
        $exit = $tester->execute(['--dry-run' => true]);

        $this->assertSame(0, $exit);
        $this->assertFileDoesNotExist($this->cronFile);
        $this->assertDirectoryDoesNotExist($this->runDir);
        $this->assertNull($this->stateFile(self::DS_A));
        $display = $tester->getDisplay();
        $this->assertStringContainsString('would be switched off for 2 data source(s)', $display);
        $this->assertStringContainsString(self::DS_A . '  cron on → off   (Alpha)', $display);
        $this->assertStringContainsString(self::DS_B . '  cron off (unchanged)   (Beta)', $display);
        $this->assertStringContainsString('--keep-ds-cron', $display);
    }

    public function testDryRunWithKeepDsCronSaysDataSourcesStayUntouched(): void
    {
        $this->createDs(self::DS_A);

        [, $tester] = $this->makeTester(euid: 1000, mode: 'development');
        $exit = $tester->execute(['--dry-run' => true, '--keep-ds-cron' => true]);

        $this->assertSame(0, $exit);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('keep their periodic-jobs switch', $display);
        $this->assertStringNotContainsString('would be switched off', $display);
    }

    public function testDryRunOnProductionHasNoSwitchOffPreview(): void
    {
        $this->createDs(self::DS_A);

        [, $tester] = $this->makeTester(euid: 1000, mode: 'production');
        $tester->execute(['--dry-run' => true]);

        $this->assertStringNotContainsString('First install', $tester->getDisplay());
    }

    // ── --check (U2) ────────────────────────────────────────────────────────

    public function testCheckReturnsZeroWithoutOutputWhenUpToDate(): void
    {
        [, $tester1] = $this->makeTester();
        $tester1->execute([]);

        [, $tester2] = $this->makeTester(euid: 1000);
        $exit = $tester2->execute(['--check' => true]);

        $this->assertSame(0, $exit);
        $this->assertSame('', $tester2->getDisplay());
    }

    public function testCheckReturnsOneWhenFileIsMissingOrDiffers(): void
    {
        [, $missing] = $this->makeTester(euid: 1000);
        $this->assertSame(1, $missing->execute(['--check' => true]));
        $this->assertSame('', $missing->getDisplay());

        file_put_contents($this->cronFile, "# template version 0 — old\n");
        [, $differs] = $this->makeTester(euid: 1000);
        $this->assertSame(1, $differs->execute(['--check' => true]));
        $this->assertSame('', $differs->getDisplay());
    }

    public function testCheckTouchesNeitherDataSourcesNorRunDir(): void
    {
        $this->createDs(self::DS_A);

        [, $tester] = $this->makeTester(euid: 1000, mode: 'development');
        $tester->execute(['--check' => true, '--dry-run' => true]);

        $this->assertFileDoesNotExist($this->cronFile);
        $this->assertDirectoryDoesNotExist($this->runDir);
        $this->assertNull($this->stateFile(self::DS_A));
        $this->assertSame('', $tester->getDisplay(), '--check wins over --dry-run and stays silent');
    }

    private function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmdirRecursive($path) : unlink($path);
        }
        rmdir($dir);
    }
}

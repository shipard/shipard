<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\Server;

use PHPUnit\Framework\TestCase;
use Shipard\Command\Server\DoctorCommand;
use Shipard\Core\Server\HealthChecker;
use Shipard\Core\Server\PermissionSpec;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

class TestableDoctorCommand extends DoctorCommand
{
    public ?string $stubPoolUser = null;
    public bool $skipDbCheck = true;
    public int $stubDbErrors = 0;
    public ?string $fakePoolConfigGlob = null;
    public ?string $fakeNginxSitesEnabledDir = null;
    public ?string $fakeRepoRoot = null;

    /** @var array<string, ?string> binary name → fake path (null = missing) */
    public array $fakeBinaries = [];

    public ?string $fakeCronFilePath = null;
    public ?string $fakeRunDir = null;
    public ?\DateTimeImmutable $fakeNow = null;
    public bool $stubRenderHealth = true;

    public function __construct(string $tempConfigPath, PermissionSpec $spec)
    {
        parent::__construct($spec);
        $this->serverConfigPath = $tempConfigPath;
    }

    protected function detectPoolUser(HealthChecker $checker): ?string
    {
        return $this->stubPoolUser;
    }

    protected function checkDataSourceConnections(PermissionSpec $spec, OutputInterface $output, string $mode): int
    {
        if ($this->skipDbCheck) {
            $output->writeln('  (skipped in test)');
            return $this->stubDbErrors;
        }
        return parent::checkDataSourceConnections($spec, $output, $mode);
    }

    protected function getPoolConfigGlob(): string
    {
        return $this->fakePoolConfigGlob ?? '/dev/null/nonexistent/*.conf';
    }

    protected function getNginxSitesEnabledDir(): string
    {
        return $this->fakeNginxSitesEnabledDir ?? '/dev/null/nonexistent';
    }

    protected function getRepoRoot(): string
    {
        return $this->fakeRepoRoot ?? parent::getRepoRoot();
    }

    /** @var list<string> rozšíření PHP, která se tváří jako nenačtená (#146 D10) */
    public array $fakeMissingExtensions = [];

    protected function extensionLoaded(string $extension): bool
    {
        return !in_array($extension, $this->fakeMissingExtensions, true) && parent::extensionLoaded($extension);
    }

    protected function findBinary(string $name): ?string
    {
        // array_key_exists, ne ?? — hodnota null znamená "binárka chybí".
        if (array_key_exists($name, $this->fakeBinaries)) {
            return $this->fakeBinaries[$name];
        }
        return '/usr/bin/' . $name;  // default: vše přítomno
    }

    protected function getCronFilePath(): string
    {
        return $this->fakeCronFilePath ?? '/dev/null/nonexistent/shipard';
    }

    protected function getRunDir(): string
    {
        return $this->fakeRunDir ?? '/dev/null/nonexistent';
    }

    protected function now(): \DateTimeImmutable
    {
        return $this->fakeNow ?? parent::now();
    }

    protected function renderHealth(\Shipard\Core\Config\RenderConfig $renderConfig): bool
    {
        return $this->stubRenderHealth;
    }

    public function checkCronPublic(OutputInterface $output, string $mode): int
    {
        return $this->checkCron($output, $mode);
    }

    public function checkDataSourceStatesPublic(PermissionSpec $spec, OutputInterface $output, string $mode): int
    {
        return $this->checkDataSourceStates($spec, $output, $mode);
    }

    public function checkHostingDomainsFilePublic(array $config, string $user, OutputInterface $output): void
    {
        $this->checkHostingDomainsFile($config, $user, $output);
    }

    public function checkMailSafetyPublic(array $config, OutputInterface $output): int
    {
        return $this->checkMailSafety($config, $output);
    }
}

class DoctorCommandTest extends TestCase
{
    private string $tempRoot;
    private string $tempConfigPath;
    private string $testUser;

    protected function setUp(): void
    {
        $this->tempRoot = sys_get_temp_dir() . '/shpd-doctor-test-' . uniqid();
        mkdir($this->tempRoot, 0750, true);
        $this->tempConfigPath = $this->tempRoot . '/server.json';
        $info = posix_getpwuid(posix_getuid());
        $this->testUser = $info['name'];
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tempRoot);
    }

    private function recursiveDelete(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @chmod($path, 0700);
            @unlink($path);
            return;
        }
        @chmod($path, 0700);
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->recursiveDelete($path . '/' . $entry);
        }
        @rmdir($path);
    }

    private function makeSpec(): PermissionSpec
    {
        return new PermissionSpec(
            shipardUser: $this->testUser,
            dataSourcesDir: $this->tempRoot . '/opt/shipard/data-sources',
            logDir: $this->tempRoot . '/opt/shipard/log',
            configDir: $this->tempRoot . '/etc/shipard',
            shipardRoot: $this->tempRoot . '/opt/shipard',
        );
    }

    private function buildHealthyTree(PermissionSpec $spec): void
    {
        // /etc/shipard is owned by test user (test can't chown root); we accept the
        // resulting "owner sebik, expected root" issues are filtered or expected.
        mkdir($spec->getConfigDir(), 0750, true);
        file_put_contents($spec->getConfigDir() . '/server.json', '{}');
        chmod($spec->getConfigDir() . '/server.json', 0640);
        mkdir($spec->getShipardRoot(), 0751, true);
        chmod($spec->getShipardRoot(), 0751);
        mkdir($spec->getDataSourcesDir(), 0750, true);
        chmod($spec->getDataSourcesDir(), 0750);
        mkdir($spec->getLogDir(), 0750, true);
        chmod($spec->getLogDir(), 0750);
    }

    private function writeServerJson(string $mode): void
    {
        file_put_contents($this->tempConfigPath, json_encode(['mode' => $mode]));
    }

    private function makeTester(PermissionSpec $spec): TestableDoctorCommand
    {
        $command = new TestableDoctorCommand($this->tempConfigPath, $spec);
        $app = new Application();
        $app->add($command);
        return $command;
    }

    public function testReportsMissingConfigFile(): void
    {
        $command = $this->makeTester($this->makeSpec());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Config file not found or not accessible', $tester->getDisplay());
    }

    public function testReportsModeFromServerJson(): void
    {
        $this->writeServerJson('production');
        $spec = $this->makeSpec();
        $this->buildHealthyTree($spec);

        $command = $this->makeTester($spec);
        $command->stubPoolUser = $this->testUser;
        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertStringContainsString('Mode:                production', $tester->getDisplay());
    }

    public function testReportsSuccessWhenAllOk(): void
    {
        // Use a spec where all owners resolve to test user; override the global
        // entries that expect root by using a custom spec subclass... or simpler:
        // ignore the /etc/shipard/server.json "owner root" check by NOT creating
        // /etc/shipard at all — but then Doctor would report missing. So we just
        // accept that running as non-root, /etc/shipard owner mismatches show up.
        // We test the "success" path indirectly: tree built, no fixable issues
        // beyond the unavoidable root ownership ones.
        $this->writeServerJson('development');
        $spec = $this->makeSpec();
        $this->buildHealthyTree($spec);

        $command = $this->makeTester($spec);
        $command->stubPoolUser = $this->testUser;
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $display = $tester->getDisplay();
        // We *expect* failure here because /etc/shipard ownership is 'sebik' but
        // spec says 'root'. But we should see the report structure and "→ Run:
        // sudo shpd-server fix-permissions" hint (those are fixable).
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Mode:                development', $display);
        $this->assertStringContainsString("Shipard user:        {$this->testUser}", $display);
        $this->assertStringContainsString("PHP-FPM pool user:   {$this->testUser}   ✓", $display);
    }

    public function testDataSourceStatesSectionReportsOverdueMaintenanceAndCorruptedFile(): void
    {
        // #56 D8: ⚠ pro maintenance déle než 7 dní, ✗ pro fail-closed state.json,
        // read_only jen informativně.
        $this->writeServerJson('development');
        $spec = $this->makeSpec();
        $this->buildHealthyTree($spec);
        $dsRoot = $spec->getDataSourcesDir();
        foreach (['aaaa-aaaa-aaaa-aaaa', 'bbbb-bbbb-bbbb-bbbb', 'cccc-cccc-cccc-cccc', 'dddd-dddd-dddd-dddd'] as $id) {
            @mkdir($dsRoot . '/' . $id . '/config', 0750, true);
            file_put_contents($dsRoot . '/' . $id . '/config/main.json', '{}');
        }
        file_put_contents($dsRoot . '/aaaa-aaaa-aaaa-aaaa/config/state.json',
            '{"version":1,"state":"active","maintenance":{"reason":"import","since":"2026-08-20T10:00:00Z"}}');
        file_put_contents($dsRoot . '/bbbb-bbbb-bbbb-bbbb/config/state.json', '{broken');
        file_put_contents($dsRoot . '/cccc-cccc-cccc-cccc/config/state.json', '{"version":1,"state":"read_only"}');

        $command = $this->makeTester($spec);
        $command->stubPoolUser = $this->testUser;
        $command->fakeNow = new \DateTimeImmutable('2026-09-02T12:00:00Z');
        $tester = new CommandTester($command);
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('Data source states', $display);
        // Jen sekce stavů — další sekce (Outbound mail) DS vypisují taky.
        $section = substr($display, strpos($display, 'Data source states'));
        $section = substr($section, 0, strpos($section, 'Outbound mail'));
        $this->assertStringContainsString('active 1 · read_only 1 · suspended 2 (maintenance 1) · pending_deletion 0 · cron off 0', $section);
        $this->assertStringContainsString('⚠ aaaa-aaaa-aaaa-aaaa: maintenance (import) for 13 days', $section);
        $this->assertStringContainsString('✗ bbbb-bbbb-bbbb-bbbb: state.json unusable', $section);
        $this->assertStringContainsString('· cccc-cccc-cccc-cccc: read_only', $section);
        $this->assertStringNotContainsString('dddd-dddd-dddd-dddd', $section);
    }

    public function testDataSourceStatesCronOffIsInfoOutsideProductionAndWarningOnProduction(): void
    {
        // #115 D11: vypnutý cron je na produkci varování, jinde informační
        // řádek; nikdy nezvyšuje počet chyb a je nezávislý na řádku o stavu.
        $spec = $this->makeSpec();
        $this->buildHealthyTree($spec);
        $dsRoot = $spec->getDataSourcesDir();
        foreach (['aaaa-aaaa-aaaa-aaaa', 'bbbb-bbbb-bbbb-bbbb', 'cccc-cccc-cccc-cccc'] as $id) {
            @mkdir($dsRoot . '/' . $id . '/config', 0750, true);
            file_put_contents($dsRoot . '/' . $id . '/config/main.json', '{}');
        }
        file_put_contents($dsRoot . '/aaaa-aaaa-aaaa-aaaa/config/state.json', '{"version":1,"state":"active","cron":false}');
        file_put_contents($dsRoot . '/bbbb-bbbb-bbbb-bbbb/config/state.json',
            '{"version":1,"state":"active","cron":false,"maintenance":{"reason":"manual","since":"2026-09-01T10:00:00Z"}}');

        $this->writeServerJson('development');
        $command = $this->makeTester($spec);
        $command->fakeNow = new \DateTimeImmutable('2026-09-02T12:00:00Z');

        $out = new BufferedOutput();
        $this->assertSame(0, $command->checkDataSourceStatesPublic($spec, $out, 'development'));
        $dev = $out->fetch();
        $this->assertStringContainsString('active 2 · read_only 0 · suspended 1 (maintenance 1) · pending_deletion 0 · cron off 2', $dev);
        $this->assertStringContainsString('· aaaa-aaaa-aaaa-aaaa: cron off', $dev);
        $this->assertStringContainsString('· bbbb-bbbb-bbbb-bbbb: maintenance (manual), 1 day(s)', $dev);
        $this->assertStringContainsString('· bbbb-bbbb-bbbb-bbbb: cron off', $dev);
        $this->assertStringNotContainsString('cccc-cccc-cccc-cccc', $dev);
        $this->assertStringNotContainsString('All data sources active', $dev);
        $this->assertStringNotContainsString('⚠', $dev);

        $out = new BufferedOutput();
        $this->assertSame(0, $command->checkDataSourceStatesPublic($spec, $out, 'production'));
        $prod = $out->fetch();
        $this->assertStringContainsString('⚠ aaaa-aaaa-aaaa-aaaa: cron off — periodic jobs are not running (shpd-ds ds-state cron --on)', $prod);
        $this->assertStringContainsString('⚠ bbbb-bbbb-bbbb-bbbb: cron off — periodic jobs are not running', $prod);

        // Samý cron off bez jiných nálezů → žádné „All data sources active".
        unlink($dsRoot . '/bbbb-bbbb-bbbb-bbbb/config/state.json');
        $out = new BufferedOutput();
        $command->checkDataSourceStatesPublic($spec, $out, 'development');
        $this->assertStringNotContainsString('All data sources active', $out->fetch());

        // Po zapnutí cronu všude je sekce zelená.
        unlink($dsRoot . '/aaaa-aaaa-aaaa-aaaa/config/state.json');
        $out = new BufferedOutput();
        $command->checkDataSourceStatesPublic($spec, $out, 'production');
        $this->assertStringContainsString('All data sources active ✓', $out->fetch());
    }

    public function testCronOffDoesNotChangeDoctorExitCode(): void
    {
        $this->writeServerJson('development');
        $spec = $this->makeSpec();
        $this->buildHealthyTree($spec);
        $dsRoot = $spec->getDataSourcesDir();
        @mkdir($dsRoot . '/aaaa-aaaa-aaaa-aaaa/config', 0750, true);
        file_put_contents($dsRoot . '/aaaa-aaaa-aaaa-aaaa/config/main.json', '{}');

        $command = $this->makeTester($spec);
        $command->stubPoolUser = $this->testUser;
        $tester = new CommandTester($command);
        $baselineExit = $tester->execute([]);
        preg_match('/Issues found: (\d+)/', $tester->getDisplay(), $m);
        $baselineIssues = $m[1] ?? '0';

        file_put_contents($dsRoot . '/aaaa-aaaa-aaaa-aaaa/config/state.json', '{"version":1,"state":"active","cron":false}');
        // Jako od DataSourceState::save() — state.json je v PermissionSpec (0640),
        // soubor s módem podle umask by přidal nález a test by měřil něco jiného.
        chmod($dsRoot . '/aaaa-aaaa-aaaa-aaaa/config/state.json', 0640);
        $command = $this->makeTester($spec);
        $command->stubPoolUser = $this->testUser;
        $tester = new CommandTester($command);
        $exit = $tester->execute([]);
        preg_match('/Issues found: (\d+)/', $tester->getDisplay(), $m);

        $this->assertSame($baselineExit, $exit);
        $this->assertSame($baselineIssues, $m[1] ?? '0');
        $this->assertStringContainsString('· aaaa-aaaa-aaaa-aaaa: cron off', $tester->getDisplay());
    }

    public function testReportsFailureWhenPoolUserMismatch(): void
    {
        $this->writeServerJson('development');
        $spec = $this->makeSpec();
        $this->buildHealthyTree($spec);

        $command = $this->makeTester($spec);
        $command->stubPoolUser = 'someone-else';
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('someone-else   ✗ (mismatch)', $display);
        $this->assertStringContainsString('Issues found:', $display);
    }

    public function testReportsFixableHint(): void
    {
        $this->writeServerJson('development');
        $spec = $this->makeSpec();
        $this->buildHealthyTree($spec);
        // Introduce a fixable issue: wrong mode on /opt/shipard/log
        chmod($spec->getLogDir(), 0700);

        $command = $this->makeTester($spec);
        $command->stubPoolUser = $this->testUser;
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('mode 0700, expected 0750', $display);
        $this->assertStringContainsString('sudo shpd-server fix-permissions', $display);
    }

    // ─── New checks: FPM socket + nginx routing ────────────────────────────

    private function writeFakePoolConfig(string $socket): string
    {
        $dir = $this->tempRoot . '/pool';
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        file_put_contents(
            $dir . '/shipard.conf',
            "[shipard]\nuser = sebik\ngroup = sebik\nlisten = {$socket}\n",
        );
        return $dir . '/*.conf';
    }

    private function makeFakeNginxDir(): string
    {
        $dir = $this->tempRoot . '/nginx-sites';
        mkdir($dir, 0750, true);
        return $dir;
    }

    private function commandWithStubs(PermissionSpec $spec, string $mode = 'development'): TestableDoctorCommand
    {
        $this->writeServerJson($mode);
        $this->buildHealthyTree($spec);
        return $this->commandWithStubsNoTree($spec);
    }

    /** Druhý příkaz nad už postaveným stromem (srovnání počtu nálezů). */
    private function commandWithStubsNoTree(PermissionSpec $spec): TestableDoctorCommand
    {
        $command = $this->makeTester($spec);
        $command->stubPoolUser = $this->testUser;
        return $command;
    }

    public function testFpmSocketMissingIsReported(): void
    {
        $spec = $this->makeSpec();
        $command = $this->commandWithStubs($spec);
        $command->fakePoolConfigGlob = $this->writeFakePoolConfig('/tmp/never-exists-' . uniqid() . '.sock');

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('FPM socket missing:', $display);
        $this->assertStringContainsString('systemctl restart php8.5-fpm', $display);
    }

    public function testFpmPoolConfigMissingIsReported(): void
    {
        $spec = $this->makeSpec();
        $command = $this->commandWithStubs($spec);
        // fakePoolConfigGlob not set → defaults to /dev/null/nonexistent/*.conf

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Shipard PHP-FPM pool config not found', $display);
        $this->assertStringContainsString('install-packages.sh', $display);
    }

    public function testNginxRoutingAllShipardIsOk(): void
    {
        $socket = $this->tempRoot . '/run/shipard.sock';
        mkdir(dirname($socket), 0750, true);
        // Create a fake "socket" so checkFpmSocket passes too
        file_put_contents($socket, '');

        $spec = $this->makeSpec();
        $command = $this->commandWithStubs($spec);
        $command->fakePoolConfigGlob = $this->writeFakePoolConfig($socket);

        $nginxDir = $this->makeFakeNginxDir();
        file_put_contents($nginxDir . '/shipard.conf', "location ~ \\.php\$ {\n    fastcgi_pass unix:{$socket};\n}\n");
        $command->fakeNginxSitesEnabledDir = $nginxDir;

        $tester = new CommandTester($command);
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('nginx routes to shipard socket (1 active site(s))', $display);
        $this->assertStringNotContainsString('not shipard socket', $display);
    }

    public function testNginxRoutingForeignFastcgiPassIsReported(): void
    {
        $socket = $this->tempRoot . '/run/shipard.sock';
        mkdir(dirname($socket), 0750, true);
        file_put_contents($socket, '');

        $spec = $this->makeSpec();
        $command = $this->commandWithStubs($spec);
        $command->fakePoolConfigGlob = $this->writeFakePoolConfig($socket);

        $nginxDir = $this->makeFakeNginxDir();
        file_put_contents($nginxDir . '/shipard.conf', "location ~ \\.php\$ {\n    fastcgi_pass unix:{$socket};\n}\n");
        file_put_contents($nginxDir . '/legacy.conf',  "location ~ \\.php\$ {\n    fastcgi_pass unix:/run/php/php-fpm.sock;\n}\n");
        $command->fakeNginxSitesEnabledDir = $nginxDir;

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('legacy.conf', $display);
        $this->assertStringContainsString('fastcgi_pass unix:/run/php/php-fpm.sock (not shipard socket)', $display);
        $this->assertStringContainsString('regardless of file extension', $display);
    }

    public function testNginxRoutingScansFilesRegardlessOfExtension(): void
    {
        // The exact bug from the cutover: development.conf.disabled-20260511
        // still in sites-enabled with old fastcgi_pass.
        $socket = $this->tempRoot . '/run/shipard.sock';
        mkdir(dirname($socket), 0750, true);
        file_put_contents($socket, '');

        $spec = $this->makeSpec();
        $command = $this->commandWithStubs($spec);
        $command->fakePoolConfigGlob = $this->writeFakePoolConfig($socket);

        $nginxDir = $this->makeFakeNginxDir();
        file_put_contents($nginxDir . '/shipard.conf', "location ~ \\.php\$ {\n    fastcgi_pass unix:{$socket};\n}\n");
        // The "disabled" file — nginx loads it anyway
        file_put_contents(
            $nginxDir . '/development.conf.disabled-20260511',
            "location ~ \\.php\$ {\n    fastcgi_pass unix:/run/php/php-fpm.sock;\n}\n",
        );
        $command->fakeNginxSitesEnabledDir = $nginxDir;

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('development.conf.disabled-20260511', $display);
    }

    public function testNginxRoutingEmptyDirIsWarning(): void
    {
        $socket = $this->tempRoot . '/run/shipard.sock';
        mkdir(dirname($socket), 0750, true);
        file_put_contents($socket, '');

        $spec = $this->makeSpec();
        $command = $this->commandWithStubs($spec);
        $command->fakePoolConfigGlob = $this->writeFakePoolConfig($socket);
        $command->fakeNginxSitesEnabledDir = $this->makeFakeNginxDir();   // empty

        $tester = new CommandTester($command);
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('No site configs in sites-enabled', $display);
        // Warning, not error — should not bump the issue counter
        $this->assertStringNotContainsString('No nginx site routes to shipard FPM socket', $display);
    }

    public function testNginxRoutingHandlesMultipleFastcgiPassPerFile(): void
    {
        $socket = $this->tempRoot . '/run/shipard.sock';
        mkdir(dirname($socket), 0750, true);
        file_put_contents($socket, '');

        $spec = $this->makeSpec();
        $command = $this->commandWithStubs($spec);
        $command->fakePoolConfigGlob = $this->writeFakePoolConfig($socket);

        $nginxDir = $this->makeFakeNginxDir();
        // One site file with two location blocks, both fastcgi_pass shipard
        $content = "location ~ \\.php\$ {\n    fastcgi_pass unix:{$socket};\n}\n"
                 . "location /api {\n    fastcgi_pass unix:{$socket};\n}\n";
        file_put_contents($nginxDir . '/shipard.conf', $content);
        $command->fakeNginxSitesEnabledDir = $nginxDir;

        $tester = new CommandTester($command);
        $tester->execute([]);

        // Two fastcgi_pass matches in one file are counted as two active sites
        $this->assertStringContainsString(
            'nginx routes to shipard socket (2 active site(s))',
            $tester->getDisplay(),
        );
    }

    public function testNginxRoutingMixedMultipleFastcgiPassReportsForeignOnly(): void
    {
        $socket = $this->tempRoot . '/run/shipard.sock';
        mkdir(dirname($socket), 0750, true);
        file_put_contents($socket, '');

        $spec = $this->makeSpec();
        $command = $this->commandWithStubs($spec);
        $command->fakePoolConfigGlob = $this->writeFakePoolConfig($socket);

        $nginxDir = $this->makeFakeNginxDir();
        // One file: one shipard pass, one foreign pass
        $content = "location ~ \\.php\$ {\n    fastcgi_pass unix:{$socket};\n}\n"
                 . "location /legacy {\n    fastcgi_pass unix:/run/php/php-fpm.sock;\n}\n";
        file_put_contents($nginxDir . '/shipard.conf', $content);
        $command->fakeNginxSitesEnabledDir = $nginxDir;

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('fastcgi_pass unix:/run/php/php-fpm.sock (not shipard socket)', $display);
    }

    public function testNginxRoutingMatchesInlineOneLinerDirective(): void
    {
        // Regression for the cutover repro: an inline `{ fastcgi_pass ...; }`
        // block. The previous regex required `^\s*fastcgi_pass` (line start)
        // and missed this form, letting the bug slip through.
        $socket = $this->tempRoot . '/run/shipard.sock';
        mkdir(dirname($socket), 0750, true);
        file_put_contents($socket, '');

        $spec = $this->makeSpec();
        $command = $this->commandWithStubs($spec);
        $command->fakePoolConfigGlob = $this->writeFakePoolConfig($socket);

        $nginxDir = $this->makeFakeNginxDir();
        // The shipard route (separate file)
        file_put_contents($nginxDir . '/shipard.conf', "location ~ \\.php\$ {\n    fastcgi_pass unix:{$socket};\n}\n");
        // Inline foreign directive (the reproduction format)
        file_put_contents(
            $nginxDir . '/old-bug-repro.disabled-99999',
            "location ~ \\.php\$ { fastcgi_pass unix:/run/php/php-fpm.sock; }\n",
        );
        $command->fakeNginxSitesEnabledDir = $nginxDir;

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('old-bug-repro.disabled-99999', $display);
        $this->assertStringContainsString(
            'fastcgi_pass unix:/run/php/php-fpm.sock (not shipard socket)',
            $display,
        );
    }

    public function testNginxRoutingIgnoresCommentedDirectives(): void
    {
        // A commented-out fastcgi_pass should NOT count as a foreign route.
        $socket = $this->tempRoot . '/run/shipard.sock';
        mkdir(dirname($socket), 0750, true);
        file_put_contents($socket, '');

        $spec = $this->makeSpec();
        $command = $this->commandWithStubs($spec);
        $command->fakePoolConfigGlob = $this->writeFakePoolConfig($socket);

        $nginxDir = $this->makeFakeNginxDir();
        $content = "# fastcgi_pass unix:/run/php/php-fpm.sock;   ← old, kept for reference\n"
                 . "location ~ \\.php\$ {\n    fastcgi_pass unix:{$socket};\n}\n";
        file_put_contents($nginxDir . '/shipard.conf', $content);
        $command->fakeNginxSitesEnabledDir = $nginxDir;

        $tester = new CommandTester($command);
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('nginx routes to shipard socket (1 active site(s))', $display);
        $this->assertStringNotContainsString('not shipard socket', $display);
    }

    // ─── enableReset warning ────────────────────────────────────────────────

    /**
     * @param bool $passwordInMainJson          starý tvar (před #126 B1) — jen pro testy, které ho hlásí
     * @param array<string, mixed> $extra        další klíče main.json
     * @param array<string, string>|null $secretFiles soubory v secrets/ (název → obsah); null = výchozí
     *   `db.password`, není-li heslo v main.json; `[]` = žádný soubor
     */
    private function createDataSourceFixture(
        PermissionSpec $spec,
        string $id,
        bool $enableReset = false,
        bool $passwordInMainJson = false,
        array $extra = [],
        ?array $secretFiles = null,
    ): void {
        $dsDir = $spec->getDataSourcesDir() . '/' . $id;
        mkdir($dsDir . '/config', 0750, true);
        $config = [
            'id'            => $id,
            'name'          => 'Test DS',
            'database_name' => str_replace('-', '_', $id),
            'database_user' => 'shpd_test',
            'created'       => '2026-07-03T10:00:00+02:00',
            'enableReset'   => $enableReset,
        ] + $extra;
        if ($passwordInMainJson) {
            $config['database_password'] = 'secret';
        }
        $secretFiles ??= $passwordInMainJson ? [] : ['db.password' => 'db-fake'];
        file_put_contents($dsDir . '/config/main.json', json_encode($config));
        // Práva podle kontraktu, aby filesystem checks nepřidávaly nálezy.
        chmod($dsDir . '/config/main.json', 0600);
        if ($secretFiles !== []) {
            mkdir($dsDir . '/secrets', 0700);
            foreach ($secretFiles as $name => $content) {
                file_put_contents($dsDir . '/secrets/' . $name, $content);
                chmod($dsDir . '/secrets/' . $name, 0600);
            }
        }
    }

    /** Počet nálezů z patičky; 0 = „All checks passed“. */
    private function issuesFound(string $display): int
    {
        return preg_match('/Issues found: (\d+)/', $display, $m) === 1 ? (int) $m[1] : 0;
    }

    /** Výřez sekce Data source secrets. */
    private function secretsSection(string $display): string
    {
        $start = strpos($display, 'Data source secrets');
        $this->assertNotFalse($start, 'sekce Data source secrets chybí');
        $end = strpos($display, 'Data source states', $start);
        return substr($display, $start, $end === false ? null : $end - $start);
    }

    // ── Data source secrets (#126 B1 U6, B2 U4) ─────────────────────────────

    public function testSecretsSectionSitsBetweenDbConnectionsAndStates(): void
    {
        $spec = $this->makeSpec();
        $command = $this->commandWithStubs($spec);
        $tester = new CommandTester($command);
        $tester->execute([]);
        $display = $tester->getDisplay();

        $db = strpos($display, 'Data source DB connections');
        $secrets = strpos($display, 'Data source secrets');
        $states = strpos($display, 'Data source states');
        $this->assertTrue($db < $secrets && $secrets < $states);
        $this->assertStringContainsString('(no data sources)', $this->secretsSection($display));
    }

    public function testSecretsStillInMainJsonAreOneErrorPerDataSource(): void
    {
        $spec = $this->makeSpec();
        $command = $this->commandWithStubs($spec);
        $this->createDataSourceFixture($spec, 'test-0002-test-0002', passwordInMainJson: false, secretFiles: ['db.password' => 'db-fake']);
        $baseline = new CommandTester($command);
        $baseline->execute([]);
        $this->assertStringContainsString('All secrets in secrets/ ✓', $this->secretsSection($baseline->getDisplay()));

        // Soubory existují, hodnoty v main.json zůstaly (obnova starší zálohy) — ignorují se, ale je to chyba.
        $this->createDataSourceFixture($spec, 'test-0001-test-0001', passwordInMainJson: true, extra: [
            'mail' => ['relay' => ['host' => 'smtp.example', 'username' => 'u', 'password' => 'relay-fake']],
        ], secretFiles: ['db.password' => 'db-fake', 'mail-relay.password' => 'relay-file-fake']);
        $tester = new CommandTester($this->commandWithStubsNoTree($spec));
        $tester->execute([]);
        $display = $tester->getDisplay();
        $section = $this->secretsSection($display);

        $this->assertStringContainsString('✗ test-0001-test-0001: db.password, mail-relay.password still in config/main.json — ignored; run shpd-ds ds-upgrade', $section);
        $this->assertStringNotContainsString('test-0002-test-0002', $section);
        $this->assertStringContainsString('1 data source(s) keep ignored secrets in config/main.json (shpd-server ds-upgrade-all moves them)', $section);
        $this->assertStringNotContainsString('All secrets in secrets/', $section);
        $this->assertStringNotContainsString('⚠', $section);
        $this->assertStringNotContainsString('fake', $display);
        $this->assertSame($this->issuesFound($baseline->getDisplay()) + 1, $this->issuesFound($display), 'jedna chyba za zdroj dat');
    }

    public function testDatabasePasswordOnlyInMainJsonIsASingleErrorLine(): void
    {
        $spec = $this->makeSpec();
        $command = $this->commandWithStubs($spec);
        $this->createDataSourceFixture($spec, 'test-0002-test-0002', passwordInMainJson: false, secretFiles: ['db.password' => 'db-fake']);
        $baseline = new CommandTester($command);
        $baseline->execute([]);

        // Soubor chybí a klíč je v main.json: ds-upgrade vyřeší obojí, stačí jeden řádek.
        $this->createDataSourceFixture($spec, 'test-0001-test-0001', passwordInMainJson: true);
        $tester = new CommandTester($this->commandWithStubsNoTree($spec));
        $tester->execute([]);
        $display = $tester->getDisplay();
        $section = $this->secretsSection($display);

        $this->assertStringContainsString('✗ test-0001-test-0001: db.password still in config/main.json — ignored; run shpd-ds ds-upgrade', $section);
        $this->assertStringNotContainsString('database password missing', $section);
        $this->assertSame(1, substr_count($section, 'test-0001-test-0001'));
        $this->assertSame($this->issuesFound($baseline->getDisplay()) + 1, $this->issuesFound($display));
    }

    public function testMissingDatabasePasswordIsAnError(): void
    {
        $spec = $this->makeSpec();
        $command = $this->commandWithStubs($spec);
        $this->createDataSourceFixture($spec, 'test-0002-test-0002', passwordInMainJson: false, secretFiles: ['db.password' => 'db-fake']);
        $baseline = new CommandTester($command);
        $baseline->execute([]);

        $this->createDataSourceFixture($spec, 'test-0001-test-0001', secretFiles: []); // heslo nikde
        $tester = new CommandTester($this->commandWithStubsNoTree($spec));
        $tester->execute([]);
        $display = $tester->getDisplay();

        $this->assertStringContainsString('✗ test-0001-test-0001: database password missing (secrets/db.password)', $this->secretsSection($display));
        $this->assertSame($this->issuesFound($baseline->getDisplay()) + 1, $this->issuesFound($display));
    }

    public function testProviderWithoutSecretAndRelayWithUsernameButNoPasswordAreWarnings(): void
    {
        $spec = $this->makeSpec();
        $command = $this->commandWithStubs($spec);
        $this->createDataSourceFixture($spec, 'test-0001-test-0001', passwordInMainJson: false, extra: [
            'mail' => ['relay' => ['host' => 'smtp.example', 'username' => 'u']],
            'auth' => ['providers' => [
                ['id' => 'shipard-id', 'issuer' => 'https://idp.example', 'clientId' => 'c'],
                ['id' => 'github', 'kind' => 'github', 'clientId' => 'c'],
            ]],
        ], secretFiles: ['db.password' => 'db-fake', 'oidc-github.client-secret' => 'oidc-fake']);
        $this->createDataSourceFixture($spec, 'test-0002-test-0002', passwordInMainJson: false, extra: [
            'mail' => ['relay' => ['host' => 'localhost', 'port' => 25, 'security' => 'none']],
        ], secretFiles: ['db.password' => 'db-fake']);

        $tester = new CommandTester($command);
        $tester->execute([]);
        $section = $this->secretsSection($tester->getDisplay());

        $this->assertStringContainsString("⚠ test-0001-test-0001: auth provider 'shipard-id' has no client secret (secrets/oidc-shipard-id.client-secret)", $section);
        $this->assertStringContainsString('shpd-ds ds-secret set oidc-shipard-id.client-secret', $section);
        $this->assertStringContainsString('⚠ test-0001-test-0001: mail.relay has a username but no password (secrets/mail-relay.password)', $section);
        $this->assertStringNotContainsString('github', $section);
        $this->assertStringNotContainsString('test-0002-test-0002', $section, 'relay bez username heslo nepotřebuje');
        $this->assertStringNotContainsString('All secrets in secrets/', $section);
        $this->assertStringNotContainsString('✗', $section);
        $this->assertStringNotContainsString('fake', $tester->getDisplay());
    }

    public function testUnusableMainJsonIsSkippedInTheSecretsSection(): void
    {
        $spec = $this->makeSpec();
        $command = $this->commandWithStubs($spec);
        $dsDir = $spec->getDataSourcesDir() . '/test-0001-test-0001';
        mkdir($dsDir . '/config', 0750, true);
        file_put_contents($dsDir . '/config/main.json', '{not json');

        $tester = new CommandTester($command);
        $tester->execute([]);
        $section = $this->secretsSection($tester->getDisplay());

        $this->assertStringContainsString('· test-0001-test-0001: config unusable — see Data source DB connections above', $section);
        $this->assertStringNotContainsString('✗', $section);
    }

    public function testEnableResetWarnsOnProduction(): void
    {
        $spec = $this->makeSpec();
        $command = $this->commandWithStubs($spec, 'production');
        $command->skipDbCheck = false;
        $this->createDataSourceFixture($spec, 'test-0001-test-0001', enableReset: true);

        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertStringContainsString(
            'test-0001-test-0001: enableReset is set — data source is resettable on a production server.',
            $tester->getDisplay(),
        );
    }

    public function testEnableResetSilentOnDevelopment(): void
    {
        $spec = $this->makeSpec();
        $command = $this->commandWithStubs($spec, 'development');
        $command->skipDbCheck = false;
        $this->createDataSourceFixture($spec, 'test-0001-test-0001', enableReset: true);

        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertStringNotContainsString('enableReset is set', $tester->getDisplay());
    }

    public function testSocketPathNormalizationStripsUnixPrefix(): void
    {
        $socket = $this->tempRoot . '/run/shipard.sock';
        mkdir(dirname($socket), 0750, true);
        file_put_contents($socket, '');

        $spec = $this->makeSpec();
        $command = $this->commandWithStubs($spec);
        $command->fakePoolConfigGlob = $this->writeFakePoolConfig($socket);

        $nginxDir = $this->makeFakeNginxDir();
        // Pool config has the path without 'unix:' prefix; nginx uses 'unix:'
        // prefix. The doctor must normalize before comparing.
        file_put_contents($nginxDir . '/shipard.conf', "location ~ \\.php\$ {\n    fastcgi_pass unix:{$socket};\n}\n");
        $command->fakeNginxSitesEnabledDir = $nginxDir;

        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertStringContainsString(
            'nginx routes to shipard socket (1 active site(s))',
            $tester->getDisplay(),
        );
    }

    // ─── System config includes (warn-only) ─────────────────────────────────

    /** Repo root fixture with all three versioned include files present. */
    private function makeFakeRepoRoot(): string
    {
        $dir = $this->tempRoot . '/repo';
        mkdir($dir . '/docs/nginx', 0750, true);
        mkdir($dir . '/docs/php', 0750, true);
        file_put_contents($dir . '/docs/nginx/shipard-common.conf', "client_max_body_size 128M;\n");
        file_put_contents($dir . '/docs/nginx/shipard-tls.conf', "ssl_protocols TLSv1.3;\n");
        file_put_contents($dir . '/docs/php/shipard-fpm-common.conf', "php_admin_value[post_max_size] = 130M\n");
        return $dir;
    }

    /**
     * @return array{command: TestableDoctorCommand, siteFile: string, poolFile: string}
     */
    private function includeCheckScenario(): array
    {
        $socket = $this->tempRoot . '/run/shipard.sock';
        mkdir(dirname($socket), 0750, true);
        file_put_contents($socket, '');

        $spec = $this->makeSpec();
        $command = $this->commandWithStubs($spec);
        $command->fakePoolConfigGlob = $this->writeFakePoolConfig($socket);
        $command->fakeRepoRoot = $this->makeFakeRepoRoot();

        $nginxDir = $this->makeFakeNginxDir();
        $siteFile = $nginxDir . '/shipard.conf';
        file_put_contents($siteFile, "location ~ \\.php\$ {\n    fastcgi_pass unix:{$socket};\n}\n");
        $command->fakeNginxSitesEnabledDir = $nginxDir;

        return [
            'command' => $command,
            'siteFile' => $siteFile,
            'poolFile' => $this->tempRoot . '/pool/shipard.conf',
        ];
    }

    public function testIncludeChecksWarnWhenMissing(): void
    {
        $s = $this->includeCheckScenario();

        $tester = new CommandTester($s['command']);
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('missing include of shipard-common.conf', $display);
        $this->assertStringContainsString('include /opt/shipard/shpd/docs/nginx/shipard-common.conf;', $display);
        $this->assertStringContainsString('missing include of shipard-fpm-common.conf', $display);
        $this->assertStringContainsString('include=/opt/shipard/shpd/docs/php/shipard-fpm-common.conf', $display);
    }

    public function testIncludeChecksOkWhenPresent(): void
    {
        $s = $this->includeCheckScenario();
        file_put_contents(
            $s['siteFile'],
            "include /opt/shipard/shpd/docs/nginx/shipard-common.conf;\n",
            FILE_APPEND,
        );
        file_put_contents(
            $s['poolFile'],
            "include=/opt/shipard/shpd/docs/php/shipard-fpm-common.conf\n",
            FILE_APPEND,
        );

        $tester = new CommandTester($s['command']);
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('✓ nginx site includes shipard-common.conf', $display);
        $this->assertStringContainsString('✓ FPM pool includes shipard-fpm-common.conf', $display);
        $this->assertStringNotContainsString('missing include', $display);
    }

    public function testIncludeChecksIgnoreCommentedIncludes(): void
    {
        $s = $this->includeCheckScenario();
        file_put_contents(
            $s['siteFile'],
            "# include /opt/shipard/shpd/docs/nginx/shipard-common.conf;\n",
            FILE_APPEND,
        );
        file_put_contents(
            $s['poolFile'],
            ";include=/opt/shipard/shpd/docs/php/shipard-fpm-common.conf\n",
            FILE_APPEND,
        );

        $tester = new CommandTester($s['command']);
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('missing include of shipard-common.conf', $display);
        $this->assertStringContainsString('missing include of shipard-fpm-common.conf', $display);
    }

    public function testIncludeChecksDoNotAffectExitCode(): void
    {
        // Same scenario twice — once without and once with the include lines.
        // The warn-only checks must not change the issue count or exit code.
        $s = $this->includeCheckScenario();
        $tester = new CommandTester($s['command']);
        $exitWithout = $tester->execute([]);
        $displayWithout = $tester->getDisplay();

        file_put_contents(
            $s['siteFile'],
            "include /opt/shipard/shpd/docs/nginx/shipard-common.conf;\n",
            FILE_APPEND,
        );
        file_put_contents(
            $s['poolFile'],
            "include=/opt/shipard/shpd/docs/php/shipard-fpm-common.conf\n",
            FILE_APPEND,
        );
        $exitWith = $tester->execute([]);
        $displayWith = $tester->getDisplay();

        $this->assertSame($exitWith, $exitWithout);
        preg_match('/Issues found: (\d+)/', $displayWithout, $mWithout);
        preg_match('/Issues found: (\d+)/', $displayWith, $mWith);
        $this->assertSame($mWith[1] ?? 'none', $mWithout[1] ?? 'none');
    }

    public function testIncludeCheckWarnsWhenRepoFileMissing(): void
    {
        $s = $this->includeCheckScenario();
        $emptyRepo = $this->tempRoot . '/empty-repo';
        mkdir($emptyRepo, 0750, true);
        $s['command']->fakeRepoRoot = $emptyRepo;

        $tester = new CommandTester($s['command']);
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('Include file missing in repo: docs/nginx/shipard-common.conf', $display);
        $this->assertStringContainsString('Include file missing in repo: docs/php/shipard-fpm-common.conf', $display);
    }

    // ─── PHP extensions (#146 D10) ───────────────────────────────────────────

    public function testPhpExtensionsRequiredByComposerJsonAreListed(): void
    {
        $spec = $this->makeSpec();
        $command = $this->commandWithStubs($spec);

        $tester = new CommandTester($command);
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('PHP extensions', $display);
        foreach (['bcmath', 'intl', 'openssl', 'zip'] as $extension) {
            $this->assertStringContainsString("✓ {$extension}", $display, "ext-{$extension} z composer.json");
        }
        $this->assertStringNotContainsString('extension not loaded', $display);
        $this->assertStringNotContainsString('required PHP extensions not checked', $display);
    }

    public function testMissingPhpExtensionIsAnErrorWithTheAptPackageToInstall(): void
    {
        $spec = $this->makeSpec();
        $command = $this->commandWithStubs($spec);
        $tester = new CommandTester($command);
        $tester->execute([]);
        preg_match('/Issues found: (\d+)/', $tester->getDisplay(), $mWithout);
        $baseline = (int) ($mWithout[1] ?? 0);

        $command->fakeMissingExtensions = ['bcmath', 'openssl'];
        $exitCode = $tester->execute([]);
        $display = $tester->getDisplay();
        preg_match('/Issues found: (\d+)/', $display, $mWith);

        $version = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('✗ bcmath: extension not loaded', $display);
        $this->assertStringContainsString("sudo apt install php{$version}-bcmath", $display);
        $this->assertStringContainsString('✗ openssl: extension not loaded', $display);
        $this->assertStringContainsString("Built into php{$version}-cli", $display);
        $this->assertStringContainsString('✓ intl', $display);
        $this->assertSame($baseline + 2, (int) ($mWith[1] ?? 0), 'každé chybějící rozšíření je jedna chyba');
    }

    public function testUnreadableComposerJsonIsAWarningThatDoesNotChangeTheExitCount(): void
    {
        $spec = $this->makeSpec();
        $command = $this->commandWithStubs($spec);
        $tester = new CommandTester($command);
        $tester->execute([]);
        preg_match('/Issues found: (\d+)/', $tester->getDisplay(), $mWithout);

        $emptyRepo = $this->tempRoot . '/empty-repo';
        mkdir($emptyRepo, 0750, true);
        $command->fakeRepoRoot = $emptyRepo;
        $tester->execute([]);
        $display = $tester->getDisplay();
        preg_match('/Issues found: (\d+)/', $display, $mWith);

        $this->assertStringContainsString("⚠ {$emptyRepo}/composer.json not readable — required PHP extensions not checked", $display);
        $this->assertSame((int) ($mWithout[1] ?? 0), (int) ($mWith[1] ?? 0));
    }

    // ─── Attachment tools check ──────────────────────────────────────────────

    public function testThumbnailToolsAllPresent(): void
    {
        $spec = $this->makeSpec();
        $command = $this->commandWithStubs($spec);

        $tester = new CommandTester($command);
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('Attachment tools', $display);
        $this->assertStringContainsString('✓ pdftocairo (/usr/bin/pdftocairo)', $display);
        $this->assertStringContainsString('✓ pdftotext (/usr/bin/pdftotext)', $display);
        $this->assertStringContainsString('✓ pdfdetach (/usr/bin/pdfdetach)', $display);
        $this->assertStringContainsString('✓ rsvg-convert (/usr/bin/rsvg-convert)', $display);
        $this->assertStringContainsString('✓ vipsthumbnail (/usr/bin/vipsthumbnail)', $display);
        $this->assertStringContainsString('✓ vips (/usr/bin/vips)', $display);
        $this->assertStringNotContainsString('not found in PATH', $display);
    }

    public function testMissingThumbnailToolReportsErrorWithAptHint(): void
    {
        $spec = $this->makeSpec();
        $command = $this->commandWithStubs($spec);
        $command->fakeBinaries['vipsthumbnail'] = null;

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('✗ vipsthumbnail: not found in PATH', $display);
        $this->assertStringContainsString('sudo apt install libvips-tools', $display);
    }

    public function testMissingToolCountsIntoTotalIssues(): void
    {
        // Delta pattern (viz testIncludeChecksDoNotAffectExitCode): healthy tree
        // bez roota vždy hlásí nějaké nevyhnutelné issues, takže porovnáváme
        // počet mezi během se všemi binárkami a se dvěma chybějícími.
        $spec = $this->makeSpec();
        $command = $this->commandWithStubs($spec);

        $tester = new CommandTester($command);
        $tester->execute([]);
        preg_match('/Issues found: (\d+)/', $tester->getDisplay(), $mWithout);
        $baseline = (int) ($mWithout[1] ?? 0);

        $command->fakeBinaries['pdftocairo'] = null;
        $command->fakeBinaries['vipsthumbnail'] = null;
        $exitCode = $tester->execute([]);
        preg_match('/Issues found: (\d+)/', $tester->getDisplay(), $mWith);

        $this->assertSame(1, $exitCode);
        $this->assertSame($baseline + 2, (int) ($mWith[1] ?? 0));
    }

    // ─── Render service ──────────────────────────────────────────────────────

    public function testRenderNotConfiguredIsSkippedInfo(): void
    {
        $spec = $this->makeSpec();
        $command = $this->commandWithStubs($spec);

        $tester = new CommandTester($command);
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('Render service', $display);
        $this->assertStringContainsString('(render service not configured — skipped)', $display);
    }

    public function testRenderHealthyReportsOk(): void
    {
        $spec = $this->makeSpec();
        $command = $this->commandWithStubs($spec);
        file_put_contents($this->tempConfigPath, json_encode([
            'mode'   => 'development',
            'render' => ['url' => 'http://127.0.0.1:3000'],
        ]));

        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertStringContainsString('✓ render service responding at http://127.0.0.1:3000', $tester->getDisplay());
    }

    public function testRenderUnhealthyReportsErrorWithHint(): void
    {
        $spec = $this->makeSpec();
        $command = $this->commandWithStubs($spec);
        $command->stubRenderHealth = false;
        file_put_contents($this->tempConfigPath, json_encode([
            'mode'   => 'development',
            'render' => ['url' => 'http://127.0.0.1:3000'],
        ]));

        $tester = new CommandTester($command);
        $command->stubRenderHealth = true;
        $tester->execute([]);
        preg_match('/Issues found: (\d+)/', $tester->getDisplay(), $mWithout);

        $command->stubRenderHealth = false;
        $tester->execute([]);
        $display = $tester->getDisplay();
        preg_match('/Issues found: (\d+)/', $display, $mWith);

        $this->assertStringContainsString('✗ render service at http://127.0.0.1:3000 is not responding', $display);
        $this->assertStringContainsString('docs/operations/render-service.md', $display);
        $this->assertSame((int) ($mWithout[1] ?? 0) + 1, (int) ($mWith[1] ?? 0));
    }

    public function testRenderInvalidConfigReportsError(): void
    {
        $spec = $this->makeSpec();
        $command = $this->commandWithStubs($spec);
        file_put_contents($this->tempConfigPath, json_encode([
            'mode'   => 'development',
            'render' => ['url' => 'ftp://bad'],
        ]));

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('✗ server.json render is invalid', $tester->getDisplay());
    }

    // ─── Cron checks ────────────────────────────────────────────────────────

    /** @return array{TestableDoctorCommand, \Symfony\Component\Console\Output\BufferedOutput} */
    private function makeCronChecker(): array
    {
        $command = new TestableDoctorCommand($this->tempConfigPath, $this->makeSpec());
        $command->fakeCronFilePath = $this->tempRoot . '/cron.d/shipard';
        $command->fakeRunDir = $this->tempRoot . '/run';
        mkdir($this->tempRoot . '/cron.d', 0750, true);
        mkdir($this->tempRoot . '/run', 0750, true);
        return [$command, new \Symfony\Component\Console\Output\BufferedOutput()];
    }

    private function writeCurrentCronFile(TestableDoctorCommand $command, int $mtimeAge = 3600): void
    {
        $content = new \Shipard\Core\Server\CronProvisioner()->renderTemplate('/usr/bin/php', '/repo', 'shipard');
        file_put_contents($command->fakeCronFilePath, $content);
        touch($command->fakeCronFilePath, time() - $mtimeAge);
    }

    private function writeHeartbeat(TestableDoctorCommand $command, string $slot, int $ageSeconds, int $failedCount = 0): void
    {
        $path = \Shipard\Core\Server\CronProvisioner::heartbeatPath($slot, $command->fakeRunDir);
        file_put_contents($path, json_encode([
            'ts' => date('c', time() - $ageSeconds),
            'slot' => $slot,
            'failedCount' => $failedCount,
        ]));
    }

    public function testCronMissingFileIsErrorInProduction(): void
    {
        [$command, $output] = $this->makeCronChecker();
        $command->fakeCronFilePath = $this->tempRoot . '/cron.d/missing';

        $errors = $command->checkCronPublic($output, 'production');

        $this->assertSame(1, $errors);
        $display = $output->fetch();
        $this->assertStringContainsString('missing — periodic jobs', $display);
        $this->assertStringContainsString('sudo shpd-server upgrade (or sudo shpd-server cron-install)', $display);
    }

    public function testCronMissingFileIsWarningInDevelopment(): void
    {
        // #115 D6: mimo produkci varování s radou, ne „skipped“ — a žádná chyba.
        [$command, $output] = $this->makeCronChecker();
        $command->fakeCronFilePath = $this->tempRoot . '/cron.d/missing';

        $errors = $command->checkCronPublic($output, 'development');

        $this->assertSame(0, $errors);
        $display = $output->fetch();
        $this->assertStringContainsString('⚠ ' . $command->fakeCronFilePath . ' missing — periodic jobs', $display);
        $this->assertStringContainsString('→ Run: sudo shpd-server cron-install', $display);
        $this->assertStringContainsString('first install switches periodic jobs off for existing data sources', $display);
        $this->assertStringNotContainsString('✗', $display);
    }

    public function testCronOutdatedMarkerIsErrorInDevelopmentToo(): void
    {
        // #115 U5: zastaralá šablona je chyba i mimo produkci — oprava je jeden příkaz.
        [$command, $output] = $this->makeCronChecker();
        file_put_contents($command->fakeCronFilePath, "# hand-written, template version 0\n");
        touch($command->fakeCronFilePath, time() - 3600);
        foreach (\Shipard\Core\Server\CronProvisioner::SLOTS as $slot) {
            $this->writeHeartbeat($command, $slot, 30);
        }

        $errors = $command->checkCronPublic($output, 'development');

        $this->assertSame(1, $errors);
        $this->assertStringContainsString('is outdated (template version 0, expected', $output->fetch());
    }

    public function testCronStaleMinuteHeartbeatIsWarnOnlyInDevelopment(): void
    {
        [$command, $output] = $this->makeCronChecker();
        $this->writeCurrentCronFile($command);
        $this->writeHeartbeat($command, 'minute', 1800);
        $this->writeHeartbeat($command, 'two-minutes', 30);
        $this->writeHeartbeat($command, 'five-minutes', 30);
        $this->writeHeartbeat($command, 'daily', 30);
        $this->writeHeartbeat($command, 'weekly', 30);

        $errors = $command->checkCronPublic($output, 'development');

        $this->assertSame(0, $errors);
        $display = $output->fetch();
        $this->assertStringContainsString('⚠ minute: heartbeat stale (30 min old) — cron daemon not running or dispatcher failing', $display);
        $this->assertStringContainsString('Laptop asleep or VM stopped? Check: systemctl status cron', $display);
        $this->assertStringNotContainsString('✗', $display);
    }

    public function testCronMissingMinuteHeartbeatOnOldInstallIsWarnOnlyInDevelopment(): void
    {
        [$command, $output] = $this->makeCronChecker();
        $this->writeCurrentCronFile($command, mtimeAge: 7200);
        $this->writeHeartbeat($command, 'two-minutes', 30);
        $this->writeHeartbeat($command, 'five-minutes', 30);

        $errors = $command->checkCronPublic($output, 'development');

        $this->assertSame(0, $errors);
        $display = $output->fetch();
        $this->assertStringContainsString('⚠ minute: heartbeat missing — cron daemon not running or dispatcher failing', $display);
        $this->assertStringContainsString('⚠ daily: not yet run', $display);
        $this->assertStringNotContainsString('✗', $display);
    }

    public function testCronCurrentFileAndFreshHeartbeatsPass(): void
    {
        [$command, $output] = $this->makeCronChecker();
        $this->writeCurrentCronFile($command);
        $this->writeHeartbeat($command, 'minute', 30);
        $this->writeHeartbeat($command, 'two-minutes', 60);
        $this->writeHeartbeat($command, 'five-minutes', 200);
        $this->writeHeartbeat($command, 'daily', 3600);
        $this->writeHeartbeat($command, 'weekly', 86400);

        $errors = $command->checkCronPublic($output, 'production');

        $this->assertSame(0, $errors);
        $display = $output->fetch();
        $this->assertStringContainsString('✓ ' . $command->fakeCronFilePath, $display);
        $this->assertStringContainsString('✓ minute: last run', $display);
        $this->assertStringContainsString('✓ weekly: last run', $display);
        $this->assertStringNotContainsString('✗', $display);
    }

    public function testCronOutdatedMarkerIsError(): void
    {
        [$command, $output] = $this->makeCronChecker();
        file_put_contents($command->fakeCronFilePath, "# hand-written, template version 0\n");
        touch($command->fakeCronFilePath, time() - 3600);
        $this->writeHeartbeat($command, 'minute', 30);
        $this->writeHeartbeat($command, 'two-minutes', 30);
        $this->writeHeartbeat($command, 'five-minutes', 30);
        $this->writeHeartbeat($command, 'daily', 30);
        $this->writeHeartbeat($command, 'weekly', 30);

        $errors = $command->checkCronPublic($output, 'production');

        $this->assertSame(1, $errors);
        $this->assertStringContainsString('is outdated (template version 0, expected', $output->fetch());
    }

    public function testCronStaleMinuteHeartbeatIsError(): void
    {
        [$command, $output] = $this->makeCronChecker();
        $this->writeCurrentCronFile($command);
        $this->writeHeartbeat($command, 'minute', 1800);
        $this->writeHeartbeat($command, 'two-minutes', 30);
        $this->writeHeartbeat($command, 'five-minutes', 30);
        $this->writeHeartbeat($command, 'daily', 30);
        $this->writeHeartbeat($command, 'weekly', 30);

        $errors = $command->checkCronPublic($output, 'production');

        $this->assertSame(1, $errors);
        $display = $output->fetch();
        $this->assertStringContainsString('✗ minute: heartbeat stale', $display);
        $this->assertStringContainsString('cron daemon not running', $display);
    }

    public function testCronStaleWeeklyHeartbeatIsWarnOnly(): void
    {
        [$command, $output] = $this->makeCronChecker();
        $this->writeCurrentCronFile($command);
        $this->writeHeartbeat($command, 'minute', 30);
        $this->writeHeartbeat($command, 'two-minutes', 30);
        $this->writeHeartbeat($command, 'five-minutes', 30);
        $this->writeHeartbeat($command, 'daily', 30);
        $this->writeHeartbeat($command, 'weekly', 20 * 86400);

        $errors = $command->checkCronPublic($output, 'production');

        $this->assertSame(0, $errors);
        $this->assertStringContainsString('⚠ weekly: heartbeat stale', $output->fetch());
    }

    public function testCronMissingHeartbeatAfterRecentInstallIsWarn(): void
    {
        [$command, $output] = $this->makeCronChecker();
        $this->writeCurrentCronFile($command, mtimeAge: 60);

        $errors = $command->checkCronPublic($output, 'production');

        $this->assertSame(0, $errors);
        $display = $output->fetch();
        $this->assertStringContainsString('⚠ minute: not yet run (cron installed recently)', $display);
    }

    public function testCronMissingMinuteHeartbeatOnOldInstallIsError(): void
    {
        [$command, $output] = $this->makeCronChecker();
        $this->writeCurrentCronFile($command, mtimeAge: 7200);
        $this->writeHeartbeat($command, 'two-minutes', 30);
        $this->writeHeartbeat($command, 'five-minutes', 30);

        $errors = $command->checkCronPublic($output, 'production');

        // minute i five-minutes chybět nesmí; daily/weekly jen warn
        $this->assertSame(1, $errors);
        $display = $output->fetch();
        $this->assertStringContainsString('✗ minute: heartbeat missing', $display);
        $this->assertStringContainsString('⚠ daily: not yet run', $display);
        $this->assertStringContainsString('⚠ weekly: not yet run', $display);
    }

    public function testCronFailedJobsAreWarnOnly(): void
    {
        [$command, $output] = $this->makeCronChecker();
        $this->writeCurrentCronFile($command);
        $this->writeHeartbeat($command, 'minute', 30, failedCount: 2);
        $this->writeHeartbeat($command, 'two-minutes', 30);
        $this->writeHeartbeat($command, 'five-minutes', 30);
        $this->writeHeartbeat($command, 'daily', 30);
        $this->writeHeartbeat($command, 'weekly', 30);

        $errors = $command->checkCronPublic($output, 'production');

        $this->assertSame(0, $errors);
        $this->assertStringContainsString('⚠ minute: last run 30 s ago, 2 failed job(s) — see shipard.log', $output->fetch());
    }

    public function testCronSectionAppearsInFullRun(): void
    {
        $this->writeServerJson('development');
        $spec = $this->makeSpec();
        $this->buildHealthyTree($spec);

        $command = $this->makeTester($spec);
        $command->stubPoolUser = $this->testUser;
        $tester = new CommandTester($command);
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('Cron', $display);
        $this->assertStringContainsString('missing — periodic jobs (mail outbox, alerts, invoicing) are not running', $display);
        // Mimo produkci jen varování (#115 D6): v sekci Cron není žádný nález ✗.
        preg_match('/^Cron\n(.*?)\n\n/ms', $display, $m);
        $this->assertArrayHasKey(1, $m, 'Cron section found');
        $this->assertStringContainsString('⚠', $m[1]);
        $this->assertStringNotContainsString('✗', $m[1]);
    }

    // ── Hosting domains file (nález č. 3 z adopce) ─────────────────────────

    public function testDomainsFileCheckSkippedWithoutHostingSection(): void
    {
        $command = $this->makeTester($this->makeSpec());
        $output = new \Symfony\Component\Console\Output\BufferedOutput();

        $command->checkHostingDomainsFilePublic(['mode' => 'production'], $this->testUser, $output);

        $this->assertStringContainsString('not hosting-managed — skipped', $output->fetch());
    }

    public function testDomainsFileWritableDirPasses(): void
    {
        $dir = $this->tempRoot . '/writable';
        mkdir($dir, 0755, true);
        $command = $this->makeTester($this->makeSpec());
        $output = new \Symfony\Component\Console\Output\BufferedOutput();

        $command->checkHostingDomainsFilePublic(
            ['hosting' => ['portal_url' => 'x'], 'domainsFile' => $dir . '/domains.json'],
            $this->testUser,
            $output,
        );

        $this->assertStringContainsString('✓', $output->fetch());
    }

    public function testDomainsFileUnwritableDirWarnsWithOverrideHint(): void
    {
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('root writes anywhere');
        }
        // Atomický zápis (tmp + rename) potřebuje write na ADRESÁŘI — vzor
        // z alfy: /etc/shipard root:shipard 750, agent běží jako shipard.
        $dir = $this->tempRoot . '/readonly';
        mkdir($dir, 0500, true);
        $command = $this->makeTester($this->makeSpec());
        $output = new \Symfony\Component\Console\Output\BufferedOutput();

        $command->checkHostingDomainsFilePublic(
            ['hosting' => ['portal_url' => 'x'], 'domainsFile' => $dir . '/domains.json'],
            $this->testUser,
            $output,
        );

        $display = $output->fetch();
        $this->assertStringContainsString('⚠', $display);
        $this->assertStringContainsString('not writable', $display);
        $this->assertStringContainsString("domainsFile", $display);
    }

    public function testDomainsFileCheckAppearsInFullRun(): void
    {
        $this->writeServerJson('development');
        $spec = $this->makeSpec();
        $this->buildHealthyTree($spec);

        $command = $this->makeTester($spec);
        $command->stubPoolUser = $this->testUser;
        $tester = new CommandTester($command);
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('Hosting domains file', $display);
        $this->assertStringContainsString('not hosting-managed — skipped', $display);
    }

    public function testBlockedCheckoutAncestorIsReportedAsError(): void
    {
        // #96 D11: /opt/shipard/shpd → checkout under a home created 0750
        // (Ubuntu HOME_MODE) — nginx cannot reach public/.
        $this->writeServerJson('development');
        $spec = $this->makeSpec();
        $this->buildHealthyTree($spec);
        $home = realpath($this->tempRoot) . '/home/dev';
        mkdir($home . '/sw/shpd/public', 0755, true);
        chmod($home, 0750);
        symlink($home . '/sw/shpd', $spec->getShipardRoot() . '/shpd');

        $command = $this->makeTester($spec);
        $command->stubPoolUser = $this->testUser;
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString("✗ {$home}: mode 0750 blocks nginx", $display);
        $this->assertStringContainsString("chmod o+x {$home}", $display);
        $this->assertStringContainsString('sudo shpd-server fix-permissions', $display);
    }

    // ── Pojistka odchozí pošty (#95 D7) ─────────────────────────────

    /**
     * @param array<string, mixed> $serverConfig
     * @return array{int, string}
     */
    private function mailSafetyCheck(array $serverConfig): array
    {
        $command = new TestableDoctorCommand($this->tempConfigPath, $this->makeSpec());
        $output  = new \Symfony\Component\Console\Output\BufferedOutput();
        $errors  = $command->checkMailSafetyPublic($serverConfig, $output);

        return [$errors, $output->fetch()];
    }

    public function testMailSafetyConfiguredRedirectShowsTarget(): void
    {
        [$errors, $display] = $this->mailSafetyCheck([
            'mode' => 'production',
            'mail' => ['safety' => ['mode' => 'redirect', 'redirectTo' => 'testy@example.com']],
        ]);

        $this->assertSame(0, $errors);
        $this->assertStringContainsString('✓ Mail safety: redirect → testy@example.com (configured)', $display);
    }

    public function testMailSafetyConfiguredAllowlistShowsWhereTheRestGoes(): void
    {
        [, $display] = $this->mailSafetyCheck([
            'mode' => 'development',
            'mail' => ['safety' => ['mode' => 'allowlist', 'allow' => ['@example.com', 'jan@example.org']]],
        ]);

        $this->assertStringContainsString('✓ Mail safety: allowlist (2 allowed), others → dropped (configured)', $display);
    }

    public function testMailSafetyDefaultsFollowServerMode(): void
    {
        [$errors, $display] = $this->mailSafetyCheck(['mode' => 'development']);
        $this->assertSame(0, $errors);
        $this->assertStringContainsString('✓ Mail safety: drop — nothing is sent (default, no mail.safety in server.json)', $display);

        [$errors, $display] = $this->mailSafetyCheck(['mode' => 'production']);
        $this->assertSame(0, $errors);
        $this->assertStringContainsString('✓ Mail safety: off (default, no mail.safety in server.json)', $display);
    }

    public function testMailSafetyInvalidSectionIsAnError(): void
    {
        [$errors, $display] = $this->mailSafetyCheck([
            'mode' => 'production',
            'mail' => ['safety' => ['mode' => 'redirect']],
        ]);

        $this->assertSame(1, $errors);
        $this->assertStringContainsString('✗ Mail safety: drop (invalid)', $display);
        $this->assertStringContainsString('redirectTo', $display);
        $this->assertStringContainsString('until then nothing is sent', $display);
    }

    public function testMailSafetyOffOnDevelopmentServerIsAWarning(): void
    {
        [$errors, $display] = $this->mailSafetyCheck([
            'mode' => 'development',
            'mail' => ['safety' => ['mode' => 'off']],
        ]);

        $this->assertSame(0, $errors);
        $this->assertStringContainsString('⚠ Mail safety: off (configured) on a non-production server', $display);
    }

    public function testOutboundMailSectionReportsMailSafety(): void
    {
        $this->writeServerJson('development');
        $spec = $this->makeSpec();
        $this->buildHealthyTree($spec);

        $command = $this->makeTester($spec);
        $command->stubPoolUser = $this->testUser;
        $tester = new CommandTester($command);
        $tester->execute([]);

        $section = substr($tester->getDisplay(), (int) strpos($tester->getDisplay(), 'Outbound mail'));
        $this->assertStringContainsString('Mail safety: drop — nothing is sent', $section);
    }
}

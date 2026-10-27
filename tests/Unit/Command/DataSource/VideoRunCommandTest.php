<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\DataSource;

use PHPUnit\Framework\TestCase;
use Shipard\Api\SessionService;
use Shipard\Command\DataSource\VideoRunCommand;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\DevUpdate\UpdateEnvironment;
use Shipard\Module\Core\Exchange\Dataset\DatasetCatalog;
use Shipard\Module\Core\Exchange\Dataset\DatasetReader;
use Shipard\Module\Core\Exchange\Dataset\E2e\E2eSession;
use Shipard\Module\Core\Exchange\Dataset\E2e\LocalBaseUrl;
use Shipard\Module\Core\Exchange\Dataset\E2e\PinnedBaseUrl;
use Shipard\Module\Core\Exchange\Dataset\Video\DatasetBuildCache;
use Shipard\Module\Core\Exchange\Dataset\Video\DatasetBuildProcess;
use Shipard\Module\Core\Exchange\Dataset\Video\VideoPreflight;
use Shipard\Module\Core\Exchange\Dataset\Video\VideoRunnerProcess;
use Shipard\Module\Core\Exchange\Dataset\Video\VideoScenarioCatalog;
use Shipard\Module\Core\Exchange\Dataset\Video\VideoStore;
use Shipard\Tests\Unit\Module\Core\Exchange\Dataset\E2e\E2eFakeConnection;
use Shipard\Tests\Unit\Module\Core\Exchange\Dataset\Video\VideoPreflightTest;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/** Preflight bez sítě: dosažitelnost aplikace i Gotenbergu je skriptovaná. */
final class StubVideoPreflight extends VideoPreflight
{
    public ?string $reach = null;
    public ?string $gotenberg = null;
    /** @var list<string> */
    public array $checked = [];
    /** @var list<?string> */
    public array $pins = [];
    /** @var list<string> */
    public array $gotenbergChecked = [];

    public function appReachable(string $url, float $timeout = self::APP_TIMEOUT_SECONDS, ?PinnedBaseUrl $pin = null): ?string
    {
        $this->checked[] = $url;
        $this->pins[] = $pin?->curlResolve();
        return $this->reach;
    }

    public function gotenbergReachable(string $url, float $timeout = self::GOTENBERG_TIMEOUT_SECONDS): ?string
    {
        $this->gotenbergChecked[] = $url;
        return $this->gotenberg;
    }
}

/** SessionService, který session založit nesmí — preflight odmítl (D97). */
final class VideoForbiddenSessionService extends SessionService
{
    public int $attempts = 0;

    public function createSession(int $userId, DataSourceConnection $db, ?string $clientIp = null, ?int $ttlSeconds = null): array
    {
        $this->attempts++;
        throw new \LogicException('session se po odmítnutém preflightu nesmí založit');
    }
}

/** Místo `build.py`: zapamatuje argv a prostředí, podle chování zapíše manifest do `--out`. */
final class FakeBuildProcess extends DatasetBuildProcess
{
    /** @var array<string, string> */
    public array $env = [];
    public string $outDir = '';

    /** @param \Closure(FakeBuildProcess): array{exitCode: ?int, termSignal: ?int, cancelled: bool, durationMs: int} $behaviour */
    public function __construct(array $argv, \Closure $cancel, private readonly \Closure $behaviour)
    {
        parent::__construct($argv, '/repo', $cancel);
        $this->outDir = (string) ($argv[array_search('--out', $argv, true) + 1] ?? '');
    }

    public function run(array $env, OutputInterface $log): array
    {
        $this->env = $env;
        $log->writeln('[build.py] generating');
        return ($this->behaviour)($this);
    }

    public function writeManifest(): void
    {
        file_put_contents($this->outDir . '/manifest.jsonc', '{"format":"shpd.dataset.v1","name":"demo-set"}');
    }
}

/**
 * Místo video-runneru: zapamatuje prostředí, přečte `storageState`
 * v okamžiku běhu (práva, token) a podle chování zapíše `result.json`
 * (a u `compose` složené video s `timeline.json`).
 */
final class FakeRunnerProcess extends VideoRunnerProcess
{
    /** @var array<string, string> */
    public array $env = [];
    public ?string $storagePerms = null;
    /** @var array<string, mixed>|null */
    public ?array $stateSeen = null;

    /** @param \Closure(FakeRunnerProcess, array<string, string>): array{exitCode: ?int, termSignal: ?int, cancelled: bool, durationMs: int} $behaviour */
    public function __construct(string $verb, string $scenarioPath, private readonly \Closure $cancel, private readonly \Closure $behaviour)
    {
        parent::__construct('/usr/bin/node', '/repo/tools/video-runner', $verb, $scenarioPath, $cancel);
    }

    public function run(array $env, OutputInterface $log): array
    {
        $this->env = $env;
        $storage = $env['SHPD_STORAGE_STATE'] ?? null;
        if ($storage !== null && is_file($storage)) {
            $this->storagePerms = substr(sprintf('%o', fileperms($storage)), -4);
            $this->stateSeen = json_decode((string) file_get_contents($storage), true);
        }
        $log->writeln('[video-runner] ' . $this->verb());
        return ($this->behaviour)($this, $env);
    }

    public function cancelRequested(): bool
    {
        return ($this->cancel)();
    }

    public function tokenSeen(): ?string
    {
        foreach ($this->stateSeen['origins'][0]['localStorage'] ?? [] as $item) {
            if (($item['name'] ?? null) === 'shpd_token') {
                return (string) $item['value'];
            }
        }
        return null;
    }

    public function languageSeen(): ?string
    {
        foreach ($this->stateSeen['origins'][0]['localStorage'] ?? [] as $item) {
            if (($item['name'] ?? null) === 'shpd_language') {
                return (string) $item['value'];
            }
        }
        return null;
    }

    /** @param array<string, mixed> $result */
    public function writeResult(array $result): void
    {
        $dir = $this->env['VIDEO_WORK_DIR'] . '/s1';
        @mkdir($dir, 0700, true);
        file_put_contents($dir . '/result.json', json_encode(['verb' => $this->verb(), 'scenario' => 's1'] + $result));
    }

    public function writeVideo(float $durationSeconds = 42.5): void
    {
        file_put_contents($this->env['VIDEO_WORK_DIR'] . '/s1/timeline.json', json_encode(['scenario' => 's1', 'duration' => $durationSeconds, 'fps' => 30]));
        file_put_contents($this->env['VIDEO_OUT_DIR'] . '/s1.mp4', str_repeat('v', 4096));
    }
}

class TestableVideoRunCommand extends VideoRunCommand
{
    public ?string $mode = 'development';
    public ?string $gotenberg = 'http://10.0.0.1:3000';
    public ?StubVideoPreflight $preflight = null;
    /** @var list<FakeBuildProcess> */
    public array $builds = [];
    /** @var list<FakeRunnerProcess> */
    public array $runners = [];
    public ?\Closure $buildBehaviour = null;
    public ?\Closure $runnerBehaviour = null;
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $subCalls = [];
    public int $seedExit = 0;
    /** @var array<string, list<string>> */
    public array $dns = ['dev.example' => ['127.0.0.1']];
    /** @var list<string> */
    public array $localAddresses = ['127.0.0.1', '::1'];
    public ?SessionService $sessions = null;
    public ?string $commit = 'deadbeefcafe';

    public function __construct(
        DataSourceConfig $dsConfig,
        DataSourceConnection $db,
        private readonly string $dsDir,
        private readonly string $repo,
        private readonly string $binPath,
        private readonly string $browsersPath,
        private readonly string $runDir,
    ) {
        parent::__construct($dsConfig, $db);
    }

    protected function getDataSourceDir(): string
    {
        return $this->dsDir;
    }

    protected function getServerMode(): ?string
    {
        return $this->mode;
    }

    protected function gotenbergUrl(): ?string
    {
        return $this->gotenberg;
    }

    protected function repoRoot(): string
    {
        return $this->repo;
    }

    protected function createPreflight(): VideoPreflight
    {
        return $this->preflight ??= new StubVideoPreflight($this->repo, $this->binPath, $this->browsersPath, $this->repo . '/home');
    }

    public function preflightForTest(): StubVideoPreflight
    {
        $this->createPreflight();
        return $this->preflight ?? throw new \LogicException('preflight');
    }

    protected function createLocalBaseUrl(): LocalBaseUrl
    {
        return new LocalBaseUrl(
            fn (string $host): array => $this->dns[$host] ?? [],
            fn (): array => $this->localAddresses,
        );
    }

    protected function createCache(): DatasetBuildCache
    {
        return new DatasetBuildCache($this->runDir . '/video-datasets', $this->repo);
    }

    protected function createStore(): VideoStore
    {
        return new VideoStore($this->runDir . '/videos');
    }

    protected function createSession(DataSourceConnection $db): E2eSession
    {
        return new E2eSession($db, $this->sessions ?? new SessionService(), self::USER_LOGIN, self::USER_FULL_NAME, self::USER_LANGUAGE);
    }

    protected function createBuildProcess(array $argv, \Closure $shouldCancel): DatasetBuildProcess
    {
        return $this->builds[] = new FakeBuildProcess($argv, $shouldCancel, $this->buildBehaviour ?? throw new \LogicException('build behaviour not scripted'));
    }

    protected function createRunner(VideoPreflight $preflight, string $verb, string $scenarioPath, \Closure $shouldCancel): VideoRunnerProcess
    {
        return $this->runners[] = new FakeRunnerProcess($verb, $scenarioPath, $shouldCancel, $this->runnerBehaviour ?? throw new \LogicException('runner behaviour not scripted'));
    }

    protected function runSub(string $command, array $args, OutputInterface $out): int
    {
        $this->subCalls[] = [$command, $args];
        $out->writeln('[seed] reset + seed');
        return $this->seedExit;
    }

    protected function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-23T10:00:00+02:00');
    }

    protected function commitHash(): ?string
    {
        return $this->commit;
    }

    protected function privateDir(): string
    {
        return $this->runDir . '/private-' . bin2hex(random_bytes(3));
    }
}

/**
 * `shpd-ds video-run` (tasks/testing-phase7b.md § 2, D119, D120, D125,
 * D127, D128): preflight exit 2 (±`--json`, cizí adresa před session),
 * `check` se stavbou sady, přeseedováním, session a `check.json`; druhý
 * běh z mezipaměti; `build` = record + compose + povýšení videa s meta;
 * selhání runneru s krokem a selektorem; selhaný seed; výjimka a zrušení
 * uklidí session; token nikde.
 */
final class VideoRunCommandTest extends TestCase
{
    private const DS_ID = 'abcd-abcd-abcd-abcd';
    private string $tmp;
    private string $repo;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/shpd_video_cmd_' . bin2hex(random_bytes(6));
        $this->repo = $this->tmp . '/repo';
        VideoPreflightTest::fullRepo($this->repo, $this->tmp . '/bin', $this->tmp . '/browsers');
        mkdir($this->tmp . '/run', 0700, true);
        $dir = $this->tmp . '/ds/' . self::DS_ID . '/config';
        mkdir($dir, 0700, true);
        file_put_contents($dir . '/main.json', json_encode(['id' => self::DS_ID, 'name' => 'Videa — Demo', 'database_name' => 'x', 'country' => 'cz', 'dataClass' => 'free', 'purpose' => 'videa — demo-set']));
    }

    protected function tearDown(): void
    {
        DatasetReader::removeTree($this->tmp);
    }

    private function command(E2eFakeConnection $db): TestableVideoRunCommand
    {
        $dsConfig = $this->createMock(DataSourceConfig::class);
        $dsConfig->method('getId')->willReturn(self::DS_ID);
        $dsConfig->method('getName')->willReturn('Videa — Demo');
        $dsConfig->method('getCountry')->willReturn('cz');
        $command = new TestableVideoRunCommand($dsConfig, $db, $this->tmp . '/ds/' . self::DS_ID, $this->repo, $this->tmp . '/bin', $this->tmp . '/browsers', $this->tmp . '/run');
        $command->buildBehaviour = static function (FakeBuildProcess $build): array {
            $build->writeManifest();
            return ['exitCode' => 0, 'termSignal' => null, 'cancelled' => false, 'durationMs' => 180_000];
        };
        $command->runnerBehaviour = self::okRun();
        return $command;
    }

    /** @return \Closure(FakeRunnerProcess, array<string, string>): array<string, mixed> */
    private static function okRun(): \Closure
    {
        return static function (FakeRunnerProcess $runner): array {
            $result = ['status' => 'ok', 'durationMs' => 20_000];
            if ($runner->verb() === 'compose') {
                $runner->writeVideo();
                $result['video'] = ['path' => $runner->env['VIDEO_OUT_DIR'] . '/s1.mp4'];
            }
            $runner->writeResult($result);
            return ['exitCode' => 0, 'termSignal' => null, 'cancelled' => false, 'durationMs' => 21_000];
        };
    }

    /** @param array<string, mixed> $args */
    private function execute(TestableVideoRunCommand $command, array $args = [], bool $separateStderr = false): CommandTester
    {
        $app = new Application();
        $app->add($command);
        $tester = new CommandTester($app->find('video-run'));
        $tester->execute($args + ['scenario' => 's1'], ['capture_stderr_separately' => $separateStderr]);
        return $tester;
    }

    /** @return list<array<string, mixed>> */
    private function progressEvents(string $file): array
    {
        return array_map(
            static fn (string $l): array => json_decode($l, true, 512, JSON_THROW_ON_ERROR),
            array_values(array_filter(explode("\n", (string) file_get_contents($file)))),
        );
    }

    private function cacheDir(): string
    {
        return $this->tmp . '/run/video-datasets/demo-set/2026-10-23';
    }

    private function markCacheFresh(): void
    {
        $cache = new DatasetBuildCache($this->tmp . '/run/video-datasets', $this->repo);
        $dir = $cache->prepare('demo-set', '2026-10-23');
        file_put_contents($dir . '/manifest.jsonc', '{}');
        $cache->markBuilt('demo-set', '2026-10-23', $cache->sourceHash('demo-set'), 'old', new \DateTimeImmutable('2026-10-23T06:00:00Z'));
    }

    // ── Preflight ──────────────────────────────────────────────────────────

    public function testPreflightRefusesWithExitTwoAndJsonProblems(): void
    {
        $db = E2eFakeConnection::create();
        $command = $this->command($db);
        $command->mode = 'production';
        $tester = $this->execute($command);
        $this->assertSame(Command::INVALID, $tester->getStatusCode());
        $this->assertStringContainsString('video-run odmítnuto:', $tester->getDisplay());
        $this->assertStringContainsString("mode is 'production'", $tester->getDisplay());
        $this->assertSame([], $command->subCalls);
        $this->assertSame([], $command->builds);
        $this->assertSame([], $db->inserted, 'preflight nic nemění');
        $this->assertDirectoryDoesNotExist($this->tmp . '/run/video-datasets');

        $command = $this->command($db);
        $command->mode = null;
        $tester = $this->execute($command, ['--json' => true], separateStderr: true);
        $this->assertSame(Command::INVALID, $tester->getStatusCode());
        $json = json_decode(trim($tester->getDisplay()), true, 512, JSON_THROW_ON_ERROR);
        $this->assertFalse($json['ok']);
        $this->assertCount(1, $json['problems']);
        $this->assertStringContainsString('Režim serveru nejde určit', $json['problems'][0]);
        $this->assertCount(1, explode("\n", trim($tester->getDisplay())), 'stdout = jeden JSON řádek');

        $command = $this->command($db);
        unlink($this->tmp . '/bin/ffmpeg');
        $tester = $this->execute($command);
        $this->assertSame(Command::INVALID, $tester->getStatusCode());
        $this->assertStringContainsString('ffmpeg není v PATH', $tester->getDisplay());

        $command = $this->command($db);
        $command->gotenberg = null;
        $tester = $this->execute($command);
        $this->assertStringContainsString('Gotenberg není nastavený', $tester->getDisplay());

        $command = $this->command($db);
        $tester = $this->execute($command, ['scenario' => 'nope']);
        $this->assertSame(Command::INVALID, $tester->getStatusCode());
        $this->assertStringContainsString("Scénář 'nope' neexistuje", $tester->getDisplay());

        $command = $this->command($db);
        $tester = $this->execute($command, ['--verb' => 'login']);
        $this->assertStringContainsString('--verb musí být check nebo build', $tester->getDisplay());

        $command = $this->command($db);
        $tester = $this->execute($command, ['--anchor' => '2026-13-40']);
        $this->assertStringContainsString('--anchor: Kotva musí být datum', $tester->getDisplay());
    }

    public function testForeignBaseUrlIsRefusedBeforeAnySessionAndUnreachableAppToo(): void
    {
        $db = E2eFakeConnection::create();
        $command = $this->command($db);
        $sessions = new VideoForbiddenSessionService();
        $command->sessions = $sessions;
        $command->dns = ['alpha.example' => ['203.0.113.10']];
        $tester = $this->execute($command, ['--base-url' => 'https://alpha.example']);
        $this->assertSame(Command::INVALID, $tester->getStatusCode());
        $this->assertStringContainsString('alpha.example', $tester->getDisplay());
        $this->assertSame(0, $sessions->attempts);
        $this->assertSame([], $command->preflightForTest()->checked, 'dosažitelnost se u cizí adresy nezkouší');
        $this->assertSame([], $command->builds, 'sada se nestaví');

        $command = $this->command($db);
        $command->preflightForTest()->reach = 'Aplikace na http://127.0.0.1/x není dosažitelná';
        $tester = $this->execute($command);
        $this->assertSame(Command::INVALID, $tester->getStatusCode());
        $this->assertStringContainsString('není dosažitelná', $tester->getDisplay());
        $this->assertSame(['http://127.0.0.1/' . self::DS_ID . '/api/v1/_app/info'], $command->preflightForTest()->checked);

        // Mrtvý Gotenberg odmítne před stavbou; z mezipaměti se na něj neptá.
        $command = $this->command($db);
        $command->preflightForTest()->gotenberg = 'Gotenberg na http://10.0.0.1:3000/health není dosažitelný';
        $tester = $this->execute($command);
        $this->assertSame(Command::INVALID, $tester->getStatusCode());
        $this->assertStringContainsString('Gotenberg na http://10.0.0.1:3000/health není dosažitelný', $tester->getDisplay());
        $this->assertSame([], $command->builds);
        $this->markCacheFresh();
        $command = $this->command($db);
        $command->preflightForTest()->gotenberg = 'nedosažitelný';
        $tester = $this->execute($command);
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertSame([], $command->preflightForTest()->gotenbergChecked);
    }

    // ── check ──────────────────────────────────────────────────────────────

    public function testCheckBuildsTheSetSeedsRunsTheRunnerAndWritesCheckJson(): void
    {
        $db = E2eFakeConnection::create();
        $command = $this->command($db);
        mkdir($this->tmp . '/run/video-datasets/demo-set/2026-10-20', 0700, true);
        $progress = $this->tmp . '/progress.jsonl';
        $tester = $this->execute($command, ['--json' => true, '--progress-file' => $progress, '--base-url' => 'http://dev.example'], separateStderr: true);

        $stdout = $tester->getDisplay();
        $stderr = $tester->getErrorOutput();
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), $stderr);
        $report = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($report['ok']);
        $this->assertSame('ok', $report['status']);
        $this->assertSame('check', $report['verb']);
        $this->assertSame('s1', $report['scenario']['id']);
        $this->assertSame('S1 — test.', $report['scenario']['title']);
        $this->assertSame('2026-10-23', $report['anchor']);
        $this->assertSame('2026-10-23T09:30:00+02:00', $report['fixedTime']);
        $this->assertSame(['name' => 'demo-set', 'cacheDir' => $this->cacheDir(), 'cached' => false], array_slice($report['dataset'], 0, 3));
        $this->assertSame(['id' => self::DS_ID, 'name' => 'Videa — Demo'], $report['dataSource']);
        $this->assertSame('http://dev.example/' . self::DS_ID . '/app/', $report['appUrl']);
        $this->assertSame(['build', 'reseed', 'check'], array_keys($report['steps']));
        $this->assertSame(['ran' => true, 'exitCode' => 0, 'termSignal' => null, 'durationMs' => 180000, 'cached' => false], $report['steps']['build']);
        $this->assertTrue($report['steps']['reseed']['ran']);
        $this->assertSame(['ran' => true, 'exitCode' => 0, 'termSignal' => null, 'durationMs' => 21000, 'cached' => null], $report['steps']['check']);
        $this->assertSame('ok', $report['result']['status']);
        $this->assertNull($report['video']);

        // Lidské řádky jen na stderr.
        $this->assertStringContainsString('Scénář s1 (check) → sada demo-set, kotva 2026-10-23, zdroj dat ' . self::DS_ID, $stderr);
        $this->assertStringContainsString('Sestavení sady demo-set s kotvou 2026-10-23', $stderr);
        $this->assertStringContainsString('[build.py] generating', $stderr);
        $this->assertStringContainsString('Starší sestavení smazáno: ' . $this->tmp . '/run/video-datasets/demo-set/2026-10-20', $stderr);
        $this->assertStringContainsString('[seed] reset + seed', $stderr);
        $this->assertStringContainsString("Uživatel 'video' založen", $stderr);
        $this->assertStringContainsString('[video-runner] check', $stderr);
        $this->assertStringContainsString('Výsledek: v pořádku.', $stderr);
        $this->assertStringNotContainsString('Scénář s1', $stdout);

        // Sada: build.py s kotvou do mezipaměti, značka s otiskem a commitem, stará kotva pryč, seed z mezipaměti.
        $this->assertCount(1, $command->builds);
        $build = $command->builds[0];
        $this->assertSame([
            $this->tmp . '/bin/python3', $this->repo . '/demo/datasets/build.py',
            '--story', 'demo-set', '--anchor', '2026-10-23', '--out', $this->cacheDir(), '--state', $this->cacheDir() . '/.build-state.json',
        ], $build->argv());
        $this->assertSame('http://10.0.0.1:3000', $build->env['GOTENBERG_URL']);
        $this->assertSame('1', $build->env['PYTHONUTF8']);
        $this->assertSame($this->tmp . '/bin', $build->env['PATH']);
        $this->assertSame(['http://10.0.0.1:3000'], $command->preflightForTest()->gotenbergChecked);
        $marker = json_decode((string) file_get_contents($this->cacheDir() . '/build.json'), true);
        $this->assertSame('deadbeefcafe', $marker['commit']);
        $this->assertSame('2026-10-23T08:00:00Z', $marker['builtAt']);
        $this->assertDirectoryDoesNotExist($this->tmp . '/run/video-datasets/demo-set/2026-10-20');
        $this->assertSame([['dataset-seed', ['path' => $this->cacheDir(), '--yes' => true]]], $command->subCalls);

        // Kontrakt s runnerem: jen SHPD_* / VIDEO_*, pevný PATH, HOME, pevný čas, pin jména hostu, HEADFUL vypnutý.
        $this->assertCount(1, $command->runners);
        $runner = $command->runners[0];
        $this->assertSame('check', $runner->verb());
        $this->assertSame($this->repo . '/demo/scenarios/s1.jsonc', $runner->argv()[3]);
        $vars = array_filter($runner->env, static fn (string $k): bool => str_starts_with($k, 'SHPD_') || str_starts_with($k, 'VIDEO_'), ARRAY_FILTER_USE_KEY);
        $privateDir = dirname($vars['SHPD_STORAGE_STATE']);
        $this->assertSame([
            'SHPD_BASE_URL' => 'http://dev.example/' . self::DS_ID,
            'SHPD_STORAGE_STATE' => $privateDir . '/storage-state.json',
            'VIDEO_WORK_DIR' => $this->tmp . '/run/videos/s1/work',
            'VIDEO_OUT_DIR' => $this->tmp . '/run/videos/s1/work/out',
            'VIDEO_FIXED_TIME' => '2026-10-23T09:30:00+02:00',
            'VIDEO_HEADFUL' => '0',
            'VIDEO_HOST_RESOLVER_RULES' => 'MAP dev.example 127.0.0.1',
        ], $vars);
        $this->assertSame($this->tmp . '/bin', $runner->env['PATH']);
        $this->assertArrayNotHasKey('CI', $runner->env);
        $this->assertArrayHasKey('HOME', $runner->env);
        $this->assertStringStartsWith($this->tmp . '/run/private-', $privateDir);
        $this->assertDirectoryDoesNotExist($privateDir, 'soukromý adresář po běhu pryč');
        $this->assertSame(['dev.example:80:127.0.0.1'], $command->preflightForTest()->pins, 'jméno hostu se připíchne i curl (D97)');

        // Session: uživatel video (jméno, jazyk auto), úklid z minula, TTL 3600, storageState 0600 jen po dobu běhu.
        $this->assertSame('core_system_users', $db->inserted[0]['table']);
        $this->assertSame(['login' => 'video', 'full_name' => 'Jana Zelená', 'is_active' => 1, 'is_admin' => 1, 'is_system' => 1], array_intersect_key($db->inserted[0]['data'], array_flip(['login', 'full_name', 'is_active', 'is_admin', 'is_system'])));
        $this->assertNull($db->inserted[0]['data']['password_hash']);
        $tokens = $db->sessionTokens();
        $this->assertCount(1, $tokens);
        $this->assertEqualsWithDelta(time() + 3600, strtotime((string) $db->inserted[1]['data']['expires']), 5);
        $this->assertSame('0600', $runner->storagePerms);
        $this->assertSame($tokens[0], $runner->tokenSeen());
        $this->assertSame('auto', $runner->languageSeen(), 'jazyk podle locale scénáře (D121)');
        $this->assertSame('http://dev.example', $runner->stateSeen['origins'][0]['origin']);
        $this->assertCount(2, $db->sessionDeletes(), 'zneplatnění na začátku a při zavření');

        // check.json s výsledkem posledního ověření; video žádné.
        $check = json_decode((string) file_get_contents($this->tmp . '/run/videos/s1/check.json'), true);
        $this->assertSame(['scenario' => 's1', 'at' => '2026-10-23T08:00:00Z', 'verb' => 'check', 'anchor' => '2026-10-23', 'fixedTime' => '2026-10-23T09:30:00+02:00', 'status' => 'ok', 'durationMs' => 21000, 'error' => null, 'commit' => 'deadbeefcafe'], $check);
        $this->assertFileDoesNotExist($this->tmp . '/run/videos/s1/meta.json');

        // Token nikde: stdout, stderr, průběh, report, prostředí runneru, argv.
        $token = $tokens[0];
        $haystack = $stdout . $stderr . file_get_contents($progress) . json_encode($report) . implode("\n", $runner->env) . implode(' ', $runner->argv()) . implode(' ', $build->argv());
        $this->assertStringNotContainsString($token, $haystack);
        $this->assertStringNotContainsString('shpd_st_', $haystack);

        // Průběh: started → build → reseed → check → finished.
        $events = $this->progressEvents($progress);
        $this->assertSame(
            [['started', null], ['step', 'build'], ['step', 'build'], ['step', 'reseed'], ['step', 'reseed'], ['step', 'check'], ['step', 'check'], ['finished', null]],
            array_map(static fn (array $e): array => [$e['event'], $e['step'] ?? null], $events),
        );
        $this->assertSame(['pid', 'verb', 'scenario', 'dataset', 'anchor', 'dataSource', 'appUrl'], array_keys(array_slice($events[0], 2)));
        $this->assertSame(['step' => 'build', 'status' => 'finished', 'exitCode' => 0, 'cached' => false, 'cancelled' => false], array_slice($events[2], 2));
        $this->assertSame(['step' => 'check', 'status' => 'finished', 'exitCode' => 0, 'termSignal' => null, 'cancelled' => false], array_slice($events[6], 2));
        $this->assertSame(['status' => 'ok', 'exitCode' => 0, 'video' => null], array_slice($events[7], 2));
    }

    public function testSecondRunTheSameDayUsesTheCache(): void
    {
        $this->markCacheFresh();
        $db = E2eFakeConnection::create();
        $command = $this->command($db);
        $progress = $this->tmp . '/progress.jsonl';
        $tester = $this->execute($command, ['--json' => true, '--progress-file' => $progress], separateStderr: true);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getErrorOutput());
        $this->assertSame([], $command->builds, 'sada se nestaví');
        $report = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($report['dataset']['cached']);
        $this->assertSame(['ran' => true, 'exitCode' => 0, 'termSignal' => null, 'durationMs' => 0, 'cached' => true], $report['steps']['build']);
        $this->assertStringContainsString('Sada z mezipaměti ' . $this->cacheDir(), $tester->getErrorOutput());
        $this->assertStringContainsString('sestavení sady … z mezipaměti', $tester->getErrorOutput());
        $this->assertSame('old', json_decode((string) file_get_contents($this->cacheDir() . '/build.json'), true)['commit'], 'značka beze změny');
        $this->assertSame([['dataset-seed', ['path' => $this->cacheDir(), '--yes' => true]]], $command->subCalls);
        $events = $this->progressEvents($progress);
        $this->assertSame(['step' => 'build', 'status' => 'finished', 'exitCode' => 0, 'cached' => true], array_slice($events[1], 2));

        // Změna příběhu týž den = přestavět.
        file_put_contents($this->repo . '/demo/datasets/stories/demo-set/story.jsonc', '{"name":"demo-set","changed":true}');
        $command = $this->command(E2eFakeConnection::create());
        $tester = $this->execute($command, ['--json' => true], separateStderr: true);
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getErrorOutput());
        $this->assertCount(1, $command->builds);

        // Jiná kotva (--anchor) = jiný adresář, stavět, stará kotva pryč.
        $command = $this->command(E2eFakeConnection::create());
        $tester = $this->execute($command, ['--json' => true, '--anchor' => '2027-01-20'], separateStderr: true);
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getErrorOutput());
        $report = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('2027-01-20', $report['anchor']);
        $this->assertSame('2027-01-20T09:30:00+01:00', $report['fixedTime']);
        $this->assertSame('2027-01-20T09:30:00+01:00', $command->runners[0]->env['VIDEO_FIXED_TIME']);
        $this->assertDirectoryDoesNotExist($this->cacheDir());
    }

    // ── build ──────────────────────────────────────────────────────────────

    public function testBuildRecordsComposesAndPromotesTheVideoWithMeta(): void
    {
        $this->markCacheFresh();
        $db = E2eFakeConnection::create();
        $command = $this->command($db);
        $progress = $this->tmp . '/progress.jsonl';
        $tester = $this->execute($command, ['--verb' => 'build', '--json' => true, '--progress-file' => $progress], separateStderr: true);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getErrorOutput());
        $this->assertSame(['record', 'compose'], array_map(static fn (FakeRunnerProcess $r): string => $r->verb(), $command->runners));
        $this->assertSame($command->runners[0]->env, $command->runners[1]->env, 'compose běží se stejným prostředím');
        $report = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('build', $report['verb']);
        $this->assertSame(['build', 'reseed', 'record', 'compose'], array_keys($report['steps']));
        $video = $report['video'];
        $this->assertSame($this->tmp . '/run/videos/s1/s1.mp4', $video['path']);
        $this->assertSame('s1.mp4', $video['file']);
        $this->assertSame(42500, $video['durationMs']);
        $this->assertSame(4096, $video['bytes']);
        $this->assertSame('2026-10-23', $video['anchor']);
        $this->assertSame('deadbeefcafe', $video['commit']);
        $this->assertSame('2026-10-23T08:00:00Z', $video['recordedAt']);
        $this->assertFileExists($this->tmp . '/run/videos/s1/s1.mp4');
        $this->assertFileDoesNotExist($this->tmp . '/run/videos/s1/work/out/s1.mp4', 'povýšeno přesunem');
        $meta = json_decode((string) file_get_contents($this->tmp . '/run/videos/s1/meta.json'), true);
        $this->assertSame(['scenario' => 's1', 'file' => 's1.mp4', 'dataset' => 'demo-set', 'anchor' => '2026-10-23', 'fixedTime' => '2026-10-23T09:30:00+02:00', 'recordedAt' => '2026-10-23T08:00:00Z', 'commit' => 'deadbeefcafe', 'durationMs' => 42500, 'bytes' => 4096], $meta);
        $check = json_decode((string) file_get_contents($this->tmp . '/run/videos/s1/check.json'), true);
        $this->assertSame(['record', 'ok'], [$check['verb'], $check['status']], 'natáčení je i ověření');
        $this->assertStringContainsString('Video: ' . $this->tmp . '/run/videos/s1/s1.mp4 (0:42, 4 KiB)', $tester->getErrorOutput());
        $events = $this->progressEvents($progress);
        $this->assertSame(['started', 'step', 'step', 'step', 'step', 'step', 'step', 'step', 'finished'], array_column($events, 'event'), 'sada z mezipaměti = jediná událost build');
        $this->assertSame(['record', 'record', 'compose', 'compose'], array_column(array_slice($events, 4, 4), 'step'));
        $this->assertSame($meta + ['path' => $this->tmp . '/run/videos/s1/s1.mp4'], end($events)['video'], 'průběh nese i cestu videa');
    }

    public function testRunnerFailureNamesTheStepKeepsThePreviousVideoAndExitsOne(): void
    {
        $this->markCacheFresh();
        $store = new VideoStore($this->tmp . '/run/videos');
        $store->prepare('s1');
        file_put_contents($store->videoPath('s1'), 'OLD');
        file_put_contents($store->metaPath('s1'), json_encode(['scenario' => 's1', 'file' => 's1.mp4', 'anchor' => '2026-10-22']));

        $db = E2eFakeConnection::create();
        $command = $this->command($db);
        $command->runnerBehaviour = static function (FakeRunnerProcess $runner): array {
            $runner->writeResult(['status' => 'failed', 'durationMs' => 15_000, 'error' => ['message' => 'krok #2 (click @card-action-review): prvek se do 15 s neobjevil.', 'step' => ['index' => 2, 'verb' => 'click', 'value' => '@card-action-review']]]);
            return ['exitCode' => 1, 'termSignal' => null, 'cancelled' => false, 'durationMs' => 16_000];
        };
        $tester = $this->execute($command, ['--verb' => 'build', '--json' => true], separateStderr: true);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $report = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('failed', $report['status']);
        $this->assertSame(1, $report['exitCode']);
        $this->assertSame(['natáčení: krok #2 (click @card-action-review): prvek se do 15 s neobjevil. [krok #2 click @card-action-review]'], $report['problems']);
        $this->assertSame(['index' => 2, 'verb' => 'click', 'value' => '@card-action-review'], $report['result']['error']['step']);
        $this->assertFalse($report['steps']['compose']['ran'], 'po selhaném natáčení se neskládá');
        $this->assertCount(1, $command->runners);
        $this->assertNull($report['video']);
        $this->assertSame('OLD', file_get_contents($store->videoPath('s1')), 'předchozí video zůstává');
        $this->assertSame('2026-10-22', json_decode((string) file_get_contents($store->metaPath('s1')), true)['anchor'], 'meta beze změny');
        $check = json_decode((string) file_get_contents($store->checkPath('s1')), true);
        $this->assertSame('failed', $check['status']);
        $this->assertSame(2, $check['error']['step']['index']);
        $this->assertStringContainsString('Výsledek: nálezy (1).', $tester->getErrorOutput());
        $this->assertFileDoesNotExist(dirname($command->runners[0]->env['SHPD_STORAGE_STATE']) . '/storage-state.json');
        $this->assertCount(2, $db->sessionDeletes());
    }

    public function testFailedSeedStopsBeforeTheSession(): void
    {
        $this->markCacheFresh();
        $db = E2eFakeConnection::create();
        $command = $this->command($db);
        $command->seedExit = 1;
        $progress = $this->tmp . '/progress.jsonl';
        $tester = $this->execute($command, ['--progress-file' => $progress]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertSame([], $command->runners, 'runner se bez čistého zdroje dat nespouští');
        $this->assertSame([], $db->inserted, 'bez session');
        $this->assertStringContainsString('Přeseedování selhalo (exit 1)', $tester->getDisplay());
        $this->assertStringContainsString('přeseedování selhalo (dataset-seed exit 1)', $tester->getDisplay());
        $events = $this->progressEvents($progress);
        $this->assertSame(['started', 'step', 'step', 'step', 'finished'], array_column($events, 'event'));
        $this->assertSame(['reseed', 'failed', 1], [$events[3]['step'], $events[3]['status'], $events[3]['exitCode']]);

        $command = $this->command(E2eFakeConnection::create());
        $command->buildBehaviour = static fn (FakeBuildProcess $build): array => ['exitCode' => 2, 'termSignal' => null, 'cancelled' => false, 'durationMs' => 900];
        DatasetReader::removeTree($this->cacheDir());
        $tester = $this->execute($command);
        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertSame([], $command->subCalls, 'po selhaném buildu se neseeduje');
        $this->assertStringContainsString('sestavení demo sady selhalo (build.py exit 2)', $tester->getDisplay());
        $this->assertFileDoesNotExist($this->cacheDir() . '/build.json', 'bez značky — příště se přestaví');
    }

    // ── Úklid ──────────────────────────────────────────────────────────────

    public function testRunnerExceptionStillClosesTheSession(): void
    {
        $this->markCacheFresh();
        $db = E2eFakeConnection::create();
        $command = $this->command($db);
        $command->runnerBehaviour = static function (): array {
            throw new \RuntimeException('browser crashed');
        };
        $progress = $this->tmp . '/progress.jsonl';
        $tester = $this->execute($command, ['--json' => true, '--progress-file' => $progress], separateStderr: true);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertSame(['ok' => false, 'problems' => ['browser crashed']], json_decode(trim($tester->getDisplay()), true));
        $this->assertStringContainsString('video-run selhalo: browser crashed', $tester->getErrorOutput());
        $this->assertCount(1, $db->sessionTokens(), 'session vznikla');
        $this->assertFileDoesNotExist(dirname($command->runners[0]->env['SHPD_STORAGE_STATE']) . '/storage-state.json', 'close() ve finally');
        $this->assertDirectoryDoesNotExist(dirname($command->runners[0]->env['SHPD_STORAGE_STATE']));
        $this->assertCount(2, $db->sessionDeletes());
        $events = $this->progressEvents($progress);
        $this->assertSame('error', end($events)['event']);
        $this->assertStringNotContainsString($db->sessionTokens()[0], $tester->getDisplay() . $tester->getErrorOutput() . file_get_contents($progress));
    }

    public function testCancellationCleansUpAndExits130(): void
    {
        // Zrušení před startem: žádný krok, žádná session, žádná stavba.
        $db = E2eFakeConnection::create();
        $command = $this->command($db);
        $command->requestCancel();
        $progress = $this->tmp . '/progress.jsonl';
        $tester = $this->execute($command, ['--progress-file' => $progress]);
        $this->assertSame(VideoRunCommand::EXIT_CANCELLED, $tester->getStatusCode());
        $this->assertSame([], $command->subCalls);
        $this->assertSame([], $command->builds);
        $this->assertSame([], $db->inserted);
        $this->assertStringContainsString('Výsledek: zrušeno.', $tester->getDisplay());
        $events = $this->progressEvents($progress);
        $this->assertSame(['started', 'finished'], array_column($events, 'event'));
        $this->assertSame(['status' => 'cancelled', 'exitCode' => 130, 'video' => null], array_slice($events[1], 2));
        $this->assertFalse($command->handleSignal(15));

        // SIGTERM během stavby sady: python dostane signál, značka se nezapíše, nic dalšího neběží.
        unlink($progress);
        $command = $this->command(E2eFakeConnection::create());
        $command->buildBehaviour = static function (FakeBuildProcess $build) use ($command): array {
            $command->handleSignal(15);
            return ['exitCode' => null, 'termSignal' => 15, 'cancelled' => true, 'durationMs' => 2000];
        };
        $tester = $this->execute($command, ['--json' => true, '--progress-file' => $progress], separateStderr: true);
        $this->assertSame(VideoRunCommand::EXIT_CANCELLED, $tester->getStatusCode());
        $this->assertFileDoesNotExist($this->cacheDir() . '/build.json');
        $this->assertSame([], $command->subCalls);
        $this->assertSame([], $command->runners);

        // SIGTERM během natáčení: runner vidí žádost, session pryč, nic se nepovýší, check.json se nepíše.
        unlink($progress);
        $this->markCacheFresh();
        $db = E2eFakeConnection::create();
        $command = $this->command($db);
        $command->runnerBehaviour = static function (FakeRunnerProcess $runner) use ($command): array {
            $command->handleSignal(15);
            if (!$runner->cancelRequested()) {
                throw new \LogicException('runner must see the cancel request through the closure');
            }
            return ['exitCode' => 130, 'termSignal' => null, 'cancelled' => true, 'durationMs' => 3000];
        };
        $tester = $this->execute($command, ['--verb' => 'build', '--json' => true, '--progress-file' => $progress], separateStderr: true);

        $this->assertSame(VideoRunCommand::EXIT_CANCELLED, $tester->getStatusCode());
        $report = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('cancelled', $report['status']);
        $this->assertSame(130, $report['steps']['record']['exitCode']);
        $this->assertFalse($report['steps']['compose']['ran']);
        $this->assertNull($report['result'], 'zrušený runner result.json nezapsal — a nevadí');
        $this->assertCount(1, $command->runners);
        $this->assertFileDoesNotExist($this->tmp . '/run/videos/s1/check.json');
        $this->assertFileDoesNotExist($this->tmp . '/run/videos/s1/meta.json');
        $this->assertFileDoesNotExist(dirname($command->runners[0]->env['SHPD_STORAGE_STATE']) . '/storage-state.json');
        $this->assertCount(2, $db->sessionDeletes());
        $events = $this->progressEvents($progress);
        $this->assertSame(['step' => 'record', 'status' => 'failed', 'exitCode' => 130, 'termSignal' => null, 'cancelled' => true], array_slice($events[5], 2));
        $this->assertSame(['status' => 'cancelled', 'exitCode' => 130, 'video' => null], array_slice(end($events), 2));
    }

    public function testHumanOutputAndExistingUserWarning(): void
    {
        $this->markCacheFresh();
        $db = E2eFakeConnection::create();
        $db->userRow = ['id' => 7, 'login' => 'video', 'full_name' => 'Někdo jiný', 'is_admin' => 1, 'is_active' => 1, 'is_system' => 1];
        $command = $this->command($db);
        $tester = $this->execute($command);
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $display = $tester->getDisplay();
        $this->assertStringContainsString("uživatel 'video' má jméno 'Někdo jiný' — na videu bude ono", $display);
        $this->assertStringContainsString('Aplikace: http://127.0.0.1/' . self::DS_ID . '/app/, čas v prohlížeči: 2026-10-23T09:30:00+02:00', $display);
        $this->assertSame([null], $command->preflightForTest()->pins, 'IP literál se nepřipichuje');
        $this->assertArrayNotHasKey('VIDEO_HOST_RESOLVER_RULES', $command->runners[0]->env);
        $this->assertSame(UpdateEnvironment::DEFAULT_PATH, UpdateEnvironment::DEFAULT_PATH);
        $this->assertSame(['core_system_sessions'], array_column($db->inserted, 'table'), 'existující uživatel se nezakládá');
        $this->assertInstanceOf(DatasetCatalog::class, (new \ReflectionMethod($command, 'createCatalog'))->invoke($command));
        $this->assertInstanceOf(VideoScenarioCatalog::class, (new \ReflectionMethod($command, 'createScenarioCatalog'))->invoke($command));
    }
}

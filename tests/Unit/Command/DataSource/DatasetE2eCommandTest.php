<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\DataSource;

use PHPUnit\Framework\TestCase;
use Shipard\Command\DataSource\DatasetE2eCommand;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\DevUpdate\UpdateEnvironment;
use Shipard\Module\Core\Exchange\Dataset\DatasetManifest;
use Shipard\Module\Core\Exchange\Dataset\DatasetReader;
use Shipard\Module\Core\Exchange\Dataset\DatasetWriter;
use Shipard\Module\Core\Exchange\Dataset\E2e\E2ePreflight;
use Shipard\Module\Core\Exchange\Dataset\E2e\PlaywrightRunner;
use Shipard\Tests\Unit\Module\Core\Exchange\Dataset\DatasetPreflightTest;
use Shipard\Tests\Unit\Module\Core\Exchange\Dataset\E2e\E2eFakeConnection;
use Shipard\Tests\Unit\Module\Core\Exchange\Dataset\E2e\E2eReportTest;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/** Preflight bez sítě: dosažitelnost aplikace je skriptovaná. */
final class StubE2ePreflight extends E2ePreflight
{
    public ?string $reach = null;
    /** @var list<string> */
    public array $checked = [];

    public function appReachable(string $url, float $timeout = self::APP_TIMEOUT_SECONDS): ?string
    {
        $this->checked[] = $url;
        return $this->reach;
    }
}

/**
 * Místo Playwrightu: zapamatuje prostředí, přečte `storageState` v okamžiku
 * běhu (práva, token) a podle chování zapíše výstupy specu do adresáře běhu.
 */
final class FakePlaywrightRunner extends PlaywrightRunner
{
    /** @var array<string, string> */
    public array $env = [];
    public ?string $storagePerms = null;
    /** @var array<string, mixed>|null */
    public ?array $stateSeen = null;
    public int $runs = 0;

    /** @param \Closure(FakePlaywrightRunner, array<string, string>): array{exitCode: ?int, termSignal: ?int, cancelled: bool, durationMs: int} $behaviour */
    public function __construct(private readonly \Closure $cancel, private readonly \Closure $behaviour)
    {
        parent::__construct('/usr/bin/node', '/repo/tests/e2e/node_modules/@playwright/test/cli.js', '/repo/tests/e2e/playwright.config.mjs', '/repo/tests/e2e', $cancel);
    }

    public function run(array $env, OutputInterface $log): array
    {
        $this->runs++;
        $this->env = $env;
        $storage = $env['SHPD_E2E_STORAGE_STATE'] ?? null;
        if ($storage !== null && is_file($storage)) {
            $this->storagePerms = substr(sprintf('%o', fileperms($storage)), -4);
            $this->stateSeen = json_decode((string) file_get_contents($storage), true);
        }
        $log->writeln('[playwright] 1 passed');
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
}

class TestableDatasetE2eCommand extends DatasetE2eCommand
{
    public ?string $mode = 'development';
    public ?StubE2ePreflight $preflight = null;
    public ?FakePlaywrightRunner $runner = null;
    public ?\Closure $runnerBehaviour = null;
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $subCalls = [];
    public int $seedExit = 0;
    public string $verifyOutput = '';
    public int $verifyExit = 0;
    public int $expected = 2;
    /** @var list<string> */
    public array $messageIdsAsked = [];
    public ?string $outDir = null;

    public function __construct(
        DataSourceConfig $dsConfig,
        DataSourceConnection $db,
        private readonly string $dsDir,
        private readonly string $repo,
        private readonly string $binPath,
        private readonly string $browsersPath,
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

    protected function repoRoot(): string
    {
        return $this->repo;
    }

    protected function createPreflight(): E2ePreflight
    {
        return $this->preflight ??= new StubE2ePreflight($this->repo, $this->binPath, $this->browsersPath, $this->repo . '/home');
    }

    public function createPreflightForTest(): StubE2ePreflight
    {
        $this->createPreflight();
        return $this->preflight ?? throw new \LogicException('preflight');
    }

    protected function createRunner(E2ePreflight $preflight, \Closure $shouldCancel): PlaywrightRunner
    {
        return $this->runner = new FakePlaywrightRunner($shouldCancel, $this->runnerBehaviour ?? throw new \LogicException('runner behaviour not scripted'));
    }

    protected function runSub(string $command, array $args, OutputInterface $out): int
    {
        $this->subCalls[] = [$command, $args];
        if ($command === 'dataset-seed') {
            $out->writeln('[seed] reset + seed');
            return $this->seedExit;
        }
        $out->writeln($this->verifyOutput);
        return $this->verifyExit;
    }

    protected function expectedCards(DataSourceConnection $db, array $messageIds): int
    {
        $this->messageIdsAsked = $messageIds;
        return $this->expected;
    }

    protected function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-23T10:00:00+02:00');
    }

    protected function defaultOutputDir(): string
    {
        return $this->outDir ?? parent::defaultOutputDir();
    }
}

/**
 * `shpd-ds dataset-e2e` (tasks/testing-phase7a.md § 3, D91–D95): preflight
 * exit 2 (±`--json`), šťastná cesta s jedním JSON dokumentem na stdout,
 * nálezy exit 1, odmítnutý verify, úklid session při výjimce a při zrušení
 * (exit 130), sekvence průběhu, token nikde ve výstupu, `--no-reseed`.
 */
final class DatasetE2eCommandTest extends TestCase
{
    private const DS_ID = 'abcd-abcd-abcd-abcd';
    private string $tmp;
    private string $setDir;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/shpd_e2e_cmd_' . bin2hex(random_bytes(6));
        $repo = $this->tmp . '/repo';
        mkdir($repo . '/tests/datasets/cz', 0700, true);
        mkdir($repo . '/tests/eval', 0700, true);
        mkdir($repo . '/tests/e2e/node_modules/@playwright/test', 0700, true);
        mkdir($repo . '/tests/e2e/node_modules/playwright-core', 0700, true);
        mkdir($repo . '/home', 0700, true);
        mkdir($this->tmp . '/bin', 0700, true);
        mkdir($this->tmp . '/browsers/chromium-1234', 0700, true);
        mkdir($this->tmp . '/out', 0700, true);
        file_put_contents($this->tmp . '/bin/node', "#!/bin/sh\nexit 0\n");
        chmod($this->tmp . '/bin/node', 0755);
        file_put_contents($repo . '/tests/e2e/node_modules/@playwright/test/cli.js', '');
        file_put_contents($repo . '/tests/e2e/node_modules/playwright-core/browsers.json', '{"browsers":[{"name":"chromium","revision":"1234"}]}');
        file_put_contents($repo . '/tests/e2e/playwright.config.mjs', '');
        file_put_contents($this->tmp . '/browsers/chromium-1234/INSTALLATION_COMPLETE', '');

        $this->setDir = $repo . '/tests/datasets/cz/test';
        $w = DatasetWriter::create($this->setDir);
        $w->writeJsonc('mail/0001-msg-20260601-0001.jsonc', DatasetPreflightTest::mail());
        $w->writeRaw('mail/0001-msg-20260601-0001.files/faktura.pdf', '%PDF');
        $second = DatasetPreflightTest::mail();
        $second['messageId'] = 'MSG-20260601-0002';
        $w->writeJsonc('mail/0002-msg-20260601-0002.jsonc', $second);
        $w->writeRaw('mail/0002-msg-20260601-0002.files/faktura.pdf', '%PDF');
        $w->writeManifest(new DatasetManifest('cz-test', 'Test', null, 'fixed', '2026-10-14T00:00:00Z', ['mail' => 2], country: 'cz', profile: 'vat-payer'));

        $this->writeMainJson(['dataClass' => 'free', 'dataset' => ['name' => 'cz-test', 'hash' => 'h', 'seededAt' => '2026-10-23T07:00:00Z']]);
    }

    protected function tearDown(): void
    {
        DatasetReader::removeTree($this->tmp);
    }

    /** @param array<string, mixed> $config */
    private function writeMainJson(array $config): void
    {
        $dir = $this->tmp . '/ds/' . self::DS_ID . '/config';
        @mkdir($dir, 0700, true);
        file_put_contents($dir . '/main.json', json_encode(['id' => self::DS_ID, 'name' => 'DS', 'database_name' => 'x', 'country' => 'cz'] + $config));
    }

    /** @param array{name: string, hash: string, seededAt: ?string}|null $held */
    private function command(E2eFakeConnection $db, ?array $held = ['name' => 'cz-test', 'hash' => 'h', 'seededAt' => null]): TestableDatasetE2eCommand
    {
        $dsConfig = $this->createMock(DataSourceConfig::class);
        $dsConfig->method('getId')->willReturn(self::DS_ID);
        $dsConfig->method('getCountry')->willReturn('cz');
        $dsConfig->method('getDataset')->willReturn($held);
        $command = new TestableDatasetE2eCommand($dsConfig, $db, $this->tmp . '/ds/' . self::DS_ID, $this->tmp . '/repo', $this->tmp . '/bin', $this->tmp . '/browsers');
        $command->outDir = $this->tmp . '/out';
        $command->verifyOutput = (string) file_get_contents(E2eReportTest::fixturePath('verify-ok.json'));
        $command->runnerBehaviour = self::okRun();
        return $command;
    }

    /** @return \Closure(FakePlaywrightRunner, array<string, string>): array<string, mixed> */
    private static function okRun(): \Closure
    {
        return static function (FakePlaywrightRunner $runner, array $env): array {
            copy(E2eReportTest::fixturePath('walkthrough-ok.json'), $env['SHPD_E2E_OUTPUT_DIR'] . '/walkthrough.json');
            copy(E2eReportTest::fixturePath('report-ok.json'), $env['SHPD_E2E_OUTPUT_DIR'] . '/report.json');
            return ['exitCode' => 0, 'termSignal' => null, 'cancelled' => false, 'durationMs' => 9500];
        };
    }

    /** @return \Closure(FakePlaywrightRunner, array<string, string>): array<string, mixed> */
    private static function failedRun(): \Closure
    {
        return static function (FakePlaywrightRunner $runner, array $env): array {
            copy(E2eReportTest::fixturePath('walkthrough-failed.json'), $env['SHPD_E2E_OUTPUT_DIR'] . '/walkthrough.json');
            copy(E2eReportTest::fixturePath('report-failed.json'), $env['SHPD_E2E_OUTPUT_DIR'] . '/report.json');
            file_put_contents($env['SHPD_E2E_OUTPUT_DIR'] . '/traces/trace-mail_suggestion_22.zip', 'PK');
            return ['exitCode' => 1, 'termSignal' => null, 'cancelled' => false, 'durationMs' => 20500];
        };
    }

    /** @param array<string, mixed> $args */
    private function execute(TestableDatasetE2eCommand $command, array $args = [], bool $separateStderr = false): CommandTester
    {
        $app = new Application();
        $app->add($command);
        $tester = new CommandTester($app->find('dataset-e2e'));
        $tester->execute($args + ['path' => $this->setDir], ['capture_stderr_separately' => $separateStderr]);
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

    // ── Preflight ──────────────────────────────────────────────────────────

    public function testPreflightRefusesWithExitTwoAndJsonProblems(): void
    {
        $db = E2eFakeConnection::create();
        $command = $this->command($db);
        $command->mode = 'production';
        $tester = $this->execute($command);
        $this->assertSame(Command::INVALID, $tester->getStatusCode());
        $this->assertStringContainsString('dataset-e2e odmítnuto:', $tester->getDisplay());
        $this->assertStringContainsString("mode is 'production'", $tester->getDisplay());
        $this->assertSame([], $command->subCalls);
        $this->assertSame([], $db->inserted, 'preflight nic nemění');
        $this->assertNull($command->runner);

        $command = $this->command($db);
        $command->mode = null;
        $tester = $this->execute($command, ['--json' => true], separateStderr: true);
        $this->assertSame(Command::INVALID, $tester->getStatusCode());
        $json = json_decode(trim($tester->getDisplay()), true, 512, JSON_THROW_ON_ERROR);
        $this->assertFalse($json['ok']);
        $this->assertCount(1, $json['problems']);
        $this->assertStringContainsString('Režim serveru nejde určit', $json['problems'][0]);
        $this->assertCount(1, explode("\n", trim($tester->getDisplay())), 'stdout = jeden JSON řádek');
        $this->assertStringContainsString('dataset-e2e odmítnuto:', $tester->getErrorOutput());

        // realCopy zdroj dat
        $this->writeMainJson(['dataClass' => 'realCopy']);
        $tester = $this->execute($this->command($db));
        $this->assertSame(Command::INVALID, $tester->getStatusCode());
        $this->assertStringContainsString('not a free data source', $tester->getDisplay());
        $this->writeMainJson(['dataClass' => 'free']);

        // chybějící nástroje → přesná věta
        unlink($this->tmp . '/repo/tests/e2e/node_modules/@playwright/test/cli.js');
        $tester = $this->execute($this->command($db));
        $this->assertSame(Command::INVALID, $tester->getStatusCode());
        $this->assertStringContainsString(E2ePreflight::REMEDY, $tester->getDisplay());
        file_put_contents($this->tmp . '/repo/tests/e2e/node_modules/@playwright/test/cli.js', '');

        // nedosažitelná aplikace
        $command = $this->command($db);
        $command->createPreflightForTest()->reach = 'Aplikace na http://127.0.0.1/abcd-abcd-abcd-abcd/app/ není dosažitelná (Connection refused)';
        $tester = $this->execute($command);
        $this->assertSame(Command::INVALID, $tester->getStatusCode());
        $this->assertStringContainsString('není dosažitelná', $tester->getDisplay());
        $this->assertSame(['http://127.0.0.1/' . self::DS_ID . '/app/'], $command->preflight->checked);

        // špatný --base-url
        $tester = $this->execute($this->command($db), ['--base-url' => 'ftp://x']);
        $this->assertSame(Command::INVALID, $tester->getStatusCode());
        $this->assertStringContainsString('--base-url', $tester->getDisplay());

        // neznámá sada (manifest mimo seznam) — jiný název než v katalogu
        $other = $this->tmp . '/elsewhere';
        $w = DatasetWriter::create($other);
        $w->writeJsonc('mail/0001-msg-x.jsonc', DatasetPreflightTest::mail());
        $w->writeRaw('mail/0001-msg-x.files/faktura.pdf', '%PDF');
        $w->writeManifest(new DatasetManifest('cz-unknown', 'X', null, 'fixed', '2026-10-14T00:00:00Z', ['mail' => 1], country: 'cz'));
        $tester = $this->execute($this->command($db), ['path' => $other]);
        $this->assertSame(Command::INVALID, $tester->getStatusCode());
        $this->assertStringContainsString("Unknown dataset 'cz-unknown'", $tester->getDisplay());
    }

    // ── Šťastná cesta ──────────────────────────────────────────────────────

    public function testHappyPathWritesOneJsonDocumentToStdoutAndCleansTheSession(): void
    {
        $db = E2eFakeConnection::create();
        $command = $this->command($db);
        $progress = $this->tmp . '/progress.jsonl';
        $tester = $this->execute($command, ['--json' => true, '--progress-file' => $progress], separateStderr: true);

        $stdout = $tester->getDisplay();
        $stderr = $tester->getErrorOutput();
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), $stderr);
        $report = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('ok', $report['status']);
        $this->assertSame(0, $report['exitCode']);
        $this->assertSame([], $report['problems']);
        $this->assertSame('cz-test', $report['dataset']['name']);
        $this->assertSame(self::DS_ID, $report['dataSource']);
        $this->assertSame('http://127.0.0.1/' . self::DS_ID . '/app/', $report['appUrl']);
        $this->assertSame($this->tmp . '/out', $report['outputDir']);
        $this->assertTrue($report['steps']['reseed']['ran']);
        $this->assertSame(0, $report['steps']['reseed']['exitCode']);
        $this->assertSame(['ran' => true, 'exitCode' => 0, 'termSignal' => null, 'durationMs' => 9500], $report['steps']['walkthrough']);
        $this->assertSame(['ran' => true, 'exitCode' => 0, 'refused' => null], $report['steps']['verify']);
        $this->assertSame(2, $report['cards']['expected']);
        $this->assertCount(2, $report['cards']['applied']);
        $this->assertSame(['ok' => 3, 'diff' => 0, 'skipped' => 0], $report['verify']['summary']);
        $this->assertSame($this->tmp . '/out/walkthrough.json', $report['artifacts']['walkthrough']);

        // Lidské řádky jen na stderr.
        $this->assertStringContainsString('Sada cz-test → zdroj dat ' . self::DS_ID, $stderr);
        $this->assertStringContainsString("Uživatel 'e2e' založen", $stderr);
        $this->assertStringContainsString('Karet návrhů k proklikání: 2', $stderr);
        $this->assertStringContainsString('[seed] reset + seed', $stderr, 'výstup dataset-seed jde na stderr');
        $this->assertStringContainsString('[playwright] 1 passed', $stderr, 'výstup Playwrightu jde na stderr');
        $this->assertStringContainsString('Výsledek: v pořádku.', $stderr);
        $this->assertStringNotContainsString('Sada cz-test', $stdout);

        // Kroky ve stejném procesu, ve správném pořadí a s argumenty.
        $this->assertSame([
            ['dataset-seed', ['path' => $this->setDir, '--yes' => true]],
            ['dataset-verify', ['path' => $this->setDir, '--no-apply' => true, '--json' => true]],
        ], $command->subCalls);
        $this->assertSame(['MSG-20260601-0001', 'MSG-20260601-0002'], $command->messageIdsAsked);

        // Kontrakt se specem (krok 2): jen tyto proměnné + pevný PATH + CI.
        $runner = $command->runner;
        $this->assertNotNull($runner);
        $this->assertSame(1, $runner->runs);
        $e2eVars = array_filter($runner->env, static fn (string $k): bool => str_starts_with($k, 'SHPD_E2E_'), ARRAY_FILTER_USE_KEY);
        $this->assertSame([
            'SHPD_E2E_APP_URL' => 'http://127.0.0.1/' . self::DS_ID . '/app/',
            'SHPD_E2E_STORAGE_STATE' => $this->tmp . '/out/storage-state.json',
            'SHPD_E2E_OUTPUT_DIR' => $this->tmp . '/out',
            'SHPD_E2E_EXPECTED_CARDS' => '2',
            'SHPD_E2E_PROGRESS_FILE' => $progress,
        ], $e2eVars);
        $this->assertSame('1', $runner->env['CI']);
        $this->assertSame(UpdateEnvironment::DEFAULT_PATH, $runner->env['PATH']);
        $this->assertArrayHasKey('HOME', $runner->env);
        $this->assertDirectoryExists($this->tmp . '/out/traces');

        // Session: uživatel e2e založen, úklid z minula, session s TTL max(3600, 180 × 2), storageState 0600 jen po dobu běhu.
        $this->assertSame('core_system_users', $db->inserted[0]['table']);
        $this->assertSame(['is_active' => 1, 'is_admin' => 1, 'is_system' => 1], array_intersect_key($db->inserted[0]['data'], ['is_active' => 1, 'is_admin' => 1, 'is_system' => 1]));
        $this->assertNull($db->inserted[0]['data']['password_hash']);
        $tokens = $db->sessionTokens();
        $this->assertCount(1, $tokens);
        $this->assertEqualsWithDelta(time() + 3600, strtotime((string) $db->inserted[1]['data']['expires']), 5);
        $this->assertSame('0600', $runner->storagePerms);
        $this->assertSame($tokens[0], $runner->tokenSeen());
        $this->assertSame('http://127.0.0.1', $runner->stateSeen['origins'][0]['origin']);
        $this->assertFileDoesNotExist($this->tmp . '/out/storage-state.json');
        $this->assertCount(2, $db->sessionDeletes(), 'zneplatnění na začátku a při zavření');

        // Token nikde: stdout, stderr, průběh, report, prostředí Playwrightu.
        $token = $tokens[0];
        $this->assertStringNotContainsString($token, $stdout);
        $this->assertStringNotContainsString($token, $stderr);
        $this->assertStringNotContainsString($token, (string) file_get_contents($progress));
        $this->assertStringNotContainsString($token, (string) json_encode($report));
        $this->assertStringNotContainsString($token, implode("\n", $runner->env));
        $this->assertStringNotContainsString('shpd_st_', $stdout . $stderr . file_get_contents($progress));

        // Průběh: started → reseed → walkthrough (expected) → verify → finished.
        $events = $this->progressEvents($progress);
        $this->assertSame(
            [['started', null], ['step', 'reseed'], ['step', 'reseed'], ['step', 'walkthrough'], ['step', 'walkthrough'], ['step', 'verify'], ['step', 'verify'], ['finished', null]],
            array_map(static fn (array $e): array => [$e['event'], $e['step'] ?? null], $events),
        );
        $this->assertSame(['pid', 'dataset', 'dataSource', 'appUrl', 'outputDir', 'expected'], array_keys(array_slice($events[0], 2)));
        $this->assertSame('cz-test', $events[0]['dataset']);
        $this->assertNull($events[0]['expected']);
        $this->assertSame(['started', 'finished'], [$events[1]['status'], $events[2]['status']]);
        $this->assertSame(0, $events[2]['exitCode']);
        $this->assertSame(2, $events[3]['expected']);
        $this->assertSame(['step' => 'walkthrough', 'status' => 'finished', 'exitCode' => 0, 'termSignal' => null, 'cancelled' => false], array_slice($events[4], 2));
        $this->assertSame(['status' => 'ok', 'applied' => 2, 'failed' => 0, 'exitCode' => 0], array_slice($events[7], 2));
    }

    public function testHumanOutputWithoutJsonAndCustomBaseUrl(): void
    {
        $db = E2eFakeConnection::create();
        $command = $this->command($db);
        $tester = $this->execute($command, ['--base-url' => 'https://dev.example:8443/']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Aplikace: https://dev.example:8443/' . self::DS_ID . '/app/', $display);
        $this->assertStringContainsString('Výsledek: v pořádku.', $display);
        $this->assertStringNotContainsString('"status"', $display, 'bez --json žádný JSON');
        $this->assertSame('https://dev.example:8443', $command->runner?->stateSeen['origins'][0]['origin'], 'origin storageState = origin --base-url');
        $this->assertSame(['https://dev.example:8443/' . self::DS_ID . '/app/'], $command->preflight->checked);
    }

    // ── Nálezy ─────────────────────────────────────────────────────────────

    public function testFindingsGiveExitOne(): void
    {
        $db = E2eFakeConnection::create();
        $command = $this->command($db);
        $command->runnerBehaviour = self::failedRun();
        $command->verifyOutput = (string) file_get_contents(E2eReportTest::fixturePath('verify-diff.json'));
        $command->verifyExit = 1;
        $command->expected = 3;
        $progress = $this->tmp . '/progress.jsonl';
        $tester = $this->execute($command, ['--json' => true, '--progress-file' => $progress], separateStderr: true);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $report = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('failed', $report['status']);
        $this->assertSame(1, $report['exitCode']);
        $this->assertContains('karta mail_suggestion:22: decide/no_create_offered (supplier)', $report['problems']);
        $this->assertContains('kontrola Položky a účty: rozdíl (2)', $report['problems']);
        $this->assertContains('kontrola Náklady: přeskočeno — žádný použitý doklad', $report['problems']);
        $this->assertSame(['ran' => true, 'exitCode' => 1, 'refused' => null], $report['steps']['verify']);
        $this->assertSame(
            [['cardId' => 'mail_suggestion:22', 'file' => 'trace-mail_suggestion_22.zip', 'path' => $this->tmp . '/out/traces/trace-mail_suggestion_22.zip']],
            $report['artifacts']['traces'],
        );
        $this->assertStringContainsString('Výsledek: nálezy (', $tester->getErrorOutput());
        $events = $this->progressEvents($progress);
        $this->assertSame(['status' => 'failed', 'applied' => 1, 'failed' => 2, 'exitCode' => 1], array_slice(end($events), 2));
        $this->assertSame('failed', $events[6]['status'], 'verify s exit 1 = krok failed');
        $this->assertFileDoesNotExist($this->tmp . '/out/storage-state.json');
    }

    public function testVerifyRefusalIsAFinding(): void
    {
        $db = E2eFakeConnection::create();
        $command = $this->command($db);
        $command->verifyOutput = "dataset-verify odmítnuto:\n  - zdroj dat obsahuje data mimo sadu — dataset-verify běží jen nad čistě naseedovaným zdrojem dat:\n  - doklad #5 nemá zdrojovou zprávu";
        $command->verifyExit = 2;
        $tester = $this->execute($command, ['--json' => true], separateStderr: true);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $report = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('failed', $report['status']);
        $this->assertNull($report['verify']);
        $this->assertSame(2, $report['steps']['verify']['exitCode']);
        $this->assertStringContainsString('doklad #5 nemá zdrojovou zprávu', $report['steps']['verify']['refused']);
        $this->assertSame(['dataset-verify odmítnuto: zdroj dat obsahuje data mimo sadu — dataset-verify běží jen nad čistě naseedovaným zdrojem dat:; doklad #5 nemá zdrojovou zprávu'], $report['problems']);
    }

    public function testSeedFailureSkipsWalkthroughAndVerify(): void
    {
        $db = E2eFakeConnection::create();
        $command = $this->command($db);
        $command->seedExit = 1;
        $progress = $this->tmp . '/progress.jsonl';
        $tester = $this->execute($command, ['--progress-file' => $progress]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertSame(['dataset-seed'], array_column($command->subCalls, 0));
        $this->assertNull($command->runner, 'Playwright se bez čistého zdroje dat nespouští');
        $this->assertSame([], $db->inserted, 'bez session');
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Přeseedování selhalo (exit 1)', $display);
        $this->assertStringContainsString('přeseedování selhalo (dataset-seed exit 1)', $display);
        $events = $this->progressEvents($progress);
        $this->assertSame(['started', 'step', 'step', 'finished'], array_column($events, 'event'));
        $this->assertSame('failed', $events[2]['status']);
        $this->assertSame(1, $events[2]['exitCode']);
    }

    // ── Úklid ──────────────────────────────────────────────────────────────

    public function testRunnerExceptionStillClosesTheSession(): void
    {
        $db = E2eFakeConnection::create();
        $command = $this->command($db);
        $command->runnerBehaviour = static function (): array {
            throw new \RuntimeException('browser crashed');
        };
        $progress = $this->tmp . '/progress.jsonl';
        $tester = $this->execute($command, ['--json' => true, '--progress-file' => $progress], separateStderr: true);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertSame(['ok' => false, 'problems' => ['browser crashed']], json_decode(trim($tester->getDisplay()), true));
        $this->assertStringContainsString('dataset-e2e selhalo: browser crashed', $tester->getErrorOutput());
        $this->assertCount(1, $db->sessionTokens(), 'session vznikla');
        $this->assertFileDoesNotExist($this->tmp . '/out/storage-state.json', 'close() ve finally');
        $this->assertCount(2, $db->sessionDeletes());
        $this->assertSame(['dataset-seed'], array_column($command->subCalls, 0), 'verify po pádu neběží');
        $events = $this->progressEvents($progress);
        $this->assertSame('error', end($events)['event']);
        $this->assertSame('browser crashed', end($events)['message']);
        $this->assertStringNotContainsString($db->sessionTokens()[0], $tester->getDisplay() . $tester->getErrorOutput() . file_get_contents($progress));
    }

    public function testCancellationCleansUpAndExits130(): void
    {
        // Zrušení před startem: žádný krok, žádná session.
        $db = E2eFakeConnection::create();
        $command = $this->command($db);
        $command->requestCancel();
        $progress = $this->tmp . '/progress.jsonl';
        $tester = $this->execute($command, ['--progress-file' => $progress]);
        $this->assertSame(DatasetE2eCommand::EXIT_CANCELLED, $tester->getStatusCode());
        $this->assertSame([], $command->subCalls);
        $this->assertSame([], $db->inserted);
        $this->assertStringContainsString('Výsledek: zrušeno.', $tester->getDisplay());
        $events = $this->progressEvents($progress);
        $this->assertSame(['started', 'finished'], array_column($events, 'event'));
        $this->assertSame(['status' => 'cancelled', 'applied' => 0, 'failed' => 0, 'exitCode' => 130], array_slice($events[1], 2));
        $this->assertFalse($command->handleSignal(15), 'false = Symfony proces neukončí');

        // SIGTERM během proklikání: runner vidí žádost, skončí, session pryč, verify neběží.
        unlink($progress);
        $db = E2eFakeConnection::create();
        $command = $this->command($db);
        $command->runnerBehaviour = static function (FakePlaywrightRunner $runner, array $env) use ($command): array {
            $command->handleSignal(15);
            if (!$runner->cancelRequested()) {
                throw new \LogicException('runner must see the cancel request through the closure');
            }
            $walkthrough = E2eReportTest::fixture('walkthrough-ok.json');
            $walkthrough['applied'] = [$walkthrough['applied'][0]];
            $walkthrough['complete'] = false;
            file_put_contents($env['SHPD_E2E_OUTPUT_DIR'] . '/walkthrough.json', json_encode($walkthrough));
            return ['exitCode' => 130, 'termSignal' => null, 'cancelled' => true, 'durationMs' => 3000];
        };
        $tester = $this->execute($command, ['--json' => true, '--progress-file' => $progress], separateStderr: true);

        $this->assertSame(DatasetE2eCommand::EXIT_CANCELLED, $tester->getStatusCode());
        $report = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('cancelled', $report['status']);
        $this->assertSame(130, $report['exitCode']);
        $this->assertSame(130, $report['steps']['walkthrough']['exitCode']);
        $this->assertFalse($report['steps']['verify']['ran']);
        $this->assertCount(1, $report['cards']['applied'], 'rozpracovaný průchod se do reportu dostane');
        $this->assertSame(['dataset-seed'], array_column($command->subCalls, 0));
        $this->assertFileDoesNotExist($this->tmp . '/out/storage-state.json');
        $this->assertCount(2, $db->sessionDeletes());
        $events = $this->progressEvents($progress);
        $this->assertSame(['step' => 'walkthrough', 'status' => 'failed', 'exitCode' => 130, 'termSignal' => null, 'cancelled' => true], array_slice($events[4], 2));
        $this->assertSame(['status' => 'cancelled', 'applied' => 1, 'failed' => 0, 'exitCode' => 130], array_slice(end($events), 2));
    }

    // ── --no-reseed ────────────────────────────────────────────────────────

    public function testNoReseedSkipsSeedingAndRequiresTheHeldDataset(): void
    {
        $db = E2eFakeConnection::create();
        $command = $this->command($db);
        $tester = $this->execute($command, ['--no-reseed' => true, '--json' => true], separateStderr: true);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getErrorOutput());
        $this->assertSame(['dataset-verify'], array_column($command->subCalls, 0));
        $report = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['ran' => false, 'exitCode' => null, 'durationMs' => null], $report['steps']['reseed']);
        $this->assertStringContainsString('vynecháno (--no-reseed)', $tester->getErrorOutput());

        $command = $this->command($db, held: ['name' => 'cz-vat-payer', 'hash' => 'h', 'seededAt' => null]);
        $tester = $this->execute($command, ['--no-reseed' => true]);
        $this->assertSame(Command::INVALID, $tester->getStatusCode());
        $this->assertStringContainsString("--no-reseed: zdroj dat drží sadu 'cz-vat-payer' (main.json dataset.name), ne 'cz-test'", $tester->getDisplay());
        $this->assertSame([], $command->subCalls);

        $command = $this->command($db, held: null);
        $tester = $this->execute($command, ['--no-reseed' => true]);
        $this->assertSame(Command::INVALID, $tester->getStatusCode());
        $this->assertStringContainsString('zdroj dat drží sadu žádnou', $tester->getDisplay());
    }
}

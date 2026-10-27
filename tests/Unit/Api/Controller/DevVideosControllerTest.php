<?php
declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\Controller\DevVideosController;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\DevJobs\JobLauncher;
use Shipard\Core\DevJobs\JobRecord;
use Shipard\Core\DevJobs\JobStore;
use Shipard\Module\Core\Exchange\Dataset\DatasetManifest;
use Shipard\Module\Core\Exchange\Dataset\DatasetReader;
use Shipard\Module\Core\Exchange\Dataset\DatasetWriter;
use Shipard\Module\Core\Exchange\Dataset\E2e\LocalBaseUrl;
use Shipard\Module\Core\Exchange\Dataset\Video\VideoStore;
use Shipard\Tests\Unit\Module\Core\Exchange\Dataset\Video\VideoScenarioTest;

class TestableDevVideosController extends DevVideosController
{
	/** @var array<string, list<string>> */
	public array $dns = ['dev.example' => ['127.0.0.1']];
	/** @var list<string> */
	public array $localAddresses = ['127.0.0.1', '::1', '10.0.0.7'];
	/** @var list<string> */
	public array $problems = [];
	public ?string $gotenberg = 'http://10.0.0.1:3000';
	public string $nextId = 'zzzz-zzzz-zzzz-zzzz';

	public function __construct(string $repoRoot, string $dataSourcesDir, string $serverMode, JobStore $jobs, JobLauncher $launcher, private readonly string $videosDir)
	{
		parent::__construct($repoRoot, $dataSourcesDir, $serverMode, $jobs, $launcher);
	}

	protected function toolProblems(): array
	{
		return $this->problems;
	}

	protected function gotenbergUrl(): ?string
	{
		return $this->gotenberg;
	}

	protected function localBaseUrl(): LocalBaseUrl
	{
		return new LocalBaseUrl(fn (string $host): array => $this->dns[$host] ?? [], fn (): array => $this->localAddresses);
	}

	protected function dsCommand(): array
	{
		return ['/usr/bin/php', '/repo/bin/shpd-ds'];
	}

	protected function serverCommand(): array
	{
		return ['/usr/bin/php', '/repo/bin/shpd-server'];
	}

	protected function newDataSourceId(): string
	{
		return $this->nextId;
	}

	protected function now(): \DateTimeImmutable
	{
		return new \DateTimeImmutable('2026-10-27T12:00:00Z');
	}

	protected function store(): VideoStore
	{
		return new VideoStore($this->videosDir);
	}
}

/**
 * Oddíl Videa (tasks/testing-phase7b.md § 3, D122, D126, D127): výpis
 * scénářů se stavem a zdrojem dat, úloha `video` s originem požadavku,
 * 409 bez zdroje dat, 422 bez nástrojů, 400 cizí origin, založení zdroje
 * dat (ds-create + ds-upgrade), video s Range jen podle `meta.json`.
 */
final class DevVideosControllerTest extends TestCase
{
	private string $tmp;
	private string $repo;
	private string $dsDir;
	private string $jobsDir;
	private string $videosDir;
	private JobStore $jobs;
	/** @var list<array{argv: list<string>, cwd: string}> */
	private array $spawns = [];
	private TestableDevVideosController $ctrl;

	protected function setUp(): void
	{
		$this->tmp = sys_get_temp_dir() . '/shpd_devvideos_' . bin2hex(random_bytes(5));
		$this->repo = $this->tmp . '/repo';
		$this->dsDir = $this->tmp . '/data-sources';
		$this->jobsDir = $this->tmp . '/run/dev-jobs';
		$this->videosDir = $this->tmp . '/run/videos';
		mkdir($this->repo . '/demo/scenarios', 0700, true);
		mkdir($this->dsDir, 0700, true);
		file_put_contents($this->repo . '/demo/scenarios/s1.jsonc', VideoScenarioTest::scenarioJsonc('s1', [], "// S1 — Přeposlaná faktura.\n// Popis.\n"));
		file_put_contents($this->repo . '/demo/scenarios/broken.jsonc', VideoScenarioTest::scenarioJsonc('broken', ['dataset' => null]));
		file_put_contents($this->repo . '/demo/scenarios/s2.jsonc', VideoScenarioTest::scenarioJsonc('s2', ['dataset' => 'other-set']));
		$w = DatasetWriter::create($this->repo . '/demo/datasets/demo-set');
		$w->writeManifest(new DatasetManifest('demo-set', 'Demo — IT servis', null, 'fixed', '2026-10-20T00:00:00Z', [], country: 'cz', profile: 'vat-payer'));

		$this->createDs('aaaa-aaaa-aaaa-aaaa', ['dataClass' => 'free', 'purpose' => 'videa — demo-set']);
		$this->createDs('bbbb-bbbb-bbbb-bbbb', ['dataClass' => 'realCopy', 'purpose' => 'videa — other-set']);
		$this->createDs('cccc-cccc-cccc-cccc', ['dataClass' => 'free', 'purpose' => 'evaluace AI — demo-set']);

		$this->jobs = new JobStore($this->jobsDir, static fn (): bool => true, static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-10-27T12:00:00Z'));
		$launcher = new JobLauncher(
			$this->jobs,
			function (array $argv, string $cwd, string $log): bool {
				$this->spawns[] = ['argv' => $argv, 'cwd' => $cwd];
				return true;
			},
			static fn (int $pid, int $signal): bool => true,
			['/usr/bin/php', '/repo/bin/shpd-server'],
		);
		$this->ctrl = new TestableDevVideosController($this->repo, $this->dsDir, 'development', $this->jobs, $launcher, $this->videosDir);
	}

	protected function tearDown(): void
	{
		DatasetReader::removeTree($this->tmp);
	}

	/** @param array<string, mixed> $extra */
	private function createDs(string $id, array $extra): void
	{
		if (!is_dir($this->dsDir . '/' . $id . '/config')) {
			mkdir($this->dsDir . '/' . $id . '/config', 0700, true);
		}
		file_put_contents($this->dsDir . '/' . $id . '/config/main.json', json_encode([
			'id' => $id, 'name' => 'DS ' . substr($id, 0, 4), 'database_name' => str_replace('-', '_', $id), 'created' => '2026-10-01T00:00:00Z', 'country' => 'cz',
		] + $extra, JSON_UNESCAPED_UNICODE));
	}

	/** @param array<string, mixed> $body @param array<string, string> $server */
	private function request(string $method, string $path, array $body = [], array $server = [], array $query = []): Response
	{
		$request = Request::fromArray($method, '/_dev/api/videos' . $path, $query, $body === [] ? '' : (string) json_encode($body), ['REMOTE_ADDR' => '10.0.0.5'] + $server);
		return $this->ctrl->dispatch($request);
	}

	/** @return array<string, mixed> */
	private function data(Response $r): array
	{
		return (array) ($r->getPayload()['data'] ?? []);
	}

	/** @return array<string, mixed> */
	private function error(Response $r): array
	{
		return (array) ($r->getPayload()['error'] ?? []);
	}

	private function storeVideo(string $id = 's1'): VideoStore
	{
		$store = new VideoStore($this->videosDir);
		$store->prepare($id);
		file_put_contents($store->composedPath($id), str_repeat('v', 1000));
		$store->promote($id, ['dataset' => 'demo-set', 'anchor' => '2026-10-27', 'commit' => 'abc', 'recordedAt' => '2026-10-27T08:00:00Z', 'durationMs' => 42000]);
		$store->writeCheck($id, ['at' => '2026-10-27T08:00:00Z', 'status' => 'ok', 'anchor' => '2026-10-27']);
		return $store;
	}

	// ── Seznam ─────────────────────────────────────────────────────────────

	public function testListShowsScenariosStateDataSourcesAndProblems(): void
	{
		$this->storeVideo();
		$r = $this->request('GET', '');
		$this->assertSame(200, $r->getStatus());
		$data = $this->data($r);
		$this->assertTrue($data['canRun']);
		$this->assertSame([], $data['toolProblems']);
		$rows = array_column($data['scenarios'], null, 'id');
		$this->assertSame(['broken', 's1', 's2'], array_keys($rows));

		$s1 = $rows['s1'];
		$this->assertSame('S1 — Přeposlaná faktura.', $s1['title']);
		$this->assertSame('Popis.', $s1['description']);
		$this->assertSame('demo-set', $s1['dataset']);
		$this->assertSame('Demo — IT servis', $s1['datasetTitle']);
		$this->assertTrue($s1['datasetKnown']);
		$this->assertSame(['cs-CZ', 'Europe/Prague', '09:30', 2], [$s1['locale'], $s1['timezone'], $s1['clockTime'], $s1['stepCount']]);
		$this->assertNull($s1['problem']);
		$this->assertTrue($s1['hasVideo']);
		$this->assertSame('s1.mp4', $s1['meta']['file']);
		$this->assertSame(1000, $s1['meta']['bytes']);
		$this->assertSame('ok', $s1['check']['status']);
		$this->assertSame(['id' => 'aaaa-aaaa-aaaa-aaaa', 'name' => 'DS aaaa', 'state' => 'active', 'purpose' => 'videa — demo-set'], $s1['dataSource']);
		$this->assertNull($s1['activeJob']);

		$this->assertStringContainsString('chybí dataset', $rows['broken']['problem']);
		$this->assertNull($rows['broken']['dataset']);
		$this->assertNull($rows['broken']['dataSource']);
		$this->assertFalse($rows['broken']['hasVideo']);

		$this->assertFalse($rows['s2']['datasetKnown'], 'sada mimo katalog');
		$this->assertNull($rows['s2']['dataSource'], 'realCopy se pro videa nepoužije');

		$this->ctrl->problems = ['ffmpeg není v PATH'];
		$this->ctrl->gotenberg = null;
		$data = $this->data($this->request('GET', ''));
		$this->assertFalse($data['canRun']);
		$this->assertCount(2, $data['toolProblems']);
		$this->assertStringContainsString('Gotenberg není nastavený', $data['toolProblems'][1]);
	}

	public function testListReportsTheRunningJobOfTheDataSource(): void
	{
		$r = $this->request('POST', '/s1/run', ['verb' => 'build'], ['HTTP_HOST' => '10.0.0.7', 'HTTP_ORIGIN' => 'http://10.0.0.7']);
		$this->assertSame(201, $r->getStatus(), json_encode($r->getPayload()));
		$job = $this->data($r)['job'];
		$rows = array_column($this->data($this->request('GET', ''))['scenarios'], null, 'id');
		$this->assertSame(['id' => $job['id'], 'type' => 'video', 'status' => 'starting', 'scenario' => 's1', 'verb' => 'build'], $rows['s1']['activeJob']);
	}

	// ── Ověřit / Natočit ───────────────────────────────────────────────────

	public function testRunLaunchesTheVideoJobWithTheRequestOrigin(): void
	{
		$r = $this->request('POST', '/s1/run', ['verb' => 'check'], ['HTTP_HOST' => 'dev.example:8080', 'HTTPS' => 'on']);
		$this->assertSame(201, $r->getStatus(), json_encode($r->getPayload()));
		$job = $this->data($r)['job'];
		$this->assertSame(JobRecord::TYPE_VIDEO, $job['type']);
		$this->assertSame('aaaa-aaaa-aaaa-aaaa', $job['dataSource']['id']);
		$this->assertSame('demo-set', $job['dataset']);
		$this->assertSame(['scenario' => 's1', 'verb' => 'check', 'baseUrl' => 'https://dev.example:8080'], $job['params']);
		$this->assertSame(DevVideosController::VIDEO_CANCEL_GRACE_SECONDS, $job['cancelGraceSeconds']);
		$step = $job['steps'][0];
		$this->assertTrue($step['captureStdout']);
		$this->assertSame($this->dsDir . '/aaaa-aaaa-aaaa-aaaa', $step['cwd']);
		$this->assertSame([
			'/usr/bin/php', '/repo/bin/shpd-ds', 'video-run', $this->repo . '/demo/scenarios/s1.jsonc',
			'--verb', 'check', '--json',
			'--progress-file', $this->jobsDir . '/' . $job['id'] . '/progress.jsonl',
			'--base-url', 'https://dev.example:8080',
		], $step['argv']);
		$this->assertCount(1, $this->spawns);
		$this->assertStringNotContainsString('shpd_st_', json_encode($job));

		// Druhá úloha na tomtéž zdroji dat → 409 s id běžící.
		$r = $this->request('POST', '/s1/run', ['verb' => 'build'], ['HTTP_HOST' => '10.0.0.7']);
		$this->assertSame(409, $r->getStatus());
		$this->assertSame('JOB_RUNNING', $this->error($r)['code']);
		$this->assertSame($job['id'], $this->error($r)['details']['jobId']);
	}

	public function testRunRefusesBadVerbUnknownScenarioMissingDataSourceToolsAndForeignOrigin(): void
	{
		$this->assertSame('VALIDATION', $this->error($this->request('POST', '/s1/run', ['verb' => 'record'], ['HTTP_HOST' => '10.0.0.7']))['code']);
		$this->assertSame('UNKNOWN_SCENARIO', $this->error($this->request('POST', '/nope/run', ['verb' => 'check'], ['HTTP_HOST' => '10.0.0.7']))['code']);
		$this->assertSame('SCENARIO_INVALID', $this->error($this->request('POST', '/broken/run', ['verb' => 'check'], ['HTTP_HOST' => '10.0.0.7']))['code']);

		$r = $this->request('POST', '/s2/run', ['verb' => 'check'], ['HTTP_HOST' => '10.0.0.7']);
		$this->assertSame(400, $r->getStatus());
		$this->assertSame('UNKNOWN_DATASET', $this->error($r)['code'], 'sada scénáře musí být v katalogu');

		unlink($this->dsDir . '/aaaa-aaaa-aaaa-aaaa/config/main.json');
		$r = $this->request('POST', '/s1/run', ['verb' => 'check'], ['HTTP_HOST' => '10.0.0.7']);
		$this->assertSame(409, $r->getStatus());
		$this->assertSame('VIDEO_DS_MISSING', $this->error($r)['code']);
		$this->assertSame(['dataset' => 'demo-set', 'purpose' => 'videa — demo-set'], $this->error($r)['details']);
		$this->createDs('aaaa-aaaa-aaaa-aaaa', ['dataClass' => 'free', 'purpose' => 'videa — demo-set']);

		$this->ctrl->problems = ['ffmpeg není v PATH'];
		$r = $this->request('POST', '/s1/run', ['verb' => 'check'], ['HTTP_HOST' => '10.0.0.7']);
		$this->assertSame(422, $r->getStatus());
		$this->assertSame('VIDEO_UNAVAILABLE', $this->error($r)['code']);
		$this->assertSame(['ffmpeg není v PATH'], $this->error($r)['details']['problems']);
		$this->ctrl->problems = [];
		$this->ctrl->gotenberg = null;
		$r = $this->request('POST', '/s1/run', ['verb' => 'check'], ['HTTP_HOST' => '10.0.0.7']);
		$this->assertSame('VIDEO_UNAVAILABLE', $this->error($r)['code']);
		$this->ctrl->gotenberg = 'http://10.0.0.1:3000';

		$r = $this->request('POST', '/s1/run', ['verb' => 'check'], []);
		$this->assertSame(400, $r->getStatus(), 'bez hostu není origin');
		$r = $this->request('POST', '/s1/run', ['verb' => 'check'], ['HTTP_HOST' => '203.0.113.5', 'HTTP_ORIGIN' => 'http://203.0.113.5']);
		$this->assertSame(400, $r->getStatus());
		$this->assertStringContainsString('203.0.113.5', $this->error($r)['message']);

		$this->ctrl = new TestableDevVideosController($this->repo, $this->dsDir, 'production', $this->jobs, new JobLauncher($this->jobs, static fn (): bool => true, static fn (): bool => true, ['/usr/bin/php', '/repo/bin/shpd-server']), $this->videosDir);
		$r = $this->request('POST', '/s1/run', ['verb' => 'check'], ['HTTP_HOST' => '10.0.0.7']);
		$this->assertSame(403, $r->getStatus());
		$this->assertSame('FORBIDDEN', $this->error($r)['code']);
		$this->assertSame(403, $this->request('GET', '/s1/file')->getStatus(), 'video jen ve vývoji');
		$this->assertSame([], $this->spawns);
	}

	// ── Zdroj dat pro videa ────────────────────────────────────────────────

	public function testCreateDataSourceRunsDsCreateWithCronAndDsUpgradeWithoutSeeding(): void
	{
		unlink($this->dsDir . '/aaaa-aaaa-aaaa-aaaa/config/main.json');
		$r = $this->request('POST', '/data-source', ['dataset' => 'demo-set'], ['HTTP_HOST' => '10.0.0.7']);
		$this->assertSame(201, $r->getStatus(), json_encode($r->getPayload()));
		$job = $this->data($r)['job'];
		$this->assertSame(JobRecord::TYPE_CREATE, $job['type']);
		$this->assertSame('zzzz-zzzz-zzzz-zzzz', $job['dataSource']['id']);
		$this->assertSame('Videa — Demo — IT servis', $job['dataSource']['name']);
		$this->assertSame('demo-set', $job['dataset']);
		$this->assertSame(['purpose' => 'videa — demo-set', 'videos' => true], $job['params']);
		$this->assertSame(['ds-create zzzz-zzzz-zzzz-zzzz', 'ds-upgrade zzzz-zzzz-zzzz-zzzz'], array_column($job['steps'], 'label'));
		$this->assertSame([
			'/usr/bin/php', '/repo/bin/shpd-server', 'ds-create',
			'--name', 'Videa — Demo — IT servis', '--language', 'cs', '--country', 'cz', '--module', 'install.base',
			'--ds-id', 'zzzz-zzzz-zzzz-zzzz', '--purpose', 'videa — demo-set',
		], $job['steps'][0]['argv']);
		$this->assertSame($this->repo, $job['steps'][0]['cwd']);
		$this->assertSame(['/usr/bin/php', '/repo/bin/shpd-ds', 'ds-upgrade'], $job['steps'][1]['argv']);
		$this->assertSame($this->dsDir . '/zzzz-zzzz-zzzz-zzzz', $job['steps'][1]['cwd']);

		$this->createDs('aaaa-aaaa-aaaa-aaaa', ['dataClass' => 'free', 'purpose' => 'videa — demo-set']);
		$r = $this->request('POST', '/data-source', ['dataset' => 'demo-set'], ['HTTP_HOST' => '10.0.0.7']);
		$this->assertSame(409, $r->getStatus());
		$this->assertSame('VIDEO_DS_EXISTS', $this->error($r)['code']);
		$this->assertSame('aaaa-aaaa-aaaa-aaaa', $this->error($r)['details']['dataSource']['id']);

		$this->assertSame('UNKNOWN_DATASET', $this->error($this->request('POST', '/data-source', ['dataset' => 'other-set'], ['HTTP_HOST' => '10.0.0.7']))['code']);
		$this->assertSame('UNKNOWN_DATASET', $this->error($this->request('POST', '/data-source', [], ['HTTP_HOST' => '10.0.0.7']))['code']);
	}

	// ── Video ──────────────────────────────────────────────────────────────

	public function testFileServesOnlyTheVideoNamedByMetaWithRanges(): void
	{
		$this->assertSame(404, $this->request('GET', '/s1/file')->getStatus(), 'bez meta');
		$store = $this->storeVideo();

		$r = $this->request('GET', '/s1/file');
		$this->assertSame(200, $r->getStatus());
		$this->assertSame($store->videoPath('s1'), $r->getPayload());
		$this->assertSame(['Content-Type' => 'video/mp4', 'Accept-Ranges' => 'bytes', 'Content-Length' => '1000', 'Cache-Control' => 'no-store'], $r->getHeaders());

		$r = $this->request('GET', '/s1/file', [], ['HTTP_RANGE' => 'bytes=200-299'], ['download' => '1']);
		$this->assertSame(206, $r->getStatus());
		$this->assertSame('bytes 200-299/1000', $r->getHeaders()['Content-Range']);
		$this->assertSame('100', $r->getHeaders()['Content-Length']);
		$this->assertSame('attachment; filename="s1.mp4"', $r->getHeaders()['Content-Disposition']);

		$r = $this->request('GET', '/s1/file', [], ['HTTP_RANGE' => 'bytes=5000-']);
		$this->assertSame(416, $r->getStatus());
		$this->assertSame('bytes */1000', $r->getHeaders()['Content-Range']);

		$this->assertSame(404, $this->request('GET', '/../s1/file')->getStatus());
		$this->assertSame(404, $this->request('GET', '/S1/file')->getStatus());
		file_put_contents($store->metaPath('s1'), json_encode(['file' => 'other.mp4']));
		$this->assertSame(404, $this->request('GET', '/s1/file')->getStatus(), 'jen soubor, který meta jmenuje');
	}

	public function testUnknownRoutesAndMissingScenariosDirectory(): void
	{
		$this->assertSame(404, $this->request('GET', '/s1')->getStatus());
		$this->assertSame(404, $this->request('DELETE', '')->getStatus());
		DatasetReader::removeTree($this->repo . '/demo/scenarios');
		$r = $this->request('GET', '');
		$this->assertSame(404, $r->getStatus());
		$this->assertSame('VIDEOS_UNAVAILABLE', $this->error($r)['code']);
	}
}

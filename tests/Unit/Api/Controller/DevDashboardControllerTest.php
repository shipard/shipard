<?php
declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\Controller\DevDashboardController;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Ai\ServerAiConnection;
use Shipard\Core\DevJobs\JobLauncher;
use Shipard\Core\DevJobs\JobRecord;
use Shipard\Core\DevJobs\JobState;
use Shipard\Core\DevJobs\JobStatus;
use Shipard\Core\DevJobs\JobStep;
use Shipard\Core\DevJobs\JobStore;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Core\Mail\Eval\Browse\EvalCatalog;

class DevDashboardControllerTest extends TestCase
{
	private string $tmpRoot;
	private string $tmpDir;
	private string $modulesDir;
	private string $spaIndex;
	/** Úlohy na pozadí nad temp adresářem — seznam zdrojů dat nesmí číst skutečný /opt/shipard/run/dev-jobs. */
	private JobStore $jobStore;
	private DevDashboardController $ctrl;
	/** @var list<string> */
	private array $tmpFiles = [];
	/** @var list<array{argv: list<string>, cwd: string}> spawny spouštěče úloh (smazání zdroje dat, D70) */
	private array $spawns = [];

	/** Obsah „sestaveného“ indexu SPA — controller ho vrací beze změny. */
	private const SPA_INDEX = "<!doctype html>\n<html lang=\"cs\"><head><title>Shipard dev SPA</title></head><body><div id=\"app\"></div></body></html>\n";

	protected function setUp(): void
	{
		$this->tmpRoot   = sys_get_temp_dir() . '/shpd-dev-test-' . uniqid();
		$this->tmpDir    = $this->tmpRoot . '/data-sources';
		$this->modulesDir = $this->tmpRoot . '/modules';
		mkdir($this->tmpDir, 0700, true);
		mkdir($this->modulesDir . '/install/base', 0700, true);
		file_put_contents(
			$this->modulesDir . '/install/base/module.jsonc',
			(string) json_encode(['id' => 'install.base', 'name' => 'Base']),
		);
		$this->spaIndex = $this->tmpRoot . '/public/_dev/dev.html';
		mkdir(dirname($this->spaIndex), 0700, true);
		file_put_contents($this->spaIndex, self::SPA_INDEX);
		$this->jobStore = new JobStore($this->tmpRoot . '/jobs', static fn (): bool => true);
		$this->ctrl = $this->makeController(null);
		// Seznam zdrojů dat zjišťuje serverové AI připojení — testy ho ze stroje nečtou (#126 D9).
		ServerAiConnection::overrideForTesting(null);
	}

	/** Controller nad temp adresáři: zdroje dat, moduly, index SPA a „repo root“ (bez tests/). */
	private function makeController(?string $logPath): DevDashboardController
	{
		return new DevDashboardController(
			$this->tmpDir,
			$logPath,
			new ModulePathResolver([$this->modulesDir]),
			$this->spaIndex,
			$this->tmpRoot,
			jobStore: $this->jobStore,
		);
	}

	protected function tearDown(): void
	{
		ServerAiConnection::resetForTesting();
		$this->rrmdir($this->tmpRoot);
		foreach ($this->tmpFiles as $f) {
			if (file_exists($f)) unlink($f);
		}
	}

	private function createInstallModule(string $name): void
	{
		$dir = $this->modulesDir . '/install/' . $name;
		mkdir($dir, 0700, true);
		file_put_contents(
			$dir . '/module.jsonc',
			(string) json_encode(['id' => 'install.' . $name, 'name' => 'Module ' . $name]),
		);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function rrmdir(string $dir): void
	{
		if (is_link($dir)) {
			unlink($dir); // symlink na tests/datasets repa — nikdy do něj nesestupovat
			return;
		}
		if (!is_dir($dir)) {
			return;
		}
		$entries = scandir($dir) ?: [];
		foreach ($entries as $entry) {
			if ($entry === '.' || $entry === '..') continue;
			$path = $dir . '/' . $entry;
			is_dir($path) && !is_link($path) ? $this->rrmdir($path) : unlink($path);
		}
		rmdir($dir);
	}

	/**
	 * Katalog sad v „repo rootu“ testu: `tests/datasets` jen ke čtení přes
	 * symlink na checkout, `tests/eval` prázdný (vzor DevEvalControllerTest).
	 */
	private function enableDatasets(): void
	{
		mkdir($this->tmpRoot . '/tests/eval', 0700, true);
		symlink(dirname(__DIR__, 4) . '/tests/datasets', $this->tmpRoot . '/tests/datasets');
	}

	/** @param array<string, mixed> $extra další klíče main.json (třída dat, účel, …) */
	private function createDs(string $id, string $name, ?string $created = null, array $extra = []): void
	{
		$dir = $this->tmpDir . '/' . $id . '/config';
		mkdir($dir, 0700, true);
		$cfg = [
			'id'            => $id,
			'name'          => $name,
			'database_name' => str_replace('-', '_', $id),
		] + $extra;
		if ($created !== null) {
			$cfg['created'] = $created;
		}
		file_put_contents($dir . '/main.json', json_encode($cfg, JSON_UNESCAPED_UNICODE));
	}

	private function makeRequest(string $method, string $uri): Request
	{
		$queryParams = [];
		$queryString = parse_url($uri, PHP_URL_QUERY);
		if (is_string($queryString) && $queryString !== '') {
			parse_str($queryString, $queryParams);
		}
		return Request::fromArray($method, $uri, $queryParams, '', []);
	}

	private function getStatus(Response $r): int
	{
		$prop = (new \ReflectionClass($r))->getProperty('status');
		return (int) $prop->getValue($r);
	}

	private function getHeaders(Response $r): array
	{
		$prop = (new \ReflectionClass($r))->getProperty('headers');
		return (array) $prop->getValue($r);
	}

	private function getPayloadRaw(Response $r): mixed
	{
		$prop = (new \ReflectionClass($r))->getProperty('payload');
		return $prop->getValue($r);
	}

	// -------------------------------------------------------------------------
	// Tests
	// -------------------------------------------------------------------------

	public function testRootPathRedirects(): void
	{
		$resp = $this->ctrl->dispatch($this->makeRequest('GET', '/'));
		$this->assertSame(302, $this->getStatus($resp));
		$this->assertSame('/_dev/', $this->getHeaders($resp)['Location'] ?? null);
	}

	// -------------------------------------------------------------------------
	// SPA (#103 D57): stránky vrací sestavený index, API zůstává
	// -------------------------------------------------------------------------

	/** @return list<array{string}> */
	public static function spaPaths(): array
	{
		return [
			['/_dev'], ['/_dev/'],
			['/_dev/logs'], ['/_dev/logs/'], ['/_dev/logs/?ds=abcd-1234-efgh-5678'],
			['/_dev/ds-create'], ['/_dev/ds-create/?name=Demo'],
			['/_dev/doctor'], ['/_dev/doctor/'],
			['/_dev/upgrade-all'], ['/_dev/upgrade-all/'],
			// validaci ?ds= dělá klient a POST endpoint — stránka je vždy SPA
			['/_dev/ds-upgrade/?ds=abcd-1234-efgh-5678'], ['/_dev/ds-upgrade/?ds=bad!id'], ['/_dev/ds-upgrade'],
			['/_dev/testing'], ['/_dev/testing/'], ['/_dev/testing/cz-vat-payer/runs/2026-10-16-baseline.json/'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('spaPaths')]
	public function testSpaPagesServeBuiltIndex(string $uri): void
	{
		$resp = $this->ctrl->dispatch($this->makeRequest('GET', $uri));
		$this->assertSame(200, $this->getStatus($resp), $uri);
		$this->assertSame(self::SPA_INDEX, (string) $this->getPayloadRaw($resp), $uri);
	}

	public function testSpaWithoutBuildReturns503WithInstructions(): void
	{
		unlink($this->spaIndex);
		$resp = $this->ctrl->dispatch($this->makeRequest('GET', '/_dev/'));
		$this->assertSame(503, $this->getStatus($resp));
		$body = (string) $this->getPayloadRaw($resp);
		$this->assertStringContainsString('není sestavený', $body);
		$this->assertStringContainsString('scripts/dev-update.sh', $body);
	}

	/** @return list<array{string}> */
	public static function nonSpaPaths(): array
	{
		return [
			['/_dev/random'], ['/_dev/logs/extra'], ['/_dev/doctor/x/'], ['/_dev/ds-upgrade/abcd'],
			['/_dev/api'], ['/_dev/api/'], ['/_dev/api/unknown'], ['/_devil/'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('nonSpaPaths')]
	public function testUnknownPathReturns404(string $uri): void
	{
		$resp = $this->ctrl->dispatch($this->makeRequest('GET', $uri));
		$this->assertSame(404, $this->getStatus($resp), $uri);
		$this->assertSame('NOT_FOUND', $this->getPayloadRaw($resp)['error']['code'] ?? null, $uri);
	}

	public function testApiInfoReturnsHostnameDirAndTestingFlag(): void
	{
		$resp = $this->ctrl->dispatch($this->makeRequest('GET', '/_dev/api/info'));
		$this->assertSame(200, $this->getStatus($resp));
		$data = $this->getPayloadRaw($resp)['data'];
		$this->assertSame(gethostname() ?: 'unknown', $data['hostname']);
		$this->assertSame($this->tmpDir, $data['dataSourcesDir']);
		$this->assertFalse($data['testing'], 'repo root bez tests/datasets a tests/eval → sekce Testování se nenabízí');
	}

	public function testApiInfoTestingAvailableWithDatasetsAndEvalDirs(): void
	{
		mkdir($this->tmpRoot . '/tests/datasets', 0700, true);
		mkdir($this->tmpRoot . '/tests/eval', 0700, true);
		$resp = $this->ctrl->dispatch($this->makeRequest('GET', '/_dev/api/info'));
		$this->assertTrue($this->getPayloadRaw($resp)['data']['testing']);
	}

	public function testApiInfoPostReturns404(): void
	{
		$resp = $this->ctrl->dispatch($this->makeRequest('POST', '/_dev/api/info'));
		$this->assertSame(404, $this->getStatus($resp));
	}

	public function testListDataSourcesReturnsSorted(): void
	{
		$this->createDs('cccc-cccc-cccc-cccc', 'Charlie');
		$this->createDs('aaaa-aaaa-aaaa-aaaa', 'Alpha');
		$this->createDs('bbbb-bbbb-bbbb-bbbb', 'Bravo');

		$resp = $this->ctrl->dispatch($this->makeRequest('GET', '/_dev/api/data-sources'));
		$this->assertSame(200, $this->getStatus($resp));

		$payload = $this->getPayloadRaw($resp);
		$this->assertTrue($payload['success']);
		$names = array_column($payload['data'], 'name');
		$this->assertSame(['Alpha', 'Bravo', 'Charlie'], $names);
	}

	public function testListDataSourcesIncludesCronSwitchAndState(): void
	{
		// #115 D11: jen zobrazení — přepínač a efektivní stav z config/state.json.
		$this->createDs('aaaa-aaaa-aaaa-aaaa', 'Alpha');
		$this->createDs('bbbb-bbbb-bbbb-bbbb', 'Bravo');
		file_put_contents($this->tmpDir . '/bbbb-bbbb-bbbb-bbbb/config/state.json', '{"version":1,"state":"active","cron":false}');
		$this->createDs('cccc-cccc-cccc-cccc', 'Charlie');
		file_put_contents($this->tmpDir . '/cccc-cccc-cccc-cccc/config/state.json',
			'{"version":1,"state":"read_only","maintenance":{"reason":"import","since":"2026-10-01T00:00:00Z"}}');
		$this->createDs('dddd-dddd-dddd-dddd', 'Delta');
		file_put_contents($this->tmpDir . '/dddd-dddd-dddd-dddd/config/state.json', '{broken');

		$resp = $this->ctrl->dispatch($this->makeRequest('GET', '/_dev/api/data-sources'));
		$this->assertSame(200, $this->getStatus($resp));
		$byName = [];
		foreach ($this->getPayloadRaw($resp)['data'] as $item) {
			$byName[$item['name']] = $item;
		}
		$this->assertTrue($byName['Alpha']['cron']);
		$this->assertSame('active', $byName['Alpha']['state']);
		$this->assertFalse($byName['Bravo']['cron']);
		$this->assertSame('active', $byName['Bravo']['state']);
		$this->assertTrue($byName['Charlie']['cron']);
		$this->assertSame('suspended', $byName['Charlie']['state']);
		$this->assertSame('corrupted', $byName['Delta']['state']);
	}

	public function testListDataSourcesIncludesTheDatasetCountryAndRunningJob(): void
	{
		// #103 D66: akce „Nahrát datovou sadu“ potřebuje zemi, drženou sadu a běžící úlohu.
		$this->createDs('aaaa-aaaa-aaaa-aaaa', 'Alpha', null, [
			'dataClass' => 'free', 'country' => 'cz', 'origin' => 'dataset-seed cz-vat-payer',
			'dataset' => ['name' => 'cz-vat-payer', 'hash' => 'h1', 'seededAt' => '2026-10-16T10:00:00Z'],
		]);
		$this->createDs('bbbb-bbbb-bbbb-bbbb', 'Bravo', null, ['dataClass' => 'free', 'country' => 'cz', 'origin' => 'dataset-seed cz-non-payer']);
		$this->createDs('cccc-cccc-cccc-cccc', 'Charlie', null, ['dataClass' => 'free', 'origin' => 'ds-create']);

		$now = new \DateTimeImmutable('2026-10-17T08:00:00Z');
		$this->jobStore->create(new JobRecord(
			JobRecord::newId($now), JobRecord::TYPE_RESEED, 'aaaa-aaaa-aaaa-aaaa', 'Alpha', 'cz-vat-payer', null, [],
			[new JobStep('dataset-seed cz-vat-payer', ['/usr/bin/php', 'shpd-ds', 'dataset-seed'], '/tmp')],
			JobStore::formatTime($now), '10.0.0.5', 60,
		));
		$jobId = $this->jobStore->list()[0]['id'];
		$state = JobState::pending(1);
		$state->pid = 4242;
		$this->jobStore->writeState($jobId, $state);

		$resp = $this->ctrl->dispatch($this->makeRequest('GET', '/_dev/api/data-sources'));
		$this->assertSame(200, $this->getStatus($resp));
		$byName = array_column($this->getPayloadRaw($resp)['data'], null, 'name');

		$alpha = $byName['Alpha'];
		$this->assertSame('cz', $alpha['country']);
		$this->assertSame(['name' => 'cz-vat-payer', 'hash' => 'h1', 'seededAt' => '2026-10-16T10:00:00Z'], $alpha['dataset']);
		$this->assertNull($alpha['legacyDataset']);
		$this->assertSame(['id' => $jobId, 'type' => 'reseed', 'status' => 'running'], $alpha['job']);

		$bravo = $byName['Bravo'];
		$this->assertNull($bravo['dataset']);
		$this->assertSame('cz-non-payer', $bravo['legacyDataset'], 'sada jen z původu (před F6b)');
		$this->assertNull($bravo['job']);

		$charlie = $byName['Charlie'];
		$this->assertNull($charlie['country']);
		$this->assertNull($charlie['dataset']);
		$this->assertNull($charlie['legacyDataset']);
		$this->assertNull($charlie['job']);
	}

	public function testListDataSourcesIncludesTheDataClassAndKeepsTheOldKeys(): void
	{
		// #126 D10: přes sdílený inventář — staré klíče zůstávají, přibývá třída, účel, původ a serverové AI.
		$this->createDs('aaaa-aaaa-aaaa-aaaa', 'Alpha', '2026-10-01T10:00:00+02:00', [
			'dataClass' => 'free', 'purpose' => 'Ruční zkoušení UI',
			'origin' => 'dataset-seed cz-vat-payer', 'dataClassChanged' => '2026-10-16T12:00:00Z',
			'database_password' => 'S3cret!Pw',
		]);
		$this->createDs('bbbb-bbbb-bbbb-bbbb', 'Bravo');

		$resp = $this->ctrl->dispatch($this->makeRequest('GET', '/_dev/api/data-sources'));
		$this->assertSame(200, $this->getStatus($resp));
		$raw = json_encode($resp->getPayload());
		$this->assertStringNotContainsString('S3cret!Pw', (string) $raw);
		$this->assertStringNotContainsString('database_password', (string) $raw);

		$byName = [];
		foreach ($this->getPayloadRaw($resp)['data'] as $item) {
			$byName[$item['name']] = $item;
		}
		$alpha = $byName['Alpha'];
		$this->assertSame(
			['id', 'name', 'created', 'database_name', 'cron', 'state', 'dataClass', 'dataClassExplicit', 'purpose', 'origin', 'dataClassChanged', 'serverAi', 'country', 'dataset', 'legacyDataset', 'job'],
			array_keys($alpha),
		);
		$this->assertSame('aaaa-aaaa-aaaa-aaaa', $alpha['id']);
		$this->assertSame('2026-10-01T10:00:00+02:00', $alpha['created']);
		$this->assertSame('aaaa_aaaa_aaaa_aaaa', $alpha['database_name']);
		$this->assertTrue($alpha['cron']);
		$this->assertSame('active', $alpha['state']);
		$this->assertSame('free', $alpha['dataClass']);
		$this->assertTrue($alpha['dataClassExplicit']);
		$this->assertSame('Ruční zkoušení UI', $alpha['purpose']);
		$this->assertSame('dataset-seed cz-vat-payer', $alpha['origin']);
		$this->assertSame('2026-10-16T12:00:00Z', $alpha['dataClassChanged']);
		$this->assertFalse($alpha['serverAi'], 'server žádné připojení nenabízí (overrideForTesting(null))');

		$bravo = $byName['Bravo'];
		$this->assertSame('realCopy', $bravo['dataClass']);
		$this->assertFalse($bravo['dataClassExplicit']);
		$this->assertNull($bravo['purpose']);
		$this->assertNull($bravo['origin']);
		$this->assertFalse($bravo['serverAi']);
	}

	public function testListDataSourcesServerAiFollowsTheServerAndTheClass(): void
	{
		ServerAiConnection::overrideForTesting(new ServerAiConnection('anthropic', null, $this->tmpRoot));
		$this->createDs('aaaa-aaaa-aaaa-aaaa', 'Alpha', null, ['dataClass' => 'free']);
		$this->createDs('bbbb-bbbb-bbbb-bbbb', 'Bravo', null, ['dataClass' => 'realCopy']);
		$this->createDs('cccc-cccc-cccc-cccc', 'Charlie');

		$resp = $this->ctrl->dispatch($this->makeRequest('GET', '/_dev/api/data-sources'));
		$byName = [];
		foreach ($this->getPayloadRaw($resp)['data'] as $item) {
			$byName[$item['name']] = $item['serverAi'];
		}
		$this->assertSame(['Alpha' => true, 'Bravo' => false, 'Charlie' => false], $byName);
	}

	public function testListDataSourcesSkipsDirsWithoutMainJson(): void
	{
		$this->createDs('aaaa-aaaa-aaaa-aaaa', 'Alpha');
		mkdir($this->tmpDir . '/lost+found', 0700, true);

		$resp    = $this->ctrl->dispatch($this->makeRequest('GET', '/_dev/api/data-sources'));
		$payload = $this->getPayloadRaw($resp);
		$this->assertCount(1, $payload['data']);
		$this->assertSame('Alpha', $payload['data'][0]['name']);
	}

	public function testListDataSourcesSkipsCorruptJson(): void
	{
		$dir = $this->tmpDir . '/corrupt-ds-id/config';
		mkdir($dir, 0700, true);
		file_put_contents($dir . '/main.json', '{ this is not json');

		$resp    = $this->ctrl->dispatch($this->makeRequest('GET', '/_dev/api/data-sources'));
		$payload = $this->getPayloadRaw($resp);
		$this->assertSame(200, $this->getStatus($resp));
		$this->assertSame([], $payload['data']);
	}

	public function testListDataSourcesEmpty(): void
	{
		$resp    = $this->ctrl->dispatch($this->makeRequest('GET', '/_dev/api/data-sources'));
		$payload = $this->getPayloadRaw($resp);
		$this->assertSame(200, $this->getStatus($resp));
		$this->assertSame([], $payload['data']);
	}

	public function testApiDataSourcesPostReturns404(): void
	{
		$resp = $this->ctrl->dispatch($this->makeRequest('POST', '/_dev/api/data-sources'));
		$this->assertSame(404, $this->getStatus($resp));
	}

	// -------------------------------------------------------------------------
	// Logs page / API
	// -------------------------------------------------------------------------

	private function makeLogFile(array $lines): string
	{
		$path = tempnam(sys_get_temp_dir(), 'shpd-log-');
		if ($path === false) {
			$this->fail('Could not create temp log file');
		}
		$this->tmpFiles[] = $path;
		file_put_contents($path, implode("\n", $lines) . "\n");
		return $path;
	}

	private function ctrlWithLog(string $logPath): DevDashboardController
	{
		return $this->makeController($logPath);
	}

	private function jsonLine(array $data): string
	{
		return (string) json_encode($data);
	}

	public function testApiLogsWithoutLogPathReturns503(): void
	{
		// $this->ctrl was constructed without log path
		$resp = $this->ctrl->dispatch($this->makeRequest('GET', '/_dev/api/logs'));
		$this->assertSame(503, $this->getStatus($resp));
		$payload = $this->getPayloadRaw($resp);
		$this->assertFalse($payload['success']);
		$this->assertSame('LOG_NOT_CONFIGURED', $payload['error']['code']);
	}

	public function testApiLogsReturnsParsedEntries(): void
	{
		$logPath = $this->makeLogFile([
			$this->jsonLine(['ts' => '2026-05-07T12:00:00+00:00', 'level' => 'info', 'msg' => 'one']),
			$this->jsonLine(['ts' => '2026-05-07T12:00:01+00:00', 'level' => 'warn', 'msg' => 'two']),
			$this->jsonLine(['ts' => '2026-05-07T12:00:02+00:00', 'level' => 'error', 'msg' => 'three']),
		]);
		$ctrl = $this->ctrlWithLog($logPath);
		$resp = $ctrl->dispatch($this->makeRequest('GET', '/_dev/api/logs'));
		$this->assertSame(200, $this->getStatus($resp));

		$payload = $this->getPayloadRaw($resp);
		$this->assertTrue($payload['success']);
		$this->assertCount(3, $payload['data']['entries']);
		$this->assertTrue($payload['data']['available']);
		$this->assertSame('one', $payload['data']['entries'][0]['msg']);
		$this->assertSame('three', $payload['data']['entries'][2]['msg']);
	}

	public function testApiLogsSkipsInvalidJsonLines(): void
	{
		$logPath = $this->makeLogFile([
			$this->jsonLine(['ts' => '2026-05-07T12:00:00+00:00', 'level' => 'info', 'msg' => 'good']),
			'this is not { valid json',
			$this->jsonLine(['ts' => '2026-05-07T12:00:02+00:00', 'level' => 'error', 'msg' => 'good2']),
		]);
		$ctrl = $this->ctrlWithLog($logPath);
		$resp = $ctrl->dispatch($this->makeRequest('GET', '/_dev/api/logs'));
		$payload = $this->getPayloadRaw($resp);
		$this->assertCount(2, $payload['data']['entries']);
		$msgs = array_column($payload['data']['entries'], 'msg');
		$this->assertSame(['good', 'good2'], $msgs);
	}

	public function testApiLogsSkipsLinesMissingRequiredFields(): void
	{
		$logPath = $this->makeLogFile([
			$this->jsonLine(['ts' => '2026-05-07T12:00:00+00:00', 'level' => 'info', 'msg' => 'ok']),
			$this->jsonLine(['level' => 'info', 'msg' => 'no-ts']),
			$this->jsonLine(['ts' => '2026-05-07T12:00:02+00:00', 'msg' => 'no-level']),
			$this->jsonLine(['ts' => '2026-05-07T12:00:03+00:00', 'level' => 'warn', 'msg' => 'ok2']),
		]);
		$ctrl = $this->ctrlWithLog($logPath);
		$resp = $ctrl->dispatch($this->makeRequest('GET', '/_dev/api/logs'));
		$payload = $this->getPayloadRaw($resp);
		$msgs = array_column($payload['data']['entries'], 'msg');
		$this->assertSame(['ok', 'ok2'], $msgs);
	}

	public function testApiLogsRespectsLimit(): void
	{
		$lines = [];
		for ($i = 1; $i <= 50; $i++) {
			$lines[] = $this->jsonLine([
				'ts'    => sprintf('2026-05-07T12:00:%02d+00:00', $i % 60),
				'level' => 'info',
				'msg'   => 'm' . $i,
			]);
		}
		$logPath = $this->makeLogFile($lines);
		$ctrl    = $this->ctrlWithLog($logPath);

		$resp    = $ctrl->dispatch(Request::fromArray('GET', '/_dev/api/logs?limit=10', ['limit' => '10'], '', []));
		$payload = $this->getPayloadRaw($resp);
		$this->assertCount(10, $payload['data']['entries']);
		$this->assertSame(10, $payload['data']['limit']);
		// Last 10 of 50 should be m41..m50
		$this->assertSame('m41', $payload['data']['entries'][0]['msg']);
		$this->assertSame('m50', $payload['data']['entries'][9]['msg']);
	}

	public function testApiLogsCapsLimitAt2000(): void
	{
		$logPath = $this->makeLogFile([
			$this->jsonLine(['ts' => '2026-05-07T12:00:00+00:00', 'level' => 'info', 'msg' => 'x']),
		]);
		$ctrl = $this->ctrlWithLog($logPath);
		$resp = $ctrl->dispatch(Request::fromArray('GET', '/_dev/api/logs?limit=99999', ['limit' => '99999'], '', []));
		$payload = $this->getPayloadRaw($resp);
		$this->assertSame(2000, $payload['data']['limit']);
	}

	public function testApiLogsClampsLimitAtMin(): void
	{
		$logPath = $this->makeLogFile([
			$this->jsonLine(['ts' => '2026-05-07T12:00:00+00:00', 'level' => 'info', 'msg' => 'x']),
		]);
		$ctrl = $this->ctrlWithLog($logPath);
		$resp = $ctrl->dispatch(Request::fromArray('GET', '/_dev/api/logs?limit=0', ['limit' => '0'], '', []));
		$payload = $this->getPayloadRaw($resp);
		$this->assertSame(1, $payload['data']['limit']);
	}

	public function testApiLogsHandlesMissingLogFile(): void
	{
		$ctrl = $this->ctrlWithLog('/tmp/shpd-nonexistent-log-' . uniqid());
		$resp = $ctrl->dispatch($this->makeRequest('GET', '/_dev/api/logs'));
		$this->assertSame(200, $this->getStatus($resp));

		$payload = $this->getPayloadRaw($resp);
		$this->assertTrue($payload['success']);
		$this->assertFalse($payload['data']['available']);
		$this->assertSame([], $payload['data']['entries']);
	}

	// -------------------------------------------------------------------------
	// ds-create page / validation
	// -------------------------------------------------------------------------

	private function makeJsonRequest(string $method, string $uri, array $body): Request
	{
		$rawBody = json_encode($body) ?: '';
		return Request::fromArray($method, $uri, [], $rawBody, []);
	}

	public function testDsCreateValidationEmptyName(): void
	{
		$resp = $this->ctrl->dispatch($this->makeJsonRequest('POST', '/_dev/api/ds-create', [
			'name'     => '',
			'login'    => 'admin',
			'password' => 'admin',
		]));
		$this->assertSame(400, $this->getStatus($resp));
		$payload = $this->getPayloadRaw($resp);
		$this->assertFalse($payload['success']);
		$this->assertSame('VALIDATION', $payload['error']['code']);
		$this->assertStringContainsString('Name is required', $payload['error']['message']);
	}

	public function testDsCreateValidationInvalidLogin(): void
	{
		$resp = $this->ctrl->dispatch($this->makeJsonRequest('POST', '/_dev/api/ds-create', [
			'name'     => 'Test DS',
			'login'    => 'bad-login!',
			'password' => 'admin',
		]));
		$this->assertSame(400, $this->getStatus($resp));
		$payload = $this->getPayloadRaw($resp);
		$this->assertStringContainsString('letters, digits, and underscores', $payload['error']['message']);
	}

	public function testDsCreateValidationShortPassword(): void
	{
		$resp = $this->ctrl->dispatch($this->makeJsonRequest('POST', '/_dev/api/ds-create', [
			'name'     => 'Test DS',
			'login'    => 'admin',
			'password' => 'ab',
		]));
		$this->assertSame(400, $this->getStatus($resp));
		$payload = $this->getPayloadRaw($resp);
		$this->assertStringContainsString('at least 4 characters', $payload['error']['message']);
	}

	public function testDsCreateValidationMultipleErrors(): void
	{
		$resp = $this->ctrl->dispatch($this->makeJsonRequest('POST', '/_dev/api/ds-create', []));
		$this->assertSame(400, $this->getStatus($resp));
		$payload = $this->getPayloadRaw($resp);
		$msg = $payload['error']['message'];
		$this->assertStringContainsString('Name is required', $msg);
		$this->assertStringContainsString('Admin login is required', $msg);
		$this->assertStringContainsString('Admin password is required', $msg);
		$this->assertStringContainsString('Language is required', $msg);
		$this->assertStringContainsString('Country is required', $msg);
	}

	public function testDsCreateValidationMissingLanguage(): void
	{
		$resp = $this->ctrl->dispatch($this->makeJsonRequest('POST', '/_dev/api/ds-create', [
			'name'     => 'Test DS',
			'login'    => 'admin',
			'password' => 'admin',
			'country'  => 'cz',
		]));
		$this->assertSame(400, $this->getStatus($resp));
		$payload = $this->getPayloadRaw($resp);
		$this->assertStringContainsString('Language is required', $payload['error']['message']);
	}

	public function testDsCreateValidationInvalidLanguage(): void
	{
		$resp = $this->ctrl->dispatch($this->makeJsonRequest('POST', '/_dev/api/ds-create', [
			'name'     => 'Test DS',
			'login'    => 'admin',
			'password' => 'admin',
			'language' => 'de',
			'country'  => 'cz',
		]));
		$this->assertSame(400, $this->getStatus($resp));
		$payload = $this->getPayloadRaw($resp);
		$this->assertStringContainsString('Language is required', $payload['error']['message']);
	}

	public function testDsCreateValidationMissingCountry(): void
	{
		$resp = $this->ctrl->dispatch($this->makeJsonRequest('POST', '/_dev/api/ds-create', [
			'name'     => 'Test DS',
			'login'    => 'admin',
			'password' => 'admin',
			'language' => 'cs',
		]));
		$this->assertSame(400, $this->getStatus($resp));
		$payload = $this->getPayloadRaw($resp);
		$this->assertStringContainsString('Country is required', $payload['error']['message']);
	}

	public function testDsCreateValidationInvalidCountry(): void
	{
		$resp = $this->ctrl->dispatch($this->makeJsonRequest('POST', '/_dev/api/ds-create', [
			'name'     => 'Test DS',
			'login'    => 'admin',
			'password' => 'admin',
			'language' => 'cs',
			'country'  => 'CZE',
		]));
		$this->assertSame(400, $this->getStatus($resp));
		$payload = $this->getPayloadRaw($resp);
		$this->assertStringContainsString('Country is required', $payload['error']['message']);
	}

	public function testDsCreateGetReturns404(): void
	{
		$resp = $this->ctrl->dispatch($this->makeRequest('GET', '/_dev/api/ds-create'));
		$this->assertSame(404, $this->getStatus($resp));
	}

	// -------------------------------------------------------------------------
	// Install modules endpoint
	// -------------------------------------------------------------------------

	public function testInstallModulesEndpointReturnsList(): void
	{
		$this->createInstallModule('foo');
		// Resolver eagerly scans roots in its constructor — rebuild the controller
		// so the freshly created install.foo module is discovered.
		$ctrl = new DevDashboardController($this->tmpDir, null, new ModulePathResolver([$this->modulesDir]));

		$resp = $ctrl->dispatch($this->makeRequest('GET', '/_dev/api/install-modules'));
		$this->assertSame(200, $this->getStatus($resp));

		$payload = $this->getPayloadRaw($resp);
		$this->assertTrue($payload['success']);
		$this->assertCount(2, $payload['data']);

		$ids = array_column($payload['data'], 'id');
		$this->assertContains('install.base', $ids);
		$this->assertContains('install.foo', $ids);
	}

	public function testInstallModulesEndpointEmpty(): void
	{
		// Wipe the install dir
		unlink($this->modulesDir . '/install/base/module.jsonc');
		rmdir($this->modulesDir . '/install/base');

		$resp = $this->ctrl->dispatch($this->makeRequest('GET', '/_dev/api/install-modules'));
		$this->assertSame(200, $this->getStatus($resp));

		$payload = $this->getPayloadRaw($resp);
		$this->assertTrue($payload['success']);
		$this->assertSame([], $payload['data']);
	}

	public function testInstallModulesWithoutConfigReturns503(): void
	{
		$ctrl = new DevDashboardController($this->tmpDir, null, null);
		$resp = $ctrl->dispatch($this->makeRequest('GET', '/_dev/api/install-modules'));

		$this->assertSame(503, $this->getStatus($resp));
		$payload = $this->getPayloadRaw($resp);
		$this->assertFalse($payload['success']);
		$this->assertSame('MODULES_NOT_CONFIGURED', $payload['error']['code']);
	}

	public function testInstallModulesEndpointPostReturns404(): void
	{
		$resp = $this->ctrl->dispatch($this->makeRequest('POST', '/_dev/api/install-modules'));
		$this->assertSame(404, $this->getStatus($resp));
	}

	public function testDsCreateValidationInvalidModuleFormat(): void
	{
		$resp = $this->ctrl->dispatch($this->makeJsonRequest('POST', '/_dev/api/ds-create', [
			'name'     => 'Test DS',
			'login'    => 'admin',
			'password' => 'admin',
			'module'   => 'bad-id',
		]));
		$this->assertSame(400, $this->getStatus($resp));
		$payload = $this->getPayloadRaw($resp);
		$this->assertStringContainsString('Invalid install module id', $payload['error']['message']);
	}

	public function testDsCreateValidationNonExistentModule(): void
	{
		$resp = $this->ctrl->dispatch($this->makeJsonRequest('POST', '/_dev/api/ds-create', [
			'name'     => 'Test DS',
			'login'    => 'admin',
			'password' => 'admin',
			'module'   => 'install.zzz',
		]));
		$this->assertSame(400, $this->getStatus($resp));
		$payload = $this->getPayloadRaw($resp);
		$this->assertStringContainsString('not found', $payload['error']['message']);
	}

	public function testDsCreatePipelinePassesModuleFlag(): void
	{
		$ctrl = $this->makeTestableCtrl();
		$ctrl->commandResults = [
			[0, "  Directory:     /tmp/test/abc123\n"],
			[0, ""],
			[0, ""],
		];

		$resp = $ctrl->dispatch($this->makeJsonRequest('POST', '/_dev/api/ds-create', [
			'name'     => 'Test',
			'login'    => 'admin',
			'password' => 'admin',
			'language' => 'cs',
			'country'  => 'cz',
		]));

		$this->streamToString($resp);
		$this->assertStringContainsString('--module=', $ctrl->commandsRun[0]);
		$this->assertStringContainsString('install.base', $ctrl->commandsRun[0]);
	}

	public function testDsCreatePipelinePassesExplicitModuleFlag(): void
	{
		$this->createInstallModule('foo');
		$ctrl = $this->makeTestableCtrl();
		$ctrl->commandResults = [
			[0, "  Directory:     /tmp/test/abc123\n"],
			[0, ""],
			[0, ""],
		];

		$resp = $ctrl->dispatch($this->makeJsonRequest('POST', '/_dev/api/ds-create', [
			'name'     => 'Test',
			'login'    => 'admin',
			'password' => 'admin',
			'language' => 'cs',
			'country'  => 'cz',
			'module'   => 'install.foo',
		]));

		$this->streamToString($resp);
		$this->assertStringContainsString('install.foo', $ctrl->commandsRun[0]);
	}

	public function testDsCreateValidationRejectsAMultilineOrLongPurpose(): void
	{
		// #126 D6: stejná validace jako ds-create --purpose (MainConfigPatcher::normalizePurpose).
		foreach (["první\ndruhý", str_repeat('x', 201)] as $purpose) {
			$resp = $this->ctrl->dispatch($this->makeJsonRequest('POST', '/_dev/api/ds-create', [
				'name'     => 'Test',
				'login'    => 'admin',
				'password' => 'admin',
				'language' => 'cs',
				'country'  => 'cz',
				'purpose'  => $purpose,
			]));
			$this->assertSame(400, $this->getStatus($resp));
			$payload = $this->getPayloadRaw($resp);
			$this->assertSame('VALIDATION', $payload['error']['code']);
			$this->assertStringContainsString('Purpose:', $payload['error']['message']);
		}
	}

	public function testDsCreatePipelinePassesPurposeOnlyWhenGiven(): void
	{
		$ctrl = $this->makeTestableCtrl();
		$ctrl->commandResults = [[0, "  Directory:     /tmp/test/abc123\n"], [0, ''], [0, '']];
		$resp = $ctrl->dispatch($this->makeJsonRequest('POST', '/_dev/api/ds-create', [
			'name' => 'Test', 'login' => 'admin', 'password' => 'admin', 'language' => 'cs', 'country' => 'cz',
			'purpose' => '  Ruční zkoušení UI  ',
		]));
		$this->streamToString($resp);
		$this->assertStringContainsString("--purpose='Ruční zkoušení UI'", $ctrl->commandsRun[0], 'účel normalizovaný (trim)');

		$ctrl = $this->makeTestableCtrl();
		$ctrl->commandResults = [[0, "  Directory:     /tmp/test/abc123\n"], [0, ''], [0, '']];
		$resp = $ctrl->dispatch($this->makeJsonRequest('POST', '/_dev/api/ds-create', [
			'name' => 'Test', 'login' => 'admin', 'password' => 'admin', 'language' => 'cs', 'country' => 'cz',
			'purpose' => '   ',
		]));
		$this->streamToString($resp);
		$this->assertStringNotContainsString('--purpose', $ctrl->commandsRun[0], 'prázdný účel se nepředává');
	}

	public function testDsCreatePipelinePassesLanguageAndCountryFlags(): void
	{
		$ctrl = $this->makeTestableCtrl();
		$ctrl->commandResults = [
			[0, "  Directory:     /tmp/test/abc123\n"],
			[0, ""],
			[0, ""],
		];

		$resp = $ctrl->dispatch($this->makeJsonRequest('POST', '/_dev/api/ds-create', [
			'name'     => 'Test',
			'login'    => 'admin',
			'password' => 'admin',
			'language' => 'en',
			'country'  => 'sk',
		]));

		$this->streamToString($resp);
		$this->assertStringContainsString('--language=', $ctrl->commandsRun[0]);
		$this->assertStringContainsString("'en'", $ctrl->commandsRun[0]);
		$this->assertStringContainsString('--country=', $ctrl->commandsRun[0]);
		$this->assertStringContainsString("'sk'", $ctrl->commandsRun[0]);
	}

	// -------------------------------------------------------------------------
	// ds-create pipeline (subprocess mocks via subclassing)
	// -------------------------------------------------------------------------

	private function streamToString(Response $response): string
	{
		$prop = (new \ReflectionClass($response))->getProperty('streamProducer');
		$producer = $prop->getValue($response);
		ob_start();
		$producer();
		return (string) ob_get_clean();
	}

	/** Repo root = temp root testu (bez `tests/`, dokud ho enableDatasets() nedoplní). */
	private function makeTestableCtrl(): TestableDevDashboardController
	{
		return new TestableDevDashboardController($this->tmpDir, null, new ModulePathResolver([$this->modulesDir]), $this->spaIndex, $this->tmpRoot, jobStore: $this->jobStore);
	}

	/** @return array<string, mixed> tělo požadavku ds-create s výchozími platnými poli */
	private function dsCreateBody(array $overrides = []): array
	{
		return $overrides + ['name' => 'Test', 'login' => 'admin', 'password' => 'admin', 'language' => 'cs', 'country' => 'cz'];
	}

	public function testDsCreatePipelineHappyPath(): void
	{
		$ctrl = $this->makeTestableCtrl();
		$ctrl->commandResults = [
			[0, "Some output\n  Directory:     /tmp/test/abc123\n"],
			[0, ""],
			[0, ""],
		];

		$resp = $ctrl->dispatch($this->makeJsonRequest('POST', '/_dev/api/ds-create', [
			'name'     => 'Test',
			'login'    => 'admin',
			'password' => 'admin',
			'language' => 'cs',
			'country'  => 'cz',
			'seed'     => false,
		]));

		$this->assertSame(200, $this->getStatus($resp));
		$out = $this->streamToString($resp);
		$this->assertStringContainsString('[STEP] Creating data source...', $out);
		$this->assertStringContainsString('[STEP] Running ds-upgrade...', $out);
		$this->assertStringContainsString('[STEP] Creating admin user', $out);
		$this->assertStringNotContainsString('Seeding', $out, 'bez sady žádný seed');
		$this->assertStringContainsString('[DONE] {"id":"abc123","url":"/abc123/app/","dataset":null}', $out);
		$this->assertCount(2, $ctrl->commandsRun, 'ds-create + ds-upgrade přes shell');
		$this->assertCount(1, $ctrl->processesRun, 'user-create jako podproces s argv');
	}

	public function testDsCreatePipelineCreatesAdministratorWithThePasswordOnStdin(): void
	{
		// #103 D65: heslo nejde do argv (ps, shell history), jen na stdin user-create --password-stdin.
		$ctrl = $this->makeTestableCtrl();
		$ctrl->commandResults = [
			[0, "  Directory:     /tmp/test/abc123\n"],
			[0, ""],
			[0, "User created successfully.\n"],
		];

		$resp = $ctrl->dispatch($this->makeJsonRequest('POST', '/_dev/api/ds-create', $this->dsCreateBody([
			'login' => 'boss', 'password' => 'Tajne-heslo-123',
		])));

		$out = $this->streamToString($resp);
		$this->assertCount(1, $ctrl->processesRun);
		$process = $ctrl->processesRun[0];
		$this->assertSame('/tmp/test/abc123', $process['cwd']);
		$this->assertSame(
			['user-create', '--login', 'boss', '--password-stdin', '--name', 'boss', '--admin', '--no-ansi'],
			array_slice($process['argv'], 1),
		);
		$this->assertSame("Tajne-heslo-123\n", $process['stdin']);
		$this->assertStringNotContainsString('Tajne-heslo-123', implode("\n", $process['argv']));
		$this->assertStringNotContainsString('Tajne-heslo-123', implode("\n", $ctrl->commandsRun));
		$this->assertStringNotContainsString('Tajne-heslo-123', $out);
		$this->assertStringContainsString('[DONE]', $out);
	}

	public function testDsCreatePipelineCreateFails(): void
	{
		$ctrl = $this->makeTestableCtrl();
		$ctrl->commandResults = [
			[1, "boom\n"],
		];

		$resp = $ctrl->dispatch($this->makeJsonRequest('POST', '/_dev/api/ds-create', [
			'name'     => 'Test',
			'login'    => 'admin',
			'password' => 'admin',
			'language' => 'cs',
			'country'  => 'cz',
		]));

		$out = $this->streamToString($resp);
		$this->assertStringContainsString('[ERROR] ds-create failed', $out);
		$this->assertStringNotContainsString('[DONE]', $out);
		$this->assertCount(1, $ctrl->commandsRun);
	}

	public function testDsCreatePipelineUpgradeFails(): void
	{
		$ctrl = $this->makeTestableCtrl();
		$ctrl->commandResults = [
			[0, "  Directory:     /tmp/test/abc123\n"],
			[1, "upgrade boom\n"],
		];

		$resp = $ctrl->dispatch($this->makeJsonRequest('POST', '/_dev/api/ds-create', [
			'name'     => 'Test',
			'login'    => 'admin',
			'password' => 'admin',
			'language' => 'cs',
			'country'  => 'cz',
		]));

		$out = $this->streamToString($resp);
		$this->assertStringContainsString('ds-upgrade failed', $out);
		$this->assertStringContainsString('abc123', $out);
		$this->assertStringNotContainsString('[DONE]', $out);
		$this->assertCount(2, $ctrl->commandsRun);
	}

	public function testDsCreatePipelineParsesIdFromDirectoryLine(): void
	{
		$ctrl = $this->makeTestableCtrl();
		$ctrl->commandResults = [
			[0, "  Directory:     /opt/shipard/data-sources/zzzz-aaaa-bbbb-cccc\n"],
			[0, ""],
			[0, ""],
		];

		$resp = $ctrl->dispatch($this->makeJsonRequest('POST', '/_dev/api/ds-create', [
			'name'     => 'Test',
			'login'    => 'admin',
			'password' => 'admin',
			'language' => 'cs',
			'country'  => 'cz',
		]));

		$out = $this->streamToString($resp);
		$this->assertStringContainsString('[DONE] {"id":"zzzz-aaaa-bbbb-cccc"', $out);
		$this->assertStringContainsString('"url":"/zzzz-aaaa-bbbb-cccc/app/"', $out);
	}

	public function testDsCreatePipelineFailsOnMissingDirectoryLine(): void
	{
		$ctrl = $this->makeTestableCtrl();
		$ctrl->commandResults = [
			[0, "Some output without the magic line\n"],
		];

		$resp = $ctrl->dispatch($this->makeJsonRequest('POST', '/_dev/api/ds-create', [
			'name'     => 'Test',
			'login'    => 'admin',
			'password' => 'admin',
			'language' => 'cs',
			'country'  => 'cz',
		]));

		$out = $this->streamToString($resp);
		$this->assertStringContainsString('[ERROR] Could not parse', $out);
		$this->assertCount(1, $ctrl->commandsRun);
	}

	// ── Datová sada při zakládání (#103 D64) ─────────────────────────────

	public function testDsCreatePipelineSeedsTheDatasetAfterTheAdminUser(): void
	{
		$this->enableDatasets();
		$datasetDir = (new EvalCatalog($this->tmpRoot))->dataset('cz-non-payer')?->dir;
		$this->assertNotNull($datasetDir);

		$ctrl = $this->makeTestableCtrl();
		$ctrl->commandResults = [
			[0, "  Directory:     /tmp/x/abc123\n"],
			[0, ""],
			[0, ""],
			[0, "Dataset cz-non-payer — …\n"],
		];

		$resp = $ctrl->dispatch($this->makeJsonRequest('POST', '/_dev/api/ds-create', $this->dsCreateBody(['dataset' => 'cz-non-payer'])));
		$this->assertSame(200, $this->getStatus($resp));

		$out = $this->streamToString($resp);
		$this->assertStringContainsString('[STEP] Seeding dataset cz-non-payer...', $out);
		$this->assertStringContainsString('[DONE] {"id":"abc123","url":"/abc123/app/","dataset":"cz-non-payer"}', $out);
		$this->assertCount(3, $ctrl->commandsRun);
		$this->assertCount(1, $ctrl->processesRun, 'user-create běží před seedem — admin reset v dataset-seed přežije');
		$seed = $ctrl->commandsRun[2];
		$this->assertStringStartsWith("cd '/tmp/x/abc123' && ", $seed);
		$this->assertStringContainsString(' dataset-seed ' . escapeshellarg($datasetDir) . ' --yes --no-ansi', $seed, 'adresář sady z katalogu, ne z požadavku');
		$this->assertStringNotContainsString('seed-persons', $out . implode(' ', $ctrl->commandsRun));
	}

	public function testDsCreatePipelineReportsAFailedSeedWithAUsableDataSource(): void
	{
		$this->enableDatasets();
		$ctrl = $this->makeTestableCtrl();
		$ctrl->commandResults = [
			[0, "  Directory:     /tmp/x/abc123\n"],
			[0, ""],
			[0, ""],
			[1, "Dataset preflight failed — nothing was changed:\n"],
		];

		$out = $this->streamToString($ctrl->dispatch($this->makeJsonRequest('POST', '/_dev/api/ds-create', $this->dsCreateBody(['dataset' => 'cz-vat-payer']))));
		$this->assertStringContainsString('[ERROR] dataset-seed failed — DS abc123 and its admin user are usable, but the dataset cz-vat-payer is not loaded.', $out);
		$this->assertStringContainsString('Nahrát datovou sadu', $out);
		$this->assertStringNotContainsString('[DONE]', $out);
	}

	public function testDsCreateValidatesTheDatasetAgainstTheCatalogAndTheCountry(): void
	{
		// Bez checkoutu tests/ se sada nedá použít vůbec.
		$resp = $this->makeTestableCtrl()->dispatch($this->makeJsonRequest('POST', '/_dev/api/ds-create', $this->dsCreateBody(['dataset' => 'cz-vat-payer'])));
		$this->assertSame(400, $this->getStatus($resp));
		$this->assertStringContainsString('Datasets are not available', $this->getPayloadRaw($resp)['error']['message']);

		$this->enableDatasets();
		$resp = $this->makeTestableCtrl()->dispatch($this->makeJsonRequest('POST', '/_dev/api/ds-create', $this->dsCreateBody(['dataset' => '../etc'])));
		$this->assertSame(400, $this->getStatus($resp));
		$this->assertStringContainsString("Unknown dataset '../etc'", $this->getPayloadRaw($resp)['error']['message']);

		$resp = $this->makeTestableCtrl()->dispatch($this->makeJsonRequest('POST', '/_dev/api/ds-create', $this->dsCreateBody(['dataset' => 'cz-vat-payer', 'country' => 'sk'])));
		$this->assertSame(400, $this->getStatus($resp));
		$this->assertStringContainsString('Country must match the dataset (cz)', $this->getPayloadRaw($resp)['error']['message']);

		// Prázdný / chybějící název = bez sady; staré `seed` se ignoruje.
		$ctrl = $this->makeTestableCtrl();
		$ctrl->commandResults = [[0, "  Directory:     /tmp/x/abc123\n"], [0, ''], [0, '']];
		$out = $this->streamToString($ctrl->dispatch($this->makeJsonRequest('POST', '/_dev/api/ds-create', $this->dsCreateBody(['dataset' => '', 'seed' => true]))));
		$this->assertStringContainsString('"dataset":null}', $out);
		$this->assertCount(2, $ctrl->commandsRun);
	}

	public function testDsCreatePasswordRedactedInOutput(): void
	{
		$ctrl = $this->makeTestableCtrl();
		$ctrl->commandResults = [
			[0, "  Directory:     /tmp/x/abc123\n"],
			[0, ""],
			[0, "running with --password=secret123 in args\n"],
		];

		$resp = $ctrl->dispatch($this->makeJsonRequest('POST', '/_dev/api/ds-create', [
			'name'     => 'Test',
			'login'    => 'admin',
			'password' => 'secret123',
			'language' => 'cs',
			'country'  => 'cz',
		]));

		$out = $this->streamToString($resp);
		$this->assertStringContainsString('--password=***', $out);
		$this->assertStringNotContainsString('--password=secret123', $out);
	}

	// -------------------------------------------------------------------------
	// Action endpoints — streaming
	// -------------------------------------------------------------------------

	public function testDoctorEndpointStreamsOutputAndReportsDone(): void
	{
		$ctrl = $this->makeTestableCtrl();
		$ctrl->commandResults = [
			[0, "All checks passed\n"],
		];

		$resp = $ctrl->dispatch($this->makeRequest('POST', '/_dev/api/doctor'));
		$this->assertSame(200, $this->getStatus($resp));

		$out = $this->streamToString($resp);
		$this->assertStringContainsString('[DONE]', $out);
		$this->assertStringContainsString('"message":"Doctor completed without issues"', $out);
		$this->assertCount(1, $ctrl->commandsRun);
		$this->assertStringContainsString('doctor --no-ansi', $ctrl->commandsRun[0]);
	}

	public function testDoctorEndpointReportsErrorOnNonZeroExit(): void
	{
		$ctrl = $this->makeTestableCtrl();
		$ctrl->commandResults = [
			[2, "Issues found\n"],
		];

		$resp = $ctrl->dispatch($this->makeRequest('POST', '/_dev/api/doctor'));

		$out = $this->streamToString($resp);
		$this->assertStringContainsString('[ERROR] Doctor reported issues', $out);
		$this->assertStringContainsString('exit 2', $out);
		$this->assertStringNotContainsString('[DONE]', $out);
	}

	public function testUpgradeAllEndpointSpawnsCorrectCommand(): void
	{
		$ctrl = $this->makeTestableCtrl();
		$ctrl->commandResults = [
			[0, "Upgraded 3 data sources\n"],
		];

		$resp = $ctrl->dispatch($this->makeRequest('POST', '/_dev/api/upgrade-all'));
		$this->assertSame(200, $this->getStatus($resp));

		$out = $this->streamToString($resp);
		$this->assertStringContainsString('[STEP] Upgrading all data sources', $out);
		$this->assertStringContainsString('[DONE]', $out);
		$this->assertCount(1, $ctrl->commandsRun);
		$this->assertStringContainsString('ds-upgrade-all --no-ansi', $ctrl->commandsRun[0]);
	}

	public function testUpgradeAllEndpointReportsErrorOnNonZeroExit(): void
	{
		$ctrl = $this->makeTestableCtrl();
		$ctrl->commandResults = [
			[1, "Failed for 2 DS\n"],
		];

		$resp = $ctrl->dispatch($this->makeRequest('POST', '/_dev/api/upgrade-all'));
		$out = $this->streamToString($resp);
		$this->assertStringContainsString('[ERROR] Upgrade-all reported failures', $out);
		$this->assertStringNotContainsString('[DONE]', $out);
	}

	public function testDsUpgradeEndpointValidatesDsId(): void
	{
		$resp = $this->ctrl->dispatch($this->makeRequest('POST', '/_dev/api/ds-upgrade?ds=bad!'));
		$this->assertSame(400, $this->getStatus($resp));
		$payload = $this->getPayloadRaw($resp);
		$this->assertSame('INVALID_DS_ID', $payload['error']['code']);
	}

	public function testDsUpgradeEndpointRejectsUnknownDs(): void
	{
		$resp = $this->ctrl->dispatch($this->makeRequest('POST', '/_dev/api/ds-upgrade?ds=zzzz-aaaa-bbbb-cccc'));
		$this->assertSame(404, $this->getStatus($resp));
		$payload = $this->getPayloadRaw($resp);
		$this->assertSame('DS_NOT_FOUND', $payload['error']['code']);
	}

	public function testDsUpgradeEndpointSpawnsCorrectCommand(): void
	{
		$this->createDs('abcd-1234-efgh-5678', 'Demo');
		$ctrl = $this->makeTestableCtrl();
		$ctrl->commandResults = [
			[0, "Nothing to upgrade\n"],
		];

		$resp = $ctrl->dispatch($this->makeRequest('POST', '/_dev/api/ds-upgrade?ds=abcd-1234-efgh-5678'));
		$this->assertSame(200, $this->getStatus($resp));

		$out = $this->streamToString($resp);
		$this->assertStringContainsString('[STEP] Upgrading data source abcd-1234-efgh-5678', $out);
		$this->assertStringContainsString('[DONE]', $out);
		$this->assertStringContainsString('"message":"Data source abcd-1234-efgh-5678 upgraded successfully"', $out);
		$this->assertCount(1, $ctrl->commandsRun);
		$this->assertStringContainsString('abcd-1234-efgh-5678', $ctrl->commandsRun[0]);
		$this->assertStringContainsString('ds-upgrade --no-ansi', $ctrl->commandsRun[0]);
	}

	public function testDsUpgradeEndpointReportsErrorOnNonZeroExit(): void
	{
		$this->createDs('abcd-1234-efgh-5678', 'Demo');
		$ctrl = $this->makeTestableCtrl();
		$ctrl->commandResults = [
			[1, "Schema migration failed\n"],
		];

		$resp = $ctrl->dispatch($this->makeRequest('POST', '/_dev/api/ds-upgrade?ds=abcd-1234-efgh-5678'));
		$out = $this->streamToString($resp);
		$this->assertStringContainsString('[ERROR] Upgrade failed for abcd-1234-efgh-5678', $out);
		$this->assertStringNotContainsString('[DONE]', $out);
	}

	public function testActionEndpointsRejectGet(): void
	{
		$this->createDs('abcd-1234-efgh-5678', 'Demo');

		// GET on POST-only API endpoints — dispatch falls through to 404
		$this->assertSame(404, $this->getStatus(
			$this->ctrl->dispatch($this->makeRequest('GET', '/_dev/api/doctor'))
		));
		$this->assertSame(404, $this->getStatus(
			$this->ctrl->dispatch($this->makeRequest('GET', '/_dev/api/upgrade-all'))
		));
		$this->assertSame(404, $this->getStatus(
			$this->ctrl->dispatch($this->makeRequest('GET', '/_dev/api/ds-upgrade?ds=abcd-1234-efgh-5678'))
		));
	}

	/** Úlohy na pozadí (#103 F6b D58) se routují před Testováním — POST na cancel nekončí 404 z GET-only controlleru. */
	public function testJobsRoutesAreDispatchedBeforeTesting(): void
	{
		$ctrl = new DevDashboardController(
			$this->tmpDir,
			null,
			new ModulePathResolver([$this->modulesDir]),
			$this->spaIndex,
			$this->tmpRoot,
			'development',
			new JobStore($this->tmpRoot . '/run/dev-jobs'),
		);

		$list = $ctrl->dispatch($this->makeRequest('GET', '/_dev/api/testing/jobs'));
		$this->assertSame(200, $this->getStatus($list));
		$this->assertSame([], $list->getPayload()['data']['jobs']);

		$cancel = $ctrl->dispatch($this->makeRequest('POST', '/_dev/api/testing/jobs/20260101-000000-abcdef/cancel'));
		$this->assertSame(404, $this->getStatus($cancel));
		$this->assertSame('Job not found', $cancel->getPayload()['error']['message']);

		// Spouštěcí akce (DevEvalController): bez tests/ v repo rootu TESTING_UNAVAILABLE, ne „Not found“ GET-only Testování.
		$reseed = $ctrl->dispatch($this->makeRequest('POST', '/_dev/api/testing/eval/reseed'));
		$this->assertSame(404, $this->getStatus($reseed));
		$this->assertSame('TESTING_UNAVAILABLE', $reseed->getPayload()['error']['code']);
	}

	// ── Smazání zdroje dat jako úloha (tasks/ds-delete.md § 2, D70) ────────

	/** Controller se spouštěčem, který místo spawnu jen zaznamená argv; čas pevný, aby byla nová úloha `starting`. */
	private function deleteController(?string $mode = 'development'): TestableDevDashboardController
	{
		$store = new JobStore(
			$this->tmpRoot . '/jobs',
			static fn (): bool => true,
			static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-10-16T12:00:00Z'),
		);
		$launcher = new JobLauncher(
			$store,
			function (array $argv, string $cwd, string $log): bool {
				$this->spawns[] = ['argv' => $argv, 'cwd' => $cwd];
				return true;
			},
			static fn (int $pid, int $signal): bool => true,
			['/usr/bin/php', '/repo/bin/shpd-server'],
		);
		return new TestableDevDashboardController($this->tmpDir, null, new ModulePathResolver([$this->modulesDir]), $this->spaIndex, $this->tmpRoot, $mode, $store, $launcher);
	}

	private function deleteRequest(string $id): Request
	{
		return Request::fromArray('POST', '/_dev/api/data-sources/' . $id . '/delete', [], '', []);
	}

	public function testDeleteDataSourceLaunchesDeleteJob(): void
	{
		$this->createDs('aaaa-aaaa-aaaa-aaaa', 'Volný zdroj', '2026-10-01T00:00:00Z', ['dataClass' => 'free']);
		$ctrl = $this->deleteController();

		$resp = $ctrl->dispatch($this->deleteRequest('aaaa-aaaa-aaaa-aaaa'));

		$this->assertSame(201, $this->getStatus($resp));
		$job = $this->getPayloadRaw($resp)['data']['job'];
		$this->assertSame(JobRecord::TYPE_DELETE, $job['type']);
		$this->assertSame(['id' => 'aaaa-aaaa-aaaa-aaaa', 'name' => 'Volný zdroj'], $job['dataSource']);
		$this->assertSame('starting', $job['status']);
		$this->assertCount(1, $job['steps']);
		$this->assertSame(['/usr/bin/php', '/repo/bin/shpd-server', 'ds-delete', 'aaaa-aaaa-aaaa-aaaa', '--yes', '--job', $job['id']], $job['steps'][0]['argv'], '--job = vlastní úloha, kterou preflight ds-delete přeskočí');
		$this->assertSame($this->tmpRoot, $job['steps'][0]['cwd'], 'cwd je kořen repozitáře — adresář zdroje dat během úlohy zmizí');
		$this->assertSame(DevDashboardController::DELETE_CANCEL_GRACE_SECONDS, $job['cancelGraceSeconds']);
		$this->assertCount(1, $this->spawns);
		$this->assertSame(['/usr/bin/php', '/repo/bin/shpd-server', 'dev-job-run', $this->tmpRoot . '/jobs/' . $job['id']], $this->spawns[0]['argv']);
		$stored = (new JobStore($this->tmpRoot . '/jobs'))->read($job['id']);
		$this->assertNotNull($stored);
		$this->assertSame(JobRecord::TYPE_DELETE, $stored->type);
		// Adresář maže až úloha (ds-delete) — API ho nechává.
		$this->assertDirectoryExists($this->tmpDir . '/aaaa-aaaa-aaaa-aaaa');
	}

	public function testDeleteDataSourceRefusedOutsideDevelopment(): void
	{
		$this->createDs('aaaa-aaaa-aaaa-aaaa', 'Volný zdroj', null, ['dataClass' => 'free']);
		foreach ([null, 'production'] as $mode) {
			$resp = $this->deleteController($mode)->dispatch($this->deleteRequest('aaaa-aaaa-aaaa-aaaa'));
			$this->assertSame(403, $this->getStatus($resp), var_export($mode, true));
			$this->assertSame('FORBIDDEN', $this->getPayloadRaw($resp)['error']['code']);
		}
		$this->assertSame([], $this->spawns);
		$this->assertDirectoryExists($this->tmpDir . '/aaaa-aaaa-aaaa-aaaa');
	}

	public function testDeleteDataSourceRefusedForRealCopyAndMissingClass(): void
	{
		$this->createDs('bbbb-bbbb-bbbb-bbbb', 'Reálná kopie', null, ['dataClass' => 'realCopy', 'purpose' => 'účtárna']);
		$this->createDs('cccc-cccc-cccc-cccc', 'Bez třídy');
		$ctrl = $this->deleteController();
		foreach (['bbbb-bbbb-bbbb-bbbb', 'cccc-cccc-cccc-cccc'] as $id) {
			$resp = $ctrl->dispatch($this->deleteRequest($id));
			$this->assertSame(403, $this->getStatus($resp), $id);
			$error = $this->getPayloadRaw($resp)['error'];
			$this->assertSame('DS_NOT_FREE', $error['code']);
			$this->assertStringContainsString('delete', $error['message']);
			$this->assertDirectoryExists($this->tmpDir . '/' . $id);
		}
		$this->assertSame([], $this->spawns);
	}

	public function testDeleteDataSourceUnknownOrInvalidIdIs404(): void
	{
		$ctrl = $this->deleteController();

		$resp = $ctrl->dispatch($this->deleteRequest('zzzz-zzzz-zzzz-zzzz'));
		$this->assertSame(404, $this->getStatus($resp));
		$this->assertSame('DS_NOT_FOUND', $this->getPayloadRaw($resp)['error']['code']);

		$resp = $ctrl->dispatch($this->deleteRequest('abc'));
		$this->assertSame(404, $this->getStatus($resp));
		$this->assertSame('NOT_FOUND', $this->getPayloadRaw($resp)['error']['code']);

		$this->assertSame([], $this->spawns);
	}

	public function testDeleteDataSourceConflictsWithRunningJob(): void
	{
		$this->createDs('aaaa-aaaa-aaaa-aaaa', 'Volný zdroj', null, ['dataClass' => 'free']);
		$ctrl = $this->deleteController();
		$store = new JobStore($this->tmpRoot . '/jobs', static fn (): bool => true);
		$store->ensureDir();
		$runningId = JobRecord::newId(new \DateTimeImmutable('2026-10-16T11:59:00Z'));
		$store->create(new JobRecord(
			$runningId,
			JobRecord::TYPE_RESEED,
			'aaaa-aaaa-aaaa-aaaa',
			'Volný zdroj',
			'cz-vat-payer',
			null,
			[],
			[new JobStep('dataset-seed', ['/usr/bin/php', '/repo/bin/shpd-ds', 'dataset-seed'], $this->tmpRoot)],
			'2026-10-16T11:59:00Z',
			null,
			60,
		));
		$state = JobState::pending(1);
		$state->status = JobStatus::RUNNING;
		$state->pid = 4242;
		$state->startedAt = '2026-10-16T11:59:01Z';
		$store->writeState($runningId, $state);

		$resp = $ctrl->dispatch($this->deleteRequest('aaaa-aaaa-aaaa-aaaa'));

		$this->assertSame(409, $this->getStatus($resp));
		$error = $this->getPayloadRaw($resp)['error'];
		$this->assertSame('JOB_RUNNING', $error['code']);
		$this->assertSame($runningId, $error['details']['jobId']);
		$this->assertSame('aaaa-aaaa-aaaa-aaaa', $error['details']['dataSource']);
		$this->assertSame([], $this->spawns);
		$this->assertDirectoryExists($this->tmpDir . '/aaaa-aaaa-aaaa-aaaa');
	}

	public function testDeleteDataSourceRequiresPost(): void
	{
		$this->createDs('aaaa-aaaa-aaaa-aaaa', 'Volný zdroj', null, ['dataClass' => 'free']);

		$resp = $this->deleteController()->dispatch($this->makeRequest('GET', '/_dev/api/data-sources/aaaa-aaaa-aaaa-aaaa/delete'));

		$this->assertSame(404, $this->getStatus($resp));
		$this->assertSame([], $this->spawns);
	}
}

class TestableDevDashboardController extends DevDashboardController
{
	/** @var list<array{0: int, 1: string}> výsledky v pořadí kroků — sdílí je shellové příkazy i podprocesy s argv */
	public array $commandResults = [];
	/** @var list<string> */
	public array $commandsRun = [];
	/** @var list<array{argv: list<string>, cwd: string, stdin: ?string}> */
	public array $processesRun = [];

	/** @return list<string> */
	protected function serverCommand(): array
	{
		return ['/usr/bin/php', '/repo/bin/shpd-server'];
	}

	protected function now(): \DateTimeImmutable
	{
		return new \DateTimeImmutable('2026-10-16T12:00:00Z');
	}

	protected function streamCommand(string $cmd, bool $redactPassword = false): array
	{
		$this->commandsRun[] = $cmd;
		return $this->nextResult($redactPassword);
	}

	protected function streamProcess(array $argv, string $cwd, ?string $stdin = null, bool $redactPassword = false): array
	{
		$this->processesRun[] = ['argv' => $argv, 'cwd' => $cwd, 'stdin' => $stdin];
		return $this->nextResult($redactPassword);
	}

	/** @return array{0: int, 1: string} */
	private function nextResult(bool $redactPassword): array
	{
		if (count($this->commandResults) === 0) {
			return [0, ''];
		}
		$result = array_shift($this->commandResults);

		$emitted = $redactPassword
			? (string) preg_replace('/--password=\S+/', '--password=***', $result[1])
			: $result[1];
		echo $emitted;

		return $result;
	}
}

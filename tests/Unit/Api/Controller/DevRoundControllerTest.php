<?php
declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\Controller\DevRoundController;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\DevJobs\JobLauncher;
use Shipard\Core\DevJobs\JobRecord;
use Shipard\Core\DevJobs\JobState;
use Shipard\Core\DevJobs\JobStatus;
use Shipard\Core\DevJobs\JobStore;
use Shipard\Module\Core\Exchange\Dataset\E2e\LocalBaseUrl;
use Shipard\Module\Core\Exchange\Dataset\Round\RoundJobFactory;
use Shipard\Module\Core\Exchange\Dataset\Round\RoundResult;

class TestableDevRoundController extends DevRoundController
{
	/** @var list<string> */
	public array $e2eProblems = [];
	/** @var list<string> */
	public array $videoProblems = [];
	/** @var array<string, array<string, mixed>> cwd → výsledek `ai-eval --estimate` */
	public array $captures = [];
	/** @var list<array{argv: list<string>, cwd: string}> */
	public array $captured = [];

	protected function e2eToolProblems(): array
	{
		return $this->e2eProblems;
	}

	protected function videoToolProblems(): array
	{
		return $this->videoProblems;
	}

	protected function localBaseUrl(): LocalBaseUrl
	{
		return new LocalBaseUrl(static fn (string $host): array => $host === 'dev.example' ? ['10.0.0.7'] : [], static fn (): array => ['10.0.0.7']);
	}

	protected function dsCommand(): array
	{
		return ['/usr/bin/php', '/repo/bin/shpd-ds'];
	}

	protected function serverCommand(): array
	{
		return ['/usr/bin/php', '/repo/bin/shpd-server'];
	}

	protected function capture(array $argv, string $cwd, float $timeoutSeconds): array
	{
		$this->captured[] = ['argv' => $argv, 'cwd' => $cwd];
		return ($this->captures[$cwd] ?? []) + ['exitCode' => 0, 'termSignal' => null, 'timedOut' => false, 'stdout' => '', 'stderr' => ''];
	}

	protected function now(): \DateTimeImmutable
	{
		return new \DateTimeImmutable('2026-10-27T12:00:00Z');
	}
}

/**
 * Testovací kolo v dashboardu (#103 F8a § 5): přehled s posledním kolem
 * a nabídkami, odhad per sada, spuštění úlohy `round` týmž záznamem jako
 * CLI, origin mimo server, strop ceny, běžící kolo = 409.
 */
final class DevRoundControllerTest extends TestCase
{
	private string $tmpRoot;
	private string $dsDir;
	private string $jobsDir;
	private JobStore $jobs;
	/** @var list<array{argv: list<string>, cwd: string}> */
	private array $spawns = [];
	private TestableDevRoundController $ctrl;

	protected function setUp(): void
	{
		$this->tmpRoot = sys_get_temp_dir() . '/shpd_devround_' . bin2hex(random_bytes(5));
		$this->dsDir = $this->tmpRoot . '/data-sources';
		$this->jobsDir = $this->tmpRoot . '/run/dev-jobs';
		$real = dirname(__DIR__, 4);
		mkdir($this->dsDir, 0700, true);
		mkdir($this->tmpRoot . '/tests/eval/variants', 0700, true);
		mkdir($this->tmpRoot . '/demo/datasets/it-servis', 0700, true);
		mkdir($this->tmpRoot . '/demo/scenarios', 0700, true);
		symlink($real . '/tests/datasets', $this->tmpRoot . '/tests/datasets');
		copy($real . '/tests/eval/variants/baseline.jsonc', $this->tmpRoot . '/tests/eval/variants/baseline.jsonc');
		copy($real . '/demo/datasets/it-servis/manifest.jsonc', $this->tmpRoot . '/demo/datasets/it-servis/manifest.jsonc');
		copy($real . '/demo/scenarios/s1-prijata-faktura.jsonc', $this->tmpRoot . '/demo/scenarios/s1-prijata-faktura.jsonc');

		$this->createDs('kkkk-kkkk-kkkk-kkkk', ['dataClass' => 'free', 'purpose' => 'testovací kolo — cz-non-payer']);
		$this->createDs('eeee-eeee-eeee-eeee', ['dataClass' => 'free', 'purpose' => 'evaluace AI — cz-vat-payer', 'dataset' => ['name' => 'cz-vat-payer', 'hash' => 'h']]);
		$this->createDs('vvvv-vvvv-vvvv-vvvv', ['dataClass' => 'free', 'purpose' => 'videa — it-servis']);

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
		$this->ctrl = new TestableDevRoundController($this->tmpRoot, $this->dsDir, 'development', $this->jobs, $launcher);
	}

	protected function tearDown(): void
	{
		@unlink($this->tmpRoot . '/tests/datasets');
		$this->rrmdir($this->tmpRoot);
	}

	/** @param array<string, mixed> $extra */
	private function createDs(string $id, array $extra): void
	{
		mkdir($this->dsDir . '/' . $id . '/config', 0700, true);
		file_put_contents($this->dsDir . '/' . $id . '/config/main.json', json_encode([
			'id' => $id, 'name' => 'DS ' . substr($id, 0, 4), 'database_name' => str_replace('-', '_', $id), 'created' => '2026-10-01T00:00:00Z', 'country' => 'cz',
		] + $extra, JSON_UNESCAPED_UNICODE));
	}

	/** @param array<string, mixed> $body @param array<string, string> $server */
	private function call(string $method, string $sub, array $body = [], array $server = ['HTTP_HOST' => '10.0.0.7', 'HTTP_ORIGIN' => 'http://10.0.0.7']): Response
	{
		$request = Request::fromArray($method, DevRoundController::PREFIX . $sub, [], $body === [] ? '' : (string) json_encode($body), ['REMOTE_ADDR' => '10.0.0.5'] + $server);
		return $this->ctrl->dispatch($request);
	}

	private function statusOf(Response $r): int
	{
		return (int) (new \ReflectionClass($r))->getProperty('status')->getValue($r);
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

	public function testOverviewListsOptionsDataSourcesAndTheLastRound(): void
	{
		$r = $this->call('GET', '');
		$this->assertSame(200, $this->statusOf($r), json_encode($r->getPayload()));
		$data = $this->data($r);
		$this->assertNull($data['last']);
		$this->assertNull($data['active']);
		$options = $data['options'];
		$this->assertSame(['code', 'datasets', 'videos'], $options['layers']);
		$this->assertSame(['cz-association', 'cz-non-payer', 'cz-vat-payer'], array_column($options['datasets'], 'name'));
		$this->assertSame(['s1-prijata-faktura'], array_column($options['scenarios'], 'id'));
		$this->assertSame('it-servis', $options['scenarios'][0]['dataset']);
		$this->assertSame(['baseline'], $options['variants']);
		$this->assertSame(5, $options['maxRepeat']);
		$this->assertSame(['e2e' => [], 'video' => []], $options['tools']);
		$this->assertSame(['id' => 'kkkk-kkkk-kkkk-kkkk', 'name' => 'DS kkkk'], $options['dataSources']['datasets']['cz-non-payer']);
		$this->assertNull($options['dataSources']['datasets']['cz-vat-payer'], 'evaluační zdroj dat se nepočítá (O1)');
		$this->assertNull($options['dataSources']['datasets']['cz-association']);
		$this->assertSame(['id' => 'vvvv-vvvv-vvvv-vvvv', 'name' => 'DS vvvv'], $options['dataSources']['videos']['it-servis']);

		// Poslední kolo se souhrnem a běžící kolo.
		$this->call('POST', '/run', ['layers' => ['code']]);
		$id = $this->jobs->list()[0]['id'];
		$result = new RoundResult(['layers' => ['code']], ['commit' => 'abc', 'branch' => 'stable', 'dirty' => false], '2026-10-27T12:00:00Z', $id);
		$result->recordStep('code', 'phpunit', 'PHPUnit', null, null, RoundResult::STEP_OK, '2026-10-27T12:01:00Z');
		$this->jobs->writeResult($id, $result->toArray());
		$state = JobState::pending(1);
		$state->pid = 4242;
		$this->jobs->writeState($id, $state);

		$data = $this->data($this->call('GET', ''));
		$this->assertSame($id, $data['last']['job']['id']);
		$this->assertSame(RoundResult::STATUS_RUNNING, $data['last']['result']['status']);
		$this->assertSame('PHPUnit', $data['last']['result']['steps'][0]['label']);
		$this->assertSame($id, $data['active']['id']);
		$this->assertSame(JobStatus::RUNNING, $data['active']['status']);
	}

	public function testRunLaunchesTheRoundJobLikeTheCli(): void
	{
		$r = $this->call('POST', '/run', ['layers' => ['datasets', 'videos'], 'datasets' => ['cz-non-payer'], 'scenarios' => ['s1-prijata-faktura']], ['HTTP_HOST' => 'dev.example', 'HTTP_ORIGIN' => 'http://dev.example']);
		$this->assertSame(201, $this->statusOf($r), json_encode($r->getPayload()));
		$job = $this->data($r)['job'];
		$this->assertSame(JobRecord::TYPE_ROUND, $job['type']);
		$this->assertNull($job['dataSource']);
		$this->assertNull($job['parent']);
		$this->assertSame(['datasets', 'videos'], $job['params']['layers']);
		$this->assertSame(['cz-non-payer'], $job['params']['datasets']);
		$this->assertSame(['s1-prijata-faktura'], $job['params']['scenarios']);
		$this->assertNull($job['params']['eval']);
		$this->assertSame('http://dev.example', $job['params']['baseUrl']);
		$this->assertSame(RoundJobFactory::ORIGIN_DASHBOARD, $job['params']['origin']);
		$this->assertSame('10.0.0.5', $job['clientIp']);
		$this->assertSame(RoundJobFactory::CANCEL_GRACE_SECONDS, $job['cancelGraceSeconds']);
		$this->assertSame(['/usr/bin/php', '/repo/bin/shpd-server', 'test-round-run', $this->jobsDir . '/' . $job['id']], $job['steps'][0]['argv']);
		$this->assertSame($this->tmpRoot, $job['steps'][0]['cwd']);
		$this->assertFalse($job['steps'][0]['captureStdout']);
		$this->assertCount(1, $this->spawns);
		$this->assertSame('dev-job-run', $this->spawns[0]['argv'][2]);

		// Druhé kolo vedle běžícího → 409 s odkazem.
		$state = JobState::pending(1);
		$state->pid = 4242;
		$this->jobs->writeState($job['id'], $state);
		$r = $this->call('POST', '/run', ['layers' => ['code']]);
		$this->assertSame(409, $this->statusOf($r));
		$this->assertSame('JOB_RUNNING', $this->error($r)['code']);
		$this->assertSame($job['id'], $this->error($r)['details']['jobId']);
	}

	public function testRunRefusesBadOriginUnknownSelectionAndOtherModes(): void
	{
		$r = $this->call('POST', '/run', ['layers' => ['code']], []);
		$this->assertSame(400, $this->statusOf($r), 'bez hostu není origin');
		$r = $this->call('POST', '/run', ['layers' => ['code']], ['HTTP_HOST' => '203.0.113.5', 'HTTP_ORIGIN' => 'http://203.0.113.5']);
		$this->assertSame(400, $this->statusOf($r));
		$this->assertStringContainsString('203.0.113.5', $this->error($r)['message']);

		$r = $this->call('POST', '/run', ['layers' => ['deploy']]);
		$this->assertSame(400, $this->statusOf($r));
		$this->assertSame('VALIDATION', $this->error($r)['code']);
		$r = $this->call('POST', '/run', ['datasets' => ['it-servis']]);
		$this->assertSame(400, $this->statusOf($r));
		$this->assertSame('UNKNOWN_DATASET', $this->error($r)['code']);
		$r = $this->call('POST', '/run', ['scenarios' => ['nope']]);
		$this->assertSame(400, $this->statusOf($r));
		$r = $this->call('POST', '/run', ['eval' => ['variant' => 'baseline', 'repeat' => 1]]);
		$this->assertSame(400, $this->statusOf($r), 'evaluace bez stropu');

		$prod = new TestableDevRoundController($this->tmpRoot, $this->dsDir, 'production', $this->jobs, new JobLauncher($this->jobs, static fn (): bool => true, static fn (): bool => true, ['php', 'x']));
		$r = $prod->dispatch(Request::fromArray('GET', DevRoundController::PREFIX, [], '', ['REMOTE_ADDR' => '10.0.0.5']));
		$this->assertSame(403, $this->statusOf($r));
		$this->assertSame([], $this->spawns);
	}

	public function testEstimateAndCapGateTheEvaluation(): void
	{
		$okJson = json_encode(['ok' => true, 'calls' => 16, 'model' => 'claude-sonnet-5-5', 'estimate' => ['total' => 1.25, 'basis' => 'catalog']]) . "\n";
		$this->ctrl->captures[$this->dsDir . '/kkkk-kkkk-kkkk-kkkk'] = ['stdout' => $okJson];

		$r = $this->call('POST', '/estimate', ['datasets' => ['cz-non-payer'], 'variant' => 'baseline', 'repeat' => 2]);
		$this->assertSame(200, $this->statusOf($r), json_encode($r->getPayload()));
		$this->assertSame(1.25, $this->data($r)['total']);
		$this->assertSame('cz-non-payer', $this->data($r)['rows'][0]['dataset']);
		$this->assertSame(2, $this->data($r)['repeat']);
		$this->assertSame('--repeat', $this->ctrl->captured[0]['argv'][6]);
		$this->assertSame('2', $this->ctrl->captured[0]['argv'][7]);
		$this->assertContains('--estimate', $this->ctrl->captured[0]['argv']);

		// Sada bez zdroje dat kola → 422 s větou; cizí varianta → 422.
		$r = $this->call('POST', '/estimate', ['variant' => 'baseline']);
		$this->assertSame(422, $this->statusOf($r));
		$this->assertSame('EVAL_PREFLIGHT', $this->error($r)['code']);
		$this->assertStringContainsString('cz-association ještě neexistuje', implode(' ', $this->error($r)['details']['problems']));
		$r = $this->call('POST', '/estimate', ['datasets' => ['cz-non-payer'], 'variant' => 'nope']);
		$this->assertSame(422, $this->statusOf($r));

		// Spuštění s evaluací: nad stropem 422, pod stropem 201 s eval v params.
		$r = $this->call('POST', '/run', ['layers' => ['datasets'], 'datasets' => ['cz-non-payer'], 'eval' => ['variant' => 'baseline', 'repeat' => 1, 'maxCostUsd' => 1.0]]);
		$this->assertSame(422, $this->statusOf($r));
		$this->assertSame('EVAL_OVER_CAP', $this->error($r)['code']);
		$this->assertSame(1.25, $this->error($r)['details']['total']);
		$this->assertSame([], $this->spawns);

		$r = $this->call('POST', '/run', ['layers' => ['datasets'], 'datasets' => ['cz-non-payer'], 'eval' => ['variant' => 'baseline', 'repeat' => 1, 'maxCostUsd' => 2.0]]);
		$this->assertSame(201, $this->statusOf($r), json_encode($r->getPayload()));
		$this->assertSame(['variant' => 'baseline', 'repeat' => 1, 'maxCostUsd' => 2.0], $this->data($r)['job']['params']['eval']);
		$this->assertSame('baseline', $this->data($r)['job']['variant']);
	}

	private function rrmdir(string $dir): void
	{
		if (!is_dir($dir)) {
			return;
		}
		foreach (scandir($dir) ?: [] as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}
			$path = $dir . '/' . $entry;
			is_dir($path) && !is_link($path) ? $this->rrmdir($path) : unlink($path);
		}
		rmdir($dir);
	}
}

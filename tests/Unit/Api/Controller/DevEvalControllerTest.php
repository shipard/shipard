<?php
declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\Controller\DevEvalController;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\DevJobs\JobLauncher;
use Shipard\Core\DevJobs\JobRecord;
use Shipard\Core\DevJobs\JobState;
use Shipard\Core\DevJobs\JobStore;
use Shipard\Module\Core\Mail\Eval\Browse\EvalCatalog;

class TestableDevEvalController extends DevEvalController
{
	/** @var array<string, array<string, mixed>|\Throwable> stav zdroje dat podle ID (výchozí = čistý) */
	public array $statuses = [];
	/** @var list<array{argv: list<string>, cwd: string, timeout: float}> */
	public array $captures = [];
	/** @var array{exitCode: ?int, termSignal: ?int, timedOut: bool, stdout: string, stderr: string} */
	public array $captureResult = ['exitCode' => 0, 'termSignal' => null, 'timedOut' => false, 'stdout' => '', 'stderr' => ''];
	public string $nextId = 'zzzz-zzzz-zzzz-zzzz';

	protected function inspectDataSource(string $dsDir, array $setMessageIds): array
	{
		$status = $this->statuses[basename($dsDir)] ?? ['messages' => count($setMessageIds), 'documents' => 0, 'tagRules' => 0, 'missing' => [], 'membership' => [], 'clean' => true];
		if ($status instanceof \Throwable) {
			throw $status;
		}
		return $status;
	}

	protected function capture(array $argv, string $cwd, float $timeoutSeconds): array
	{
		$this->captures[] = ['argv' => $argv, 'cwd' => $cwd, 'timeout' => $timeoutSeconds];
		return $this->captureResult;
	}

	protected function serverOffersAi(): bool
	{
		return true;
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
		return new \DateTimeImmutable('2026-10-16T12:00:00Z');
	}

	protected function variantOptionsFor(string $dsDir): array
	{
		return ['platform' => 'anthropic', 'models' => [['id' => 'claude-haiku-5-5']], 'connections' => [], 'profiles' => [], 'effortLevels' => [], 'thinkingModes' => []];
	}
}

/**
 * Spouštěcí API evaluací (F6b D54, D55, D59–D63): zdroje dat sady a jejich
 * stav, pojistka D60 (403 realCopy / mimo development, 400 neznámá sada),
 * argv úloh bez shellu, 409 druhá úloha, odhad přes podproces, editor variant
 * nad dočasnou kopií `variants/`.
 */
final class DevEvalControllerTest extends TestCase
{
	private const SET = 'cz-vat-payer';
	private string $tmpRoot;
	private string $dsDir;
	private string $jobsDir;
	private string $datasetDir;
	private string $currentHash;
	private JobStore $jobs;
	/** @var list<array{argv: list<string>, cwd: string}> */
	private array $spawns = [];
	private TestableDevEvalController $ctrl;

	protected function setUp(): void
	{
		$this->tmpRoot = sys_get_temp_dir() . '/shpd_deveval_' . bin2hex(random_bytes(5));
		$this->dsDir = $this->tmpRoot . '/data-sources';
		$this->jobsDir = $this->tmpRoot . '/run/dev-jobs';
		mkdir($this->dsDir, 0700, true);
		mkdir($this->tmpRoot . '/tests/eval/variants', 0700, true);
		// Sady jen ke čtení přes symlink; varianty jako kopie, aby PUT nezapisoval do checkoutu.
		symlink(dirname(__DIR__, 4) . '/tests/datasets', $this->tmpRoot . '/tests/datasets');
		copy(dirname(__DIR__, 4) . '/tests/eval/variants/baseline.jsonc', $this->tmpRoot . '/tests/eval/variants/baseline.jsonc');

		$catalog = new EvalCatalog($this->tmpRoot);
		$dataset = $catalog->dataset(self::SET);
		$this->assertNotNull($dataset);
		$this->datasetDir = $dataset->dir;
		$this->currentHash = $catalog->currentHash($dataset);

		$this->createDs('aaaa-aaaa-aaaa-aaaa', ['dataClass' => 'free', 'origin' => 'dataset-seed ' . self::SET, 'dataset' => ['name' => self::SET, 'hash' => $this->currentHash, 'seededAt' => '2026-10-16T10:00:00Z']]);
		$this->createDs('bbbb-bbbb-bbbb-bbbb', ['dataClass' => 'realCopy', 'purpose' => 'účtárna', 'dataset' => ['name' => self::SET, 'hash' => $this->currentHash, 'seededAt' => '2026-10-16T10:00:00Z']]);
		$this->createDs('cccc-cccc-cccc-cccc', ['dataClass' => 'free', 'origin' => 'ds-create', 'purpose' => 'ruční zkoušení']);
		$this->createDs('dddd-dddd-dddd-dddd', ['dataClass' => 'free', 'origin' => 'dataset-seed ' . self::SET]);
		$this->createDs('eeee-eeee-eeee-eeee', ['dataClass' => 'free', 'origin' => 'dataset-seed ' . self::SET, 'dataset' => ['name' => self::SET, 'hash' => 'old-hash', 'seededAt' => '2026-10-01T10:00:00Z']]);
		$this->createDs('ffff-ffff-ffff-ffff', ['dataClass' => 'free', 'origin' => 'dataset-seed cz-non-payer']);

		$this->jobs = new JobStore($this->jobsDir, static fn (): bool => true, static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-10-16T12:00:00Z'));
		$launcher = new JobLauncher(
			$this->jobs,
			function (array $argv, string $cwd, string $log): bool {
				$this->spawns[] = ['argv' => $argv, 'cwd' => $cwd];
				return true;
			},
			static fn (int $pid, int $signal): bool => true,
			['/usr/bin/php', '/repo/bin/shpd-server'],
		);
		$this->ctrl = $this->controller('development', $launcher);
	}

	protected function tearDown(): void
	{
		@unlink($this->tmpRoot . '/tests/datasets');
		$this->rrmdir($this->tmpRoot);
	}

	private function controller(string $mode, ?JobLauncher $launcher = null): TestableDevEvalController
	{
		$launcher ??= new JobLauncher($this->jobs, static fn (): bool => true, static fn (): bool => true, ['/usr/bin/php', '/repo/bin/shpd-server']);
		return new TestableDevEvalController($this->tmpRoot, $this->dsDir, $mode, $this->jobs, $launcher);
	}

	/** @param array<string, mixed> $extra */
	private function createDs(string $id, array $extra): void
	{
		mkdir($this->dsDir . '/' . $id . '/config', 0700, true);
		file_put_contents($this->dsDir . '/' . $id . '/config/main.json', json_encode([
			'id' => $id, 'name' => 'DS ' . substr($id, 0, 4), 'database_name' => str_replace('-', '_', $id), 'created' => '2026-10-01T00:00:00Z', 'country' => 'cz',
		] + $extra, JSON_UNESCAPED_UNICODE));
	}

	/** @param array<string, mixed> $body */
	private function request(string $method, string $uri, array $body = []): Request
	{
		$query = [];
		$qs = parse_url($uri, PHP_URL_QUERY);
		if (is_string($qs) && $qs !== '') {
			parse_str($qs, $query);
		}
		return Request::fromArray($method, $uri, $query, $body === [] ? '' : (string) json_encode($body), ['REMOTE_ADDR' => '10.0.0.5']);
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

	private function post(string $action, array $body, ?TestableDevEvalController $ctrl = null): Response
	{
		return ($ctrl ?? $this->ctrl)->dispatch($this->request('POST', '/_dev/api/testing/eval/' . $action, $body));
	}

	// ── Zdroje dat sady ──────────────────────────────────────────────────

	public function testDataSourcesListHoldersWithStateAndReseedCandidates(): void
	{
		$this->ctrl->statuses['eeee-eeee-eeee-eeee'] = ['messages' => 20, 'documents' => 2, 'tagRules' => 1, 'missing' => [], 'membership' => [], 'clean' => false];
		$this->ctrl->statuses['dddd-dddd-dddd-dddd'] = new \RuntimeException('Access denied for user');

		$r = $this->ctrl->dispatch($this->request('GET', '/_dev/api/testing/eval/data-sources?dataset=' . self::SET));
		$this->assertSame(200, $this->statusOf($r), json_encode($r->getPayload()));
		$data = $this->data($r);

		$this->assertSame(self::SET, $data['dataset']['name']);
		$this->assertSame($this->currentHash, $data['currentHash']);
		$rows = array_column($data['dataSources'], null, 'id');
		$this->assertSame(['aaaa-aaaa-aaaa-aaaa', 'bbbb-bbbb-bbbb-bbbb', 'dddd-dddd-dddd-dddd', 'eeee-eeee-eeee-eeee'], array_keys($rows));

		$a = $rows['aaaa-aaaa-aaaa-aaaa'];
		$this->assertTrue($a['canEval'], json_encode($a['blockers']));
		$this->assertSame([], $a['blockers']);
		$this->assertTrue($a['dataset']['current']);
		$this->assertTrue($a['serverAi']);
		$this->assertTrue($a['usage']['clean']);
		$this->assertTrue($a['canReseed']);
		$this->assertTrue($a['canVerify']);
		$this->assertNull($a['job']);

		$b = $rows['bbbb-bbbb-bbbb-bbbb'];
		$this->assertFalse($b['canEval']);
		$this->assertContains('třída dat není free', $b['blockers']);
		$this->assertNull($b['usage'], 'reálná kopie se do databáze neotvírá');
		$this->assertFalse($b['canReseed']);

		$d = $rows['dddd-dddd-dddd-dddd'];
		$this->assertTrue($d['legacyOrigin']);
		$this->assertNull($d['dataset']);
		$this->assertContains('sada ve zdroji dat není zaznamenaná — přeseedovat', $d['blockers']);
		$this->assertStringContainsString('Access denied', (string) $d['usageError']);
		$this->assertContains('databáze nedostupná: Access denied for user', $d['blockers']);
		$this->assertTrue($d['canReseed']);
		$this->assertFalse($d['canVerify']);

		$e = $rows['eeee-eeee-eeee-eeee'];
		$this->assertFalse($e['dataset']['current']);
		$this->assertContains('zastaralá verze sady — přeseedovat', $e['blockers']);
		$this->assertContains('2 doklad(ů) — použitý zdroj dat, přeseedovat', $e['blockers']);
		$this->assertContains('1 pravidel IČO → štítek — přeseedovat', $e['blockers']);

		$this->assertSame(['cccc-cccc-cccc-cccc'], array_column($data['reseedCandidates'], 'id'), 'jen volné bez sady a bez původu z jiné sady');
	}

	public function testRunningJobBlocksTheDataSource(): void
	{
		$this->post('reseed', ['dataset' => self::SET, 'dataSource' => 'aaaa-aaaa-aaaa-aaaa']);
		$jobId = $this->jobs->list()[0]['id'];
		$state = JobState::pending(1);
		$state->pid = 4242;
		$this->jobs->writeState($jobId, $state);

		$rows = array_column($this->data($this->ctrl->dispatch($this->request('GET', '/_dev/api/testing/eval/data-sources?dataset=' . self::SET)))['dataSources'], null, 'id');
		$a = $rows['aaaa-aaaa-aaaa-aaaa'];
		$this->assertSame($jobId, $a['job']['id']);
		$this->assertSame('running', $a['job']['status']);
		$this->assertContains('běží úloha ' . $jobId, $a['blockers']);
		$this->assertFalse($a['canReseed']);

		$this->assertSame(400, $this->statusOf($this->ctrl->dispatch($this->request('GET', '/_dev/api/testing/eval/data-sources?dataset=nope'))));
	}

	// ── Pojistka D60 ─────────────────────────────────────────────────────

	public function testActionsAreRefusedOnRealCopyOutsideDevelopmentAndForUnknownDataset(): void
	{
		foreach (['reseed', 'verify', 'estimate', 'run'] as $action) {
			$r = $this->post($action, ['dataset' => self::SET, 'dataSource' => 'bbbb-bbbb-bbbb-bbbb', 'variant' => 'baseline', 'maxCostUsd' => 1]);
			$this->assertSame(403, $this->statusOf($r), $action);
			$this->assertSame('DS_NOT_FREE', $this->error($r)['code'], $action);

			$r = $this->post($action, ['dataset' => self::SET, 'dataSource' => 'cccc-cccc-cccc-cccc'], $this->controller('production'));
			$this->assertSame(403, $this->statusOf($r), $action . ' mimo development');
			$this->assertSame('FORBIDDEN', $this->error($r)['code']);

			$r = $this->post($action, ['dataset' => '../etc', 'dataSource' => 'aaaa-aaaa-aaaa-aaaa', 'variant' => 'baseline', 'maxCostUsd' => 1]);
			$this->assertSame(400, $this->statusOf($r), $action . ' neznámá sada');
			$this->assertSame('UNKNOWN_DATASET', $this->error($r)['code']);

			$r = $this->post($action, ['dataset' => self::SET, 'dataSource' => 'zzzz-zzzz-zzzz-zzzz']);
			$this->assertSame(404, $this->statusOf($r), $action . ' neznámý zdroj dat');
		}
		$r = $this->post('create', ['dataset' => self::SET], $this->controller('production'));
		$this->assertSame(403, $this->statusOf($r));
		$this->assertSame([], $this->spawns, 'nic se nespustilo');
		$this->assertSame([], $this->ctrl->captures);
	}

	// ── Akce → úlohy ─────────────────────────────────────────────────────

	public function testReseedLaunchesTheJobAndSecondOneConflicts(): void
	{
		$r = $this->post('reseed', ['dataset' => self::SET, 'dataSource' => 'aaaa-aaaa-aaaa-aaaa']);
		$this->assertSame(201, $this->statusOf($r), json_encode($r->getPayload()));
		$job = $this->data($r)['job'];
		$this->assertSame(JobRecord::TYPE_RESEED, $job['type']);
		$this->assertSame('starting', $job['status']);
		$this->assertSame(['id' => 'aaaa-aaaa-aaaa-aaaa', 'name' => 'DS aaaa'], $job['dataSource']);
		$this->assertSame(self::SET, $job['dataset']);
		$this->assertSame('10.0.0.5', $job['clientIp']);
		$this->assertSame(60, $job['cancelGraceSeconds']);
		$this->assertSame(['/usr/bin/php', '/repo/bin/shpd-ds', 'dataset-seed', $this->datasetDir, '--yes'], $job['steps'][0]['argv']);
		$this->assertSame($this->dsDir . '/aaaa-aaaa-aaaa-aaaa', $job['steps'][0]['cwd']);
		$this->assertFalse($job['steps'][0]['captureStdout']);
		$this->assertCount(1, $this->spawns);
		$this->assertSame(['/usr/bin/php', '/repo/bin/shpd-server', 'dev-job-run', $this->jobsDir . '/' . $job['id']], $this->spawns[0]['argv']);

		$r = $this->post('verify', ['dataset' => self::SET, 'dataSource' => 'aaaa-aaaa-aaaa-aaaa']);
		$this->assertSame(409, $this->statusOf($r));
		$this->assertSame('JOB_RUNNING', $this->error($r)['code']);
		$this->assertSame($job['id'], $this->error($r)['details']['jobId']);
		$this->assertCount(1, $this->spawns);
	}

	public function testReseedWithAnotherDatasetNeedsReplaceDataset(): void
	{
		// D66: ffff drží cz-non-payer jen z původu (před F6b), aaaa má pole `dataset` cz-vat-payer.
		$r = $this->post('reseed', ['dataset' => self::SET, 'dataSource' => 'ffff-ffff-ffff-ffff']);
		$this->assertSame(409, $this->statusOf($r), json_encode($r->getPayload()));
		$this->assertSame('DATASET_MISMATCH', $this->error($r)['code']);
		$this->assertSame(['current' => 'cz-non-payer', 'requested' => self::SET], $this->error($r)['details']);
		$this->assertStringContainsString("holds dataset 'cz-non-payer'", $this->error($r)['message']);

		$r = $this->post('reseed', ['dataset' => 'cz-non-payer', 'dataSource' => 'aaaa-aaaa-aaaa-aaaa', 'replaceDataset' => 'yes']);
		$this->assertSame(409, $this->statusOf($r), 'příznak jen jako boolean true');
		$this->assertSame(self::SET, $this->error($r)['details']['current']);
		$this->assertSame([], $this->spawns, 'bez příznaku se nic nespustí');

		$r = $this->post('reseed', ['dataset' => self::SET, 'dataSource' => 'ffff-ffff-ffff-ffff', 'replaceDataset' => true]);
		$this->assertSame(201, $this->statusOf($r), json_encode($r->getPayload()));
		$job = $this->data($r)['job'];
		$this->assertSame(JobRecord::TYPE_RESEED, $job['type']);
		$this->assertSame(['replaceDataset' => true, 'previousDataset' => 'cz-non-payer'], $job['params']);
		$this->assertSame(['/usr/bin/php', '/repo/bin/shpd-ds', 'dataset-seed', $this->datasetDir, '--yes'], $job['steps'][0]['argv']);
		$this->assertCount(1, $this->spawns);

		// Stejná sada a zdroj dat bez sady: bez příznaku, params prázdné.
		$job = $this->data($this->post('reseed', ['dataset' => self::SET, 'dataSource' => 'cccc-cccc-cccc-cccc']))['job'];
		$this->assertSame([], $job['params']);

		// Pojistka třídy dat má přednost: reálná kopie s příznakem je dál 403.
		$r = $this->post('reseed', ['dataset' => 'cz-non-payer', 'dataSource' => 'bbbb-bbbb-bbbb-bbbb', 'replaceDataset' => true]);
		$this->assertSame(403, $this->statusOf($r));
		$this->assertSame('DS_NOT_FREE', $this->error($r)['code']);
		$this->assertCount(2, $this->spawns);
	}

	public function testVerifyCapturesStdoutAndHonoursApply(): void
	{
		$job = $this->data($this->post('verify', ['dataset' => self::SET, 'dataSource' => 'aaaa-aaaa-aaaa-aaaa', 'apply' => false]))['job'];
		$this->assertSame(JobRecord::TYPE_VERIFY, $job['type']);
		$this->assertSame(['/usr/bin/php', '/repo/bin/shpd-ds', 'dataset-verify', $this->datasetDir, '--json', '--no-apply'], $job['steps'][0]['argv']);
		$this->assertTrue($job['steps'][0]['captureStdout']);
		$this->assertSame(['apply' => false], $job['params']);

		$job = $this->data($this->post('verify', ['dataset' => self::SET, 'dataSource' => 'dddd-dddd-dddd-dddd']))['job'];
		$this->assertSame(['/usr/bin/php', '/repo/bin/shpd-ds', 'dataset-verify', $this->datasetDir, '--json'], $job['steps'][0]['argv']);
	}

	public function testCreateGeneratesTheIdAndSeedsInTwoSteps(): void
	{
		$title = (new EvalCatalog($this->tmpRoot))->dataset(self::SET)?->title;
		$r = $this->post('create', ['dataset' => self::SET]);
		$this->assertSame(201, $this->statusOf($r), json_encode($r->getPayload()));
		$job = $this->data($r)['job'];

		$this->assertSame(JobRecord::TYPE_CREATE, $job['type']);
		$this->assertSame('zzzz-zzzz-zzzz-zzzz', $job['dataSource']['id']);
		$this->assertSame('Evaluace — ' . $title, $job['dataSource']['name']);
		$this->assertCount(2, $job['steps']);
		$this->assertSame([
			'/usr/bin/php', '/repo/bin/shpd-server', 'ds-create',
			'--name', 'Evaluace — ' . $title,
			'--language', 'cs',
			'--country', 'cz',
			'--module', 'install.base',
			'--no-cron',
			'--ds-id', 'zzzz-zzzz-zzzz-zzzz',
			'--purpose', 'evaluace AI — ' . self::SET,
		], $job['steps'][0]['argv']);
		$this->assertSame($this->tmpRoot, $job['steps'][0]['cwd']);
		$this->assertSame(['/usr/bin/php', '/repo/bin/shpd-ds', 'dataset-seed', $this->datasetDir, '--yes'], $job['steps'][1]['argv']);
		$this->assertSame($this->dsDir . '/zzzz-zzzz-zzzz-zzzz', $job['steps'][1]['cwd']);
	}

	public function testEstimateRunsAiEvalSynchronouslyAndMapsItsOutcome(): void
	{
		$this->ctrl->captureResult['stdout'] = "Evaluace AI — …\n" . json_encode(['ok' => true, 'calls' => 4, 'estimate' => ['total' => 0.1, 'basis' => 'catalog']]) . "\n";
		$r = $this->post('estimate', ['dataset' => self::SET, 'dataSource' => 'aaaa-aaaa-aaaa-aaaa', 'variant' => 'baseline', 'repeat' => 2, 'only' => '0101, 0102']);
		$this->assertSame(200, $this->statusOf($r), json_encode($r->getPayload()));
		$this->assertSame(4, $this->data($r)['calls']);
		$this->assertSame('aaaa-aaaa-aaaa-aaaa', $this->data($r)['dataSource']);
		$this->assertCount(1, $this->ctrl->captures);
		$this->assertSame([
			'/usr/bin/php', '/repo/bin/shpd-ds', 'ai-eval', $this->datasetDir,
			'--variant', $this->tmpRoot . '/tests/eval/variants/baseline.jsonc',
			'--repeat', '2', '--only', '0101,0102', '--estimate', '--json',
		], $this->ctrl->captures[0]['argv']);
		$this->assertSame($this->dsDir . '/aaaa-aaaa-aaaa-aaaa', $this->ctrl->captures[0]['cwd']);
		$this->assertSame([], $this->spawns, 'odhad není úloha');

		$this->ctrl->captureResult = ['exitCode' => 2, 'termSignal' => null, 'timedOut' => false, 'stdout' => json_encode(['ok' => false, 'problems' => ['zdroj dat má 3 doklad(ů)']]) . "\n", 'stderr' => 'ai-eval odmítnuto'];
		$r = $this->post('estimate', ['dataset' => self::SET, 'dataSource' => 'aaaa-aaaa-aaaa-aaaa', 'variant' => 'baseline']);
		$this->assertSame(422, $this->statusOf($r));
		$this->assertSame('EVAL_PREFLIGHT', $this->error($r)['code']);
		$this->assertSame(['zdroj dat má 3 doklad(ů)'], $this->error($r)['details']['problems']);

		$this->ctrl->captureResult = ['exitCode' => null, 'termSignal' => 15, 'timedOut' => true, 'stdout' => '', 'stderr' => ''];
		$this->assertSame(504, $this->statusOf($this->post('estimate', ['dataset' => self::SET, 'dataSource' => 'aaaa-aaaa-aaaa-aaaa', 'variant' => 'baseline'])));

		$this->ctrl->captureResult = ['exitCode' => 1, 'termSignal' => null, 'timedOut' => false, 'stdout' => 'garbage', 'stderr' => 'PHP Fatal error'];
		$r = $this->post('estimate', ['dataset' => self::SET, 'dataSource' => 'aaaa-aaaa-aaaa-aaaa', 'variant' => 'baseline']);
		$this->assertSame(500, $this->statusOf($r));
		$this->assertSame('PHP Fatal error', $this->error($r)['details']['stderr']);

		$this->assertSame(404, $this->statusOf($this->post('estimate', ['dataset' => self::SET, 'dataSource' => 'aaaa-aaaa-aaaa-aaaa', 'variant' => 'nope'])));
		$this->assertSame(400, $this->statusOf($this->post('estimate', ['dataset' => self::SET, 'dataSource' => 'aaaa-aaaa-aaaa-aaaa', 'variant' => 'baseline', 'only' => ['01;rm -rf /']])));
		$this->assertSame(400, $this->statusOf($this->post('estimate', ['dataset' => self::SET, 'dataSource' => 'aaaa-aaaa-aaaa-aaaa', 'variant' => 'baseline', 'repeat' => 9])));
		$this->assertSame(400, $this->statusOf($this->post('estimate', ['dataset' => self::SET, 'dataSource' => 'aaaa-aaaa-aaaa-aaaa'])), 'varianta je povinná');
	}

	public function testRunRequiresTheSpendCapAndLaunchesTheEvalJob(): void
	{
		$r = $this->post('run', ['dataset' => self::SET, 'dataSource' => 'aaaa-aaaa-aaaa-aaaa', 'variant' => 'baseline']);
		$this->assertSame(400, $this->statusOf($r));
		$this->assertStringContainsString('maxCostUsd', $this->error($r)['message']);
		$this->assertSame(400, $this->statusOf($this->post('run', ['dataset' => self::SET, 'dataSource' => 'aaaa-aaaa-aaaa-aaaa', 'variant' => 'baseline', 'maxCostUsd' => 0])));
		$this->assertSame([], $this->spawns);

		$r = $this->post('run', ['dataset' => self::SET, 'dataSource' => 'aaaa-aaaa-aaaa-aaaa', 'variant' => 'baseline', 'repeat' => '3', 'only' => ['0101'], 'maxCostUsd' => 0.75]);
		$this->assertSame(201, $this->statusOf($r), json_encode($r->getPayload()));
		$job = $this->data($r)['job'];
		$this->assertSame(JobRecord::TYPE_EVAL, $job['type']);
		$this->assertSame('baseline', $job['variant']);
		$this->assertSame(['repeat' => 3, 'only' => ['0101'], 'maxCostUsd' => 0.75], $job['params']);
		$this->assertSame(900, $job['cancelGraceSeconds']);
		$this->assertTrue($job['steps'][0]['captureStdout']);
		$this->assertSame([
			'/usr/bin/php', '/repo/bin/shpd-ds', 'ai-eval', $this->datasetDir,
			'--variant', $this->tmpRoot . '/tests/eval/variants/baseline.jsonc',
			'--repeat', '3', '--only', '0101',
			'--yes', '--json', '--max-cost', '0.75', '--progress-file', $this->jobsDir . '/' . $job['id'] . '/progress.jsonl',
		], $job['steps'][0]['argv']);
		$this->assertCount(1, $this->spawns);
	}

	// ── Editor variant ───────────────────────────────────────────────────

	public function testVariantsListDetailSaveAndOptions(): void
	{
		$list = $this->data($this->ctrl->dispatch($this->request('GET', '/_dev/api/testing/eval/variants')));
		$this->assertSame('tests/eval/variants', $list['dir']);
		$this->assertSame(['baseline'], array_column($list['variants'], 'name'));
		$this->assertTrue($list['variants'][0]['valid']);

		$detail = $this->data($this->ctrl->dispatch($this->request('GET', '/_dev/api/testing/eval/variants/baseline')));
		$this->assertSame('baseline', $detail['variant']['name']);
		$this->assertSame('czech_general', $detail['variant']['profile']);
		$this->assertNull($detail['promptTemplatePreview']);
		$this->assertSame(404, $this->statusOf($this->ctrl->dispatch($this->request('GET', '/_dev/api/testing/eval/variants/nope'))));

		$r = $this->ctrl->dispatch($this->request('PUT', '/_dev/api/testing/eval/variants/haiku-from-api', [
			'description' => 'Z API', 'profile' => 'czech_general', 'call' => ['model' => 'claude-haiku-5-5'], 'contentTags' => ['model' => 'claude-haiku-5-5'],
		]));
		$this->assertSame(200, $this->statusOf($r), json_encode($r->getPayload()));
		$this->assertSame('haiku-from-api', $this->data($r)['variant']['name']);
		$this->assertSame('claude-haiku-5-5', $this->data($r)['variant']['call']['model']);
		$this->assertFileExists($this->tmpRoot . '/tests/eval/variants/haiku-from-api.jsonc');
		$this->assertFileDoesNotExist(dirname(__DIR__, 4) . '/tests/eval/variants/haiku-from-api.jsonc', 'do checkoutu se z testu nepíše');

		$r = $this->ctrl->dispatch($this->request('PUT', '/_dev/api/testing/eval/variants/haiku-from-api', ['profile' => 'czech_general']));
		$this->assertSame(409, $this->statusOf($r));
		$this->assertSame('VARIANT_EXISTS', $this->error($r)['code']);
		$r = $this->ctrl->dispatch($this->request('PUT', '/_dev/api/testing/eval/variants/haiku-from-api', ['profile' => 'czech_general', 'description' => 'Přepsaná', 'overwrite' => true]));
		$this->assertSame(200, $this->statusOf($r));
		$this->assertSame('Přepsaná', $this->data($r)['variant']['description']);

		$r = $this->ctrl->dispatch($this->request('PUT', '/_dev/api/testing/eval/variants/Bad Name', ['profile' => 'czech_general']));
		$this->assertSame(422, $this->statusOf($r));
		$r = $this->ctrl->dispatch($this->request('PUT', '/_dev/api/testing/eval/variants/no-profile', ['description' => 'x']));
		$this->assertSame(422, $this->statusOf($r));
		$this->assertSame(403, $this->statusOf($this->controller('production')->dispatch($this->request('PUT', '/_dev/api/testing/eval/variants/prod', ['profile' => 'czech_general']))));

		$r = $this->ctrl->dispatch($this->request('GET', '/_dev/api/testing/eval/variants/options?dataSource=aaaa-aaaa-aaaa-aaaa'));
		$this->assertSame(200, $this->statusOf($r));
		$this->assertSame('anthropic', $this->data($r)['platform']);
		$this->assertSame('aaaa-aaaa-aaaa-aaaa', $this->data($r)['dataSource']);
		$this->assertSame(403, $this->statusOf($this->ctrl->dispatch($this->request('GET', '/_dev/api/testing/eval/variants/options?dataSource=bbbb-bbbb-bbbb-bbbb'))));
		$this->assertSame(404, $this->statusOf($this->ctrl->dispatch($this->request('GET', '/_dev/api/testing/eval/variants/options'))));
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
			is_dir($path) && !is_link($path) ? $this->rrmdir($path) : @unlink($path);
		}
		@rmdir($dir);
	}
}

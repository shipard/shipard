<?php
declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\Controller\DevJobsController;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\DevJobs\JobLauncher;
use Shipard\Core\DevJobs\JobRecord;
use Shipard\Core\DevJobs\JobState;
use Shipard\Core\DevJobs\JobStatus;
use Shipard\Core\DevJobs\JobStep;
use Shipard\Core\DevJobs\JobStore;

/**
 * API úloh (D58): seznam s filtry, detail s průběhem od offsetu, výstupem
 * a výsledkem (u evaluace jen souhrn), zrušení vč. force, 404 / 409.
 */
final class DevJobsControllerTest extends TestCase
{
	private string $dir;
	private bool $alive = true;
	/** @var list<array{0: int, 1: int}> */
	private array $kills = [];
	private JobStore $store;
	private DevJobsController $ctrl;

	protected function setUp(): void
	{
		$this->dir = sys_get_temp_dir() . '/shpd_jobs_api_' . bin2hex(random_bytes(6));
		$this->store = new JobStore(
			$this->dir,
			fn (): bool => $this->alive,
			static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-10-16T12:00:00Z'),
		);
		$launcher = new JobLauncher(
			$this->store,
			static fn (): bool => true,
			function (int $pid, int $signal): bool {
				$this->kills[] = [$pid, $signal];
				if ($signal === JobLauncher::SIGKILL) {
					$this->alive = false;
				}
				return true;
			},
			['/usr/bin/php', '/repo/bin/shpd-server'],
		);
		$this->ctrl = new DevJobsController($this->store, $launcher);
	}

	protected function tearDown(): void
	{
		$this->rrmdir($this->dir);
	}

	private function job(string $id, string $type = JobRecord::TYPE_VERIFY, string $ds = 'aaaa-bbbb-cccc-dddd', string $dataset = 'cz-vat-payer'): JobRecord
	{
		$record = new JobRecord($id, $type, $ds, 'DS', $dataset, null, [], [new JobStep('step', ['true'], '/tmp')], '2026-10-16T11:59:00Z', '10.0.0.5', 60);
		$this->store->create($record);
		return $record;
	}

	private function running(string $id, int $pid = 4242): void
	{
		$state = JobState::pending(1);
		$state->pid = $pid;
		$state->steps[0]['status'] = JobState::STEP_RUNNING;
		$this->store->writeState($id, $state);
	}

	private function request(string $method, string $uri, string $body = ''): Request
	{
		$query = [];
		$qs = parse_url($uri, PHP_URL_QUERY);
		if (is_string($qs) && $qs !== '') {
			parse_str($qs, $query);
		}
		return Request::fromArray($method, $uri, $query, $body, ['REMOTE_ADDR' => '10.0.0.5']);
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

	public function testListIsEmptyAndFilters(): void
	{
		$r = $this->ctrl->dispatch($this->request('GET', '/_dev/api/testing/jobs'));
		$this->assertSame(200, $this->statusOf($r));
		$this->assertSame([], $this->data($r)['jobs']);

		$this->job('20261016-115900-aaaaaa', JobRecord::TYPE_VERIFY, 'aaaa-bbbb-cccc-dddd', 'set-x');
		$this->job('20261016-115901-bbbbbb', JobRecord::TYPE_EVAL, 'eeee-ffff-gggg-hhhh', 'set-y');

		$all = $this->data($this->ctrl->dispatch($this->request('GET', '/_dev/api/testing/jobs')))['jobs'];
		$this->assertSame(['20261016-115901-bbbbbb', '20261016-115900-aaaaaa'], array_column($all, 'id'));
		$this->assertSame(JobStatus::STARTING, $all[0]['status']);

		$byDataset = $this->data($this->ctrl->dispatch($this->request('GET', '/_dev/api/testing/jobs?dataset=set-x')))['jobs'];
		$this->assertSame(['20261016-115900-aaaaaa'], array_column($byDataset, 'id'));
		$byDs = $this->data($this->ctrl->dispatch($this->request('GET', '/_dev/api/testing/jobs?ds=eeee-ffff-gggg-hhhh')))['jobs'];
		$this->assertSame(['20261016-115901-bbbbbb'], array_column($byDs, 'id'));
	}

	public function testDetailRejectsUnknownAndInvalidIds(): void
	{
		$this->assertSame(404, $this->statusOf($this->ctrl->dispatch($this->request('GET', '/_dev/api/testing/jobs/20261016-115900-zzzzzz'))));
		$this->assertSame(404, $this->statusOf($this->ctrl->dispatch($this->request('GET', '/_dev/api/testing/jobs/..%2Fetc'))));
		$this->assertSame(404, $this->statusOf($this->ctrl->dispatch($this->request('POST', '/_dev/api/testing/jobs'))));
		$this->job('20261016-115900-aaaaaa');
		$this->assertSame(404, $this->statusOf($this->ctrl->dispatch($this->request('GET', '/_dev/api/testing/jobs/20261016-115900-aaaaaa/cancel'))), 'cancel is POST only');
	}

	public function testDetailReturnsProgressTailAndEvalSummary(): void
	{
		$this->job('20261016-115900-aaaaaa', JobRecord::TYPE_EVAL);
		$this->running('20261016-115900-aaaaaa');
		$line1 = json_encode(['event' => 'started', 'calls' => 4]) . "\n";
		$line2 = json_encode(['event' => 'call', 'messageId' => 'M1', 'costUsd' => 0.02]) . "\n";
		file_put_contents($this->store->path('20261016-115900-aaaaaa', JobStore::PROGRESS_FILE), $line1 . $line2 . '{"partial":');
		file_put_contents($this->store->path('20261016-115900-aaaaaa', JobStore::OUTPUT_FILE), "[STEP] ai-eval\nEvaluace AI — sada\n");
		file_put_contents($this->store->path('20261016-115900-aaaaaa', JobStore::RESULT_FILE), json_encode([
			'file' => '/repo/tests/eval/cz-vat-payer/2026-10-16-baseline.json',
			'set' => ['name' => 'cz-vat-payer', 'hash' => 'abc'],
			'stoppedBy' => 'maxCost',
			'plannedCalls' => 4,
			'metrics' => ['calls.total' => 2, 'cost.usd' => 0.1, 'fields.all.hit' => 0.9, 'tokens.input' => 5],
			'calls' => [['messageId' => 'M1']],
		]));

		$r = $this->ctrl->dispatch($this->request('GET', '/_dev/api/testing/jobs/20261016-115900-aaaaaa?offset=0&tail=1'));
		$this->assertSame(200, $this->statusOf($r));
		$data = $this->data($r);
		$this->assertSame(JobStatus::RUNNING, $data['job']['status']);
		$this->assertSame(4242, $data['job']['pid']);
		$this->assertSame(['started', 'call'], array_column($data['progress']['events'], 'event'));
		$this->assertSame(strlen($line1 . $line2), $data['progress']['nextOffset']);
		$this->assertSame('Evaluace AI — sada', $data['outputTail']);
		$this->assertSame('2026-10-16-baseline.json', $data['result']['file']);
		$this->assertSame('cz-vat-payer', $data['result']['set']);
		$this->assertSame('maxCost', $data['result']['stoppedBy']);
		$this->assertSame(4, $data['result']['plannedCalls']);
		$this->assertSame(['calls.total' => 2, 'cost.usd' => 0.1, 'fields.all.hit' => 0.9], $data['result']['metrics']);
		$this->assertArrayNotHasKey('calls', $data['result']);

		$next = $this->data($this->ctrl->dispatch($this->request('GET', '/_dev/api/testing/jobs/20261016-115900-aaaaaa?offset=' . $data['progress']['nextOffset'])));
		$this->assertSame([], $next['progress']['events']);
	}

	public function testDetailPassesVerifyResultThrough(): void
	{
		$this->job('20261016-115900-aaaaaa', JobRecord::TYPE_VERIFY);
		$report = ['dataset' => ['name' => 'cz-vat-payer'], 'checks' => [['id' => 'ledger', 'status' => 'ok']], 'summary' => ['ok' => 1]];
		file_put_contents($this->store->path('20261016-115900-aaaaaa', JobStore::RESULT_FILE), json_encode($report));

		$data = $this->data($this->ctrl->dispatch($this->request('GET', '/_dev/api/testing/jobs/20261016-115900-aaaaaa')));

		$this->assertSame($report, $data['result']);
		$this->assertSame('', $data['outputTail']);
	}

	public function testFileServesOnlyTracesNamedByTheReportOfAnE2eJob(): void
	{
		$id = '20261016-115900-aaaaaa';
		$this->job($id, JobRecord::TYPE_E2E);
		$dir = $this->store->jobDir($id) . '/' . DevJobsController::E2E_DIR . '/' . DevJobsController::E2E_TRACES_DIR;
		mkdir($dir, 0777, true);
		file_put_contents($dir . '/trace-mail_suggestion_16.zip', 'PK-zip');
		file_put_contents($dir . '/other.zip', 'PK-other');
		file_put_contents($this->store->path($id, JobStore::RESULT_FILE), json_encode([
			'status' => 'failed',
			'artifacts' => ['traces' => [['cardId' => 'mail_suggestion:16', 'file' => 'trace-mail_suggestion_16.zip']]],
		]));

		$r = $this->ctrl->dispatch($this->request('GET', "/_dev/api/testing/jobs/{$id}/files/trace-mail_suggestion_16.zip"));
		$this->assertSame(200, $this->statusOf($r));
		$this->assertSame('PK-zip', $r->getPayload());
		$headers = $r->getHeaders();
		$this->assertSame('application/zip', $headers['Content-Type']);
		$this->assertSame('attachment; filename="trace-mail_suggestion_16.zip"', $headers['Content-Disposition']);
		$this->assertSame('no-store', $headers['Cache-Control']);

		$this->assertSame(404, $this->statusOf($this->ctrl->dispatch($this->request('GET', "/_dev/api/testing/jobs/{$id}/files/other.zip"))), 'soubor, který report nejmenuje');
		$this->assertSame(404, $this->statusOf($this->ctrl->dispatch($this->request('GET', "/_dev/api/testing/jobs/{$id}/files/..%2Fjob.json"))));
		$this->assertSame(404, $this->statusOf($this->ctrl->dispatch($this->request('GET', "/_dev/api/testing/jobs/{$id}/files/job.json"))));
		$this->assertSame(404, $this->statusOf($this->ctrl->dispatch($this->request('POST', "/_dev/api/testing/jobs/{$id}/files/trace-mail_suggestion_16.zip"))));

		$other = '20261016-115901-bbbbbb';
		$this->job($other, JobRecord::TYPE_VERIFY);
		$otherDir = $this->store->jobDir($other) . '/e2e/traces';
		mkdir($otherDir, 0777, true);
		file_put_contents($otherDir . '/t.zip', 'x');
		file_put_contents($this->store->path($other, JobStore::RESULT_FILE), json_encode(['artifacts' => ['traces' => [['file' => 't.zip']]]]));
		$this->assertSame(404, $this->statusOf($this->ctrl->dispatch($this->request('GET', "/_dev/api/testing/jobs/{$other}/files/t.zip"))), 'soubory jen u úlohy e2e');
	}

	public function testCancelRequiresActiveJobWithPid(): void
	{
		$this->job('20261016-115900-aaaaaa');
		$r = $this->ctrl->dispatch($this->request('POST', '/_dev/api/testing/jobs/20261016-115900-aaaaaa/cancel'));
		$this->assertSame(409, $this->statusOf($r));
		$this->assertSame('JOB_NOT_ACTIVE', $r->getPayload()['error']['code']);
		$this->assertSame([], $this->kills);

		$this->assertSame(404, $this->statusOf($this->ctrl->dispatch($this->request('POST', '/_dev/api/testing/jobs/20261016-115900-zzzzzz/cancel'))));
	}

	public function testCancelSignalsGroupAndForceKills(): void
	{
		$this->job('20261016-115900-aaaaaa');
		$this->running('20261016-115900-aaaaaa');

		$r = $this->ctrl->dispatch($this->request('POST', '/_dev/api/testing/jobs/20261016-115900-aaaaaa/cancel'));
		$this->assertSame(200, $this->statusOf($r));
		$this->assertSame(JobStatus::CANCELLING, $this->data($r)['job']['status']);
		$this->assertSame([[-4242, 15]], $this->kills);

		$r = $this->ctrl->dispatch($this->request('POST', '/_dev/api/testing/jobs/20261016-115900-aaaaaa/cancel?force=1'));
		$this->assertSame(200, $this->statusOf($r));
		$this->assertSame(JobStatus::CANCELLED, $this->data($r)['job']['status']);
		$this->assertTrue($this->data($r)['job']['forced']);
		$this->assertSame([[-4242, 15], [-4242, 9]], $this->kills);
	}

	public function testUpdateJobCannotBeCancelledOrForced(): void
	{
		$this->job('20261016-115900-aaaaaa', JobRecord::TYPE_UPDATE, '', '');
		$this->running('20261016-115900-aaaaaa');

		foreach (['/_dev/api/testing/jobs/20261016-115900-aaaaaa/cancel', '/_dev/api/testing/jobs/20261016-115900-aaaaaa/cancel?force=1'] as $uri) {
			$r = $this->ctrl->dispatch($this->request('POST', $uri));
			$this->assertSame(409, $this->statusOf($r), $uri);
			$this->assertSame('JOB_NOT_CANCELLABLE', $r->getPayload()['error']['code']);
		}
		$this->assertSame([], $this->kills, 'no signal was sent');

		$detail = $this->data($this->ctrl->dispatch($this->request('GET', '/_dev/api/testing/jobs/20261016-115900-aaaaaa')));
		$this->assertNull($detail['job']['dataSource']);
		$this->assertSame(JobStatus::RUNNING, $detail['job']['status']);
		$this->assertCount(1, $this->data($this->ctrl->dispatch($this->request('GET', '/_dev/api/testing/jobs')))['jobs']);
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
			is_dir($path) ? $this->rrmdir($path) : @unlink($path);
		}
		@rmdir($dir);
	}
}

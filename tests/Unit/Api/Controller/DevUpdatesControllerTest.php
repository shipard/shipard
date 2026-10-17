<?php
declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\Controller\DevUpdatesController;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\DevJobs\JobLauncher;
use Shipard\Core\DevJobs\JobRecord;
use Shipard\Core\DevJobs\JobState;
use Shipard\Core\DevJobs\JobStatus;
use Shipard\Core\DevJobs\JobStep;
use Shipard\Core\DevJobs\JobStore;
use Shipard\Core\DevUpdate\Git;
use Shipard\Core\DevUpdate\UpdateChecker;
use Shipard\Core\DevUpdate\UpdateStateStore;
use Shipard\Core\DevUpdate\UpdateStatus;
use Shipard\Tests\Fixtures\Core\DevUpdate\GitFixture;

/**
 * API aktualizací (#129 D18–D21, U1–U3): GET čte cache a při neplatné
 * spustí odpojenou kontrolu (ne když běží nebo když běží úloha `update`),
 * POST check vždy, POST run = pojistky na serveru → úloha `update` bez
 * zdroje dat, 409 / 403.
 */
final class DevUpdatesControllerTest extends TestCase
{
	private GitFixture $fx;
	private UpdateStateStore $state;
	private JobStore $jobs;
	private Git $git;
	private \DateTimeImmutable $now;
	/** @var list<array{argv: list<string>, cwd: string, log: string}> odpojené kontroly */
	private array $checks = [];
	/** @var list<array{argv: list<string>, cwd: string, log: string}> spawny obalu úloh */
	private array $spawns = [];
	private bool $spawnResult = true;

	protected function setUp(): void
	{
		if (!GitFixture::available()) {
			$this->markTestSkipped('git is not available');
		}
		$this->fx = GitFixture::create();
		$this->now = new \DateTimeImmutable('2026-10-17T12:00:00Z');
		$this->state = new UpdateStateStore($this->fx->base . '/run/dev-update', fn (): \DateTimeImmutable => $this->now);
		$this->jobs = new JobStore($this->fx->base . '/run/dev-jobs', static fn (): bool => true, fn (): \DateTimeImmutable => $this->now);
		$this->git = new Git($this->fx->checkout, $this->fx->env());
	}

	protected function tearDown(): void
	{
		if (isset($this->fx)) {
			$this->fx->cleanup();
		}
	}

	private function controller(string $mode = 'development'): DevUpdatesController
	{
		$launcher = new JobLauncher(
			$this->jobs,
			function (array $argv, string $cwd, string $log): bool {
				$this->spawns[] = ['argv' => $argv, 'cwd' => $cwd, 'log' => $log];
				return true;
			},
			static fn (): bool => true,
			['/usr/bin/php', '/repo/bin/shpd-server'],
		);
		$status = new UpdateStatus($this->git, $this->state, $this->jobs, $this->fx->bin . ':/usr/bin:/bin');
		return new DevUpdatesController(
			$mode,
			$this->fx->checkout,
			$this->jobs,
			$launcher,
			$status,
			['/usr/bin/php', '/repo/bin/shpd-server'],
			function (array $argv, string $cwd, string $log): bool {
				$this->checks[] = ['argv' => $argv, 'cwd' => $cwd, 'log' => $log];
				return $this->spawnResult;
			},
			fn (): \DateTimeImmutable => $this->now,
		);
	}

	private function request(string $method, string $path): Request
	{
		return Request::fromArray($method, $path, [], '', ['REMOTE_ADDR' => '10.0.0.5']);
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

	private function freshCheck(): void
	{
		(new UpdateChecker($this->git, $this->state, $this->fx->upstream))->check();
	}

	private function runningJob(string $id, string $type = JobRecord::TYPE_RESEED, string $ds = 'aaaa-bbbb-cccc-dddd'): void
	{
		$this->jobs->ensureDir();
		$this->jobs->create(new JobRecord($id, $type, $ds, $ds === '' ? '' : 'DS', null, null, [], [new JobStep('x', ['true'], '/tmp')], '2026-10-17T11:58:00Z', null, 60));
		$state = JobState::pending(1);
		$state->status = JobStatus::RUNNING;
		$state->pid = 4242;
		$this->jobs->writeState($id, $state);
	}

	public function testGetWithoutCacheStartsADetachedCheck(): void
	{
		$r = $this->controller()->dispatch($this->request('GET', '/_dev/api/updates'));

		$this->assertSame(200, $this->statusOf($r));
		$d = $this->data($r);
		$this->assertNull($d['state']);
		$this->assertTrue($d['stale']);
		$this->assertTrue($d['checking']);
		$this->assertSame('stable', $d['head']['branch']);
		$this->assertSame(['notChecked'], array_column($d['blockers'], 'code'));
		$this->assertFalse($d['canUpdate']);
		$this->assertNull($d['job']);
		$this->assertCount(1, $this->checks);
		$this->assertSame(['/usr/bin/php', '/repo/bin/shpd-server', 'dev-update-check'], $this->checks[0]['argv']);
		$this->assertSame($this->fx->checkout, $this->checks[0]['cwd']);
		$this->assertSame($this->state->path('check.log'), $this->checks[0]['log']);
		$this->assertTrue($this->state->isChecking(), 'request mark written');

		// Druhý dotaz, dokud značka platí: žádný další spawn.
		$r = $this->controller()->dispatch($this->request('GET', '/_dev/api/updates'));
		$this->assertTrue($this->data($r)['checking']);
		$this->assertCount(1, $this->checks);
	}

	public function testGetWithFreshCacheDoesNotStartAnything(): void
	{
		$this->fx->commitUpstream('new');
		$this->freshCheck();

		$d = $this->data($this->controller()->dispatch($this->request('GET', '/_dev/api/updates')));

		$this->assertSame(1, $d['state']['behind']);
		$this->assertFalse($d['stale']);
		$this->assertFalse($d['checking']);
		$this->assertSame([], $d['blockers']);
		$this->assertTrue($d['canUpdate']);
		$this->assertSame([], $this->checks);
	}

	public function testGetDoesNotStartACheckWhileOneRunsOrWhileUpdating(): void
	{
		$handle = $this->state->tryLock();
		$d = $this->data($this->controller()->dispatch($this->request('GET', '/_dev/api/updates')));
		$this->assertTrue($d['checking']);
		$this->assertSame([], $this->checks);
		$this->state->unlock($handle);

		$this->runningJob('20261017-115900-uuuuuu', JobRecord::TYPE_UPDATE, '');
		$d = $this->data($this->controller()->dispatch($this->request('GET', '/_dev/api/updates')));
		$this->assertSame('20261017-115900-uuuuuu', $d['job']['id']);
		$this->assertNull($d['job']['dataSource']);
		$this->assertFalse($d['checking']);
		$this->assertSame([], $this->checks, 'the update re-checks itself at the end');
	}

	public function testFailedSpawnReportsNotChecking(): void
	{
		$this->spawnResult = false;
		$d = $this->data($this->controller()->dispatch($this->request('GET', '/_dev/api/updates')));
		$this->assertFalse($d['checking']);
		$this->assertFalse($this->state->isChecking());
	}

	public function testPostCheckRunsRegardlessOfCacheAge(): void
	{
		$this->freshCheck();

		$r = $this->controller()->dispatch($this->request('POST', '/_dev/api/updates/check'));

		$this->assertSame(202, $this->statusOf($r));
		$this->assertTrue($this->data($r)['checking']);
		$this->assertCount(1, $this->checks);

		$r = $this->controller()->dispatch($this->request('POST', '/_dev/api/updates/check'));
		$this->assertSame(202, $this->statusOf($r));
		$this->assertCount(1, $this->checks, 'already running = no duplicate');
	}

	public function testRunLaunchesTheUpdateJobWithoutADataSource(): void
	{
		$this->fx->commitUpstream('new');
		$this->freshCheck();

		$r = $this->controller()->dispatch($this->request('POST', '/_dev/api/updates/run'));

		$this->assertSame(201, $this->statusOf($r));
		$job = $this->data($r)['job'];
		$this->assertSame(JobRecord::TYPE_UPDATE, $job['type']);
		$this->assertNull($job['dataSource']);
		$this->assertSame('starting', $job['status']);
		$this->assertSame(1, $job['params']['behind']);
		$this->assertSame(['/usr/bin/php', '/repo/bin/shpd-server', 'dev-update', '--yes', '--job', $job['id']], $job['steps'][0]['argv'], '--job = own job for the jobRunning safeguard');
		$this->assertSame($this->fx->checkout, $job['steps'][0]['cwd']);
		$this->assertSame('10.0.0.5', $job['clientIp']);
		$this->assertCount(1, $this->spawns);
		$this->assertSame('dev-job-run', $this->spawns[0]['argv'][2]);
		$this->assertSame(JobRecord::TYPE_UPDATE, $this->jobs->read($job['id'])?->type);

		// Teď GET ukáže běžící úlohu a nic nespouští.
		$d = $this->data($this->controller()->dispatch($this->request('GET', '/_dev/api/updates')));
		$this->assertSame($job['id'], $d['job']['id']);
		$this->assertSame(['jobRunning'], array_column($d['blockers'], 'code'));
		$this->assertSame([], $this->checks);
	}

	public function testRunIsBlockedByTheSafeguards(): void
	{
		$this->fx->commitUpstream('new');
		$this->freshCheck();
		file_put_contents($this->fx->checkout . '/file-1.txt', 'edited');

		$r = $this->controller()->dispatch($this->request('POST', '/_dev/api/updates/run'));

		$this->assertSame(409, $this->statusOf($r));
		$this->assertSame('UPDATE_BLOCKED', $this->error($r)['code']);
		$this->assertSame(['dirty'], array_column($this->error($r)['details']['blockers'], 'code'));
		$this->assertSame([], $this->spawns);
		$this->assertSame([], $this->jobs->activeJobs());
	}

	public function testRunIsBlockedByARunningJobOnAnyDataSource(): void
	{
		$this->fx->commitUpstream('new');
		$this->freshCheck();
		$this->runningJob('20261017-115900-aaaaaa');

		$r = $this->controller()->dispatch($this->request('POST', '/_dev/api/updates/run'));

		$this->assertSame(409, $this->statusOf($r));
		$this->assertSame('UPDATE_BLOCKED', $this->error($r)['code']);
		$blocker = $this->error($r)['details']['blockers'][0];
		$this->assertSame(['code' => 'jobRunning', 'jobId' => '20261017-115900-aaaaaa', 'type' => 'reseed'], $blocker);
		$this->assertSame([], $this->spawns);
	}

	public function testRunRefusedOutsideDevelopment(): void
	{
		$this->fx->commitUpstream('new');
		$this->freshCheck();
		foreach (['production', 'unknown'] as $mode) {
			$r = $this->controller($mode)->dispatch($this->request('POST', '/_dev/api/updates/run'));
			$this->assertSame(403, $this->statusOf($r), $mode);
			$this->assertSame('FORBIDDEN', $this->error($r)['code']);
		}
		$this->assertSame([], $this->spawns);
	}

	public function testUnknownRoutesAre404(): void
	{
		$ctrl = $this->controller();
		foreach ([['GET', '/_dev/api/updates/run'], ['POST', '/_dev/api/updates'], ['GET', '/_dev/api/updates/x'], ['DELETE', '/_dev/api/updates/check']] as [$method, $path]) {
			$this->assertSame(404, $this->statusOf($ctrl->dispatch($this->request($method, $path))), "{$method} {$path}");
		}
		$this->assertSame([], $this->checks);
	}
}

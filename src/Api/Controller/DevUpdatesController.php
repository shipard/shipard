<?php
declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\DevJobs\DevActionGuard;
use Shipard\Core\DevJobs\JobConflict;
use Shipard\Core\DevJobs\JobLauncher;
use Shipard\Core\DevJobs\JobRecord;
use Shipard\Core\DevJobs\JobStep;
use Shipard\Core\DevJobs\JobStore;
use Shipard\Core\DevUpdate\UpdateStateStore;
use Shipard\Core\DevUpdate\UpdateStatus;
use Shipard\Core\Process\DetachedProcess;

/**
 * Zastaralost checkoutu a Aktualizovat ve vývojářském dashboardu
 * (`/_dev/api/updates…`, tasks/dev-dashboard-update.md § 4, #129 D18–D21).
 * Volá ho `DevDashboardController` — platí stejná ochrana (mód development,
 * důvěryhodná síť); spuštění aktualizace navíc hlídá {@see DevActionGuard}.
 *
 * | metoda | cesta             | odpověď                                                              |
 * |--------|-------------------|----------------------------------------------------------------------|
 * | GET    | `updates`         | `{state, stale, checking, head, blockers, canUpdate, job}`; je-li cache |
 * |        |                   | neplatná a kontrola neběží, spustí odpojený `dev-update-check` (U3) — |
 * |        |                   | po selhané kontrole až za 10 minut (`RETRY_AFTER_FAILURE_SECONDS`)    |
 * | POST   | `updates/check`   | 202 `{checking: true}` — kontrola teď, bez ohledu na stáří cache      |
 * | POST   | `updates/run`     | 201 `{job}` úloha `update`; 409 `UPDATE_BLOCKED {blockers}` /         |
 * |        |                   | `JOB_RUNNING`; 403 mimo development                                   |
 *
 * Odpověď nikdy nečeká na síť: fetch dělá jen odpojený proces, API čte
 * cache a počítá pojistky ({@see UpdateStatus::snapshot()}).
 */
final class DevUpdatesController
{
	public const string PREFIX = '/_dev/api/updates';
	/** Zrušení úlohy `update` API odmítá (U2); hodnota jen pro úplnost záznamu. */
	public const int UPDATE_CANCEL_GRACE_SECONDS = 60;

	/**
	 * @param list<string> $serverCommand argv prefix `shpd-server`
	 * @param \Closure(list<string> $argv, string $cwd, string $logFile): bool|null $spawn test seam; výchozí {@see DetachedProcess::spawn()}
	 * @param \Closure(): \DateTimeImmutable|null $now
	 */
	public function __construct(
		private readonly string $serverMode,
		private readonly string $repoRoot,
		private readonly JobStore $store,
		private readonly JobLauncher $launcher,
		private readonly UpdateStatus $status,
		private readonly array $serverCommand,
		private readonly ?\Closure $spawn = null,
		private readonly ?\Closure $now = null,
	) {}

	public function dispatch(Request $request): Response
	{
		$path   = $request->getPath();
		$method = $request->getMethod();
		if ($path === self::PREFIX && $method === 'GET') {
			return $this->status();
		}
		if ($path === self::PREFIX . '/check' && $method === 'POST') {
			return $this->check();
		}
		if ($path === self::PREFIX . '/run' && $method === 'POST') {
			return $this->run($request);
		}
		return Response::error('NOT_FOUND', 'Not found', 404);
	}

	/**
	 * Stav z cache + pojistky teď + běžící úloha; neplatná cache bez běžící
	 * kontroly → kontrola na pozadí. Po selhané kontrole (bez sítě) až za
	 * {@see UpdateStateStore::RETRY_AFTER_FAILURE_SECONDS} — `stale` a pojistka
	 * `notChecked` platí dál, jen se nespouští proces při každém dotazu.
	 */
	private function status(): Response
	{
		$snapshot = $this->status->snapshot();
		$job = $this->activeUpdateJob();
		$due = $this->status->store()->isAutoCheckDue($snapshot['state'], $snapshot['head']['hash']);
		if ($due && !$snapshot['checking'] && $job === null) {
			$snapshot['checking'] = $this->startCheck();
		}
		return Response::success($snapshot + ['job' => $job]);
	}

	private function check(): Response
	{
		$checking = $this->status->store()->isChecking() || $this->startCheck();
		return Response::success(['checking' => $checking], 202);
	}

	private function run(Request $request): Response
	{
		$problem = DevActionGuard::check($this->serverMode, null, false, null, []);
		if ($problem !== null) {
			return Response::error($problem['code'], $problem['message'], $problem['status']);
		}
		$snapshot = $this->status->snapshot();
		if ($snapshot['blockers'] !== []) {
			return Response::error(
				'UPDATE_BLOCKED',
				'The update cannot start: ' . implode(', ', array_column($snapshot['blockers'], 'code')),
				409,
				['blockers' => $snapshot['blockers']],
			);
		}
		$now = $this->now !== null ? ($this->now)() : new \DateTimeImmutable();
		// ID úlohy dopředu: dev-update ho dostane v --job, aby vlastní běžící
		// úlohu nepovažoval za cizí (pojistka jobRunning).
		$jobId = JobRecord::newId($now);
		$record = new JobRecord(
			$jobId,
			JobRecord::TYPE_UPDATE,
			'',
			'',
			null,
			null,
			['behind' => $snapshot['state']['behind'] ?? null, 'upstreamHash' => $snapshot['state']['upstream']['hash'] ?? null],
			[new JobStep('dev-update', [...$this->serverCommand, 'dev-update', '--yes', '--job', $jobId], $this->repoRoot)],
			JobStore::formatTime($now),
			$request->getClientIp(),
			self::UPDATE_CANCEL_GRACE_SECONDS,
		);
		try {
			return Response::success(['job' => $this->launcher->launch($record)], 201);
		} catch (JobConflict $e) {
			return Response::error('JOB_RUNNING', $e->getMessage(), 409, ['jobId' => $e->runningJobId, 'dataSource' => $e->dataSourceId]);
		} catch (\RuntimeException $e) {
			return Response::error('JOB_LAUNCH_FAILED', $e->getMessage(), 500);
		}
	}

	/** @return array<string, mixed>|null */
	private function activeUpdateJob(): ?array
	{
		foreach ($this->store->activeJobs() as $job) {
			if (($job['type'] ?? null) === JobRecord::TYPE_UPDATE) {
				return $job;
			}
		}
		return null;
	}

	/** Odpojený `shpd-server dev-update-check` + značka požadavku; false = nešel spustit. */
	private function startCheck(): bool
	{
		$store = $this->status->store();
		if (!$store->ensureDir()) {
			return false;
		}
		$log = $store->path(UpdateStateStore::CHECK_LOG);
		@unlink($log);
		$spawn = $this->spawn ?? static fn (array $argv, string $cwd, string $logFile): bool => DetachedProcess::spawn($argv, $cwd, $logFile);
		if (!$spawn([...$this->serverCommand, 'dev-update-check'], $this->repoRoot, $log)) {
			return false;
		}
		$store->markRequested();
		return true;
	}
}

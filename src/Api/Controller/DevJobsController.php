<?php
declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\DevJobs\JobLauncher;
use Shipard\Core\DevJobs\JobRecord;
use Shipard\Core\DevJobs\JobStore;
use Shipard\Module\Core\Exchange\Dataset\Jobs\DatasetJobRecipes;

/**
 * API úloh na pozadí vývojářského dashboardu (`/_dev/api/testing/jobs…`,
 * tasks/testing-phase6b.md § 1, D58). Úlohy zakládají akce
 * `DevEvalController`; tady je seznam, detail pro polling a zrušení. Volá
 * ho `DevDashboardController` — platí stejná ochrana (mód development,
 * důvěryhodná síť). Id úlohy z URL se ověřuje proti
 * {@see JobRecord::ID_REGEX}, než se z něj skládá cesta.
 *
 * | metoda | cesta                      | odpověď                                                      |
 * |--------|----------------------------|--------------------------------------------------------------|
 * | GET    | `jobs?dataset=&ds=&days=&type=&parent=` | `{jobs[]}` nejnovější první (před výpisem úklid po retenci); `type` = typ úlohy, `parent` = dílčí úlohy kola |
 * | GET    | `jobs/{id}?offset=&tail=`  | `{job, progress{events,nextOffset}, outputTail, result}`      |
 * | POST   | `jobs/{id}/cancel?force=1` | `{job}` po zrušení; 409 `JOB_NOT_ACTIVE` když není co rušit, |
 * |        |                            | 409 `JOB_NOT_CANCELLABLE` pro typ `update` (#129 U2)          |
 */
final class DevJobsController
{
	public const string PREFIX = '/_dev/api/testing/jobs';
	private const int MAX_TAIL_LINES = 1000;
	private const int MAX_DAYS = 365;

	public function __construct(
		private readonly JobStore $store,
		private readonly JobLauncher $launcher,
	) {}

	public function dispatch(Request $request): Response
	{
		$path   = $request->getPath();
		$method = $request->getMethod();
		if ($path !== self::PREFIX && !str_starts_with($path, self::PREFIX . '/')) {
			return $this->notFound('Not found');
		}
		$segments = array_values(array_filter(
			array_map(rawurldecode(...), explode('/', substr($path, strlen(self::PREFIX)))),
			static fn (string $s): bool => $s !== '',
		));
		if ($segments === []) {
			return $method === 'GET' ? $this->list($request) : $this->notFound('Not found');
		}
		$id = $segments[0];
		if (!JobStore::isValidId($id)) {
			return $this->notFound('Job not found');
		}
		if (count($segments) === 1 && $method === 'GET') {
			return $this->detail($id, $request);
		}
		if (count($segments) === 2 && $segments[1] === 'cancel' && $method === 'POST') {
			return $this->cancel($id, $request);
		}
		if (count($segments) === 3 && $segments[1] === 'files' && $method === 'GET') {
			return $this->file($id, $segments[2]);
		}
		return $this->notFound('Not found');
	}

	/** Podadresář úlohy s výstupem `dataset-e2e --output-dir` a jeho trace soubory (D96). */
	public const string E2E_DIR = DatasetJobRecipes::E2E_DIR;
	public const string E2E_TRACES_DIR = 'traces';

	/**
	 * Stažení trace selhané karty proklikání (D96): jen úloha typu `e2e`,
	 * jen název souboru, který `result.json` jmenuje v `artifacts.traces[].file`,
	 * jen z `<úloha>/e2e/traces/` — žádné libovolné cesty.
	 */
	private function file(string $id, string $name): Response
	{
		$record = $this->store->read($id);
		if ($record === null || $record->type !== JobRecord::TYPE_E2E) {
			return $this->notFound('Job not found');
		}
		if (preg_match('/^[A-Za-z0-9._-]+\.zip$/', $name) !== 1 || str_contains($name, '..')) {
			return $this->notFound('File not found');
		}
		$result = $this->store->result($id);
		$traces = is_array($result['artifacts']['traces'] ?? null) ? $result['artifacts']['traces'] : [];
		$listed = false;
		foreach ($traces as $trace) {
			if (is_array($trace) && isset($trace['file']) && is_string($trace['file']) && basename($trace['file']) === $name) {
				$listed = true;
				break;
			}
		}
		if (!$listed) {
			return $this->notFound('File not found');
		}
		$dir = realpath($this->store->jobDir($id) . '/' . self::E2E_DIR . '/' . self::E2E_TRACES_DIR);
		$path = $dir === false ? false : realpath($dir . '/' . $name);
		if ($dir === false || $path === false || !str_starts_with($path, $dir . '/') || !is_file($path)) {
			return $this->notFound('File not found');
		}
		$bytes = @file_get_contents($path);
		if ($bytes === false) {
			return $this->notFound('File not found');
		}
		return Response::binary($bytes, 'application/zip')
			->withHeader('Content-Disposition', 'attachment; filename="' . $name . '"')
			->withHeader('Cache-Control', 'no-store');
	}

	private function list(Request $request): Response
	{
		$qp = $request->getQueryParams();
		$dataset = self::stringParam($qp, 'dataset');
		$ds = self::stringParam($qp, 'ds');
		$days = self::intParam($qp, 'days', JobStore::RETENTION_DAYS, 1, self::MAX_DAYS);
		$type = self::stringParam($qp, 'type');
		$type = $type !== null && in_array($type, JobRecord::TYPES, true) ? $type : null;
		$parent = self::stringParam($qp, 'parent');
		$parent = $parent !== null && JobStore::isValidId($parent) ? $parent : null;
		$this->store->prune();
		return Response::success(['jobs' => $this->store->list($dataset, $ds, $days, $type, $parent)]);
	}

	private function detail(string $id, Request $request): Response
	{
		$view = $this->store->viewOf($id);
		if ($view === null) {
			return $this->notFound('Job not found');
		}
		$qp = $request->getQueryParams();
		$offset = self::intParam($qp, 'offset', 0, 0, PHP_INT_MAX);
		$tail = self::intParam($qp, 'tail', JobStore::OUTPUT_TAIL_LINES, 0, self::MAX_TAIL_LINES);
		return Response::success([
			'job' => $view,
			'progress' => $this->store->progress($id, $offset),
			'outputTail' => $this->store->outputTail($id, $tail),
			'result' => self::resultSummary($view, $this->store->result($id)),
		]);
	}

	private function cancel(string $id, Request $request): Response
	{
		$record = $this->store->read($id);
		if ($record === null) {
			return $this->notFound('Job not found');
		}
		// U2: aktualizaci checkoutu nejde zrušit ani vynutit — přerušení
		// uprostřed composer install / buildu by nechalo checkout v půli.
		if (!$record->isCancellable()) {
			return Response::error(
				'JOB_NOT_CANCELLABLE',
				"Job type '{$record->type}' cannot be cancelled from the dashboard — a stuck update is resolved in the terminal",
				409,
			);
		}
		$force = ($request->getQueryParams()['force'] ?? null) === '1'
			|| (bool) ($request->getBody()['force'] ?? false);
		$view = $this->launcher->cancel($id, $force);
		if ($view === null) {
			return Response::error(
				'JOB_NOT_ACTIVE',
				'Job is not active (already finished, or the wrapper has not reported its PID yet)',
				409,
			);
		}
		return Response::success(['job' => $view]);
	}

	/**
	 * Výsledek pro UI: u evaluace jen odkaz na soubor běhu a souhrn (celý běh
	 * ukazuje prohlížeč výsledků), u ostatních typů celý `result.json`.
	 *
	 * @param array<string, mixed>      $view
	 * @param array<string, mixed>|null $result
	 * @return array<string, mixed>|null
	 */
	private static function resultSummary(array $view, ?array $result): ?array
	{
		if ($result === null) {
			return null;
		}
		if (($view['type'] ?? null) !== JobRecord::TYPE_EVAL) {
			return $result;
		}
		$metrics = is_array($result['metrics'] ?? null) ? $result['metrics'] : [];
		return [
			'ok' => (bool) ($result['ok'] ?? true),
			'problems' => is_array($result['problems'] ?? null) ? array_values($result['problems']) : null,
			'file' => isset($result['file']) ? basename((string) $result['file']) : null,
			'set' => $result['set']['name'] ?? null,
			'stoppedBy' => $result['stoppedBy'] ?? null,
			'plannedCalls' => $result['plannedCalls'] ?? null,
			'metrics' => array_intersect_key($metrics, array_flip([
				'calls.total', 'calls.ok', 'calls.failed', 'cost.usd', 'cost.totalUsd', 'fields.all.hit', 'documents.clean',
			])),
		];
	}

	/** @param array<string, mixed> $qp */
	private static function stringParam(array $qp, string $key): ?string
	{
		$value = $qp[$key] ?? null;
		return is_string($value) && $value !== '' ? $value : null;
	}

	/** @param array<string, mixed> $qp */
	private static function intParam(array $qp, string $key, int $default, int $min, int $max): int
	{
		$value = $qp[$key] ?? null;
		if (!is_string($value) || !ctype_digit($value)) {
			return $default;
		}
		return max($min, min($max, (int) $value));
	}

	private function notFound(string $message): Response
	{
		return Response::error('NOT_FOUND', $message, 404);
	}
}

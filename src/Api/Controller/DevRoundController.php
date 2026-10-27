<?php
declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Cli\BinPaths;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\DevJobs\DevActionGuard;
use Shipard\Core\DevJobs\JobConflict;
use Shipard\Core\DevJobs\JobLauncher;
use Shipard\Core\DevJobs\JobRecord;
use Shipard\Core\DevJobs\JobStore;
use Shipard\Core\Process\CapturedProcess;
use Shipard\Core\Server\DataSourceInventory;
use Shipard\Module\Core\Exchange\Dataset\DatasetCatalog;
use Shipard\Module\Core\Exchange\Dataset\E2e\E2eException;
use Shipard\Module\Core\Exchange\Dataset\E2e\E2ePreflight;
use Shipard\Module\Core\Exchange\Dataset\E2e\LocalBaseUrl;
use Shipard\Module\Core\Exchange\Dataset\Round\CodeSteps;
use Shipard\Module\Core\Exchange\Dataset\Round\RoundDataSources;
use Shipard\Module\Core\Exchange\Dataset\Round\RoundEstimate;
use Shipard\Module\Core\Exchange\Dataset\Round\RoundEvalOptions;
use Shipard\Module\Core\Exchange\Dataset\Round\RoundException;
use Shipard\Module\Core\Exchange\Dataset\Round\RoundJobFactory;
use Shipard\Module\Core\Exchange\Dataset\Round\RoundPlan;
use Shipard\Module\Core\Exchange\Dataset\Round\RoundPlanner;
use Shipard\Module\Core\Exchange\Dataset\Round\RoundSelection;
use Shipard\Module\Core\Exchange\Dataset\Video\VideoDataSources;
use Shipard\Module\Core\Exchange\Dataset\Video\VideoPreflight;
use Shipard\Module\Core\Exchange\Dataset\Video\VideoScenarioCatalog;
use Shipard\Module\Core\Mail\Eval\Browse\EvalCatalog;
use Shipard\Module\Core\Mail\Eval\Browse\EvalDatasetInfo;
use Shipard\Module\Core\Mail\Eval\EvalEstimateRunner;

/**
 * Testovací kolo ve vývojářském dashboardu (`/_dev/api/testing/round`,
 * tasks/testing-phase8a.md § 5, D130, D131, D133): poslední kolo se
 * souhrnem, nabídky pro dialog spuštění, odhad ceny evaluací a spuštění
 * úlohy `round` — týž záznam jako `shpd-server test-round`
 * ({@see RoundJobFactory}), takže kolo z CLI i z dashboardu je totéž.
 * Zrušení jde přes `DevJobsController` (`jobs/{id}/cancel`, kaskáda na
 * dílčí úlohy).
 *
 * | metoda | cesta      | odpověď |
 * |--------|------------|---------|
 * | GET    | ``         | `{last: {job, result}|null, active: job|null, options: {datasets[], scenarios[], variants[], tools{e2e[], video[]}, dataSources{datasets{}, videos{}}}}` |
 * | POST   | `estimate` `{datasets?, variant, repeat?}` | `{total, rows[]}`; 422 `EVAL_PREFLIGHT` s `problems` |
 * | POST   | `run` `{layers?, datasets?, scenarios?, eval?{variant, repeat, maxCostUsd}}` | 201 `{job}`; 400 `UNKNOWN_DATASET` / `VALIDATION` (origin mimo server, D97); 422 `EVAL_PREFLIGHT` / `EVAL_OVER_CAP`; 409 `JOB_RUNNING` (běží kolo nebo update) |
 *
 * Pojistka: jen režim `development` ({@see DevActionGuard}); zdroje dat
 * kolo bere jen vlastní ({@see RoundDataSources}, O1) — nic z požadavku
 * nejmenuje zdroj dat. Testovatelné přes podtřídu (seamy níže).
 */
class DevRoundController
{
	public const string PREFIX = '/_dev/api/testing/round';
	/** Strop útraty jen pro odhad — odhad ho nečte, {@see RoundEvalOptions} ho vyžaduje. */
	private const float ESTIMATE_PLACEHOLDER_CAP = 1.0;

	public function __construct(
		private readonly string $repoRoot,
		private readonly string $dataSourcesDir,
		private readonly string $serverMode,
		private readonly JobStore $jobs,
		private readonly JobLauncher $launcher,
	) {}

	// ── Seamy ──────────────────────────────────────────────────────────────

	/** @return list<string> */
	protected function e2eToolProblems(): array
	{
		return (new E2ePreflight($this->repoRoot))->toolProblems();
	}

	/** @return list<string> */
	protected function videoToolProblems(): array
	{
		$problems = (new VideoPreflight($this->repoRoot))->toolProblems();
		if ($this->gotenbergUrl() === null) {
			$problems[] = 'Gotenberg není nastavený (render.url v /etc/shipard/server.json) — PDF demo sady se bez něj nevyrobí';
		}
		return $problems;
	}

	protected function gotenbergUrl(): ?string
	{
		try {
			$cfg = new ServerConfig();
			$cfg->load();
			$url = $cfg->getRender()?->url;
			return is_string($url) && trim($url) !== '' ? $url : null;
		} catch (\Throwable) {
			return null;
		}
	}

	protected function localBaseUrl(): LocalBaseUrl
	{
		return new LocalBaseUrl();
	}

	/** @return list<string> */
	protected function dsCommand(): array
	{
		return BinPaths::shpdDsCommand();
	}

	/** @return list<string> */
	protected function serverCommand(): array
	{
		return BinPaths::shpdServerCommand();
	}

	/**
	 * @param list<string> $argv
	 * @return array{exitCode: ?int, termSignal: ?int, timedOut: bool, stdout: string, stderr: string}
	 */
	protected function capture(array $argv, string $cwd, float $timeoutSeconds): array
	{
		return CapturedProcess::run($argv, $cwd, $timeoutSeconds);
	}

	protected function now(): \DateTimeImmutable
	{
		return new \DateTimeImmutable();
	}

	// ── Routování ──────────────────────────────────────────────────────────

	public function dispatch(Request $request): Response
	{
		$path = $request->getPath();
		$method = $request->getMethod();
		if ($path !== self::PREFIX && !str_starts_with($path, self::PREFIX . '/')) {
			return $this->notFound();
		}
		$segments = array_values(array_filter(
			array_map(rawurldecode(...), explode('/', substr($path, strlen(self::PREFIX)))),
			static fn (string $s): bool => $s !== '',
		));
		$refused = DevActionGuard::check($this->serverMode, null, false, null, []);
		if ($refused !== null) {
			return Response::error($refused['code'], $refused['message'], $refused['status']);
		}
		$body = $request->getBody() ?? [];
		return match (true) {
			$segments === [] && $method === 'GET' => $this->overview(),
			$segments === ['estimate'] && $method === 'POST' => $this->estimate($body),
			$segments === ['run'] && $method === 'POST' => $this->run($body, $request),
			default => $this->notFound(),
		};
	}

	// ── Přehled (karta posledního kola, nabídky dialogu) ───────────────────

	private function overview(): Response
	{
		$rounds = $this->jobs->list(null, null, null, JobRecord::TYPE_ROUND);
		$last = $rounds[0] ?? null;
		$active = null;
		foreach ($rounds as $view) {
			if ($view['active'] === true) {
				$active = $view;
				break;
			}
		}
		$evalCatalog = new EvalCatalog($this->repoRoot);
		$inventory = new DataSourceInventory($this->dataSourcesDir);
		$datasets = [];
		$roundDataSources = [];
		foreach ($evalCatalog->datasets() as $dataset) {
			$datasets[] = ['name' => $dataset->name, 'title' => $dataset->title, 'country' => $dataset->country, 'messages' => $dataset->messages];
			$ds = RoundDataSources::find($inventory, $dataset->name);
			$roundDataSources[$dataset->name] = $ds === null ? null : ['id' => $ds->id, 'name' => $ds->name];
		}
		$scenarios = [];
		$videoDataSources = [];
		foreach ((new VideoScenarioCatalog($this->repoRoot))->scenarios() as $entry) {
			$scenario = $entry['scenario'];
			$scenarios[] = [
				'id' => $entry['id'],
				'title' => $scenario?->title,
				'dataset' => $scenario?->dataset,
				'problem' => $entry['problem'],
			];
			if ($scenario !== null && !array_key_exists($scenario->dataset, $videoDataSources)) {
				$ds = VideoDataSources::find($inventory, $scenario->dataset);
				$videoDataSources[$scenario->dataset] = $ds === null ? null : ['id' => $ds->id, 'name' => $ds->name];
			}
		}
		$variants = [];
		foreach ($evalCatalog->variantStore()->list() as $variant) {
			if (($variant['valid'] ?? true) === true && is_string($variant['name'] ?? null)) {
				$variants[] = $variant['name'];
			}
		}
		return Response::success([
			'last' => $last === null ? null : ['job' => $last, 'result' => $this->jobs->result((string) $last['id'])],
			'active' => $active,
			'options' => [
				'layers' => RoundSelection::LAYERS,
				'datasets' => $datasets,
				'scenarios' => $scenarios,
				'variants' => $variants,
				'maxRepeat' => RoundEvalOptions::MAX_REPEAT,
				'tools' => ['e2e' => $this->e2eToolProblems(), 'video' => $this->videoToolProblems()],
				'dataSources' => ['datasets' => $roundDataSources, 'videos' => $videoDataSources],
			],
		]);
	}

	// ── Odhad (D131) ───────────────────────────────────────────────────────

	/** @param array<string, mixed> $body */
	private function estimate(array $body): Response
	{
		try {
			$eval = RoundEvalOptions::fromArray(['variant' => $body['variant'] ?? null, 'repeat' => $body['repeat'] ?? 1, 'maxCostUsd' => self::ESTIMATE_PLACEHOLDER_CAP]);
			$selection = new RoundSelection([RoundSelection::LAYER_DATASETS], self::names($body['datasets'] ?? []), [], $eval, RoundSelection::DEFAULT_BASE_URL);
		} catch (\InvalidArgumentException $e) {
			return Response::error('VALIDATION', $e->getMessage(), 400);
		}
		try {
			$plan = $this->planner()->plan($selection);
		} catch (RoundException $e) {
			return Response::error(DevActionGuard::CODE_UNKNOWN_DATASET, $e->getMessage(), 400);
		}
		$estimate = $this->roundEstimate($plan, $eval);
		if ($estimate['problems'] !== []) {
			return Response::error('EVAL_PREFLIGHT', implode(' ', $estimate['problems']), 422, ['problems' => $estimate['problems'], 'rows' => $estimate['rows']]);
		}
		return Response::success(['total' => $estimate['total'], 'rows' => $estimate['rows'], 'variant' => $eval->variant, 'repeat' => $eval->repeat]);
	}

	// ── Spuštění (D130, D134) ──────────────────────────────────────────────

	/** @param array<string, mixed> $body */
	private function run(array $body, Request $request): Response
	{
		$origin = $request->getOrigin();
		if (!Request::isOrigin($origin)) {
			return Response::error('VALIDATION', "Request origin '{$origin}' is not a usable base URL for the walkthrough and videos", 400);
		}
		try {
			$this->localBaseUrl()->verify($origin);
		} catch (E2eException $e) {
			return Response::error('VALIDATION', $e->getMessage(), 400);
		}
		try {
			$selection = RoundSelection::fromArray([
				'layers' => $body['layers'] ?? RoundSelection::LAYERS,
				'datasets' => self::names($body['datasets'] ?? []),
				'scenarios' => self::names($body['scenarios'] ?? []),
				'eval' => is_array($body['eval'] ?? null) ? $body['eval'] : null,
				'baseUrl' => $origin,
			]);
		} catch (\InvalidArgumentException $e) {
			return Response::error('VALIDATION', $e->getMessage(), 400);
		}
		try {
			$plan = $this->planner()->plan($selection);
		} catch (RoundException $e) {
			return Response::error(DevActionGuard::CODE_UNKNOWN_DATASET, $e->getMessage(), 400);
		}
		if ($selection->eval !== null) {
			$estimate = $this->roundEstimate($plan, $selection->eval);
			if ($estimate['problems'] !== []) {
				return Response::error('EVAL_PREFLIGHT', implode(' ', $estimate['problems']), 422, ['problems' => $estimate['problems'], 'rows' => $estimate['rows']]);
			}
			if ($estimate['total'] > $selection->eval->maxCostUsd) {
				return Response::error('EVAL_OVER_CAP', sprintf('Odhad %.4f USD je nad stropem %.4f USD — kolo nezačne', $estimate['total'], $selection->eval->maxCostUsd), 422, ['total' => $estimate['total'], 'maxCostUsd' => $selection->eval->maxCostUsd, 'rows' => $estimate['rows']]);
			}
		}
		$record = RoundJobFactory::record($selection, $this->serverCommand(), $this->repoRoot, $this->jobs, $this->now(), $request->getClientIp(), RoundJobFactory::ORIGIN_DASHBOARD);
		try {
			return Response::success(['job' => $this->launcher->launch($record)], 201);
		} catch (JobConflict $e) {
			return Response::error('JOB_RUNNING', $e->getMessage(), 409, ['jobId' => $e->runningJobId, 'dataSource' => $e->dataSourceId]);
		} catch (\RuntimeException $e) {
			return Response::error('JOB_LAUNCH_FAILED', $e->getMessage(), 500);
		}
	}

	// ── Pomocné ────────────────────────────────────────────────────────────

	private function planner(): RoundPlanner
	{
		return new RoundPlanner(
			new DataSourceInventory($this->dataSourcesDir),
			new EvalCatalog($this->repoRoot),
			new DatasetCatalog($this->repoRoot),
			new VideoScenarioCatalog($this->repoRoot),
			new CodeSteps($this->repoRoot),
			fn (): array => $this->e2eToolProblems(),
			fn (): array => $this->videoToolProblems(),
		);
	}

	/** @return array{total: float, rows: list<array<string, mixed>>, problems: list<string>} */
	private function roundEstimate(RoundPlan $plan, RoundEvalOptions $eval): array
	{
		$variantPath = (new EvalCatalog($this->repoRoot))->variantStore()->pathFor($eval->variant);
		$runner = new EvalEstimateRunner($this->dsCommand(), fn (array $argv, string $cwd, float $timeout): array => $this->capture($argv, $cwd, $timeout));
		return (new RoundEstimate($runner, $this->dataSourcesDir))->forPlan($plan, $eval, $variantPath);
	}

	/** Seznam názvů z těla požadavku (řetězce; cokoli jiného se zahodí). @return list<string> */
	private static function names(mixed $raw): array
	{
		if (is_string($raw)) {
			$raw = $raw === '' ? [] : explode(',', $raw);
		}
		if (!is_array($raw)) {
			return [];
		}
		$names = [];
		foreach ($raw as $item) {
			if (is_string($item) && trim($item) !== '') {
				$names[] = trim($item);
			}
		}
		return $names;
	}

	private function notFound(): Response
	{
		return Response::error('NOT_FOUND', 'Not found', 404);
	}
}

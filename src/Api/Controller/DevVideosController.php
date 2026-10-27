<?php
declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\ByteRange;
use Shipard\Api\ByteRangeUnsatisfiable;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Cli\BinPaths;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\DevJobs\DevActionGuard;
use Shipard\Core\DevJobs\JobConflict;
use Shipard\Core\DevJobs\JobLauncher;
use Shipard\Core\DevJobs\JobRecord;
use Shipard\Core\DevJobs\JobStep;
use Shipard\Core\DevJobs\JobStore;
use Shipard\Core\Server\DataSourceInfo;
use Shipard\Core\Server\DataSourceInventory;
use Shipard\Core\Utils\IdGenerator;
use Shipard\Module\Core\Exchange\Dataset\DatasetCatalog;
use Shipard\Module\Core\Exchange\Dataset\DatasetCatalogEntry;
use Shipard\Module\Core\Exchange\Dataset\E2e\E2eException;
use Shipard\Module\Core\Exchange\Dataset\E2e\LocalBaseUrl;
use Shipard\Module\Core\Exchange\Dataset\Video\VideoDataSources;
use Shipard\Module\Core\Exchange\Dataset\Video\VideoPreflight;
use Shipard\Module\Core\Exchange\Dataset\Video\VideoReport;
use Shipard\Module\Core\Exchange\Dataset\Video\VideoScenario;
use Shipard\Module\Core\Exchange\Dataset\Video\VideoScenarioCatalog;
use Shipard\Module\Core\Exchange\Dataset\Video\VideoStore;

/**
 * Oddíl Videa vývojářského dashboardu (tasks/testing-phase7b.md § 3, #103
 * D122, D126, D127): scénáře z `demo/scenarios/` se stavem posledního
 * ověření a natočení, akce Ověřit / Natočit jako úloha `video` (jeden krok
 * `shpd-ds video-run <scénář> --verb … --json --progress-file --base-url
 * <origin požadavku>`, vzor D91) nad vyhrazeným zdrojem dat sady (účel
 * `videa — <sada>`, D126 — najít, nebo založit úlohou `create`: `ds-create`
 * s cronem + `ds-upgrade`, bez seedu — seeduje až `video-run`), a hotové
 * video read-only s HTTP `Range` (jen soubor, který `meta.json` jmenuje).
 *
 * | Metoda | Cesta pod `/_dev/api/videos`  | Význam |
 * |--------|-------------------------------|--------|
 * | GET    | ``                            | scénáře + nástroje + zdroj dat per sada + běžící úloha |
 * | POST   | `{id}/run` `{verb}`           | úloha `video`; 422 `VIDEO_UNAVAILABLE` bez nástrojů / Gotenbergu, 409 `VIDEO_DS_MISSING` bez zdroje dat, 400 `VALIDATION` origin mimo server (D97), 409 `JOB_RUNNING` |
 * | POST   | `data-source` `{dataset}`     | úloha `create` (ds-create --purpose "videa — <sada>" s cronem + ds-upgrade); 409 `VIDEO_DS_EXISTS` |
 * | GET    | `{id}/file[?download=1]`      | video s `Range` (200 / 206 / 416), `download=1` = příloha |
 *
 * Pojistka {@see DevActionGuard} (jen `development`, jen `free`) je tady,
 * ne v UI. Testovatelné přes podtřídu (seamy níže).
 */
class DevVideosController
{
	public const string PREFIX = '/_dev/api/videos';
	/** Zrušení videa: runner po SIGINT zavře Chromium, příkaz uklidí session; seed je nepřerušitelný (desítky sekund). */
	public const int VIDEO_CANCEL_GRACE_SECONDS = 180;
	public const int CREATE_CANCEL_GRACE_SECONDS = 60;
	public const array VERBS = [VideoReport::VERB_CHECK, VideoReport::VERB_BUILD];
	public const string CONTENT_TYPE = 'video/mp4';

	public function __construct(
		private readonly string $repoRoot,
		private readonly string $dataSourcesDir,
		private readonly string $serverMode,
		private readonly JobStore $jobs,
		private readonly JobLauncher $launcher,
	) {}

	// ── Seamy ──────────────────────────────────────────────────────────────

	/**
	 * Co na stroji chybí pro videa (Node, závislosti a Chromium runneru,
	 * ffmpeg, python3, generátor) — týž zdroj jako preflight `video-run`.
	 *
	 * @return list<string>
	 */
	protected function toolProblems(): array
	{
		return (new VideoPreflight($this->repoRoot))->toolProblems();
	}

	/** Adresa Gotenbergu (`render.url` v server.json); null = není — sada s kotvou se nedá sestavit. */
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

	protected function newDataSourceId(): string
	{
		return (new IdGenerator())->generate($this->dataSourcesDir);
	}

	protected function now(): \DateTimeImmutable
	{
		return new \DateTimeImmutable();
	}

	protected function store(): VideoStore
	{
		return new VideoStore(VideoStore::defaultDir());
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
		$scenarios = new VideoScenarioCatalog($this->repoRoot);
		if (!$scenarios->isAvailable()) {
			return Response::error('VIDEOS_UNAVAILABLE', 'demo/scenarios not present in this installation', 404);
		}
		$body = $request->getBody() ?? [];
		return match (true) {
			$segments === [] && $method === 'GET' => $this->list($scenarios),
			$segments === ['data-source'] && $method === 'POST' => $this->createDataSource($body, $request),
			count($segments) === 2 && $segments[1] === 'run' && $method === 'POST' => $this->run($scenarios, $segments[0], $body, $request),
			count($segments) === 2 && $segments[1] === 'file' && $method === 'GET' => $this->file($segments[0], $request),
			default => $this->notFound(),
		};
	}

	// ── Seznam ─────────────────────────────────────────────────────────────

	private function list(VideoScenarioCatalog $scenarios): Response
	{
		$catalog = new DatasetCatalog($this->repoRoot);
		$inventory = new DataSourceInventory($this->dataSourcesDir);
		$store = $this->store();
		$problems = $this->toolProblems();
		$gotenberg = $this->gotenbergUrl();
		if ($gotenberg === null) {
			$problems[] = 'Gotenberg není nastavený (render.url v /etc/shipard/server.json) — PDF demo sady se bez něj nevyrobí';
		}
		$dataSources = [];
		$rows = [];
		foreach ($scenarios->scenarios() as $entry) {
			$scenario = $entry['scenario'];
			$dataset = $scenario?->dataset;
			$catalogEntry = $dataset === null ? null : $catalog->dataset($dataset);
			$info = null;
			if ($dataset !== null) {
				$dataSources[$dataset] ??= VideoDataSources::find($inventory, $dataset);
				$info = $dataSources[$dataset];
			}
			$files = $store->read($entry['id']);
			$rows[] = [
				'id' => $entry['id'],
				'title' => $scenario?->title ?? $entry['id'],
				'description' => $scenario?->description ?? '',
				'dataset' => $dataset,
				'datasetTitle' => $catalogEntry?->title,
				'datasetKnown' => $catalogEntry !== null && $catalogEntry->kind === DatasetCatalog::KIND_DEMO,
				'locale' => $scenario?->locale,
				'timezone' => $scenario?->timezone,
				'clockTime' => $scenario?->clockTime,
				'stepCount' => $scenario?->stepCount,
				'problem' => $entry['problem'],
				'meta' => $files['meta'],
				'check' => $files['check'],
				'hasVideo' => $files['hasVideo'],
				'dataSource' => $info === null ? null : self::dataSourceView($info),
				'activeJob' => $info === null ? null : $this->activeJobView($info->id),
			];
		}
		return Response::success([
			'toolProblems' => $problems,
			'canRun' => $problems === [],
			'scenarios' => $rows,
		]);
	}

	/** @return array{id: string, name: string, state: string, purpose: ?string} */
	private static function dataSourceView(DataSourceInfo $info): array
	{
		return ['id' => $info->id, 'name' => $info->name, 'state' => $info->state, 'purpose' => $info->purpose];
	}

	/** @return array{id: string, type: string, status: string, scenario: ?string, verb: ?string}|null */
	private function activeJobView(string $dataSourceId): ?array
	{
		$job = $this->jobs->activeJobFor($dataSourceId);
		if ($job === null) {
			return null;
		}
		$params = is_array($job['params'] ?? null) ? $job['params'] : [];
		return [
			'id' => (string) $job['id'],
			'type' => (string) $job['type'],
			'status' => (string) $job['status'],
			'scenario' => is_string($params['scenario'] ?? null) ? $params['scenario'] : null,
			'verb' => is_string($params['verb'] ?? null) ? $params['verb'] : null,
		];
	}

	// ── Ověřit / Natočit ───────────────────────────────────────────────────

	/** @param array<string, mixed> $body */
	private function run(VideoScenarioCatalog $scenarios, string $id, array $body, Request $request): Response
	{
		$verb = $body['verb'] ?? null;
		if (!is_string($verb) || !in_array($verb, self::VERBS, true)) {
			return Response::error('VALIDATION', 'verb must be check or build', 400);
		}
		$scenario = $this->scenarioFromCatalog($scenarios, $id);
		if ($scenario instanceof Response) {
			return $scenario;
		}
		$catalog = new DatasetCatalog($this->repoRoot);
		$inventory = new DataSourceInventory($this->dataSourcesDir);
		$info = VideoDataSources::find($inventory, $scenario->dataset);
		// Režim serveru a známá sada platí i bez zdroje dat — chybějící zdroj dat je až druhá otázka.
		$refused = $this->guard(null, false, $scenario->dataset, $catalog);
		if ($refused !== null) {
			return $refused;
		}
		if ($info === null) {
			return Response::error('VIDEO_DS_MISSING', "No free data source with purpose '" . VideoDataSources::purposeFor($scenario->dataset) . "' — create one first", 409, [
				'dataset' => $scenario->dataset,
				'purpose' => VideoDataSources::purposeFor($scenario->dataset),
			]);
		}
		$refused = $this->guard($info, true, $scenario->dataset, $catalog);
		if ($refused !== null) {
			return $refused;
		}
		$problems = $this->toolProblems();
		if ($this->gotenbergUrl() === null) {
			$problems[] = 'Gotenberg není nastavený (render.url v /etc/shipard/server.json) — PDF demo sady se bez něj nevyrobí';
		}
		if ($problems !== []) {
			return Response::error('VIDEO_UNAVAILABLE', implode(' ', $problems), 422, ['problems' => $problems]);
		}
		$origin = $request->getOrigin();
		if (!Request::isOrigin($origin)) {
			return Response::error('VALIDATION', "Request origin '{$origin}' is not a usable base URL for the video", 400);
		}
		// Origin musí být tento server (D97) — jinak by runner odnesl token session jinam.
		try {
			$this->localBaseUrl()->verify($origin);
		} catch (E2eException $e) {
			return Response::error('VALIDATION', $e->getMessage(), 400);
		}
		$jobId = JobRecord::newId($this->now());
		$argv = [
			...$this->dsCommand(), 'video-run', $scenario->path,
			'--verb', $verb,
			'--json',
			'--progress-file', $this->jobs->path($jobId, JobStore::PROGRESS_FILE),
			'--base-url', $origin,
		];
		$record = new JobRecord(
			$jobId,
			JobRecord::TYPE_VIDEO,
			$info->id,
			$info->name,
			$scenario->dataset,
			null,
			['scenario' => $scenario->id, 'verb' => $verb, 'baseUrl' => $origin],
			[new JobStep("video-run {$scenario->id} ({$verb})", $argv, $this->dsDir($info->id), captureStdout: true)],
			JobStore::formatTime($this->now()),
			$request->getClientIp(),
			self::VIDEO_CANCEL_GRACE_SECONDS,
		);
		return $this->launch($record);
	}

	// ── Zdroj dat pro videa (D126) ─────────────────────────────────────────

	/** @param array<string, mixed> $body */
	private function createDataSource(array $body, Request $request): Response
	{
		$catalog = new DatasetCatalog($this->repoRoot);
		$name = $body['dataset'] ?? null;
		if (!is_string($name)) {
			return Response::error(DevActionGuard::CODE_UNKNOWN_DATASET, 'dataset (demo set name from the listing) is required', 400);
		}
		$refused = $this->guard(null, false, $name, $catalog);
		if ($refused !== null) {
			return $refused;
		}
		$dataset = $catalog->dataset($name);
		if (!$dataset instanceof DatasetCatalogEntry || $dataset->kind !== DatasetCatalog::KIND_DEMO) {
			return Response::error(DevActionGuard::CODE_UNKNOWN_DATASET, "'{$name}' is not a demo dataset", 400);
		}
		$existing = VideoDataSources::find(new DataSourceInventory($this->dataSourcesDir), $dataset->name);
		if ($existing !== null) {
			return Response::error('VIDEO_DS_EXISTS', "Data source {$existing->id} already holds the videos of '{$dataset->name}'", 409, ['dataSource' => self::dataSourceView($existing)]);
		}
		$id = $this->newDataSourceId();
		$dsName = 'Videa — ' . $dataset->title;
		$purpose = VideoDataSources::purposeFor($dataset->name);
		$steps = [
			new JobStep('ds-create ' . $id, [
				...$this->serverCommand(), 'ds-create',
				'--name', $dsName,
				'--language', 'cs',
				'--country', $dataset->country ?? 'cz',
				'--module', 'install.base',
				// Bez --no-cron (na rozdíl od evaluačních zdrojů dat): pruh „Periodické
				// úlohy vypnuté“ by byl na každém videu; sada se stejně před během přeseeduje.
				'--ds-id', $id,
				'--purpose', $purpose,
			], $this->repoRoot),
			// Čerstvý zdroj dat nemá tabulky — API by odpovídalo 500 a preflight
			// video-run by ho odmítl; seed až video-run (sada s kotvou).
			new JobStep('ds-upgrade ' . $id, [...$this->dsCommand(), 'ds-upgrade'], $this->dsDir($id)),
		];
		$record = new JobRecord(
			JobRecord::newId($this->now()),
			JobRecord::TYPE_CREATE,
			$id,
			$dsName,
			$dataset->name,
			null,
			['purpose' => $purpose, 'videos' => true],
			$steps,
			JobStore::formatTime($this->now()),
			$request->getClientIp(),
			self::CREATE_CANCEL_GRACE_SECONDS,
		);
		return $this->launch($record);
	}

	// ── Video (D127) ───────────────────────────────────────────────────────

	private function file(string $id, Request $request): Response
	{
		$refused = $this->guard(null, false, null, null);
		if ($refused !== null) {
			return $refused;
		}
		if (preg_match(VideoScenario::ID_REGEX, $id) !== 1) {
			return $this->notFound('Video not found');
		}
		$store = $this->store();
		$path = $store->fileFor($id);
		$size = $path === null ? false : filesize($path);
		if ($path === null || $size === false) {
			return $this->notFound('Video not found');
		}
		try {
			$range = ByteRange::resolve($request->getHeader('range'), $size);
		} catch (ByteRangeUnsatisfiable $e) {
			return Response::rangeNotSatisfiable($e->size)->withHeader('Cache-Control', 'no-store');
		}
		$response = Response::file($path, self::CONTENT_TYPE, $size, $range)->withHeader('Cache-Control', 'no-store');
		$download = $request->getQueryParams()['download'] ?? null;
		if ($download !== null && $download !== '' && $download !== '0') {
			$response = $response->withHeader('Content-Disposition', 'attachment; filename="' . $store->videoFile($id) . '"');
		}
		return $response;
	}

	// ── Pomocné ────────────────────────────────────────────────────────────

	/** Scénář jen z výpisu katalogu (id z URL se nikdy nepřekládá na cestu bez výpisu). */
	private function scenarioFromCatalog(VideoScenarioCatalog $scenarios, string $id): VideoScenario|Response
	{
		foreach ($scenarios->scenarios() as $entry) {
			if ($entry['id'] !== $id) {
				continue;
			}
			if ($entry['scenario'] === null) {
				return Response::error('SCENARIO_INVALID', (string) $entry['problem'], 422);
			}
			return $entry['scenario'];
		}
		return Response::error('UNKNOWN_SCENARIO', "Unknown scenario '{$id}'", 404);
	}

	private function guard(?DataSourceInfo $info, bool $requireDataSource, ?string $dataset, ?DatasetCatalog $catalog): ?Response
	{
		$problem = DevActionGuard::check($this->serverMode, $info, $requireDataSource, $dataset, $catalog?->datasetNames() ?? []);
		return $problem === null ? null : Response::error($problem['code'], $problem['message'], $problem['status']);
	}

	private function dsDir(string $id): string
	{
		return rtrim($this->dataSourcesDir, '/') . '/' . $id;
	}

	private function launch(JobRecord $record): Response
	{
		try {
			return Response::success(['job' => $this->launcher->launch($record)], 201);
		} catch (JobConflict $e) {
			return Response::error('JOB_RUNNING', $e->getMessage(), 409, ['jobId' => $e->runningJobId, 'dataSource' => $e->dataSourceId]);
		} catch (\RuntimeException $e) {
			return Response::error('JOB_LAUNCH_FAILED', $e->getMessage(), 500);
		}
	}

	private function notFound(string $message = 'Not found'): Response
	{
		return Response::error('NOT_FOUND', $message, 404);
	}
}

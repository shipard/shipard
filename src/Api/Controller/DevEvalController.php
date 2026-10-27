<?php
declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Cli\BinPaths;
use Shipard\Core\Ai\AiPlatforms;
use Shipard\Core\Ai\ModelCatalog;
use Shipard\Core\Ai\ModelSpec;
use Shipard\Core\Ai\RequestShaper;
use Shipard\Core\Ai\ServerAiConnection;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataClass;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Config\DataSourceState;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\DevJobs\DevActionGuard;
use Shipard\Core\DevJobs\JobConflict;
use Shipard\Core\DevJobs\JobLauncher;
use Shipard\Core\DevJobs\JobRecord;
use Shipard\Core\DevJobs\JobStore;
use Shipard\Core\Process\CapturedProcess;
use Shipard\Core\Server\DataSourceInfo;
use Shipard\Core\Server\DataSourceInventory;
use Shipard\Core\Utils\IdGenerator;
use Shipard\Module\Core\Exchange\Dataset\E2e\E2eException;
use Shipard\Module\Core\Exchange\Dataset\E2e\E2ePreflight;
use Shipard\Module\Core\Exchange\Dataset\E2e\LocalBaseUrl;
use Shipard\Module\Core\Exchange\Dataset\Jobs\DatasetJobRecipes;
use Shipard\Module\Core\Mail\Eval\Browse\EvalCatalog;
use Shipard\Module\Core\Mail\Eval\Browse\EvalDatasetInfo;
use Shipard\Module\Core\Mail\Eval\EvalDataSource;
use Shipard\Module\Core\Mail\Eval\EvalDataSourceStatus;
use Shipard\Module\Core\Mail\Eval\EvalEstimateRunner;
use Shipard\Module\Core\Mail\Eval\EvalException;
use Shipard\Module\Core\Mail\Eval\EvalMessage;
use Shipard\Module\Core\Mail\Eval\EvalMessages;
use Shipard\Module\Core\Mail\Eval\EvalVariant;
use Shipard\Module\Core\Mail\Eval\EvalVariantExistsException;
use Shipard\Module\Core\Mail\Eval\EvalVariantStore;

/**
 * Spouštěcí API sekce Testování (`/_dev/api/testing/eval/…`,
 * tasks/testing-phase6b.md § 2–7): evaluační zdroje dat sady a jejich stav,
 * akce reseed / create / verify / eval jako úlohy na pozadí
 * ({@see JobLauncher}), odhad ceny synchronně (`ai-eval --estimate --json`
 * přes {@see CapturedProcess}) a editor variant ({@see EvalVariantStore}).
 *
 * Pojistka D60 ({@see DevActionGuard}): každá spouštěcí akce ověří režim
 * `development`, třídu **free** cílového zdroje dat a sadu ze seznamu —
 * tady, ne v UI. Argv kroků se skládá z hodnot ověřených proti výpisům
 * (sada z katalogu, varianta z `variants/`, zdroj dat podle ID); z požadavku
 * nikdy nejde do příkazu volný text, `--only` jen tokeny `[A-Za-z0-9._-]`.
 *
 * | metoda | cesta                          | odpověď                                               |
 * |--------|--------------------------------|-------------------------------------------------------|
 * | GET    | `data-sources?dataset=`        | zdroje dat se sadou + stav (`canEval` / `canReseed` / `canVerify` / `canE2e` + `e2eBlocker`), kandidáti na přeseedování |
 * | POST   | `reseed` `{dataset, dataSource, replaceDataset?}` | úloha `reseed`; jiná než držená sada jen s `replaceDataset: true`, jinak 409 `DATASET_MISMATCH` (D66) |
 * | POST   | `create` `{dataset}`           | úloha `create` (ds-create --no-cron + dataset-seed)   |
 * | POST   | `verify` `{dataset, dataSource, apply?}` | úloha `verify` (dataset-verify --json)      |
 * | POST   | `e2e` `{dataset, dataSource}`  | úloha `e2e` (dataset-e2e --json --progress-file --output-dir --base-url <origin požadavku>, F7a D88); 422 `E2E_UNAVAILABLE` bez Node / závislostí / prohlížeče, 400 `VALIDATION` bez použitelného originu nebo s hostem mimo tento server (D97, {@see LocalBaseUrl}) |
 * | POST   | `estimate` `{dataset, dataSource, variant, repeat?, only?}` | odhad; 422 `EVAL_PREFLIGHT` |
 * | POST   | `run` `{…, maxCostUsd}`        | úloha `eval` (ai-eval --yes --max-cost --progress-file) |
 * | GET    | `variants`, `variants/{název}`, `variants/options?dataSource=` | editor variant        |
 * | PUT    | `variants/{název}` `{…, overwrite?}` | uložená varianta; 409 `VARIANT_EXISTS`          |
 *
 * Testovatelné přes podtřídu (seamy: databáze zdroje dat, podproces,
 * serverové AI, cesty binárek, nové ID).
 */
class DevEvalController
{
	public const string PREFIX = '/_dev/api/testing/eval';
	/** Grace zrušení per typ úlohy žijí v receptech ({@see DatasetJobRecipes}); aliasy pro testy a dokumentaci. */
	public const int EVAL_CANCEL_GRACE_SECONDS = DatasetJobRecipes::EVAL_CANCEL_GRACE_SECONDS;
	public const int DEFAULT_CANCEL_GRACE_SECONDS = DatasetJobRecipes::DEFAULT_CANCEL_GRACE_SECONDS;
	public const int E2E_CANCEL_GRACE_SECONDS = DatasetJobRecipes::E2E_CANCEL_GRACE_SECONDS;
	public const float ESTIMATE_TIMEOUT_SECONDS = EvalEstimateRunner::TIMEOUT_SECONDS;
	public const int MAX_REPEAT = 5;
	private const string ONLY_TOKEN = '/^[A-Za-z0-9._-]+$/';

	public function __construct(
		private readonly string $repoRoot,
		private readonly string $dataSourcesDir,
		private readonly string $serverMode,
		private readonly JobStore $jobs,
		private readonly JobLauncher $launcher,
	) {}

	// ── Seamy ──────────────────────────────────────────────────────────────

	/**
	 * Čistota zdroje dat vůči sadě z jeho databáze (jen SELECTy).
	 *
	 * @param list<string> $setMessageIds
	 * @return array<string, mixed> {@see EvalDataSourceStatus::inspect()}
	 */
	protected function inspectDataSource(string $dsDir, array $setMessageIds): array
	{
		$config = new DataSourceConfig($dsDir);
		return EvalDataSourceStatus::inspect(new EvalDataSource(new DataSourceConnection($config)), $setMessageIds);
	}

	/** @param list<string> $argv @return array{exitCode: ?int, termSignal: ?int, timedOut: bool, stdout: string, stderr: string} */
	protected function capture(array $argv, string $cwd, float $timeoutSeconds): array
	{
		return CapturedProcess::run($argv, $cwd, $timeoutSeconds);
	}

	protected function serverOffersAi(): bool
	{
		return ServerAiConnection::detect() !== null;
	}

	/**
	 * Co na stroji chybí pro proklikání sady (Node v pevném PATH, závislosti
	 * `tests/e2e`, Chromium Playwrightu pod uživatelem php-fpm) — týž zdroj
	 * jako preflight `dataset-e2e` (F7a § 4); prázdné = lze spustit.
	 *
	 * @return list<string>
	 */
	protected function e2eToolProblems(): array
	{
		return (new E2ePreflight($this->repoRoot))->toolProblems();
	}

	/** Pravidlo lokální adresy běhu proklikání (D97) — totéž, co ověří a připíchne příkaz. */
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

	/**
	 * Nabídky pro editor variant z konfigurace a databáze zvoleného zdroje
	 * dat: připojení (`core_ai_backends` + serverové), modely katalogu
	 * dostupné na platformě serverového připojení, aktivní profily pošty.
	 *
	 * @return array<string, mixed>
	 */
	protected function variantOptionsFor(string $dsDir): array
	{
		$config = new DataSourceConfig($dsDir);
		$db = new DataSourceConnection($config);
		$runtime = ConfigRuntime::load($dsDir, $config->getDefaultLanguage());
		$catalog = ModelCatalog::fromConfig($runtime);
		$platforms = AiPlatforms::fromConfig($runtime);
		$server = ServerAiConnection::detect();
		$platform = $server !== null ? $platforms->getOrFallback($server->platform) : null;

		$models = [];
		foreach ($catalog->all() as $spec) {
			if ($platform !== null && $platform->modelIdFor($spec) === null) {
				continue;
			}
			$models[] = [
				'id' => $spec->id,
				'name' => $spec->name,
				'tier' => $spec->tier,
				'status' => $spec->status,
				'thinkingModes' => $spec->thinkingModes,
				'effortLevels' => $spec->effortLevels,
			];
		}
		$connections = [];
		if ($server !== null && $config->isFree()) {
			$connections[] = ['code' => ServerAiConnection::CODE, 'name' => 'Server (development)', 'platform' => $server->platform, 'default' => false];
		}
		foreach ($db->fetchAll('SELECT backend_id, name, provider, is_default FROM core_ai_backends WHERE is_active = %i ORDER BY name', 1) as $row) {
			$connections[] = [
				'code' => (string) $row['backend_id'],
				'name' => (string) $row['name'],
				'platform' => (string) ($row['provider'] ?? ''),
				'default' => (bool) ($row['is_default'] ?? false),
			];
		}
		$profiles = [];
		foreach ($db->fetchAll('SELECT profile_id, name FROM core_mail_ai_profiles WHERE is_active = %i ORDER BY profile_id', 1) as $row) {
			$profiles[] = ['code' => (string) $row['profile_id'], 'name' => (string) ($row['name'] ?? $row['profile_id'])];
		}
		return [
			'platform' => $server?->platform,
			'models' => $models,
			'connections' => $connections,
			'profiles' => $profiles,
			'effortLevels' => RequestShaper::EFFORT_ORDER,
			'thinkingModes' => [ModelSpec::THINKING_DEFAULT_OFF, ModelSpec::THINKING_DEFAULT_ADAPTIVE],
		];
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
		$catalog = new EvalCatalog($this->repoRoot);
		if (!$catalog->isAvailable()) {
			return Response::error('TESTING_UNAVAILABLE', 'tests/datasets or tests/eval not present in this installation', 404);
		}
		$body = $request->getBody() ?? [];
		try {
			return match (true) {
				$segments === ['data-sources'] && $method === 'GET' => $this->dataSources($catalog, $request),
				$segments === ['reseed'] && $method === 'POST' => $this->reseed($catalog, $body, $request),
				$segments === ['create'] && $method === 'POST' => $this->create($catalog, $body, $request),
				$segments === ['verify'] && $method === 'POST' => $this->verify($catalog, $body, $request),
				$segments === ['e2e'] && $method === 'POST' => $this->e2e($catalog, $body, $request),
				$segments === ['estimate'] && $method === 'POST' => $this->estimate($catalog, $body),
				$segments === ['run'] && $method === 'POST' => $this->run($catalog, $body, $request),
				$segments === ['variants'] && $method === 'GET' => $this->variants($catalog),
				$segments === ['variants', 'options'] && $method === 'GET' => $this->variantOptions($request),
				count($segments) === 2 && $segments[0] === 'variants' && $method === 'GET' => $this->variant($catalog, $segments[1]),
				count($segments) === 2 && $segments[0] === 'variants' && $method === 'PUT' => $this->saveVariant($catalog, $segments[1], $body),
				default => $this->notFound(),
			};
		} catch (EvalException $e) {
			return Response::error('EVAL_ERROR', $e->getMessage(), 422);
		}
	}

	// ── Zdroje dat sady (D55, D59) ─────────────────────────────────────────

	private function dataSources(EvalCatalog $catalog, Request $request): Response
	{
		$name = $request->getQueryParams()['dataset'] ?? null;
		$dataset = is_string($name) ? $catalog->dataset($name) : null;
		if ($dataset === null) {
			return Response::error(DevActionGuard::CODE_UNKNOWN_DATASET, 'dataset (set name from the listing) is required', 400);
		}
		$hash = $catalog->currentHash($dataset);
		$setIds = array_map(static fn (EvalMessage $m): string => $m->messageId, EvalMessages::fromDataset($dataset->reader()));
		$serverAi = $this->serverOffersAi();
		$e2eProblems = $this->e2eToolProblems();
		$legacyOrigin = 'dataset-seed ' . $dataset->name;

		$rows = [];
		$candidates = [];
		foreach ((new DataSourceInventory($this->dataSourcesDir))->scan() as $info) {
			$ds = $info->dataset ?? null; // pole `dataset` v main.json (F6b krok 1, D59)
			$dsName = is_array($ds) && is_string($ds['name'] ?? null) ? $ds['name'] : null;
			$legacy = $dsName === null && $info->origin === $legacyOrigin;
			if ($dsName === $dataset->name || $legacy) {
				$rows[] = $this->dataSourceRow($info, is_array($ds) ? $ds : null, $legacy, $hash, $setIds, $serverAi, $e2eProblems);
				continue;
			}
			if ($info->readable && $info->dataClass === DataClass::Free && $dsName === null
				&& !str_starts_with((string) $info->origin, 'dataset-seed ')
				&& $info->state === DataSourceState::ACTIVE
			) {
				$candidates[] = ['id' => $info->id, 'name' => $info->name, 'purpose' => $info->purpose, 'origin' => $info->origin, 'state' => $info->state];
			}
		}
		return Response::success([
			'dataset' => $dataset->toArray(),
			'currentHash' => $hash,
			'dataSources' => $rows,
			'reseedCandidates' => $candidates,
		]);
	}

	/**
	 * @param array<string, mixed>|null $ds     pole `dataset` z main.json
	 * @param list<string>              $setIds
	 * @param list<string>              $e2eProblems chybějící nástroje proklikání (stejné pro všechny řádky)
	 * @return array<string, mixed>
	 */
	private function dataSourceRow(DataSourceInfo $info, ?array $ds, bool $legacy, string $hash, array $setIds, bool $serverAi, array $e2eProblems = []): array
	{
		$row = $info->toArray($serverAi);
		$free = $info->readable && $info->dataClass === DataClass::Free;
		$current = $ds !== null && ($ds['hash'] ?? null) === $hash;
		$row['dataset'] = $ds === null ? null : $ds + ['current' => $current];
		$row['legacyOrigin'] = $legacy;
		$job = $this->jobs->activeJobFor($info->id);
		$row['job'] = $job === null ? null : ['id' => $job['id'], 'type' => $job['type'], 'status' => $job['status']];

		$usage = null;
		$usageError = null;
		if ($free) {
			try {
				$usage = $this->inspectDataSource($this->dsDir($info->id), $setIds);
			} catch (\Throwable $e) {
				$usageError = $e->getMessage();
			}
		}
		$row['usage'] = $usage;
		$row['usageError'] = $usageError;

		$blockers = [];
		if (!$info->readable) {
			$blockers[] = 'main.json nejde přečíst';
		} elseif (!$free) {
			$blockers[] = 'třída dat není free';
		}
		if ($info->state !== DataSourceState::ACTIVE) {
			$blockers[] = 'stav zdroje dat: ' . $info->state;
		}
		if ($job !== null) {
			$blockers[] = 'běží úloha ' . $job['id'];
		}
		if ($ds === null) {
			$blockers[] = 'sada ve zdroji dat není zaznamenaná — přeseedovat';
		} elseif (!$current) {
			$blockers[] = 'zastaralá verze sady — přeseedovat';
		}
		if ($usage !== null) {
			if ($usage['documents'] > 0) {
				$blockers[] = sprintf('%d doklad(ů) — použitý zdroj dat, přeseedovat', $usage['documents']);
			}
			if ($usage['tagRules'] > 0) {
				$blockers[] = sprintf('%d pravidel IČO → štítek — přeseedovat', $usage['tagRules']);
			}
			if ($usage['missing'] !== []) {
				$blockers[] = sprintf('chybí %d zpráv sady — přeseedovat', count($usage['missing']));
			}
			if ($usage['membership'] !== []) {
				$blockers[] = 'data mimo sadu — přeseedovat';
			}
		} elseif ($free) {
			$blockers[] = 'databáze nedostupná: ' . $usageError;
		}
		$row['blockers'] = $blockers;
		$row['canEval'] = $blockers === [];
		$row['canReseed'] = $free && $job === null && $info->state === DataSourceState::ACTIVE;
		$row['canVerify'] = $free && $job === null && $ds !== null && $info->state === DataSourceState::ACTIVE;
		// Proklikání (F7a I14) nesdílí `blockers`: použitý zdroj dat mu nevadí —
		// přeseedování je jeho první krok; blokují ho jen chybějící nástroje.
		$row['canE2e'] = $row['canReseed'] && $e2eProblems === [];
		$row['e2eBlocker'] = $e2eProblems === [] ? null : implode(' ', $e2eProblems);
		return $row;
	}

	// ── Akce → úlohy (D54, D55, D63) ───────────────────────────────────────

	/** @param array<string, mixed> $body */
	private function reseed(EvalCatalog $catalog, array $body, Request $request): Response
	{
		$dataset = $this->datasetFromBody($catalog, $body);
		$info = $this->dataSourceFromBody($body);
		$refused = $this->guard($catalog, $info, true, $body['dataset'] ?? null);
		if ($refused !== null) {
			return $refused;
		}
		/** @var EvalDatasetInfo $dataset @var DataSourceInfo $info */
		// Výměna sady jen výslovně (D66): zdroj dat s jinou sadou (pole `dataset`,
		// nebo původ `dataset-seed <sada>` před F6b) přijme jen `replaceDataset: true`.
		$held = $info->heldDataset();
		$replace = $held !== null && $held !== $dataset->name;
		if ($replace && ($body['replaceDataset'] ?? false) !== true) {
			return Response::error(
				'DATASET_MISMATCH',
				"Data source {$info->id} holds dataset '{$held}' — send replaceDataset: true to replace it with '{$dataset->name}'",
				409,
				['current' => $held, 'requested' => $dataset->name],
			);
		}
		return $this->launch($this->recipes($request)->reseed(
			$info->id,
			$info->name,
			$dataset->name,
			$dataset->dir,
			$replace ? ['replaceDataset' => true, 'previousDataset' => $held] : [],
		));
	}

	/** @param array<string, mixed> $body */
	private function create(EvalCatalog $catalog, array $body, Request $request): Response
	{
		$dataset = $this->datasetFromBody($catalog, $body);
		$refused = $this->guard($catalog, null, false, $body['dataset'] ?? null);
		if ($refused !== null) {
			return $refused;
		}
		/** @var EvalDatasetInfo $dataset */
		$id = $this->newDataSourceId();
		return $this->launch($this->recipes($request)->createSeeded(
			$id,
			DatasetJobRecipes::evalDataSourceName($dataset->title),
			DatasetJobRecipes::evalPurpose($dataset->name),
			$dataset->name,
			$dataset->dir,
			$dataset->country,
		));
	}

	/** @param array<string, mixed> $body */
	private function verify(EvalCatalog $catalog, array $body, Request $request): Response
	{
		$dataset = $this->datasetFromBody($catalog, $body);
		$info = $this->dataSourceFromBody($body);
		$refused = $this->guard($catalog, $info, true, $body['dataset'] ?? null);
		if ($refused !== null) {
			return $refused;
		}
		/** @var EvalDatasetInfo $dataset @var DataSourceInfo $info */
		$apply = (bool) ($body['apply'] ?? true);
		return $this->launch($this->recipes($request)->verify($info->id, $info->name, $dataset->name, $dataset->dir, $apply));
	}

	/**
	 * Proklikání sady (F7a D88, D91): jedna úloha `e2e` = `dataset-e2e <sada>
	 * --json --progress-file <úloha>/progress.jsonl --output-dir <úloha>/e2e
	 * --base-url <origin požadavku>`; id úlohy vzniká předem, aby cesty mířily
	 * do jejího adresáře. Origin bere aplikaci tak, jak ji vidí prohlížeč
	 * uživatele ({@see Request::getOrigin()}); nepoužitelný = 400.
	 *
	 * @param array<string, mixed> $body
	 */
	private function e2e(EvalCatalog $catalog, array $body, Request $request): Response
	{
		$dataset = $this->datasetFromBody($catalog, $body);
		$info = $this->dataSourceFromBody($body);
		$refused = $this->guard($catalog, $info, true, $body['dataset'] ?? null);
		if ($refused !== null) {
			return $refused;
		}
		/** @var EvalDatasetInfo $dataset @var DataSourceInfo $info */
		$problems = $this->e2eToolProblems();
		if ($problems !== []) {
			return Response::error('E2E_UNAVAILABLE', implode(' ', $problems), 422, ['problems' => $problems]);
		}
		$origin = $request->getOrigin();
		if (!Request::isOrigin($origin)) {
			return Response::error('VALIDATION', "Request origin '{$origin}' is not a usable base URL for the walkthrough", 400);
		}
		// Origin musí být tento server (D97) — jinak by Playwright odnesl token
		// session `e2e` jinam. Příkaz pravidlo ověří znovu a adresu připíchne;
		// tady jen 400 místo spadlé úlohy.
		try {
			$this->localBaseUrl()->verify($origin);
		} catch (E2eException $e) {
			return Response::error('VALIDATION', $e->getMessage(), 400);
		}
		return $this->launch($this->recipes($request)->e2e($info->id, $info->name, $dataset->name, $dataset->dir, $origin));
	}

	/** @param array<string, mixed> $body */
	private function estimate(EvalCatalog $catalog, array $body): Response
	{
		$dataset = $this->datasetFromBody($catalog, $body);
		$info = $this->dataSourceFromBody($body);
		$refused = $this->guard($catalog, $info, true, $body['dataset'] ?? null);
		if ($refused !== null) {
			return $refused;
		}
		$variantPath = $this->variantPath($catalog, $body['variant'] ?? null);
		if ($variantPath instanceof Response) {
			return $variantPath;
		}
		$params = $this->runParams($body);
		if ($params instanceof Response) {
			return $params;
		}
		/** @var EvalDatasetInfo $dataset @var DataSourceInfo $info */
		$runner = new EvalEstimateRunner($this->dsCommand(), fn (array $argv, string $cwd, float $timeout): array => $this->capture($argv, $cwd, $timeout));
		$outcome = $runner->estimate($this->dsDir($info->id), $dataset->dir, $variantPath, $params['repeat'], $params['only']);
		return match ($outcome['kind']) {
			EvalEstimateRunner::KIND_OK => Response::success(($outcome['json'] ?? []) + ['variant' => $outcome['json']['variant'] ?? null, 'dataSource' => $info->id]),
			EvalEstimateRunner::KIND_PREFLIGHT => Response::error('EVAL_PREFLIGHT', 'ai-eval odmítnuto', 422, ['problems' => $outcome['problems']]),
			EvalEstimateRunner::KIND_TIMEOUT => Response::error('EVAL_TIMEOUT', 'ai-eval --estimate did not finish in time', 504, ['stderr' => $outcome['stderr']]),
			default => Response::error('EVAL_ERROR', 'ai-eval --estimate failed (exit ' . var_export($outcome['exitCode'], true) . ')', 500, ['stderr' => $outcome['stderr'], 'stdout' => $outcome['stdout']]),
		};
	}

	/** @param array<string, mixed> $body */
	private function run(EvalCatalog $catalog, array $body, Request $request): Response
	{
		$dataset = $this->datasetFromBody($catalog, $body);
		$info = $this->dataSourceFromBody($body);
		$refused = $this->guard($catalog, $info, true, $body['dataset'] ?? null);
		if ($refused !== null) {
			return $refused;
		}
		$variantPath = $this->variantPath($catalog, $body['variant'] ?? null);
		if ($variantPath instanceof Response) {
			return $variantPath;
		}
		$params = $this->runParams($body);
		if ($params instanceof Response) {
			return $params;
		}
		$maxCost = $body['maxCostUsd'] ?? null;
		if (!is_numeric($maxCost) || (float) $maxCost <= 0) {
			return Response::error('VALIDATION', 'maxCostUsd (positive spend cap in USD) is required for an evaluation run', 400);
		}
		$maxCost = (float) $maxCost;
		/** @var EvalDatasetInfo $dataset @var DataSourceInfo $info */
		return $this->launch($this->recipes($request)->eval($info->id, $info->name, $dataset->name, $dataset->dir, $variantPath, $params['repeat'], $params['only'], $maxCost));
	}

	// ── Editor variant (D62) ───────────────────────────────────────────────

	private function variants(EvalCatalog $catalog): Response
	{
		$store = $catalog->variantStore();
		return Response::success([
			'dir' => EvalVariantStore::DIR,
			'variants' => $store->list(),
			'promptTemplates' => $store->promptTemplates(),
		]);
	}

	private function variant(EvalCatalog $catalog, string $name): Response
	{
		$store = $catalog->variantStore();
		$path = $store->pathFor($name);
		if ($path === null) {
			return Response::error('NOT_FOUND', 'Variant not found', 404);
		}
		$variant = EvalVariant::load($path);
		return Response::success([
			'variant' => ['name' => $name, 'file' => basename($path), 'declaredName' => $variant->name] + $variant->toArray(),
			'promptTemplatePreview' => $variant->promptTemplate === null ? null : mb_substr($variant->promptTemplate, 0, 4000),
			'promptTemplates' => $store->promptTemplates(),
		]);
	}

	/** @param array<string, mixed> $body */
	private function saveVariant(EvalCatalog $catalog, string $name, array $body): Response
	{
		$refused = $this->guard(null, null, false, null); // jen režim serveru — zápis do checkoutu
		if ($refused !== null) {
			return $refused;
		}
		$declared = array_intersect_key($body, array_flip(['description', 'profile', 'promptTemplateFile', 'call', 'contentTags']));
		try {
			$variant = $catalog->variantStore()->save($name, $declared, (bool) ($body['overwrite'] ?? false));
		} catch (EvalVariantExistsException $e) {
			return Response::error('VARIANT_EXISTS', $e->getMessage(), 409);
		} catch (EvalException $e) {
			return Response::error('VALIDATION', $e->getMessage(), 422);
		}
		return Response::success(['variant' => ['name' => $name, 'file' => basename($variant->path), 'declaredName' => $variant->name] + $variant->toArray()]);
	}

	private function variantOptions(Request $request): Response
	{
		$info = $this->dataSourceInfo($request->getQueryParams()['dataSource'] ?? null);
		$refused = $this->guard(null, $info, true, null);
		if ($refused !== null) {
			return $refused;
		}
		/** @var DataSourceInfo $info */
		try {
			return Response::success($this->variantOptionsFor($this->dsDir($info->id)) + ['dataSource' => $info->id]);
		} catch (\Throwable $e) {
			return Response::error('OPTIONS_FAILED', 'Cannot read the data source configuration: ' . $e->getMessage(), 500);
		}
	}

	// ── Pomocné ─────────────────────────────────────────────────────────────

	/** @param array<string, mixed> $body */
	private function datasetFromBody(EvalCatalog $catalog, array $body): ?EvalDatasetInfo
	{
		$name = $body['dataset'] ?? null;
		return is_string($name) ? $catalog->dataset($name) : null;
	}

	/** @param array<string, mixed> $body */
	private function dataSourceFromBody(array $body): ?DataSourceInfo
	{
		return $this->dataSourceInfo($body['dataSource'] ?? null);
	}

	private function dataSourceInfo(mixed $id): ?DataSourceInfo
	{
		if (!is_string($id) || preg_match(IdGenerator::ID_PATTERN, $id) !== 1) {
			return null;
		}
		return (new DataSourceInventory($this->dataSourcesDir))->read($this->dsDir($id));
	}

	private function dsDir(string $id): string
	{
		return rtrim($this->dataSourcesDir, '/') . '/' . $id;
	}

	/** Pojistka D60 jako odpověď; `$dataset` je surová hodnota z požadavku (neznámá sada → 400). */
	private function guard(?EvalCatalog $catalog, ?DataSourceInfo $info, bool $requireDataSource, mixed $dataset): ?Response
	{
		if ($catalog !== null && !is_string($dataset)) {
			return Response::error(DevActionGuard::CODE_UNKNOWN_DATASET, 'dataset (set name from the listing) is required', 400);
		}
		$problem = DevActionGuard::check(
			$this->serverMode,
			$info,
			$requireDataSource,
			is_string($dataset) ? $dataset : null,
			$catalog?->datasetNames() ?? [],
		);
		return $problem === null ? null : Response::error($problem['code'], $problem['message'], $problem['status']);
	}

	/** Cesta k variantě podle názvu souboru z výpisu; soubor musí být platná varianta. */
	private function variantPath(EvalCatalog $catalog, mixed $name): string|Response
	{
		if (!is_string($name) || !EvalVariantStore::isValidName($name) && preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $name) !== 1) {
			return Response::error('VALIDATION', 'variant (file name from tests/eval/variants without .jsonc) is required', 400);
		}
		$store = $catalog->variantStore();
		$path = $store->pathFor($name);
		if ($path === null) {
			return Response::error('UNKNOWN_VARIANT', "Unknown variant '{$name}'", 404);
		}
		try {
			EvalVariant::load($path);
		} catch (EvalException $e) {
			return Response::error('EVAL_ERROR', 'variant file is invalid: ' . $e->getMessage(), 422);
		}
		return $path;
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array{repeat: int, only: list<string>}|Response
	 */
	private function runParams(array $body): array|Response
	{
		$repeat = $body['repeat'] ?? 1;
		if (is_string($repeat) && ctype_digit($repeat)) {
			$repeat = (int) $repeat;
		}
		if (!is_int($repeat) || $repeat < 1 || $repeat > self::MAX_REPEAT) {
			return Response::error('VALIDATION', 'repeat must be an integer between 1 and ' . self::MAX_REPEAT, 400);
		}
		$onlyRaw = $body['only'] ?? [];
		if (is_string($onlyRaw)) {
			$onlyRaw = $onlyRaw === '' ? [] : explode(',', $onlyRaw);
		}
		if (!is_array($onlyRaw)) {
			return Response::error('VALIDATION', 'only must be a list of tokens or a comma-separated string', 400);
		}
		$only = [];
		foreach ($onlyRaw as $token) {
			$token = is_string($token) ? trim($token) : '';
			if ($token === '') {
				continue;
			}
			if (preg_match(self::ONLY_TOKEN, $token) !== 1) {
				return Response::error('VALIDATION', "only: invalid token '{$token}'", 400);
			}
			$only[] = $token;
		}
		return ['repeat' => $repeat, 'only' => $only];
	}

	/** Recepty úloh s binárkami, cestami a časem tohoto controlleru (seamy) a IP klienta požadavku. */
	private function recipes(Request $request): DatasetJobRecipes
	{
		return new DatasetJobRecipes(
			$this->serverCommand(),
			$this->dsCommand(),
			$this->repoRoot,
			$this->dataSourcesDir,
			$this->jobs,
			fn (): \DateTimeImmutable => $this->now(),
			$request->getClientIp(),
		);
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

	private function notFound(): Response
	{
		return Response::error('NOT_FOUND', 'Not found', 404);
	}
}

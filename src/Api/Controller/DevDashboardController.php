<?php
declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Cli\BinPaths;
use Shipard\Core\Ai\ServerAiConnection;
use Shipard\Core\DevJobs\DevActionGuard;
use Shipard\Core\DevJobs\JobConflict;
use Shipard\Core\DevJobs\JobLauncher;
use Shipard\Core\DevJobs\JobRecord;
use Shipard\Core\DevJobs\JobStep;
use Shipard\Core\DevJobs\JobStore;
use Shipard\Core\DevUpdate\Git;
use Shipard\Core\DevUpdate\UpdateStateStore;
use Shipard\Core\DevUpdate\UpdateStatus;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Server\DataSourceInfo;
use Shipard\Core\Server\DataSourceInventory;
use Shipard\Core\Server\MainConfigPatcher;
use Shipard\Core\Utils\IdGenerator;
use Shipard\Core\Version;
use Shipard\Module\Core\Exchange\Dataset\DatasetCatalog;
use Shipard\Module\Core\Exchange\Dataset\DatasetCatalogEntry;

class DevDashboardController
{
	/**
	 * Stránky SPA (`public/_dev/dev.html`, `frontend/vite.dev.config.js`):
	 * první segment cesty pod `/_dev/`, pro který se vrací index. Jen
	 * `testing` má podcesty. Neznámá cesta pod `/_dev/` dál vrací 404 JSON.
	 * Tabulka rout na klientu (`frontend/src/dev/routes.js`) si s tímto
	 * seznamem musí odpovídat.
	 */
	private const array SPA_PAGES = ['logs', 'ds-create', 'doctor', 'upgrade-all', 'ds-upgrade', 'testing', 'videos'];
	/** Zrušení úlohy smazání: ds-delete běží sekundy, SIGTERM stačí. */
	public const int DELETE_CANCEL_GRACE_SECONDS = 60;

	private readonly string $repoRoot;
	private readonly string $spaIndexPath;
	/** Režim serveru pro pojistku spouštěcích akcí (#103 F6b D60); null = neznámý = ne development. */
	private readonly ?string $serverMode;
	private ?JobStore $jobStore;
	private ?JobLauncher $jobLauncher;

	public function __construct(
		private readonly string $dataSourcesDir = '/opt/shipard/data-sources',
		private readonly ?string $logFilePath = null,
		private readonly ?ModulePathResolver $modulePathResolver = null,
		?string $spaIndexPath = null,
		?string $repoRoot = null,
		?string $serverMode = null,
		?JobStore $jobStore = null,
		?JobLauncher $jobLauncher = null,
	) {
		$this->repoRoot     = $repoRoot ?? dirname(__DIR__, 3);
		$this->spaIndexPath = $spaIndexPath ?? $this->repoRoot . '/public/_dev/dev.html';
		$this->serverMode   = $serverMode;
		$this->jobStore     = $jobStore;
		$this->jobLauncher  = $jobLauncher;
	}

	public function dispatch(Request $request): Response
	{
		$path   = $request->getPath();
		$method = $request->getMethod();

		if ($path === '/') {
			return Response::redirect('/_dev/');
		}

		// Úlohy na pozadí a spouštěcí akce evaluací (#103 F6b D54–D63) — před Testováním:
		// to je GET-only a POST by vrátilo 404.
		if ($path === DevJobsController::PREFIX || str_starts_with($path, DevJobsController::PREFIX . '/')) {
			return (new DevJobsController($this->jobStore(), $this->jobLauncher()))->dispatch($request);
		}
		if ($path === DevEvalController::PREFIX || str_starts_with($path, DevEvalController::PREFIX . '/')) {
			return (new DevEvalController($this->repoRoot, $this->dataSourcesDir, $this->serverMode(), $this->jobStore(), $this->jobLauncher()))->dispatch($request);
		}
		// Testovací kolo (#103 F8a D130–D134): poslední kolo, odhad, spuštění úlohy `round`.
		if ($path === DevRoundController::PREFIX || str_starts_with($path, DevRoundController::PREFIX . '/')) {
			return (new DevRoundController($this->repoRoot, $this->dataSourcesDir, $this->serverMode(), $this->jobStore(), $this->jobLauncher()))->dispatch($request);
		}
		// Zastaralost checkoutu a Aktualizovat (#129 D18–D21).
		if ($path === DevUpdatesController::PREFIX || str_starts_with($path, DevUpdatesController::PREFIX . '/')) {
			return $this->updatesController()->dispatch($request);
		}

		// Oddíl Videa (#103 F7b D122, D126, D127): scénáře, úlohy video / create, video s Range.
		if ($path === DevVideosController::PREFIX || str_starts_with($path, DevVideosController::PREFIX . '/')) {
			return (new DevVideosController($this->repoRoot, $this->dataSourcesDir, $this->serverMode(), $this->jobStore(), $this->jobLauncher()))->dispatch($request);
		}

		// Sekce Testování (#103 D51–D53) — jen čtení souborů v checkoutu.
		if ($path === DevTestingController::PREFIX || str_starts_with($path, DevTestingController::PREFIX . '/')) {
			return (new DevTestingController($this->repoRoot))->dispatch($request);
		}

		// JSON API a streamované akce — kontrakt beze změny (#103 D57).
		if ($path === '/_dev/api/info' && $method === 'GET') {
			return $this->getInfo();
		}
		if ($path === '/_dev/api/data-sources' && $method === 'GET') {
			return $this->listDataSources();
		}
		// Smazání zdroje dat jako úloha na pozadí (tasks/ds-delete.md § 2, D70).
		if ($method === 'POST' && preg_match('#^/_dev/api/data-sources/([^/]+)/delete$#', $path, $m) === 1) {
			return $this->deleteDataSource(rawurldecode($m[1]), $request);
		}
		// Nahrát datovou sadu do volného zdroje dat jako úloha na pozadí (#103 D66, #131 i demo sady).
		if ($method === 'POST' && preg_match('#^/_dev/api/data-sources/([^/]+)/reseed$#', $path, $m) === 1) {
			return $this->reseedDataSource(rawurldecode($m[1]), $request);
		}
		if ($path === '/_dev/api/datasets' && $method === 'GET') {
			return $this->listDatasets();
		}
		if ($path === '/_dev/api/install-modules' && $method === 'GET') {
			return $this->getInstallModules();
		}
		if ($path === '/_dev/api/logs' && $method === 'GET') {
			return $this->getLogs($request);
		}
		if ($path === '/_dev/api/ds-create' && $method === 'POST') {
			return $this->dsCreate($request);
		}
		if ($path === '/_dev/api/doctor' && $method === 'POST') {
			return $this->runDoctor();
		}
		if ($path === '/_dev/api/upgrade-all' && $method === 'POST') {
			return $this->runUpgradeAll();
		}
		if ($path === '/_dev/api/ds-upgrade' && $method === 'POST') {
			return $this->runDsUpgrade($request);
		}

		if ($this->isSpaPath($path)) {
			return $this->spa();
		}

		return Response::error('NOT_FOUND', 'Not found', 404);
	}

	/** `/_dev`, `/_dev/` a stránky ze SPA_PAGES — s lomítkem i bez, `testing` i s podcestami. */
	private function isSpaPath(string $path): bool
	{
		if ($path === '/_dev' || $path === '/_dev/') {
			return true;
		}
		if (!str_starts_with($path, '/_dev/')) {
			return false;
		}
		$rel   = substr($path, strlen('/_dev/'));
		$first = explode('/', $rel, 2)[0];
		if (!in_array($first, self::SPA_PAGES, true)) {
			return false;
		}
		return $first === 'testing' || $rel === $first || $rel === $first . '/';
	}

	/**
	 * Index SPA dashboardu. Bez buildu (`scripts/dev-update.sh`) 503 s návodem
	 * místo prázdné stránky — soubor je build artefakt mimo git.
	 */
	private function spa(): Response
	{
		$html = is_file($this->spaIndexPath) ? @file_get_contents($this->spaIndexPath) : false;
		if ($html === false) {
			return Response::html(<<<'HTML'
				<!doctype html>
				<html lang="cs"><head><meta charset="utf-8"><title>Shipard — dashboard není sestavený</title></head>
				<body style="font-family: system-ui, sans-serif; max-width: 640px; margin: 3rem auto; line-height: 1.5">
				<h1>Vývojářský dashboard není sestavený</h1>
				<p>Chybí <code>public/_dev/dev.html</code>. Sestav frontend a obnov stránku:</p>
				<pre>bash scripts/dev-update.sh</pre>
				<p>(nebo <code>cd frontend &amp;&amp; npm run build</code>)</p>
				</body></html>
				HTML, 503);
		}
		return Response::html($html);
	}

	/**
	 * Údaje, které dřív vkládalo PHP do HTML: server, adresář zdrojů dat,
	 * dostupnost sekce Testování (`testing`: `tests/datasets` + `tests/eval`)
	 * a sad k seedu (`datasets`: {@see DatasetCatalog} — `tests/datasets`
	 * nebo `demo/datasets`, #131); od #129 D9 i `version` — číslo verze a HEAD
	 * gitu pro pruh dashboardu. Bez gitu nebo s jeho odmítnutím (PHP-FPM pod
	 * jiným uživatelem než vlastník checkoutu) jsou `hash`, `fullHash`,
	 * `branch` a `commitDate` null a pruh ukáže jen číslo verze.
	 */
	private function getInfo(): Response
	{
		$git = Version::gitInfo();
		return Response::success([
			'hostname'       => gethostname() ?: 'unknown',
			'dataSourcesDir' => $this->dataSourcesDir,
			'testing'        => is_dir($this->repoRoot . '/tests/datasets') && is_dir($this->repoRoot . '/tests/eval'),
			'datasets'       => (new DatasetCatalog($this->repoRoot))->isAvailable(),
			'videos'         => is_dir($this->repoRoot . '/demo/scenarios') && is_dir($this->repoRoot . '/tools/video-runner'),
			'version'        => [
				'version'    => Version::VERSION,
				'hash'       => $git['hash'] ?? null,
				'fullHash'   => $git['fullHash'] ?? null,
				'branch'     => $git['branch'] ?? null,
				'commitDate' => $git['commitDate'] ?? null,
			],
		]);
	}

	/**
	 * Seznam zdrojů dat přes sdílený čtenář {@see DataSourceInventory} (#126
	 * D10) — allowlist polí, bez tajemství. Nečitelný `main.json` se v seznamu
	 * neukazuje (jako dřív). Stávající klíče zůstávají, nové: `dataClass`,
	 * `dataClassExplicit`, `purpose`, `origin`, `dataClassChanged`, `serverAi`
	 * (efektivní serverové AI připojení: server ho nabízí a zdroj je `free`);
	 * pro akci „Nahrát datovou sadu“ (#103 D66) `country`, `dataset` (pole
	 * z `main.json`), `legacyDataset` (sada jen z původu `dataset-seed <sada>`,
	 * před F6b) a `job` (běžící úloha na zdroji dat, `{id, type, status}`).
	 */
	private function listDataSources(): Response
	{
		$serverOffersAi = ServerAiConnection::detect() !== null;
		$jobs = $this->jobStore();
		$items = [];
		foreach ((new DataSourceInventory($this->dataSourcesDir))->scan() as $info) {
			if (!$info->readable) {
				continue;
			}
			$job = $jobs->activeJobFor($info->id);
			// #115 D11: přepínač periodických úloh + efektivní stav, jen zobrazení.
			$items[] = [
				'id'                => $info->id,
				'name'              => $info->name,
				'created'           => $info->created,
				'database_name'     => $info->databaseName,
				'cron'              => $info->cronEnabled,
				'state'             => $info->state,
				'dataClass'         => $info->dataClass->value,
				'dataClassExplicit' => $info->dataClassExplicit,
				'purpose'           => $info->purpose,
				'origin'            => $info->origin,
				'dataClassChanged'  => $info->dataClassChanged,
				'serverAi'          => $info->usesServerAi($serverOffersAi),
				'country'           => $info->country,
				'dataset'           => $info->dataset,
				'legacyDataset'     => $info->dataset === null ? $info->heldDataset() : null,
				'job'               => $job === null ? null : ['id' => $job['id'], 'type' => $job['type'], 'status' => $job['status']],
			];
		}

		usort($items, fn($a, $b) => strcmp(
			mb_strtolower((string) $a['name']),
			mb_strtolower((string) $b['name']),
		));

		return Response::success($items);
	}

	private function getInstallModules(): Response
	{
		if ($this->modulePathResolver === null) {
			return Response::error(
				'MODULES_NOT_CONFIGURED',
				'Modules directory not configured',
				503,
			);
		}

		$registry = new \Shipard\Core\Module\InstallModuleRegistry($this->modulePathResolver);
		return Response::success($registry->list());
	}

	private function getLogs(Request $request): Response
	{
		if ($this->logFilePath === null) {
			return Response::error(
				'LOG_NOT_CONFIGURED',
				'Log file path not configured',
				503,
			);
		}

		$limit = (int) ($request->getQueryParams()['limit'] ?? 200);
		$limit = max(1, min(2000, $limit));

		$logFile = $this->logFilePath;

		if (!is_file($logFile)) {
			return Response::success([
				'entries'   => [],
				'logFile'   => $logFile,
				'available' => false,
				'limit'     => $limit,
			]);
		}

		$tail     = new \Shipard\Core\Logging\LogTail($logFile);
		$rawLines = $tail->readLast($limit);

		$entries = [];
		foreach ($rawLines as $line) {
			$parsed = json_decode($line, true);
			// Validate only the minimal contract — `ts` and `level`. Anything else
			// may be missing (older entries, partial migration).
			if (is_array($parsed) && isset($parsed['ts'], $parsed['level'])) {
				$entries[] = $parsed;
			}
			// Invalid lines are silently skipped (typically a partial first line
			// from the chunked read when limit ~= total line count).
		}

		return Response::success([
			'entries'   => $entries,
			'logFile'   => $logFile,
			'available' => true,
			'limit'     => $limit,
		]);
	}

	/**
	 * Sady k seedu z checkoutu (#131): testovací z `tests/datasets` i demo
	 * z `demo/datasets` ({@see DatasetCatalog}) — pro pole Data formuláře
	 * Nový zdroj dat a dialog Nahrát datovou sadu. Bez obou adresářů prázdný
	 * seznam (formulář pole nenabízí podle `datasets` v `/_dev/api/info`).
	 */
	private function listDatasets(): Response
	{
		return Response::success(array_map(
			static fn (DatasetCatalogEntry $dataset): array => $dataset->toArray(),
			(new DatasetCatalog($this->repoRoot))->datasets(),
		));
	}

	/**
	 * Založení zdroje dat z formuláře (tasks/testing-phase6c.md D64, D65):
	 * `dataset` = název sady z katalogu ({@see DatasetCatalog} — testovací
	 * z `tests/datasets` i demo z `demo/datasets`, #131), prázdný / chybějící
	 * = bez dat; se sadou musí země formuláře sedět se zemí sady (`dataset-seed`
	 * to v preflightu kontroluje znovu). Staré `seed` (náhodné osoby a pošta)
	 * zaniklo (D67) a ignoruje se.
	 */
	private function dsCreate(Request $request): Response
	{
		$body = $request->getBody() ?? [];

		$name     = is_string($body['name'] ?? null) ? trim($body['name']) : '';
		$login    = is_string($body['login'] ?? null) ? trim($body['login']) : '';
		$password = is_string($body['password'] ?? null) ? $body['password'] : '';
		$datasetName = is_string($body['dataset'] ?? null) ? trim($body['dataset']) : '';
		$module   = is_string($body['module'] ?? null) && trim($body['module']) !== ''
			? trim($body['module'])
			: 'install.base';
		$language = is_string($body['language'] ?? null) ? trim($body['language']) : '';
		$country  = is_string($body['country'] ?? null) ? trim($body['country']) : '';
		$purpose  = is_string($body['purpose'] ?? null) ? $body['purpose'] : '';

		$errors = $this->validateDsCreateInput($name, $login, $password, $module, $language, $country);
		// Účel (#126 D6): jediná validace je MainConfigPatcher::normalizePurpose
		// (sdílí ji ds-create i ds-class); prázdný = bez účelu.
		try {
			$purpose = MainConfigPatcher::normalizePurpose($purpose);
		} catch (\InvalidArgumentException $e) {
			$errors[] = 'Purpose: ' . $e->getMessage();
		}
		// Sada jen z katalogu — do příkazu jde její adresář z výpisu, nikdy text z požadavku.
		$dataset = null;
		if ($datasetName !== '') {
			$catalog = new DatasetCatalog($this->repoRoot);
			if (!$catalog->isAvailable()) {
				$errors[] = 'Datasets are not available on this server (no tests/datasets or demo/datasets checkout).';
			} else {
				$dataset = $catalog->dataset($datasetName);
				if ($dataset === null) {
					$errors[] = "Unknown dataset '{$datasetName}'.";
				} elseif ($dataset->country !== null && $dataset->country !== $country) {
					$errors[] = "Country must match the dataset ({$dataset->country}).";
				}
			}
		}
		if ($errors) {
			return Response::error('VALIDATION', implode(' ', $errors), 400);
		}

		return Response::stream(function () use ($name, $login, $password, $dataset, $module, $language, $country, $purpose) {
			$this->runDsCreatePipeline($name, $login, $password, $dataset, $module, $language, $country, $purpose);
		});
	}

	/**
	 * @return list<string>
	 */
	private function validateDsCreateInput(
		string $name,
		string $login,
		string $password,
		string $module,
		string $language,
		string $country,
	): array {
		$errors = [];

		if ($name === '') {
			$errors[] = 'Name is required.';
		} elseif (mb_strlen($name) > 200) {
			$errors[] = 'Name is too long (max 200 characters).';
		}

		if ($login === '') {
			$errors[] = 'Admin login is required.';
		} elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $login)) {
			$errors[] = 'Admin login may contain only letters, digits, and underscores.';
		} elseif (mb_strlen($login) > 64) {
			$errors[] = 'Admin login is too long (max 64 characters).';
		}

		if ($password === '') {
			$errors[] = 'Admin password is required.';
		} elseif (mb_strlen($password) < 4) {
			$errors[] = 'Admin password must be at least 4 characters.';
		}

		if ($module === '') {
			$errors[] = 'Install module is required.';
		} elseif (!preg_match('/^install\.[a-z][a-zA-Z0-9]*$/', $module)) {
			$errors[] = 'Invalid install module id.';
		} elseif ($this->modulePathResolver !== null) {
			$registry = new \Shipard\Core\Module\InstallModuleRegistry($this->modulePathResolver);
			if (!$registry->exists($module)) {
				$errors[] = 'Install module "' . $module . '" not found.';
			}
		}

		// Same shape rules as DsCreateCommand — fail here with a 400 instead
		// of letting the error surface mid-stream from the subprocess.
		if (!in_array($language, ['cs', 'en'], true)) {
			$errors[] = 'Language is required (cs|en).';
		}

		if (!preg_match('/^[a-z]{2}$/', $country)) {
			$errors[] = 'Country is required (ISO 3166-1 alpha-2, e.g. cz).';
		}

		return $errors;
	}

	/**
	 * `POST /_dev/api/data-sources/{id}/delete` — smazání volného zdroje dat
	 * jako úloha na pozadí (tasks/ds-delete.md § 2, D70). Pojistka D60
	 * ({@see DevActionGuard}: režim development, zdroj dat čitelný a s třídou
	 * free → 403 `FORBIDDEN` / 404 `DS_NOT_FOUND` / 403 `DS_NOT_FREE`), jedna
	 * úloha na zdroj dat ({@see JobLauncher} → 409 `JOB_RUNNING`), jediný krok
	 * `shpd-server ds-delete <id> --yes --job <id úlohy>` s cwd v kořeni
	 * repozitáře — adresář zdroje dat během úlohy zmizí. Preflight CLI (třída,
	 * běžící úloha) tak běží dvakrát: API odmítne dřív a srozumitelně, CLI je
	 * poslední pojistka v procesu úlohy; `--job` mu říká, která běžící úloha
	 * je ta jeho, jinak by se odmítl sám. Routa je tady, ne v `DevEvalController`: ten bez
	 * `tests/` vrací 404 `TESTING_UNAVAILABLE` a mazání na katalogu sad
	 * nesmí záviset. Po doběhnutí úlohy zdroj dat ze seznamu zmizí sám
	 * (adresář neexistuje); záznam úlohy zůstává v historii.
	 */
	private function deleteDataSource(string $id, Request $request): Response
	{
		if (preg_match(IdGenerator::ID_PATTERN, $id) !== 1) {
			return Response::error('NOT_FOUND', 'Not found', 404);
		}
		$info = (new DataSourceInventory($this->dataSourcesDir))->read(rtrim($this->dataSourcesDir, '/') . '/' . $id);
		$problem = DevActionGuard::check($this->serverMode(), $info, true, null, []);
		if ($problem !== null) {
			return Response::error($problem['code'], $problem['message'], $problem['status']);
		}
		/** @var DataSourceInfo $info pojistka s requireDataSource = true prošla */
		$now = $this->now();
		// ID úlohy dopředu: ds-delete ho dostane v --job, aby vlastní běžící úlohu
		// nepovažoval za cizí a neodmítl se (JobStore::activeJobFor).
		$jobId = JobRecord::newId($now);
		$record = new JobRecord(
			$jobId,
			JobRecord::TYPE_DELETE,
			$info->id,
			$info->name,
			null,
			null,
			[],
			[new JobStep('ds-delete ' . $id, [...$this->serverCommand(), 'ds-delete', $id, '--yes', '--job', $jobId], $this->repoRoot)],
			JobStore::formatTime($now),
			$request->getClientIp(),
			self::DELETE_CANCEL_GRACE_SECONDS,
		);
		return $this->launchJob($record);
	}

	/**
	 * Nahrát datovou sadu do existujícího volného zdroje dat (#103 D66, od
	 * #131 i demo sady): úloha `reseed` = `dataset-seed <adresář sady
	 * z katalogu> --yes` v adresáři zdroje dat. Pojistka {@see DevActionGuard}
	 * (jen development, jen třída free, sada ze seznamu {@see DatasetCatalog});
	 * zdroj dat, který drží jinou sadu ({@see DataSourceInfo::heldDataset()}),
	 * přijme jen `replaceDataset: true`, jinak 409 `DATASET_MISMATCH`
	 * s `details.current` / `requested`. Sekce Testování má vlastní `reseed`
	 * ({@see DevEvalController}) jen nad testovacími sadami.
	 */
	private function reseedDataSource(string $id, Request $request): Response
	{
		if (preg_match(IdGenerator::ID_PATTERN, $id) !== 1) {
			return Response::error('NOT_FOUND', 'Not found', 404);
		}
		$body = $request->getBody() ?? [];
		$name = $body['dataset'] ?? null;
		if (!is_string($name) || $name === '') {
			return Response::error(DevActionGuard::CODE_UNKNOWN_DATASET, 'dataset (set name from the listing) is required', 400);
		}
		$catalog = new DatasetCatalog($this->repoRoot);
		$info = (new DataSourceInventory($this->dataSourcesDir))->read(rtrim($this->dataSourcesDir, '/') . '/' . $id);
		$problem = DevActionGuard::check($this->serverMode(), $info, true, $name, $catalog->datasetNames());
		if ($problem !== null) {
			return Response::error($problem['code'], $problem['message'], $problem['status']);
		}
		/** @var DataSourceInfo $info @var DatasetCatalogEntry $dataset pojistka prošla — zdroj dat čitelný, sada z výpisu */
		$dataset = $catalog->dataset($name);
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
		$now = $this->now();
		$record = new JobRecord(
			JobRecord::newId($now),
			JobRecord::TYPE_RESEED,
			$info->id,
			$info->name,
			$dataset->name,
			null,
			$replace ? ['replaceDataset' => true, 'previousDataset' => $held] : [],
			[new JobStep(
				'dataset-seed ' . $dataset->name,
				[...$this->dsCommand(), 'dataset-seed', $dataset->dir, '--yes'],
				rtrim($this->dataSourcesDir, '/') . '/' . $info->id,
			)],
			JobStore::formatTime($now),
			$request->getClientIp(),
			DevEvalController::DEFAULT_CANCEL_GRACE_SECONDS,
		);
		return $this->launchJob($record);
	}

	/** Spuštění úlohy jako odpověď: 201 se záznamem, 409 `JOB_RUNNING` při jiné aktivní úloze na zdroji dat. */
	private function launchJob(JobRecord $record): Response
	{
		try {
			return Response::success(['job' => $this->jobLauncher()->launch($record)], 201);
		} catch (JobConflict $e) {
			return Response::error('JOB_RUNNING', $e->getMessage(), 409, ['jobId' => $e->runningJobId, 'dataSource' => $e->dataSourceId]);
		} catch (\RuntimeException $e) {
			return Response::error('JOB_LAUNCH_FAILED', $e->getMessage(), 500);
		}
	}

	/** Argv prefix `shpd-server` pro kroky úloh (seam pro testy). @return list<string> */
	protected function serverCommand(): array
	{
		return BinPaths::shpdServerCommand();
	}

	/** Argv prefix `shpd-ds` pro kroky úloh (seam pro testy). @return list<string> */
	protected function dsCommand(): array
	{
		return BinPaths::shpdDsCommand();
	}

	protected function now(): \DateTimeImmutable
	{
		return new \DateTimeImmutable();
	}

	protected function getShpdServerPath(): string
	{
		return dirname(__DIR__, 3) . '/bin/shpd-server';
	}

	/** Režim serveru pro pojistku D60 — bez předání `unknown` (fail-closed, nikdy development). */
	protected function serverMode(): string
	{
		return $this->serverMode ?? 'unknown';
	}

	protected function jobStore(): JobStore
	{
		return $this->jobStore ??= new JobStore(JobStore::defaultDir());
	}

	protected function jobLauncher(): JobLauncher
	{
		return $this->jobLauncher ??= new JobLauncher($this->jobStore());
	}

	/** Controller aktualizací nad skutečným checkoutem a cache v run adresáři (seam pro testy). */
	protected function updatesController(): DevUpdatesController
	{
		$status = new UpdateStatus(new Git($this->repoRoot), new UpdateStateStore(UpdateStateStore::defaultDir()), $this->jobStore());
		return new DevUpdatesController($this->serverMode(), $this->repoRoot, $this->jobStore(), $this->jobLauncher(), $status, $this->serverCommand());
	}

	protected function getShpdDsPath(): string
	{
		return dirname(__DIR__, 3) . '/bin/shpd-ds';
	}

	/**
	 * `ds-create` → `ds-upgrade` → `user-create` (heslo přes stdin, D65) →
	 * `dataset-seed <sada> --yes` (jen se sadou, D64). Admin vzniká před seedem:
	 * `ds-reset` uvnitř `dataset-seed` uživatele zachová (`keepOnReset`), takže
	 * i po selhání seedu je zdroj dat s adminem použitelný.
	 */
	private function runDsCreatePipeline(
		string $name,
		string $login,
		string $password,
		?DatasetCatalogEntry $dataset,
		string $module,
		string $language,
		string $country,
		string $purpose = '',
	): void {
		$shpdServer = $this->getShpdServerPath();
		$shpdDs     = $this->getShpdDsPath();

		// ── 1. ds-create ────────────────────────────────────────────────
		// Zdroj dat založený z dashboardu je ve vývoji `free` (#126 D7);
		// --purpose jen neprázdný.
		$this->emitStep('Creating data source...');
		$cmd = sprintf(
			'%s ds-create --name=%s --module=%s --language=%s --country=%s%s --no-ansi 2>&1',
			escapeshellarg($shpdServer),
			escapeshellarg($name),
			escapeshellarg($module),
			escapeshellarg($language),
			escapeshellarg($country),
			$purpose !== '' ? ' --purpose=' . escapeshellarg($purpose) : '',
		);
		[$exitCode, $output] = $this->streamCommand($cmd);

		if ($exitCode !== 0) {
			$this->emitError('ds-create failed (exit ' . $exitCode . ')');
			return;
		}

		if (!preg_match('~Directory:\s+(\S+)~', $output, $m)) {
			$this->emitError('Could not parse data source directory from output');
			return;
		}
		$dsDir = $m[1];
		$dsId  = basename($dsDir);

		// ── 2. ds-upgrade ───────────────────────────────────────────────
		$this->emitStep('Running ds-upgrade...');
		$cmd = sprintf(
			'cd %s && %s ds-upgrade --no-ansi 2>&1',
			escapeshellarg($dsDir),
			escapeshellarg($shpdDs),
		);
		[$exitCode] = $this->streamCommand($cmd);

		if ($exitCode !== 0) {
			$this->emitError(
				'ds-upgrade failed — DS ' . $dsId . ' was created but is not usable. '
				. 'Check the directory and run upgrade manually.'
			);
			return;
		}

		// ── 3. user-create — heslo přes stdin, ne v argv (D65) ──────────
		$this->emitStep('Creating admin user "' . $login . '"...');
		[$exitCode] = $this->streamProcess(
			[$shpdDs, 'user-create', '--login', $login, '--password-stdin', '--name', $login, '--admin', '--no-ansi'],
			$dsDir,
			$password . "\n",
			redactPassword: true,
		);

		if ($exitCode !== 0) {
			$this->emitError(
				'user-create failed — DS ' . $dsId . ' was upgraded but has no admin user.'
			);
			return;
		}

		// ── 4. dataset-seed (jen se sadou, D64) ─────────────────────────
		if ($dataset !== null) {
			$this->emitStep('Seeding dataset ' . $dataset->name . '...');
			$cmd = sprintf(
				'cd %s && %s dataset-seed %s --yes --no-ansi 2>&1',
				escapeshellarg($dsDir),
				escapeshellarg($shpdDs),
				escapeshellarg($dataset->dir),
			);
			[$exitCode] = $this->streamCommand($cmd);
			if ($exitCode !== 0) {
				$this->emitError(
					'dataset-seed failed — DS ' . $dsId . ' and its admin user are usable, but the dataset '
					. $dataset->name . ' is not loaded. Retry with "Nahrát datovou sadu" in the data source list.'
				);
				return;
			}
		}

		$this->emitDone($dsId, $dataset?->name);
	}

	/**
	 * Run a shell command, stream its output to the client line-by-line, and
	 * capture it for parsing.
	 *
	 * @return array{0: int, 1: string} [exitCode, capturedOutput]
	 */
	protected function streamCommand(string $cmd, bool $redactPassword = false): array
	{
		$proc = popen($cmd, 'r');
		if ($proc === false) {
			return [-1, ''];
		}

		$captured = '';
		while (($line = fgets($proc)) !== false) {
			$emitted = $redactPassword
				? preg_replace('/--password=\S+/', '--password=***', $line)
				: $line;

			echo $emitted;
			flush();

			$captured .= $line;
		}

		$status = pclose($proc);
		$exitCode = ($status === -1) ? -1 : (($status >> 8) & 0xFF);

		return [$exitCode, $captured];
	}

	/**
	 * Podproces s argv (bez shellu) a volitelným vstupem na stdin — pro kroky,
	 * které dostávají tajemství (`user-create --password-stdin`, D65): hodnota
	 * nejde do argv ani do výpisu procesů. Stdout i stderr se streamují
	 * po řádcích jako u {@see streamCommand}; `redactPassword` zůstává jako
	 * obrana do hloubky.
	 *
	 * @param list<string> $argv
	 * @return array{0: int, 1: string} [exitCode, capturedOutput]
	 */
	protected function streamProcess(array $argv, string $cwd, ?string $stdin = null, bool $redactPassword = false): array
	{
		$descriptors = [
			0 => $stdin === null ? ['file', '/dev/null', 'r'] : ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['redirect', 1],
		];
		$proc = $argv === [] ? false : @proc_open($argv, $descriptors, $pipes, $cwd);
		if (!is_resource($proc)) {
			return [-1, ''];
		}
		if ($stdin !== null) {
			fwrite($pipes[0], $stdin);
			fclose($pipes[0]);
		}

		$captured = '';
		while (($line = fgets($pipes[1])) !== false) {
			$emitted = $redactPassword
				? preg_replace('/--password=\S+/', '--password=***', $line)
				: $line;

			echo $emitted;
			flush();

			$captured .= $line;
		}
		fclose($pipes[1]);

		$exitCode = proc_close($proc);
		return [$exitCode, $captured];
	}

	private function emitStep(string $msg): void
	{
		echo "[STEP] " . $msg . "\n";
		flush();
	}

	private function emitError(string $msg): void
	{
		echo "[ERROR] " . $msg . "\n";
		flush();
	}

	private function emitDone(string $dsId, ?string $dataset = null): void
	{
		$payload = json_encode(
			['id' => $dsId, 'url' => '/' . $dsId . '/app/', 'dataset' => $dataset],
			JSON_UNESCAPED_SLASHES,
		);
		echo "[DONE] " . $payload . "\n";
		flush();
	}

	private function emitDoneMessage(string $message): void
	{
		echo "[DONE] " . json_encode(['message' => $message], JSON_UNESCAPED_SLASHES) . "\n";
		flush();
	}

	private const DS_ID_REGEX = '/^[a-z0-9]{4}(-[a-z0-9]{4}){3}$/';

	private function runDoctor(): Response
	{
		return Response::stream(function () {
			$shpdServer = $this->getShpdServerPath();
			$cmd = sprintf('%s doctor --no-ansi 2>&1', escapeshellarg($shpdServer));
			[$exitCode] = $this->streamCommand($cmd);

			if ($exitCode === 0) {
				$this->emitDoneMessage('Doctor completed without issues');
			} else {
				$this->emitError('Doctor reported issues (exit ' . $exitCode . ')');
			}
		});
	}

	private function runUpgradeAll(): Response
	{
		return Response::stream(function () {
			$shpdServer = $this->getShpdServerPath();

			$this->emitStep('Upgrading all data sources...');
			$cmd = sprintf('%s ds-upgrade-all --no-ansi 2>&1', escapeshellarg($shpdServer));
			[$exitCode] = $this->streamCommand($cmd);

			if ($exitCode === 0) {
				$this->emitDoneMessage('All data sources upgraded successfully');
			} else {
				$this->emitError('Upgrade-all reported failures (exit ' . $exitCode . ')');
			}
		});
	}

	private function runDsUpgrade(Request $request): Response
	{
		$dsId = $request->getQueryParams()['ds'] ?? '';

		if (!is_string($dsId) || !preg_match(self::DS_ID_REGEX, $dsId)) {
			return Response::error('INVALID_DS_ID', 'Invalid or missing ?ds=<id> parameter', 400);
		}

		$dsDir = $this->dataSourcesDir . '/' . $dsId;
		if (!is_file($dsDir . '/config/main.json')) {
			return Response::error('DS_NOT_FOUND', 'Data source not found: ' . $dsId, 404);
		}

		return Response::stream(function () use ($dsId, $dsDir) {
			$shpdDs = $this->getShpdDsPath();

			$this->emitStep('Upgrading data source ' . $dsId . '...');
			$cmd = sprintf(
				'cd %s && %s ds-upgrade --no-ansi 2>&1',
				escapeshellarg($dsDir),
				escapeshellarg($shpdDs),
			);
			[$exitCode] = $this->streamCommand($cmd);

			if ($exitCode === 0) {
				$this->emitDoneMessage('Data source ' . $dsId . ' upgraded successfully');
			} else {
				$this->emitError('Upgrade failed for ' . $dsId . ' (exit ' . $exitCode . ')');
			}
		});
	}
}

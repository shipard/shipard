<?php
declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Ai\ServerAiConnection;
use Shipard\Core\DevJobs\JobLauncher;
use Shipard\Core\DevJobs\JobStore;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Server\DataSourceInventory;
use Shipard\Core\Server\MainConfigPatcher;

class DevDashboardController
{
	/**
	 * Stránky SPA (`public/_dev/dev.html`, `frontend/vite.dev.config.js`):
	 * první segment cesty pod `/_dev/`, pro který se vrací index. Jen
	 * `testing` má podcesty. Neznámá cesta pod `/_dev/` dál vrací 404 JSON.
	 * Tabulka rout na klientu (`frontend/src/dev/routes.js`) si s tímto
	 * seznamem musí odpovídat.
	 */
	private const array SPA_PAGES = ['logs', 'ds-create', 'doctor', 'upgrade-all', 'ds-upgrade', 'testing'];

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

	/** Údaje, které dřív vkládalo PHP do HTML: server, adresář zdrojů dat, dostupnost sekce Testování. */
	private function getInfo(): Response
	{
		return Response::success([
			'hostname'       => gethostname() ?: 'unknown',
			'dataSourcesDir' => $this->dataSourcesDir,
			'testing'        => is_dir($this->repoRoot . '/tests/datasets') && is_dir($this->repoRoot . '/tests/eval'),
		]);
	}

	/**
	 * Seznam zdrojů dat přes sdílený čtenář {@see DataSourceInventory} (#126
	 * D10) — allowlist polí, bez tajemství. Nečitelný `main.json` se v seznamu
	 * neukazuje (jako dřív). Stávající klíče zůstávají, nové: `dataClass`,
	 * `dataClassExplicit`, `purpose`, `origin`, `dataClassChanged`, `serverAi`
	 * (efektivní serverové AI připojení: server ho nabízí a zdroj je `free`).
	 */
	private function listDataSources(): Response
	{
		$serverOffersAi = ServerAiConnection::detect() !== null;
		$items = [];
		foreach ((new DataSourceInventory($this->dataSourcesDir))->scan() as $info) {
			if (!$info->readable) {
				continue;
			}
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

	private function dsCreate(Request $request): Response
	{
		$body = $request->getBody() ?? [];

		$name     = is_string($body['name'] ?? null) ? trim($body['name']) : '';
		$login    = is_string($body['login'] ?? null) ? trim($body['login']) : '';
		$password = is_string($body['password'] ?? null) ? $body['password'] : '';
		$seed     = (bool) ($body['seed'] ?? false);
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
		if ($errors) {
			return Response::error('VALIDATION', implode(' ', $errors), 400);
		}

		return Response::stream(function () use ($name, $login, $password, $seed, $module, $language, $country, $purpose) {
			$this->runDsCreatePipeline($name, $login, $password, $seed, $module, $language, $country, $purpose);
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

	protected function getShpdDsPath(): string
	{
		return dirname(__DIR__, 3) . '/bin/shpd-ds';
	}

	private function runDsCreatePipeline(
		string $name,
		string $login,
		string $password,
		bool $seed,
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

		// ── 3. user-create ──────────────────────────────────────────────
		$this->emitStep('Creating admin user "' . $login . '"...');
		$cmd = sprintf(
			'cd %s && %s user-create --login=%s --password=%s --name=%s --admin --no-ansi 2>&1',
			escapeshellarg($dsDir),
			escapeshellarg($shpdDs),
			escapeshellarg($login),
			escapeshellarg($password),
			escapeshellarg($login),
		);
		[$exitCode] = $this->streamCommand($cmd, redactPassword: true);

		if ($exitCode !== 0) {
			$this->emitError(
				'user-create failed — DS ' . $dsId . ' was upgraded but has no admin user.'
			);
			return;
		}

		// ── 4. seed (optional) ──────────────────────────────────────────
		if ($seed) {
			$this->emitStep('Seeding test persons...');
			$cmd = sprintf(
				'cd %s && %s seed-persons --no-ansi 2>&1',
				escapeshellarg($dsDir),
				escapeshellarg($shpdDs),
			);
			[$exitCode] = $this->streamCommand($cmd);
			if ($exitCode !== 0) {
				$this->emitError('seed-persons failed (DS is otherwise usable)');
				return;
			}

			$this->emitStep('Seeding test mail...');
			$cmd = sprintf(
				'cd %s && %s seed-mail --no-ansi 2>&1',
				escapeshellarg($dsDir),
				escapeshellarg($shpdDs),
			);
			[$exitCode] = $this->streamCommand($cmd);
			if ($exitCode !== 0) {
				$this->emitError('seed-mail failed (DS is otherwise usable)');
				return;
			}
		}

		$this->emitDone($dsId);
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

	private function emitDone(string $dsId): void
	{
		$payload = json_encode(
			['id' => $dsId, 'url' => '/' . $dsId . '/app/'],
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

<?php
declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Module\ModulePathResolver;

class DevDashboardController
{
	public function __construct(
		private readonly string $dataSourcesDir = '/opt/shipard/data-sources',
		private readonly ?string $logFilePath = null,
		private readonly ?ModulePathResolver $modulePathResolver = null,
	) {}

	public function dispatch(Request $request): Response
	{
		$path = $request->getPath();

		if ($path === '/') {
			return Response::redirect('/_dev/');
		}

		if ($path === '/_dev' || $path === '/_dev/') {
			return $this->page($request);
		}

		if ($path === '/_dev/api/data-sources' && $request->getMethod() === 'GET') {
			return $this->listDataSources();
		}

		if ($path === '/_dev/api/install-modules' && $request->getMethod() === 'GET') {
			return $this->getInstallModules();
		}

		if ($path === '/_dev/logs' || $path === '/_dev/logs/') {
			return $this->logsPage();
		}

		if ($path === '/_dev/api/logs' && $request->getMethod() === 'GET') {
			return $this->getLogs($request);
		}

		if ($path === '/_dev/ds-create' || $path === '/_dev/ds-create/') {
			return $this->dsCreatePage();
		}

		if ($path === '/_dev/api/ds-create' && $request->getMethod() === 'POST') {
			return $this->dsCreate($request);
		}

		if ($path === '/_dev/doctor' || $path === '/_dev/doctor/') {
			return $this->doctorPage();
		}

		if ($path === '/_dev/api/doctor' && $request->getMethod() === 'POST') {
			return $this->runDoctor();
		}

		if ($path === '/_dev/upgrade-all' || $path === '/_dev/upgrade-all/') {
			return $this->upgradeAllPage();
		}

		if ($path === '/_dev/api/upgrade-all' && $request->getMethod() === 'POST') {
			return $this->runUpgradeAll();
		}

		if ($path === '/_dev/ds-upgrade' || $path === '/_dev/ds-upgrade/') {
			return $this->dsUpgradePage($request);
		}

		if ($path === '/_dev/api/ds-upgrade' && $request->getMethod() === 'POST') {
			return $this->runDsUpgrade($request);
		}

		return Response::error('NOT_FOUND', 'Not found', 404);
	}

	private function listDataSources(): Response
	{
		$items = [];
		$dirs = glob($this->dataSourcesDir . '/*', GLOB_ONLYDIR) ?: [];
		foreach ($dirs as $dsDir) {
			$configFile = $dsDir . '/config/main.json';
			if (!is_file($configFile)) {
				continue;
			}

			$content = @file_get_contents($configFile);
			if ($content === false) {
				continue;
			}
			$config = json_decode($content, true);
			if (!is_array($config) || !isset($config['id'], $config['name'])) {
				continue;
			}

			$items[] = [
				'id'            => $config['id'],
				'name'          => $config['name'],
				'created'       => $config['created'] ?? null,
				'database_name' => $config['database_name'] ?? null,
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

	private function logsPage(): Response
	{
		$hostname = htmlspecialchars(gethostname() ?: 'unknown', ENT_QUOTES, 'UTF-8');
		return Response::html($this->renderLogsHtml($hostname));
	}

	private function page(Request $request): Response
	{
		$hostname = htmlspecialchars(gethostname() ?: 'unknown', ENT_QUOTES, 'UTF-8');

		$refresh = 60;
		$qp = $request->getQueryParams();
		if (isset($qp['refresh']) && is_numeric($qp['refresh'])) {
			$candidate = (int) $qp['refresh'];
			if ($candidate >= 5 && $candidate <= 3600) {
				$refresh = $candidate;
			}
		}

		return Response::html($this->renderHtml($hostname, $refresh));
	}

	private function dsCreatePage(): Response
	{
		$hostname = htmlspecialchars(gethostname() ?: 'unknown', ENT_QUOTES, 'UTF-8');
		return Response::html($this->renderDsCreateHtml($hostname));
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

		$errors = $this->validateDsCreateInput($name, $login, $password, $module, $language, $country);
		if ($errors) {
			return Response::error('VALIDATION', implode(' ', $errors), 400);
		}

		return Response::stream(function () use ($name, $login, $password, $seed, $module, $language, $country) {
			$this->runDsCreatePipeline($name, $login, $password, $seed, $module, $language, $country);
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
	): void {
		$shpdServer = $this->getShpdServerPath();
		$shpdDs     = $this->getShpdDsPath();

		// ── 1. ds-create ────────────────────────────────────────────────
		$this->emitStep('Creating data source...');
		$cmd = sprintf(
			'%s ds-create --name=%s --module=%s --language=%s --country=%s --no-ansi 2>&1',
			escapeshellarg($shpdServer),
			escapeshellarg($name),
			escapeshellarg($module),
			escapeshellarg($language),
			escapeshellarg($country),
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

	private function doctorPage(): Response
	{
		return $this->renderActionPage([
			'title'         => 'Server Doctor',
			'description'   => 'Runs <code>shpd-server doctor</code> to check server configuration, '
			                 . 'filesystem permissions, FPM socket, nginx routing, and DB connections. '
			                 . 'Read-only — no changes are made.',
			'endpoint'      => '/_dev/api/doctor',
			'runButtonText' => 'Run Doctor',
		]);
	}

	private function upgradeAllPage(): Response
	{
		return $this->renderActionPage([
			'title'         => 'Upgrade All Data Sources',
			'description'   => 'Runs <code>shpd-server ds-upgrade-all</code> to upgrade schema and '
			                 . 'configuration on every data source. Idempotent — DS that are already '
			                 . 'up to date pass through without changes.',
			'endpoint'      => '/_dev/api/upgrade-all',
			'runButtonText' => 'Run Upgrade All',
		]);
	}

	private function dsUpgradePage(Request $request): Response
	{
		$dsId = $request->getQueryParams()['ds'] ?? '';

		if (!is_string($dsId) || !preg_match(self::DS_ID_REGEX, $dsId)) {
			return Response::error('INVALID_DS_ID', 'Invalid or missing ?ds=<id> parameter', 400);
		}

		$dsDir = $this->dataSourcesDir . '/' . $dsId;
		if (!is_file($dsDir . '/config/main.json')) {
			return Response::error('DS_NOT_FOUND', 'Data source not found: ' . $dsId, 404);
		}

		return $this->renderActionPage([
			'title'         => 'Upgrade Data Source — ' . $dsId,
			'description'   => 'Runs <code>shpd-ds ds-upgrade</code> in <code>' . htmlspecialchars($dsDir, ENT_QUOTES, 'UTF-8') . '</code>. '
			                 . 'Idempotent — no-op if already up to date.',
			'endpoint'      => '/_dev/api/ds-upgrade',
			'runButtonText' => 'Run Upgrade',
			'queryParams'   => '?ds=' . urlencode($dsId),
		]);
	}

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

	private function renderHtml(string $hostname, int $refreshSeconds): string
	{
		return <<<HTML
		<!DOCTYPE html>
		<html lang="en">
		<head>
		<meta charset="utf-8">
		<title>Shipard Dev Dashboard</title>
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<style>
		* { box-sizing: border-box; }
		body {
			margin: 0;
			font-family: system-ui, -apple-system, sans-serif;
			background: #f3f4f6;
			color: #111827;
		}
		.banner {
			background: #d97706;
			color: white;
			padding: 8px 16px;
			text-align: center;
			font-weight: 600;
			font-size: 14px;
		}
		header.app {
			background: #1f2937;
			color: white;
			padding: 16px 24px;
			display: flex;
			justify-content: space-between;
			align-items: center;
		}
		header.app h1 { margin: 0; font-size: 18px; font-weight: 600; }
		header.app .host { opacity: 0.8; font-size: 14px; }
		header.app .host code { font-family: monospace; }
		main {
			max-width: 1200px;
			margin: 24px auto;
			padding: 0 16px;
		}
		.toolbar {
			display: flex;
			align-items: center;
			gap: 16px;
			margin-bottom: 12px;
		}
		.toolbar button {
			padding: 6px 14px;
			font-size: 14px;
			cursor: pointer;
		}
		.toolbar .countdown {
			color: #6b7280;
			font-size: 13px;
		}
		table {
			width: 100%;
			border-collapse: collapse;
			background: white;
			box-shadow: 0 1px 2px rgba(0,0,0,0.05);
		}
		th, td {
			padding: 10px 12px;
			text-align: left;
			border-bottom: 1px solid #e5e7eb;
			font-size: 14px;
		}
		th {
			background: #f9fafb;
			font-weight: 600;
			color: #374151;
		}
		tbody tr:nth-child(even) { background: #fafbfc; }
		tbody tr:hover { background: #f1f5f9; }
		td.mono { font-family: monospace; font-size: 13px; }
		.copy-btn {
			background: none;
			border: none;
			cursor: pointer;
			margin-left: 4px;
			opacity: 0.6;
			font-size: 14px;
		}
		.copy-btn:hover { opacity: 1; }
		.open-btn, .logs-btn {
			display: inline-block;
			padding: 4px 12px;
			color: white;
			text-decoration: none;
			border-radius: 4px;
			font-size: 13px;
		}
		.open-btn { background: #2563eb; }
		.open-btn:hover { background: #1d4ed8; }
		.logs-btn { background: #6b7280; margin-left: 4px; }
		.logs-btn:hover { background: #4b5563; }
		header.app .host a {
			color: #93c5fd;
			text-decoration: none;
			margin-left: 12px;
		}
		header.app .host a:hover { text-decoration: underline; }
		.empty, .error {
			text-align: center;
			color: #6b7280;
			padding: 24px !important;
		}
		.error { color: #b91c1c; }
		</style>
		</head>
		<body>
		<div class="banner">⚠️  DEVELOPMENT MODE — do not deploy publicly</div>
		<header class="app">
			<h1>Shipard Dev Dashboard</h1>
			<div class="host">Server: <code>{$hostname}</code><a href="/_dev/ds-create/">+ New DS</a><a href="/_dev/upgrade-all/">Upgrade All</a><a href="/_dev/logs/">Logs</a><a href="/_dev/doctor/">Doctor</a></div>
		</header>
		<main>
			<div class="toolbar">
				<button id="refreshBtn" type="button">Refresh</button>
				<span class="countdown">Auto-refresh in <span id="countdown">{$refreshSeconds}</span>s</span>
			</div>
			<table>
				<thead>
					<tr>
						<th>ID</th>
						<th>Name</th>
						<th>Created</th>
						<th>Database</th>
						<th></th>
					</tr>
				</thead>
				<tbody id="dsBody">
					<tr><td colspan="5" class="empty">Loading…</td></tr>
				</tbody>
			</table>
		</main>
		<script>
		(function () {
			var REFRESH_SECONDS = {$refreshSeconds};
			var countdownEl = document.getElementById('countdown');
			var bodyEl = document.getElementById('dsBody');
			var refreshBtn = document.getElementById('refreshBtn');
			var remaining = REFRESH_SECONDS;

			function setStatusRow(text, cls) {
				var tr = document.createElement('tr');
				var td = document.createElement('td');
				td.colSpan = 5;
				td.className = cls || 'empty';
				td.textContent = text;
				tr.appendChild(td);
				bodyEl.replaceChildren(tr);
			}

			function setEmptyRow() {
				var tr = document.createElement('tr');
				var td = document.createElement('td');
				td.colSpan = 5;
				td.className = 'empty';
				td.appendChild(document.createTextNode('No data sources found. Run '));
				var code = document.createElement('code');
				code.textContent = 'sudo shpd-server ds-create --name <n> --language cs --country cz';
				td.appendChild(code);
				tr.appendChild(td);
				bodyEl.replaceChildren(tr);
			}

			function makeCopyBtn(text) {
				var btn = document.createElement('button');
				btn.type = 'button';
				btn.className = 'copy-btn';
				btn.title = 'Copy ID';
				btn.textContent = '📋';
				btn.addEventListener('click', function () {
					if (!navigator.clipboard) return;
					navigator.clipboard.writeText(text).then(function () {
						var prev = btn.textContent;
						btn.textContent = '✓';
						setTimeout(function () { btn.textContent = prev; }, 1000);
					});
				});
				return btn;
			}

			function renderRow(item) {
				var tr = document.createElement('tr');

				var tdId = document.createElement('td');
				tdId.className = 'mono';
				tdId.appendChild(document.createTextNode(item.id));
				tdId.appendChild(makeCopyBtn(item.id));
				tr.appendChild(tdId);

				var tdName = document.createElement('td');
				tdName.textContent = item.name || '';
				tr.appendChild(tdName);

				var tdCreated = document.createElement('td');
				tdCreated.textContent = item.created || '—';
				tr.appendChild(tdCreated);

				var tdDb = document.createElement('td');
				tdDb.className = 'mono';
				tdDb.textContent = item.database_name || '—';
				tr.appendChild(tdDb);

				var tdAction = document.createElement('td');
				var a = document.createElement('a');
				a.className = 'open-btn';
				a.href = '/' + item.id + '/app/';
				a.target = '_blank';
				a.rel = 'noopener';
				a.textContent = 'Open';
				tdAction.appendChild(a);

				var logs = document.createElement('a');
				logs.className = 'logs-btn';
				logs.href = '/_dev/logs/?ds=' + encodeURIComponent(item.id);
				logs.target = '_blank';
				logs.rel = 'noopener';
				logs.textContent = 'Logs';
				tdAction.appendChild(logs);

				var upg = document.createElement('a');
				upg.className = 'logs-btn';
				upg.href = '/_dev/ds-upgrade/?ds=' + encodeURIComponent(item.id);
				upg.textContent = 'Upgrade';
				tdAction.appendChild(upg);

				tr.appendChild(tdAction);

				return tr;
			}

			function loadData() {
				fetch('/_dev/api/data-sources', { headers: { 'Accept': 'application/json' } })
					.then(function (r) {
						if (!r.ok) throw new Error('HTTP ' + r.status);
						return r.json();
					})
					.then(function (json) {
						var items = (json && json.data) || [];
						if (!items.length) {
							setEmptyRow();
							return;
						}
						var frag = document.createDocumentFragment();
						items.forEach(function (it) { frag.appendChild(renderRow(it)); });
						bodyEl.replaceChildren(frag);
					})
					.catch(function (e) {
						console.error(e);
						setStatusRow('Failed to load data sources', 'error');
					});
			}

			function tick() {
				remaining -= 1;
				if (remaining <= 0) {
					remaining = REFRESH_SECONDS;
					loadData();
				}
				countdownEl.textContent = remaining;
			}

			refreshBtn.addEventListener('click', function () {
				remaining = REFRESH_SECONDS;
				countdownEl.textContent = remaining;
				loadData();
			});

			// ?refresh=1 from action pages → clean URL, fresh fetch already happens via loadData() below
			if (new URLSearchParams(location.search).has('refresh')) {
				history.replaceState({}, '', '/_dev/');
			}

			loadData();
			setInterval(tick, 1000);
		})();
		</script>
		</body>
		</html>
		HTML;
	}

	private function renderLogsHtml(string $hostname): string
	{
		return <<<HTML
		<!DOCTYPE html>
		<html lang="en">
		<head>
		<meta charset="utf-8">
		<title>Shipard Logs</title>
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<style>
		* { box-sizing: border-box; }
		body {
			margin: 0;
			font-family: system-ui, -apple-system, sans-serif;
			background: #f3f4f6;
			color: #111827;
		}
		.banner {
			background: #d97706;
			color: white;
			padding: 8px 16px;
			text-align: center;
			font-weight: 600;
			font-size: 14px;
		}
		header.app {
			background: #1f2937;
			color: white;
			padding: 16px 24px;
			display: flex;
			justify-content: space-between;
			align-items: center;
		}
		header.app h1 { margin: 0; font-size: 18px; font-weight: 600; }
		header.app .meta { opacity: 0.9; font-size: 14px; }
		header.app .meta code { font-family: monospace; }
		header.app .meta a { color: #93c5fd; text-decoration: none; margin-left: 12px; }
		header.app .meta a:hover { text-decoration: underline; }
		main { max-width: 1400px; margin: 16px auto; padding: 0 16px; }
		.toolbar {
			background: white;
			border-radius: 6px;
			padding: 12px 14px;
			box-shadow: 0 1px 2px rgba(0,0,0,0.05);
			margin-bottom: 12px;
			display: flex;
			flex-wrap: wrap;
			gap: 10px 16px;
			align-items: center;
		}
		.toolbar-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
		.toolbar-label { color: #6b7280; font-size: 13px; }
		.level-chip {
			display: inline-block;
			padding: 3px 10px;
			border-radius: 999px;
			font-size: 12px;
			font-weight: 600;
			text-transform: uppercase;
			cursor: pointer;
			border: 1px solid transparent;
			user-select: none;
		}
		.level-chip.lc-debug { background: #e5e7eb; color: #374151; }
		.level-chip.lc-info  { background: #dbeafe; color: #1e40af; }
		.level-chip.lc-warn  { background: #fef3c7; color: #92400e; }
		.level-chip.lc-error { background: #fee2e2; color: #991b1b; }
		.level-chip.off { opacity: 0.35; }
		.level-chip:hover { border-color: #9ca3af; }
		select, input[type=text] {
			padding: 4px 8px;
			border: 1px solid #d1d5db;
			border-radius: 4px;
			font-size: 13px;
			background: white;
		}
		input[type=text].search { width: 280px; }
		button.tb {
			padding: 5px 12px;
			border: 1px solid #d1d5db;
			background: white;
			border-radius: 4px;
			cursor: pointer;
			font-size: 13px;
		}
		button.tb:hover { background: #f3f4f6; }
		button.tb.primary { background: #2563eb; color: white; border-color: #2563eb; }
		button.tb.primary:hover { background: #1d4ed8; }
		.pause-btn { display: inline-flex; align-items: center; gap: 6px; }
		.pause-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; }
		.pause-dot.run { background: #16a34a; }
		.pause-dot.paused { background: #dc2626; }
		.countdown { color: #6b7280; font-size: 13px; }
		.status-line {
			padding: 6px 4px;
			color: #6b7280;
			font-size: 12px;
			font-family: monospace;
		}
		.entries {
			background: white;
			border-radius: 6px;
			box-shadow: 0 1px 2px rgba(0,0,0,0.05);
			overflow: hidden;
		}
		.entry {
			border-bottom: 1px solid #f1f5f9;
			padding: 6px 12px;
			cursor: pointer;
			display: grid;
			grid-template-columns: 80px 70px 70px 220px 1fr;
			gap: 8px;
			align-items: center;
			font-size: 13px;
		}
		.entry:hover { background: #f9fafb; }
		.entry .ts { font-family: monospace; color: #475569; font-size: 12px; }
		.entry .lvl { text-align: left; }
		.entry .ds-chip {
			font-family: monospace;
			font-size: 11px;
			padding: 1px 6px;
			background: #eef2ff;
			color: #3730a3;
			border-radius: 4px;
			cursor: pointer;
			display: inline-block;
		}
		.entry .ds-chip:hover { text-decoration: underline; }
		.entry .ds-chip.empty { background: #f3f4f6; color: #6b7280; }
		.entry .request { font-family: monospace; font-size: 12px; color: #334155; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
		.entry .msg { color: #111827; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
		.entry-detail {
			padding: 12px 16px 14px 16px;
			background: #f9fafb;
			border-left: 3px solid #d1d5db;
			border-bottom: 1px solid #f1f5f9;
			font-size: 13px;
		}
		.entry-detail.bd-debug { border-left-color: #9ca3af; }
		.entry-detail.bd-info  { border-left-color: #3b82f6; }
		.entry-detail.bd-warn  { border-left-color: #d97706; }
		.entry-detail.bd-error { border-left-color: #dc2626; }
		.entry-detail .det-head {
			display: flex;
			justify-content: space-between;
			align-items: baseline;
			margin-bottom: 8px;
		}
		.entry-detail .det-head h3 { margin: 0; font-size: 13px; color: #6b7280; font-weight: 600; }
		.entry-detail .close-btn {
			border: none;
			background: none;
			color: #6b7280;
			cursor: pointer;
			font-size: 18px;
			line-height: 1;
		}
		.entry-detail .field { margin: 4px 0; }
		.entry-detail .field .k { color: #6b7280; font-size: 12px; margin-right: 6px; }
		.entry-detail .field .v { font-family: monospace; font-size: 12px; }
		.entry-detail .field .v.msg-full { font-family: inherit; font-size: 14px; color: #111827; white-space: pre-wrap; }
		.entry-detail pre {
			background: #1f2937;
			color: #e5e7eb;
			padding: 10px 12px;
			border-radius: 4px;
			font-family: monospace;
			font-size: 12px;
			line-height: 1.45;
			overflow-x: auto;
			margin: 6px 0 0 0;
			white-space: pre;
		}
		.exc {
			background: white;
			border: 1px solid #e5e7eb;
			border-radius: 4px;
			padding: 10px 12px;
			margin-top: 6px;
		}
		.exc .exc-title { font-family: monospace; font-size: 13px; color: #991b1b; word-break: break-all; }
		.exc .exc-loc { font-family: monospace; font-size: 12px; color: #6b7280; margin-top: 2px; }
		.exc .toggle-frames {
			border: none;
			background: none;
			color: #2563eb;
			cursor: pointer;
			padding: 4px 0;
			font-size: 12px;
			margin-top: 4px;
		}
		.exc .caused-by { margin-top: 10px; padding-left: 12px; border-left: 2px solid #e5e7eb; color: #6b7280; font-size: 12px; }
		.empty-state, .error-state {
			padding: 24px;
			text-align: center;
			color: #6b7280;
		}
		.error-state { color: #b91c1c; }
		.empty-state code { font-family: monospace; }
		.load-older {
			margin: 12px 0 24px 0;
			text-align: center;
		}
		.load-older button {
			padding: 8px 18px;
			border: 1px solid #d1d5db;
			background: white;
			border-radius: 4px;
			cursor: pointer;
			font-size: 13px;
		}
		.load-older button:hover:not(:disabled) { background: #f3f4f6; }
		.load-older button:disabled { color: #9ca3af; cursor: default; }
		.lvl-badge {
			display: inline-block;
			padding: 1px 8px;
			border-radius: 4px;
			font-size: 11px;
			font-weight: 600;
			text-transform: uppercase;
		}
		.lvl-badge.lc-debug { background: #e5e7eb; color: #374151; }
		.lvl-badge.lc-info  { background: #dbeafe; color: #1e40af; }
		.lvl-badge.lc-warn  { background: #fef3c7; color: #92400e; }
		.lvl-badge.lc-error { background: #fee2e2; color: #991b1b; }
		.lvl-badge.clickable { cursor: pointer; }
		.lvl-badge.clickable:hover { outline: 1px solid currentColor; }
		</style>
		</head>
		<body>
		<div class="banner">⚠️  DEVELOPMENT MODE — do not deploy publicly</div>
		<header class="app">
			<h1>Shipard Logs</h1>
			<div class="meta">
				Server: <code>{$hostname}</code>
				<a href="/_dev/">&larr; Dashboard</a>
			</div>
		</header>
		<main>
			<div class="toolbar">
				<div class="toolbar-row">
					<span class="toolbar-label">Levels:</span>
					<span class="level-chip lc-debug" data-level="debug">DEBUG</span>
					<span class="level-chip lc-info"  data-level="info">INFO</span>
					<span class="level-chip lc-warn"  data-level="warn">WARN</span>
					<span class="level-chip lc-error" data-level="error">ERROR</span>
				</div>
				<div class="toolbar-row">
					<span class="toolbar-label">DS:</span>
					<select id="dsSelect"><option value="">All</option></select>
				</div>
				<div class="toolbar-row" style="flex: 1; min-width: 240px;">
					<span class="toolbar-label">Search:</span>
					<input type="text" class="search" id="searchInput" placeholder="msg or exception">
				</div>
				<div class="toolbar-row">
					<button type="button" class="tb primary" id="refreshBtn">Refresh</button>
					<button type="button" class="tb pause-btn" id="pauseBtn">
						<span class="pause-dot run" id="pauseDot"></span>
						<span id="pauseLabel">Pause</span>
					</button>
					<span class="countdown">Auto-refresh in <span id="countdown">5</span>s</span>
				</div>
			</div>
			<div class="status-line" id="statusLine">Loading…</div>
			<div class="entries" id="entriesEl">
				<div class="empty-state">Loading…</div>
			</div>
			<div class="load-older">
				<button type="button" id="loadOlderBtn">Load older (currently 200)</button>
			</div>
		</main>
		<script>
		(function () {
			var REFRESH_INTERVAL = 5;
			var DOCS_URL = 'https://github.com/shipard/shipard/blob/stable/docs/logging.md';
			var DS_NULL = '__null__';

			var state = {
				limit: 200,
				levels: { debug: false, info: false, warn: true, error: true },
				ds: '',
				search: '',
				paused: false,
				expanded: new Set(),
				countdown: REFRESH_INTERVAL,
				entries: [],
				logFile: '',
				available: false,
				lastError: null,
			};

			var entriesEl   = document.getElementById('entriesEl');
			var statusEl    = document.getElementById('statusLine');
			var dsSelect    = document.getElementById('dsSelect');
			var searchInput = document.getElementById('searchInput');
			var refreshBtn  = document.getElementById('refreshBtn');
			var pauseBtn    = document.getElementById('pauseBtn');
			var pauseDot    = document.getElementById('pauseDot');
			var pauseLabel  = document.getElementById('pauseLabel');
			var countdownEl = document.getElementById('countdown');
			var loadOlderBtn = document.getElementById('loadOlderBtn');

			// Pre-fill DS filter from URL
			var urlDs = new URLSearchParams(location.search).get('ds');
			if (urlDs) state.ds = urlDs;

			function formatTimeCompact(iso) {
				var m = String(iso).match(/T(\d{2}:\d{2}:\d{2})/);
				return m ? m[1] : String(iso);
			}
			function formatTimeFull(iso) {
				return String(iso).replace('T', ' ');
			}

			function applyFilters(entries) {
				var q = state.search.trim().toLowerCase();
				return entries.filter(function (e) {
					if (!state.levels[e.level]) return false;
					if (state.ds === DS_NULL) {
						if (e.ds !== null && e.ds !== undefined) return false;
					} else if (state.ds) {
						if (e.ds !== state.ds) return false;
					}
					if (q) {
						var hay = ((e.msg || '') + ' ' + ((e.exception && e.exception.message) || '')).toLowerCase();
						if (hay.indexOf(q) === -1) return false;
					}
					return true;
				});
			}

			function rebuildDsSelect() {
				var seen = new Map();
				var hasNull = false;
				state.entries.forEach(function (e) {
					if (e.ds === null || e.ds === undefined) hasNull = true;
					else seen.set(e.ds, true);
				});
				var ids = Array.from(seen.keys()).sort();
				var prev = state.ds;

				while (dsSelect.firstChild) dsSelect.removeChild(dsSelect.firstChild);

				var optAll = document.createElement('option');
				optAll.value = '';
				optAll.textContent = 'All';
				dsSelect.appendChild(optAll);

				if (hasNull) {
					var optNull = document.createElement('option');
					optNull.value = DS_NULL;
					optNull.textContent = '(none)';
					dsSelect.appendChild(optNull);
				}

				ids.forEach(function (id) {
					var o = document.createElement('option');
					o.value = id;
					o.textContent = id;
					dsSelect.appendChild(o);
				});

				// If current selection is not present in new options, keep it as
				// disabled placeholder so the filter still applies.
				if (prev && prev !== DS_NULL && !seen.has(prev)) {
					var ph = document.createElement('option');
					ph.value = prev;
					ph.textContent = prev + ' (no entries)';
					dsSelect.appendChild(ph);
				}

				dsSelect.value = prev;
			}

			function renderLevelChips() {
				document.querySelectorAll('.level-chip').forEach(function (chip) {
					var lvl = chip.getAttribute('data-level');
					if (state.levels[lvl]) chip.classList.remove('off');
					else chip.classList.add('off');
				});
			}

			function updatePauseButton() {
				if (state.paused) {
					pauseDot.classList.remove('run');
					pauseDot.classList.add('paused');
					pauseLabel.textContent = 'Resume';
				} else {
					pauseDot.classList.add('run');
					pauseDot.classList.remove('paused');
					pauseLabel.textContent = 'Pause';
				}
			}

			function updateLoadOlder() {
				if (state.limit >= 2000) {
					loadOlderBtn.textContent = 'Showing maximum 2000 entries — use grep for older';
					loadOlderBtn.disabled = true;
				} else {
					loadOlderBtn.textContent = 'Load older (currently ' + state.limit + ')';
					loadOlderBtn.disabled = false;
				}
			}

			function updateStatusLine(filteredCount) {
				if (state.lastError) {
					statusEl.textContent = state.lastError;
					return;
				}
				var parts = [];
				parts.push('log: ' + (state.logFile || '(unknown)'));
				if (!state.available) parts.push('not available');
				else parts.push('shown: ' + filteredCount + ' / ' + state.entries.length);
				statusEl.textContent = parts.join('  •  ');
			}

			function setExpanded(idx, expand) {
				if (expand) state.expanded.add(idx);
				else state.expanded.delete(idx);
				state.paused = state.expanded.size > 0;
				updatePauseButton();
				renderEntries();
			}

			function makeDsChip(ds) {
				var span = document.createElement('span');
				span.className = 'ds-chip';
				if (ds === null || ds === undefined) {
					span.classList.add('empty');
					span.textContent = '(none)';
					span.title = 'No data source';
					span.addEventListener('click', function (ev) {
						ev.stopPropagation();
						state.ds = DS_NULL;
						dsSelect.value = DS_NULL;
						renderEntries();
					});
				} else {
					var compact = ds.length > 5 ? ds.substring(0, 4) + '…' : ds;
					span.textContent = compact;
					span.title = ds;
					span.addEventListener('click', function (ev) {
						ev.stopPropagation();
						state.ds = ds;
						dsSelect.value = ds;
						renderEntries();
					});
				}
				return span;
			}

			function makeLevelBadge(level, clickable) {
				var span = document.createElement('span');
				span.className = 'lvl-badge lc-' + level;
				if (clickable) span.classList.add('clickable');
				span.textContent = level;
				if (clickable) {
					span.addEventListener('click', function (ev) {
						ev.stopPropagation();
						// When user clicks a level badge in a row, filter to that
						// level only (turn off others). Familiar pattern.
						Object.keys(state.levels).forEach(function (k) {
							state.levels[k] = (k === level);
						});
						renderLevelChips();
						renderEntries();
					});
				}
				return span;
			}

			function renderCompactEntry(e, displayIndex) {
				var row = document.createElement('div');
				row.className = 'entry';
				row.addEventListener('click', function () {
					setExpanded(displayIndex, !state.expanded.has(displayIndex));
				});

				var ts = document.createElement('span');
				ts.className = 'ts';
				ts.textContent = formatTimeCompact(e.ts);
				row.appendChild(ts);

				var lvl = document.createElement('span');
				lvl.className = 'lvl';
				lvl.appendChild(makeLevelBadge(e.level, true));
				row.appendChild(lvl);

				var dsCell = document.createElement('span');
				dsCell.appendChild(makeDsChip(e.ds === undefined ? null : e.ds));
				row.appendChild(dsCell);

				var req = document.createElement('span');
				req.className = 'request';
				req.textContent = e.request || '';
				req.title = e.request || '';
				row.appendChild(req);

				var msg = document.createElement('span');
				msg.className = 'msg';
				var msgText = e.msg || '';
				if (e.exception && e.exception.message) {
					msgText = msgText + (msgText ? ' — ' : '') + e.exception.class + ': ' + e.exception.message;
				}
				msg.textContent = msgText;
				msg.title = msgText;
				row.appendChild(msg);

				return row;
			}

			function renderException(exc, depth) {
				var box = document.createElement('div');
				box.className = 'exc';

				if (depth > 0) {
					var cb = document.createElement('div');
					cb.className = 'caused-by';
					cb.textContent = 'Caused by:';
					box.appendChild(cb);
				}

				var title = document.createElement('div');
				title.className = 'exc-title';
				title.textContent = (exc['class'] || 'Exception') + ': ' + (exc.message || '');
				box.appendChild(title);

				if (exc.at) {
					var loc = document.createElement('div');
					loc.className = 'exc-loc';
					loc.textContent = 'at ' + exc.at;
					box.appendChild(loc);
				}

				if (Array.isArray(exc.trace) && exc.trace.length > 0) {
					var btn = document.createElement('button');
					btn.type = 'button';
					btn.className = 'toggle-frames';
					btn.textContent = 'Show ' + exc.trace.length + ' frames ▾';
					box.appendChild(btn);

					var pre = document.createElement('pre');
					pre.style.display = 'none';
					pre.textContent = exc.trace.map(function (f, i) {
						return '#' + i + ' ' + f;
					}).join('\\n');
					box.appendChild(pre);

					btn.addEventListener('click', function (ev) {
						ev.stopPropagation();
						if (pre.style.display === 'none') {
							pre.style.display = 'block';
							btn.textContent = 'Hide trace ▴';
						} else {
							pre.style.display = 'none';
							btn.textContent = 'Show ' + exc.trace.length + ' frames ▾';
						}
					});
				}

				if (exc.previous) {
					box.appendChild(renderException(exc.previous, depth + 1));
				}

				return box;
			}

			function renderExpandedDetail(e, displayIndex) {
				var det = document.createElement('div');
				det.className = 'entry-detail bd-' + e.level;
				det.addEventListener('click', function (ev) { ev.stopPropagation(); });

				var head = document.createElement('div');
				head.className = 'det-head';
				var h3 = document.createElement('h3');
				h3.textContent = formatTimeFull(e.ts) + '  •  ' + e.level.toUpperCase();
				head.appendChild(h3);
				var close = document.createElement('button');
				close.type = 'button';
				close.className = 'close-btn';
				close.textContent = '×';
				close.title = 'Collapse';
				close.addEventListener('click', function () { setExpanded(displayIndex, false); });
				head.appendChild(close);
				det.appendChild(head);

				if (e.msg) {
					var f = document.createElement('div');
					f.className = 'field';
					var v = document.createElement('div');
					v.className = 'v msg-full';
					v.textContent = e.msg;
					f.appendChild(v);
					det.appendChild(f);
				}

				function appendField(label, value) {
					var f = document.createElement('div');
					f.className = 'field';
					var k = document.createElement('span');
					k.className = 'k';
					k.textContent = label + ':';
					var v = document.createElement('span');
					v.className = 'v';
					v.textContent = value;
					f.appendChild(k);
					f.appendChild(v);
					det.appendChild(f);
				}

				appendField('Request', e.request || '(none)');
				appendField('DS', (e.ds === null || e.ds === undefined) ? '(none)' : e.ds);

				if (e.ctx && typeof e.ctx === 'object' && Object.keys(e.ctx).length > 0) {
					var k = document.createElement('div');
					k.className = 'field';
					var lbl = document.createElement('span');
					lbl.className = 'k';
					lbl.textContent = 'Context:';
					k.appendChild(lbl);
					det.appendChild(k);
					var pre = document.createElement('pre');
					pre.textContent = JSON.stringify(e.ctx, null, 2);
					det.appendChild(pre);
				}

				if (e.exception) {
					var lbl2 = document.createElement('div');
					lbl2.className = 'field';
					var k2 = document.createElement('span');
					k2.className = 'k';
					k2.textContent = 'Exception:';
					lbl2.appendChild(k2);
					det.appendChild(lbl2);
					det.appendChild(renderException(e.exception, 0));
				}

				return det;
			}

			function renderEntries() {
				while (entriesEl.firstChild) entriesEl.removeChild(entriesEl.firstChild);

				if (state.lastError) {
					var err = document.createElement('div');
					err.className = 'error-state';
					err.textContent = 'Failed to load logs. Check console.';
					entriesEl.appendChild(err);
					updateStatusLine(0);
					return;
				}

				if (!state.available) {
					var es = document.createElement('div');
					es.className = 'empty-state';
					es.appendChild(document.createTextNode('Log file does not exist yet at '));
					var c = document.createElement('code');
					c.textContent = state.logFile;
					es.appendChild(c);
					es.appendChild(document.createTextNode('. See '));
					var a = document.createElement('a');
					a.href = DOCS_URL;
					a.target = '_blank';
					a.rel = 'noopener';
					a.textContent = 'docs/logging.md';
					es.appendChild(a);
					es.appendChild(document.createTextNode(' for setup.'));
					entriesEl.appendChild(es);
					updateStatusLine(0);
					return;
				}

				if (state.entries.length === 0) {
					var es2 = document.createElement('div');
					es2.className = 'empty-state';
					es2.textContent = 'No log entries yet.';
					entriesEl.appendChild(es2);
					updateStatusLine(0);
					return;
				}

				// Newest first.
				var ordered = state.entries.slice().reverse();
				var filtered = applyFilters(ordered);

				if (filtered.length === 0) {
					var es3 = document.createElement('div');
					es3.className = 'empty-state';
					es3.textContent = 'No entries match current filters.';
					entriesEl.appendChild(es3);
					updateStatusLine(0);
					return;
				}

				var frag = document.createDocumentFragment();
				filtered.forEach(function (e, idx) {
					frag.appendChild(renderCompactEntry(e, idx));
					if (state.expanded.has(idx)) {
						frag.appendChild(renderExpandedDetail(e, idx));
					}
				});
				entriesEl.appendChild(frag);

				updateStatusLine(filtered.length);
			}

			function loadEntries() {
				var url = '/_dev/api/logs?limit=' + state.limit;
				fetch(url, { headers: { 'Accept': 'application/json' } })
					.then(function (r) {
						if (!r.ok) throw new Error('HTTP ' + r.status);
						return r.json();
					})
					.then(function (json) {
						if (!json || !json.success || !json.data) {
							throw new Error('Bad response shape');
						}
						state.entries = json.data.entries || [];
						state.logFile = json.data.logFile || '';
						state.available = !!json.data.available;
						state.lastError = null;
						state.expanded.clear();
						rebuildDsSelect();
						renderEntries();
					})
					.catch(function (err) {
						console.error(err);
						state.lastError = 'Failed to load logs: ' + err.message;
						renderEntries();
					});
			}

			function tick() {
				if (state.paused) return;
				state.countdown -= 1;
				countdownEl.textContent = Math.max(0, state.countdown);
				if (state.countdown <= 0) {
					loadEntries();
					state.countdown = REFRESH_INTERVAL;
					countdownEl.textContent = state.countdown;
				}
			}

			// Wire up controls
			document.querySelectorAll('.level-chip').forEach(function (chip) {
				chip.addEventListener('click', function () {
					var lvl = chip.getAttribute('data-level');
					state.levels[lvl] = !state.levels[lvl];
					renderLevelChips();
					renderEntries();
				});
			});
			renderLevelChips();

			dsSelect.addEventListener('change', function () {
				state.ds = dsSelect.value;
				renderEntries();
			});

			searchInput.addEventListener('input', function () {
				state.search = searchInput.value;
				renderEntries();
			});

			refreshBtn.addEventListener('click', function () {
				state.countdown = REFRESH_INTERVAL;
				countdownEl.textContent = state.countdown;
				loadEntries();
			});

			pauseBtn.addEventListener('click', function () {
				if (state.expanded.size > 0 && state.paused) {
					// User wants to resume but has expansions — collapse them so
					// the next refresh doesn't replace content under their cursor.
					state.expanded.clear();
				}
				state.paused = !state.paused;
				if (!state.paused) {
					state.countdown = REFRESH_INTERVAL;
					countdownEl.textContent = state.countdown;
				}
				updatePauseButton();
				renderEntries();
			});

			loadOlderBtn.addEventListener('click', function () {
				if (state.limit >= 2000) return;
				state.limit = Math.min(2000, state.limit * 2);
				updateLoadOlder();
				loadEntries();
			});

			updatePauseButton();
			updateLoadOlder();
			loadEntries();
			setInterval(tick, 1000);
		})();
		</script>
		</body>
		</html>
		HTML;
	}

	private function renderDsCreateHtml(string $hostname): string
	{
		return <<<HTML
		<!DOCTYPE html>
		<html lang="en">
		<head>
		<meta charset="utf-8">
		<title>Create Data Source — Shipard Dev</title>
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<style>
		* { box-sizing: border-box; }
		body {
			margin: 0;
			font-family: system-ui, -apple-system, sans-serif;
			background: #f3f4f6;
			color: #111827;
		}
		.banner {
			background: #d97706;
			color: white;
			padding: 8px 16px;
			text-align: center;
			font-weight: 600;
			font-size: 14px;
		}
		header.app {
			background: #1f2937;
			color: white;
			padding: 16px 24px;
			display: flex;
			justify-content: space-between;
			align-items: center;
		}
		header.app h1 { margin: 0; font-size: 18px; font-weight: 600; }
		header.app .meta { opacity: 0.9; font-size: 14px; }
		header.app .meta code { font-family: monospace; }
		header.app .meta a { color: #93c5fd; text-decoration: none; margin-left: 12px; }
		header.app .meta a:hover { text-decoration: underline; }
		main {
			max-width: 800px;
			margin: 24px auto;
			padding: 0 16px;
		}
		.card {
			background: white;
			border-radius: 6px;
			box-shadow: 0 1px 2px rgba(0,0,0,0.05);
			padding: 20px 24px;
			margin-bottom: 16px;
		}
		.field { margin-bottom: 14px; }
		.field label {
			display: block;
			font-size: 13px;
			color: #374151;
			font-weight: 600;
			margin-bottom: 4px;
		}
		.field .req { color: #dc2626; }
		.field input[type=text],
		.field input[type=password] {
			width: 100%;
			padding: 8px 10px;
			border: 1px solid #d1d5db;
			border-radius: 4px;
			font-size: 14px;
			font-family: inherit;
		}
		.field input:focus, .field select:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 2px rgba(37,99,235,0.2); }
		.field input:disabled, .field select:disabled { background: #f9fafb; color: #6b7280; }
		.field select {
			width: 100%;
			padding: 8px 10px;
			border: 1px solid #d1d5db;
			border-radius: 4px;
			font-size: 14px;
			font-family: inherit;
			background: white;
		}
		.field .hint { font-size: 12px; color: #6b7280; margin-top: 4px; min-height: 14px; }
		.field .err {
			color: #b91c1c;
			font-size: 12px;
			margin-top: 4px;
			min-height: 14px;
		}
		.field .pw-row { display: flex; gap: 6px; }
		.field .pw-row input { flex: 1; }
		.field .pw-row button {
			padding: 0 12px;
			border: 1px solid #d1d5db;
			background: white;
			border-radius: 4px;
			cursor: pointer;
			font-size: 13px;
		}
		.field .pw-row button:hover { background: #f3f4f6; }
		.field.checkbox { display: flex; align-items: center; gap: 8px; }
		.field.checkbox label { margin: 0; font-weight: normal; }
		.submit-row { margin-top: 18px; }
		.submit-btn {
			background: #2563eb;
			color: white;
			border: none;
			border-radius: 4px;
			padding: 10px 18px;
			font-size: 14px;
			font-weight: 600;
			cursor: pointer;
			width: 100%;
			display: inline-flex;
			align-items: center;
			justify-content: center;
			gap: 8px;
		}
		.submit-btn:hover:not(:disabled) { background: #1d4ed8; }
		.submit-btn:disabled { background: #93c5fd; cursor: default; }
		.spinner {
			display: inline-block;
			width: 12px;
			height: 12px;
			border: 2px solid rgba(255,255,255,0.4);
			border-top-color: white;
			border-radius: 50%;
			animation: spin 0.8s linear infinite;
		}
		@keyframes spin { to { transform: rotate(360deg); } }
		.form-error {
			background: #fee2e2;
			color: #991b1b;
			border-radius: 4px;
			padding: 8px 12px;
			margin-bottom: 12px;
			font-size: 13px;
		}
		.output-section { display: none; }
		.output-section.active { display: block; }
		.output-section h2 {
			margin: 0 0 8px 0;
			font-size: 13px;
			color: #6b7280;
			font-weight: 600;
			text-transform: uppercase;
			letter-spacing: 0.05em;
		}
		.output-pre {
			background: #1f2937;
			color: #f3f4f6;
			padding: 12px 14px;
			border-radius: 4px;
			font-family: monospace;
			font-size: 0.85em;
			max-height: 60vh;
			overflow-y: auto;
			margin: 0;
			white-space: pre-wrap;
			word-break: break-word;
		}
		.output-pre .line-step  { color: #93c5fd; font-weight: 600; display: block; }
		.output-pre .line-error { color: #fca5a5; font-weight: 600; display: block; }
		.output-pre .line-plain { color: #e5e7eb; display: block; }
		.result-banner {
			padding: 14px 16px;
			border-radius: 6px;
			margin-bottom: 12px;
			display: none;
			align-items: center;
			gap: 12px;
			flex-wrap: wrap;
		}
		.result-banner.active { display: flex; }
		.result-banner.success { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }
		.result-banner.error   { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
		.result-banner .msg { flex: 1; font-weight: 600; }
		.result-banner .actions { display: flex; gap: 8px; }
		.result-banner a, .result-banner button {
			padding: 6px 14px;
			border-radius: 4px;
			text-decoration: none;
			font-size: 13px;
			font-weight: 600;
			cursor: pointer;
			border: 1px solid transparent;
			background: white;
			color: #111827;
		}
		.result-banner a:hover, .result-banner button:hover { background: #f3f4f6; }
		.result-banner a.primary {
			background: #059669;
			color: white;
		}
		.result-banner a.primary:hover { background: #047857; }
		.result-banner.error a.primary { background: #dc2626; }
		.result-banner.error a.primary:hover { background: #b91c1c; }
		</style>
		</head>
		<body>
		<div class="banner">⚠️  DEVELOPMENT MODE — do not deploy publicly</div>
		<header class="app">
			<h1>Create new Data Source</h1>
			<div class="meta">
				Server: <code>{$hostname}</code>
				<a href="/_dev/">&larr; Dashboard</a>
			</div>
		</header>
		<main>
			<div class="result-banner" id="resultBanner">
				<span class="msg" id="resultMsg"></span>
				<span class="actions" id="resultActions"></span>
			</div>

			<div class="card">
				<div class="form-error" id="formError" style="display:none;"></div>
				<form id="dsForm" autocomplete="off">
					<div class="field">
						<label for="f-name">Name <span class="req">*</span></label>
						<input type="text" id="f-name" name="name" required maxlength="200">
						<div class="err" id="err-name"></div>
					</div>
					<div class="field">
						<label for="f-language">Default language <span class="req">*</span></label>
						<select id="f-language" name="language" required>
							<option value="cs" selected>Čeština (cs)</option>
							<option value="en">English (en)</option>
						</select>
						<div class="err" id="err-language"></div>
					</div>
					<div class="field">
						<label for="f-country">Country <span class="req">*</span></label>
						<select id="f-country" name="country" required>
							<option value="cz" selected>Česko (cz)</option>
							<option value="sk">Slovensko (sk)</option>
						</select>
						<div class="err" id="err-country"></div>
					</div>
					<div class="field">
						<label for="f-module">Install module <span class="req">*</span></label>
						<select id="f-module" name="module" required>
							<option value="">Loading modules...</option>
						</select>
						<div class="hint" id="module-hint"></div>
						<div class="err" id="err-module"></div>
					</div>
					<div class="field">
						<label for="f-login">Admin login <span class="req">*</span></label>
						<input type="text" id="f-login" name="login" value="admin" required maxlength="64">
						<div class="err" id="err-login"></div>
					</div>
					<div class="field">
						<label for="f-password">Admin password <span class="req">*</span></label>
						<div class="pw-row">
							<input type="password" id="f-password" name="password" value="admin" required>
							<button type="button" id="togglePw">show</button>
						</div>
						<div class="err" id="err-password"></div>
					</div>
					<div class="field checkbox">
						<input type="checkbox" id="f-seed" name="seed">
						<label for="f-seed">Seed test data (persons, mail samples)</label>
					</div>
					<div class="submit-row">
						<button type="submit" class="submit-btn" id="submitBtn">
							<span id="submitLabel">Create Data Source</span>
						</button>
					</div>
				</form>
			</div>

			<div class="output-section" id="outputSection">
				<div class="card">
					<h2>Output</h2>
					<pre class="output-pre" id="outputPre"></pre>
				</div>
			</div>
		</main>
		<script>
		(function () {
			var form         = document.getElementById('dsForm');
			var submitBtn    = document.getElementById('submitBtn');
			var submitLabel  = document.getElementById('submitLabel');
			var togglePw     = document.getElementById('togglePw');
			var pwInput      = document.getElementById('f-password');
			var nameInput    = document.getElementById('f-name');
			var loginInput   = document.getElementById('f-login');
			var seedInput    = document.getElementById('f-seed');
			var languageSel  = document.getElementById('f-language');
			var countrySel   = document.getElementById('f-country');
			var moduleSel    = document.getElementById('f-module');
			var moduleHint   = document.getElementById('module-hint');
			var formError    = document.getElementById('formError');
			var outputSection = document.getElementById('outputSection');
			var outputPre    = document.getElementById('outputPre');
			var resultBanner = document.getElementById('resultBanner');
			var resultMsg    = document.getElementById('resultMsg');
			var resultActions = document.getElementById('resultActions');

			// Pre-fill name from URL ?name=...
			var qpName = new URLSearchParams(location.search).get('name');
			if (qpName) nameInput.value = qpName;

			togglePw.addEventListener('click', function () {
				if (pwInput.type === 'password') {
					pwInput.type = 'text';
					togglePw.textContent = 'hide';
				} else {
					pwInput.type = 'password';
					togglePw.textContent = 'show';
				}
			});

			function clearErrors() {
				formError.style.display = 'none';
				formError.textContent = '';
				document.getElementById('err-name').textContent = '';
				document.getElementById('err-login').textContent = '';
				document.getElementById('err-password').textContent = '';
				document.getElementById('err-language').textContent = '';
				document.getElementById('err-country').textContent = '';
				document.getElementById('err-module').textContent = '';
			}

			function validateClient(name, login, password, module, language, country) {
				var errs = {};
				if (!name) errs.name = 'Name is required.';
				else if (name.length > 200) errs.name = 'Name is too long (max 200 characters).';

				if (!login) errs.login = 'Admin login is required.';
				else if (!/^[a-zA-Z0-9_]+$/.test(login)) errs.login = 'Only letters, digits, and underscores.';
				else if (login.length > 64) errs.login = 'Admin login is too long (max 64 characters).';

				if (!password) errs.password = 'Admin password is required.';
				else if (password.length < 4) errs.password = 'Admin password must be at least 4 characters.';

				if (!language) errs.language = 'Please select a default language.';

				if (!country) errs.country = 'Please select a country.';

				if (!module) errs.module = 'Please select an install module.';

				return errs;
			}

			function showFieldErrors(errs) {
				if (errs.name) document.getElementById('err-name').textContent = errs.name;
				if (errs.login) document.getElementById('err-login').textContent = errs.login;
				if (errs.password) document.getElementById('err-password').textContent = errs.password;
				if (errs.language) document.getElementById('err-language').textContent = errs.language;
				if (errs.country) document.getElementById('err-country').textContent = errs.country;
				if (errs.module) document.getElementById('err-module').textContent = errs.module;
			}

			function setBusy(busy) {
				nameInput.disabled = busy;
				loginInput.disabled = busy;
				pwInput.disabled = busy;
				seedInput.disabled = busy;
				languageSel.disabled = busy;
				countrySel.disabled = busy;
				moduleSel.disabled = busy;
				togglePw.disabled = busy;
				submitBtn.disabled = busy;
				if (busy) {
					submitLabel.textContent = 'Creating...';
					var sp = document.createElement('span');
					sp.className = 'spinner';
					submitBtn.insertBefore(sp, submitLabel);
				} else {
					submitLabel.textContent = 'Create Data Source';
					var existing = submitBtn.querySelector('.spinner');
					if (existing) existing.remove();
				}
			}

			function appendOutput(line, kind) {
				var span = document.createElement('span');
				span.className = 'line-' + (kind || 'plain');
				span.textContent = line + '\\n';
				outputPre.appendChild(span);
				outputPre.scrollTop = outputPre.scrollHeight;
			}

			function showDoneBanner(data) {
				resultBanner.classList.remove('error');
				resultBanner.classList.add('success', 'active');
				resultMsg.textContent = 'Data source created successfully.';
				while (resultActions.firstChild) resultActions.removeChild(resultActions.firstChild);

				var openLink = document.createElement('a');
				openLink.className = 'primary';
				openLink.href = data.url || '/';
				openLink.target = '_blank';
				openLink.rel = 'noopener';
				openLink.textContent = 'Open data source →';
				resultActions.appendChild(openLink);

				var another = document.createElement('button');
				another.type = 'button';
				another.textContent = 'Create another';
				another.addEventListener('click', function () { location.reload(); });
				resultActions.appendChild(another);

				var back = document.createElement('a');
				back.href = '/_dev/?refresh=1';
				back.textContent = 'Back to Dashboard';
				resultActions.appendChild(back);
			}

			function showErrorBanner(message) {
				resultBanner.classList.remove('success');
				resultBanner.classList.add('error', 'active');
				resultMsg.textContent = message || 'Failed.';
				while (resultActions.firstChild) resultActions.removeChild(resultActions.firstChild);

				var retry = document.createElement('a');
				retry.className = 'primary';
				retry.href = location.pathname + location.search;
				retry.textContent = 'Try again';
				resultActions.appendChild(retry);
			}

			function handleLine(line) {
				if (line.startsWith('[STEP] ')) {
					appendOutput(line, 'step');
				} else if (line.startsWith('[ERROR] ')) {
					appendOutput(line, 'error');
					showErrorBanner(line.slice(8));
				} else if (line.startsWith('[DONE] ')) {
					try {
						var data = JSON.parse(line.slice(7));
						showDoneBanner(data);
					} catch (e) {
						showErrorBanner('Could not parse [DONE] payload');
					}
				} else if (line !== '') {
					appendOutput(line, 'plain');
				}
			}

			function updateModuleHint() {
				var opt = moduleSel.options[moduleSel.selectedIndex];
				moduleHint.textContent = (opt && opt.dataset && opt.dataset.description) || '';
			}

			async function loadInstallModules() {
				try {
					var r = await fetch('/_dev/api/install-modules', { headers: { 'Accept': 'application/json' } });
					var result = await r.json();
					var modules = (result && result.data) || [];

					while (moduleSel.firstChild) moduleSel.removeChild(moduleSel.firstChild);

					if (modules.length === 0) {
						var opt0 = document.createElement('option');
						opt0.value = '';
						opt0.textContent = 'No install modules found';
						moduleSel.appendChild(opt0);
						moduleSel.disabled = true;
						moduleHint.textContent = '';
						return;
					}

					modules.forEach(function (m) {
						var opt = document.createElement('option');
						opt.value = m.id;
						opt.textContent = m.name + ' (' + m.id + ')';
						opt.dataset.description = m.description || '';
						moduleSel.appendChild(opt);
					});

					var baseIdx = -1;
					for (var i = 0; i < modules.length; i++) {
						if (modules[i].id === 'install.base') { baseIdx = i; break; }
					}
					moduleSel.selectedIndex = baseIdx >= 0 ? baseIdx : 0;
					updateModuleHint();
				} catch (e) {
					console.error(e);
					while (moduleSel.firstChild) moduleSel.removeChild(moduleSel.firstChild);
					var opt = document.createElement('option');
					opt.value = '';
					opt.textContent = 'Failed to load modules';
					moduleSel.appendChild(opt);
					moduleSel.disabled = true;
				}
			}

			moduleSel.addEventListener('change', updateModuleHint);
			loadInstallModules();

			form.addEventListener('submit', async function (ev) {
				ev.preventDefault();
				clearErrors();

				var name     = nameInput.value.trim();
				var login    = loginInput.value.trim();
				var password = pwInput.value;
				var seed     = seedInput.checked;
				var language = languageSel.value;
				var country  = countrySel.value;
				var module   = moduleSel.value;

				var errs = validateClient(name, login, password, module, language, country);
				if (Object.keys(errs).length > 0) {
					showFieldErrors(errs);
					return;
				}

				while (outputPre.firstChild) outputPre.removeChild(outputPre.firstChild);
				outputSection.classList.add('active');
				resultBanner.classList.remove('active', 'success', 'error');
				setBusy(true);

				try {
					var response = await fetch('/_dev/api/ds-create', {
						method: 'POST',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify({ name: name, login: login, password: password, seed: seed, module: module, language: language, country: country }),
					});

					if (!response.ok) {
						var err = await response.json().catch(function () { return {}; });
						var msg = (err && err.error && err.error.message) || ('HTTP ' + response.status);
						formError.textContent = msg;
						formError.style.display = 'block';
						setBusy(false);
						return;
					}

					var reader = response.body.getReader();
					var decoder = new TextDecoder();
					var buffer = '';

					while (true) {
						var chunk = await reader.read();
						if (chunk.done) break;
						buffer += decoder.decode(chunk.value, { stream: true });

						var nl;
						while ((nl = buffer.indexOf('\\n')) >= 0) {
							var line = buffer.slice(0, nl);
							buffer = buffer.slice(nl + 1);
							handleLine(line);
						}
					}
					if (buffer) handleLine(buffer);
				} catch (e) {
					console.error(e);
					showErrorBanner('Network error: ' + e.message);
				} finally {
					setBusy(false);
				}
			});
		})();
		</script>
		</body>
		</html>
		HTML;
	}

	/**
	 * @param array{
	 *   title: string,
	 *   description: string,
	 *   endpoint: string,
	 *   runButtonText: string,
	 *   queryParams?: string,
	 * } $config
	 */
	private function renderActionPage(array $config): Response
	{
		$hostname    = htmlspecialchars(gethostname() ?: 'unknown', ENT_QUOTES, 'UTF-8');
		$title       = htmlspecialchars($config['title'], ENT_QUOTES, 'UTF-8');
		$description = $config['description']; // already-trusted, contains <code> tags
		$endpoint    = htmlspecialchars($config['endpoint'], ENT_QUOTES, 'UTF-8');
		$runText     = htmlspecialchars($config['runButtonText'], ENT_QUOTES, 'UTF-8');
		$queryParams = htmlspecialchars($config['queryParams'] ?? '', ENT_QUOTES, 'UTF-8');
		$endpointJs  = addslashes($config['endpoint']);
		$queryJs     = addslashes($config['queryParams'] ?? '');
		$runTextJs   = addslashes($config['runButtonText']);

		$html = <<<HTML
		<!DOCTYPE html>
		<html lang="en">
		<head>
		<meta charset="utf-8">
		<title>{$title} — Shipard Dev</title>
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<style>
		* { box-sizing: border-box; }
		body {
			margin: 0;
			font-family: system-ui, -apple-system, sans-serif;
			background: #f3f4f6;
			color: #111827;
		}
		.banner {
			background: #d97706;
			color: white;
			padding: 8px 16px;
			text-align: center;
			font-weight: 600;
			font-size: 14px;
		}
		header.app {
			background: #1f2937;
			color: white;
			padding: 16px 24px;
			display: flex;
			justify-content: space-between;
			align-items: center;
		}
		header.app h1 { margin: 0; font-size: 18px; font-weight: 600; }
		header.app .meta { opacity: 0.9; font-size: 14px; }
		header.app .meta code { font-family: monospace; }
		header.app .meta a { color: #93c5fd; text-decoration: none; margin-left: 12px; }
		header.app .meta a:hover { text-decoration: underline; }
		main {
			max-width: 900px;
			margin: 24px auto;
			padding: 0 16px;
		}
		.card {
			background: white;
			border-radius: 6px;
			box-shadow: 0 1px 2px rgba(0,0,0,0.05);
			padding: 20px 24px;
			margin-bottom: 16px;
		}
		.card p { margin-top: 0; color: #374151; line-height: 1.5; }
		.card p code { background: #f3f4f6; padding: 1px 6px; border-radius: 3px; font-size: 0.9em; }
		.run-btn {
			background: #2563eb;
			color: white;
			border: none;
			border-radius: 4px;
			padding: 10px 18px;
			font-size: 14px;
			font-weight: 600;
			cursor: pointer;
			display: inline-flex;
			align-items: center;
			gap: 8px;
		}
		.run-btn:hover:not(:disabled) { background: #1d4ed8; }
		.run-btn:disabled { background: #93c5fd; cursor: default; }
		.spinner {
			display: inline-block;
			width: 12px;
			height: 12px;
			border: 2px solid rgba(255,255,255,0.4);
			border-top-color: white;
			border-radius: 50%;
			animation: spin 0.8s linear infinite;
		}
		@keyframes spin { to { transform: rotate(360deg); } }
		.output-section { display: none; }
		.output-section.active { display: block; }
		.output-section h2 {
			margin: 0 0 8px 0;
			font-size: 13px;
			color: #6b7280;
			font-weight: 600;
			text-transform: uppercase;
			letter-spacing: 0.05em;
		}
		.output-pre {
			background: #1f2937;
			color: #f3f4f6;
			padding: 12px 14px;
			border-radius: 4px;
			font-family: monospace;
			font-size: 0.85em;
			max-height: 60vh;
			overflow-y: auto;
			margin: 0;
			white-space: pre-wrap;
			word-break: break-word;
		}
		.output-pre .line-step  { color: #93c5fd; font-weight: 600; display: block; }
		.output-pre .line-error { color: #fca5a5; font-weight: 600; display: block; }
		.output-pre .line-done  { color: #86efac; font-weight: 600; display: block; }
		.output-pre .line-plain { color: #e5e7eb; display: block; }
		.result-banner {
			padding: 14px 16px;
			border-radius: 6px;
			margin-bottom: 12px;
			display: none;
			align-items: center;
			gap: 12px;
			flex-wrap: wrap;
		}
		.result-banner.active { display: flex; }
		.result-banner.success { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }
		.result-banner.error   { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
		.result-banner .msg { flex: 1; font-weight: 600; }
		.result-banner .actions { display: flex; gap: 8px; }
		.result-banner a, .result-banner button {
			padding: 6px 14px;
			border-radius: 4px;
			text-decoration: none;
			font-size: 13px;
			font-weight: 600;
			cursor: pointer;
			border: 1px solid transparent;
			background: white;
			color: #111827;
		}
		.result-banner a:hover, .result-banner button:hover { background: #f3f4f6; }
		.result-banner a.primary {
			background: #059669;
			color: white;
		}
		.result-banner a.primary:hover { background: #047857; }
		.result-banner.error a.primary { background: #dc2626; }
		.result-banner.error a.primary:hover { background: #b91c1c; }
		</style>
		</head>
		<body>
		<div class="banner">⚠️  DEVELOPMENT MODE — do not deploy publicly</div>
		<header class="app">
			<h1>{$title}</h1>
			<div class="meta">
				Server: <code>{$hostname}</code>
				<a href="/_dev/?refresh=1">&larr; Dashboard</a>
			</div>
		</header>
		<main>
			<div class="result-banner" id="resultBanner">
				<span class="msg" id="resultMsg"></span>
				<span class="actions" id="resultActions"></span>
			</div>

			<div class="card">
				<p>{$description}</p>
				<button type="button" class="run-btn" id="runButton">
					<span id="runLabel">{$runText}</span>
				</button>
			</div>

			<div class="output-section" id="outputSection">
				<div class="card">
					<h2>Output</h2>
					<pre class="output-pre" id="outputPre"></pre>
				</div>
			</div>
		</main>
		<script>
		(function () {
			var ENDPOINT = "{$endpointJs}";
			var QUERY_PARAMS = "{$queryJs}";
			var RUN_LABEL = "{$runTextJs}";

			var runButton    = document.getElementById('runButton');
			var runLabel     = document.getElementById('runLabel');
			var outputSection = document.getElementById('outputSection');
			var outputPre    = document.getElementById('outputPre');
			var resultBanner = document.getElementById('resultBanner');
			var resultMsg    = document.getElementById('resultMsg');
			var resultActions = document.getElementById('resultActions');

			function setBusy(busy) {
				runButton.disabled = busy;
				if (busy) {
					runLabel.textContent = 'Running...';
					var sp = document.createElement('span');
					sp.className = 'spinner';
					runButton.insertBefore(sp, runLabel);
				} else {
					runLabel.textContent = RUN_LABEL;
					var existing = runButton.querySelector('.spinner');
					if (existing) existing.remove();
				}
			}

			function appendOutput(line, kind) {
				var div = document.createElement('div');
				div.className = 'line-' + (kind || 'plain');
				div.textContent = line;
				outputPre.appendChild(div);
				outputPre.scrollTop = outputPre.scrollHeight;
			}

			function showDoneBanner(message) {
				resultBanner.classList.remove('error');
				resultBanner.classList.add('success', 'active');
				resultMsg.textContent = message || 'Done.';
				while (resultActions.firstChild) resultActions.removeChild(resultActions.firstChild);

				var again = document.createElement('button');
				again.type = 'button';
				again.textContent = 'Run again';
				again.addEventListener('click', resetAndRun);
				resultActions.appendChild(again);

				var back = document.createElement('a');
				back.className = 'primary';
				back.href = '/_dev/?refresh=1';
				back.textContent = 'Back to Dashboard';
				resultActions.appendChild(back);
			}

			function showErrorBanner(message) {
				resultBanner.classList.remove('success');
				resultBanner.classList.add('error', 'active');
				resultMsg.textContent = message || 'Failed.';
				while (resultActions.firstChild) resultActions.removeChild(resultActions.firstChild);

				var again = document.createElement('button');
				again.type = 'button';
				again.textContent = 'Run again';
				again.addEventListener('click', resetAndRun);
				resultActions.appendChild(again);

				var back = document.createElement('a');
				back.href = '/_dev/?refresh=1';
				back.textContent = 'Back to Dashboard';
				resultActions.appendChild(back);
			}

			function handleLine(line) {
				if (line === '') return;
				if (line.startsWith('[STEP] ')) {
					appendOutput(line, 'step');
				} else if (line.startsWith('[ERROR] ')) {
					appendOutput(line, 'error');
				} else if (line.startsWith('[DONE] ')) {
					appendOutput(line, 'done');
				} else {
					appendOutput(line, 'plain');
				}
			}

			function resetAndRun() {
				while (outputPre.firstChild) outputPre.removeChild(outputPre.firstChild);
				resultBanner.classList.remove('active', 'success', 'error');
				runAction();
			}

			async function runAction() {
				outputSection.classList.add('active');
				setBusy(true);

				var sawDone = false;
				var sawError = false;
				var doneMessage = '';
				var errorMessage = '';

				try {
					var response = await fetch(ENDPOINT + QUERY_PARAMS, { method: 'POST' });

					if (!response.ok) {
						var err = await response.json().catch(function () { return {}; });
						var msg = (err && err.error && err.error.message) || ('HTTP ' + response.status);
						showErrorBanner(msg);
						return;
					}

					var reader = response.body.getReader();
					var decoder = new TextDecoder();
					var buffer = '';

					while (true) {
						var chunk = await reader.read();
						if (chunk.done) break;
						buffer += decoder.decode(chunk.value, { stream: true });

						var nl;
						while ((nl = buffer.indexOf('\\n')) >= 0) {
							var line = buffer.slice(0, nl);
							buffer = buffer.slice(nl + 1);

							if (line.startsWith('[DONE] ')) {
								sawDone = true;
								try {
									var payload = JSON.parse(line.slice(7));
									doneMessage = payload.message || 'Done.';
								} catch (e) {
									doneMessage = 'Done.';
								}
							} else if (line.startsWith('[ERROR] ')) {
								sawError = true;
								errorMessage = line.slice(8);
							}
							handleLine(line);
						}
					}
					if (buffer) handleLine(buffer);

					if (sawError) {
						showErrorBanner(errorMessage);
					} else if (sawDone) {
						showDoneBanner(doneMessage);
					} else {
						showDoneBanner('Completed.');
					}
				} catch (e) {
					console.error(e);
					showErrorBanner('Network error: ' + e.message);
				} finally {
					setBusy(false);
				}
			}

			runButton.addEventListener('click', resetAndRun);
		})();
		</script>
		</body>
		</html>
		HTML;

		return Response::html($html);
	}
}

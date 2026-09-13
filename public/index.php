<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Shipard\Api\AlertCheckLoader;
use Shipard\Api\AuthContext;
use Shipard\Core\Auth\CurrentUser;
use Shipard\Api\Controller\AlertsController;
use Shipard\Api\Controller\AuthController;
use Shipard\Api\Controller\CrudController;
use Shipard\Api\Controller\DashboardController;
use Shipard\Api\Controller\ExchangeController;
use Shipard\Api\Controller\MetaController;
use Shipard\Api\Controller\NavigationController;
use Shipard\Api\Controller\SettingsController;
use Shipard\Api\Controller\OpenApiController;
use Shipard\Api\Controller\FormController;
use Shipard\Api\Controller\ViewerController;
use Shipard\Api\Controller\AttachmentController;
use Shipard\Api\Controller\ChatController;
use Shipard\Core\Ai\AnthropicLlmClient;
use Shipard\Api\Controller\MailController;
use Shipard\Api\Controller\AnalysisController;
use Shipard\Api\Controller\PersonsRegistryController;
use Shipard\Api\DataSourceResolver;
use Shipard\Api\DocumentLoader;
use Shipard\Api\FormLoader;
use Shipard\Api\LookupLoader;
use Shipard\Api\Exception\UnknownDataSourceException;
use Shipard\Api\Exception\DataSourceUnavailableException;
use Shipard\Api\Exception\UnknownHostException;
use Shipard\Api\Middleware\AuthMiddleware;
use Shipard\Api\Middleware\CorsMiddleware;
use Shipard\Api\Middleware\RateLimitMiddleware;
use Shipard\Api\ReadOnlyPolicy;
use Shipard\Api\ReadOnlyVerdict;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Api\Route;
use Shipard\Api\Router;
use Shipard\Api\TableLoader;
use Shipard\Api\ViewerLoader;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Form\FormRegistry;
use Shipard\Core\Form\Lookup\LookupRegistry;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Module\ModuleClassLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Viewer\ViewerRegistry;

$request        = Request::fromGlobals();

// Set request context immediately so even early errors carry it
ErrorLogger::setRequestContext($request->getMethod() . ' ' . $request->getPath());

$corsMiddleware = new CorsMiddleware();

// ── 1. CORS preflight ────────────────────────────────────────────────────────
$corsResult = $corsMiddleware->handle($request);
if ($corsResult !== null) {
	$corsResult->send();
	exit;
}

$serverConfig = null;

try {
	// ── 2. Server config ─────────────────────────────────────────────────────
	$serverConfig = new ServerConfig();
	$serverConfig->load();

	// Configure logger from server config — must happen as early as possible
	ErrorLogger::setLogPath($serverConfig->getLogFile());
	ErrorLogger::setLogLevel($serverConfig->getLogLevel());

	// ── 2a. Module path resolver + class autoloader ──────────────────────────
	$modulePathResolver = ModulePathResolver::fromServerConfig(
		$serverConfig, dirname(__DIR__) . '/modules',
	);
	ModuleClassLoader::register($modulePathResolver);

	// ── 2.5. Dev dashboard ───────────────────────────────────────────────────
	if ($serverConfig->getMode() === 'development') {
		$path = $request->getPath();
		if ($path === '/' || $path === '/_dev' || str_starts_with($path, '/_dev/')) {
			$response = (new \Shipard\Api\Controller\DevDashboardController(
				'/opt/shipard/data-sources',
				$serverConfig->getLogFile(),
				$modulePathResolver,
			))->dispatch($request);
			$corsMiddleware->applyTo($response)->send();
			exit;
		}
	}

	// ── 3. Resolve data source ────────────────────────────────────────────────
	$resolver = new DataSourceResolver($serverConfig->getDomainsFile(), $serverConfig->getDataSourcesDir());
	$resolved = $resolver->resolve($request->getHost(), $request->getPath());

	// DS is now known — propagate to logger
	ErrorLogger::setDsId($resolved->config->getId());

	// ── 4. Load table definitions (localized) ─────────────────────────────────
	$language = resolveLanguage($request, $resolved->config);
	$tables   = TableLoader::load($resolved->config, $modulePathResolver, $language);

	// ── 4b. Build viewer registry ─────────────────────────────────────────────
	$viewerRegistry = ViewerLoader::load($resolved->config, $modulePathResolver, $language);

	// ── 4c. Build form registry ───────────────────────────────────────────────
	$formRegistry = FormLoader::load($resolved->config, $modulePathResolver);

	// ── 4c2. Build lookup registry ───────────────────────────────────────────
	$lookupRegistry = LookupLoader::load($resolved->config, $modulePathResolver);

	// ── 4d. Build document registry ───────────────────────────────────────────
	$documentRegistry = DocumentLoader::load($resolved->config, $modulePathResolver);

	// ── 4e. Build alert check registry ────────────────────────────────────────
	$alertCheckRegistry = AlertCheckLoader::load($resolved->config, $modulePathResolver, $language);

	// ── 5. Route ──────────────────────────────────────────────────────────────
	$router      = new Router();
	$routeResult = $router->resolve($resolved->normalizedPath, $request->getMethod());
	if ($routeResult instanceof Response) {
		$corsMiddleware->applyTo($routeResult)->send();
		exit;
	}
	/** @var Route $route */
	$route = $routeResult;

	// ── 6. Auth ───────────────────────────────────────────────────────────────
	$openApiPublic  = (bool) ($resolved->config->getModules()['api']['openApiPublic'] ?? true);
	$authMiddleware = new AuthMiddleware();
	$authResult     = $authMiddleware->handle($request, $route, $resolved->connection, $openApiPublic);
	if ($authResult instanceof Response) {
		$corsMiddleware->applyTo($authResult)->send();
		exit;
	}
	/** @var AuthContext $auth */
	$auth = $authResult;
	// Aktuální uživatel pro Document hooky (locked_by zámků, #55 D25/D27).
	CurrentUser::set($auth->userId);

	// ── 6.5. Read-only vynucení (#56 fáze 2) ─────────────────────────────────
	// Za auth (anonym dostane 401 dřív než informaci o stavu DS), před rate
	// limitem. `active` politiku vůbec nevolá. Verdikt per routa (D7),
	// neznámá routa fail-closed 403 — viz ReadOnlyPolicy.
	if ($resolved->isReadOnly()) {
		$verdict = (new ReadOnlyPolicy())->verdict($route);
		if ($verdict === ReadOnlyVerdict::Deny503) {
			// Strojový ingest (D4): stejná odpověď jako zavřený DS, volající
			// frontuje. Info do logu — ops vidí, že router/analyzer čeká.
			ErrorLogger::info('request refused — data source read-only', [
				'controller' => $route->controller,
				'action' => $route->action,
			]);
			$corsMiddleware->applyTo(unavailableResponse($resolved->state->getEffectiveState()))->send();
			exit;
		}
		if ($verdict === ReadOnlyVerdict::Deny403) {
			$corsMiddleware->applyTo(
				Response::error('DS_READ_ONLY', 'Data source is read-only', 403),
			)->send();
			exit;
		}
	}

	// ── 7. Rate limiting ──────────────────────────────────────────────────────
	$rateLimiter = new RateLimitMiddleware();
	$rateResult  = $rateLimiter->handle($request, $auth, $route, $resolved->connection);
	if ($rateResult instanceof Response) {
		applyAllHeaders($corsMiddleware, $rateLimiter, $rateResult)->send();
		exit;
	}

	// ── 8. Load compiled config (best-effort) ────────────────────────────────
	$configRuntime = null;
	try {
		$configRuntime = \Shipard\Core\Config\ConfigRuntime::load(
			$resolved->config->getDataSourceDir(),
			$language,
		);
	} catch (\Throwable) {
		// Config may not be compiled yet — doc state and enum features degrade gracefully
	}

	// ── 8b. Build journal + document event dispatchers ───────────────────────
	// Journal dispatcher (journalEventHandlers) se vkládá do document dispatcheru,
	// aby ho ten injektoval do handlerů konstruujících účtovací engine.
	$journalEventDispatcher = \Shipard\Api\JournalEventHandlerLoader::load(
		$resolved->config,
		$modulePathResolver,
		$resolved->connection->getDibiConnection(),
		$configRuntime,
	);
	$documentEventDispatcher = \Shipard\Api\DocumentEventHandlerLoader::load(
		$resolved->config,
		$modulePathResolver,
		$resolved->connection->getDibiConnection(),
		$configRuntime,
		$journalEventDispatcher,
	);

	// ── 9. Dispatch to controller ─────────────────────────────────────────────
	$host     = $request->getHost();
	$response = dispatch(
		$route, $request, $auth, $tables,
		$resolved->connection, $openApiPublic,
		$host, $resolved, $modulePathResolver,
		$viewerRegistry, $configRuntime, $formRegistry, $documentRegistry,
		$lookupRegistry, $alertCheckRegistry, $serverConfig,
		$documentEventDispatcher, $journalEventDispatcher,
	);

	// ── 10. Apply headers and send ────────────────────────────────────────────
	applyAllHeaders($corsMiddleware, $rateLimiter, $response)->send();

} catch (DataSourceUnavailableException $e) {
	// DS zavřený stavem (suspended / maintenance / pending_deletion) — 503,
	// aby mail-router a monitoring frontovaly místo zahazování (#56 D3).
	// Zavřený DS není chyba → info. Reason maintenance je interní, jen do logu.
	ErrorLogger::setDsId($e->dsId);
	ErrorLogger::info('request refused — data source unavailable', [
		'state' => $e->effectiveState,
		'maintenanceReason' => $e->maintenanceReason,
	]);
	$corsMiddleware->applyTo(unavailableResponse($e->effectiveState))->send();
} catch (UnknownDataSourceException $e) {
	$corsMiddleware->applyTo(
		Response::error('UNKNOWN_DATASOURCE', "Unknown data source: {$e->dsId}", 404),
	)->send();
} catch (UnknownHostException) {
	$corsMiddleware->applyTo(
		Response::error('UNKNOWN_HOST', 'Unknown host', 404),
	)->send();
} catch (\Throwable $e) {
	// Always log — request context and ds id were set on the logger earlier
	// (see bootstrap), so the JSON entry carries everything needed for triage.
	ErrorLogger::logException($e);

	$isDev = $serverConfig !== null && $serverConfig->getMode() === 'development';
	$details = $isDev
		? [
			[
				'field'   => '_exception',
				'code'    => get_class($e),
				'message' => $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(),
				// Trim trace to keep the response readable; full trace is in the log file
				'trace'   => array_slice(
					preg_split('/\r?\n/', $e->getTraceAsString()) ?: [],
					0,
					10,
				),
			],
		]
		: [];
	$corsMiddleware->applyTo(
		Response::error('INTERNAL_ERROR', 'Internal server error', 500, $details),
	)->send();
}

// ── Helpers ──────────────────────────────────────────────────────────────────

function resolveLanguage(Request $request, ?\Shipard\Core\Config\DataSourceConfig $config = null): string
{
	$fallback = $config?->getDefaultLanguage() ?? 'en';

	$header = $request->getHeader('Accept-Language');
	if ($header === null) {
		return $fallback;
	}
	// Parse first language tag: "cs-CZ,cs;q=0.9,en;q=0.8" → "cs"
	$first = explode(',', $header)[0];
	$first = explode(';', $first)[0];
	$first = explode('-', trim($first))[0];
	return $first !== '' ? strtolower($first) : $fallback;
}

/**
 * 503 pro DS, který teď nepřijímá požadavek (zavřený stav, nebo read-only
 * pro strojový ingest). Retry-After — mail-router a analyzer frontují.
 */
function unavailableResponse(string $effectiveState): Response
{
	return Response::error('DS_UNAVAILABLE', 'Data source is temporarily unavailable', 503, [
		['field' => '_state', 'code' => $effectiveState, 'message' => 'Data source state: ' . $effectiveState],
	])->withHeader('Retry-After', '300');
}

function applyAllHeaders(CorsMiddleware $cors, RateLimitMiddleware $rateLimit, Response $response): Response
{
	$response = $cors->applyTo($response);
	foreach ($rateLimit->getHeaders() as $name => $value) {
		$response = $response->withHeader($name, $value);
	}
	return $response;
}

function dispatch(
	Route $route,
	Request $request,
	AuthContext $auth,
	array $tables,
	\Shipard\Core\Database\DataSourceConnection $db,
	bool $openApiPublic,
	string $host,
	\Shipard\Api\ResolvedDataSource $resolved,
	ModulePathResolver $modulePathResolver,
	ViewerRegistry $viewerRegistry,
	?\Shipard\Core\Config\ConfigRuntime $configRuntime = null,
	?FormRegistry $formRegistry = null,
	?\Shipard\Core\Document\DocumentRegistry $documentRegistry = null,
	?LookupRegistry $lookupRegistry = null,
	?\Shipard\Core\Alerts\AlertCheckRegistry $alertCheckRegistry = null,
	?ServerConfig $serverConfig = null,
	?\Shipard\Core\Document\DocumentEventDispatcher $documentEventDispatcher = null,
	?\Shipard\Core\Document\JournalEventDispatcher $journalEventDispatcher = null,
): Response {
	$baseUrl = $resolved->isDevMode()
		? 'http://' . $host . '/' . $resolved->config->getId()
		: 'https://' . $host;

	return match ($route->controller) {
		'auth'    => dispatchAuth($route->action, $request, $auth, $db, $resolved),
		'password' => dispatchPassword($route, $request, $auth, $db, $resolved),
		'crud'       => dispatchCrud($route, $request, $auth, $tables, $db, $configRuntime, $documentRegistry ?? new \Shipard\Core\Document\DocumentRegistry()),
		'attachment'  => dispatchAttachment($route, $request, $auth, $tables, $db, $resolved, $modulePathResolver),
		'chat'    => dispatchChat($route, $request, $auth, $db, $tables, $configRuntime, $resolved, $documentRegistry ?? new \Shipard\Core\Document\DocumentRegistry(), resolveLanguage($request, $resolved->config), $alertCheckRegistry),
		'meta'    => dispatchMeta($route->action, $route->table, $tables, resolveLanguage($request, $resolved->config)),
		'ui'      => dispatchUi($route->action, $resolved->config, $modulePathResolver, resolveLanguage($request, $resolved->config), $configRuntime, $db, $auth, $tables, $resolved->isReadOnly()),
		'dashboard' => dispatchDashboard($route, $db, $configRuntime, resolveLanguage($request, $resolved->config), $resolved->config, $alertCheckRegistry, $tables, $auth, $request, $resolved->isReadOnly()),
		'settings' => dispatchSettings($route, $request, $auth, $resolved->config, $modulePathResolver, resolveLanguage($request, $resolved->config), $configRuntime, $db, $tables),
		'app'     => dispatchApp($route, $auth, $db, $resolved->config, $tables, $resolved->isDevMode(), $resolved->state->getEffectiveState()),
		'form'    => dispatchForm($route, $request, $auth, $tables, $db, $formRegistry ?? new FormRegistry(), $configRuntime, $modulePathResolver, $documentRegistry ?? new \Shipard\Core\Document\DocumentRegistry(), resolveLanguage($request, $resolved->config), $resolved->config, $lookupRegistry ?? new LookupRegistry(), $documentEventDispatcher),
		'lookup'  => dispatchLookup($route, $request, $auth, $tables, $db, $lookupRegistry ?? new LookupRegistry(), $configRuntime),
		'viewer'  => dispatchViewer($route, $request, $auth, $viewerRegistry, $tables, $db, $configRuntime, resolveLanguage($request, $resolved->config), $documentRegistry, $resolved->config),
		'mail'    => dispatchMail($route, $request, $auth, $tables, $db, $resolved, $documentRegistry ?? new \Shipard\Core\Document\DocumentRegistry(), $configRuntime),
		'senderRules' => dispatchSenderRules($route, $request, $auth, $tables, $db, $resolved, $documentRegistry ?? new \Shipard\Core\Document\DocumentRegistry(), $configRuntime, $documentEventDispatcher),
		'registry' => dispatchRegistry($route, $request, $auth, $tables, $db, $resolved, $documentRegistry ?? new \Shipard\Core\Document\DocumentRegistry(), $configRuntime),
		'analysis' => dispatchAnalysis($route, $request, $auth, $tables, $db, $configRuntime, $resolved, $documentRegistry ?? new \Shipard\Core\Document\DocumentRegistry(), $documentEventDispatcher),
		'exchange' => dispatchExchange($route, $request, $tables, $db, $configRuntime, $resolved, $documentRegistry ?? new \Shipard\Core\Document\DocumentRegistry(), $documentEventDispatcher),
		'contentTags' => dispatchContentTags($route, $request, $auth, $db, $configRuntime, resolveLanguage($request, $resolved->config), $tables, $resolved->config, $documentRegistry ?? new \Shipard\Core\Document\DocumentRegistry(), $documentEventDispatcher),
		'alerts' => dispatchAlerts($route, $request, $db, $alertCheckRegistry, $configRuntime, resolveLanguage($request, $resolved->config)),
		'reports' => dispatchReports($route, $request, $db, $configRuntime, $modulePathResolver, $resolved, resolveLanguage($request, $resolved->config)),
		'setup' => dispatchSetup($route, $request, $auth, $db, $alertCheckRegistry, $configRuntime, $modulePathResolver, resolveLanguage($request, $resolved->config), $tables, $resolved->config, $documentRegistry ?? new \Shipard\Core\Document\DocumentRegistry(), $documentEventDispatcher),
		'dsAbout' => dispatchDsAbout($route, $auth, $db, $configRuntime, $resolved->config, resolveLanguage($request, $resolved->config), $tables),
		'accbal'  => dispatchAccbal($route, $request, $db, $configRuntime, $journalEventDispatcher, $resolved->config),
		'accounting' => dispatchAccounting($route, $request, $db, $configRuntime, $journalEventDispatcher, $documentRegistry, $resolved->config),
		'vat' => dispatchVat($route, $request, $db, $configRuntime, $resolved, $auth, $documentRegistry, $tables, $documentEventDispatcher),
		'bank'    => dispatchBank($route, $request, $auth, $tables, $db, $resolved, $configRuntime, $documentRegistry ?? new \Shipard\Core\Document\DocumentRegistry(), $documentEventDispatcher, $journalEventDispatcher),
		'personsRegistry' => dispatchPersonsRegistry($route, $request, $tables, $db, $configRuntime, $resolved, $documentRegistry ?? new \Shipard\Core\Document\DocumentRegistry(), $serverConfig),
		'hostingPortal' => dispatchHostingPortal($route, $request, $auth, $db, $tables, $resolved, $modulePathResolver, $configRuntime, $documentRegistry ?? new \Shipard\Core\Document\DocumentRegistry()),
		'hostingOidc' => dispatchHostingOidc($route, $request, $auth, $db, $tables, $resolved),
		'hostingServer' => dispatchHostingServer($route, $request, $db, $tables, $resolved),
		'hostingMail' => dispatchHostingMail($route, $request, $db, $tables, $resolved),
		'hostingAiAnalyzer' => dispatchHostingAiAnalyzer($route, $request, $db, $tables, $resolved),
		'hostingAiGateway' => dispatchHostingAiGateway($route, $request, $db, $tables, $resolved),
		'mcp'     => dispatchMcp($request, $auth, $resolved->connection, $tables, $configRuntime, $resolved, $documentRegistry ?? new \Shipard\Core\Document\DocumentRegistry(), resolveLanguage($request, $resolved->config), $alertCheckRegistry),
		'openapi' => (new OpenApiController())->spec($auth, $openApiPublic, $tables, $baseUrl),
		default   => Response::error('INTERNAL_ERROR', "Unknown controller: {$route->controller}", 500),
	};
}

function dispatchMcp(
	Request $request,
	AuthContext $auth,
	\Shipard\Core\Database\DataSourceConnection $db,
	array $tables,
	?\Shipard\Core\Config\ConfigRuntime $configRuntime,
	\Shipard\Api\ResolvedDataSource $resolved,
	\Shipard\Core\Document\DocumentRegistry $documentRegistry,
	?string $language = null,
	?\Shipard\Core\Alerts\AlertCheckRegistry $alertCheckRegistry = null,
): Response {
	$registry = buildMcpRegistry($db, $tables, $configRuntime, $resolved, $documentRegistry, $language, $alertCheckRegistry);
	$ctrl = new \Shipard\Api\Controller\McpController($registry, $resolved->isReadOnly());
	return $ctrl->rpc($request, $auth, $db, $tables, $configRuntime);
}

/**
 * Builds the in-process MCP tool registry shared by /_mcp (dispatchMcp) and the
 * chat tool-use loop (dispatchChat). Every tool is registered here; the chat
 * loop filters to read-only tools itself via McpTool::isReadOnly().
 *
 * @param array<string, \Shipard\Core\Database\TableDefinition> $tables
 */
function buildMcpRegistry(
	\Shipard\Core\Database\DataSourceConnection $db,
	array $tables,
	?\Shipard\Core\Config\ConfigRuntime $configRuntime,
	\Shipard\Api\ResolvedDataSource $resolved,
	\Shipard\Core\Document\DocumentRegistry $documentRegistry,
	?string $language = null,
	?\Shipard\Core\Alerts\AlertCheckRegistry $alertCheckRegistry = null,
): \Shipard\Api\Mcp\McpToolRegistry {
	$registry = new \Shipard\Api\Mcp\McpToolRegistry();
	$registry->register(new \Shipard\Module\Base\Persons\Mcp\PersonsSearchTool());
	// Feed karet (UI shells Fáze 5) — jazyk a alert registry nejsou
	// v McpInvocationContext, injektují se konstruktorem (vzor
	// ReportToolSupport). Zdroje feedu se gatují samy dle $tables.
	$registry->register(new \Shipard\Api\Mcp\FeedCardsTool($language, $alertCheckRegistry));
	$registry->register(new \Shipard\Module\Base\Persons\Mcp\PersonsGetTool());
	$registry->register(new \Shipard\Module\Docs\Core\Mcp\DocumentsSearchTool());
	$registry->register(new \Shipard\Module\Docs\Core\Mcp\DocumentsAggregateTool());
	$registry->register(new \Shipard\Module\Core\Mail\Mcp\MailListPendingTool());
	// Uživatelská dokumentace z help/ — čte soubory aplikace, ne DB, takže
	// bez závislostí a nezávisle na modulech DS.
	$registry->register(new \Shipard\Module\Core\Help\Mcp\HelpSearchTool());
	$registry->register(new \Shipard\Module\Core\Help\Mcp\HelpGetPageTool());
	// Spisovna — jen s aktivním modulem base.registry.
	if (isset($tables['base_registry_documents'])) {
		$registry->register(new \Shipard\Module\Base\Registry\Mcp\RegistrySearchTool());
	}
	// Reporty (D11) — jen s aktivní účetní doménou; DataSourceConfig + jazyk
	// nese sdílený support (ctx je nemá), report registry se staví lazily.
	if (isset($tables['economy_accounting_journal'])) {
		$reportToolSupport = new \Shipard\Module\Economy\Accounting\Mcp\ReportToolSupport($resolved->config);
		$registry->register(new \Shipard\Module\Economy\Accounting\Mcp\ReportListTool($reportToolSupport));
		$registry->register(new \Shipard\Module\Economy\Accounting\Mcp\ReportRunTool($reportToolSupport));
	}

	// Zápisový nástroj mail_draft_document nad sdílenou apply službou.
	// DocumentApplier vyžaduje ConfigRuntime (jako dispatchExchange/Analysis);
	// bez něj injektujeme null → nástroj degraduje na graceful obálku.
	// Bez event dispatcheru záměrně: draft tool zakládá vždy jen Koncept
	// (targetDocState=10), nikdy stav 40 → účtování se ho netýká.
	// Registry target (Spisovna) — jen s aktivním modulem base.registry.
	$mcpTargetAppliers = [];
	if ($configRuntime !== null && isset($tables['base_registry_documents'])) {
		$mcpTargetAppliers['registry'] = new \Shipard\Module\Base\Registry\RegistryApplier(
			$db,
			$documentRegistry,
			new \Shipard\Module\Core\Attachments\AttachmentService(
				$db,
				$resolved->config->getDataSourceDir(),
				$tables,
			),
			$configRuntime,
			new \Shipard\Module\Core\Exchange\Resolve\PartyResolver(
				$db->getDibiConnection(),
				new \Shipard\Module\Docs\Core\OwnCompanyResolver($db->getDibiConnection()),
			),
		);
	}
	$draftApplier = $configRuntime !== null
		? new \Shipard\Module\Core\Mail\MessageProposalApplier(
			$db,
			\Shipard\Module\Core\Exchange\Document\DocumentApplier::create(
				$db->getDibiConnection(),
				$configRuntime,
				$resolved->config,
				$documentRegistry,
				$tables,
			),
			\Shipard\Module\Core\Exchange\Enrich\RowEnrichmentPipeline::create($db, $configRuntime, $resolved->config),
			$configRuntime,
			$mcpTargetAppliers,
		)
		: null;
	$registry->register(new \Shipard\Module\Core\Mail\Mcp\MailDraftDocumentTool($draftApplier));

	return $registry;
}

function dispatchAccounting(
	Route $route,
	Request $request,
	\Shipard\Core\Database\DataSourceConnection $db,
	?\Shipard\Core\Config\ConfigRuntime $configRuntime,
	?\Shipard\Core\Document\JournalEventDispatcher $journalEventDispatcher = null,
	?\Shipard\Core\Document\DocumentRegistry $documentRegistry = null,
	?\Shipard\Core\Config\DataSourceConfig $dsConfig = null,
): Response {
	$ctrl = new \Shipard\Module\Economy\Accounting\AccountingController(
		$db, $configRuntime, $journalEventDispatcher, $documentRegistry, $dsConfig,
	);
	return match ($route->action) {
		'reaccount' => $ctrl->reaccount($request),
		default     => Response::error('INTERNAL_ERROR', "Unknown accounting action: {$route->action}", 500),
	};
}

function dispatchVat(
	Route $route,
	Request $request,
	\Shipard\Core\Database\DataSourceConnection $db,
	?\Shipard\Core\Config\ConfigRuntime $configRuntime,
	\Shipard\Api\ResolvedDataSource $resolved,
	AuthContext $auth,
	?\Shipard\Core\Document\DocumentRegistry $documentRegistry = null,
	array $tables = [],
	?\Shipard\Core\Document\DocumentEventDispatcher $documentEventDispatcher = null,
): Response {
	$ctrl = new \Shipard\Module\Economy\Vat\VatFilingController(
		$db,
		$configRuntime,
		$resolved->config,
		$auth->userId,
		$documentRegistry,
		$tables['economy_vat_report_periods'] ?? null,
		$tables['economy_codebooks_vat_registrations'] ?? null,
		$tables,
		$documentEventDispatcher,
	);
	return match ($route->action) {
		'filingCompose'           => $ctrl->compose($request),
		'filingFiles'             => $ctrl->files($request),
		'filingHeaderFromProfile' => $ctrl->headerFromProfile($request),
		'reportPeriodLock'        => $ctrl->lockPeriod($request),
		'registrationTaxOffice'   => $ctrl->registrationTaxOffice($request),
		'filingAccount'           => $ctrl->account($request),
		'filingImport'            => $ctrl->import($request),
		'filingImportFinish'      => $ctrl->importFinish($request),
		default                   => Response::error('INTERNAL_ERROR', "Unknown vat action: {$route->action}", 500),
	};
}

function dispatchBank(
	Route $route,
	Request $request,
	AuthContext $auth,
	array $tables,
	\Shipard\Core\Database\DataSourceConnection $db,
	\Shipard\Api\ResolvedDataSource $resolved,
	?\Shipard\Core\Config\ConfigRuntime $configRuntime,
	\Shipard\Core\Document\DocumentRegistry $documentRegistry,
	?\Shipard\Core\Document\DocumentEventDispatcher $documentEventDispatcher = null,
	?\Shipard\Core\Document\JournalEventDispatcher $journalEventDispatcher = null,
): Response {
	$dsPath = $resolved->config->getDataSourceDir();
	$ctrl = new \Shipard\Module\Economy\Bank\BankController(
		$db,
		$configRuntime,
		$dsPath,
		$tables,
		$resolved->config,
		$documentRegistry,
		$documentEventDispatcher,
		$journalEventDispatcher,
	);
	return match ($route->action) {
		'importStatement' => $ctrl->importStatement($auth),
		'reaccount'       => $ctrl->reaccount($request),
		default           => Response::error('INTERNAL_ERROR', "Unknown bank action: {$route->action}", 500),
	};
}

/**
 * @param array<string, \Shipard\Core\Database\TableDefinition> $tables
 */
function dispatchHostingPortal(
	Route $route,
	Request $request,
	AuthContext $auth,
	\Shipard\Core\Database\DataSourceConnection $db,
	array $tables,
	\Shipard\Api\ResolvedDataSource $resolved,
	ModulePathResolver $modulePathResolver,
	?\Shipard\Core\Config\ConfigRuntime $configRuntime,
	\Shipard\Core\Document\DocumentRegistry $documentRegistry,
): Response {
	$ctrl = new \Shipard\Api\Controller\HostingPortalController();
	$installModules = new \Shipard\Core\Module\InstallModuleRegistry($modulePathResolver);
	return match ($route->action) {
		'myDatasources' => $ctrl->myDatasources($auth, $db, $tables),
		'createMeta'    => $ctrl->createMeta($auth, $db, $tables, $installModules, $configRuntime, resolveLanguage($request, $resolved->config)),
		'checkWebId'    => $ctrl->checkWebId($request, $auth, $db, $tables),
		'createDatasource' => $ctrl->createDatasource($request, $auth, $db, $tables, $installModules, $configRuntime, $resolved->config, $documentRegistry),
		default         => Response::error('INTERNAL_ERROR', "Unknown hostingPortal action: {$route->action}", 500),
	};
}

/**
 * @param array<string, \Shipard\Core\Database\TableDefinition> $tables
 */
function dispatchHostingOidc(
	Route $route,
	Request $request,
	AuthContext $auth,
	\Shipard\Core\Database\DataSourceConnection $db,
	array $tables,
	\Shipard\Api\ResolvedDataSource $resolved,
): Response {
	$ctrl = new \Shipard\Api\Controller\HostingOidcController($resolved->config, $resolved->isDevMode());
	return match ($route->action) {
		'discovery' => $ctrl->discovery($request, $db, $tables),
		'jwks'      => $ctrl->jwks($db, $tables),
		'authorize' => $ctrl->authorize($request, $db, $tables),
		'approve'   => $ctrl->approve($request, $auth, $db, $tables),
		// Token endpoint je form-encoded — Request::getBody() umí jen JSON,
		// $_POST plní PHP samo.
		'token'     => $ctrl->token($request, $_POST, $db, $tables),
		default     => Response::error('INTERNAL_ERROR', "Unknown hostingOidc action: {$route->action}", 500),
	};
}

/**
 * @param array<string, \Shipard\Core\Database\TableDefinition> $tables
 */
function dispatchHostingServer(
	Route $route,
	Request $request,
	\Shipard\Core\Database\DataSourceConnection $db,
	array $tables,
	\Shipard\Api\ResolvedDataSource $resolved,
): Response {
	// Endpointy jsou exempt (auth dělá kontroler) — velikost body omezit
	// už tady; rekonciliační inventura se do limitu vejde s velkou rezervou.
	$contentLength = (int) ($request->getHeader('content-length') ?? '0');
	if ($contentLength > 524288) {
		return Response::error('PAYLOAD_TOO_LARGE', 'Request body too large', 413);
	}
	$ctrl = new \Shipard\Api\Controller\HostingServerController($resolved->config);
	return match ($route->action) {
		'reconcile' => $ctrl->reconcile($request, $db, $tables),
		'queue'     => $ctrl->queue($request, $db, $tables),
		'confirm'   => $ctrl->confirm($request, $db, $tables),
		'stats'     => $ctrl->stats($request, $db, $tables),
		default     => Response::error('INTERNAL_ERROR', "Unknown hostingServer action: {$route->action}", 500),
	};
}

function dispatchHostingMail(
	Route $route,
	Request $request,
	\Shipard\Core\Database\DataSourceConnection $db,
	array $tables,
	\Shipard\Api\ResolvedDataSource $resolved,
): Response {
	// Endpoint je exempt (auth klíčem routeru dělá kontroler); config kvůli
	// DsSecretCipher — api_token se dešifruje až do lookup odpovědi.
	$ctrl = new \Shipard\Api\Controller\HostingMailController($resolved->config);
	return match ($route->action) {
		'lookup' => $ctrl->lookup($request, $db, $tables),
		default  => Response::error('INTERNAL_ERROR', "Unknown hostingMail action: {$route->action}", 500),
	};
}

function dispatchHostingAiAnalyzer(
	Route $route,
	Request $request,
	\Shipard\Core\Database\DataSourceConnection $db,
	array $tables,
	\Shipard\Api\ResolvedDataSource $resolved,
): Response {
	// Endpoint je exempt (auth klíčem analyzeru dělá kontroler); config
	// kvůli DsSecretCipher — api_token se dešifruje až do lookup odpovědi.
	$ctrl = new \Shipard\Api\Controller\HostingAiAnalyzerController($resolved->config);
	return match ($route->action) {
		'lookup' => $ctrl->lookup($request, $db, $tables),
		default  => Response::error('INTERNAL_ERROR', "Unknown hostingAiAnalyzer action: {$route->action}", 500),
	};
}

function dispatchHostingAiGateway(
	Route $route,
	Request $request,
	\Shipard\Core\Database\DataSourceConnection $db,
	array $tables,
	\Shipard\Api\ResolvedDataSource $resolved,
): Response {
	// Endpoint je exempt (auth gateway tokenem dělá kontroler) — velikost
	// body omezit už tady; limit dle kontraktu Anthropic Messages (D5).
	// Chybová odpověď v Anthropic formátu — klienti jí rozumí.
	$contentLength = (int) ($request->getHeader('content-length') ?? '0');
	if ($contentLength > 33554432) { // 32 MiB
		return \Shipard\Api\HostingAiGatewayTokenAuthenticator::anthropicError(
			'request_too_large', 'Request body too large', 413,
		);
	}
	$ctrl = new \Shipard\Api\Controller\HostingAiGatewayController($resolved->config);
	return match ($route->action) {
		'messages' => $ctrl->messages($request, $db, $tables),
		default    => Response::error('INTERNAL_ERROR', "Unknown hostingAiGateway action: {$route->action}", 500),
	};
}

function dispatchAlerts(
	Route $route,
	Request $request,
	\Shipard\Core\Database\DataSourceConnection $db,
	?\Shipard\Core\Alerts\AlertCheckRegistry $registry,
	?\Shipard\Core\Config\ConfigRuntime $configRuntime,
	string $language,
): Response {
	if ($registry === null) {
		return Response::error('INTERNAL_ERROR', 'AlertCheckRegistry is required for /_alerts endpoints', 500);
	}
	if ($configRuntime === null) {
		return Response::error('INTERNAL_ERROR', 'ConfigRuntime is required for /_alerts endpoints', 500);
	}

	$ctrl = new AlertsController($db, $registry, $configRuntime, $language);
	return match ($route->action) {
		'registry'  => $ctrl->registry(),
		'runDue'    => $ctrl->runDue(),
		'runCheck'  => $ctrl->runCheck($route->table ?? ''),
		'snooze'    => $ctrl->snooze((int) $route->id, $request),
		'dismiss'   => $ctrl->dismiss((int) $route->id),
		'unsnooze'  => $ctrl->unsnooze((int) $route->id),
		default     => Response::error('INTERNAL_ERROR', "Unknown alerts action: {$route->action}", 500),
	};
}

function dispatchReports(
	Route $route,
	Request $request,
	\Shipard\Core\Database\DataSourceConnection $db,
	?\Shipard\Core\Config\ConfigRuntime $configRuntime,
	ModulePathResolver $modulePathResolver,
	\Shipard\Api\ResolvedDataSource $resolved,
	string $language,
): Response {
	// Registry se staví lazily až tady — /_reports je jediný konzument,
	// bootstrap ostatních requestů modul scan navíc neplatí.
	$registry = \Shipard\Api\ReportDefinitionLoader::load($resolved->config, $modulePathResolver, $language);
	$runner   = new \Shipard\Core\Reports\ReportRunner(
		$registry,
		$db,
		$configRuntime,
		$resolved->config->getId(),
		$language,
	);

	$ctrl = new \Shipard\Api\Controller\ReportsController(
		$registry,
		$runner,
		new \Shipard\Core\Reports\DbFiscalPeriodProvider($db),
		// Konstrukce bez dotazu — catalog() se na registrace ptá jen když je
		// registrovaný nějaký vatPeriod report (ten garantuje tabulky).
		new \Shipard\Core\Reports\DbReportPeriodProvider($db),
	);
	return match ($route->action) {
		'catalog' => $ctrl->catalog(),
		'run'     => $ctrl->run($route->table ?? '', $request->getQueryParams()),
		default   => Response::error('INTERNAL_ERROR', "Unknown reports action: {$route->action}", 500),
	};
}

function dispatchContentTags(
	Route $route,
	Request $request,
	AuthContext $auth,
	\Shipard\Core\Database\DataSourceConnection $db,
	?\Shipard\Core\Config\ConfigRuntime $configRuntime,
	string $language,
	array $tables = [],
	?\Shipard\Core\Config\DataSourceConfig $dsConfig = null,
	?\Shipard\Core\Document\DocumentRegistry $documentRegistry = null,
	?\Shipard\Core\Document\DocumentEventDispatcher $eventDispatcher = null,
): Response {
	if ($configRuntime === null) {
		return Response::error('INTERNAL_ERROR', 'ConfigRuntime is required for /_exchange/content-tags endpoints', 500);
	}

	$ctrl = new \Shipard\Api\Controller\ContentTagsController(
		$db,
		$configRuntime,
		$language,
		$dsConfig,
		$tables,
		$documentRegistry,
		$eventDispatcher,
	);
	return match ($route->action) {
		'materialize' => $ctrl->materialize($request, $auth),
		'overview'    => $ctrl->overview($auth),
		'tagItems'    => $ctrl->tagItems($request, $auth),
		default       => Response::error('INTERNAL_ERROR', "Unknown content-tags action: {$route->action}", 500),
	};
}

function dispatchSetup(
	Route $route,
	Request $request,
	AuthContext $auth,
	\Shipard\Core\Database\DataSourceConnection $db,
	?\Shipard\Core\Alerts\AlertCheckRegistry $registry,
	?\Shipard\Core\Config\ConfigRuntime $configRuntime,
	ModulePathResolver $modulePathResolver,
	string $language,
	array $tables = [],
	?\Shipard\Core\Config\DataSourceConfig $dsConfig = null,
	?\Shipard\Core\Document\DocumentRegistry $documentRegistry = null,
	?\Shipard\Core\Document\DocumentEventDispatcher $eventDispatcher = null,
): Response {
	if ($registry === null) {
		return Response::error('INTERNAL_ERROR', 'AlertCheckRegistry is required for /_setup endpoints', 500);
	}
	if ($configRuntime === null) {
		return Response::error('INTERNAL_ERROR', 'ConfigRuntime is required for /_setup endpoints', 500);
	}

	$ctrl = new \Shipard\Api\Controller\SetupController(
		$db,
		$registry,
		$configRuntime,
		$language,
		$modulePathResolver,
		$dsConfig,
		$tables,
		$documentRegistry,
		$eventDispatcher,
	);
	return match ($route->action) {
		'checklist'               => $ctrl->checklist($auth),
		'parameters'              => $ctrl->saveParameters($request, $auth),
		'vatRegistrationPrefill'  => $ctrl->vatRegistrationPrefill($auth),
		'bankAccountCandidates'   => $ctrl->bankAccountCandidates($auth),
		'bridgeBankAccounts'      => $ctrl->bridgeBankAccounts($request, $auth),
		'accountingItemsOffer'    => $ctrl->accountingItemsOffer($auth),
		'generateAccountingItems' => $ctrl->generateAccountingItems($request, $auth),
		default                   => Response::error('INTERNAL_ERROR', "Unknown setup action: {$route->action}", 500),
	};
}

/** Panel „O zdroji dat" — GET /_ui/ds-about (tasks/ds-about-panel.md, Issue #41). */
function dispatchDsAbout(
	Route $route,
	AuthContext $auth,
	\Shipard\Core\Database\DataSourceConnection $db,
	?\Shipard\Core\Config\ConfigRuntime $configRuntime,
	\Shipard\Core\Config\DataSourceConfig $dsConfig,
	string $language,
	array $tables = [],
): Response {
	if ($configRuntime === null) {
		return Response::error('INTERNAL_ERROR', 'ConfigRuntime is required for /_ui/ds-about', 500);
	}

	$ctrl = new \Shipard\Api\Controller\DsAboutController($db, $configRuntime, $dsConfig, $language, $tables);
	return match ($route->action) {
		'about' => $ctrl->about($auth),
		default => Response::error('INTERNAL_ERROR', "Unknown dsAbout action: {$route->action}", 500),
	};
}

function dispatchAccbal(
	Route $route,
	Request $request,
	\Shipard\Core\Database\DataSourceConnection $db,
	?\Shipard\Core\Config\ConfigRuntime $configRuntime,
	?\Shipard\Core\Document\JournalEventDispatcher $journalEventDispatcher,
	\Shipard\Core\Config\DataSourceConfig $dsConfig,
): Response {
	if ($configRuntime === null) {
		return Response::error('INTERNAL_ERROR', 'ConfigRuntime is required for /_accbal endpoints', 500);
	}
	if ($journalEventDispatcher === null) {
		// Bez journal dispatcheru by se po reaccountu nespustila re-derivace ledgeru.
		return Response::error('INTERNAL_ERROR', 'JournalEventDispatcher is required for /_accbal endpoints', 500);
	}

	$ctrl = new \Shipard\Api\Controller\AccbalController($db, $configRuntime, $journalEventDispatcher, $dsConfig);
	return match ($route->action) {
		'match' => $ctrl->match($request),
		default => Response::error('INTERNAL_ERROR', "Unknown accbal action: {$route->action}", 500),
	};
}

function dispatchExchange(
	Route $route,
	Request $request,
	array $tables,
	\Shipard\Core\Database\DataSourceConnection $db,
	?\Shipard\Core\Config\ConfigRuntime $configRuntime,
	\Shipard\Api\ResolvedDataSource $resolved,
	\Shipard\Core\Document\DocumentRegistry $documentRegistry,
	?\Shipard\Core\Document\DocumentEventDispatcher $documentEventDispatcher = null,
): Response {
	if ($configRuntime === null) {
		return Response::error('INTERNAL_ERROR', 'ConfigRuntime is required for /_exchange endpoints', 500);
	}

	$applier = \Shipard\Module\Core\Exchange\Document\DocumentApplier::create(
		$db->getDibiConnection(),
		$configRuntime,
		$resolved->config,
		$documentRegistry,
		$tables,
		$documentEventDispatcher,
	);
	$personApplier = \Shipard\Module\Core\Exchange\Person\PersonApplier::create(
		$db->getDibiConnection(),
		$configRuntime,
		$resolved->config,
		$documentRegistry,
		$tables,
	);
	$itemApplier = \Shipard\Module\Core\Exchange\Item\ItemApplier::create(
		$db->getDibiConnection(),
		$configRuntime,
		$resolved->config,
		$documentRegistry,
		$tables,
		$personApplier,
	);
	$bankApplier = \Shipard\Module\Core\Exchange\Bank\BankStatementApplier::create(
		$db->getDibiConnection(),
		$configRuntime,
		$resolved->config,
		$documentRegistry,
		$tables,
		$documentEventDispatcher,
	);
	$ctrl = new ExchangeController($applier, $personApplier, $itemApplier, $bankApplier);

	return match ($route->action) {
		'validate'        => $ctrl->validate($request),
		'preview'         => $ctrl->preview($request),
		'apply'            => $ctrl->apply($request),
		'person:validate' => $ctrl->validatePerson($request),
		'person:preview'  => $ctrl->previewPerson($request),
		'person:apply'    => $ctrl->applyPerson($request),
		'item:validate'   => $ctrl->validateItem($request),
		'item:preview'    => $ctrl->previewItem($request),
		'item:apply'      => $ctrl->applyItem($request),
		'bank:validate'   => $ctrl->validateBankStatement($request),
		'bank:preview'    => $ctrl->previewBankStatement($request),
		'bank:apply'      => $ctrl->applyBankStatement($request),
		default           => Response::error('INTERNAL_ERROR', "Unknown exchange action: {$route->action}", 500),
	};
}

function dispatchPersonsRegistry(
	Route $route,
	Request $request,
	array $tables,
	\Shipard\Core\Database\DataSourceConnection $db,
	?\Shipard\Core\Config\ConfigRuntime $configRuntime,
	\Shipard\Api\ResolvedDataSource $resolved,
	\Shipard\Core\Document\DocumentRegistry $documentRegistry,
	?ServerConfig $serverConfig,
): Response {
	if ($configRuntime === null) {
		return Response::error('INTERNAL_ERROR', 'ConfigRuntime is required for /persons/registry endpoints', 500);
	}
	if ($serverConfig === null) {
		return Response::error('INTERNAL_ERROR', 'ServerConfig is required for /persons/registry endpoints', 500);
	}

	$client = \Shipard\Module\Base\Persons\Registry\PersonsRegistryClient::fromServerConfig($serverConfig);

	$personApplier = \Shipard\Module\Core\Exchange\Person\PersonApplier::create(
		$db->getDibiConnection(),
		$configRuntime,
		$resolved->config,
		$documentRegistry,
		$tables,
	);
	$importer = new \Shipard\Module\Base\Persons\Registry\RegistryPersonImporter(
		$client, $personApplier,
	);

	$ctrl = new PersonsRegistryController($client, $importer, $db);

	return match ($route->action) {
		'search'      => $ctrl->search($request),
		'fetchPerson' => (function () use ($ctrl, $route): Response {
			$parts = explode(':', (string) $route->table, 2);
			if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
				return Response::error('NOT_FOUND', 'Not found', 404);
			}
			return $ctrl->fetchPerson($parts[0], $parts[1]);
		})(),
		'import'      => $ctrl->import($request),
		default       => Response::error('INTERNAL_ERROR', "Unknown personsRegistry action: {$route->action}", 500),
	};
}

function dispatchMail(
	Route $route,
	Request $request,
	AuthContext $auth,
	array $tables,
	\Shipard\Core\Database\DataSourceConnection $db,
	\Shipard\Api\ResolvedDataSource $resolved,
	\Shipard\Core\Document\DocumentRegistry $documentRegistry,
	?\Shipard\Core\Config\ConfigRuntime $configRuntime = null,
): Response {
	$dsPath = $resolved->config->getDataSourceDir();

	// Lazy wiring deterministického ISDOC importu (tasks/mail-isdoc-import.md).
	// Stejná degradace jako dispatchAnalysis: bez ConfigRuntime běží import
	// bez RowHistoryEnricheru (jen bez obohacení řádků z historie).
	$isdocImportFactory = static fn(): \Shipard\Module\Core\Mail\IsdocImportService =>
		new \Shipard\Module\Core\Mail\IsdocImportService(
			$db,
			new \Shipard\Module\Core\Exchange\Schema\SchemaValidator(
				\Shipard\Module\Core\Exchange\Schema\SchemaLoader::default(),
			),
			$configRuntime !== null
				? \Shipard\Module\Core\Exchange\Enrich\RowHistoryEnricher::create($db->getDibiConnection())
				: null,
			$dsPath,
			partnerWriter: \Shipard\Module\Core\Mail\MessagePartnerWriter::create($db->getDibiConnection(), $configRuntime),
			// Labely typů v jazyce AI profilu DS, ne requestu (mail-router
			// Accept-Language neposílá).
			titleComposer: \Shipard\Module\Core\Mail\MessageTitleComposer::forDataSource($db, $resolved->config),
		);

	$ctrl = new MailController(
		$db, $dsPath, $tables, $documentRegistry, $configRuntime, $resolved->config,
		$isdocImportFactory,
	);
	return match ($route->action) {
		'receiveIncoming'   => $ctrl->receiveIncoming($auth, $request),
		'importMessage'     => $ctrl->importMessage($auth, $request),
		'uploadMessages'    => $ctrl->uploadMessages($auth, $request),
		'setSenderPassword' => $ctrl->setSenderPassword($auth, $request, (int) $route->id),
		default             => Response::error('INTERNAL_ERROR', "Unknown mail action: {$route->action}", 500),
	};
}

function dispatchSenderRules(
	Route $route,
	Request $request,
	AuthContext $auth,
	array $tables,
	\Shipard\Core\Database\DataSourceConnection $db,
	\Shipard\Api\ResolvedDataSource $resolved,
	\Shipard\Core\Document\DocumentRegistry $documentRegistry,
	?\Shipard\Core\Config\ConfigRuntime $configRuntime = null,
	?\Shipard\Core\Document\DocumentEventDispatcher $documentEventDispatcher = null,
): Response {
	$ctrl = new \Shipard\Api\Controller\SenderRulesController(
		$db, $tables, $documentRegistry, $configRuntime, $resolved->config, $documentEventDispatcher,
	);
	return match ($route->action) {
		'confirmRule'     => $ctrl->confirmRule($auth, (int) $route->id),
		'rejectRule'      => $ctrl->rejectRule($auth, (int) $route->id),
		'undoAutoArchive' => $ctrl->undoAutoArchive($auth, $request),
		default           => Response::error('INTERNAL_ERROR', "Unknown senderRules action: {$route->action}", 500),
	};
}

function dispatchRegistry(
	Route $route,
	Request $request,
	AuthContext $auth,
	array $tables,
	\Shipard\Core\Database\DataSourceConnection $db,
	\Shipard\Api\ResolvedDataSource $resolved,
	\Shipard\Core\Document\DocumentRegistry $documentRegistry,
	?\Shipard\Core\Config\ConfigRuntime $configRuntime = null,
): Response {
	$attachments = new \Shipard\Module\Core\Attachments\AttachmentService(
		$db,
		$resolved->config->getDataSourceDir(),
		$tables,
	);
	$service = new \Shipard\Module\Base\Registry\FileFromMessageService(
		$db,
		$documentRegistry,
		$attachments,
		$configRuntime,
	);
	$importService = new \Shipard\Module\Base\Registry\RegistryImportService(
		$db,
		$documentRegistry,
		$tables['base_registry_documents'] ?? null,
		$configRuntime,
	);
	$ctrl = new \Shipard\Api\Controller\RegistryController(
		$service,
		new \Shipard\Module\Base\Registry\ExtractedTextFiller($db, $attachments),
		$db,
		$importService,
	);
	return match ($route->action) {
		'import'      => $ctrl->import($auth, $request),
		'fromMessage' => $ctrl->fromMessage($auth, (int) $route->id),
		'extractText' => $ctrl->extractText($auth, (int) $route->id),
		default       => Response::error('INTERNAL_ERROR', "Unknown registry action: {$route->action}", 500),
	};
}

function dispatchAnalysis(
	Route $route,
	Request $request,
	AuthContext $auth,
	array $tables,
	\Shipard\Core\Database\DataSourceConnection $db,
	?\Shipard\Core\Config\ConfigRuntime $configRuntime,
	\Shipard\Api\ResolvedDataSource $resolved,
	\Shipard\Core\Document\DocumentRegistry $documentRegistry,
	?\Shipard\Core\Document\DocumentEventDispatcher $documentEventDispatcher = null,
): Response {
	$dsPath = $resolved->config->getDataSourceDir();

	// Exchange wiring: SchemaValidator for /result canonical validation,
	// DocumentApplier for /applyExtracted. Both require ConfigRuntime; if
	// the compiled config is missing we degrade gracefully (controller
	// falls back to legacy behaviour). See Phase 2 spec.
	$schemaValidator = new \Shipard\Module\Core\Exchange\Schema\SchemaValidator(
		\Shipard\Module\Core\Exchange\Schema\SchemaLoader::default(),
	);
	$applier = $configRuntime !== null
		? \Shipard\Module\Core\Exchange\Document\DocumentApplier::create(
			$db->getDibiConnection(),
			$configRuntime,
			$resolved->config,
			$documentRegistry,
			$tables,
			$documentEventDispatcher,
		)
		: null;
	// Obohacení řádků — pipeline Vrstvy 0 (historie) + Vrstvy 2 (obsahová
	// eskalace); stejná degradace jako applier (bez ConfigRuntime se
	// enrichment přeskočí).
	$enricher = $configRuntime !== null
		? \Shipard\Module\Core\Exchange\Enrich\RowEnrichmentPipeline::create($db, $configRuntime, $resolved->config)
		: null;

	$ctrl = new AnalysisController(
		$db, $resolved->config, $dsPath, $tables, $documentRegistry,
		$schemaValidator, $applier, $configRuntime, $documentEventDispatcher,
		$enricher,
	);
	return match ($route->action) {
		'queue'             => $ctrl->queue($auth, $request),
		'claim'             => $ctrl->claim($auth, $request, (int) $route->id),
		'payload'           => $ctrl->payload($auth, $request, (int) $route->id),
		'attachmentContent' => $ctrl->attachmentContent($auth, $request, (int) $route->id, (int) $route->secondaryId),
		'result'            => $ctrl->result($auth, $request, (int) $route->id),
		'failed'            => $ctrl->failed($auth, $request, (int) $route->id),
		'reanalyze'         => $ctrl->reanalyze($auth, $request, (int) $route->id),
		'applyMessage'      => $ctrl->applyMessage($auth, $request, (int) $route->id),
		'unapplyMessage'    => $ctrl->unapplyMessage($auth, $request, (int) $route->id),
		'rejectMessage'     => $ctrl->rejectMessage($auth, $request, (int) $route->id),
		'previewMessage'    => $ctrl->previewMessage($auth, $request, (int) $route->id),
		'saveDecisions'     => $ctrl->saveDecisions($auth, $request, (int) $route->id),
		default             => Response::error('INTERNAL_ERROR', "Unknown analysis action: {$route->action}", 500),
	};
}

function dispatchAuth(
	string $action,
	Request $request,
	AuthContext $auth,
	\Shipard\Core\Database\DataSourceConnection $db,
	\Shipard\Api\ResolvedDataSource $resolved,
): Response {
	if (str_starts_with($action, 'oidc')) {
		$oidc = new \Shipard\Api\Controller\OidcController($resolved->config, $resolved->isDevMode());
		return match ($action) {
			'oidcStart'    => $oidc->start($request, $db),
			'oidcCallback' => $oidc->callback($request, $db),
			'oidcExchange' => $oidc->exchange($request, $db),
			default        => Response::error('INTERNAL_ERROR', "Unknown auth action: {$action}", 500),
		};
	}

	$ctrl = new AuthController();
	return match ($action) {
		'login'   => $ctrl->login($request, $db, $resolved->config->getAuthPolicy()),
		'refresh' => $ctrl->refresh($request, $auth, $db),
		'logout'  => $ctrl->logout($request, $auth, $db),
		default   => Response::error('INTERNAL_ERROR', "Unknown auth action: {$action}", 500),
	};
}

function dispatchPassword(
	Route $route,
	Request $request,
	AuthContext $auth,
	\Shipard\Core\Database\DataSourceConnection $db,
	\Shipard\Api\ResolvedDataSource $resolved,
): Response {
	$ctrl = new \Shipard\Api\Controller\PasswordController($resolved->config, $resolved->isDevMode());
	return match ($route->action) {
		'forgot'               => $ctrl->forgot($request, $db),
		'reset'                => $ctrl->reset($request, $db),
		'change'               => $ctrl->change($request, $auth, $db),
		'invite'               => $ctrl->invite($request, $auth, $db, (int) $route->id),
		'sessions'             => $ctrl->sessions($request, $auth, $db),
		'sessionDelete'        => $ctrl->sessionDelete($request, $auth, $db, (int) $route->id),
		'sessionsRevokeOthers' => $ctrl->sessionsRevokeOthers($request, $auth, $db),
		default                => Response::error('INTERNAL_ERROR', "Unknown password action: {$route->action}", 500),
	};
}

function dispatchAttachment(
	Route $route,
	Request $request,
	AuthContext $auth,
	array $tables,
	\Shipard\Core\Database\DataSourceConnection $db,
	\Shipard\Api\ResolvedDataSource $resolved,
	ModulePathResolver $modulePathResolver,
): Response {
	$dsPath = $resolved->config->getDataSourceDir();
	// Guardy příloh (#55 X16) — chrání soubory vázané na nevratný stav
	// záznamu (podané tvrzení DPH). Jinde než v API se nenačítají: CLI
	// a seedery s přílohami pracují záměrně bez omezení.
	$guards = \Shipard\Api\AttachmentGuardLoader::load($resolved->config, $modulePathResolver);
	$ctrl   = new AttachmentController($db, $dsPath, $tables, $guards);
	return match ($route->action) {
		'upload'    => $ctrl->upload($auth),
		'download'  => $ctrl->download((int) $route->id, $request),
		'thumbnail' => $ctrl->thumbnail((int) $route->id, $request),
		'list'      => $ctrl->list($request),
		'patch'     => $ctrl->patch((int) $route->id, $request),
		'delete'    => $ctrl->delete((int) $route->id),
		'restore'   => $ctrl->restore((int) $route->id),
		default     => Response::error('INTERNAL_ERROR', "Unknown attachment action: {$route->action}", 500),
	};
}

function dispatchChat(
	Route $route,
	Request $request,
	AuthContext $auth,
	\Shipard\Core\Database\DataSourceConnection $db,
	array $tables = [],
	?\Shipard\Core\Config\ConfigRuntime $configRuntime = null,
	?\Shipard\Api\ResolvedDataSource $resolved = null,
	?\Shipard\Core\Document\DocumentRegistry $documentRegistry = null,
	?string $language = null,
	?\Shipard\Core\Alerts\AlertCheckRegistry $alertCheckRegistry = null,
): Response {
	$registry = $resolved !== null
		? buildMcpRegistry($db, $tables, $configRuntime, $resolved, $documentRegistry ?? new \Shipard\Core\Document\DocumentRegistry(), $language, $alertCheckRegistry)
		: null;
	$ctrl = new ChatController($db, $configRuntime, $resolved?->config, new AnthropicLlmClient(), $tables, $registry);
	return match ($route->action) {
		'list'        => $ctrl->list($auth, $request),
		'create'      => $ctrl->create($auth, $request),
		'show'        => $ctrl->show($auth, (int) $route->id),
		'rename'      => $ctrl->rename($auth, (int) $route->id, $request),
		'delete'      => $ctrl->delete($auth, (int) $route->id),
		'sendMessage' => $ctrl->sendMessage($auth, (int) $route->id, $request),
		default       => Response::error('INTERNAL_ERROR', "Unknown chat action: {$route->action}", 500),
	};
}

function dispatchCrud(
	Route $route,
	Request $request,
	AuthContext $auth,
	array $tables,
	\Shipard\Core\Database\DataSourceConnection $db,
	?\Shipard\Core\Config\ConfigRuntime $configRuntime = null,
	?\Shipard\Core\Document\DocumentRegistry $documentRegistry = null,
): Response {
	$ctrl  = new CrudController($db, $tables, $configRuntime, $auth, $documentRegistry);
	$table = $route->table ?? '';
	$id    = $route->id;
	return match ($route->action) {
		'list'            => $ctrl->list($table, $request),
		'show'            => $ctrl->show($table, (int) $id, $request),
		'create'          => $ctrl->create($table, $request),
		'update'          => $ctrl->update($table, (int) $id, $request),
		'patch'           => $ctrl->patch($table, (int) $id, $request),
		'delete'          => $ctrl->delete($table, (int) $id),
		'docStateOptions' => $ctrl->docStateOptions($table, (int) $id),
		default           => Response::error('INTERNAL_ERROR', "Unknown CRUD action: {$route->action}", 500),
	};
}

function dispatchMeta(string $action, ?string $tableName, array $tables, string $language): Response
{
	$ctrl = new MetaController();
	return match ($action) {
		'tables' => $ctrl->tables($tables, $language),
		'table'  => $ctrl->table((string) $tableName, $tables, $language),
		default  => Response::error('INTERNAL_ERROR', "Unknown meta action: {$action}", 500),
	};
}

function dispatchUi(
	string $action,
	\Shipard\Core\Config\DataSourceConfig $config,
	ModulePathResolver $modulePathResolver,
	string $language,
	?\Shipard\Core\Config\ConfigRuntime $configRuntime = null,
	?\Shipard\Core\Database\DataSourceConnection $db = null,
	?AuthContext $auth = null,
	array $tables = [],
	bool $readOnly = false,
): Response {
	$ctrl = new NavigationController();
	return match ($action) {
		// $db jen pro app navigaci — navigation providery (data-driven položky);
		// settings/account navigace jde přes SettingsController bez providerů.
		// $auth + $tables: ne-adminovi se strom ořezává (adminOnly, D4).
		'navigation' => $ctrl->navigation($config, $modulePathResolver, $language, $configRuntime, $db, $auth, $tables, $readOnly),
		default      => Response::error('INTERNAL_ERROR', "Unknown UI action: {$action}", 500),
	};
}

function dispatchDashboard(
	Route $route,
	\Shipard\Core\Database\DataSourceConnection $db,
	?\Shipard\Core\Config\ConfigRuntime $configRuntime,
	string $language,
	?\Shipard\Core\Config\DataSourceConfig $dsConfig = null,
	?\Shipard\Core\Alerts\AlertCheckRegistry $alertCheckRegistry = null,
	array $tables = [],
	?AuthContext $auth = null,
	?Request $request = null,
	bool $readOnly = false,
): Response {
	$ctrl = new DashboardController();
	// Sekční filtr karet (?section=, UI shells Fáze 5) — jen akce index.
	$section = $request !== null ? ($request->getQueryParams()['section'] ?? null) : null;
	return match ($route->action) {
		'index'         => $ctrl->dashboard($db, $configRuntime, $language, $alertCheckRegistry, $tables, $auth, $section !== null ? (string) $section : null, $readOnly),
		'sectionBadges' => $ctrl->sectionBadges($db, $configRuntime, $language, $alertCheckRegistry, $tables),
		'summary' => $ctrl->summary(
			$db,
			new \Shipard\Core\Dashboard\DashboardSummaryService(
				$db,
				new AnthropicLlmClient(),
				new \Shipard\Core\Ai\AiBackendResolver($db, $dsConfig),
			),
			$configRuntime,
			$language,
			$alertCheckRegistry,
			$tables,
		),
		default   => Response::error('INTERNAL_ERROR', "Unknown dashboard action: {$route->action}", 500),
	};
}

function dispatchSettings(
	Route $route,
	Request $request,
	AuthContext $auth,
	\Shipard\Core\Config\DataSourceConfig $config,
	ModulePathResolver $modulePathResolver,
	string $language,
	?\Shipard\Core\Config\ConfigRuntime $configRuntime,
	\Shipard\Core\Database\DataSourceConnection $db,
	array $tables = [],
): Response {
	$ctrl = new SettingsController();
	return match ($route->action) {
		'navigation'        => $ctrl->navigation($config, $modulePathResolver, $language, $configRuntime, 'settings', $auth, $tables, $db),
		'accountNavigation' => $ctrl->navigation($config, $modulePathResolver, $language, $configRuntime, 'account', $auth, $tables, $db),
		'page'              => $ctrl->page((string) $route->table, $config, $modulePathResolver, $language, $auth, $db),
		'savePage'          => $ctrl->savePage((string) $route->table, $request, $config, $modulePathResolver, $auth, $db),
		default             => Response::error('INTERNAL_ERROR', "Unknown settings action: {$route->action}", 500),
	};
}

/**
 * @param array<string, \Shipard\Core\Database\TableDefinition> $tables
 */
function dispatchApp(
	Route $route,
	AuthContext $auth,
	\Shipard\Core\Database\DataSourceConnection $db,
	\Shipard\Core\Config\DataSourceConfig $config,
	array $tables = [],
	bool $devMode = false,
	string $dsState = \Shipard\Core\Config\DataSourceState::ACTIVE,
): Response {
	$ctrl = new \Shipard\Api\Controller\AppController($db, $config, $tables);
	$slot = (string) $route->table;
	return match ($route->action) {
		'info'           => $ctrl->info($dsState),
		'manifest'       => $ctrl->manifest($devMode),
		'brandingGet'    => $ctrl->brandingGet($slot),
		'brandingUpload' => $ctrl->brandingUpload($slot, $auth),
		'brandingDelete' => $ctrl->brandingDelete($slot, $auth),
		'avatarGet'      => $ctrl->avatarGet($auth),
		'avatarUpload'   => $ctrl->avatarUpload($auth),
		'avatarDelete'   => $ctrl->avatarDelete($auth),
		default          => Response::error('INTERNAL_ERROR', "Unknown app action: {$route->action}", 500),
	};
}

function dispatchViewer(
	Route $route,
	Request $request,
	AuthContext $auth,
	ViewerRegistry $registry,
	array $tables,
	\Shipard\Core\Database\DataSourceConnection $db,
	?\Shipard\Core\Config\ConfigRuntime $config = null,
	string $language = 'en',
	?\Shipard\Core\Document\DocumentRegistry $documentRegistry = null,
	?\Shipard\Core\Config\DataSourceConfig $dsConfig = null,
): Response {
	$ctrl     = new ViewerController();
	$viewerId = $route->table ?? '';
	return match ($route->action) {
		'meta'   => $ctrl->meta($viewerId, $auth, $registry, $tables, $db, $config, $language),
		'rows'   => $ctrl->rows($viewerId, $request, $auth, $registry, $tables, $db, $config, $language),
		'detail' => $ctrl->detail($viewerId, (int) $route->id, $auth, $registry, $tables, $db, $config, $language, $documentRegistry, $dsConfig),
		default  => Response::error('INTERNAL_ERROR', "Unknown viewer action: {$route->action}", 500),
	};
}

function dispatchForm(
	Route $route,
	Request $request,
	AuthContext $auth,
	array $tables,
	\Shipard\Core\Database\DataSourceConnection $db,
	FormRegistry $formRegistry,
	?\Shipard\Core\Config\ConfigRuntime $configRuntime,
	ModulePathResolver $modulePathResolver,
	?\Shipard\Core\Document\DocumentRegistry $documentRegistry = null,
	string $language = 'en',
	?\Shipard\Core\Config\DataSourceConfig $dsConfig = null,
	?LookupRegistry $lookupRegistry = null,
	?\Shipard\Core\Document\DocumentEventDispatcher $eventDispatcher = null,
): Response {
	$ctrl  = new FormController();
	$table = $route->table ?? '';

	// New-record prefill from per-type viewer (e.g. doc_type=invno) arrives
	// as ?defaults[<key>]=<value>. Only forwarded to meta — save/recalculate
	// receive the merged data via JSON body.
	$queryDefaults = [];
	if ($route->action === 'meta' && $route->id === null) {
		$qp = $request->getQueryParams();
		if (isset($qp['defaults']) && is_array($qp['defaults'])) {
			foreach ($qp['defaults'] as $k => $v) {
				if (is_string($k) && $k !== '' && (is_string($v) || is_numeric($v) || is_bool($v))) {
					$queryDefaults[$k] = $v;
				}
			}
		}
	}

	$lookupReg = $lookupRegistry ?? new LookupRegistry();
	return match ($route->action) {
		'meta'        => $ctrl->meta($table, $route->id, $tables, $db, $formRegistry, $configRuntime, $lookupReg, $modulePathResolver, $language, $queryDefaults, $auth, $documentRegistry),
		'save'        => $ctrl->save($table, $route->id, $request, $tables, $db, $configRuntime, $formRegistry, $modulePathResolver, $lookupReg, $language, $documentRegistry, $dsConfig, $auth, $eventDispatcher),
		'recalculate' => $ctrl->recalculate($table, $request, $tables, $db, $formRegistry, $configRuntime, $lookupReg, $modulePathResolver, $language, $auth, $documentRegistry),
		'subtable'     => $ctrl->subtable($table, $route->key, $route->id, $tables, $db, $formRegistry, $configRuntime, $auth),
		'subtableMove' => $ctrl->subtableMove($table, $route->key, $route->id, $request, $tables, $db, $formRegistry, $configRuntime, $auth),
		default       => Response::error('INTERNAL_ERROR', "Unknown form action: {$route->action}", 500),
	};
}

function dispatchLookup(
	Route $route,
	Request $request,
	AuthContext $auth,
	array $tables,
	\Shipard\Core\Database\DataSourceConnection $db,
	LookupRegistry $lookupRegistry,
	?\Shipard\Core\Config\ConfigRuntime $configRuntime,
): Response {
	$ctrl  = new \Shipard\Api\Controller\LookupController();
	$table = $route->table ?? '';
	return match ($route->action) {
		'search'  => $ctrl->search($table, $request, $auth, $tables, $db, $lookupRegistry, $configRuntime),
		'resolve' => $ctrl->resolve($table, $request, $auth, $tables, $db, $lookupRegistry, $configRuntime),
		default   => Response::error('INTERNAL_ERROR', "Unknown lookup action: {$route->action}", 500),
	};
}

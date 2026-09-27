<?php
declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\AuthContext;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Api\TableAccessGuard;
use Shipard\Api\Validation\InputValidator;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\ColumnDefinition;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\SearchCondition;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Document\DocumentLockReason;
use Shipard\Core\Document\DocumentLockRegistry;

class CrudController
{
	private const VALID_OPERATORS = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'like', 'in', 'null', 'notnull'];
	private const DEFAULT_LIMIT   = 20;
	private const MAX_LIMIT       = 100;

	private InputValidator $validator;

	/**
	 * @param  array<string, TableDefinition>  $tables  Indexed by table name (e.g. 'core_system_users')
	 */
	public function __construct(
		protected DataSourceConnection $db,
		private array $tables,
		private ?ConfigRuntime $config = null,
		private AuthContext $auth = new AuthContext(false),
		private ?\Shipard\Core\Document\DocumentRegistry $documentRegistry = null,
	) {
		$this->validator = new InputValidator();
	}

	/**
	 * Resolves the table definition or fails: 404 for unknown tables,
	 * 403 for core_system_* or adminOnly tables accessed by a non-admin.
	 */
	private function resolveTable(string $table): TableDefinition|Response
	{
		$def = $this->tables[$table] ?? null;
		if ($def === null) {
			return Response::error('TABLE_NOT_FOUND', "Table '{$table}' not found", 404);
		}
		return TableAccessGuard::guardTable($table, $this->auth, $def) ?? $def;
	}

	// -------------------------------------------------------------------------
	// Public CRUD methods
	// -------------------------------------------------------------------------

	public function list(string $table, Request $request): Response
	{
		$def = $this->resolveTable($table);
		if ($def instanceof Response) {
			return $def;
		}

		$params = $request->getQueryParams();

		// Parse limit / offset
		$limit = min((int) ($params['limit'] ?? self::DEFAULT_LIMIT), self::MAX_LIMIT);
		$limit = max(1, $limit);
		$offset = max(0, (int) ($params['offset'] ?? 0));

		// Parse fields
		$fieldsResult = $this->parseFields($params['fields'] ?? null, $def);
		if ($fieldsResult instanceof Response) {
			return $fieldsResult;
		}

		// Parse filters
		$filtersResult = $this->parseFilters($params['filter'] ?? [], $def);
		if ($filtersResult instanceof Response) {
			return $filtersResult;
		}

		// Parse sort
		$sortsResult = $this->parseSorts($params['sort'] ?? null, $def);
		if ($sortsResult instanceof Response) {
			return $sortsResult;
		}

		$rows  = $this->fetchList($table, $fieldsResult, $filtersResult, $sortsResult, $limit, $offset);
		$total = $this->countList($table, $filtersResult);

		$output = array_map(fn(array $row) => $this->castRow($row, $def), $rows);

		return Response::success($output, 200, ['total' => $total, 'limit' => $limit, 'offset' => $offset]);
	}

	public function show(string $table, int $id, Request $request): Response
	{
		$def = $this->resolveTable($table);
		if ($def instanceof Response) {
			return $def;
		}

		$params = $request->getQueryParams();
		$fieldsResult = $this->parseFields($params['fields'] ?? null, $def);
		if ($fieldsResult instanceof Response) {
			return $fieldsResult;
		}

		$row = $this->fetchById($table, $id, $fieldsResult);
		if ($row === null) {
			return Response::error('NOT_FOUND', 'Record not found', 404);
		}

		return Response::success($this->castRow($row, $def));
	}

	public function create(string $table, Request $request): Response
	{
		$def = $this->resolveTable($table);
		if ($def instanceof Response) {
			return $def;
		}

		$body = $request->getBody();
		if ($body === null) {
			return Response::error('BAD_REQUEST', 'Request body must be a JSON object', 400);
		}

		$sensitiveErr = TableAccessGuard::rejectSensitiveInput($body, $def);
		if ($sensitiveErr !== null) {
			return $sensitiveErr;
		}

		// Strukturovaná pole (#74) jdou jen přes gateway — viz guard.
		$structuredErr = TableAccessGuard::rejectStructuredInput($body, $def);
		if ($structuredErr !== null) {
			return $structuredErr;
		}

		$errors = $this->validator->validate($body, $def, 'create', $this->config);
		if ($errors !== []) {
			return Response::error('VALIDATION_ERROR', 'Validation failed', 422, $errors);
		}

		$data = $this->filterWritableFields($body, $def);
		$now = date('Y-m-d H:i:s');
		if ($this->tableHasColumn($def, 'created')) {
			$data['created'] = $now;
		}
		if ($this->tableHasColumn($def, 'modified')) {
			$data['modified'] = $now;
		}

		// Set docState + docStateMain for new records
		$this->initDocState($body, $def, $data);

		$newId = $this->insertRecord($table, $data);

		$row = $this->fetchById($table, $newId, $this->allReadableColumns($def));
		if ($row === null) {
			return Response::error('INTERNAL_ERROR', 'Failed to fetch created record', 500);
		}

		return Response::success($this->castRow($row, $def), 201);
	}

	public function update(string $table, int $id, Request $request): Response
	{
		$def = $this->resolveTable($table);
		if ($def instanceof Response) {
			return $def;
		}

		$existing = $this->fetchById($table, $id, ['id']);
		if ($existing === null) {
			return Response::error('NOT_FOUND', 'Record not found', 404);
		}

		$body = $request->getBody();
		if ($body === null) {
			return Response::error('BAD_REQUEST', 'Request body must be a JSON object', 400);
		}

		$sensitiveErr = TableAccessGuard::rejectSensitiveInput($body, $def);
		if ($sensitiveErr !== null) {
			return $sensitiveErr;
		}

		// Strukturovaná pole (#74) jdou jen přes gateway — viz guard.
		$structuredErr = TableAccessGuard::rejectStructuredInput($body, $def);
		if ($structuredErr !== null) {
			return $structuredErr;
		}

		$errors = $this->validator->validate($body, $def, 'create', $this->config);
		if ($errors !== []) {
			return Response::error('VALIDATION_ERROR', 'Validation failed', 422, $errors);
		}

		$data = $this->filterWritableFields($body, $def);

		// Validate and apply doc state transition
		$stateErr = $this->processDocState($table, $id, $body, $def, $data);
		if ($stateErr !== null) {
			return $stateErr;
		}

		$lockErr = $this->guardLock($table, $def, $id, $data);
		if ($lockErr !== null) {
			return $lockErr;
		}

		if ($this->tableHasColumn($def, 'modified')) {
			$data['modified'] = date('Y-m-d H:i:s');
		}

		$this->updateRecord($table, $id, $data);

		$row = $this->fetchById($table, $id, $this->allReadableColumns($def));
		return Response::success($this->castRow($row ?? [], $def));
	}

	public function patch(string $table, int $id, Request $request): Response
	{
		$def = $this->resolveTable($table);
		if ($def instanceof Response) {
			return $def;
		}

		$existing = $this->fetchById($table, $id, ['id']);
		if ($existing === null) {
			return Response::error('NOT_FOUND', 'Record not found', 404);
		}

		$body = $request->getBody();
		if ($body === null) {
			return Response::error('BAD_REQUEST', 'Request body must be a JSON object', 400);
		}

		$sensitiveErr = TableAccessGuard::rejectSensitiveInput($body, $def);
		if ($sensitiveErr !== null) {
			return $sensitiveErr;
		}

		// Strukturovaná pole (#74) jdou jen přes gateway — viz guard.
		$structuredErr = TableAccessGuard::rejectStructuredInput($body, $def);
		if ($structuredErr !== null) {
			return $structuredErr;
		}

		$errors = $this->validator->validate($body, $def, 'patch', $this->config);
		if ($errors !== []) {
			return Response::error('VALIDATION_ERROR', 'Validation failed', 422, $errors);
		}

		$data = $this->filterWritableFields($body, $def);

		// Validate and apply doc state transition
		$stateErr = $this->processDocState($table, $id, $body, $def, $data);
		if ($stateErr !== null) {
			return $stateErr;
		}

		if ($data === []) {
			// Nothing to update; just return current state
			$row = $this->fetchById($table, $id, $this->allReadableColumns($def));
			return Response::success($this->castRow($row ?? [], $def));
		}

		$lockErr = $this->guardLock($table, $def, $id, $data);
		if ($lockErr !== null) {
			return $lockErr;
		}

		if ($this->tableHasColumn($def, 'modified')) {
			$data['modified'] = date('Y-m-d H:i:s');
		}

		$this->updateRecord($table, $id, $data);

		$row = $this->fetchById($table, $id, $this->allReadableColumns($def));
		return Response::success($this->castRow($row ?? [], $def));
	}

	public function delete(string $table, int $id): Response
	{
		$def = $this->resolveTable($table);
		if ($def instanceof Response) {
			return $def;
		}

		$existing = $this->fetchById($table, $id, ['id']);
		if ($existing === null) {
			return Response::error('NOT_FOUND', 'Record not found', 404);
		}

		$lockErr = $this->guardLock($table, $def, $id, null);
		if ($lockErr !== null) {
			return $lockErr;
		}

		$this->deleteRecord($table, $id);

		return Response::success(null, 204);
	}

	/**
	 * Zámek záznamu (documentLockProviders, #55 D24). Generické CRUD píše
	 * mimo TableGateway (přímý UPDATE/DELETE bez Document lifecycle), takže
	 * bariéru musí mít samo — jinak by zámek obešel `PATCH /{table}/{id}`.
	 * Nový stav = uložený řádek přepsaný patchem (child sety REST nenese,
	 * providery si je dočtou z DB). Fail-closed: výjimka providera = 500.
	 *
	 * @param array<string, mixed>|null $patch null = mazání (stav se nemění)
	 */
	private function guardLock(string $table, TableDefinition $def, int $id, ?array $patch): ?Response
	{
		if ($this->documentRegistry === null || !$this->documentRegistry->hasLockProviders($table)) {
			return null;
		}
		$row = $this->fetchById($table, $id, $this->allReadableColumns($def));
		if ($row === null) {
			return null;
		}
		$reasons = $this->lockRegistry()->reasons($table, $patch === null ? $row : array_merge($row, $patch), $row);
		if ($reasons === []) {
			return null;
		}
		return Response::error(
			DocumentLockRegistry::DOMAIN_CODE,
			DocumentLockRegistry::summarize($reasons),
			422,
			array_map(static fn(DocumentLockReason $r): array => $r->toArray(), $reasons),
		);
	}

	/** Seam pro testy — registry providerů nad DB tohoto controlleru. */
	protected function lockRegistry(): DocumentLockRegistry
	{
		return DocumentLockRegistry::forDocuments(
			$this->documentRegistry,
			$this->db->getDibiConnection(),
			$this->config,
		);
	}

	/**
	 * Returns the available doc state transitions for a given record.
	 * GET /{table}/{id}/doc-state-options
	 */
	public function docStateOptions(string $table, int $id): Response
	{
		$def = $this->resolveTable($table);
		if ($def instanceof Response) {
			return $def;
		}

		$dsDef = $def->docStates;
		if ($dsDef === null) {
			return Response::error('NO_DOC_STATES', "Table '{$table}' does not support document states", 404);
		}

		$stateCol = $dsDef->stateColumn;
		// Celý řádek — filterStateTransitions hook potřebuje víc než stavový
		// sloupec (doc_type pro polymorfní dispatch, číslo/řadu dokladu).
		$row = $this->fetchById($table, $id, $this->allReadableColumns($def));
		if ($row === null) {
			return Response::error('NOT_FOUND', 'Record not found', 404);
		}

		$cfg          = $this->loadDocStateConfig($dsDef->cfgItem);
		$currentState = (int) ($row[$stateCol] ?? 10);
		$stateData    = $cfg->getState($currentState);

		$transitions = $cfg->getAvailableTransitions($currentState);
		if ($this->documentRegistry !== null) {
			$transitions = \Shipard\Core\Document\DocStateTransitionFilter::apply(
				$table,
				$row,
				$transitions,
				$this->documentRegistry,
				$this->db->getDibiConnection(),
				$this->config,
			);
		}

		return Response::success([
			'currentState' => $currentState,
			'stateName'    => $stateData['stateName']  ?? '',
			'stateStyle'   => $stateData['stateStyle']  ?? '',
			'readOnly'     => $cfg->isReadOnly($currentState),
			'transitions'  => $transitions,
		]);
	}

	// -------------------------------------------------------------------------
	// Doc state helpers (private)
	// -------------------------------------------------------------------------

	/**
	 * Sets docState and docStateMain when creating a new record.
	 * Uses docState from $rawBody if provided, otherwise defaults to 10 (Koncept).
	 */
	private function initDocState(array $rawBody, TableDefinition $def, array &$data): void
	{
		$dsDef = $def->docStates;
		if ($dsDef === null || $this->config === null) {
			return;
		}

		$stateCol = $dsDef->stateColumn;
		$mainCol  = $dsDef->mainColumn;
		$cfg      = $this->loadDocStateConfig($dsDef->cfgItem);

		$newState        = isset($rawBody[$stateCol]) ? (int) $rawBody[$stateCol] : 10;
		$data[$stateCol] = $newState;
		$data[$mainCol]  = $cfg->getMainState($newState);
	}

	/**
	 * Validates and applies a doc state transition on update/patch.
	 *
	 * Rules:
	 * - If current state is readOnly and body contains non-system fields → error
	 * - If docState is in the body, validate that the transition is in goto[] → error if not
	 * - On valid transition: set docState + docStateMain in $data
	 *
	 * Returns a Response on error, null on success.
	 */
	private function processDocState(
		string $table,
		int $id,
		array $rawBody,
		TableDefinition $def,
		array &$data,
	): ?Response {
		$dsDef = $def->docStates;
		if ($dsDef === null || $this->config === null) {
			return null;
		}

		$stateCol = $dsDef->stateColumn;
		$mainCol  = $dsDef->mainColumn;
		$cfg      = $this->loadDocStateConfig($dsDef->cfgItem);

		// Load current state from DB
		$currentRow   = $this->fetchById($table, $id, [$stateCol]);
		$currentState = (int) ($currentRow[$stateCol] ?? 10);
		$isReadOnly   = $cfg->isReadOnly($currentState);

		$hasStateChange = isset($rawBody[$stateCol]);

		// readOnly + non-system fields being written → refuse
		if ($isReadOnly && $data !== []) {
			return Response::error(
				'DOCUMENT_READONLY',
				"Document is read-only in state {$currentState}. Switch to edit state first (e.g. V opravě).",
				422,
			);
		}

		if ($hasStateChange) {
			$newState = (int) $rawBody[$stateCol];

			if (!$cfg->isTransitionAllowed($currentState, $newState)) {
				return Response::error(
					'INVALID_STATE_TRANSITION',
					"Transition from state {$currentState} to {$newState} is not allowed.",
					422,
				);
			}

			$data[$stateCol] = $newState;
			$data[$mainCol]  = $cfg->getMainState($newState);
		}

		return null;
	}

	/** Loads and wraps the docStates cfgItem from ConfigRuntime. */
	private function loadDocStateConfig(string $cfgItemId): DocStateConfig
	{
		return DocStateConfig::fromCfgItem(
			$this->config !== null ? $this->config->cfgItem($cfgItemId) : null,
		);
	}

	// -------------------------------------------------------------------------
	// Protected DB methods (overridable for testing)
	// -------------------------------------------------------------------------

	/**
	 * @param  array<array{column:string,operator:string,value:string}>  $filters
	 * @param  array<array{column:string,direction:string}>               $sorts
	 * @return array<array<string,mixed>>
	 */
	protected function fetchList(string $tableName, array $columns, array $filters, array $sorts, int $limit, int $offset): array
	{
		[$sql, $params] = $this->buildSelectSql($tableName, $columns, $filters, $sorts, $limit, $offset);
		return $this->db->fetchAll($sql, ...$params);
	}

	/** @param array<array{column:string,operator:string,value:string}> $filters */
	protected function countList(string $tableName, array $filters): int
	{
		[$whereSql, $whereParams] = $this->buildWhereSql($filters);
		$sql = "SELECT COUNT(*) FROM `{$tableName}`" . $whereSql;
		$row = $this->db->fetchRow($sql, ...$whereParams);
		return (int) array_values($row ?? [0])[0];
	}

	/** @param string[] $columns */
	protected function fetchById(string $tableName, int $id, array $columns): ?array
	{
		$cols = implode(', ', array_map(fn($c) => "`{$c}`", $columns));
		return $this->db->fetchRow("SELECT {$cols} FROM `{$tableName}` WHERE id = %i", $id);
	}

	protected function insertRecord(string $tableName, array $data): int
	{
		return $this->db->insertRow($tableName, $data);
	}

	protected function updateRecord(string $tableName, int $id, array $data): void
	{
		$this->db->updateWhere($tableName, $data, 'id = %i', $id);
	}

	protected function deleteRecord(string $tableName, int $id): void
	{
		$this->db->deleteWhere($tableName, 'id = %i', $id);
	}

	// -------------------------------------------------------------------------
	// Query builders (private)
	// -------------------------------------------------------------------------

	/** @return array{0:string, 1:list<mixed>} */
	private function buildSelectSql(string $tableName, array $columns, array $filters, array $sorts, int $limit, int $offset): array
	{
		$cols = implode(', ', array_map(fn($c) => "`{$c}`", $columns));
		$sql = "SELECT {$cols} FROM `{$tableName}`";

		[$whereSql, $whereParams] = $this->buildWhereSql($filters);
		$sql .= $whereSql;

		if ($sorts !== []) {
			$orderParts = array_map(fn($s) => "`{$s['column']}` " . strtoupper($s['direction']), $sorts);
			$sql .= ' ORDER BY ' . implode(', ', $orderParts);
		} else {
			$sql .= ' ORDER BY `id` ASC';
		}

		$sql .= ' LIMIT %i OFFSET %i';
		return [$sql, [...$whereParams, $limit, $offset]];
	}

	/** @return array{0:string, 1:list<mixed>} */
	private function buildWhereSql(array $filters): array
	{
		if ($filters === []) {
			return ['', []];
		}

		$parts  = [];
		$params = [];

		foreach ($filters as $f) {
			[$fSql, $fParams] = $this->buildFilterFragment($f);
			$parts[]  = $fSql;
			$params   = array_merge($params, $fParams);
		}

		return [' WHERE ' . implode(' AND ', $parts), $params];
	}

	/** @return array{0:string, 1:list<mixed>} */
	private function buildFilterFragment(array $filter): array
	{
		$col = '`' . $filter['column'] . '`';
		$v   = $filter['value'];

		return match ($filter['operator']) {
			'eq'      => ["{$col} = %s", [$v]],
			'neq'     => ["{$col} != %s", [$v]],
			'gt'      => ["{$col} > %s", [$v]],
			'gte'     => ["{$col} >= %s", [$v]],
			'lt'      => ["{$col} < %s", [$v]],
			'lte'     => ["{$col} <= %s", [$v]],
			'like'    => [SearchCondition::contains($col), [$v]],
			'in'      => $this->buildInFragment($col, $v),
			'null'    => $v === 'true' ? ["{$col} IS NULL", []] : ["{$col} IS NOT NULL", []],
			'notnull' => ["{$col} IS NOT NULL", []],
			default   => ["{$col} = %s", [$v]],
		};
	}

	/** @return array{0:string, 1:list<mixed>} */
	private function buildInFragment(string $col, string $value): array
	{
		$values       = array_map('trim', explode(',', $value));
		$placeholders = implode(', ', array_fill(0, count($values), '%s'));
		return ["{$col} IN ({$placeholders})", $values];
	}

	// -------------------------------------------------------------------------
	// Parsing helpers
	// -------------------------------------------------------------------------

	/** @return string[]|Response */
	private function parseFields(?string $fieldsParam, TableDefinition $def): array|Response
	{
		$readable = $this->allReadableColumns($def);

		if ($fieldsParam === null || $fieldsParam === '') {
			return $readable;
		}

		$requested = array_map('trim', explode(',', $fieldsParam));
		$valid      = array_flip($readable);
		$result     = ['id']; // id is always included

		foreach ($requested as $f) {
			if ($f === '' || $f === 'id') {
				continue;
			}
			if (!isset($valid[$f])) {
				return Response::error('BAD_REQUEST', "Unknown field: '{$f}'", 400);
			}
			$result[] = $f;
		}

		return array_values(array_unique($result));
	}

	/** @return array<array{column:string,operator:string,value:string}>|Response */
	private function parseFilters(mixed $filterParam, TableDefinition $def): array|Response
	{
		if (!is_array($filterParam) || $filterParam === []) {
			return [];
		}

		$colMap  = [];
		foreach ($def->columns as $col) {
			$colMap[$col->id] = $col;
		}

		$filters = [];
		foreach ($filterParam as $column => $spec) {
			$column = (string) $column;
			if (!isset($colMap[$column])) {
				return Response::error('BAD_REQUEST', "Unknown filter column: '{$column}'", 400);
			}

			// Filtr nad sensitive sloupcem = orákulum na extrakci hodnoty
			// (like/gt po znacích), i když sloupec není v SELECT.
			if ($colMap[$column]->sensitive) {
				return Response::error('SENSITIVE_COLUMN', "Column '{$column}' cannot be filtered", 400);
			}

			$colonPos = strpos((string) $spec, ':');
			if ($colonPos === false) {
				return Response::error('BAD_REQUEST', "Invalid filter format for '{$column}': expected 'operator:value'", 400);
			}

			$operator = substr((string) $spec, 0, $colonPos);
			$value    = substr((string) $spec, $colonPos + 1);

			if (!in_array($operator, self::VALID_OPERATORS, true)) {
				return Response::error('BAD_REQUEST', "Invalid filter operator: '{$operator}'", 400);
			}

			$filters[] = ['column' => $column, 'operator' => $operator, 'value' => $value];
		}

		return $filters;
	}

	/** @return array<array{column:string,direction:string}>|Response */
	private function parseSorts(?string $sortParam, TableDefinition $def): array|Response
	{
		if ($sortParam === null || $sortParam === '') {
			return [];
		}

		$colMap = [];
		foreach ($def->columns as $col) {
			$colMap[$col->id] = $col;
		}

		$sorts = [];
		foreach (array_map('trim', explode(',', $sortParam)) as $part) {
			if ($part === '') {
				continue;
			}
			$pieces    = explode(':', $part, 2);
			$column    = $pieces[0];
			$direction = strtolower($pieces[1] ?? 'asc');

			if (!isset($colMap[$column])) {
				return Response::error('BAD_REQUEST', "Unknown sort column: '{$column}'", 400);
			}
			if ($colMap[$column]->sensitive) {
				return Response::error('SENSITIVE_COLUMN', "Column '{$column}' cannot be sorted by", 400);
			}
			if ($direction !== 'asc' && $direction !== 'desc') {
				return Response::error('BAD_REQUEST', "Invalid sort direction: '{$direction}'", 400);
			}

			$sorts[] = ['column' => $column, 'direction' => $direction];
		}

		return $sorts;
	}

	// -------------------------------------------------------------------------
	// Type casting & output helpers
	// -------------------------------------------------------------------------

	private function castRow(array $row, TableDefinition $def): array
	{
		$colMap = [];
		foreach ($def->columns as $col) {
			$colMap[$col->id] = $col;
		}

		$result = [];
		foreach ($row as $key => $value) {
			$key = (string) $key;

			// Skip password-related and sensitive columns
			if (str_contains($key, 'password')
				|| (isset($colMap[$key]) && $colMap[$key]->sensitive)) {
				continue;
			}

			if ($value === null || !isset($colMap[$key])) {
				$result[$key] = $value;
				continue;
			}

			$result[$key] = $this->castValue($value, $colMap[$key]);
		}

		return $result;
	}

	private function castValue(mixed $value, ColumnDefinition $col): mixed
	{
		return match ($col->type) {
			'int', 'smallint', 'bigint', 'tinyint', 'enumInt' => (int) $value,
			'numeric', 'float'                                 => (float) $value,
			'boolean'                                          => (bool) $value,
			'json'                                             => is_string($value) ? json_decode($value, true) : $value,
			'datetime'                                         => $this->castDatetime($value),
			default                                            => $value,
		};
	}

	private function castDatetime(mixed $value): ?string
	{
		if ($value === null || $value === '') {
			return null;
		}
		$ts = is_int($value) ? $value : strtotime((string) $value);
		return $ts !== false ? date('c', $ts) : (string) $value;
	}

	/** @return string[] */
	private function allReadableColumns(TableDefinition $def): array
	{
		$result = [];
		foreach ($def->columns as $col) {
			if (!$col->sensitive && !str_contains($col->id, 'password')) {
				$result[] = $col->id;
			}
		}
		return $result;
	}

	/**
	 * Returns fields from $data that are writable: defined in the table, not auto-managed, not system.
	 * System columns (docState, docStateMain, …) are handled explicitly by initDocState / processDocState.
	 */
	private function filterWritableFields(array $data, TableDefinition $def): array
	{
		$excluded = ['id', 'created', 'modified'];
		$colMap   = [];
		foreach ($def->columns as $col) {
			if (!in_array($col->id, $excluded, true) && !$col->system) {
				$colMap[$col->id] = true;
			}
		}

		$result = [];
		foreach ($data as $k => $v) {
			$k = (string) $k;
			if (isset($colMap[$k])) {
				$result[$k] = $v;
			}
		}
		return $result;
	}

	private function tableHasColumn(TableDefinition $def, string $colId): bool
	{
		foreach ($def->columns as $col) {
			if ($col->id === $colId) {
				return true;
			}
		}
		return false;
	}
}

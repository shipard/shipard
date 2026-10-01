<?php

declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\AuthContext;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Api\TableAccessGuard;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Form\Lookup\LookupRegistry;

class LookupController
{
    private const int MAX_LIMIT = 50;
    private const int DEFAULT_LIMIT = 20;

    /**
     * GET /_ui/lookup/{table}/search?q=...&limit=...&filter[col]=...
     *
     * @param array<string, TableDefinition> $tables
     */
    public function search(
        string $table,
        Request $request,
        AuthContext $auth,
        array $tables,
        DataSourceConnection $db,
        LookupRegistry $lookupRegistry,
        ?ConfigRuntime $config,
    ): Response {
        $def = $tables[$table] ?? null;
        if ($def === null) {
            return Response::error('TABLE_NOT_FOUND', "Table '{$table}' not found", 404);
        }

        $guardErr = TableAccessGuard::guardTable($table, $auth, $def);
        if ($guardErr !== null) {
            return $guardErr;
        }

        $lookup = $lookupRegistry->create($table, $db, $config, $def);
        if ($lookup === null) {
            return Response::error(
                'LOOKUP_NOT_REGISTERED',
                "No TableLookup registered for '{$table}'",
                404,
            );
        }

        $qp = $request->getQueryParams();
        $q  = is_string($qp['q'] ?? null) ? (string) $qp['q'] : '';

        $limitRaw = $qp['limit'] ?? null;
        if ($limitRaw !== null && !is_numeric($limitRaw)) {
            return Response::error('BAD_REQUEST', "Invalid 'limit' parameter", 400);
        }
        $limitInt = (int) ($limitRaw ?? self::DEFAULT_LIMIT);
        if ($limitInt < 1) {
            return Response::error('BAD_REQUEST', "'limit' must be >= 1", 400);
        }
        $limit = min(self::MAX_LIMIT, $limitInt);

        $filterIn = $qp['filter'] ?? [];
        if (!is_array($filterIn)) {
            return Response::error('BAD_REQUEST', "'filter' must be an array", 400);
        }
        $filter = $this->validateFilter($filterIn, $lookup->getAllowedFilterKeys());

        $items = $lookup->search($q, $filter, $limit);

        return Response::success([
            'items' => array_map(fn($i) => $i->toArray(), $items),
            'limit' => $limit,
            'total' => null,
        ]);
    }

    /**
     * GET /_ui/lookup/{table}/resolve?ids=42,17,3
     *
     * @param array<string, TableDefinition> $tables
     */
    public function resolve(
        string $table,
        Request $request,
        AuthContext $auth,
        array $tables,
        DataSourceConnection $db,
        LookupRegistry $lookupRegistry,
        ?ConfigRuntime $config,
    ): Response {
        $def = $tables[$table] ?? null;
        if ($def === null) {
            return Response::error('TABLE_NOT_FOUND', "Table '{$table}' not found", 404);
        }

        $guardErr = TableAccessGuard::guardTable($table, $auth, $def);
        if ($guardErr !== null) {
            return $guardErr;
        }

        $lookup = $lookupRegistry->create($table, $db, $config, $def);
        if ($lookup === null) {
            return Response::error(
                'LOOKUP_NOT_REGISTERED',
                "No TableLookup registered for '{$table}'",
                404,
            );
        }

        $qp = $request->getQueryParams();
        $idsRaw = is_string($qp['ids'] ?? null) ? (string) $qp['ids'] : '';
        $ids = $this->parseIds($idsRaw);
        if ($ids === []) {
            return Response::success(['items' => []]);
        }

        $items = $lookup->resolve($ids);
        return Response::success([
            'items' => array_map(fn($i) => $i->toArray(), $items),
        ]);
    }

    /**
     * POST /_ui/lookup/{table}/create-defaults
     *
     * Tělo `{row: {...}, head: {...}}` = neuložená data formuláře, ve kterém
     * lookup pole je, a jeho rodiče. Vrací `{defaults: {sloupec: hodnota}}`
     * pro prefill nového záznamu — jen skalární hodnoty zapisovatelných
     * sloupců cílové tabulky. Nic nezapisuje (v read-only DS povoleno).
     *
     * @param array<string, TableDefinition> $tables
     */
    public function createDefaults(
        string $table,
        Request $request,
        AuthContext $auth,
        array $tables,
        DataSourceConnection $db,
        LookupRegistry $lookupRegistry,
        ?ConfigRuntime $config,
    ): Response {
        $def = $tables[$table] ?? null;
        if ($def === null) {
            return Response::error('TABLE_NOT_FOUND', "Table '{$table}' not found", 404);
        }

        $guardErr = TableAccessGuard::guardTable($table, $auth, $def);
        if ($guardErr !== null) {
            return $guardErr;
        }

        $lookup = $lookupRegistry->create($table, $db, $config, $def);
        if ($lookup === null) {
            return Response::error(
                'LOOKUP_NOT_REGISTERED',
                "No TableLookup registered for '{$table}'",
                404,
            );
        }

        $body = $request->getBody() ?? [];
        $row = $body['row'] ?? [];
        $head = $body['head'] ?? [];
        if (!is_array($row) || !is_array($head)) {
            return Response::error('BAD_REQUEST', "'row' and 'head' must be objects", 400);
        }

        $writable = [];
        foreach ($def->columns as $column) {
            if (!$column->primaryKey && !$column->system) {
                $writable[$column->id] = true;
            }
        }
        $defaults = [];
        foreach ($lookup->createDefaults($row, $head) as $column => $value) {
            if (isset($writable[$column]) && ($value === null || is_scalar($value))) {
                $defaults[$column] = $value;
            }
        }

        // Prázdná mapa se musí serializovat jako objekt, ne jako pole.
        return Response::success(['defaults' => $defaults === [] ? new \stdClass() : $defaults]);
    }

    /**
     * @param array<int|string, mixed> $filterIn
     * @param list<string> $allowedKeys
     * @return array<string, scalar>
     */
    private function validateFilter(array $filterIn, array $allowedKeys): array
    {
        $result = [];
        $allowed = array_flip($allowedKeys);
        foreach ($filterIn as $k => $v) {
            $key = (string) $k;
            if (!isset($allowed[$key])) {
                continue;
            }
            if (!is_scalar($v)) {
                continue;
            }
            $result[$key] = $v;
        }
        return $result;
    }

    /**
     * @return list<int|string>
     */
    private function parseIds(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        $parts = array_filter(
            array_map('trim', explode(',', $raw)),
            fn($p) => $p !== '',
        );
        return array_values(array_map(
            fn($p) => ctype_digit($p) ? (int) $p : $p,
            $parts,
        ));
    }
}

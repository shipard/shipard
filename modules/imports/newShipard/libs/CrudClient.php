<?php

namespace imports\newShipard\libs;

/**
 * Tenká fasáda nad HttpClient pro generický CRUD nového Shipardu.
 *
 * Konvence endpoint:
 *   GET    /api/v1/{table}        — list (paginated + filtrable)
 *   POST   /api/v1/{table}        — create
 *   GET    /api/v1/{table}/{id}   — show
 *   PATCH  /api/v1/{table}/{id}   — partial update
 *
 * Response shape (viz nov_shipard:src/Api/Response.php):
 *   create:  {success: true, data: {...full row including id...}}
 *   show:    {success: true, data: {...full row...}}
 *   patch:   {success: true, data: {...full row...}}
 *   list:    {success: true, data: [{...}, ...], meta: {total, limit, offset}}
 */
final class CrudClient
{
	public function __construct(private readonly HttpClient $http) {}

	/**
	 * POST /api/v1/{table} → id vytvořeného záznamu.
	 *
	 * @throws HttpException při 4xx/5xx
	 */
	public function create(string $table, array $payload): int
	{
		$resp = $this->http->post('/' . $table, $payload);
		$id = $resp['data']['id'] ?? null;
		if (!is_int($id) && !(is_string($id) && ctype_digit($id)))
			throw new HttpException(0, null, "Create response missing numeric 'data.id': " . json_encode($resp), $resp);
		return (int) $id;
	}

	/**
	 * PATCH /api/v1/{table}/{id} → vrátí celý záznam po update.
	 *
	 * @throws HttpException při 4xx/5xx
	 */
	public function patch(string $table, int $id, array $payload): array
	{
		$resp = $this->http->patch('/' . $table . '/' . $id, $payload);
		return is_array($resp['data'] ?? null) ? $resp['data'] : [];
	}

	/**
	 * GET /api/v1/{table}/{id} → záznam nebo null pokud 404.
	 *
	 * Nehází exception při 404 — vrací null. Ostatní non-2xx házejí.
	 */
	public function show(string $table, int $id): ?array
	{
		try
		{
			$resp = $this->http->get('/' . $table . '/' . $id);
			return is_array($resp['data'] ?? null) ? $resp['data'] : null;
		}
		catch (HttpException $e)
		{
			if ($e->statusCode === 404)
				return null;
			throw $e;
		}
	}

	/**
	 * GET /api/v1/{table}?filter[{column}]=eq:{value}&limit=1 → první match nebo null.
	 *
	 * Helper pro lookup podle business klíče (např. system_code = "service").
	 * Pokud nový Shipard nepodporuje filter pro daný sloupec, vrátí 400.
	 */
	public function findOneBy(string $table, string $column, mixed $value): ?array
	{
		$resp = $this->http->get('/' . $table, [
			"filter[{$column}]" => 'eq:' . (string) $value,
			'limit'             => 1,
		]);
		$rows = $resp['data'] ?? [];
		if (!is_array($rows) || $rows === [])
			return null;
		return is_array($rows[0]) ? $rows[0] : null;
	}
}

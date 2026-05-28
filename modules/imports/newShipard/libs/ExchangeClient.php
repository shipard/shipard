<?php

namespace imports\newShipard\libs;

/**
 * Tenká fasáda nad HttpClient pro exchange flow nového Shipardu — persons,
 * items, docs. Sourozenec `CrudClient`.
 *
 * Endpoint konvence (router v nov_shipard:src/Api/Router.php):
 *   POST /api/v1/_exchange/{flow}/{type}/validate
 *   POST /api/v1/_exchange/{flow}/{type}/preview
 *   POST /api/v1/_exchange/{flow}/{type}/apply
 *
 * Response shape (viz nov_shipard:src/Api/Controller/ExchangeController.php
 * a Common/ApplyResult):
 *
 *   success: HTTP 200, {success: true, data: {canonical, <savedKey>}}
 *   error:   {success: false, error: {code, message, details: {canonical}}}
 *            HTTP 400 (schema_invalid) / 422 (validation_failed,
 *            unresolved_required) / 409 (person_exists, person_id_conflict).
 *
 * `savedKey` je dynamický — pro persons "savedPersonId", pro items
 * "savedItemId", pro docs "savedDocId".
 *
 * HttpException pro non-2xx — runner ho catchuje a má v errorBody přístup
 * k enriched canonical (s `_resolve.issues`) přes `$e->responseBody`.
 */
final class ExchangeClient
{
	public function __construct(private readonly HttpClient $http) {}

	/**
	 * POST /_exchange/{flow}/{type}/apply — uložení s DB writes.
	 *
	 * @return array{canonical: array, savedId: ?int}
	 * @throws HttpException při 4xx/5xx
	 */
	public function apply(string $flow, string $type, array $canonical, string $savedIdKey): array
	{
		return $this->call('apply', $flow, $type, $canonical, $savedIdKey);
	}

	/**
	 * POST /_exchange/{flow}/{type}/preview — validate + resolve, bez DB writes.
	 *
	 * @return array{canonical: array, savedId: ?int}
	 * @throws HttpException při 4xx/5xx
	 */
	public function preview(string $flow, string $type, array $canonical, string $savedIdKey): array
	{
		return $this->call('preview', $flow, $type, $canonical, $savedIdKey);
	}

	/**
	 * POST /_exchange/{flow}/{type}/validate — schema + PHP validator, bez resolve.
	 *
	 * @return array{canonical: array, savedId: ?int}
	 * @throws HttpException při 4xx/5xx
	 */
	public function validate(string $flow, string $type, array $canonical, string $savedIdKey): array
	{
		return $this->call('validate', $flow, $type, $canonical, $savedIdKey);
	}

	/**
	 * @return array{canonical: array, savedId: ?int}
	 */
	private function call(string $action, string $flow, string $type, array $canonical, string $savedIdKey): array
	{
		$path = '/_exchange/' . $flow . '/' . $type . '/' . $action;
		$resp = $this->http->post($path, $canonical);

		$data = is_array($resp['data'] ?? null) ? $resp['data'] : [];
		$returnedCanonical = is_array($data['canonical'] ?? null) ? $data['canonical'] : [];

		$savedId = $data[$savedIdKey] ?? null;
		if (!is_int($savedId) && !(is_string($savedId) && ctype_digit($savedId)))
			$savedId = null;

		return [
			'canonical' => $returnedCanonical,
			'savedId'   => $savedId !== null ? (int) $savedId : null,
		];
	}
}

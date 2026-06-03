<?php

namespace imports\newShipard\libs;

/**
 * Tenká fasáda nad HttpClient::uploadFile pro obecný endpoint
 * `POST /_attachments/upload` nového Shipardu. Sourozenec `CrudClient` /
 * `ExchangeClient`.
 *
 * Endpoint (viz nov_shipard:src/Api/Controller/AttachmentController.php):
 *   POST /api/v1/_attachments/upload   (multipart: table_id, record_id, file)
 *
 * Response shape:
 *   uploaded:  HTTP 201, {success: true, data: {id, …}}
 *   duplicate: {success: true, data: {id}, warning: {code: 'DUPLICATE_CHECKSUM',
 *              existing_attachment_id}} — obsah už u záznamu je (dedup přes SHA-256
 *              v rámci table_id+record_id), neukládá se podruhé.
 *
 * Entity-agnostické — žádná závislost na konkrétní tabulce.
 */
final class AttachmentUploadClient
{
	public function __construct(private readonly HttpClient $http) {}

	/**
	 * Upload souboru k záznamu (table_id, record_id) nového Shipardu.
	 *
	 * @return array{status: 'uploaded'|'duplicate', id: int}
	 * @throws HttpException při tvrdé chybě (4xx/5xx, chybějící soubor)
	 */
	public function upload(int $tableId, int $recordId, string $filePath, string $displayName): array
	{
		$resp = $this->http->uploadFile(
			'/_attachments/upload',
			['table_id' => $tableId, 'record_id' => $recordId],
			$filePath,
			'file',
			$displayName,
		);

		$id    = (int) ($resp['data']['id'] ?? 0);
		$isDup = isset($resp['warning']['code']) && $resp['warning']['code'] === 'DUPLICATE_CHECKSUM';

		return ['status' => $isDup ? 'duplicate' : 'uploaded', 'id' => $id];
	}
}

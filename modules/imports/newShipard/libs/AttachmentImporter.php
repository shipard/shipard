<?php

namespace imports\newShipard\libs;

/**
 * Orchestrace importu příloh: „zkopíruj všechny přílohy starého záznamu
 * `(oldTableId, oldRecId)` na nový záznam `(newTableIdNumeric, newRecordId)".
 *
 * Spojuje {@see AttachmentReader} (čtení + resolve cesty) a
 * {@see AttachmentUploadClient} (upload přes REST). Entity-agnostické — žádná
 * závislost na konkrétní tabulce; prvním konzumentem je MailRunner (Fáze 07b).
 *
 * Robustnost: soubor odmítnutý serverem (413/400 — nad nginx/PHP limit) ani
 * jiná HTTP chyba neukončí běh. Zaloguje se a počítá jako `failed`; ostatní
 * přílohy záznamu se nahrají dál.
 */
final class AttachmentImporter
{
	public function __construct(
		private readonly AttachmentReader $reader,
		private readonly AttachmentUploadClient $client,
		private readonly ?Logger $logger = null,
	) {}

	/**
	 * @return array{uploaded: int, duplicate: int, missing: int, failed: int}
	 */
	public function importFor(string $oldTableId, int $oldRecId, int $newTableIdNumeric, int $newRecordId): array
	{
		$stats = ['uploaded' => 0, 'duplicate' => 0, 'missing' => 0, 'failed' => 0];

		$res             = $this->reader->attachmentsFor($oldTableId, $oldRecId);
		$stats['missing'] = $res['missing'];

		foreach ($res['items'] as $a)
		{
			try
			{
				$up = $this->client->upload($newTableIdNumeric, $newRecordId, $a['filePath'], $a['displayName']);
				$stats[$up['status']]++;   // 'uploaded' | 'duplicate'
			}
			catch (HttpException $e)
			{
				$stats['failed']++;
				$this->logger?->line(sprintf(
					'! attachment upload failed (%s → table %d/rec %d): %s [HTTP %d]',
					$a['fileName'], $newTableIdNumeric, $newRecordId, $e->errorMessage, $e->statusCode,
				));
			}
		}

		return $stats;
	}
}

<?php

namespace imports\newShipard\libs;

/**
 * Čte přílohy starého Shipardu (`e10_attachments_files`) pro daný záznam
 * `(tableid, recid)`, resolvuje fyzickou cestu na disku a připravuje je k uploadu.
 *
 * Fyzické úložiště: `<dsRoot>/att/{path}{filename}` (`path` má koncové lomítko).
 * Vynechává soft-deleted řádky (`deleted = 1`) i řádky, jejichž soubor na disku
 * chybí (v legacy datech se to vyskytuje) — počet chybějících vrací zvlášť.
 *
 * `symlinkTo` neřešíme: symlinkovaná příloha sdílí stejné `path`+`filename` →
 * na disku jeden soubor. Nahrajeme každou řádku; nová strana dedupne podle
 * SHA-256 v rámci `(table_id, record_id)`.
 *
 * Entity-agnostické — `tableid` přijímá jako parametr.
 */
final class AttachmentReader
{
	public function __construct(
		private readonly \Dibi\Connection $db,
		private readonly string $dsRoot,
	) {}

	/**
	 * Přílohy starého záznamu připravené k uploadu. Pořadí dle defaultImage,
	 * order, name. Vynechává deleted=1 a soubory chybějící na disku.
	 *
	 * @return array{
	 *   items: list<array{displayName: string, filePath: string, fileName: string}>,
	 *   missing: int,
	 * }
	 */
	public function attachmentsFor(string $oldTableId, int $oldRecId): array
	{
		$rows = $this->db->query(
			'SELECT [name], [path], [filename] FROM [e10_attachments_files]'
			. ' WHERE [tableid] = %s AND [recid] = %i AND [deleted] = %i'
			. ' ORDER BY [defaultImage] DESC, [order], [name]',
			$oldTableId, $oldRecId, 0,
		)->fetchAll();

		$items   = [];
		$missing = 0;

		foreach ($rows as $r)
		{
			$row      = (array) $r;
			$physical = $this->dsRoot . '/att/' . (string) $row['path'] . (string) $row['filename'];

			if (!is_file($physical))
			{
				$missing++;
				continue;
			}

			// Zobrazovaný název: preferuj `name`, fallback `filename`.
			$name    = trim((string) ($row['name'] ?? ''));
			$display = $name !== '' ? $name : (string) $row['filename'];

			$items[] = [
				'displayName' => $display,
				'filePath'    => $physical,
				'fileName'    => (string) $row['filename'],
			];
		}

		return ['items' => $items, 'missing' => $missing];
	}
}

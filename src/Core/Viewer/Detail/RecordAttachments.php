<?php

declare(strict_types=1);

namespace Shipard\Core\Viewer\Detail;

use Shipard\Core\Database\DataSourceConnection;

/**
 * Vlastní přílohy záznamu pro detail: `core_attachments_files` podle
 * `tableId` tabulky a id záznamu, bez smazaných, v pořadí `att_order`.
 * Jeden dotaz pro builder detailu (`TableViewer`, #136 D21) místo kopií
 * ve viewerech; položky ve tvaru, který kreslí `AttachmentGrid`.
 */
final class RecordAttachments
{
    public function __construct(private readonly DataSourceConnection $db)
    {
    }

    /**
     * @return list<array{id: int, name: string, mime_type: string, file_size: int, send_with_record?: true}>
     */
    public function forRecord(int $tableId, int $recordId): array
    {
        $files = $this->db->fetchAll(
            'SELECT `id`, `name`, `file_name`, `file_size`, `mime_type`, `send_with_record`'
            . ' FROM `core_attachments_files`'
            . ' WHERE `table_id` = %i AND `record_id` = %i AND `is_deleted` = 0'
            . ' ORDER BY `att_order` ASC, `name` ASC',
            $tableId,
            $recordId,
        );

        return array_map([self::class, 'item'], $files);
    }

    /**
     * Položka přílohy z řádku `core_attachments_files`: název s fallbackem
     * na jméno souboru; `send_with_record: true` jen když je značka
     * „Odeslat s dokladem“ nastavená (#94 D6) — jinak klíč chybí.
     *
     * @param array<string, mixed> $file
     * @return array{id: int, name: string, mime_type: string, file_size: int, send_with_record?: true}
     */
    public static function item(array $file): array
    {
        $item = [
            'id'        => (int) $file['id'],
            'name'      => (string) ($file['name'] ?? $file['file_name'] ?? ''),
            'mime_type' => (string) ($file['mime_type'] ?? ''),
            'file_size' => (int) ($file['file_size'] ?? 0),
        ];
        if (!empty($file['send_with_record'])) {
            $item['send_with_record'] = true;
        }
        return $item;
    }
}

<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Attachments;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;

/**
 * Business logic for attachment operations.
 *
 * Orchestrates upload (validation → file storage → metadata extraction → DB insert),
 * download, thumbnail, soft-delete, and restore.
 */
class AttachmentService
{
    private const TABLE = 'core_attachments_files';

    private FileStorage $fileStorage;
    private ThumbnailGenerator $thumbnailGen;
    private MetadataExtractor $metadataExtractor;

    public function __construct(
        private DataSourceConnection $db,
        private string $dsPath,
        /** @var array<string, TableDefinition> */
        private array $tableDefinitions = [],
        /**
         * Ochrana příloh per cílová tabulka (#55 X16) — `tabulka => [třídy]`.
         * Sbírá je `AttachmentGuardLoader` z module.jsonc; instancují se až
         * při změně přílohy. Prázdná mapa = žádná ochrana (CLI, seedery).
         *
         * @var array<string, list<class-string>>
         */
        private array $guards = [],
    ) {
        $this->fileStorage = new FileStorage();
        $this->thumbnailGen = new ThumbnailGenerator();
        $this->metadataExtractor = new MetadataExtractor();
    }

    /**
     * Upload a new attachment.
     *
     * @param int    $tableId     Numeric tableId of the target table
     * @param int    $recordId    PK of the target record
     * @param string $originalName  Original filename
     * @param string $tmpPath     Path to the temporary uploaded file
     * @param int|null $userId    Uploading user ID
     * @param bool   $sendWithRecord  Příloha se posílá se záznamem (#94 D6);
     *                                výchozí ne — volající to musí říct výslovně
     * @return array{success: bool, data?: array, warning?: array, error?: string}
     * @throws \DomainException když nahrání odmítne guard cílové tabulky
     */
    public function upload(
        int $tableId,
        int $recordId,
        string $originalName,
        string $tmpPath,
        ?int $userId = null,
        bool $sendWithRecord = false,
    ): array {
        // Validate that the table exists
        $tableName = $this->resolveTableName($tableId);
        if ($tableName === null) {
            return ['success' => false, 'error' => "Tabulka s tableId {$tableId} neexistuje"];
        }

        // Validate that the record exists
        if (!$this->recordExists($tableName, $recordId)) {
            return ['success' => false, 'error' => "Záznam {$recordId} v tabulce {$tableName} neexistuje"];
        }

        // Guard cílové tabulky (#55 X16) — záznam s pevným obsahem další
        // přílohy nepřijímá.
        $this->assertChangeAllowed(
            ['table_id' => $tableId, 'record_id' => $recordId, 'name' => $originalName],
            AttachmentGuard::OPERATION_UPLOAD,
        );

        // Store file on disk
        $fileInfo = $this->fileStorage->store($this->dsPath, $tableName, $originalName, $tmpPath);

        // Detect MIME type from actual file content
        $fullPath = $this->fileStorage->getFullPath($this->dsPath, $fileInfo->filePath, $fileInfo->fileName);
        $mimeType = $this->detectMimeType($fullPath);

        // Extract metadata
        $metadata = $this->metadataExtractor->extract($fullPath, $mimeType);

        // Check for duplicates (same checksum for the same record)
        $warning = null;
        $duplicate = $this->findDuplicate($tableId, $recordId, $fileInfo->checksum);
        if ($duplicate !== null) {
            $warning = [
                'code' => 'DUPLICATE_CHECKSUM',
                'message' => 'Soubor se shodným obsahem již existuje u tohoto záznamu',
                'existing_attachment_id' => (int) $duplicate['id'],
            ];
        }

        // Sanitize display name (original filename without the hash suffix)
        $displayName = $this->buildDisplayName($originalName);

        // Determine next att_order
        $nextOrder = $this->getNextOrder($tableId, $recordId);

        $now = date('Y-m-d H:i:s');

        // Insert into DB
        $data = [
            'table_id'   => $tableId,
            'record_id'  => $recordId,
            'name'       => $displayName,
            'file_name'  => $fileInfo->fileName,
            'file_path'  => $fileInfo->filePath,
            'file_size'  => $fileInfo->fileSize,
            'mime_type'   => $mimeType,
            'checksum'   => $fileInfo->checksum,
            'metadata'   => $metadata !== null ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null,
            'att_order'  => $nextOrder,
            'is_deleted' => 0,
            'created'    => $now,
            'created_by' => $userId,
            'modified'   => $now,
        ];
        // Výchozí hodnotu nechává na databázi — sloupec se zapisuje jen
        // s výslovným příznakem.
        if ($sendWithRecord) {
            $data['send_with_record'] = 1;
        }

        $id = $this->db->insertRow(self::TABLE, $data);
        $data['id'] = $id;
        $data['send_with_record'] = $sendWithRecord ? 1 : 0;

        // Decode metadata back to array for response
        if ($data['metadata'] !== null) {
            $data['metadata'] = $metadata;
        }

        $result = ['success' => true, 'data' => $data];
        if ($warning !== null) {
            $result['warning'] = $warning;
        }

        return $result;
    }

    /**
     * Copy an existing attachment to another record (typically in another
     * table). The physical file is copied into the target table's dated
     * directory with a fresh filename hash — the source attachment and its
     * file stay untouched (D8 — kopie, ne přesun).
     *
     * Kept from the source row: display name, att_order, metadata, mime_type,
     * checksum (content is identical). `created_by` is the acting user.
     * `send_with_record` se nekopíruje — kopie u jiného záznamu se neposílá,
     * dokud to tam někdo výslovně nezapne.
     *
     * If the DB insert fails, the copied file is unlinked before the
     * exception propagates (no orphans). Callers wrapping this in an outer
     * transaction must unlink `data.file_path`/`data.file_name` themselves
     * on rollback (vzor MailController::cleanupOrphanedFiles).
     *
     * @return array{success: bool, data?: array, warning?: array, error?: string}
     */
    public function copyTo(int $attachmentId, int $targetTableId, int $targetRecordId, ?int $userId = null): array
    {
        $source = $this->getAttachment($attachmentId);
        if ($source === null || (int) ($source['is_deleted'] ?? 0) === 1) {
            return ['success' => false, 'error' => "Příloha {$attachmentId} neexistuje"];
        }

        $tableName = $this->resolveTableName($targetTableId);
        if ($tableName === null) {
            return ['success' => false, 'error' => "Tabulka s tableId {$targetTableId} neexistuje"];
        }

        if (!$this->recordExists($tableName, $targetRecordId)) {
            return ['success' => false, 'error' => "Záznam {$targetRecordId} v tabulce {$tableName} neexistuje"];
        }

        $this->assertChangeAllowed(
            ['table_id' => $targetTableId, 'record_id' => $targetRecordId, 'name' => (string) $source['name']],
            AttachmentGuard::OPERATION_UPLOAD,
        );

        $sourcePath = $this->getFilePath($source);
        $fileInfo = $this->fileStorage->copy(
            $this->dsPath,
            $tableName,
            $sourcePath,
            (string) $source['file_name'],
        );

        // Duplicate check against the target record (same semantics as upload)
        $warning = null;
        $duplicate = $this->findDuplicate($targetTableId, $targetRecordId, $fileInfo->checksum);
        if ($duplicate !== null) {
            $warning = [
                'code' => 'DUPLICATE_CHECKSUM',
                'message' => 'Soubor se shodným obsahem již existuje u tohoto záznamu',
                'existing_attachment_id' => (int) $duplicate['id'],
            ];
        }

        $now = date('Y-m-d H:i:s');

        $data = [
            'table_id'   => $targetTableId,
            'record_id'  => $targetRecordId,
            'name'       => (string) $source['name'],
            'file_name'  => $fileInfo->fileName,
            'file_path'  => $fileInfo->filePath,
            'file_size'  => $fileInfo->fileSize,
            'mime_type'  => $source['mime_type'] ?? null,
            'checksum'   => $fileInfo->checksum,
            'metadata'   => $source['metadata'] ?? null,
            'att_order'  => (int) ($source['att_order'] ?? 0),
            'is_deleted' => 0,
            'created'    => $now,
            'created_by' => $userId,
            'modified'   => $now,
        ];

        try {
            $id = $this->db->insertRow(self::TABLE, $data);
        } catch (\Throwable $e) {
            // Insert failed → copied file would be orphaned; clean up and rethrow.
            $orphan = $this->fileStorage->getFullPath($this->dsPath, $fileInfo->filePath, $fileInfo->fileName);
            if (is_file($orphan)) {
                @unlink($orphan);
            }
            throw $e;
        }
        $data['id'] = $id;

        $result = ['success' => true, 'data' => $data];
        if ($warning !== null) {
            $result['warning'] = $warning;
        }

        return $result;
    }

    /**
     * Get attachment record by ID.
     */
    public function getAttachment(int $id): ?array
    {
        return $this->db->fetchRow(
            'SELECT * FROM %n WHERE id = %i',
            self::TABLE,
            $id,
        );
    }

    /**
     * Get full file path for an attachment.
     */
    public function getFilePath(array $attachment): string
    {
        return $this->fileStorage->getFullPath(
            $this->dsPath,
            $attachment['file_path'],
            $attachment['file_name'],
        );
    }

    /**
     * Get or generate a thumbnail.
     *
     * @return string|null  Path to the thumbnail JPEG, or null if not supported/failed
     */
    public function getThumbnail(array $attachment, int $width = 300, int $quality = 85, int $page = 1): ?string
    {
        $inputPath = $this->getFilePath($attachment);
        if (!file_exists($inputPath)) {
            return null;
        }

        return $this->thumbnailGen->getThumbnail(
            $this->dsPath,
            $inputPath,
            $attachment['mime_type'],
            (int) $attachment['id'],
            $attachment['checksum'],
            $width,
            $quality,
            $page,
        );
    }

    /**
     * List attachments for a given record.
     *
     * @return array<int, array>
     */
    public function listAttachments(int $tableId, int $recordId, bool $includeDeleted = false): array
    {
        if ($includeDeleted) {
            return $this->db->fetchAll(
                'SELECT * FROM %n WHERE table_id = %i AND record_id = %i ORDER BY att_order ASC, name ASC',
                self::TABLE,
                $tableId,
                $recordId,
            );
        }

        return $this->db->fetchAll(
            'SELECT * FROM %n WHERE table_id = %i AND record_id = %i AND is_deleted = 0 ORDER BY att_order ASC, name ASC',
            self::TABLE,
            $tableId,
            $recordId,
        );
    }

    /**
     * Rename an attachment (change display name only).
     *
     * @throws \DomainException když změnu odmítne guard cílové tabulky
     */
    public function rename(int $id, string $newName): bool
    {
        $attachment = $this->getAttachment($id);
        if ($attachment === null) {
            return false;
        }
        $this->assertChangeAllowed($attachment, AttachmentGuard::OPERATION_RENAME);

        $this->db->updateWhere(
            self::TABLE,
            ['name' => $newName, 'modified' => date('Y-m-d H:i:s')],
            'id = %i',
            $id,
        );

        return true;
    }

    /**
     * Update attachment order.
     */
    public function updateOrder(int $id, int $newOrder): bool
    {
        $attachment = $this->getAttachment($id);
        if ($attachment === null) {
            return false;
        }
        $this->assertChangeAllowed($attachment, AttachmentGuard::OPERATION_REORDER);

        $this->db->updateWhere(
            self::TABLE,
            ['att_order' => $newOrder, 'modified' => date('Y-m-d H:i:s')],
            'id = %i',
            $id,
        );

        return true;
    }

    /**
     * Zapne / vypne odeslání přílohy se záznamem (`send_with_record`,
     * #94 D6). Zámek záznamu to neblokuje — příznak není obsah dokladu
     * a typicky se nastavuje u hotového dokladu těsně před odesláním.
     *
     * @throws \DomainException když změnu odmítne guard cílové tabulky
     */
    public function setSendWithRecord(int $id, bool $value): bool
    {
        $attachment = $this->getAttachment($id);
        if ($attachment === null) {
            return false;
        }
        $this->assertChangeAllowed($attachment, AttachmentGuard::OPERATION_SEND_FLAG);

        $this->db->updateWhere(
            self::TABLE,
            ['send_with_record' => $value ? 1 : 0, 'modified' => date('Y-m-d H:i:s')],
            'id = %i',
            $id,
        );

        return true;
    }

    /**
     * Merge extra keys into the attachment's `metadata` JSON (existing keys
     * are overwritten, others kept). Used for provenance of generated
     * attachments (e.g. `{generatedBy: "preprocess", ruleId, sourceUrl}`).
     *
     * @param array<string, mixed> $extra
     */
    public function mergeMetadata(int $id, array $extra): bool
    {
        $attachment = $this->getAttachment($id);
        if ($attachment === null) {
            return false;
        }

        $current = $attachment['metadata'] ?? null;
        if (is_string($current)) {
            $current = json_decode($current, true);
        }
        $merged = array_merge(is_array($current) ? $current : [], $extra);

        $this->db->updateWhere(
            self::TABLE,
            [
                'metadata' => json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'modified' => date('Y-m-d H:i:s'),
            ],
            'id = %i',
            $id,
        );

        return true;
    }

    /**
     * Soft-delete an attachment.
     *
     * @throws \DomainException když smazání odmítne guard cílové tabulky
     */
    public function softDelete(int $id): bool
    {
        $attachment = $this->getAttachment($id);
        if ($attachment === null) {
            return false;
        }
        $this->assertChangeAllowed($attachment, AttachmentGuard::OPERATION_DELETE);

        $this->db->updateWhere(
            self::TABLE,
            ['is_deleted' => 1, 'modified' => date('Y-m-d H:i:s')],
            'id = %i',
            $id,
        );

        return true;
    }

    /**
     * Restore a soft-deleted attachment.
     */
    public function restore(int $id): bool
    {
        $attachment = $this->getAttachment($id);
        if ($attachment === null) {
            return false;
        }

        $this->db->updateWhere(
            self::TABLE,
            ['is_deleted' => 0, 'modified' => date('Y-m-d H:i:s')],
            'id = %i',
            $id,
        );

        return true;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve numeric tableId to string table name.
     */
    /**
     * Zeptá se guardů cílové tabulky, jestli se s přílohou smí hýbat
     * (#55 X16). Odmítnutí je `DomainException` — controller ho převede na
     * 409 s hláškou guardu.
     *
     * @param array<string, mixed> $attachment
     * @throws \DomainException
     */
    private function assertChangeAllowed(array $attachment, string $operation): void
    {
        if ($this->guards === []) {
            return;
        }
        $tableName = $this->resolveTableName((int) ($attachment['table_id'] ?? 0));
        if ($tableName === null) {
            return;
        }
        foreach ($this->guards[$tableName] ?? [] as $class) {
            if (!class_exists($class) || !is_subclass_of($class, AttachmentGuard::class)) {
                continue;
            }
            $reason = (new $class($this->db))->refuse($attachment, $operation);
            if ($reason !== null) {
                throw new \DomainException($reason);
            }
        }
    }

    private function resolveTableName(int $tableId): ?string
    {
        foreach ($this->tableDefinitions as $name => $def) {
            if ($def->tableId === $tableId) {
                return $name;
            }
        }
        return null;
    }

    /**
     * Check whether a record exists in the target table.
     */
    private function recordExists(string $tableName, int $recordId): bool
    {
        $row = $this->db->fetchRow('SELECT id FROM %n WHERE id = %i', $tableName, $recordId);
        return $row !== null;
    }

    /**
     * Find an existing non-deleted attachment with the same checksum for the same record.
     */
    private function findDuplicate(int $tableId, int $recordId, string $checksum): ?array
    {
        return $this->db->fetchRow(
            'SELECT id FROM %n WHERE table_id = %i AND record_id = %i AND checksum = %s AND is_deleted = 0 LIMIT 1',
            self::TABLE,
            $tableId,
            $recordId,
            $checksum,
        );
    }

    /**
     * Get the next att_order value for a record.
     */
    private function getNextOrder(int $tableId, int $recordId): int
    {
        $row = $this->db->fetchRow(
            'SELECT MAX(att_order) AS max_order FROM %n WHERE table_id = %i AND record_id = %i',
            self::TABLE,
            $tableId,
            $recordId,
        );
        return $row !== null ? ((int) ($row['max_order'] ?? 0)) + 1 : 0;
    }

    /**
     * Build display name from original filename.
     * Just trims whitespace — the original name is usually fine as-is.
     */
    private function buildDisplayName(string $originalName): string
    {
        $name = trim($originalName);
        return $name !== '' ? $name : 'attachment';
    }

    /**
     * Detect MIME type from file content using PHP finfo.
     */
    private function detectMimeType(string $filePath): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($filePath);
        return $mimeType !== false ? $mimeType : 'application/octet-stream';
    }
}

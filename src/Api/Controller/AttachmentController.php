<?php

declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\AuthContext;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Module\Core\Attachments\AttachmentService;

/**
 * API controller for attachment operations.
 *
 * Endpoints:
 *   POST   /_attachments/upload          Upload a new attachment (multipart/form-data)
 *   GET    /_attachments/{id}/download    Download the original file
 *   GET    /_attachments/{id}/thumbnail   Get/generate a thumbnail
 *   GET    /_attachments                  List attachments for a record
 *   PATCH  /_attachments/{id}             Rename, reorder or flag an attachment for sending
 *   DELETE /_attachments/{id}             Soft-delete an attachment
 *   POST   /_attachments/{id}/restore     Restore a soft-deleted attachment
 */
class AttachmentController
{
    private AttachmentService $service;

    /**
     * @param array<string, TableDefinition> $tables
     */
    /**
     * @param array<string, TableDefinition>     $tables
     * @param array<string, list<class-string>>  $guards ochrana příloh
     *        per cílová tabulka (#55 X16) — `AttachmentGuardLoader`
     */
    public function __construct(
        private DataSourceConnection $db,
        private string $dsPath,
        private array $tables,
        array $guards = [],
    ) {
        $this->service = new AttachmentService($db, $dsPath, $tables, $guards);
    }

    /**
     * POST /_attachments/upload
     * Content-Type: multipart/form-data
     *
     * Fields: table_id (int), record_id (int), file (binary),
     * send_with_record ("1" | "0", volitelné — příloha se posílá se
     * záznamem; cesta pro import, #94 D9)
     */
    public function upload(AuthContext $auth): Response
    {
        // Validate required form fields
        $tableId = (int) ($_POST['table_id'] ?? 0);
        $recordId = (int) ($_POST['record_id'] ?? 0);

        if ($tableId <= 0) {
            return Response::error('VALIDATION_ERROR', 'Chybí table_id', 422);
        }
        if ($recordId <= 0) {
            return Response::error('VALIDATION_ERROR', 'Chybí record_id', 422);
        }

        // Validate uploaded file
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $errorCode = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
            $errorMessage = match ($errorCode) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Soubor je příliš velký',
                UPLOAD_ERR_NO_FILE => 'Žádný soubor nebyl nahrán',
                UPLOAD_ERR_PARTIAL => 'Soubor byl nahrán jen částečně',
                default => 'Chyba při nahrávání souboru',
            };
            return Response::error('UPLOAD_ERROR', $errorMessage, 400);
        }

        $file = $_FILES['file'];
        $originalName = $file['name'] ?? 'unknown';
        $tmpPath = $file['tmp_name'];

        $userId = $auth->isAuthenticated ? $auth->userId : null;

        $sendWithRecord = (string) ($_POST['send_with_record'] ?? '0') === '1';

        try {
            $result = $this->service->upload($tableId, $recordId, $originalName, $tmpPath, $userId, $sendWithRecord);
        } catch (\DomainException $e) {
            // Guard cílové tabulky (#55 X16) — záznam další přílohy nepřijímá.
            return Response::error('ATTACHMENT_LOCKED', $e->getMessage(), 409);
        }

        if (!$result['success']) {
            return Response::error('VALIDATION_ERROR', $result['error'] ?? 'Upload failed', 422);
        }

        $response = ['success' => true, 'data' => $this->formatAttachment($result['data'])];
        if (isset($result['warning'])) {
            $response['warning'] = $result['warning'];
        }

        return Response::raw($response, 201);
    }

    /**
     * GET /_attachments/{id}/download[?inline=1]
     *
     * Default disposition is `attachment` (file save dialog). With
     * `?inline=1` the browser renders the file inline — required for the
     * Exchange split-view PDF panel and image previews. Restricted to PDF
     * and image MIME types to prevent same-origin XSS via inline HTML/SVG.
     */
    public function download(int $id, Request $request): Response
    {
        $attachment = $this->service->getAttachment($id);
        if ($attachment === null) {
            return Response::error('NOT_FOUND', 'Příloha nenalezena', 404);
        }

        $filePath = $this->service->getFilePath($attachment);
        if (!file_exists($filePath)) {
            return Response::error('NOT_FOUND', 'Soubor nenalezen na disku', 404);
        }

        $inlineRequested = ($request->getQueryParams()['inline'] ?? '0') === '1';
        $disposition = $this->computeDisposition(
            (string) $attachment['mime_type'],
            $inlineRequested,
        );

        $this->sendFile(
            $filePath,
            $attachment['mime_type'],
            $attachment['name'],
            (int) $attachment['file_size'],
            cacheForever: false,
            disposition: $disposition,
        );

        // sendFile exits — this is just for type safety
        return Response::success(null, 204);
    }

    /**
     * Decide `Content-Disposition` value. Inline is only granted to safe
     * types — anything else falls back to `attachment` even when the
     * caller asks for inline. Public so it can be tested without invoking
     * the streaming `sendFile()` path that exits the process.
     */
    public function computeDisposition(string $mimeType, bool $inlineRequested): string
    {
        if (!$inlineRequested) {
            return 'attachment';
        }
        $inlineSafe = $mimeType === 'application/pdf'
            || str_starts_with($mimeType, 'image/');
        return $inlineSafe ? 'inline' : 'attachment';
    }

    /**
     * GET /_attachments/{id}/thumbnail?w=300&q=85&page=1
     */
    public function thumbnail(int $id, Request $request): Response
    {
        $attachment = $this->service->getAttachment($id);
        if ($attachment === null) {
            return Response::error('NOT_FOUND', 'Příloha nenalezena', 404);
        }

        $params = $request->getQueryParams();
        $width = min(max((int) ($params['w'] ?? 300), 16), 2000);
        $quality = min(max((int) ($params['q'] ?? 85), 1), 100);
        $page = max((int) ($params['page'] ?? 1), 1);

        $thumbnailPath = $this->service->getThumbnail($attachment, $width, $quality, $page);
        if ($thumbnailPath === null) {
            return Response::error('NOT_FOUND', 'Náhled nelze vygenerovat pro tento typ souboru', 404);
        }

        $this->sendFile($thumbnailPath, 'image/jpeg', null, null, true);

        return Response::success(null, 204);
    }

    /**
     * GET /_attachments?table_id={tableId}&record_id={recordId}
     */
    public function list(Request $request): Response
    {
        $params = $request->getQueryParams();
        $tableId = (int) ($params['table_id'] ?? 0);
        $recordId = (int) ($params['record_id'] ?? 0);

        if ($tableId <= 0 || $recordId <= 0) {
            return Response::error('BAD_REQUEST', 'Parametry table_id a record_id jsou povinné', 400);
        }

        $includeDeleted = ($params['include_deleted'] ?? '0') === '1';
        $attachments = $this->service->listAttachments($tableId, $recordId, $includeDeleted);

        $data = array_map(fn(array $att) => $this->formatAttachment($att), $attachments);

        return Response::success($data, 200, ['total' => count($data)]);
    }

    /**
     * PATCH /_attachments/{id}
     *
     * Body: {"name": "new name"}, {"att_order": 5} and/or
     * {"send_with_record": true} — samostatně i dohromady.
     */
    public function patch(int $id, Request $request): Response
    {
        $body = $request->getBody();
        if ($body === null) {
            return Response::error('BAD_REQUEST', 'Tělo požadavku musí být JSON', 400);
        }

        $attachment = $this->service->getAttachment($id);
        if ($attachment === null) {
            return Response::error('NOT_FOUND', 'Příloha nenalezena', 404);
        }

        // Nejdřív celé tělo zvalidovat — neplatné pole nesmí nechat změnu napůl.
        $name = null;
        if (isset($body['name'])) {
            $name = trim((string) $body['name']);
            if ($name === '') {
                return Response::error('VALIDATION_ERROR', 'Název přílohy nesmí být prázdný', 422);
            }
        }
        $sendWithRecord = $body['send_with_record'] ?? null;
        if ($sendWithRecord !== null && !is_bool($sendWithRecord)) {
            return Response::error('VALIDATION_ERROR', 'Pole send_with_record musí být true nebo false', 422);
        }

        try {
            if ($name !== null) {
                $this->service->rename($id, $name);
            }
            if (isset($body['att_order'])) {
                $this->service->updateOrder($id, (int) $body['att_order']);
            }
            if ($sendWithRecord !== null) {
                $this->service->setSendWithRecord($id, $sendWithRecord);
            }
        } catch (\DomainException $e) {
            // Guard cílové tabulky (#55 X16) — stejně jako u smazání.
            return Response::error('ATTACHMENT_LOCKED', $e->getMessage(), 409);
        }

        // Return updated record
        $updated = $this->service->getAttachment($id);
        return Response::success($this->formatAttachment($updated ?? $attachment));
    }

    /**
     * DELETE /_attachments/{id}
     */
    public function delete(int $id): Response
    {
        try {
            if (!$this->service->softDelete($id)) {
                return Response::error('NOT_FOUND', 'Příloha nenalezena', 404);
            }
        } catch (\DomainException $e) {
            // Guard cílové tabulky (#55 X16) — přílohu chrání stav záznamu,
            // ne oprávnění uživatele, proto 409 a ne 403.
            return Response::error('ATTACHMENT_LOCKED', $e->getMessage(), 409);
        }

        return Response::success(null, 204);
    }

    /**
     * POST /_attachments/{id}/restore
     */
    public function restore(int $id): Response
    {
        if (!$this->service->restore($id)) {
            return Response::error('NOT_FOUND', 'Příloha nenalezena', 404);
        }

        $attachment = $this->service->getAttachment($id);
        return Response::success($this->formatAttachment($attachment ?? []));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Format attachment record for API response.
     * Adds thumbnail_url for supported types, decodes metadata JSON.
     */
    private function formatAttachment(array $att): array
    {
        // Cast types
        $att['id'] = (int) ($att['id'] ?? 0);
        $att['table_id'] = (int) ($att['table_id'] ?? 0);
        $att['record_id'] = (int) ($att['record_id'] ?? 0);
        $att['file_size'] = (int) ($att['file_size'] ?? 0);
        $att['att_order'] = (int) ($att['att_order'] ?? 0);
        $att['is_deleted'] = (bool) ($att['is_deleted'] ?? false);
        $att['send_with_record'] = (bool) ($att['send_with_record'] ?? false);
        $att['created_by'] = $att['created_by'] !== null ? (int) $att['created_by'] : null;

        // Decode metadata if it's a JSON string
        if (is_string($att['metadata'] ?? null)) {
            $att['metadata'] = json_decode($att['metadata'], true);
        }

        // Add thumbnail URL for supported types
        $mimeType = $att['mime_type'] ?? '';
        if (str_starts_with($mimeType, 'image/') || $mimeType === 'application/pdf') {
            $att['thumbnail_url'] = "/_attachments/{$att['id']}/thumbnail?w=300";
        }

        // Remove internal file path details from response
        unset($att['file_path'], $att['file_name']);

        return $att;
    }

    /**
     * Send a file to the client and exit.
     *
     * `$disposition` controls the Content-Disposition value — `attachment`
     * for downloads (default), `inline` for the rare cases where the
     * caller wants the browser to render inline (PDF/image preview).
     * Callers must whitelist MIME types before passing `inline` —
     * {@see computeDisposition()}.
     */
    private function sendFile(
        string $filePath,
        string $mimeType,
        ?string $displayName,
        ?int $fileSize,
        bool $cacheForever = false,
        string $disposition = 'attachment',
    ): never {
        // Clean any output buffers
        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: ' . $mimeType);

        if ($displayName !== null) {
            // RFC 6266: ASCII fallback + UTF-8 filename*
            $asciiName = preg_replace('/[^\x20-\x7E]/', '_', $displayName);
            header("Content-Disposition: {$disposition}; filename=\"{$asciiName}\"; filename*=UTF-8''" . rawurlencode($displayName));
        }

        if ($fileSize !== null) {
            header('Content-Length: ' . $fileSize);
        }

        if ($cacheForever) {
            header('Cache-Control: public, max-age=31536000, immutable');
        }

        readfile($filePath);
        exit;
    }
}

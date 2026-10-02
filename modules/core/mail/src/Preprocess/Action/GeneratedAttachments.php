<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Preprocess\Action;

use Shipard\Module\Core\Attachments\AttachmentService;
use Shipard\Module\Core\Mail\Preprocess\PreprocessFailureCode;

/**
 * Společný kus akcí předzpracování: příloha zprávy vygenerovaná akcí
 * s provenance metadaty (D5) — hledání existující (idempotence), uložení
 * přes AttachmentService a sanitizace názvu souboru. Akce se liší jen
 * tím, odkud berou obsah (stažení z odkazu, render těla).
 *
 * Provenance v `core_attachments_files.metadata`:
 * `{generatedBy: "preprocess", ruleId, action, ...per akci}`.
 */
final class GeneratedAttachments
{
    /** tableId `core_mail_incoming_messages`. */
    public const MAIL_TABLE_ID = 303;

    public const FILE_NAME_MAX_LENGTH = 150;

    public function __construct(private readonly AttachmentService $attachments)
    {
    }

    /**
     * Id nesmazané přílohy zprávy se shodnou provenance `(ruleId, action)`,
     * a je-li dán, i `sourceUrl`; null = zatím nevygenerována.
     */
    public function findExisting(int $messageId, string $ruleId, string $action, ?string $sourceUrl = null): ?int
    {
        foreach ($this->attachments->listAttachments(self::MAIL_TABLE_ID, $messageId) as $file) {
            $file = (array) $file;
            $metadata = $file['metadata'] ?? null;
            if (is_string($metadata)) {
                $metadata = json_decode($metadata, true);
            }
            if (!is_array($metadata)) {
                continue;
            }
            if (($metadata['generatedBy'] ?? null) === 'preprocess'
                && ($metadata['action'] ?? null) === $action
                && ($metadata['ruleId'] ?? null) === $ruleId
                && ($sourceUrl === null || ($metadata['sourceUrl'] ?? null) === $sourceUrl)
            ) {
                return (int) $file['id'];
            }
        }
        return null;
    }

    /**
     * Uloží obsah jako obsahovou přílohu zprávy a zapíše provenance.
     * Selhání (disk, upload) = provozní stav v poznámce, žádná výjimka;
     * kód vždy `internal` — chyba na straně Shipardu, ne zprávy.
     *
     * @param array<string, mixed> $extra Metadata specifická pro akci
     *        (sourceUrl, finalUrl, bodySha256, …).
     * @return array{ok: bool, note: string, code?: string, id?: int}
     */
    public function store(int $messageId, string $fileName, string $content, string $ruleId, string $action, array $extra = []): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'shpd_pp_');
        if ($tmp === false || file_put_contents($tmp, $content) === false) {
            return ['ok' => false, 'note' => 'cannot write temporary file', 'code' => PreprocessFailureCode::INTERNAL];
        }

        // FileStorage soubor přesouvá (rename); při selhání uklidit sami.
        $result = $this->attachments->upload(self::MAIL_TABLE_ID, $messageId, $fileName, $tmp, null);
        if (is_file($tmp)) {
            @unlink($tmp);
        }
        if (!($result['success'] ?? false)) {
            return ['ok' => false, 'note' => 'attachment upload failed: ' . (string) ($result['error'] ?? 'unknown'), 'code' => PreprocessFailureCode::INTERNAL];
        }

        $id = (int) ($result['data']['id'] ?? 0);
        $this->attachments->mergeMetadata($id, [
            'generatedBy' => 'preprocess',
            'ruleId' => $ruleId,
            'action' => $action,
        ] + $extra);

        return ['ok' => true, 'note' => '', 'id' => $id];
    }

    /**
     * Bezpečný název PDF souboru: zakázané znaky → `_`, ořez okrajových
     * teček/mezer, `$fallback` za prázdný, vynucená přípona `.pdf`, strop
     * délky (přípona se nikdy neodřízne).
     */
    public static function sanitizePdfFileName(string $name, string $fallback): string
    {
        $name = preg_replace('~[\\\\/:*?"<>|\x00-\x1F]+~', '_', $name) ?? '';
        $name = trim($name, ' ._');
        if ($name === '') {
            $name = $fallback;
        }
        $base = preg_replace('~\.pdf$~i', '', $name) ?? $name;
        $base = rtrim(mb_substr($base, 0, self::FILE_NAME_MAX_LENGTH - 4), ' ._');
        return ($base !== '' ? $base : 'document') . '.pdf';
    }
}

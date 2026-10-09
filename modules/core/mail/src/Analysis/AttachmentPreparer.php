<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Analysis;

use Shipard\Core\Logging\ErrorLogger;
use Shipard\Module\Core\Attachments\AttachmentService;

/**
 * Příprava příloh zprávy pro model (tasks/mail-analysis-inprocess.md D16,
 * přenos `ai_analyzer/preprocessing.py`):
 *
 *   1. rozbalení ZIP (jedna úroveň, vnořený ZIP se přeskočí; soubory dědí
 *      `ndx` rodiče a název `<zip>/<vnitřní název>`),
 *   2. třídění na PDF / obrázek (`jpeg`, `png`, `gif`, `webp`) / text
 *      (`text/*`); u `application/octet-stream` odhad podle přípony,
 *      ostatní typy se přeskočí (i `.eml` / `message/rfc822` — rozbalení
 *      přeposlané zprávy se nepřenáší),
 *   3. dekódování textu (`utf-8`, `utf-16` s BOM, `windows-1250`,
 *      `latin-1`), binární obsah do base64,
 *   4. limity: nejvýš {@see MAX_ATTACHMENTS} příloh a {@see MAX_TOTAL_BYTES}
 *      v tělě požadavku (priorita PDF > obrázek > text, při stejné prioritě
 *      větší dřív; zachované přílohy v původním pořadí), nad
 *      {@see WARN_ATTACHMENT_BYTES} na přílohu jen varování v logu.
 *
 * Limity jsou konstanty, ne nastavení. Vstup z DB dělá {@see prepare()}
 * (přílohy jako `GET /payload`: bez `raw_source_attachment`, bez
 * smazaných), čistá část {@see prepareRaw()} jde testovat bez databáze.
 */
class AttachmentPreparer
{
    public const MAX_ATTACHMENTS = 20;
    public const MAX_TOTAL_BYTES = 30 * 1024 * 1024;
    public const WARN_ATTACHMENT_BYTES = 10 * 1024 * 1024;

    public const MAIL_TABLE_ID = 303;

    private const PDF_MIME = 'application/pdf';
    private const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    private const ZIP_MIMES = ['application/zip', 'application/x-zip-compressed'];
    private const EML_MIMES = ['message/rfc822', 'application/eml'];

    /** Vyšší vyhrává při ořezu na limity. */
    private const PRIORITY = [
        PreparedAttachment::KIND_PDF => 3,
        PreparedAttachment::KIND_IMAGE => 2,
        PreparedAttachment::KIND_TEXT => 1,
    ];

    /** Odhad typu podle přípony (soubory ze ZIPu, `application/octet-stream`). */
    private const EXTENSION_MIMES = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'txt' => 'text/plain',
        'log' => 'text/plain',
        'csv' => 'text/csv',
        'md' => 'text/markdown',
        'html' => 'text/html',
        'htm' => 'text/html',
        'xml' => 'text/xml',
    ];

    public function __construct(
        private readonly AttachmentService $attachments,
    ) {}

    /**
     * Přílohy zprávy z DB a disku: bez `raw_source_attachment` (.eml
     * originál — jinak by se data analyzovala dvakrát) a bez smazaných.
     * Soubor chybějící na disku se přeskočí s varováním.
     *
     * @return list<PreparedAttachment>
     */
    public function prepare(int $messageId, int $rawSourceNdx): array
    {
        $raw = [];
        foreach ($this->attachments->listAttachments(self::MAIL_TABLE_ID, $messageId) as $file) {
            $file = (array) $file;
            $ndx = (int) ($file['id'] ?? 0);
            if ($rawSourceNdx > 0 && $ndx === $rawSourceNdx) {
                continue;
            }
            $path = $this->attachments->getFilePath($file);
            $content = is_file($path) ? @file_get_contents($path) : false;
            if ($content === false) {
                ErrorLogger::warn('AttachmentPreparer: attachment file missing on disk — skipped', [
                    'message' => $messageId,
                    'ndx' => $ndx,
                    'filename' => (string) ($file['name'] ?? ''),
                ]);
                continue;
            }
            $raw[] = new RawAttachment($ndx, (string) ($file['name'] ?? ''), (string) ($file['mime_type'] ?? ''), $content);
        }

        return $this->prepareRaw($raw);
    }

    /**
     * @param list<RawAttachment> $attachments
     * @return list<PreparedAttachment>
     */
    public function prepareRaw(array $attachments): array
    {
        $expanded = [];
        foreach ($attachments as $att) {
            foreach ($this->expand($att) as $item) {
                $expanded[] = $item;
            }
        }

        $prepared = [];
        foreach ($expanded as $raw) {
            [$kind, $mime] = self::classify($raw);
            if ($kind === null) {
                ErrorLogger::warn('AttachmentPreparer: unsupported attachment type — skipped', [
                    'ndx' => $raw->ndx,
                    'filename' => $raw->filename,
                    'mime' => $raw->mimeType,
                ]);
                continue;
            }

            $size = strlen($raw->content);
            if ($size > self::WARN_ATTACHMENT_BYTES) {
                ErrorLogger::warn('AttachmentPreparer: attachment over the single-file limit — kept', [
                    'ndx' => $raw->ndx,
                    'filename' => $raw->filename,
                    'sizeMb' => round($size / 1024 / 1024, 2),
                    'limitMb' => self::WARN_ATTACHMENT_BYTES / 1024 / 1024,
                ]);
            }

            if ($kind === PreparedAttachment::KIND_TEXT) {
                $prepared[] = new PreparedAttachment(
                    $raw->ndx,
                    $kind,
                    $raw->filename,
                    $raw->mimeType,
                    null,
                    self::decodeText($raw->content),
                );
            } else {
                $prepared[] = new PreparedAttachment(
                    $raw->ndx,
                    $kind,
                    $raw->filename,
                    self::normalizeMime($mime, $kind),
                    base64_encode($raw->content),
                    null,
                );
            }
        }

        return self::applyLimits($prepared);
    }

    // ── rozbalení ──────────────────────────────────────────────────────────

    /** @return list<RawAttachment> */
    private function expand(RawAttachment $att): array
    {
        $mime = self::baseMime($att->mimeType);
        $name = strtolower($att->filename);
        if (in_array($mime, self::ZIP_MIMES, true) || str_ends_with($name, '.zip')) {
            return $this->expandZip($att);
        }
        if (in_array($mime, self::EML_MIMES, true) || str_ends_with($name, '.eml')) {
            // Rozbalení přeposlané zprávy se nepřenáší (D16) — okrajový případ.
            ErrorLogger::warn('AttachmentPreparer: forwarded message (.eml) is not expanded — skipped', [
                'ndx' => $att->ndx,
                'filename' => $att->filename,
            ]);
            return [];
        }
        return [$att];
    }

    /**
     * Jedna úroveň: vnořený ZIP se přeskočí (jinak tarpit), adresáře
     * a nečitelné položky (šifrované, poškozené) také. Vadný archiv = nic.
     *
     * @return list<RawAttachment>
     */
    private function expandZip(RawAttachment $att): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'shpd-analysis-zip-');
        if ($tmp === false) {
            ErrorLogger::warn('AttachmentPreparer: cannot create temp file for ZIP — skipped', ['ndx' => $att->ndx]);
            return [];
        }

        try {
            file_put_contents($tmp, $att->content);
            $zip = new \ZipArchive();
            if ($zip->open($tmp) !== true) {
                ErrorLogger::warn('AttachmentPreparer: bad ZIP — skipped', [
                    'ndx' => $att->ndx,
                    'filename' => $att->filename,
                ]);
                return [];
            }

            $out = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $inner = is_array($stat) ? (string) ($stat['name'] ?? '') : '';
                if ($inner === '' || str_ends_with($inner, '/')) {
                    continue;
                }
                if (str_ends_with(strtolower($inner), '.zip')) {
                    ErrorLogger::warn('AttachmentPreparer: nested ZIP — skipped', [
                        'parentNdx' => $att->ndx,
                        'inner' => $inner,
                    ]);
                    continue;
                }
                if ((int) ($stat['size'] ?? 0) > self::MAX_TOTAL_BYTES) {
                    // Nad celkový limit se nevejde ani sám — nečíst do paměti.
                    ErrorLogger::warn('AttachmentPreparer: ZIP entry over the total limit — skipped', [
                        'parentNdx' => $att->ndx,
                        'inner' => $inner,
                        'size' => (int) $stat['size'],
                    ]);
                    continue;
                }
                $data = $zip->getFromIndex($i);
                if ($data === false) {
                    ErrorLogger::warn('AttachmentPreparer: unreadable ZIP entry — skipped', [
                        'parentNdx' => $att->ndx,
                        'inner' => $inner,
                    ]);
                    continue;
                }
                $out[] = new RawAttachment(
                    $att->ndx,
                    $att->filename . '/' . $inner,
                    self::guessMime($inner) ?? 'application/octet-stream',
                    $data,
                );
            }
            $zip->close();

            return $out;
        } finally {
            @unlink($tmp);
        }
    }

    // ── třídění ────────────────────────────────────────────────────────────

    /**
     * Druh přílohy a MIME, ze kterého vzešel (u `application/octet-stream`
     * odhad podle přípony — ten pak nese i blok pro model, jinak by obrázek
     * dostal `media_type` octet-stream).
     *
     * @return array{0: ?string, 1: string}
     */
    private static function classify(RawAttachment $att): array
    {
        $mime = self::baseMime($att->mimeType);
        $kind = self::kindOfMime($mime);
        if ($kind !== null) {
            return [$kind, $mime];
        }
        if ($mime === 'application/octet-stream' || $mime === '') {
            $guessed = self::guessMime($att->filename);
            if ($guessed !== null) {
                return [self::kindOfMime($guessed), $guessed];
            }
        }
        return [null, $mime];
    }

    private static function kindOfMime(string $mime): ?string
    {
        if ($mime === self::PDF_MIME) {
            return PreparedAttachment::KIND_PDF;
        }
        if (in_array($mime, self::IMAGE_MIMES, true)) {
            return PreparedAttachment::KIND_IMAGE;
        }
        if (str_starts_with($mime, 'text/')) {
            return PreparedAttachment::KIND_TEXT;
        }
        return null;
    }

    private static function baseMime(string $mime): string
    {
        return strtolower(trim(explode(';', $mime, 2)[0]));
    }

    private static function guessMime(string $filename): ?string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return self::EXTENSION_MIMES[$ext] ?? null;
    }

    private static function normalizeMime(string $mime, string $kind): string
    {
        $base = self::baseMime($mime);
        if ($kind === PreparedAttachment::KIND_IMAGE && in_array($base, self::IMAGE_MIMES, true)) {
            return $base;
        }
        if ($kind === PreparedAttachment::KIND_PDF) {
            return self::PDF_MIME;
        }
        return $base !== '' ? $base : 'application/octet-stream';
    }

    /**
     * UTF-8 beze změny; UTF-16 jen s BOM (bez něj by se jako UTF-16 „dekódoval“
     * i text ve windows-1250 sudé délky); pak windows-1250 (mbstring ji
     * nezná — iconv, nedefinovaný bajt = selhání); latin-1 nikdy neselže.
     */
    public static function decodeText(string $data): string
    {
        if (mb_check_encoding($data, 'UTF-8')) {
            return $data;
        }
        if (str_starts_with($data, "\xFF\xFE")) {
            return mb_convert_encoding(substr($data, 2), 'UTF-8', 'UTF-16LE');
        }
        if (str_starts_with($data, "\xFE\xFF")) {
            return mb_convert_encoding(substr($data, 2), 'UTF-8', 'UTF-16BE');
        }
        $cp1250 = @iconv('CP1250', 'UTF-8', $data);
        if ($cp1250 !== false) {
            return $cp1250;
        }
        return (string) iconv('ISO-8859-1', 'UTF-8', $data);
    }

    // ── limity ─────────────────────────────────────────────────────────────

    /**
     * Výběr podle (priorita druhu ↓, bajty v požadavku ↓, původní pořadí ↑)
     * do stropu počtu a celkové velikosti; zachované přílohy zpět v původním
     * pořadí (model je vidí tak, jak byly na zprávě).
     *
     * @param list<PreparedAttachment> $prepared
     * @return list<PreparedAttachment>
     */
    private static function applyLimits(array $prepared): array
    {
        $indexed = [];
        foreach ($prepared as $i => $att) {
            $indexed[] = [$i, $att];
        }
        usort($indexed, static function (array $a, array $b): int {
            return [-self::PRIORITY[$a[1]->kind], -$a[1]->aiBytes(), $a[0]]
                <=> [-self::PRIORITY[$b[1]->kind], -$b[1]->aiBytes(), $b[0]];
        });

        $selected = [];
        $total = 0;
        foreach ($indexed as [$index, $att]) {
            if (count($selected) >= self::MAX_ATTACHMENTS) {
                ErrorLogger::warn('AttachmentPreparer: attachment count limit — skipped', [
                    'ndx' => $att->ndx,
                    'filename' => $att->filename,
                    'limit' => self::MAX_ATTACHMENTS,
                ]);
                continue;
            }
            $bytes = $att->aiBytes();
            if ($total + $bytes > self::MAX_TOTAL_BYTES) {
                ErrorLogger::warn('AttachmentPreparer: total size limit — skipped', [
                    'ndx' => $att->ndx,
                    'filename' => $att->filename,
                    'limitMb' => self::MAX_TOTAL_BYTES / 1024 / 1024,
                    'aiBytes' => $bytes,
                ]);
                continue;
            }
            $selected[$index] = $att;
            $total += $bytes;
        }

        ksort($selected);
        return array_values($selected);
    }
}

<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Preprocess\Action;

use Shipard\Core\Render\RenderClient;
use Shipard\Core\Render\RenderProfile;
use Shipard\Module\Core\Attachments\AttachmentService;
use Shipard\Module\Core\Mail\Preprocess\ActionResult;
use Shipard\Module\Core\Mail\Preprocess\PreprocessAction;
use Shipard\Module\Core\Mail\Preprocess\PreprocessFailureCode;

/**
 * Akce `renderBodyToPdf` (tasks/mail-preprocess-phase2.md §3, D16): HTML
 * tělo zprávy → PDF příloha přes rendering službu (#34), profil
 * `Untrusted`. Pro zprávy, kde je faktura přímo tělem e-mailu (Apple,
 * Google Play) — AI analyzer i Spisovna pak dostanou doklad jako PDF.
 *
 * Vstup je jen `body_html` (prázdné = selhání akce). Assety se nepřikládají
 * a odchozí síť Chromia je vypnutá: vzdálené obrázky a tracking pixely se
 * **záměrně** nenačtou (deterministický výstup, žádný egress), `cid:`
 * obrázky v1 neřešíme. Tělo bez `<meta charset>` se obalí UTF-8 hlavičkou —
 * Chromium by jinak hádalo kódování a rozbilo diakritiku.
 *
 * Idempotence dle `(ruleId, action)` — tělo je po intake neměnné. Selhání
 * renderu (nenakonfigurovaná služba, timeout, chyba enginu) = provozní
 * stav v poznámce, žádná výjimka (D6); kód `bodyRender`, u uložení
 * přílohy `internal` (tasks/mail-preprocess-error-messages.md D1).
 */
final class RenderBodyToPdfAction implements PreprocessAction
{
    public const KEY = 'renderBodyToPdf';

    public const HTML_MAX_BYTES = 2 * 1024 * 1024;
    public const FALLBACK_FILE_NAME = 'message-body.pdf';

    private readonly GeneratedAttachments $generated;

    public function __construct(
        AttachmentService $attachments,
        private readonly RenderClient $render,
    ) {
        $this->generated = new GeneratedAttachments($attachments);
    }

    public function execute(array $message, string $ruleId, array $params): ActionResult
    {
        $messageId = (int) ($message['id'] ?? 0);

        $existing = $this->generated->findExisting($messageId, $ruleId, self::KEY);
        if ($existing !== null) {
            return ActionResult::success("already present (attachment {$existing})", [$existing]);
        }

        $html = (string) ($message['body_html'] ?? '');
        if (trim($html) === '') {
            return ActionResult::failure('message has no HTML body', PreprocessFailureCode::BODY_RENDER);
        }
        if (strlen($html) > self::HTML_MAX_BYTES) {
            return ActionResult::failure('HTML body exceeds the size cap (' . self::HTML_MAX_BYTES . ' B)', PreprocessFailureCode::BODY_RENDER);
        }

        $rendered = $this->render->renderHtml(self::ensureUtf8Document($html), [], RenderProfile::Untrusted);
        if (!$rendered->ok || $rendered->pdfContent === null) {
            $kind = $rendered->errorKind?->value ?? 'unknown';
            return ActionResult::failure(
                "render failed: {$kind}" . ($rendered->note !== null ? ": {$rendered->note}" : ''),
                PreprocessFailureCode::BODY_RENDER,
            );
        }

        $stored = $this->generated->store(
            $messageId,
            self::fileNameFor((string) ($message['subject'] ?? '')),
            $rendered->pdfContent,
            $ruleId,
            self::KEY,
            ['bodySha256' => hash('sha256', $html), 'renderedAt' => date('c')],
        );
        if (!$stored['ok']) {
            return ActionResult::failure($stored['note'], $stored['code'] ?? PreprocessFailureCode::INTERNAL);
        }

        return ActionResult::success("rendered HTML body → attachment {$stored['id']}", [$stored['id']]);
    }

    /** Název PDF ze subjectu zprávy; prázdný subject → generický název. */
    public static function fileNameFor(string $subject): string
    {
        return GeneratedAttachments::sanitizePdfFileName(trim($subject), self::FALLBACK_FILE_NAME);
    }

    /**
     * Zajistí deklaraci UTF-8: bez `<meta charset>` Chromium hádá kódování
     * souboru (typicky windows-1252) a česká diakritika se rozpadne. Těla
     * e-mailů jsou často jen fragmenty bez `<html>`/`<head>`.
     */
    public static function ensureUtf8Document(string $html): string
    {
        if (preg_match('~<meta[^>]+charset\s*=~i', $html)) {
            return $html;
        }
        $meta = '<meta charset="utf-8">';
        if (preg_match('~<head\b[^>]*>~i', $html, $m, PREG_OFFSET_CAPTURE)) {
            $pos = $m[0][1] + strlen($m[0][0]);
            return substr($html, 0, $pos) . $meta . substr($html, $pos);
        }
        if (preg_match('~<html\b[^>]*>~i', $html, $m, PREG_OFFSET_CAPTURE)) {
            $pos = $m[0][1] + strlen($m[0][0]);
            return substr($html, 0, $pos) . '<head>' . $meta . '</head>' . substr($html, $pos);
        }
        return '<!DOCTYPE html><html><head>' . $meta . '</head><body>' . $html . '</body></html>';
    }
}

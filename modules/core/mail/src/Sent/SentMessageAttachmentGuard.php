<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Sent;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Attachments\AttachmentGuard;

/**
 * Přílohy odeslané zprávy jsou součást jejího pevného obsahu (#90 D41):
 * nejde je smazat, přejmenovat, přeřadit ani k nim přidat další. Zpráva
 * je doklad o tom, co odešlo — a Odeslat znovu posílá právě tyto soubory.
 *
 * Služba odeslání přílohy zprávy zakládá přes `AttachmentService` bez
 * guardů, takže ji tohle nezastaví.
 */
final class SentMessageAttachmentGuard implements AttachmentGuard
{
    public function __construct(DataSourceConnection $db) {}

    public function refuse(array $attachment, string $operation): ?string
    {
        return match ($operation) {
            self::OPERATION_UPLOAD => 'K odeslané zprávě nelze přidávat přílohy — její obsah je pevný.',
            self::OPERATION_DELETE => 'Přílohy odeslané zprávy nelze smazat — jsou dokladem o tom, co odešlo.',
            default                => 'Přílohy odeslané zprávy nelze měnit — její obsah je pevný.',
        };
    }
}

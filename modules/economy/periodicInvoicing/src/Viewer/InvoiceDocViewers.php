<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\PeriodicInvoicing\Viewer;

/**
 * Viewer, který otevírá doklad vystavený periodickou fakturací — podle
 * typu dokladu (faktura vydaná, zálohová faktura vydaná), jinak souhrnný
 * viewer dokladů. Čte ho záložka Fakturace (odkaz z období) i upozornění
 * na koncepty ke kontrole.
 */
final class InvoiceDocViewers
{
    public const DOCUMENTS_VIEWER = 'docs.core.heads';

    /** Typ dokladu → jeho viewer. */
    public const DOC_TYPE_VIEWERS = [
        'invno' => 'docs.invoicesOut.heads',
        'invpo' => 'docs.proformasOut.heads',
    ];

    public static function viewerFor(string $docType): string
    {
        return self::DOC_TYPE_VIEWERS[$docType] ?? self::DOCUMENTS_VIEWER;
    }
}

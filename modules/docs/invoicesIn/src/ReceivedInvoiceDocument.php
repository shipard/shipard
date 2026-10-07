<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\InvoicesIn;

use Shipard\Module\Docs\Core\ReceivedInvoiceDocumentBase;

/**
 * Received invoice (FPB) — `doc_type = 'invni'`.
 *
 * Veškerá logika je v `ReceivedInvoiceDocumentBase` (docs.core, #106 D1):
 * doporučení bankovního spojení dodavatele při Potvrzení (warning, uložení
 * projde) sdílí se Zálohovou fakturou přijatou. Tahle třída je rozšiřovací
 * bod pro pravidla jen pro FPB.
 */
class ReceivedInvoiceDocument extends ReceivedInvoiceDocumentBase
{
    // Empty body — pure inheritance.
}

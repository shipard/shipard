<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\ProformasIn;

use Shipard\Module\Docs\Core\ReceivedInvoiceDocumentBase;

/**
 * Zálohová faktura přijatá (FPZ) — `doc_type = 'invpi'` (#106 D1).
 *
 * Výzva dodavatele k platbě předem: není daňový doklad
 * (`docTypes[].tax_document: false`). DUZP/DPPD a období DPH nuluje
 * `DocDocument` a economy.vat podle atributu typu, ne tahle třída.
 * Doporučení bankovního spojení dodavatele při Potvrzení (warning
 * `partner_bank_recommended`) dědí z `ReceivedInvoiceDocumentBase` —
 * výzva je hlavně podklad k platbě. Účtování na podrozvahu a saldokonto:
 * `tasks/doc-proforma-in.md` (#106 D2).
 */
class ProformaInDocument extends ReceivedInvoiceDocumentBase
{
    // Empty body — pure inheritance.
}

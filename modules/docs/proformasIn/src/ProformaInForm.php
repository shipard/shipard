<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\ProformasIn;

use Shipard\Module\Docs\Core\ReceivedInvoiceFormBase;

/**
 * Editační formulář pro Zálohové faktury přijaté (FPZ) — `doc_type = 'invpi'`
 * (#106 D1).
 *
 * Layout hlavičky a tab „Nastavení" sdílí s FPB přes `ReceivedInvoiceFormBase`
 * (docs.core). Nedaňový charakter (bez DUZP/DPPD, bez ručního zařazení do
 * KH) řídí base podle `docTypes[].tax_document`, ne tahle třída. Tady jen
 * titulky modalu a header-info hooky.
 */
class ProformaInForm extends ReceivedInvoiceFormBase
{
    protected function getFormTitle(): string
    {
        return 'Zálohová faktura přijatá';
    }

    protected function getNewFormTitle(): string
    {
        return 'Nová zálohová faktura přijatá';
    }

    protected function getDocTypeLabel(): string
    {
        return 'Zálohová faktura přijatá';
    }

    protected function getHeaderIcon(): ?string
    {
        // Jeden klíč pro viewer, hlavičku formuláře i detail (icons.js).
        return 'invoice-proforma-in';
    }
}

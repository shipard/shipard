<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\ProformasOut;

use Shipard\Module\Docs\Core\IssuedInvoiceFormBase;

/**
 * Editační formulář pro Zálohové faktury vydané (FVZ) — `doc_type = 'invpo'`
 * (#79 D1).
 *
 * Layout hlavičky a tab „Nastavení" sdílí s FVB přes `IssuedInvoiceFormBase`
 * (docs.core). Nedaňový charakter (bez DUZP, bez ručního zařazení do KH)
 * řídí base podle `docTypes[].tax_document`, ne tahle třída. Tady jen
 * titulky modalu a header-info hooky.
 */
class ProformaOutForm extends IssuedInvoiceFormBase
{
    protected function getFormTitle(): string
    {
        return 'Zálohová faktura vydaná';
    }

    protected function getNewFormTitle(): string
    {
        return 'Nová zálohová faktura vydaná';
    }

    protected function getDocTypeLabel(): string
    {
        return 'Zálohová faktura';
    }

    protected function getHeaderIcon(): ?string
    {
        // Jeden klíč pro viewer, hlavičku formuláře i detail (icons.js).
        return 'invoice-proforma';
    }
}

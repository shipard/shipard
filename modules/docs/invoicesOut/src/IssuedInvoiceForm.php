<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\InvoicesOut;

use Shipard\Module\Docs\Core\IssuedInvoiceFormBase;

/**
 * Editační formulář pro Faktury vydané (FVB) — `doc_type = 'invno'`.
 *
 * Layout hlavičky (2 sloupce bez separátorů) a tab „Nastavení" sdílí se
 * zálohovou fakturou vydanou (`ProformaOutForm`) v `IssuedInvoiceFormBase`
 * (docs.core). Tady jen titulky modalu a header-info hooky
 * (`getDocTypeLabel`, `getHeaderIcon`); partner = odběratel řeší base.
 *
 * Slouží jako rozšiřovací bod pro další FVB-specifické změny formuláře
 * (splátkový kalendář, výzva k úhradě, AI checks atd.).
 */
class IssuedInvoiceForm extends IssuedInvoiceFormBase
{
    protected function getFormTitle(): string
    {
        return 'Faktura vydaná';
    }

    protected function getNewFormTitle(): string
    {
        return 'Nová faktura vydaná';
    }

    protected function getDocTypeLabel(): string
    {
        return 'Vydaná faktura';
    }

    protected function getHeaderIcon(): ?string
    {
        return 'invoice';
    }
}

<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\InvoicesIn;

use Shipard\Module\Docs\Core\ReceivedInvoiceFormBase;

/**
 * Editační formulář pro Faktury přijaté (FPB) — `doc_type = 'invni'`.
 *
 * Layout hlavičky a tab „Nastavení" sdílí se Zálohovou fakturou přijatou
 * přes `ReceivedInvoiceFormBase` (docs.core, #106 D1). Tady jen titulky
 * modalu a header-info hooky (`getDocTypeLabel`, `getHeaderIcon`); FPB
 * drží `supplier_snapshot` defaultní.
 *
 * Slouží jako rozšiřovací bod pro další FPB-specifické změny formuláře
 * (schvalovací workflow, vazba na příchozí poštu, AI extrakce,
 * DPH-PDP-specifické přepínače atd.).
 */
class ReceivedInvoiceForm extends ReceivedInvoiceFormBase
{
    protected function getFormTitle(): string
    {
        return 'Faktura přijatá';
    }

    protected function getNewFormTitle(): string
    {
        return 'Nová faktura přijatá';
    }

    protected function getDocTypeLabel(): string
    {
        return 'Přijatá faktura';
    }

    protected function getHeaderIcon(): ?string
    {
        return 'invoice-in';
    }
}

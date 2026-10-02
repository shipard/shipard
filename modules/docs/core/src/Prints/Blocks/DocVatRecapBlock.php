<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core\Prints\Blocks;

use Shipard\Module\Docs\Core\Prints\DocPrintContext;

/**
 * Bloky `vatRecap` a `vatNotes` — rekapitulace DPH dokladu a poznámky
 * ke kódům DPH (každá jednou). Druhá strana reverse charge páru se
 * netiskne; neplátce a doklad bez DPH rekapitulaci nemají. Hodnoty
 * v domácí měně jen u dokladu v cizí měně.
 *
 * Musí běžet až po bloku řádků — poznámky sbírá ze značek přidělených
 * řádkům i rekapitulaci.
 */
final class DocVatRecapBlock implements DocPrintBlock
{
    public function build(DocPrintContext $context): array
    {
        $recap = [];
        if ($context->showsVat()) {
            $foreign = $context->foreignCurrency();
            foreach ($context->recap as $row) {
                if (!empty($row['is_reverse_pair'])) {
                    continue;
                }
                $code = (string) ($row['vat_code'] ?? '');
                $recap[] = [
                    'label'    => $context->vatCodes->label($code),
                    'pct'      => DocPrintContext::number($row['vat_pct'] ?? null),
                    'base'     => DocPrintContext::number($row['base'] ?? null),
                    'tax'      => DocPrintContext::number($row['tax'] ?? null),
                    'total'    => DocPrintContext::number($row['total'] ?? null),
                    'baseDom'  => $foreign ? DocPrintContext::number($row['base_dom'] ?? null) : null,
                    'taxDom'   => $foreign ? DocPrintContext::number($row['tax_dom'] ?? null) : null,
                    'totalDom' => $foreign ? DocPrintContext::number($row['total_dom'] ?? null) : null,
                    'noteMark' => $context->vatCodes->noteMark($code),
                ];
            }
        }

        return [
            'vatRecap' => $recap,
            'vatNotes' => $context->vatCodes->notes(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core\Prints\Blocks;

use Shipard\Module\Docs\Core\Prints\DocPrintContext;

/**
 * Blok `totals` — součty hlavičky. `total` je částka dokladu už po odpočtu
 * záloh, `totalBeforeAdvances` ji o odpočty vrací zpět. Hodnoty v domácí
 * měně jen u dokladu v cizí měně.
 */
final class DocTotalsBlock implements DocPrintBlock
{
    public function build(DocPrintContext $context): array
    {
        $head     = $context->head;
        $foreign  = $context->foreignCurrency();
        $total    = (float) ($head['total_amount'] ?? 0.0);
        $advances = DocAdvancesBlock::sum($context);

        return ['totals' => [
            'base'                => (float) ($head['total_base'] ?? 0.0),
            'vat'                 => (float) ($head['total_vat'] ?? 0.0),
            'rounding'            => (float) ($head['total_rounding'] ?? 0.0),
            'total'               => $total,
            'totalBeforeAdvances' => round($total + ($advances['total'] ?? 0.0), 2),
            'baseDom'             => $foreign ? DocPrintContext::number($head['total_base_dom'] ?? null) : null,
            'vatDom'              => $foreign ? DocPrintContext::number($head['total_vat_dom'] ?? null) : null,
            'totalDom'            => $foreign ? DocPrintContext::number($head['total_amount_dom'] ?? null) : null,
        ]];
    }
}

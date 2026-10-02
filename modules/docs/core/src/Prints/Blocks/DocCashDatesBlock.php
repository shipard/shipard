<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core\Prints\Blocks;

use Shipard\Module\Docs\Core\Prints\DocPrintContext;

/**
 * Blok `dates` pokladního dokladu — data dokladu a navíc
 * `paymentReceived`: den přijetí platby na příjmovém dokladu s DPH (může
 * předcházet DUZP). Na výdeji a u dokladu bez DPH je null, stejně jako ho
 * tam nenabízí formulář.
 */
final class DocCashDatesBlock implements DocPrintBlock
{
    public function build(DocPrintContext $context): array
    {
        $dates = (new DocDatesBlock())->build($context)['dates'];
        $dates['paymentReceived'] = $context->tradeDir() === 1 && $context->showsVat()
            ? DocPrintContext::date($context->head['vat_dppd'] ?? null)
            : null;

        return ['dates' => $dates];
    }
}

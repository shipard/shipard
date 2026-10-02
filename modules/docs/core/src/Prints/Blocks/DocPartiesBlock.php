<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core\Prints\Blocks;

use Shipard\Module\Docs\Core\Prints\DocPrintContext;

/**
 * Bloky `supplier` a `customer` — snapshoty stran z hlavičky beze změny
 * tvaru (#90 D5, D14): tisk ukazuje stav k vystavení, ne dnešní adresář.
 */
final class DocPartiesBlock implements DocPrintBlock
{
    public function build(DocPrintContext $context): array
    {
        return [
            'supplier' => $context->supplier,
            'customer' => $context->customer,
        ];
    }
}

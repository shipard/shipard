<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core\Prints;

use Shipard\Module\Docs\Core\Prints\Blocks\DocCashDatesBlock;
use Shipard\Module\Docs\Core\Prints\Blocks\DocCashDeskBlock;
use Shipard\Module\Docs\Core\Prints\Blocks\DocDatesBlock;
use Shipard\Module\Docs\Core\Prints\Blocks\DocPrintBlock;

/**
 * Data tisku pokladního dokladu (`cash`, #90 D25): bloky dokladu, data
 * s dnem přijetí platby a pokladna. Partner je nepovinný — chybějící strana
 * je `null` (D24). Žije v docs.core vedle `CashDeskDocumentBase`, ať moduly
 * pokladních dokladů a prodejek zůstanou na sobě nezávislé.
 */
class CashDocPrintBuilder extends DocPrintBuilder
{
    protected function blocks(): array
    {
        $blocks = array_map(
            static fn (DocPrintBlock $block): DocPrintBlock => $block instanceof DocDatesBlock
                ? new DocCashDatesBlock()
                : $block,
            parent::blocks(),
        );
        $blocks[] = new DocCashDeskBlock();

        return $blocks;
    }
}

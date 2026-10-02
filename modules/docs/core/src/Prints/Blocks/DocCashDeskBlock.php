<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core\Prints\Blocks;

use Shipard\Module\Docs\Core\Prints\DocPrintContext;

/**
 * Blok `cashDesk` — pokladna dokladu vázaného na pokladnu (pokladní doklad,
 * prodejka; #90 D25, D26). Aktuální data číselníku, ne snapshot (D14).
 */
final class DocCashDeskBlock implements DocPrintBlock
{
    public function build(DocPrintContext $context): array
    {
        $desk = $context->cashDesk;

        return ['cashDesk' => $desk === null ? null : [
            'id'   => (int) $desk['id'],
            'code' => (string) ($desk['code'] ?? ''),
            'name' => (string) ($desk['name'] ?? ''),
        ]];
    }
}

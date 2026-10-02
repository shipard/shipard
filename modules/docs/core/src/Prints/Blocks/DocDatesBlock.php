<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core\Prints\Blocks;

use Shipard\Module\Docs\Core\Prints\DocPrintContext;

/** Blok `dates` — data dokladu v ISO; DUZP jen u daňového dokladu. */
final class DocDatesBlock implements DocPrintBlock
{
    public function build(DocPrintContext $context): array
    {
        $head = $context->head;

        return ['dates' => [
            'issue'      => DocPrintContext::date($head['issue_date'] ?? null),
            'due'        => DocPrintContext::date($head['due_date'] ?? null),
            'duzp'       => $context->isTaxDocument() ? DocPrintContext::date($head['vat_duzp'] ?? null) : null,
            'periodFrom' => DocPrintContext::date($head['period_from'] ?? null),
            'periodTo'   => DocPrintContext::date($head['period_to'] ?? null),
        ]];
    }
}

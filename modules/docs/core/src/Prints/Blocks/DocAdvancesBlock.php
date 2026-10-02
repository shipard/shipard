<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core\Prints\Blocks;

use Shipard\Module\Docs\Core\Prints\DocPrintContext;

/**
 * Blok `advances` — souhrn odpočtů záloh jako kladná čísla (#90 D18);
 * null, když doklad žádný odpočet nemá. Řádky odpočtů jsou na dokladu
 * záporné a zůstávají v `rows`.
 */
final class DocAdvancesBlock implements DocPrintBlock
{
    public function build(DocPrintContext $context): array
    {
        return ['advances' => self::sum($context)];
    }

    /** @return array{base: float, vat: float, total: float}|null */
    public static function sum(DocPrintContext $context): ?array
    {
        $found = false;
        $base = $vat = $total = 0.0;
        foreach ($context->rows as $row) {
            if (!$context->isAdvanceDeduction($row)) {
                continue;
            }
            $found = true;
            $base  -= (float) ($row['vat_base'] ?? 0.0);
            $vat   -= (float) ($row['vat_amount'] ?? 0.0);
            $total -= (float) ($row['vat_total'] ?? 0.0);
        }
        if (!$found) {
            return null;
        }
        return ['base' => round($base, 2), 'vat' => round($vat, 2), 'total' => round($total, 2)];
    }
}

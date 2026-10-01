<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets\Reports;

use Shipard\Module\Economy\Assets\Depreciation\AssetEvent;
use Shipard\Module\Economy\Assets\Depreciation\Plan;

/**
 * Pohled na plán jednoho okruhu za období od–do (typicky účetní rok):
 * stav na začátku, odpis období a stav na konci. Počítá z řádků plánu —
 * potvrzených i plánovaných — takže neodepsaný rok ukazuje plán a příznak
 * `planned`.
 *
 * Odpis patří do období podle konce svého období (`period.end`), ostatní
 * události podle data. `depreciation` je **uplatněný** odpis: řádky
 * s příznakem „uplatněná částka neevidována“ (D11) do něj nevstupují
 * (`unrecorded`), oprávky ale snižují — `closing` proto nemusí být
 * `opening + depreciation`.
 *
 * Po vyřazení v období je `residual` nula; `entryPrice` a `closing` drží
 * stav těsně před vyřazením.
 */
final readonly class CircuitYear
{
    public function __construct(
        /** Vstupní cena na konci období (u vyřazené před vyřazením). */
        public float $entryPrice,
        /** Oprávky na začátku období. */
        public float $opening,
        /** Uplatněný odpis období (potvrzený i plánovaný). */
        public float $depreciation,
        /** Odpis období s neevidovanou uplatněnou částkou (import, D11). */
        public float $unrecorded,
        /** Oprávky na konci období. */
        public float $closing,
        /** Zůstatková cena na konci období. */
        public float $residual,
        /** V období je odpis, který je zatím jen plán. */
        public bool $planned,
        /** Datum zařazení / počátečního stavu okruhu; null = okruh nezačal. */
        public ?string $startDate,
        public ?string $disposalDate,
    ) {
    }

    public static function of(Plan $plan, string $begin, string $end): self
    {
        $entryPrice = 0.0;
        $opening = 0.0;
        $closing = 0.0;
        $residual = 0.0;
        $depreciation = 0.0;
        $unrecorded = 0.0;
        $planned = false;
        $startDate = null;
        $disposalDate = null;

        foreach ($plan->rows as $row) {
            if ($row->kind === AssetEvent::KIND_ACTIVATION || $row->kind === AssetEvent::KIND_OPENING) {
                $startDate ??= $row->date;
            }
            if ($row->kind === AssetEvent::KIND_DISPOSAL) {
                $disposalDate ??= $row->date;
            }

            $date = $row->isDepreciation() ? ($row->period?->end ?? $row->date) : $row->date;
            // Počáteční stav k prvnímu dni období je stav na jeho začátku.
            if ($date < $begin || ($row->kind === AssetEvent::KIND_OPENING && $date === $begin)) {
                $opening = $row->accumulated;
            }
            if ($date > $end) {
                continue;
            }
            $entryPrice = $row->entryPrice;
            $closing = $row->accumulated;
            $residual = $row->residual;

            if ($row->isDepreciation() && $date >= $begin) {
                if ($row->claimUnrecorded) {
                    $unrecorded += $row->amount;
                } else {
                    $depreciation += $row->amount;
                }
                $planned = $planned || $row->isPlanned();
            }
        }

        return new self(
            round($entryPrice, 2),
            round($opening, 2),
            round($depreciation, 2),
            round($unrecorded, 2),
            round($closing, 2),
            round($residual, 2),
            $planned,
            $startDate,
            $disposalDate,
        );
    }
}

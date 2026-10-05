<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Document;

/**
 * Deterministická derivace režimu výpočtu DPH (`vat_mode`) z poměru mezi
 * součtem položkových řádků a rekapitulací DPH. AI extraktory u dokladů
 * s koncovými cenami (účtenky, PHM, maloobchod) vracejí `vat.mode:
 * "fromBase"`, ačkoli `rows[].totalPrice` už daň obsahuje — DocDocument
 * pak daň počítá podruhé. Sedí-li součet řádků právě na total (a ne na
 * base), jsou řádky prokazatelně v cenách s DPH bez ohledu na to, co AI
 * deklarovala. Viz tasks/docs-vat-mode-derivation.md.
 *
 * Sdílí ji DocumentApplier (korekce `vat_mode` + issue `vat_mode_derived`)
 * a DocumentValidator (warning `vat_mode_suspect` jen když derivace nemá
 * dost dat — jinak by warning dubloval provedenou korekci).
 *
 * Čistá funkce nad canonicalem — canonical (vč. `totals`) zůstává
 * forenzně nedotčený, koriguje se až interní `vat_mode` hlavičky.
 */
final class VatModeDerivation
{
    /**
     * Odvodí `vat_mode` (1 = fromBase/zdola, 2 = fromTotal/shora), nebo
     * `null`, když data derivaci neumožňují:
     *
     *   - chybí položkové řádky s číselným totalPrice (nebo je některý
     *     bez něj — neúplný součet je pro rozhodnutí o modu nespolehlivý),
     *   - chybí reference (kompletní vatRecap ani totals),
     *   - refBase ≈ refTotal (0% sazby / osvobozeno — oba režimy dají
     *     stejná čísla; přirozeně vyřazuje i noPayTax samovyměření,
     *     kde se placené base a total rovnají),
     *   - součet řádků sedí na obě reference, nebo na žádnou.
     *
     * @param array<string, mixed> $canonical
     */
    public static function derive(array $canonical): ?int
    {
        $rowSum = self::sumItemRows($canonical['rows'] ?? null);
        if ($rowSum === null) {
            return null;
        }

        $refs = self::references($canonical);
        if ($refs === null) {
            return null;
        }
        [$refBase, $refTotal, $rowCount] = [$refs[0], $refs[1], $refs[2]];

        if (abs($refTotal - $refBase) < 1.00) {
            return null;
        }

        $eps = self::tolerance($rowCount);
        $matchesBase = abs($rowSum - $refBase) <= $eps;
        $matchesTotal = abs($rowSum - $refTotal) <= $eps;

        if ($matchesTotal && !$matchesBase) {
            return 2;
        }
        if ($matchesBase && !$matchesTotal) {
            return 1;
        }
        return null;
    }

    /**
     * Σ `totalPrice` položkových řádků (`rowKind` item, bez kontace).
     * Jakýkoli položkový řádek bez číselného totalPrice → null.
     */
    public static function sumItemRows(mixed $rows): ?float
    {
        if (!is_array($rows)) {
            return null;
        }
        $sum = 0.0;
        $count = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ((string) ($row['rowKind'] ?? 'item') !== 'item' || isset($row['accSide'])) {
                continue;
            }
            if (!isset($row['totalPrice']) || !is_numeric($row['totalPrice'])) {
                return null;
            }
            $sum += (float) $row['totalPrice'];
            $count++;
        }
        return $count > 0 ? round($sum, 2) : null;
    }

    public static function tolerance(int $rowCount): float
    {
        return max(0.02, 0.01 * $rowCount);
    }

    /**
     * Rekapitulace, o kterou se dá opřít: neprázdná a každý řádek nese
     * číselný `base` i `total`. Stejnou podmínku čte derivace režimu
     * i dorovnání cen řádků u neplátce DPH (DocumentApplier, #97 D5).
     */
    public static function recapIsComplete(mixed $vatRecap): bool
    {
        if (!is_array($vatRecap) || $vatRecap === []) {
            return false;
        }
        foreach ($vatRecap as $r) {
            if (!is_array($r)
                || !isset($r['base']) || !is_numeric($r['base'])
                || !isset($r['total']) || !is_numeric($r['total'])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Reference [refBase, refTotal, rowCount] — primárně z kompletního
     * vatRecap, fallback z totals (`totalAmount − totalRounding`, protože
     * zaokrouhlení celkové částky se řádků netýká).
     *
     * @param array<string, mixed> $canonical
     * @return array{0: float, 1: float, 2: int}|null
     */
    private static function references(array $canonical): ?array
    {
        $rowCount = 0;
        foreach ((array) ($canonical['rows'] ?? []) as $row) {
            if (is_array($row) && (string) ($row['rowKind'] ?? 'item') === 'item' && !isset($row['accSide'])) {
                $rowCount++;
            }
        }

        $vatRecap = $canonical['vatRecap'] ?? null;
        if (self::recapIsComplete($vatRecap)) {
            $base = 0.0;
            $total = 0.0;
            foreach ($vatRecap as $r) {
                $base += (float) $r['base'];
                $total += (float) $r['total'];
            }
            return [round($base, 2), round($total, 2), $rowCount];
        }

        $totals = $canonical['totals'] ?? null;
        if (is_array($totals)
            && isset($totals['totalBase']) && is_numeric($totals['totalBase'])
            && isset($totals['totalAmount']) && is_numeric($totals['totalAmount'])) {
            $rounding = isset($totals['totalRounding']) && is_numeric($totals['totalRounding'])
                ? (float) $totals['totalRounding']
                : 0.0;
            return [
                round((float) $totals['totalBase'], 2),
                round((float) $totals['totalAmount'] - $rounding, 2),
                $rowCount,
            ];
        }

        return null;
    }
}

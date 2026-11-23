<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Document\Interpret;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Core\Exchange\Document\RoundingModeNames;
use Shipard\Module\Docs\Core\RoundingModes;
use Shipard\Module\Docs\Core\TotalRoundingAuto;

/**
 * Zaokrouhlení částky dokladu z papírové podoby (#166 D19, D28): papír
 * mód zaokrouhlení neříká, interpretace ho odhaduje z čísel. Pokyn mód
 * nese výslovně (`totals.totalRoundingMode`) a nic neodhaduje.
 */
final class RoundingInterpretation
{
    /**
     * Příznak automatického zaokrouhlení dokladu z papíru (#73 D8, U9): jen
     * doklad, kde automatika platí ({@see TotalRoundingAuto::applies()}).
     * Výslovné `totals.totalRoundingAuto` vyhrává, jinak rozhoduje
     * {@see totalRoundingAuto()}.
     *
     * @param array<string, mixed> $canonical
     */
    public static function resolveAuto(?ConfigRuntime $config, array $canonical, string $docType, int $cashDir): bool
    {
        if (!TotalRoundingAuto::applies($config, $docType, $cashDir)) {
            return false;
        }
        $totals = $canonical['totals'] ?? null;
        $explicit = is_array($totals) ? ($totals['totalRoundingAuto'] ?? null) : null;
        return is_bool($explicit) ? $explicit : self::totalRoundingAuto($canonical);
    }

    /**
     * Mód zaokrouhlení dokladu z papíru: při automatice ho určí doklad
     * (null), jinak výslovný `totals.totalRoundingMode` (#166 D25, D28),
     * a bez něj odhad z čísel ({@see deriveTotalRoundingMode()}). Null bez
     * automatiky = platí výchozí mód (na haléře).
     *
     * @param array<string, mixed> $canonical
     * @param array<int, float> $grossTotals ceny řádků s daní dodavatele
     *        ({@see VatInterpretation::supplierVatGrossTotals()})
     */
    public static function resolveMode(array $canonical, bool $auto, array $grossTotals): ?int
    {
        if ($auto) {
            return null;
        }
        return RoundingModeNames::totalCode($canonical['totals']['totalRoundingMode'] ?? null)
            ?? self::deriveTotalRoundingMode(
                $canonical,
                $grossTotals !== [] ? round(array_sum($grossTotals), 2) : null,
            );
    }

    /**
     * Automatické zaokrouhlení (#73 D8) u dokladu z papíru, který ho
     * výslovně neříká: zapnuté právě tehdy, když doklad nemá deklarovanou
     * `totals.totalAmount`. Pošta i ISDOC částku nesou — zůstávají u módu
     * uvedeného, jinak odvozeného z čísel ({@see deriveTotalRoundingMode()}).
     * Zda automatika pro typ dokladu vůbec platí, rozhoduje volající.
     *
     * @param array<string, mixed> $canonical
     */
    public static function totalRoundingAuto(array $canonical): bool
    {
        $totals = $canonical['totals'] ?? null;
        return !is_array($totals) || !isset($totals['totalAmount']) || !is_numeric($totals['totalAmount']);
    }

    /**
     * Odvodí `total_rounding_mode` z rozdílu mezi spočtenou a deklarovanou
     * celkovou částkou. Konzervativně: mod se nastaví jen když se computed
     * a declared liší o >= 0,01 a < 1,00 a některý mod declared přesně
     * reprodukuje; jinak null (default 0, případný totals_mismatch warning
     * z validátoru zůstává v platnosti). Rozdíl přesně 0,01 je platné
     * zaokrouhlení (69,99 → 70,00; starý Shipard je má běžně) — podmínka
     * „declared = celé číslo reprodukované modem“ ho odliší od šumu v DPH.
     *
     * Computed se bere z nejautoritativnějšího dostupného zdroje:
     * Σ vatRecap[].total → totalBase + totalVat → Σ řádků s DPH per řádek.
     * Matematický mod (1) má u shodného výsledku přednost před směrovými
     * (3 = ceil, 4 = floor); mod 5 (matematicky na 0,05 — hotovost SK a část
     * eurozóny) se zkouší až za celými jednotkami, protože celá declared je
     * násobek 0,05 taky. DocDocument si pak total_amount/total_rounding
     * dopočte sám z řádků — tady se výpočet neduplikuje, jen se volí mod.
     *
     * U přijatého dokladu neplátce DPH (#97) je computed součet cen řádků
     * s daní, které na doklad skutečně jdou (`$rowsTotal`) — odhad z
     * canonicalu by u řádků v cenách s daní přičetl sazbu podruhé.
     *
     * @param array<string, mixed> $canonical
     * @param float|null $rowsTotal Σ {@see VatInterpretation::supplierVatGrossTotals()}; null = odvodit z canonicalu
     */
    public static function deriveTotalRoundingMode(array $canonical, ?float $rowsTotal = null): ?int
    {
        $totals = $canonical['totals'] ?? null;
        if (!is_array($totals) || !isset($totals['totalAmount']) || !is_numeric($totals['totalAmount'])) {
            return null;
        }
        $declared = round((float) $totals['totalAmount'], 2);

        $computed = $rowsTotal;

        // 1. Σ vatRecap[].total — jen když má total všechny řádky rekapitulace.
        $vatRecap = $canonical['vatRecap'] ?? null;
        if ($computed === null && is_array($vatRecap) && count($vatRecap) > 0) {
            $acc = 0.0;
            $complete = true;
            foreach ($vatRecap as $r) {
                if (!is_array($r) || !isset($r['total']) || !is_numeric($r['total'])) {
                    $complete = false;
                    break;
                }
                $acc += (float) $r['total'];
            }
            if ($complete) {
                $computed = round($acc, 2);
            }
        }

        // 2. totalBase + totalVat
        if ($computed === null
            && isset($totals['totalBase']) && is_numeric($totals['totalBase'])
            && isset($totals['totalVat']) && is_numeric($totals['totalVat'])) {
            $computed = round((float) $totals['totalBase'] + (float) $totals['totalVat'], 2);
        }

        // 3. Σ řádků: totalPrice × (1 + vat.pct/100) — jako validator, varianta 2.
        if ($computed === null) {
            $rows = $canonical['rows'] ?? null;
            if (is_array($rows)) {
                $acc = 0.0;
                $hasAny = false;
                foreach ($rows as $row) {
                    if (!is_array($row) || !isset($row['totalPrice']) || !is_numeric($row['totalPrice'])) {
                        continue;
                    }
                    $hasAny = true;
                    $pct = $row['vat']['pct'] ?? null;
                    $acc += (float) $row['totalPrice']
                        * ($pct !== null && is_numeric($pct) ? 1.0 + ((float) $pct) / 100.0 : 1.0);
                }
                if ($hasAny) {
                    $computed = round($acc, 2);
                }
            }
        }

        if ($computed === null) {
            return null;
        }

        $diff = abs($declared - $computed);
        if ($diff < 0.005 || $diff >= 1.00) {
            return null;
        }

        // Pořadí = priorita (viz docblok): 1 → 3 → 4 → 5. Kdyby se 0,05
        // zkoušelo před celými jednotkami, faktury na celé Kč by dostaly mod 5.
        $eps = 0.001;
        $candidates = [
            RoundingModes::MATH_UNIT,
            RoundingModes::UP_UNIT,
            RoundingModes::DOWN_UNIT,
            RoundingModes::MATH_FIVE_CENT,
        ];
        foreach ($candidates as $mode) {
            if (abs(RoundingModes::apply($computed, $mode) - $declared) <= $eps) {
                return $mode;
            }
        }
        return null;
    }
}

<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Document;

use Shipard\Module\Docs\Core\DocRowCalculator;

/**
 * Daň dodavatele v cenách řádků — převod cen přijatého dokladu, který
 * vznikne **Bez DPH**, ale dodavatel na něm daň účtuje: doklad neplátce DPH
 * (#97 D4, D5) a doklad mimo české DPH se zahraniční daní (#89 D2, D4).
 * Daň je pak součástí ceny pořízení: musí skončit v nákladech a v závazku,
 * ne zmizet.
 *
 * Čistá funkce nad canonicalem — kdy se převod použije, rozhoduje
 * {@see DocumentApplier} (kontext DPH dokladu); tady je jen predikát
 * „doklad nese daň dodavatele“ a výpočet cen. Jediné místo výpočtu pro
 * apply i náhled.
 */
final class SupplierVatPrices
{
    /**
     * Nese doklad daň dodavatele? Některý položkový řádek má kladnou sazbu
     * nebo nenulovou daň, případně ji nese rekapitulace. Jediný predikát
     * pro převod cen ({@see grossTotals()}), info `vat_non_payer`
     * i rozhodnutí o dokladu se zahraniční DPH — doklad bez daně zůstává,
     * jak přišel.
     *
     * @param array<string, mixed> $canonical
     */
    public static function carriesSupplierVat(array $canonical): bool
    {
        $nonZero = static fn (mixed $v): bool => $v !== null && is_numeric($v) && abs((float) $v) >= 0.005;
        $positive = static fn (mixed $v): bool => $v !== null && is_numeric($v) && (float) $v > 0.0;

        foreach ((array) ($canonical['rows'] ?? []) as $row) {
            if (!is_array($row)
                || (string) ($row['rowKind'] ?? 'item') !== 'item'
                || isset($row['accSide'])) {
                continue;
            }
            if ($positive($row['vat']['pct'] ?? null) || $nonZero($row['computed']['vatAmount'] ?? null)) {
                return true;
            }
        }
        foreach ((array) ($canonical['vatRecap'] ?? []) as $entry) {
            if (!is_array($entry) || ($entry['isReversePair'] ?? null) === true) {
                continue;
            }
            if ($positive($entry['vatPct'] ?? null) || $nonZero($entry['tax'] ?? null)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Ceny položkových řádků **včetně daně dodavatele**: index canonicalu →
     * cena, která skončí na řádku jako `total_price` (`price_calc_mode` 1,
     * bez slev — cena je už obsahuje).
     *
     * Do výsledku jdou **všechny** položkové řádky (ne kontační — ty účtují
     * částku přímo), aby součet řádků dokladu byl přesně součet vrácených
     * cen.
     *
     * Cena řádku (D4), první dostupné:
     *  1. `computed.vatTotal` od dodavatele (ISDOC, případně AI),
     *  2. řádky už v cenách s daní (`$pricesWithVat`) → cena řádku beze změny,
     *  3. cena řádku × (1 + sazba / 100), na 2 místa; řádek bez sazby
     *     nebo s 0 % beze změny.
     *
     * Dorovnání (D5): při úplné rekapitulaci
     * ({@see VatModeDerivation::recapIsComplete()}) se řádky každé sazby
     * srovnají na `vatRecap[].total` té sazby — rozdíl do tolerance
     * zaokrouhlení jde na řádek s největší absolutní částkou. Doklad pak
     * sedí na částku k úhradě i v haléřích. Větší rozdíl se nedorovnává
     * (řádky jsou neúplné; náhled ho ukáže jako `computed_total_mismatch`).
     *
     * @param array<string, mixed> $canonical
     * @param bool $pricesWithVat Řádky jsou už v cenách s daní (režim dokladu fromTotal).
     * @param array<string, mixed> $rowOperations cfgItem `docs.core.rowOperations` —
     *        operace s vlajkou `rowSide` je kontační řádek.
     * @return array<int, float>
     */
    public static function grossTotals(array $canonical, bool $pricesWithVat, array $rowOperations): array
    {
        $gross = [];
        $rateOf = [];
        foreach ((array) ($canonical['rows'] ?? []) as $idx => $row) {
            if (!is_array($row)
                || (string) ($row['rowKind'] ?? 'item') !== 'item'
                || isset($row['accSide'])
                || isset($rowOperations[(string) ($row['operation'] ?? '')]['rowSide'])) {
                continue;
            }
            $pct = isset($row['vat']['pct']) && is_numeric($row['vat']['pct']) ? (float) $row['vat']['pct'] : 0.0;
            $vatTotal = $row['computed']['vatTotal'] ?? null;
            if ($vatTotal !== null && is_numeric($vatTotal)) {
                $value = round((float) $vatTotal, 2);
            } else {
                $net = self::rowNetTotal($row);
                if ($net === null) {
                    continue;
                }
                $value = $pricesWithVat || $pct <= 0.0 ? $net : round($net * (1.0 + $pct / 100.0), 2);
            }
            $gross[(int) $idx] = $value;
            $rateOf[(int) $idx] = self::rateKey($pct);
        }

        $recap = $canonical['vatRecap'] ?? null;
        if (!VatModeDerivation::recapIsComplete($recap)) {
            return $gross;
        }
        $recapByRate = [];
        foreach ($recap as $entry) {
            if (($entry['isReversePair'] ?? null) === true) {
                continue;
            }
            $key = self::rateKey($entry['vatPct'] ?? null);
            $recapByRate[$key] = ($recapByRate[$key] ?? 0.0) + (float) $entry['total'];
        }
        foreach ($recapByRate as $key => $recapTotal) {
            $indices = array_keys($rateOf, $key, true);
            if ($indices === []) {
                continue;
            }
            $sum = 0.0;
            $target = $indices[0];
            foreach ($indices as $i) {
                $sum += $gross[$i];
                if (abs($gross[$i]) > abs($gross[$target])) {
                    $target = $i;
                }
            }
            $diff = round($recapTotal - $sum, 2);
            if (abs($diff) < 0.005 || abs($diff) > VatModeDerivation::tolerance(count($indices)) + 1e-9) {
                continue;
            }
            $gross[$target] = round($gross[$target] + $diff, 2);
        }
        return $gross;
    }

    /**
     * Cena položkového řádku canonicalu po slevě, bez přičtení daně — vstup
     * převodu cen ({@see grossTotals()}).
     *
     * Bez slevy je to `totalPrice` (částka řádku z dokladu dodavatele);
     * chybí-li, množství × jednotková cena — jinak by se řádek nepřevedl
     * a daň by se u něj ztratila dál.
     *
     * Se slevou rozhoduje `priceCalcMode`, stejně jako u dokladu plátce
     * ({@see DocRowCalculator::computePrice()}): z ceny za jednotku se
     * sleva odečítá od množství × jednotkové ceny — `totalPrice` z dokladu
     * dodavatele slevu zpravidla už obsahuje a odečetla by se podruhé.
     *
     * @param array<string, mixed> $row
     */
    private static function rowNetTotal(array $row): ?float
    {
        $num = static fn (mixed $v): ?float => $v !== null && is_numeric($v) ? (float) $v : null;
        $quantity = $num($row['quantity'] ?? null);
        $unitPrice = $num($row['unitPrice'] ?? null);
        $total = $num($row['totalPrice'] ?? null);
        $fromUnit = $quantity !== null && $unitPrice !== null ? round($quantity * $unitPrice, 2) : null;

        $discounted = !empty($row['discountPct']) || !empty($row['discountAmount']);
        // Jen `fromTotal` je cena celkem; chybějící i neznámá hodnota = cena
        // za jednotku (stejně jako mapa applieru).
        $unitMode = (string) ($row['priceCalcMode'] ?? 'fromUnitPrice') !== 'fromTotal';
        $base = $discounted && $unitMode ? ($fromUnit ?? $total) : ($total ?? $fromUnit);
        if ($base === null) {
            return null;
        }
        if (!$discounted) {
            return round($base, 2);
        }
        return DocRowCalculator::computePrice([
            'row_kind'        => 1,
            'price_calc_mode' => 1,
            'quantity'        => $quantity,
            'total_price'     => $base,
            'discount_pct'    => $row['discountPct'] ?? null,
            'discount_amount' => $row['discountAmount'] ?? null,
        ])['net_total'];
    }

    /** Sazba jako klíč skupiny — `21`, `21.0` i `"21"` jsou tatáž sazba. */
    private static function rateKey(mixed $pct): string
    {
        return number_format($pct !== null && is_numeric($pct) ? (float) $pct : 0.0, 2, '.', '');
    }
}

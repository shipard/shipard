<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

/**
 * Čistý výpočet ceny a DPH jednoho řádku dokladu (issue #71). Sdílí ho save
 * cesta (`DocDocument::calculateRowPrice` / `calculateRowVat`) a živý přepočet
 * v modalu řádku (`DocRowsForm::applyLiveCalculation`), takže formulář ukazuje
 * přesně to, co se při uložení spočítá. Bez stavu, bez DB, bez configu —
 * vstupem je pole řádku tak, jak přijde z klienta (stringy) nebo z DB.
 *
 * Proč `computePrice()` vrací `total_price` PŘED slevou a `net_total` PO ní:
 * v DB je `total_price` cena před slevou — sleva je na řádku samostatná
 * informace (`discount_pct` / `discount_amount`), ne přepis ceny. Save cesta
 * si `net_total` dosazuje do `total_price` jen dočasně pro DPH, rekapitulaci
 * a součty (`DocDocument::calculateRowPrice`); do DB z řádku jdou jen `vat_*`
 * (`persistRowComputedColumns`). Kdyby formulář zapsal do pole zlevněnou
 * hodnotu, uložila by se a při dalším přepočtu hlavičky by se sleva odečetla
 * podruhé (past P1 v `tasks/doc-row-live-calc.md`).
 */
final class DocRowCalculator
{
    /**
     * Cena řádku podle `price_calc_mode`:
     *  - 0 (z ceny za jednotku): `total_price = round(quantity × unit_price, 2)`,
     *    `unit_price` je vstup (normalizovaný na float, prázdný → null);
     *  - 1 (z celkové ceny): `unit_price = round(total_price / quantity, 4)`
     *    pro nenulové množství — i záporné (dobropis, vratka: −2 ks za −100
     *    celkem = 50 za kus, #98); množství 0 a prázdné → 0.0 (kontační
     *    řádky bez množství). `total_price` je vstup (normalizovaný stejně).
     * `net_total` = `total_price` po slevě: `discount_pct` má přednost před
     * `discount_amount`, zaokrouhlení na 2 — u procent dvoustupňově (nejdřív
     * částka slevy, pak rozdíl), shodně s původní save cestou. `empty()` na
     * slevě (a v computeVat na `vat_pct`) je záměr: "0" z klienta = žádná
     * sleva / daň.
     *
     * Řádek jiného druhu než položkový (`row_kind !== 1`) cenu nemá:
     * `unit_price` / `total_price` null, `net_total` 0.0.
     *
     * @param array<string, mixed> $row
     * @return array{unit_price: ?float, total_price: ?float, net_total: float}
     */
    public static function computePrice(array $row): array
    {
        if ((int) ($row['row_kind'] ?? 1) !== 1) {
            return ['unit_price' => null, 'total_price' => null, 'net_total' => 0.0];
        }

        $qty = (float) ($row['quantity'] ?? 0);
        $mode = (int) ($row['price_calc_mode'] ?? 0);

        if ($mode === 0) {
            $unitPrice = self::toNullableFloat($row['unit_price'] ?? null);
            $totalPrice = round($qty * ($unitPrice ?? 0.0), 2);
        } else {
            $totalPrice = self::toNullableFloat($row['total_price'] ?? null);
            $unitPrice = $qty !== 0.0 ? round(($totalPrice ?? 0.0) / $qty, 4) : 0.0;
        }

        $netTotal = $totalPrice ?? 0.0;
        if (!empty($row['discount_pct'])) {
            $discount = round($netTotal * ((float) $row['discount_pct']) / 100.0, 2);
            $netTotal = round($netTotal - $discount, 2);
        } elseif (!empty($row['discount_amount'])) {
            $netTotal = round($netTotal - (float) $row['discount_amount'], 2);
        }

        return ['unit_price' => $unitPrice, 'total_price' => $totalPrice, 'net_total' => $netTotal];
    }

    /**
     * Základ, daň a celkem řádku z ceny po slevě (`net_total` z computePrice):
     *  - `vatMode` 0 (doklad bez DPH), řádek bez kódu nebo bez sazby:
     *    základ = celkem = cena, daň 0;
     *  - noPayTax kód (tuzemská PDP, EU pořízení, osvobozená plnění): daň není
     *    součástí částky placené dodavateli, celá cena je základ i celkem —
     *    i pro vatMode 2, kde by zpětný rozpočet byl chybný. Vstupní
     *    samovyměření (`reverseVatCode`) nese spočtenou daň jako informativní
     *    nárok na odpočet; výstupní PDP / osvobozené = 0;
     *  - vatMode 1 (zezdola): cena je základ, daň = základ × pct;
     *  - vatMode 2 (shora): cena je celkem, základ = celkem / (1 + pct).
     * Řádek jiného druhu než položkový vrací trojici null.
     *
     * @param array<string, mixed> $row
     * @param array<string, array<string, mixed>>|null $vatCodes Definice DPH
     *        kódů země dokladu (`VatRateResolver::getVatCodes(…, direction: null,
     *        place: null, includeHidden: true)` — stejně jako
     *        `DocDocument::resolveVatCodesForDoc`); null = země/config
     *        nedohledány → výpočet bez sémantiky kódů.
     * @return array{vat_base: ?float, vat_amount: ?float, vat_total: ?float}
     */
    public static function computeVat(float $netTotal, array $row, int $vatMode, ?array $vatCodes): array
    {
        if ((int) ($row['row_kind'] ?? 1) !== 1) {
            return ['vat_base' => null, 'vat_amount' => null, 'vat_total' => null];
        }

        if ($vatMode === 0 || empty($row['vat_code']) || empty($row['vat_pct'])) {
            return ['vat_base' => $netTotal, 'vat_amount' => 0.0, 'vat_total' => $netTotal];
        }

        $pct = (float) $row['vat_pct'];

        $codeDef = $vatCodes[(string) $row['vat_code']] ?? null;
        if ($codeDef !== null && !empty($codeDef['noPayTax'])) {
            return [
                'vat_base'   => $netTotal,
                'vat_amount' => !empty($codeDef['reverseVatCode'])
                    ? round($netTotal * $pct / 100.0, 2)
                    : 0.0,
                'vat_total'  => $netTotal,
            ];
        }

        if ($vatMode === 1) {
            $amount = round($netTotal * $pct / 100.0, 2);
            return [
                'vat_base'   => $netTotal,
                'vat_amount' => $amount,
                'vat_total'  => round($netTotal + $amount, 2),
            ];
        }

        // vatMode 2 (i neznámé hodnoty — shodně s původní save cestou)
        $base = round($netTotal / (1.0 + $pct / 100.0), 2);
        return [
            'vat_base'   => $base,
            'vat_amount' => round($netTotal - $base, 2),
            'vat_total'  => $netTotal,
        ];
    }

    private static function toNullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (float) $value;
    }
}

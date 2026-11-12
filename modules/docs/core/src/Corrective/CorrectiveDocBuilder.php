<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core\Corrective;

use Shipard\Module\Core\Exchange\Dataset\ValueNormalizer as V;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;

/**
 * Canonical opravného dokladu z původního dokladu (#92 D6,
 * tasks/corrective-doc-exchange.md U6) — čistá funkce nad daty, databázi
 * čte {@see CorrectiveDocService}. Vzor InvoiceBuilder: hlavička i řádky se
 * skládají z databáze, nikdy přes DocumentExporter (ten nese applyOptions
 * datových sad — číslo a stav původního dokladu).
 *
 * Přebírá se typ dokladu, partner (pin `_resolve.customer`), měna a kurz,
 * režim DPH, místo plnění, registrace DPH, metoda výpočtu, způsob úhrady,
 * vlastní bankovní účet, dimenze a řada původního dokladu
 * (`applyOptions.numberSeriesId`). Nepřebírají se čísla a symboly, data
 * (vystavení = dnes, ostatní doplní dokument), text a poznámky, rekapitulace
 * ani součty (`totals` / `vatRecap` by vypnuly automatiku zaokrouhlení
 * a převzaly rekapitulaci původního dokladu), ruční zařazení do KH, přílohy.
 *
 * Řádky: textový beze změny; cenový řádek dá `net_total` s opačným
 * znaménkem — záporné množství a stejná jednotková cena, u řádku z celkové
 * ceny i záporná celková cena, sleva částkou s opačným znaménkem, sleva
 * procentem beze změny; řádek bez množství nese znaménko v ceně. Položky
 * pinem `_resolve.rows[].item`, kódy DPH se přebírají (sazbu určí applier
 * k datu původního plnění).
 */
final class CorrectiveDocBuilder
{
    public const SOURCE_KIND = 'correctiveDoc';
    public const FORMAT_VERSION = '1.0';

    private const ROW_KIND_NAMES = [0 => 'text', 1 => 'item', 2 => 'section'];

    /**
     * @param array<string, mixed>       $head           hlavička původního dokladu (docs_core_heads)
     *                                                   + `vat_reg_country` (země registrace DPH)
     * @param list<array<string, mixed>> $rows           řádky původního dokladu (docs_core_rows) + `item_code`,
     *                                                   `item_name`, `unit_code`, `unit_shortcut`, `dimensions`
     *                                                   (id dimenze → přirozený klíč)
     * @param array<string, mixed>|null  $customer       kanonická strana partnera (CanonicalParty), null = bez partnera
     * @param array<string, string>      $headDimensions id dimenze → přirozený klíč záznamu (hlavička)
     * @param string                     $today          datum vystavení `Y-m-d`
     * @return array<string, mixed> canonical `shpd.docs.document`
     */
    public static function build(array $head, array $rows, ?array $customer, array $headDimensions, string $today): array
    {
        $originalId = (int) ($head['id'] ?? 0);
        $partnerId = V::int($head['partner'] ?? null);

        $canonicalRows = [];
        $rowResolve = [];
        $index = 0;
        foreach ($rows as $row) {
            $canonicalRows[] = self::canonicalRow($row, $index + 1);
            $itemId = V::int($row['item'] ?? null);
            if ($itemId !== null && $itemId > 0) {
                $rowResolve[] = ['index' => $index, 'item' => ['userAction' => "useExisting:{$itemId}"]];
            }
            $index++;
        }

        $resolve = ['correction' => ['userAction' => "useExisting:{$originalId}"]];
        if ($partnerId !== null && $partnerId > 0) {
            $resolve['customer'] = ['userAction' => "useExisting:{$partnerId}"];
        }
        if ($rowResolve !== []) {
            $resolve['rows'] = $rowResolve;
        }

        $applyOptions = [
            'targetDocState' => 10,
            'numberSeriesId' => (int) ($head['number_series'] ?? 0),
        ];
        $bankAccount = V::int($head['bank_account'] ?? null);
        if ($bankAccount !== null) {
            $applyOptions['importOwnBankAccount'] = $bankAccount;
        }

        $canonical = [
            'format'        => DocumentApplier::FORMAT_ID,
            'formatVersion' => self::FORMAT_VERSION,
            'docType'       => DocumentApplier::canonicalDocType((string) ($head['doc_type'] ?? '')),
            'selfParty'     => 'supplier',
            'customer'      => $customer,
            // Jen datum vystavení — DUZP, DPPD, účetní datum a splatnost
            // doplní DocDocument::applyDateDefaults.
            'dates'         => ['issueDate' => $today],
            'currency'      => V::currencyUpper($head['doc_currency'] ?? null),
            'exchangeRate'  => V::float($head['exchange_rate'] ?? null),
            'vat'           => V::prune([
                'mode'                => DocumentApplier::canonicalVatMode((int) ($head['vat_mode'] ?? 1)),
                'place'               => DocumentApplier::canonicalVatPlace((int) ($head['vat_place'] ?? 0)),
                'registrationCountry' => V::countryLower($head['vat_reg_country'] ?? null),
                'calcSource'          => DocumentApplier::canonicalVatCalcSource((int) ($head['vat_calc_source'] ?? 0)),
            ]) ?: null,
            'payment'       => V::prune([
                'method' => DocumentApplier::canonicalPaymentMethod((int) ($head['payment_method'] ?? 1)),
            ]) ?: null,
            'dimensions'    => $headDimensions !== [] ? $headDimensions : null,
            // Číslo a datum plnění původního dokladu i tak přepíše applier
            // z odkazu; tady jsou pro případ, že by pin nešel použít.
            'correction'    => [
                'originalDocNumber'    => V::str($head['doc_number'] ?? null),
                'originalTaxPointDate' => V::date($head['vat_duzp'] ?? null) ?? V::date($head['issue_date'] ?? null),
                'reason'               => null,
            ],
            'source'        => ['kind' => self::SOURCE_KIND],
            'rows'          => $canonicalRows,
            '_resolve'      => $resolve,
            'applyOptions'  => $applyOptions,
        ];

        // Jen hlavičkové null klíče (customer bez partnera, kurz, dimenze…);
        // vnitřek bloku `correction` (reason null) zůstává.
        return array_filter($canonical, static fn (mixed $v): bool => $v !== null);
    }

    /**
     * @param array<string, mixed> $r řádek docs_core_rows s join sloupci
     * @return array<string, mixed>
     */
    private static function canonicalRow(array $r, int $orderPos): array
    {
        $kind = (int) ($r['row_kind'] ?? 1);
        $out = [
            'rowKind'  => self::ROW_KIND_NAMES[$kind] ?? 'item',
            'orderPos' => $orderPos,
        ];
        $operation = V::str($r['operation'] ?? null);
        if ($operation !== null) {
            $out['operation'] = $operation;
        }
        $description = V::str($r['description'] ?? null);
        if ($kind !== 1) {
            // Textový / sekční řádek beze změny — cenu nemá.
            if ($description !== null) {
                $out['description'] = $description;
            }
            return $out;
        }

        $itemCode = V::str($r['item_code'] ?? null);
        if ($itemCode !== null) {
            $out['item'] = ['ourCode' => $itemCode, 'name' => V::str($r['item_name'] ?? null)];
        }
        $unit = V::str($r['unit_code'] ?? null) ?? V::str($r['unit_shortcut'] ?? null);
        if ($unit !== null) {
            $out['unit'] = $unit;
        }

        $qty = V::float($r['quantity'] ?? null) ?? 0.0;
        $fromTotal = (int) ($r['price_calc_mode'] ?? 0) === 1;
        $unitPrice = V::float($r['unit_price'] ?? null) ?? 0.0;
        $totalPrice = V::float($r['total_price'] ?? null) ?? 0.0;
        // Řádek bez množství nese znaménko v ceně (DocRowCalculator: z celkové
        // ceny bere total_price, z jednotkové dá 0 — znaménko ceny vyjádří záměr).
        $flipPrice = $qty === 0.0;

        $out['quantity'] = self::negate($qty);
        $out['unitPrice'] = $flipPrice ? self::negate($unitPrice) : $unitPrice;
        if ($fromTotal) {
            $out['totalPrice'] = self::negate($totalPrice);
        }
        $out['priceCalcMode'] = $fromTotal ? 'fromTotal' : 'fromUnitPrice';

        $discountPct = V::float($r['discount_pct'] ?? null);
        if ($discountPct !== null && $discountPct !== 0.0) {
            $out['discountPct'] = $discountPct;
        }
        $discountAmount = V::float($r['discount_amount'] ?? null);
        if ($discountAmount !== null && $discountAmount !== 0.0) {
            // Sleva částkou se od ceny odečítá — u záporného řádku musí být záporná.
            $out['discountAmount'] = self::negate($discountAmount);
        }
        $vatCode = V::str($r['vat_code'] ?? null);
        if ($vatCode !== null) {
            // Jen kód — sazbu určí applier k datu původního plnění (U4).
            $out['vat'] = ['code' => $vatCode];
            // Opravný doklad přebírá předmět plnění původního řádku (#89 D10).
            $subject = V::str($r['reverse_charge_code'] ?? null);
            if ($subject !== null) {
                $out['vat']['reverseChargeCode'] = $subject;
            }
        }
        if ($description !== null) {
            $out['description'] = $description;
        }
        $dimensions = $r['dimensions'] ?? null;
        if (is_array($dimensions) && $dimensions !== []) {
            $out['dimensions'] = $dimensions;
        }
        return $out;
    }

    /** Opačné znaménko bez `-0.0`. */
    private static function negate(float $value): float
    {
        return $value === 0.0 ? 0.0 : -$value;
    }
}

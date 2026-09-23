<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Document;

use Shipard\Module\Docs\Core\RoundingModes;

/**
 * Semantic validator for a canonical document — runs **after** schema
 * validation and **before** resolve. Two kinds of checks:
 *
 *   - Required fields per `docType` (polymorphism the JSON schema
 *     intentionally does not enforce).
 *   - Totals coherence — declared vs. computed amounts; mismatch produces
 *     a warning, not an error, since the canonical's totals are
 *     informative and DocDocument::beforeSave recomputes them anyway.
 *
 * Returns the same `{severity, path, code, message}` shape as
 * {@see \Shipard\Module\Core\Exchange\Schema\SchemaValidator}. Issues
 * with `severity = error` block /apply; warnings only surface in
 * `_resolve.issues` and never block.
 */
final class DocumentValidator
{
    /**
     * @param array<string, mixed> $canonical
     * @return array<int, array{severity: string, path: string, code: string, message: string}>
     */
    public function validate(array $canonical): array
    {
        $issues = [];

        $this->checkCommonRequired($canonical, $issues);
        $this->checkPerDocType($canonical, $issues);
        $this->checkTotalsCoherence($canonical, $issues);
        $this->checkVatModeSuspect($canonical, $issues);
        $this->checkRowsVsRecap($canonical, $issues);
        $this->checkVatRecapArithmetic($canonical, $issues);
        $this->checkPartnerDocNumber($canonical, $issues);
        $this->checkParkingStateRequiresImport($canonical, $issues);

        return $issues;
    }

    /**
     * Stav 80 (V opravě) je výhradně parkovací cíl migrace (nezaúčtovatelné
     * doklady s číslem) — bez applyOptions.importNumber přes exchange
     * dosažitelný není.
     *
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    private function checkParkingStateRequiresImport(array $canonical, array &$issues): void
    {
        $options = $canonical['applyOptions'] ?? [];
        $targetState = $options['targetDocState'] ?? null;
        if ($targetState === null || (int) $targetState !== 80) {
            return;
        }
        if (!is_array($options['importNumber'] ?? null)) {
            $issues[] = [
                'severity' => 'error',
                'path'     => 'applyOptions.targetDocState',
                'code'     => 'target_state_80_requires_import',
                'message'  => 'targetDocState 80 (V opravě) je povolen jen s applyOptions.importNumber (migrace).',
            ];
        }
    }

    /**
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    private function checkCommonRequired(array $canonical, array &$issues): void
    {
        $dates = $canonical['dates'] ?? [];
        if (empty($dates['issueDate'])) {
            $issues[] = $this->required('dates.issueDate', 'Datum vystavení je povinné.');
        }

        if (empty($canonical['rows']) || !is_array($canonical['rows']) || count($canonical['rows']) === 0) {
            $issues[] = $this->required('rows', 'Doklad musí mít alespoň jeden řádek.');
        }
    }

    /**
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    private function checkPerDocType(array $canonical, array &$issues): void
    {
        $docType = (string) ($canonical['docType'] ?? '');
        switch ($docType) {
            case 'invoiceReceived':
                if (empty($canonical['supplier'])) {
                    $issues[] = $this->required('supplier', 'U přijaté faktury je dodavatel povinný.');
                }
                break;
            case 'invoiceIssued':
                if (empty($canonical['customer'])) {
                    $issues[] = $this->required('customer', 'U vydané faktury je odběratel povinný.');
                }
                break;
            // Zálohová faktura vydaná (#79 D1): strany jako u vydané faktury;
            // DUZP/DPPD a období DPH se ignorují (nedaňový doklad).
            case 'proformaIssued':
                if (empty($canonical['customer'])) {
                    $issues[] = $this->required('customer', 'U zálohové faktury vydané je odběratel povinný.');
                }
                break;
            // Pokladní doklad / prodejka (#59 D12): řada je vázaná na pokladnu,
            // proto je kód pokladny povinný; směr PD 1 příjem / 2 výdej.
            // Strany (supplier/customer) jsou nepovinné — anonymní doklady.
            case 'cashDocument':
                $this->checkCashDesk($canonical, $issues);
                $direction = $canonical['cashDirection'] ?? null;
                if (!in_array($direction, [1, 2], true)) {
                    $issues[] = [
                        'severity' => 'error',
                        'path'     => 'cashDirection',
                        'code'     => 'invalid_value',
                        'message'  => 'Směr pokladního dokladu musí být 1 (příjem) nebo 2 (výdej).',
                    ];
                }
                break;
            case 'cashRegisterDocument':
                $this->checkCashDesk($canonical, $issues);
                break;
            // Other docTypes (creditNote, order, deliveryNote, …) pick up the
            // common checks above; type-specific validation arrives with each
            // module that introduces them.
        }
    }

    /**
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    private function checkCashDesk(array $canonical, array &$issues): void
    {
        $code = $canonical['cashDesk'] ?? null;
        if (!is_string($code) || trim($code) === '') {
            $issues[] = $this->required('cashDesk', 'Kód pokladny je povinný — řada dokladu je vázaná na pokladnu.');
        }
    }

    /**
     * Cross-checks declared `totals.totalAmount` against three independent
     * variants the canonical may express. A warning fires only when *none*
     * of them lands within tolerance — AI extractors typically don't fill
     * `row.computed.vatTotal`, so the legacy "sum of row.totalPrice vs
     * total with VAT" check produced a false alarm on most VAT invoices.
     *
     * Variants:
     *   1. Sum of `row.totalPrice`                  — base only.
     *   2. Sum of `row.totalPrice * (1 + vat.pct)`  — base scaled per-row.
     *   3. Sum of `vatRecap[].total`                — most authoritative
     *      when available (per-rate breakdown with `total` = base + tax).
     *
     * Rounding-aware: a variant also passes when the declared amount is a
     * whole number less than 1.00 away from it — the invoice total was
     * rounded to whole currency units (DocumentApplier then derives
     * `total_rounding_mode` independently from the same numbers).
     *
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    private function checkTotalsCoherence(array $canonical, array &$issues): void
    {
        $totals = $canonical['totals'] ?? null;
        $rows = $canonical['rows'] ?? null;
        if (!is_array($totals) || !is_array($rows)) {
            return;
        }

        $declared = $totals['totalAmount'] ?? null;
        if ($declared === null) {
            return;
        }

        $declaredF = round((float) $declared, 2);

        // (1) base, (2) base × (1 + vat.pct/100)
        $sumBase = 0.0;
        $sumWithVat = 0.0;
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $rowBase = $row['totalPrice'] ?? null;
            if ($rowBase === null || !is_numeric($rowBase)) continue;
            $rowBaseF = (float) $rowBase;
            $sumBase += $rowBaseF;

            $pct = $row['vat']['pct'] ?? null;
            if ($pct !== null && is_numeric($pct)) {
                $sumWithVat += $rowBaseF * (1.0 + ((float) $pct) / 100.0);
            } else {
                $sumWithVat += $rowBaseF;
            }
        }
        $sumBase = round($sumBase, 2);
        $sumWithVat = round($sumWithVat, 2);

        // (3) vatRecap total
        $sumVatRecap = null;
        $vatRecap = $canonical['vatRecap'] ?? null;
        if (is_array($vatRecap) && count($vatRecap) > 0) {
            $acc = 0.0;
            $hasAny = false;
            foreach ($vatRecap as $r) {
                if (is_array($r) && isset($r['total']) && is_numeric($r['total'])) {
                    $acc += (float) $r['total'];
                    $hasAny = true;
                }
            }
            if ($hasAny) {
                $sumVatRecap = round($acc, 2);
            }
        }

        // Celá deklarovaná částka v pásmu < 1,00 od varianty je vždy její
        // floor/ceil/round podoba — pokrývá zaokrouhlení celkové částky
        // (total_rounding_mode 1/3/4) bez výčtu módů. Deklarovaná částka
        // rovná variantě zaokrouhlené na 0,05 pokrývá mód 5 (hotovost SK):
        // záměrně stejná podmínka, jakou má DocumentApplier::
        // deriveTotalRoundingMode, takže validátor mlčí právě tam, kde
        // applier mód přidělí (volnější pásmo < 0,05 by nechalo projít
        // rozdíly, které žádný mód nereprodukuje). Nezávisí na extrahovaném
        // totals.totalRounding.
        $tolerance = 0.01;
        $declaredIsWhole = abs($declaredF - round($declaredF, 0)) <= 0.001;
        $matches = static fn (float $v): bool =>
            abs($v - $declaredF) <= $tolerance
            || ($declaredIsWhole && abs($v - $declaredF) < 1.00)
            || abs(RoundingModes::apply($v, RoundingModes::MATH_FIVE_CENT) - $declaredF) <= 0.001;

        $matchBase = $matches($sumBase);
        $matchWithVat = $matches($sumWithVat);
        $matchRecap = $sumVatRecap !== null && $matches($sumVatRecap);

        if ($matchBase || $matchWithVat || $matchRecap) {
            return;
        }

        $detail = "součet řádků bez DPH: {$sumBase}; s DPH per řádek: {$sumWithVat}";
        if ($sumVatRecap !== null) {
            $detail .= "; podle vatRecap: {$sumVatRecap}";
        }
        $issues[] = [
            'severity' => 'warning',
            'path'     => 'totals.totalAmount',
            'code'     => 'totals_mismatch',
            'message'  => "Deklarovaná částka {$declaredF} neodpovídá žádné vypočtené variantě ({$detail}).",
        ];
    }

    /**
     * Podezření na ceny s DPH při deklarovaném `fromBase`: součet
     * položkových řádků sedí na deklarovanou částku k úhradě, ale řádky
     * nesou kladnou sazbu DPH — počítat daň zdola by ji na doklad dalo
     * podruhé. Warning vzniká **jen** když {@see VatModeDerivation} nemá
     * dost dat na korekci (typicky chybí recap i totals.totalBase) —
     * jinak DocumentApplier `vat_mode` sám opraví a ohlásí
     * `vat_mode_derived`, warning by korekci dubloval.
     *
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    private function checkVatModeSuspect(array $canonical, array &$issues): void
    {
        if ((string) ($canonical['vat']['mode'] ?? 'fromBase') !== 'fromBase') {
            return;
        }
        if (VatModeDerivation::derive($canonical) !== null) {
            return;
        }

        $totals = $canonical['totals'] ?? null;
        if (!is_array($totals) || !isset($totals['totalAmount']) || !is_numeric($totals['totalAmount'])) {
            return;
        }
        $rows = $canonical['rows'] ?? null;
        $rowSum = VatModeDerivation::sumItemRows($rows);
        if ($rowSum === null) {
            return;
        }

        $hasPositivePct = false;
        $rowCount = 0;
        foreach ((array) $rows as $row) {
            if (!is_array($row) || (string) ($row['rowKind'] ?? 'item') !== 'item' || isset($row['accSide'])) {
                continue;
            }
            $rowCount++;
            $pct = $row['vat']['pct'] ?? null;
            if ($pct !== null && is_numeric($pct) && (float) $pct > 0) {
                $hasPositivePct = true;
            }
        }
        if (!$hasPositivePct) {
            return;
        }

        $rounding = isset($totals['totalRounding']) && is_numeric($totals['totalRounding'])
            ? (float) $totals['totalRounding']
            : 0.0;
        $declaredTotal = round((float) $totals['totalAmount'] - $rounding, 2);
        $eps = VatModeDerivation::tolerance($rowCount);
        if (abs($rowSum - $declaredTotal) > $eps) {
            return;
        }
        // Sedí-li součet zároveň na totalBase, nejde vzor rozlišit od 0%
        // dokladu — derivace by v takové konstelaci taky mlčela.
        if (isset($totals['totalBase']) && is_numeric($totals['totalBase'])
            && abs($rowSum - round((float) $totals['totalBase'], 2)) <= $eps) {
            return;
        }

        $issues[] = [
            'severity' => 'warning',
            'path'     => 'vat.mode',
            'code'     => 'vat_mode_suspect',
            'message'  => 'Součet řádků odpovídá částce k úhradě, ale režim výpočtu je zdola (fromBase) — řádky vypadají jako ceny s DPH, zkontrolujte režim výpočtu.',
        ];
    }

    /**
     * Součet položkových řádků vs. rekapitulace DPH (fallback totals) podle
     * efektivního režimu výpočtu. Rekapitulace opsaná z dokladu je
     * autoritativní — mismatch signalizuje neúplné či chybně extrahované
     * řádky (AI u vícestránkových dokladů umí vrátit jen podmnožinu),
     * ne špatný doklad, proto warning. `checkTotalsCoherence` tuhle
     * konstelaci nechytí: deklarovanou částku porovnává i proti Σ
     * vatRecap[].total — a recap opsaný z téhož dokladu si vždy sedne.
     *
     * Validator běží před korekcí `vat_mode` v DocumentApplier::transform,
     * efektivní režim se proto derivuje lokálně: {@see VatModeDerivation}
     * má přednost, deklarovaný `vat.mode` je fallback.
     *
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    private function checkRowsVsRecap(array $canonical, array &$issues): void
    {
        $rows = $canonical['rows'] ?? null;
        $rowSum = VatModeDerivation::sumItemRows($rows);
        if ($rowSum === null) {
            return;
        }

        // `none` má base ≈ total, větev celkové částky sedí pro oba směry.
        $derived = VatModeDerivation::derive($canonical);
        $fromTotal = $derived !== null
            ? $derived === 2
            : (string) ($canonical['vat']['mode'] ?? 'fromBase') !== 'fromBase';

        $expected = $this->rowsRecapReference($canonical, $fromTotal);
        if ($expected === null) {
            return;
        }

        $rowCount = 0;
        foreach ((array) $rows as $row) {
            if (is_array($row) && (string) ($row['rowKind'] ?? 'item') === 'item' && !isset($row['accSide'])) {
                $rowCount++;
            }
        }
        if (abs($rowSum - $expected) <= VatModeDerivation::tolerance($rowCount)) {
            return;
        }

        $label = $fromTotal ? 'součtu celků s DPH' : 'součtu základů bez DPH';
        $issues[] = [
            'severity' => 'warning',
            'path'     => 'rows',
            'code'     => 'rows_recap_mismatch',
            'message'  => "Součet položkových řádků {$rowSum} neodpovídá {$label} v rekapitulaci dokladu ({$expected}) — řádky mohou být neúplné nebo chybně extrahované.",
        ];
    }

    /**
     * Referenční hodnota pro checkRowsVsRecap: Σ `vatRecap[].base` resp.
     * `[].total` (jen z kompletního recapu — zrcadlí sémantiku
     * {@see VatModeDerivation}), fallback `totals.totalBase` resp.
     * `totals.totalAmount − totalRounding` (zaokrouhlení celkové částky
     * se řádků netýká).
     */
    private function rowsRecapReference(array $canonical, bool $fromTotal): ?float
    {
        $vatRecap = $canonical['vatRecap'] ?? null;
        if (is_array($vatRecap) && count($vatRecap) > 0) {
            $key = $fromTotal ? 'total' : 'base';
            $sum = 0.0;
            $complete = true;
            foreach ($vatRecap as $r) {
                if (!is_array($r) || !isset($r[$key]) || !is_numeric($r[$key])) {
                    $complete = false;
                    break;
                }
                $sum += (float) $r[$key];
            }
            if ($complete) {
                return round($sum, 2);
            }
        }

        $totals = $canonical['totals'] ?? null;
        if (!is_array($totals)) {
            return null;
        }
        if (!$fromTotal) {
            return isset($totals['totalBase']) && is_numeric($totals['totalBase'])
                ? round((float) $totals['totalBase'], 2)
                : null;
        }
        if (!isset($totals['totalAmount']) || !is_numeric($totals['totalAmount'])) {
            return null;
        }
        $rounding = isset($totals['totalRounding']) && is_numeric($totals['totalRounding'])
            ? (float) $totals['totalRounding']
            : 0.0;
        return round((float) $totals['totalAmount'] - $rounding, 2);
    }

    /**
     * Vnitřní aritmetika řádků rekapitulace DPH: base + tax = total a
     * tax = base × pct/100. Rekapitulace opsaná z dokladu oběma vyhoví;
     * rekapitulace dopočtená modelem pozpátku (chybně určený režim
     * výpočtu) bývá nekonzistentní a je tak aritmeticky odhalitelná.
     * Tolerance daně kryje haléřové zaokrouhlení i výpočet koeficientem
     * u dokladů s cenami s DPH. Reverse-charge páry a 0% řádky se
     * přeskakují.
     *
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    private function checkVatRecapArithmetic(array $canonical, array &$issues): void
    {
        $vatRecap = $canonical['vatRecap'] ?? null;
        if (!is_array($vatRecap)) {
            return;
        }
        foreach ($vatRecap as $i => $r) {
            if (!is_array($r) || ($r['isReversePair'] ?? null) === true) {
                continue;
            }
            $pct = $r['vatPct'] ?? null;
            $base = $r['base'] ?? null;
            $tax = $r['tax'] ?? null;
            $total = $r['total'] ?? null;
            if (!is_numeric($pct) || !is_numeric($base) || !is_numeric($tax) || !is_numeric($total)) {
                continue;
            }
            $pctF = (float) $pct;
            if ($pctF == 0.0) {
                continue;
            }
            $baseF = (float) $base;
            $taxF = (float) $tax;
            $totalF = (float) $total;

            $problems = [];
            if (abs($baseF + $taxF - $totalF) > 0.02) {
                $problems[] = "základ {$baseF} + DPH {$taxF} ≠ celkem {$totalF}";
            }
            $expectedTax = round($baseF * $pctF / 100.0, 2);
            if (abs($taxF - $expectedTax) > max(0.05, abs($baseF) * 0.001)) {
                $problems[] = "DPH {$taxF} neodpovídá sazbě {$pctF} % ze základu {$baseF} (očekáváno ~{$expectedTax})";
            }
            if ($problems === []) {
                continue;
            }
            $issues[] = [
                'severity' => 'warning',
                'path'     => "vatRecap[{$i}]",
                'code'     => 'vat_recap_inconsistent',
                'message'  => 'Řádek rekapitulace DPH je vnitřně nekonzistentní: ' . implode('; ', $problems) . '.',
            ];
        }
    }

    /**
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    private function checkPartnerDocNumber(array $canonical, array &$issues): void
    {
        $docType = (string) ($canonical['docType'] ?? '');
        if ($docType !== 'invoiceReceived') {
            return;
        }
        // Warning platí pro všechny cíle mimo Koncept (40, 30 i 80).
        $targetState = $canonical['applyOptions']['targetDocState'] ?? null;
        if ($targetState === null || (int) $targetState === 10) {
            return;
        }
        $partnerDocNumber = $canonical['docNumber'] ?? null;
        if ($partnerDocNumber === null || trim((string) $partnerDocNumber) === '') {
            $issues[] = [
                'severity' => 'warning',
                'path'     => 'docNumber',
                'code'     => 'partner_doc_number_missing',
                'message'  => 'Číslo dokladu od dodavatele není vyplněno.',
            ];
        }
    }

    /**
     * @return array{severity: string, path: string, code: string, message: string}
     */
    private function required(string $path, string $message): array
    {
        return [
            'severity' => 'error',
            'path'     => $path,
            'code'     => 'required',
            'message'  => $message,
        ];
    }
}

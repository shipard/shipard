<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Document\Interpret;

use Shipard\Module\Core\Exchange\Document\CanonicalReferences;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Core\Exchange\Document\DocumentValidator;
use Shipard\Module\Core\Exchange\Document\VatModeDerivation;
use Shipard\Module\Docs\Core\DocDocument;
use Shipard\Module\Docs\Core\RoundingModes;

/**
 * Kontroly **papírové podoby** dokladu (#166 D19, D45, D51) — to, co
 * {@see DocumentValidator} posuzuje jen v režimu návrh:
 *
 *  - povinná pole předlohy (datum vystavení, řádky, strana podle typu
 *    dokladu) — pokyn je nevyžaduje, má výchozí hodnoty a povinnost hlídá
 *    `DocDocument` pro cílový stav;
 *  - heuristická varování nad čísly předlohy (`totals_mismatch`,
 *    `vat_mode_suspect`, `rows_recap_mismatch`, `vat_recap_inconsistent`)
 *    — pokyn čísla neposuzuje, počítá je `DocDocument`;
 *  - `import_requires_instruction` — importní mód přenáší hotový doklad,
 *    v návrhu by ho interpretace znovu posuzovala (D22).
 */
final class PaperChecks
{
    /**
     * Kontroly papírové podoby v pořadí, v jakém je nese zpráva návrhu:
     * kontroly předlohy prokládané kontrolami payloadu, které platí i pro
     * pokyn ({@see DocumentValidator} — pokladna vázaného typu, číslo
     * dokladu dodavatele, volby `applyOptions`, `source.ref`, dimenze,
     * tvary hodnot stran).
     *
     * @param array<string, mixed> $canonical
     * @return array<int, array{severity: string, path: string, code: string, message: string}>
     */
    public static function validate(array $canonical, DocumentValidator $payloadChecks): array
    {
        $issues = [];
        $canonical = CanonicalReferences::expand($canonical);

        self::checkCommonRequired($canonical, $issues);
        self::checkParties($canonical, $issues);
        $payloadChecks->checkDocTypeFields($canonical, $issues);
        self::checkHeadPartner($canonical, $issues);
        self::checkTotalsCoherence($canonical, $issues);
        self::checkVatModeSuspect($canonical, $issues);
        self::checkRowsVsRecap($canonical, $issues);
        self::checkVatRecapArithmetic($canonical, $issues);
        $payloadChecks->checkPartnerDocNumber($canonical, $issues);
        self::checkImportRequiresInstruction($canonical, $issues);
        $payloadChecks->checkOptionsAndValues($canonical, $issues);

        return $issues;
    }

    /**
     * Pole `partner` na hlavičce faktury (#166 D30): předloha z papíru, kde
     * pole vyplnil model — návrh ho s varováním ignoruje, partnerem je
     * strana dokladu. Pokyn totéž odmítá chybou.
     *
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    public static function checkHeadPartner(array $canonical, array &$issues): void
    {
        if (CanonicalReferences::headPartnerMisplaced($canonical)) {
            $issues[] = [
                'severity' => 'warning',
                'path'     => CanonicalReferences::PARTNER,
                'code'     => 'partner_not_supported',
                'message'  => 'Pole partner platí jen u účetního a pokladního dokladu — ignorováno, partnerem je strana dokladu.',
            ];
        }
    }

    /**
     * Strana povinná podle typu dokladu: přijatá faktura a zálohová faktura
     * přijatá dodavatele, vydaná odběratele. Pokladní doklad a prodejka mají
     * strany nepovinné (anonymní doklady).
     *
     * @param array<string, mixed> $canonical
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    public static function checkParties(array $canonical, array &$issues): void
    {
        $required = match ((string) ($canonical['docType'] ?? '')) {
            'invoiceReceived'  => ['supplier', 'U přijaté faktury je dodavatel povinný.'],
            'invoiceIssued'    => ['customer', 'U vydané faktury je odběratel povinný.'],
            // Zálohové faktury (#79 D1, #106 D1): strany jako u faktur.
            'proformaIssued'   => ['customer', 'U zálohové faktury vydané je odběratel povinný.'],
            'proformaReceived' => ['supplier', 'U zálohové faktury přijaté je dodavatel povinný.'],
            default            => null,
        };
        if ($required !== null && empty($canonical[$required[0]])) {
            $issues[] = DocumentValidator::required($required[0], $required[1]);
        }
    }

    /**
     * Importní mód (`applyOptions.importNumber`) přenáší hotový doklad —
     * je to pokyn (#166 D22). V režimu návrh by ho interpretace znovu
     * posuzovala, proto chyba `import_requires_instruction`.
     *
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    public static function checkImportRequiresInstruction(array $canonical, array &$issues): void
    {
        if (!is_array($canonical['applyOptions']['importNumber'] ?? null)) {
            return;
        }
        $issues[] = [
            'severity' => 'error',
            'path'     => 'applyOptions.importNumber',
            'code'     => 'import_requires_instruction',
            'message'  => 'Import s vnuceným číslem (importNumber) ukládá hotový doklad — jde jen v režimu pokyn,'
                . ' ne s interpretací (applyOptions.interpret).',
        ];
    }

    /**
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    public static function checkCommonRequired(array $canonical, array &$issues): void
    {
        $dates = $canonical['dates'] ?? [];
        if (empty($dates['issueDate'])) {
            $issues[] = DocumentValidator::required('dates.issueDate', 'Datum vystavení je povinné.');
        }

        if (empty($canonical['rows']) || !is_array($canonical['rows']) || count($canonical['rows']) === 0) {
            $issues[] = DocumentValidator::required('rows', 'Doklad musí mít alespoň jeden řádek.');
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
    public static function checkTotalsCoherence(array $canonical, array &$issues): void
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

        // Účetní doklad (cmnbkp): celková částka je obrat jedné strany
        // (`AccountingDocument` sčítá řádky MD), ne součet obou stran kontace.
        $debitOnly = ($canonical['docType'] ?? null) === 'accountingDocument';

        // (1) base, (2) base × (1 + vat.pct/100)
        $sumBase = 0.0;
        $sumWithVat = 0.0;
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            if ($debitOnly && ($row['accSide'] ?? null) === 'credit') continue;
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
    public static function checkVatModeSuspect(array $canonical, array &$issues): void
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
    public static function checkRowsVsRecap(array $canonical, array &$issues): void
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

        $expected = self::rowsRecapReference($canonical, $fromTotal);
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
     * se řádků netýká). Párové řádky samovyměření (`isReversePair`, dump
     * dokladu se samovyměřením) nesou tentýž základ podruhé — do součtu
     * nepatří, stejně jako v `DocDocument::validateDeclaredRecap`.
     */
    private static function rowsRecapReference(array $canonical, bool $fromTotal): ?float
    {
        $vatRecap = $canonical['vatRecap'] ?? null;
        if (is_array($vatRecap) && count($vatRecap) > 0) {
            $key = $fromTotal ? 'total' : 'base';
            $sum = 0.0;
            $complete = true;
            foreach ($vatRecap as $r) {
                if (is_array($r) && ($r['isReversePair'] ?? null) === true) {
                    continue;
                }
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
     * přeskakují; řádek samovyměření (primární řádek, za kterým jde jeho
     * pár `isReversePair` — tak ho skládá `DocDocument::buildVatRecapitulation`
     * a vypisuje dump) má celkem = základ, daň se dodavateli neplatí
     * (jako `noPayTax` v `DocDocument::validateDeclaredRecap`).
     *
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    public static function checkVatRecapArithmetic(array $canonical, array &$issues): void
    {
        $vatRecap = $canonical['vatRecap'] ?? null;
        if (!is_array($vatRecap)) {
            return;
        }
        $entries = array_values($vatRecap);
        foreach ($entries as $i => $r) {
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
            $selfAssessed = self::hasReversePair($entries, $i, $pctF);

            $problems = [];
            $expectedTotal = $selfAssessed ? $baseF : $baseF + $taxF;
            if (abs($expectedTotal - $totalF) > 0.02) {
                $problems[] = $selfAssessed
                    ? "celkem {$totalF} ≠ základ {$baseF} (samovyměření, daň se dodavateli neplatí)"
                    : "základ {$baseF} + DPH {$taxF} ≠ celkem {$totalF}";
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
     * Řádek `$index` je primární řádek samovyměření: hned za ním jde jeho
     * oddaňovací pár (`isReversePair`) v téže sazbě. Pár dědí sazbu i daň
     * primárního řádku (`DocDocument::buildVatRecapitulation`), exportér
     * drží pořadí `order_pos`.
     *
     * @param list<mixed> $entries
     */
    private static function hasReversePair(array $entries, int $index, float $pct): bool
    {
        $next = $entries[$index + 1] ?? null;
        if (!is_array($next) || ($next['isReversePair'] ?? null) !== true) {
            return false;
        }
        $nextPct = $next['vatPct'] ?? null;
        return !is_numeric($nextPct) || abs((float) $nextPct - $pct) < 0.001;
    }
}

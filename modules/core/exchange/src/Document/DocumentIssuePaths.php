<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Document;

use Shipard\Core\Document\ValidationError;
use Shipard\Core\Document\ValidationResult;

/**
 * Nálezy validace dokladu (`DocDocument::validate()`) ve tvaru issues
 * výměnného formátu (#166 D21, U6): `path` je cesta v canonicalu tam, kde
 * je vazba sloupec → pole jednoznačná (data hlavičky, partner, kurz, kód
 * DPH, cena, množství, položka…), jinak zůstává sloupec dokladu.
 *
 * Řádkový nález dokladu nese **pozici řádku na dokladu** (`rows.{pozice}.…`);
 * canonical může mít řádků víc (vynechané řádky návrhu), proto se pozice
 * překládá na index canonicalu mapou z `DocumentApplier::transformedRowIndices()`.
 *
 * Čistá funkce bez databáze.
 */
final class DocumentIssuePaths
{
    /** Sloupec hlavičky → pole canonicalu. Partner se řeší zvlášť (strana dokladu). */
    private const HEAD = [
        'issue_date'           => 'dates.issueDate',
        'due_date'             => 'dates.dueDate',
        'accounting_date'      => 'dates.accountingDate',
        'vat_duzp'             => 'dates.taxPointDate',
        'vat_dppd'             => 'dates.vatObligationDate',
        'vat_rate_date'        => 'dates.vatRateDate',
        'period_from'          => 'dates.periodFrom',
        'period_to'            => 'dates.periodTo',
        'exchange_rate'        => 'exchangeRate',
        'doc_currency'         => 'currency',
        'doc_text'             => 'docText',
        'partner_doc_number'   => 'docNumber',
        'partner_balance'      => 'balanceParty',
        'cash_desk'            => 'cashDesk',
        'number_series'        => 'numberSeries',
        'bank_account'         => 'payment.ownBankAccount',
        'cash_dir'             => 'cashDirection',
        'vat_mode'             => 'vat.mode',
        'vat_place'            => 'vat.place',
        'vat_rate_source'      => 'vat.rateSource',
        'vat_recap_source'     => 'vat.recapSource',
        'vat_calc_source'      => 'vat.calcSource',
        'vat_registration'     => 'vat.registration',
        'vat_rounding_mode'    => 'vat.roundingMode',
        'total_rounding_auto'  => 'totals.totalRoundingAuto',
        'total_rounding_mode'  => 'totals.totalRoundingMode',
        'cs_mode'              => 'vat.controlStatementMode',
        'payment_method'       => 'payment.method',
        'payment_reference'    => 'payment.paymentReference',
        'specific_symbol'      => 'payment.specificSymbol',
        'constant_symbol'      => 'payment.constantSymbol',
        'corrected_doc'        => 'correction.originalDoc',
        'corrected_doc_number' => 'correction.originalDocNumber',
        'corrected_doc_date'   => 'correction.originalTaxPointDate',
        'correction_reason'    => 'correction.reason',
        'rows'                 => 'rows',
        'recap'                => 'vatRecap',
    ];

    /** Strana, která je partnerem dokladu, podle typu — pro payload bez `selfParty`. */
    private const PARTNER_PARTY = [
        'invoiceIssued'    => 'customer',
        'proformaIssued'   => 'customer',
        'invoiceReceived'  => 'supplier',
        'proformaReceived' => 'supplier',
    ];

    /** Sloupec řádku → pole řádku canonicalu. */
    private const ROW = [
        'vat_code'            => 'vat.code',
        'vat_rate_date'       => 'vat.rateDate',
        'reverse_charge_code' => 'vat.reverseChargeCode',
        'quantity'            => 'quantity',
        'unit_price'          => 'unitPrice',
        'total_price'         => 'totalPrice',
        'discount_pct'        => 'discountPct',
        'discount_amount'     => 'discountAmount',
        'item'                => 'item',
        'description'         => 'description',
        'unit'                => 'unit',
        'operation'           => 'operation',
        'account'             => 'account',
        'acc_side'            => 'accSide',
        'partner'             => 'partner',
        'due_date'            => 'dueDate',
        'payment_reference'   => 'paymentReference',
        'specific_symbol'     => 'specificSymbol',
        'constant_symbol'     => 'constantSymbol',
    ];

    /**
     * @param array<string, mixed> $canonical
     * @param list<int> $rowIndices pozice řádku na dokladu → index canonicalu
     * @return list<array{severity: string, path: string, code: string, message: string}>
     */
    public static function issues(ValidationResult $validation, array $canonical, array $rowIndices): array
    {
        $issues = [];
        $derived = self::derivedFindings($validation);
        foreach ($validation->getErrors() as $error) {
            if (in_array($error, $derived, true)) {
                continue;
            }
            $issues[] = self::issue('error', $error, $canonical, $rowIndices);
        }
        foreach ($validation->getWarnings() as $warning) {
            $issues[] = self::issue('warning', $warning, $canonical, $rowIndices);
        }
        return $issues;
    }

    /**
     * Nálezy, které jen opakují jiný nález (#166 U7 — jeden nález na pole):
     * účetní datum applier odvozuje z data vystavení, takže chybějící datum
     * vystavení dá dokladu i „Účetní datum je povinné“. Hlásí se jen příčina.
     *
     * @return list<ValidationError>
     */
    private static function derivedFindings(ValidationResult $validation): array
    {
        $required = [];
        foreach ($validation->getErrors() as $error) {
            if ($error->code === 'required') {
                $required[$error->column] = $error;
            }
        }
        return isset($required['issue_date'], $required['accounting_date'])
            ? [$required['accounting_date']]
            : [];
    }

    /**
     * Jen varování — pro úspěšné uložení, kde chyby nejsou.
     *
     * @param array<string, mixed> $canonical
     * @param list<int> $rowIndices
     * @return list<array{severity: string, path: string, code: string, message: string}>
     */
    public static function warnings(ValidationResult $validation, array $canonical, array $rowIndices): array
    {
        return array_map(
            static fn (ValidationError $warning): array => self::issue('warning', $warning, $canonical, $rowIndices),
            $validation->getWarnings(),
        );
    }

    /**
     * @param array<string, mixed> $canonical
     * @param list<int> $rowIndices
     */
    public static function path(string $column, array $canonical, array $rowIndices): string
    {
        if (preg_match('/^rows\.(\d+)\.(.+)$/', $column, $m) === 1) {
            $index = $rowIndices[(int) $m[1]] ?? (int) $m[1];
            return "rows.{$index}." . (self::ROW[$m[2]] ?? $m[2]);
        }
        if ($column === 'partner') {
            // Partner dokladu je ta druhá strana než my; bez `selfParty` ji
            // u faktur říká typ dokladu (minimální pokyn `selfParty` nenese),
            // u účetního a pokladního dokladu zůstává sloupec.
            return match ($canonical['selfParty'] ?? null) {
                'customer' => 'supplier',
                'supplier' => 'customer',
                default    => self::PARTNER_PARTY[(string) ($canonical['docType'] ?? '')] ?? 'partner',
            };
        }
        return self::HEAD[$column] ?? $column;
    }

    /**
     * @param array<string, mixed> $canonical
     * @param list<int> $rowIndices
     * @return array{severity: string, path: string, code: string, message: string}
     */
    private static function issue(string $severity, ValidationError $finding, array $canonical, array $rowIndices): array
    {
        return [
            'severity' => $severity,
            'path'     => self::path($finding->column, $canonical, $rowIndices),
            'code'     => $finding->code !== '' ? $finding->code : 'document_invalid',
            'message'  => $finding->message,
        ];
    }
}

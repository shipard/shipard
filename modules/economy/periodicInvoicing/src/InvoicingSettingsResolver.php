<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\PeriodicInvoicing;

/**
 * Jediné místo, kde se skládá efektivní předpis (D3): hodnota záznamu
 * zdroje → hodnota druhu → pevný default. Čistá funkce nad poli řádků — druh si
 * načte volající (Document přes Dibi, formulář přes DataSourceConnection,
 * běh v dávce), aby resolver nenosil dva druhy DB přístupu.
 */
final class InvoicingSettingsResolver
{
    /**
     * @param array<string, mixed> $record záznam zdroje fakturace (sloupce inv_*)
     * @param array<string, mixed>|null $kind řádek druhu (sloupce inv_*), null = druh neznámý
     */
    public static function resolve(array $record, ?array $kind): InvoicingSettings
    {
        $sources = [];
        $pick = static function (string $column) use ($record, $kind, &$sources): mixed {
            $own = $record[$column] ?? null;
            if ($own !== null && $own !== '') {
                $sources[$column] = InvoicingSettings::SOURCE_RECORD;
                return $own;
            }
            $inherited = $kind[$column] ?? null;
            if ($inherited !== null && $inherited !== '') {
                $sources[$column] = InvoicingSettings::SOURCE_KIND;
                return $inherited;
            }
            $sources[$column] = InvoicingSettings::SOURCE_DEFAULT;
            return null;
        };

        $docType = $pick('inv_doc_type');
        $series = $pick('inv_number_series');
        $dueDays = $pick('inv_due_days');
        $timing = $pick('inv_timing');
        $vatMode = $pick('inv_vat_mode');
        $paymentMethod = $pick('inv_payment_method');
        $bankAccount = $pick('inv_bank_account');

        return new InvoicingSettings(
            docType:       InvoicingSettings::isDocType($docType) ? $docType : null,
            numberSeries:  $series !== null ? (int) $series : null,
            dueDays:       $dueDays !== null ? max(0, (int) $dueDays) : InvoicingSettings::DEFAULT_DUE_DAYS,
            timing:        InvoicingSettings::isTiming($timing) ? $timing : InvoicingSettings::DEFAULT_TIMING,
            vatMode:       $vatMode !== null ? (int) $vatMode : InvoicingSettings::DEFAULT_VAT_MODE,
            paymentMethod: $paymentMethod !== null ? (int) $paymentMethod : InvoicingSettings::DEFAULT_PAYMENT_METHOD,
            bankAccount:   $bankAccount !== null ? (int) $bankAccount : null,
            sources:       $sources,
        );
    }

    /**
     * Hodnoty druhu jako pole pro placeholdery formuláře („Z druhu: …“):
     * jen sloupce, které druh má vyplněné.
     *
     * @param array<string, mixed>|null $kind
     * @return array<string, mixed>
     */
    public static function kindValues(?array $kind): array
    {
        $out = [];
        foreach (InvoicingSettings::COLUMNS as $column) {
            $value = $kind[$column] ?? null;
            if ($value !== null && $value !== '') {
                $out[$column] = $value;
            }
        }
        return $out;
    }
}

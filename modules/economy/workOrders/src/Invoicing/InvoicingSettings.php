<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\WorkOrders\Invoicing;

/**
 * Efektivní fakturační předpis periodické zakázky (docs/work-orders.md D3,
 * Q5): hodnoty druhu přepsané hodnotami zakázky. Skládá ho výhradně
 * {@see InvoicingSettingsResolver}; formulář z něj bere placeholdery
 * („Z druhu: …“), builder hodnoty dokladu.
 *
 * `sources` říká per sloupec, odkud hodnota přišla: `workOrder` (přepis na
 * zakázce), `kind` (druh) nebo `default` (ani jeden — pevný default třídy;
 * u typu dokladu, řady a účtu neexistuje a hodnota je null).
 */
final readonly class InvoicingSettings
{
    /** Povolené cílové doklady periodické fakturace. */
    public const DOC_TYPES = ['invno', 'invpo'];

    public const TIMING_START = 'start';
    public const TIMING_END = 'end';
    public const TIMINGS = [self::TIMING_START, self::TIMING_END];

    public const PERIODICITIES = ['month', 'quarter', 'halfyear', 'year'];

    public const DEFAULT_DUE_DAYS = 14;
    public const DEFAULT_TIMING = self::TIMING_START;
    /** docs.core.vatModes: 1 = ze základu. */
    public const DEFAULT_VAT_MODE = 1;
    /** docs.core.paymentMethods: 1 = převodem. */
    public const DEFAULT_PAYMENT_METHOD = 1;

    /** Sloupce předpisu společné druhu a zakázce (NULL na zakázce = z druhu). */
    public const COLUMNS = [
        'inv_doc_type', 'inv_number_series', 'inv_due_days', 'inv_timing',
        'inv_vat_mode', 'inv_payment_method', 'inv_bank_account',
    ];

    /** Sloupce jen na zakázce. */
    public const WORK_ORDER_COLUMNS = ['inv_periodicity', 'inv_from', 'inv_doc_text'];

    public const SOURCE_WORK_ORDER = 'workOrder';
    public const SOURCE_KIND = 'kind';
    public const SOURCE_DEFAULT = 'default';

    /**
     * @param array<string, string> $sources sloupec → SOURCE_*
     */
    public function __construct(
        public ?string $docType,
        public ?int $numberSeries,
        public int $dueDays,
        public string $timing,
        public int $vatMode,
        public int $paymentMethod,
        public ?int $bankAccount,
        public array $sources,
    ) {
    }

    public function source(string $column): string
    {
        return $this->sources[$column] ?? self::SOURCE_DEFAULT;
    }

    public static function isDocType(mixed $value): bool
    {
        return is_string($value) && in_array($value, self::DOC_TYPES, true);
    }

    public static function isTiming(mixed $value): bool
    {
        return is_string($value) && in_array($value, self::TIMINGS, true);
    }

    public static function isPeriodicity(mixed $value): bool
    {
        return is_string($value) && in_array($value, self::PERIODICITIES, true);
    }
}

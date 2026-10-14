<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Items;

/**
 * Nabídku účetních položek nelze použít — prerekvizity selhaly ještě před
 * prvním zápisem (nic se nezměnilo). `$reason` je strojový kód, který
 * volající mapuje na HTTP odpověď (SetupController) nebo na hlášku seedu
 * (AccountingItemsSeeder).
 */
final class AccountingItemsUnavailableException extends \RuntimeException
{
    public const CHART_UNDECIDED = 'chart_undecided';
    public const CHART_NONE = 'chart_none';
    public const ACCOUNTING_INACTIVE = 'accounting_inactive';
    public const SEED_MISSING = 'seed_missing';
    public const ITEM_KIND_MISSING = 'item_kind_missing';
    public const UNIT_MISSING = 'unit_missing';

    public function __construct(
        public readonly string $reason,
        public readonly ?string $variant = null,
    ) {
        parent::__construct("Accounting items offer is not available: {$reason}");
    }
}

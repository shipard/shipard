<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\WorkOrders\Invoicing;

/**
 * Období nejde sestavit do dokladu — stav zakázky, ne chyba programu.
 * `reason` je strojový kód do `economy_work_orders_periods.result`
 * (`no_rows`, `no_doc_type`, `no_series`, `no_customer`), zpráva je pro
 * uživatele.
 */
final class InvoiceBuildException extends \RuntimeException
{
    public const NO_ROWS = 'no_rows';
    public const NO_DOC_TYPE = 'no_doc_type';
    public const NO_SERIES = 'no_series';
    public const NO_CUSTOMER = 'no_customer';
    public const CONTRIBUTOR_FAILED = 'contributor_failed';

    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}

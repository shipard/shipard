<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\WorkOrders\Invoicing;

/**
 * Parametry běhu periodické fakturace (docs/work-orders.md D5, Q4).
 *
 *  - `date` = datum běhu (splatnost období), `now` = časová značka zápisů;
 *  - `workOrderId` = jen jedna zakázka (akce Vystavit dlužná období, CLI
 *    `--work-order`);
 *  - `dryRun` = jen výčet, žádný zápis (ani založení období);
 *  - `force` = bez pojistky dohánění (víc než MAX_CATCHUP splatných období).
 */
final readonly class RunOptions
{
    public function __construct(
        public string $date,
        public ?int $workOrderId = null,
        public bool $dryRun = false,
        public bool $force = false,
        public ?string $now = null,
    ) {
    }

    public static function today(?int $workOrderId = null, bool $dryRun = false, bool $force = false): self
    {
        return new self(date('Y-m-d'), $workOrderId, $dryRun, $force, date('Y-m-d H:i:s'));
    }

    public function timestamp(): string
    {
        return $this->now ?? ($this->date . ' 00:00:00');
    }
}

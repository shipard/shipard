<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\WorkOrders\Invoicing;

/**
 * Kalendářní období periodické fakturace (docs/work-orders.md Q3):
 * uzavřený interval `from`–`to` ve tvaru `Y-m-d`.
 */
final readonly class Period
{
    public function __construct(
        public string $from,
        public string $to,
    ) {
        if ($to < $from) {
            throw new \InvalidArgumentException("Period {$from}–{$to}: konec před začátkem");
        }
    }

    public function contains(string $date): bool
    {
        return $date >= $this->from && $date <= $this->to;
    }

    public function equals(Period $other): bool
    {
        return $this->from === $other->from && $this->to === $other->to;
    }
}

<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\WorkOrders\Invoicing\Contributor;

use Shipard\Module\Economy\WorkOrders\Invoicing\Period;

/**
 * Kontext volání přispěvatele (D10): zakázka, období, den fakturace
 * (DUZP), řádky předpisu s tímto přispěvatelem a rozpracovaný kanonický
 * doklad (`rowIndexes` = pozice řádků přispěvatele v `canonical['rows']`).
 */
final readonly class ContributionContext
{
    /**
     * @param array<string, mixed> $workOrder hlavička zakázky
     * @param list<array<string, mixed>> $rows řádky předpisu s tímto přispěvatelem
     * @param array<string, mixed> $canonical rozpracovaný kanonický doklad
     * @param list<int> $rowIndexes indexy řádků přispěvatele v `canonical['rows']`
     */
    public function __construct(
        public array $workOrder,
        public Period $period,
        public string $billingDate,
        public array $rows,
        public array $canonical,
        public array $rowIndexes,
    ) {
    }
}

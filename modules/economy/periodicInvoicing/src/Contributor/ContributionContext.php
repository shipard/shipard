<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\PeriodicInvoicing\Contributor;

use Shipard\Module\Economy\PeriodicInvoicing\Period;

/**
 * Kontext volání přispěvatele (D10, #175 D5): zdroj fakturace a jeho
 * záznam, období, den fakturace (DUZP), řádky předpisu s tímto
 * přispěvatelem a rozpracovaný kanonický doklad (`rowIndexes` = pozice
 * řádků přispěvatele v `canonical['rows']`).
 */
final readonly class ContributionContext
{
    /**
     * @param string $source id zdroje fakturace (`periodicInvoicingSources[].id`)
     * @param array<string, mixed> $record normalizovaný záznam zdroje
     * @param list<array<string, mixed>> $rows řádky předpisu s tímto přispěvatelem
     * @param array<string, mixed> $canonical rozpracovaný kanonický doklad
     * @param list<int> $rowIndexes indexy řádků přispěvatele v `canonical['rows']`
     */
    public function __construct(
        public string $source,
        public array $record,
        public Period $period,
        public string $billingDate,
        public array $rows,
        public array $canonical,
        public array $rowIndexes,
    ) {
    }
}

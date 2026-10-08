<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\WorkOrders\Invoicing;

/**
 * Výsledek InvoiceBuilder::build: kanonický doklad `shpd.docs.document.v1`
 * připravený pro DocumentApplier, jazyk dokumentu, den fakturace (datum
 * vystavení = DUZP) a použité řádky předpisu (id řádku → index řádku
 * dokladu; řádky přispěvatelů volá běh zvlášť).
 */
final readonly class BuiltInvoice
{
    /**
     * @param array<string, mixed> $canonical
     * @param list<array<string, mixed>> $rows řádky předpisu platné k DUZP, v pořadí dokladu
     */
    public function __construct(
        public array $canonical,
        public string $language,
        public string $billingDate,
        public string $periodLabel,
        public array $rows,
    ) {
    }

    /** @param array<string, mixed> $canonical */
    public function withCanonical(array $canonical): self
    {
        return new self($canonical, $this->language, $this->billingDate, $this->periodLabel, $this->rows);
    }
}

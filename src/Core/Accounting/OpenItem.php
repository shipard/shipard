<?php

declare(strict_types=1);

namespace Shipard\Core\Accounting;

/**
 * Otevřený předpis saldokonta dohledaný pro klíč úhrady ({@see OpenItemLookup}).
 * Datová třída bez logiky: skupina saldokonta, účet předpisu (přesně vč.
 * analytiky — na něj engine položí úhradu) a otevřené reziduum v měně dokladu.
 */
final readonly class OpenItem
{
    public function __construct(
        public int $balance,
        public string $accountNumber,
        public float $residual,
    ) {}
}

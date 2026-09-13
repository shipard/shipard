<?php

declare(strict_types=1);

namespace Shipard\Core\Accounting;

/**
 * Null objekt: DS bez modulu saldokonta (nebo engine postavený bez lookupu)
 * žádný předpis nedohledá → bankovní úhrady jdou na clearing jako dřív.
 */
final class NullOpenItemLookup implements OpenItemLookup
{
    public function findOpenRequest(
        int $partner,
        string $paymentReference,
        string $specificSymbol,
        string $currency,
        int $direction,
        ?string $excludeSourceKind = null,
        ?int $excludeSourceId = null,
    ): ?OpenItem {
        return null;
    }
}

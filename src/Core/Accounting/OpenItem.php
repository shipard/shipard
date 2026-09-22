<?php

declare(strict_types=1);

namespace Shipard\Core\Accounting;

/**
 * Otevřený případ saldokonta dohledaný pro klíč úhrady ({@see OpenItemLookup}).
 * Datová třída bez logiky: skupina saldokonta, účet předpisu (přesně vč.
 * analytiky — na něj engine položí úhradu; u platby bez předpisu účet
 * úhrady) a reziduum v měně dokladu **se znaménkem** (#69 D19): kladné =
 * dluh, který se platí; záporné = přeplatek / dobropis / platba bez
 * faktury, která se vrací. Stranu zápisu dává směr transakce, ne reziduum.
 */
final readonly class OpenItem
{
    public function __construct(
        public int $balance,
        public string $accountNumber,
        public float $residual,
    ) {}
}

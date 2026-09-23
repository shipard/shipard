<?php

declare(strict_types=1);

namespace Shipard\Core\Accounting;

/**
 * Pohled na jeden řádek deníku zdroje pro contributory
 * ({@see JournalContributor}). Datová třída bez logiky.
 *
 * `side` 0 = MD, 1 = DAL; `moneyDom` / `moneyCur` je částka té strany
 * (domácí / měna zdroje), typicky kladná. Platební identitu (partner, VS,
 * SS) plní engine: dokladový z řádku (razítko dle vlajek operace),
 * bankovní z transakce (jeho řádky ji samy nenesou). Chybové řádky
 * (`is_error`) engine contributorům nepředává.
 */
final readonly class JournalLineView
{
    public function __construct(
        public int $side,
        public string $accountNumber,
        public ?string $operation,
        public ?int $partner,
        public ?string $paymentReference,
        public ?string $specificSymbol,
        public float $moneyDom,
        public float $moneyCur,
    ) {}
}

<?php

declare(strict_types=1);

namespace Shipard\Core\Accounting;

/**
 * Kontext zdroje deníku předávaný contributorům ({@see JournalContributor}).
 * Datová třída bez logiky.
 *
 * `sourceKind` / `sourceId` = zdroj, jehož deník se právě přepisuje
 * (`doc` + id hlavičky, `bankTransaction` + id transakce) — contributor
 * podle nich vyloučí vlastní pohyby zdroje z agregátů (reaccount je bez
 * paměti). `accountingDate` je účetní datum zdroje (dohledání účtů k datu),
 * `fiscalYear` id fiskálního roku (součást klíče případu, #69 D11),
 * `currency` měna zdroje malými písmeny (měna všech jeho řádků).
 */
final readonly class JournalSourceContext
{
    public function __construct(
        public string $sourceKind,
        public int $sourceId,
        public string $accountingDate,
        public int $fiscalYear,
        public string $currency,
    ) {}
}

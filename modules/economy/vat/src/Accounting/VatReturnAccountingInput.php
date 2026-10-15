<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat\Accounting;

/**
 * Vstup čistého builderu účetního dokladu přiznání (#55 D28–D30) — vše,
 * co služba načte z DB a configu, bez DB závislostí. Řádky přiznání jsou
 * `array<int, {base, taxFull, taxReduced}>` per číslo řádku DP3 (tvar
 * `FilingSnapshotLoader`).
 *
 * „Předchozí" = poslední podané podání instance (`previous_filing`); u
 * řádného podání prázdné. Dokladové součty a přesné řádky jsou vždy plný
 * obsah, kumulativní podaný stav skládá loader z řetězu.
 */
final readonly class VatReturnAccountingInput
{
    /**
     * @param string $kind `regular` / `corrective` / `supplementary`
     * @param string $periodName název instance („01/2026")
     * @param string $dateEnd konec období (ISO) — splatnosti, SS, datum účtování
     * @param array<string, float> $taxByCode Σ daně per kód DPH tohoto podání
     * @param array<string, float> $previousTaxByCode totéž u předchozího podaného podání
     * @param array<int, array{base: float, taxFull: float, taxReduced: float}> $exactRows
     *        přesné řádky tohoto podání (krácený sloupec ř. 46, odpočet ř. 52)
     * @param array<int, array{base: float, taxFull: float, taxReduced: float}> $previousExactRows
     * @param array<int, array{base: float, taxFull: float, taxReduced: float}> $filedRows
     *        podané řádky tohoto podání (u dodatečného rozdíl)
     * @param array<int, array{base: float, taxFull: float, taxReduced: float}> $previousCumulativeFiledRows
     *        kumulativní podaný stav před tímto podáním
     * @param array<string, array<string, mixed>> $vatCodes kódy `world.vat.cz` (`direction`, `fullName`)
     * @param \Closure(string): ?array{id: int, number: string} $analyticAccount
     *        účet analytiky 343 pro kód DPH (dle `docs/accounting.md` §5), null = chybí v rozvrhu
     * @param array<string, ?array{id: int, number: string}> $categoryAccounts
     *        `payable`, `receivable`, `nondeductible`, `roundingCost`, `roundingRevenue`
     * @param array{payableDueDays: int, refundDueDays: int, specificSymbolPrefix: string, constantSymbol: string} $accounting
     *        `reportTypes.return.accounting` z `vat-reports-cz.jsonc`
     * @param string $vatId DIČ registrace (VS = číslice bez prefixu země)
     * @param ?int $taxOfficePerson správce daně z registrace — partner saldo řádku
     * @param array<string, list<string>> $categoryMasks masky předpisu pro
     *        `nondeductible`, `roundingCost`, `roundingRevenue` — jen do hlášek
     *        o chybějícím účtu (#122 D3; 548 / 648, v NNO 549 / 649)
     */
    public function __construct(
        public string $kind,
        public string $periodName,
        public string $dateEnd,
        public array $taxByCode,
        public array $previousTaxByCode,
        public array $exactRows,
        public array $previousExactRows,
        public array $filedRows,
        public array $previousCumulativeFiledRows,
        public array $vatCodes,
        public \Closure $analyticAccount,
        public array $categoryAccounts,
        public array $accounting,
        public string $vatId = '',
        public ?int $taxOfficePerson = null,
        public array $categoryMasks = [],
    ) {}
}

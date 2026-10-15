<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat\Accounting;

use Shipard\Module\Economy\Vat\FilingDocument;
use Shipard\Module\Economy\Vat\FilingSnapshotLoader;

/**
 * Čistý builder účetního dokladu přiznání DPH (#55 D28–D30) — ze snapshotu
 * podání sestaví řádky `acc.record` bez DB. Obsah dle starého vzoru
 * (`VatReturnAccEngine`) s novým modelem per podání:
 *
 * 1. **Vynulování analytik 343** — per kód DPH rozdíl daně proti
 *    předchozímu podanému stavu; vstupní kód (v deníku MD) → DAL, výstupní
 *    (v deníku DAL) → MD, záporný rozdíl stranu otočí. Kódy mimo přiznání
 *    (`dp3_row` NULL) se nedostanou ani do vstupu.
 * 2. **Saldo řádek** — změna daňové povinnosti podle **podaných** hodnot:
 *    závazek z kumulativního stavu po tomto podání minus závazek před ním.
 *    U řádného to je ř. 64 − ř. 65, u dodatečného přesně ř. 66. Kladná →
 *    DAL 343801 (odvod, splatnost +25 d), záporná → MD 343802 (nadměrný
 *    odpočet, +60 d). Partner = správce daně, VS = DIČ bez prefixu země,
 *    SS = prefix + RRRRMM konce období, KS z configu.
 * 3. **Neuplatnitelná část krácených kódů** — přesně: krácený sloupec
 *    ř. 46 minus odpočet ř. 52 (delta proti předchozímu); kladná = náklad
 *    MD na účet kategorie `vat.nondeductible` (548, v NNO 549), záporná
 *    (koeficient nahoru) DAL. Starý systém ji nerozlišoval a nechal ji
 *    v zaokrouhlení.
 * 4. **Zaokrouhlení** — zbytek do Σ MD = Σ DAL na `rounding.cost` (MD) /
 *    `rounding.revenue` (DAL). Sanity: |zbytek| ≤ 0,5 × počet řádků DP3
 *    s daní + 0,01; větší znamená rozjetý snapshot nebo mapování → chyba.
 *
 * Prázdný výsledek (žádný řádek) je legitimní — info do zpráv, doklad se
 * nezakládá. Chybějící účet (analytika kódu, saldo, zaokrouhlení) = chyba
 * s maskou z předpisu v hlášce (`categoryMasks` vstupu, #122 D3);
 * uživatel účet doplní a akci spustí znovu — nevyrovnaný koncept by mu
 * nepomohl.
 */
final class VatReturnAccountingBuilder
{
    public const SIDE_DEBIT  = 0;
    public const SIDE_CREDIT = 1;

    public const TOLERANCE = 0.005;

    public const MSG_ACCOUNT_MISSING       = 'vatReturn.accounting.accountMissing';
    public const MSG_TAX_OFFICE_MISSING    = 'vatReturn.accounting.taxOfficeMissing';
    public const MSG_VAT_ID_MISSING        = 'vatReturn.accounting.vatIdMissing';
    public const MSG_UNKNOWN_VAT_CODE      = 'vatReturn.accounting.unknownVatCode';
    public const MSG_ROUNDING_OUT_OF_RANGE = 'vatReturn.accounting.roundingOutOfRange';
    public const MSG_NOTHING_TO_ACCOUNT    = 'vatReturn.accounting.nothingToAccount';

    /** Součtové řádky DP3 — do počtu „řádků s daní" pro toleranci zaokrouhlení se nepočítají. */
    private const SUM_ROWS = [46, 62, 63, 64, 65, 66];

    /** @var list<array<string, mixed>> */
    private array $rows = [];

    /** @var list<array{code: string, severity: string, message: string}> */
    private array $messages = [];

    public function build(VatReturnAccountingInput $in): VatReturnAccountingPlan
    {
        $this->rows     = [];
        $this->messages = [];

        $this->addAnalyticRows($in);
        $liabilityDelta = $this->addBalanceRow($in);
        $nondeductible  = $this->addNondeductibleRow($in);
        $rounding       = $this->addRoundingRow($in);

        if ($this->rows === [] && $this->errors() === []) {
            $this->message(
                self::MSG_NOTHING_TO_ACCOUNT,
                VatReturnAccountingPlan::SEVERITY_INFO,
                'Podání nemění žádnou analytiku DPH ani daňovou povinnost — účetní doklad se nezakládá.',
            );
        }

        [$debit, $credit] = $this->totals();
        return new VatReturnAccountingPlan($this->rows, $this->messages, [
            'liabilityDelta' => $liabilityDelta,
            'nondeductible'  => $nondeductible,
            'rounding'       => $rounding,
            'debit'          => $debit,
            'credit'         => $credit,
        ]);
    }

    // ── 1. analytiky 343 ────────────────────────────────────────────────────

    private function addAnalyticRows(VatReturnAccountingInput $in): void
    {
        $codes = array_unique(array_merge(array_keys($in->taxByCode), array_keys($in->previousTaxByCode)));
        sort($codes);
        foreach ($codes as $code) {
            $code  = (string) $code;
            $delta = round(($in->taxByCode[$code] ?? 0.0) - ($in->previousTaxByCode[$code] ?? 0.0), 2);
            if (abs($delta) < self::TOLERANCE) {
                continue;
            }
            $vatCode = $in->vatCodes[$code] ?? null;
            if ($vatCode === null) {
                $this->message(
                    self::MSG_UNKNOWN_VAT_CODE,
                    VatReturnAccountingPlan::SEVERITY_WARNING,
                    "Kód DPH {$code} není v číselníku — bere se jako vstupní.",
                );
            }
            $direction = (string) ($vatCode['direction'] ?? 'input');
            // Vstup má v deníku MD → vynulování DAL; výstup naopak. Záporná
            // delta (snížení daně proti předchozímu podání) stranu otočí.
            $side = $direction === 'input' ? self::SIDE_CREDIT : self::SIDE_DEBIT;
            if ($delta < 0) {
                $side = $side === self::SIDE_CREDIT ? self::SIDE_DEBIT : self::SIDE_CREDIT;
            }
            $account = ($in->analyticAccount)($code);
            if ($account === null) {
                $this->message(
                    self::MSG_ACCOUNT_MISSING,
                    VatReturnAccountingPlan::SEVERITY_ERROR,
                    "Rozvrh nemá analytiku DPH pro kód {$code} — doplňte účet a spusťte zaúčtování znovu.",
                );
                continue;
            }
            $this->row($account, $side, abs($delta), (string) ($vatCode['fullName'] ?? $code));
        }
    }

    // ── 2. saldo řádek ──────────────────────────────────────────────────────

    private function addBalanceRow(VatReturnAccountingInput $in): float
    {
        $cumulativeAfter = $in->kind === FilingDocument::KIND_SUPPLEMENTARY
            ? FilingSnapshotLoader::addRows($in->previousCumulativeFiledRows, $in->filedRows)
            : $in->filedRows;
        $delta = round(
            FilingSnapshotLoader::liability($cumulativeAfter)
            - FilingSnapshotLoader::liability($in->previousCumulativeFiledRows),
            2,
        );
        if (abs($delta) < self::TOLERANCE) {
            return 0.0;
        }

        $payable  = $delta > 0;
        $category = $payable ? 'payable' : 'receivable';
        $account  = $in->categoryAccounts[$category] ?? null;
        if ($account === null) {
            $mask = $payable ? '343801' : '343802';
            $this->message(
                self::MSG_ACCOUNT_MISSING,
                VatReturnAccountingPlan::SEVERITY_ERROR,
                "Rozvrh nemá saldo účet {$mask} (kategorie vat.{$category}) — doplňte účet a spusťte zaúčtování znovu.",
            );
            return $delta;
        }

        if ($in->taxOfficePerson === null) {
            $this->message(
                self::MSG_TAX_OFFICE_MISSING,
                VatReturnAccountingPlan::SEVERITY_WARNING,
                'Registrace nemá správce daně — saldo řádek je bez partnera.',
            );
        }
        $vs = (string) preg_replace('/\D+/', '', $in->vatId);
        if ($vs === '') {
            $this->message(
                self::MSG_VAT_ID_MISSING,
                VatReturnAccountingPlan::SEVERITY_WARNING,
                'Registrace nemá DIČ — saldo řádek je bez variabilního symbolu.',
            );
        }

        $days = $payable ? (int) $in->accounting['payableDueDays'] : (int) $in->accounting['refundDueDays'];
        $end  = new \DateTimeImmutable($in->dateEnd);
        $this->row(
            $account,
            $payable ? self::SIDE_CREDIT : self::SIDE_DEBIT,
            abs($delta),
            $this->balanceDescription($in->kind, $payable, $in->periodName),
            [
                'partner'           => $in->taxOfficePerson,
                'payment_reference' => $vs,
                'specific_symbol'   => (string) $in->accounting['specificSymbolPrefix'] . $end->format('Ym'),
                'constant_symbol'   => (string) $in->accounting['constantSymbol'],
                'due_date'          => $end->modify("+{$days} days")->format('Y-m-d'),
            ],
        );
        return $delta;
    }

    private function balanceDescription(string $kind, bool $payable, string $periodName): string
    {
        return match ($kind) {
            FilingDocument::KIND_SUPPLEMENTARY => "Dodatečné přiznání DPH {$periodName}",
            'corrective'                       => "Opravné přiznání DPH {$periodName}",
            default                            => $payable
                ? "Odvod DPH {$periodName}"
                : "Nadměrný odpočet DPH {$periodName}",
        };
    }

    // ── 3. neuplatnitelná část krácení ──────────────────────────────────────

    private function addNondeductibleRow(VatReturnAccountingInput $in): float
    {
        $delta = round(
            self::nondeductible($in->exactRows) - self::nondeductible($in->previousExactRows),
            2,
        );
        if (abs($delta) < self::TOLERANCE) {
            return 0.0;
        }
        $account = $in->categoryAccounts['nondeductible'] ?? null;
        if ($account === null) {
            $this->message(
                self::MSG_ACCOUNT_MISSING,
                VatReturnAccountingPlan::SEVERITY_ERROR,
                'Rozvrh nemá účet pro DPH bez nároku na odpočet (kategorie vat.nondeductible'
                . self::maskHint($in, 'nondeductible') . ')'
                . ' — doplňte účet a spusťte zaúčtování znovu.',
            );
            return $delta;
        }
        $this->row(
            $account,
            $delta > 0 ? self::SIDE_DEBIT : self::SIDE_CREDIT,
            abs($delta),
            "DPH bez nároku na odpočet (krácení) {$in->periodName}",
        );
        return $delta;
    }

    /** Krácený sloupec ř. 46 minus uplatněný odpočet ř. 52 — přesné hodnoty. */
    private static function nondeductible(array $exactRows): float
    {
        return round(($exactRows[46]['taxReduced'] ?? 0.0) - ($exactRows[52]['taxFull'] ?? 0.0), 2);
    }

    // ── 4. zaokrouhlení ─────────────────────────────────────────────────────

    private function addRoundingRow(VatReturnAccountingInput $in): float
    {
        if ($this->errors() !== []) {
            // Chybějící účet už doklad shodil — zbytek by byl jen šum.
            return 0.0;
        }
        [$debit, $credit] = $this->totals();
        $remainder = round($debit - $credit, 2);
        if (abs($remainder) < self::TOLERANCE) {
            return 0.0;
        }

        $tolerance = 0.5 * $this->taxRowCount($in) + 0.01;
        if (abs($remainder) > $tolerance) {
            $this->message(
                self::MSG_ROUNDING_OUT_OF_RANGE,
                VatReturnAccountingPlan::SEVERITY_ERROR,
                sprintf(
                    'Zbytek do vyrovnání %.2f Kč překračuje toleranci zaokrouhlení %.2f Kč'
                    . ' — snapshot podání neodpovídá podaným hodnotám nebo chybí mapování kódu.',
                    $remainder,
                    $tolerance,
                ),
            );
            return $remainder;
        }

        // MD > DAL → chybí DAL řádek: platíme méně než přesná daň = výnos.
        $revenue  = $remainder > 0;
        $category = $revenue ? 'roundingRevenue' : 'roundingCost';
        $account  = $in->categoryAccounts[$category] ?? null;
        if ($account === null) {
            $this->message(
                self::MSG_ACCOUNT_MISSING,
                VatReturnAccountingPlan::SEVERITY_ERROR,
                'Rozvrh nemá účet zaokrouhlení (kategorie rounding.' . ($revenue ? 'revenue' : 'cost')
                . self::maskHint($in, $category) . ')'
                . ' — doplňte účet a spusťte zaúčtování znovu.',
            );
            return $remainder;
        }
        $this->row(
            $account,
            $revenue ? self::SIDE_CREDIT : self::SIDE_DEBIT,
            abs($remainder),
            "Zaokrouhlení DPH {$in->periodName}",
        );
        return $remainder;
    }

    /**
     * Dodatek hlášky „, maska 548 / 548“ z masek předpisu pro kategorii
     * (řetěz masek oddělený lomítkem); bez masek prázdný řetězec.
     */
    private static function maskHint(VatReturnAccountingInput $in, string $category): string
    {
        $masks = [];
        foreach ($in->categoryMasks[$category] ?? [] as $mask) {
            if ((string) $mask !== '') {
                $masks[] = (string) $mask;
            }
        }
        return $masks === [] ? '' : ', maska ' . implode(' / ', $masks);
    }

    /** Počet řádků DP3 s nenulovou daní (tohoto i předchozího podání) — každý se zaokrouhluje zvlášť. */
    private function taxRowCount(VatReturnAccountingInput $in): int
    {
        $rows = [];
        foreach ([$in->exactRows, $in->previousExactRows] as $set) {
            foreach ($set as $row => $values) {
                if (in_array((int) $row, self::SUM_ROWS, true)) {
                    continue;
                }
                if (abs($values['taxFull'] ?? 0.0) >= self::TOLERANCE || abs($values['taxReduced'] ?? 0.0) >= self::TOLERANCE) {
                    $rows[(int) $row] = true;
                }
            }
        }
        return count($rows);
    }

    // ── pomocné ─────────────────────────────────────────────────────────────

    /**
     * @param array{id: int, number: string} $account
     * @param array<string, mixed> $identity saldo pole řádku
     */
    private function row(array $account, int $side, float $amount, string $description, array $identity = []): void
    {
        $this->rows[] = [
            'account'        => (int) $account['id'],
            'account_number' => (string) $account['number'],
            'acc_side'       => $side,
            'amount'         => round($amount, 2),
            'description'    => $description,
        ] + $identity;
    }

    private function message(string $code, string $severity, string $message): void
    {
        $this->messages[] = ['code' => $code, 'severity' => $severity, 'message' => $message];
    }

    /** @return list<array{code: string, severity: string, message: string}> */
    private function errors(): array
    {
        return array_values(array_filter(
            $this->messages,
            static fn (array $m): bool => $m['severity'] === VatReturnAccountingPlan::SEVERITY_ERROR,
        ));
    }

    /** @return array{0: float, 1: float} Σ MD, Σ DAL */
    private function totals(): array
    {
        $debit = 0.0;
        $credit = 0.0;
        foreach ($this->rows as $row) {
            if ($row['acc_side'] === self::SIDE_DEBIT) {
                $debit += $row['amount'];
            } else {
                $credit += $row['amount'];
            }
        }
        return [round($debit, 2), round($credit, 2)];
    }
}

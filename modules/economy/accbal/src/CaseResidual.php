<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accbal;

/**
 * Agregát řádků klíče případu nad ledgerem pro lookup a contributory
 * ({@see LedgerOpenItemLookup::caseResidual}, #79 D3b/D3c): Σ předpisů
 * a Σ úhrad v měně případu i domácí, účet prvního předpisu (vč. analytiky)
 * a účet prvního řádku (u platby bez předpisu účet úhrady). Datová třída
 * bez DB; sčítání je čistá funkce nad řádky, aby ji unit testy pokryly
 * bez ledgeru.
 */
final readonly class CaseResidual
{
    public function __construct(
        public float $requested,
        public float $requestedHc,
        public float $paid,
        public float $paidHc,
        public ?string $firstRequestAccount,
        public string $firstAccount,
    ) {}

    /**
     * @param non-empty-list<array<string, mixed>|\Dibi\Row> $rows řádky klíče (bal_side, account_number, amount, amount_hc)
     */
    public static function fromRows(array $rows): self
    {
        $requested   = 0.0;
        $requestedHc = 0.0;
        $paid        = 0.0;
        $paidHc      = 0.0;
        $requestAccount = null;
        foreach ($rows as $r) {
            $amount   = (float) $r['amount'];
            $amountHc = (float) ($r['amount_hc'] ?? 0);
            if ((int) $r['bal_side'] === 0) {
                $requested   += $amount;
                $requestedHc += $amountHc;
                $requestAccount ??= (string) $r['account_number'];
            } else {
                $paid   += $amount;
                $paidHc += $amountHc;
            }
        }
        return new self(
            round($requested, 2),
            round($requestedHc, 2),
            round($paid, 2),
            round($paidHc, 2),
            $requestAccount,
            (string) $rows[0]['account_number'],
        );
    }

    /** Σ předpisy − Σ úhrady v měně případu, se znaménkem. */
    public function residual(): float
    {
        return round($this->requested - $this->paid, 2);
    }

    /** Σ předpisy − Σ úhrady v domácí měně, se znaménkem. */
    public function residualHc(): float
    {
        return round($this->requestedHc - $this->paidHc, 2);
    }

    /**
     * Kurz předpisů případu = Σ amount_hc / Σ amount předpisů (D3c) —
     * v domácí měně 1. Bez předpisu nebo při nulové Σ vrací 1.
     */
    public function requestRate(): float
    {
        return abs($this->requested) > 0.0 ? $this->requestedHc / $this->requested : 1.0;
    }
}

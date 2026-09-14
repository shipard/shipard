<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accbal;

use Shipard\Core\Accounting\AbstractOpenItemLookup;
use Shipard\Core\Accounting\OpenItem;

/**
 * Dohledání otevřeného předpisu nad saldo deníkem (economy_accbal_ledger) —
 * poskytovatel `openItemLookup` modulu economy.accbal (#69 D3/D8, T1).
 *
 * Cílové skupiny pro směr se odvozují z nastavení saldokont, ne z kódu
 * skupiny (kód je volně editovatelný) ani z čísel účtů v kódu: směr určuje
 * přirozenou stranu předpisu (příjem → předpis vzniká na MD, výdaj → na DAL)
 * a cílem je každá skupina s řádkem nastavení `bal_side = předpis` na té
 * straně, s kladnými částkami bez otočení znaménka. Prefixy těchto řádků
 * jsou zároveň účty, na kterých se v ledgeru hledají řádky klíče. Na seedu
 * tedy příjem prohledá Pohledávky (311), výdaj Závazky (321, 325, 331, 336,
 * 341, 342, 345, 379); skupiny záloh a úvěrů následují v pořadí nastavení.
 * Dobropisový řádek 311 uvnitř Závazků (záporné částky, modify_sign) mezi
 * prefixy není, takže dobropisy reziduum ani cílový účet neovlivní. Skupina
 * „Nespárované platby“ nemá řádek předpisu → nikdy se neprohledá. Vrácený
 * účet je účet skutečného předpisu vč. analytiky (311100, 336101…).
 *
 * Klíč případu = {@see CaseQuery} (skupina, období, partner, VS, SS, měna;
 * #69 D1/D11): normalizovaný vstup, rovnost přes idx_case, prázdný SS
 * `IS NULL`. Reziduum klíče = Σ předpisy − Σ úhrady (v měně dokladu) v
 * rámci období; počítá se v PHP nad řádky klíče (jednotky řádků). Lookup je
 * užší než případ — bere jen řádky na předpisových účtech skupiny (viz
 * výše); CaseQuery agreguje celou skupinu. Pravidlo 1 z D5; pravidla 2–3
 * přidá T3.
 */
final class LedgerOpenItemLookup extends AbstractOpenItemLookup
{
    /** Aktivní docState (archivní sada) pro nastavení saldokont. */
    private const ACTIVE_STATES = [10, 40, 80];

    /** Strana, na které předpis pro daný směr vzniká (acc_side: 0 = MD, 1 = DAL); směr 1 = příjem, 2 = výdaj. */
    private const DIRECTION_SIDE = [1 => 0, 2 => 1];

    private const TOLERANCE = 0.005;

    /** @var array<int, list<array{balance: int, prefixes: list<string>}>> směr → cíle (cache) */
    private array $targets = [];

    public function findOpenRequest(
        int $partner,
        string $paymentReference,
        string $specificSymbol,
        string $currency,
        int $direction,
        ?int $fiscalYear,
        ?string $excludeSourceKind = null,
        ?int $excludeSourceId = null,
    ): ?OpenItem {
        $paymentReference = CaseQuery::normalizeSymbol($paymentReference);
        if (
            $this->db === null || $paymentReference === null || $fiscalYear === null
            || !isset(self::DIRECTION_SIDE[$direction])
        ) {
            return null;
        }
        $key = [
            'fiscal_year'       => $fiscalYear,
            'partner'           => $partner,
            'payment_reference' => $paymentReference,
            'specific_symbol'   => CaseQuery::normalizeSymbol($specificSymbol),
            'currency'          => CaseQuery::normalizeCurrency($currency),
        ];

        foreach ($this->targetsForDirection($direction) as $target) {
            $rows = $this->loadKeyRows(
                ['balance' => $target['balance']] + $key,
                $target['prefixes'],
                $excludeSourceKind,
                $excludeSourceId,
            );
            $item = $this->residualOf($target['balance'], $rows);
            if ($item !== null) {
                return $item;
            }
        }
        return null;
    }

    /**
     * Cílové skupiny pro směr s prefixy jejich předpisových účtů — z nastavení
     * saldokont, viz třídní komentář. Pořadí skupin dle sort_order nastavení,
     * první zásah vyhrává.
     *
     * @return list<array{balance: int, prefixes: list<string>}>
     */
    private function targetsForDirection(int $direction): array
    {
        if (isset($this->targets[$direction])) {
            return $this->targets[$direction];
        }

        $rows = $this->db->fetchAll(
            'SELECT a.[balance], a.[account_number]
             FROM [economy_accbal_balance_accounts] a
             JOIN [economy_accbal_balances] b ON b.[id] = a.[balance]
             WHERE a.[bal_side] = 0 AND a.[modify_sign] = 0 AND a.[amounts_sign] IN (0, 1)
               AND a.[acc_side] = %i
               AND a.[docState] IN %in AND b.[docState] IN %in
             ORDER BY b.[sort_order], a.[sort_order], a.[id]',
            self::DIRECTION_SIDE[$direction],
            self::ACTIVE_STATES,
            self::ACTIVE_STATES,
        );

        /** @var array<int, list<string>> $byBalance */
        $byBalance = [];
        foreach ($rows as $r) {
            $prefix = trim((string) $r['account_number']);
            if ($prefix === '') {
                continue;
            }
            $balance = (int) $r['balance'];
            $byBalance[$balance] ??= [];
            if (!in_array($prefix, $byBalance[$balance], true)) {
                $byBalance[$balance][] = $prefix;
            }
        }

        $out = [];
        foreach ($byBalance as $balance => $prefixes) {
            $out[] = ['balance' => $balance, 'prefixes' => $prefixes];
        }

        return $this->targets[$direction] = $out;
    }

    /**
     * Řádky ledgeru klíče ve skupině — jen na účtech s prefixem některého
     * předpisového řádku nastavení (řádky s modify_sign zůstávají mimo hru).
     * Rovnost klíče přes {@see CaseQuery::keyConditions} (idx_case).
     *
     * @param array<string, mixed> $key úplný klíč případu vč. balance
     * @param non-empty-list<string> $prefixes
     * @return list<array<string, mixed>|\Dibi\Row>
     */
    private function loadKeyRows(
        array $key,
        array $prefixes,
        ?string $excludeSourceKind,
        ?int $excludeSourceId,
    ): array {
        [$conds, $args] = CaseQuery::keyConditions($key, 'l');

        $conds[] = '(' . implode(' OR ', array_fill(0, count($prefixes), 'l.[account_number] LIKE %like~')) . ')';
        $args = [...$args, ...$prefixes];

        if ($excludeSourceKind !== null && $excludeSourceId !== null) {
            $conds[] = 'NOT (l.[source_kind] = %s AND l.[source_id] = %i)';
            $args[]  = $excludeSourceKind;
            $args[]  = $excludeSourceId;
        }

        return $this->db->fetchAll(
            'SELECT l.[id], l.[bal_side], l.[account_number], l.[amount]
             FROM [economy_accbal_ledger] l
             WHERE ' . implode(' AND ', $conds) . '
             ORDER BY l.[id]',
            ...$args,
        );
    }

    /**
     * Σ předpisy − Σ úhrady; účet = účet prvního předpisového řádku.
     *
     * @param list<array<string, mixed>|\Dibi\Row> $rows
     */
    private function residualOf(int $balance, array $rows): ?OpenItem
    {
        $requested = 0.0;
        $paid      = 0.0;
        $account   = null;
        foreach ($rows as $r) {
            $amount = (float) $r['amount'];
            if ((int) $r['bal_side'] === 0) {
                $requested += $amount;
                $account ??= (string) $r['account_number'];
            } else {
                $paid += $amount;
            }
        }
        if ($account === null) {
            return null;
        }
        $residual = round($requested - $paid, 2);
        if ($residual <= self::TOLERANCE) {
            return null;
        }
        return new OpenItem($balance, $account, $residual);
    }
}

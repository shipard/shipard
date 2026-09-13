<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accbal;

use Shipard\Core\Accounting\AbstractOpenItemLookup;
use Shipard\Core\Accounting\OpenItem;

/**
 * Dohledání otevřeného předpisu nad saldo deníkem (economy_accbal_ledger) —
 * poskytovatel `openItemLookup` modulu economy.accbal (#69 D3/D8, T1).
 *
 * Cílová skupina pro směr se odvozuje z nastavení saldokont, ne z kódu
 * skupiny (kód je volně editovatelný): řádek nastavení s `bal_side = předpis`,
 * kladnými částkami bez otočení znaménka, stranou odpovídající směru (příjem
 * → předpis vzniká na MD, výdaj → na DAL) a prefixem slučitelným s přirozeným
 * účtem směru (311 / 321). Na seedu to dá přesně (Pohledávky, 311) a
 * (Závazky, 321); dobropisový řádek 311 uvnitř Závazků (záporné částky,
 * modify_sign) se nevybere, a řádky ledgeru se navíc filtrují prefixem účtu,
 * takže dobropisy reziduum ani cílový účet neovlivní. Skupina „Nespárované
 * platby“ nemá řádek předpisu → nikdy se neprohledá.
 *
 * Reziduum klíče = Σ předpisy − Σ úhrady (v měně dokladu) přes všechny
 * fiskální roky; počítá se v PHP nad řádky klíče (jednotky řádků), SQL drží
 * jen přesnou shodu klíče. Pravidlo 1 z D5; pravidla 2–3 přidá T3.
 */
final class LedgerOpenItemLookup extends AbstractOpenItemLookup
{
    /** Aktivní docState (archivní sada) pro nastavení saldokont. */
    private const ACTIVE_STATES = [10, 40, 80];

    /** Přirozený účet předpisu per směr úhrady (1 = příjem, 2 = výdaj). */
    private const DIRECTION_PREFIX = [1 => '311', 2 => '321'];

    /** Strana, na které předpis pro daný směr vzniká (acc_side: 0 = MD, 1 = DAL). */
    private const DIRECTION_SIDE = [1 => 0, 2 => 1];

    private const TOLERANCE = 0.005;

    /** @var array<int, list<array{balance: int, prefix: string}>> směr → cíle (cache) */
    private array $targets = [];

    public function findOpenRequest(
        int $partner,
        string $paymentReference,
        string $specificSymbol,
        string $currency,
        int $direction,
        ?string $excludeSourceKind = null,
        ?int $excludeSourceId = null,
    ): ?OpenItem {
        $paymentReference = trim($paymentReference);
        if ($this->db === null || $paymentReference === '' || !isset(self::DIRECTION_PREFIX[$direction])) {
            return null;
        }
        $specificSymbol = trim($specificSymbol);
        $currency = strtolower(trim($currency));

        foreach ($this->targetsForDirection($direction) as $target) {
            $rows = $this->loadKeyRows(
                $target['balance'], $target['prefix'],
                $partner, $paymentReference, $specificSymbol, $currency,
                $excludeSourceKind, $excludeSourceId,
            );
            $item = $this->residualOf($target['balance'], $rows);
            if ($item !== null) {
                return $item;
            }
        }
        return null;
    }

    /**
     * Cílové dvojice (skupina, efektivní prefix účtu) pro směr — z nastavení
     * saldokont, viz třídní komentář. Efektivní prefix = delší z prefixu
     * nastavení a přirozeného prefixu směru (nastavení „3111“ zúží, „31“ se
     * rozšíří na 311).
     *
     * @return list<array{balance: int, prefix: string}>
     */
    private function targetsForDirection(int $direction): array
    {
        if (isset($this->targets[$direction])) {
            return $this->targets[$direction];
        }
        $natural = self::DIRECTION_PREFIX[$direction];

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

        $out  = [];
        $seen = [];
        foreach ($rows as $r) {
            $prefix = trim((string) $r['account_number']);
            if ($prefix === '' || (!str_starts_with($prefix, $natural) && !str_starts_with($natural, $prefix))) {
                continue;
            }
            $effective = strlen($prefix) >= strlen($natural) ? $prefix : $natural;
            $key = ((int) $r['balance']) . '|' . $effective;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = ['balance' => (int) $r['balance'], 'prefix' => $effective];
        }

        return $this->targets[$direction] = $out;
    }

    /**
     * Řádky ledgeru klíče v cílové skupině (jen účty s prefixem cíle).
     *
     * @return list<array<string, mixed>|\Dibi\Row>
     */
    private function loadKeyRows(
        int $balance,
        string $prefix,
        int $partner,
        string $paymentReference,
        string $specificSymbol,
        string $currency,
        ?string $excludeSourceKind,
        ?int $excludeSourceId,
    ): array {
        $sql = 'SELECT [id], [bal_side], [account_number], [amount]
                FROM [economy_accbal_ledger]
                WHERE [balance] = %i AND [account_number] LIKE %like~ AND [partner] = %i
                  AND TRIM([payment_reference]) = %s
                  AND TRIM(COALESCE([specific_symbol], \'\')) = %s
                  AND LOWER([currency]) = %s';
        $args = [$balance, $prefix, $partner, $paymentReference, $specificSymbol, $currency];

        if ($excludeSourceKind !== null && $excludeSourceId !== null) {
            $sql   .= ' AND NOT ([source_kind] = %s AND [source_id] = %i)';
            $args[] = $excludeSourceKind;
            $args[] = $excludeSourceId;
        }
        $sql .= ' ORDER BY [id]';

        return $this->db->fetchAll($sql, ...$args);
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

<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accbal;

/**
 * Saldokontní případ jako agregát nad ledgerem (#69 D1, D10, D11).
 *
 * Případ není entita ani sloupec: je to n-tice klíče
 * (balance, fiscal_year, partner, payment_reference, specific_symbol, currency)
 * a jeho stav je Σ předpisy − Σ úhrady z `economy_accbal_ledger`. Tahle
 * třída je jediné místo, kde je klíč a agregát definován — sdílí ji lookup
 * ({@see LedgerOpenItemLookup}), routing ({@see ClearingRouter},
 * {@see ClearingRerouteHandler}) i viewery (případy, pohyby).
 *
 * Normalizace klíče (D10) probíhá při zápisu ({@see LedgerGenerator}):
 * symboly TRIM, prázdné → NULL, měna malými písmeny. Rovnost klíče pak jde
 * přes složený index `idx_case` bez funkcí ve WHERE; prázdný symbol se
 * porovnává `IS NULL`. Stejnou normalizaci musí projít i vstupní klíč
 * ({@see normalizeKey}), jinak se index mine.
 *
 * Lookup pro bankovní engine je užší než případ: počítá jen řádky na
 * předpisových účtech skupiny (dobropisové řádky s `modify_sign` mimo hru,
 * T1). Případ agreguje celou skupinu. Sdílí se klíč a normalizace, ne filtr
 * účtů — docs/accbal.md §3.4.
 */
final class CaseQuery
{
    /** Sloupce klíče v pořadí složeného indexu `idx_case`. */
    public const KEY_COLUMNS = ['balance', 'fiscal_year', 'partner', 'payment_reference', 'specific_symbol', 'currency'];

    /** Zůstatek 0 — případ uzavřený (obchodně, v měně dokladu). */
    public const KIND_CLOSED = 'closed';
    /** Zůstatek > 0 — partner dluží (otevřený předpis). */
    public const KIND_DEBT = 'debt';
    /** Předpisy existují, zůstatek < 0 — přeplatek. */
    public const KIND_OVERPAYMENT = 'overpayment';
    /** Bez předpisu, jen úhrady — úhrada bez předpisu (typicky clearing, PD, interní doklad). */
    public const KIND_UNREQUESTED = 'unrequested';

    public const KINDS = [self::KIND_DEBT, self::KIND_OVERPAYMENT, self::KIND_UNREQUESTED, self::KIND_CLOSED];

    private const TOLERANCE = 0.005;

    private const AMOUNT_COLUMNS = ['sum_requests', 'sum_payments', 'sum_requests_hc', 'sum_payments_hc', 'residual', 'residual_hc'];

    public function __construct(private readonly \Dibi\Connection $db) {}

    // ── Normalizace klíče (D10) ─────────────────────────────────────────────

    /** TRIM, prázdné → NULL. */
    public static function normalizeSymbol(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = trim((string) $value);
        return $s === '' ? null : $s;
    }

    /** TRIM + malá písmena, prázdné → NULL. */
    public static function normalizeCurrency(mixed $value): ?string
    {
        $s = self::normalizeSymbol($value);
        return $s === null ? null : strtolower($s);
    }

    /**
     * Normalizovaný (i částečný) klíč z řádku ledgeru nebo libovolného pole:
     * bere jen sloupce klíče, které v poli jsou; int sloupce přetypuje
     * (prázdné → NULL), symboly a měnu normalizuje.
     *
     * @param array<string, mixed>|\Dibi\Row $row
     * @return array<string, int|string|null>
     */
    public static function normalizeKey(array|\Dibi\Row $row): array
    {
        $row = self::toArray($row);
        $key = [];
        foreach (self::KEY_COLUMNS as $col) {
            if (!array_key_exists($col, $row)) {
                continue;
            }
            $v = $row[$col];
            $key[$col] = match ($col) {
                'balance', 'fiscal_year', 'partner' => $v === null || $v === '' ? null : (int) $v,
                'currency'                          => self::normalizeCurrency($v),
                default                             => self::normalizeSymbol($v),
            };
        }
        return $key;
    }

    // ── SQL stavební kameny ─────────────────────────────────────────────────

    /**
     * Podmínky rovnosti (i částečného) klíče pro WHERE: `alias.[col] = %x`,
     * NULL hodnota → `IS NULL` (bez parametru). Klíč se normalizuje.
     * Pořadí = KEY_COLUMNS, aby dotaz šel po indexu `idx_case`.
     *
     * @param array<string, mixed> $key
     * @return array{0: list<string>, 1: list<int|string>} [podmínky, parametry]
     */
    public static function keyConditions(array $key, string $alias = 'l'): array
    {
        $prefix = self::prefix($alias);
        $conditions = [];
        $params = [];
        foreach (self::normalizeKey($key) as $col => $v) {
            $column = $prefix . '[' . $col . ']';
            if ($v === null) {
                $conditions[] = $column . ' IS NULL';
                continue;
            }
            $conditions[] = $column . (is_int($v) ? ' = %i' : ' = %s');
            $params[] = $v;
        }
        return [$conditions, $params];
    }

    /** Sloupce klíče s aliasem — pro SELECT i GROUP BY. */
    public static function keyColumnsSql(string $alias = 'l'): string
    {
        $prefix = self::prefix($alias);
        return implode(', ', array_map(static fn(string $c) => $prefix . '[' . $c . ']', self::KEY_COLUMNS));
    }

    /**
     * Agregační výrazy případu pro SELECT nad `GROUP BY` klíče: Σ předpisy a
     * úhrady v obou měnách, zůstatek obchodní (`residual`, měna dokladu) i
     * účetní (`residual_hc`, docs/accbal.md §6), splatnost = nejstarší
     * předpis klíče, počet pohybů, `row_id` = MIN(id) jako stabilní
     * identifikátor řádku případu pro viewer (detail z něj odvodí klíč přes
     * {@see keyOfRow}) a domácí měna.
     */
    public static function aggregateColumnsSql(string $alias = 'l'): string
    {
        $p = self::prefix($alias);
        return implode(', ', [
            "SUM(CASE WHEN {$p}[bal_side] = 0 THEN {$p}[amount] ELSE 0 END) AS sum_requests",
            "SUM(CASE WHEN {$p}[bal_side] = 1 THEN {$p}[amount] ELSE 0 END) AS sum_payments",
            "SUM(CASE WHEN {$p}[bal_side] = 0 THEN {$p}[amount_hc] ELSE 0 END) AS sum_requests_hc",
            "SUM(CASE WHEN {$p}[bal_side] = 1 THEN {$p}[amount_hc] ELSE 0 END) AS sum_payments_hc",
            "SUM(CASE WHEN {$p}[bal_side] = 0 THEN {$p}[amount] ELSE -{$p}[amount] END) AS residual",
            "SUM(CASE WHEN {$p}[bal_side] = 0 THEN {$p}[amount_hc] ELSE -{$p}[amount_hc] END) AS residual_hc",
            "MIN(CASE WHEN {$p}[bal_side] = 0 THEN {$p}[due_date] END) AS due_date",
            "COUNT(*) AS moves",
            "MIN({$p}[id]) AS row_id",
            "MAX({$p}[home_currency]) AS home_currency",
        ]);
    }

    /**
     * Podmínka typu otevřenosti nad agregátem (sloupce `residual`,
     * `sum_requests` z {@see aggregateColumnsSql}) — pro WHERE nad derived
     * table (alias) nebo HAVING (alias ''). Neznámý typ → null.
     */
    public static function kindConditionSql(string $kind, string $alias = 'c'): ?string
    {
        $p = self::prefix($alias);
        $residual = $p . '[residual]';
        $requests = $p . '[sum_requests]';
        return match ($kind) {
            self::KIND_DEBT        => "{$residual} > 0",
            self::KIND_OVERPAYMENT => "{$requests} <> 0 AND {$residual} < 0",
            self::KIND_UNREQUESTED => "{$requests} = 0 AND {$residual} <> 0",
            self::KIND_CLOSED      => "{$residual} = 0",
            default                => null,
        };
    }

    /** Podmínka „případ je otevřený" (zůstatek ≠ 0) nad agregátem. */
    public static function openConditionSql(string $alias = 'c'): string
    {
        return self::prefix($alias) . '[residual] <> 0';
    }

    /**
     * Zůstatek případu daného řádku ledgeru jako korelovaný skalární
     * subdotaz (měna dokladu, při $homeCurrency domácí) — viewer pohybů:
     * sloupec Zůstatek případu a filtr „jen otevřené" (= otevřenost
     * případu). Rovnost klíče NULL-safe (`<=>`) nad idx_case, per řádek
     * bodové dohledání.
     *
     * Záměrně ne LEFT JOIN na derived table s GROUP BY: MariaDB 10.11
     * (optimalizace split_materialized) při bodovém dotazu (`WHERE l.id = ?`)
     * s `<=>` v ON vrací NULL. Volba splitu je cost-based, takže by se
     * chyba projevila nepředvídatelně i v seznamu s úzkým filtrem.
     */
    public static function residualSubquerySql(string $ledgerAlias = 'l', bool $homeCurrency = false): string
    {
        $amount = $homeCurrency ? 'amount_hc' : 'amount';
        $where = [];
        foreach (self::KEY_COLUMNS as $col) {
            // balance je NOT NULL → obyčejná rovnost (ref přes idx_case), zbytek NULL-safe.
            $where[] = 'x.[' . $col . ']' . ($col === 'balance' ? ' = ' : ' <=> ') . $ledgerAlias . '.[' . $col . ']';
        }
        return '(SELECT SUM(CASE WHEN x.[bal_side] = 0 THEN x.[' . $amount . '] ELSE -x.[' . $amount . '] END)'
            . ' FROM [economy_accbal_ledger] x WHERE ' . implode(' AND ', $where) . ')';
    }

    // ── Klasifikace v PHP (detail, testy) ───────────────────────────────────

    /** Typ otevřenosti ze Σ předpisů a Σ úhrad (měna dokladu), tolerance půl haléře. */
    public static function kindOf(float $requests, float $payments): string
    {
        $residual = round($requests - $payments, 2);
        if (abs($residual) <= self::TOLERANCE) {
            return self::KIND_CLOSED;
        }
        if ($residual > 0) {
            return self::KIND_DEBT;
        }
        return abs($requests) <= self::TOLERANCE ? self::KIND_UNREQUESTED : self::KIND_OVERPAYMENT;
    }

    /**
     * Doplní agregátu odvozené hodnoty: float částky, int počet, `kind`,
     * `is_open`, `days_overdue` (jen dluh po splatnosti, k dnešku; nic se
     * neukládá).
     *
     * @param array<string, mixed>|\Dibi\Row $agg
     * @return array<string, mixed>
     */
    public static function decorate(array|\Dibi\Row $agg, ?\DateTimeInterface $today = null): array
    {
        $agg = self::toArray($agg);
        foreach (self::AMOUNT_COLUMNS as $c) {
            $agg[$c] = (float) ($agg[$c] ?? 0);
        }
        $agg['moves'] = (int) ($agg['moves'] ?? 0);
        $agg['kind'] = self::kindOf($agg['sum_requests'], $agg['sum_payments']);
        $agg['is_open'] = $agg['kind'] !== self::KIND_CLOSED;
        $agg['days_overdue'] = $agg['kind'] === self::KIND_DEBT
            ? self::daysOverdue($agg['due_date'] ?? null, $today ?? new \DateTimeImmutable('today'))
            : 0;
        return $agg;
    }

    /** Dny po splatnosti k danému dni; bez splatnosti nebo před ní 0. */
    public static function daysOverdue(mixed $dueDate, \DateTimeInterface $today): int
    {
        if ($dueDate === null || $dueDate === '') {
            return 0;
        }
        $due = $dueDate instanceof \DateTimeInterface
            ? \DateTimeImmutable::createFromInterface($dueDate)
            : \DateTimeImmutable::createFromFormat('!Y-m-d', substr((string) $dueDate, 0, 10));
        if ($due === false) {
            return 0;
        }
        $days = (int) $due->setTime(0, 0)->diff(\DateTimeImmutable::createFromInterface($today)->setTime(0, 0))->format('%r%a');
        return max(0, $days);
    }

    // ── Dotazy ──────────────────────────────────────────────────────────────

    /**
     * Jeden případ podle úplného klíče, nebo null když klíč nemá pohyby.
     *
     * @param array<string, mixed> $key všech šest sloupců klíče
     * @return array<string, mixed>|null klíč + agregát ({@see decorate})
     */
    public function caseOf(array $key): ?array
    {
        [$conditions, $params] = self::keyConditions($key, 'l');
        if (count($conditions) !== count(self::KEY_COLUMNS)) {
            throw new \InvalidArgumentException('CaseQuery::caseOf needs a complete case key: ' . implode(', ', self::KEY_COLUMNS));
        }
        $row = $this->db->fetch(
            'SELECT ' . self::keyColumnsSql('l') . ', ' . self::aggregateColumnsSql('l')
            . ' FROM [economy_accbal_ledger] l'
            . ' WHERE ' . implode(' AND ', $conditions)
            . ' GROUP BY ' . self::keyColumnsSql('l'),
            ...$params,
        );
        return $row === null ? null : self::decorate($row);
    }

    /**
     * Klíč případu z řádku ledgeru (`row_id` vieweru = MIN(id) případu, ale
     * poslouží kterýkoli pohyb klíče); null když řádek neexistuje.
     *
     * @return array<string, int|string|null>|null
     */
    public function keyOfRow(int $ledgerId): ?array
    {
        $row = $this->db->fetch(
            'SELECT ' . self::keyColumnsSql('l') . ' FROM [economy_accbal_ledger] l WHERE l.[id] = %i',
            $ledgerId,
        );
        return $row === null ? null : self::normalizeKey($row);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private static function prefix(string $alias): string
    {
        return $alias === '' ? '' : $alias . '.';
    }

    /**
     * @param array<string, mixed>|\Dibi\Row $row
     * @return array<string, mixed>
     */
    private static function toArray(array|\Dibi\Row $row): array
    {
        return $row instanceof \Dibi\Row ? $row->toArray() : $row;
    }
}

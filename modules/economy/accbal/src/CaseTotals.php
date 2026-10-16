<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accbal;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\SearchCondition;

/**
 * Součty otevřených případů saldokonta **per měna dokladu** — jeden
 * výpočet pro patičku vieweru případů ({@see CasesViewer::renderGridFooter})
 * i bloky Přehledu firmy (#120 D4, D6, D18): číslo po prokliku z přehledu
 * musí sedět s patičkou, proto obě strany volají `totals()` se stejnou
 * sadou filtrů vieweru.
 *
 * Nese i stavbu podmínek filtrů vieweru ve dvou úrovních (klíčové před
 * GROUP BY, případové nad agregátem — {@see conditions}) a vnitřní dotaz
 * agregátu ({@see casesSql}); viewer je jen používá. Nové filtry (#120):
 * `currency` (měna dokladu, klíčová úroveň — poslední sloupec `idx_case`)
 * a `dueWithin` (dluh se splatností v intervalu ⟨dnes, dnes + N dní⟩,
 * případová úroveň).
 */
final class CaseTotals
{
    public const FILTER_CURRENCY   = 'currency';
    public const FILTER_DUE_WITHIN = 'dueWithin';

    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly string $table = 'economy_accbal_ledger',
    ) {}

    /**
     * Součty per měna přes celý filtrovaný set (bez stránkování), seřazené
     * podle kódu měny. Částky `sum_*` / `residual` v měně dokladu,
     * `*_hc` v domácí měně.
     *
     * @param list<array{id: string, value: mixed}> $filters filtry ve tvaru vieweru
     * @return list<array{currency: string, home_currency: string, count: int,
     *         sum_requests: float, sum_payments: float, residual: float,
     *         sum_requests_hc: float, sum_payments_hc: float, residual_hc: float}>
     */
    public function totals(array $filters, \DateTimeImmutable $today): array
    {
        [$rowConds, $rowParams, $caseConds, $caseParams] = self::conditions($filters, $today);

        $sql = 'SELECT c.`currency`, MAX(c.`home_currency`) AS home_currency, COUNT(*) AS cnt,'
            . ' SUM(c.`sum_requests`) AS sum_requests, SUM(c.`sum_payments`) AS sum_payments,'
            . ' SUM(c.`residual`) AS residual,'
            . ' SUM(c.`sum_requests_hc`) AS sum_requests_hc, SUM(c.`sum_payments_hc`) AS sum_payments_hc,'
            . ' SUM(c.`residual_hc`) AS residual_hc'
            . ' FROM (' . self::casesSql($this->table, $rowConds) . ') c';
        if ($caseConds !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $caseConds);
        }
        $sql .= ' GROUP BY c.`currency` ORDER BY c.`currency`';

        $out = [];
        foreach ($this->db->fetchAll($sql, ...$rowParams, ...$caseParams) as $row) {
            $out[] = [
                'currency'        => strtolower((string) ($row['currency'] ?? '')),
                'home_currency'   => strtolower((string) ($row['home_currency'] ?? '')),
                'count'           => (int) ($row['cnt'] ?? 0),
                'sum_requests'    => (float) ($row['sum_requests'] ?? 0),
                'sum_payments'    => (float) ($row['sum_payments'] ?? 0),
                'residual'        => (float) ($row['residual'] ?? 0),
                'sum_requests_hc' => (float) ($row['sum_requests_hc'] ?? 0),
                'sum_payments_hc' => (float) ($row['sum_payments_hc'] ?? 0),
                'residual_hc'     => (float) ($row['residual_hc'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * Agregát případů: GROUP BY klíče nad pohyby, které prošly filtry
     * klíčové úrovně (saldokonto, období, partner, VS, SS, měna).
     *
     * @param list<string> $rowConds
     */
    public static function casesSql(string $table, array $rowConds): string
    {
        $sql = 'SELECT ' . CaseQuery::keyColumnsSql('l') . ', ' . CaseQuery::aggregateColumnsSql('l')
            . ' FROM `' . $table . '` l'
            . ' LEFT JOIN `economy_accbal_balances` b ON b.`id` = l.`balance`'
            . ' LEFT JOIN `base_persons_persons` p ON p.`id` = l.`partner`';
        if ($rowConds !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $rowConds);
        }
        return $sql . ' GROUP BY ' . CaseQuery::keyColumnsSql('l');
    }

    /**
     * Filtry vieweru případů ve dvou úrovních: klíčové → vnitřní dotaz
     * (alias `l`, `b`, `p`; před GROUP BY), případové → nad agregátem
     * (alias `c`). Bez „Včetně uzavřených" se přidá otevřenost případu;
     * typ Uzavřeno ji implicitně vypne.
     *
     * @param list<array{id: string, value: mixed}> $filters
     * @return array{0: list<string>, 1: list<mixed>, 2: list<string>, 3: list<mixed>}
     *         [rowConds, rowParams, caseConds, caseParams]
     */
    public static function conditions(array $filters, \DateTimeImmutable $today): array
    {
        $rowConds = [];
        $rowParams = [];
        $caseConds = [];
        $caseParams = [];
        $includeClosed = false;
        $todayIso = $today->format('Y-m-d');

        foreach ($filters as $filter) {
            $id = $filter['id'] ?? null;
            $value = $filter['value'] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $value = (string) $value;
            if ($id === 'viewGroup') {
                [$cond, $param] = self::viewGroupConditionSql($value);
                if ($cond !== null) {
                    $rowConds[] = $cond;
                    $rowParams[] = $param;
                }
            } elseif ($id === 'fiscal_year') {
                // Období je součást klíče → řádková úroveň (idx_case), menší
                // agregát; výsledek totožný s podmínkou nad `c`.
                $rowConds[] = 'l.`fiscal_year` = %i';
                $rowParams[] = (int) $value;
            } elseif ($id === 'partner') {
                $rowConds[] = SearchCondition::contains('p.`full_name`');
                $rowParams[] = $value;
            } elseif ($id === 'payment_reference') {
                $rowConds[] = 'l.`payment_reference` LIKE %s';
                $rowParams[] = $value . '%';
            } elseif ($id === 'specific_symbol') {
                $rowConds[] = 'l.`specific_symbol` LIKE %s';
                $rowParams[] = $value . '%';
            } elseif ($id === self::FILTER_CURRENCY) {
                // Měna dokladu je součást klíče (poslední sloupec idx_case).
                $currency = CaseQuery::normalizeCurrency($value);
                if ($currency !== null) {
                    $rowConds[] = 'l.`currency` = %s';
                    $rowParams[] = $currency;
                }
            } elseif ($id === 'include_closed' && $value === '1') {
                $includeClosed = true;
            } elseif ($id === 'kind' && in_array($value, CaseQuery::KINDS, true)) {
                $caseConds[] = CaseQuery::kindConditionSql($value, 'c');
                if ($value === CaseQuery::KIND_CLOSED) {
                    $includeClosed = true;
                }
            } elseif ($id === 'overdue' && $value === '1') {
                // Po splatnosti = dluh s nejstarším předpisem před dneškem.
                $caseConds[] = 'c.[residual] > 0 AND c.[due_date] < %s';
                $caseParams[] = $todayIso;
            } elseif ($id === self::FILTER_DUE_WITHIN && preg_match('/^\d{1,4}$/', $value)) {
                // Splatné do N dní = dluh se splatností v ⟨dnes, dnes + N⟩;
                // bez splatnosti se nepočítá. Neplatná hodnota se ignoruje.
                $caseConds[] = 'c.[residual] > 0 AND c.[due_date] >= %s AND c.[due_date] <= %s';
                $caseParams[] = $todayIso;
                $caseParams[] = $today->modify('+' . (int) $value . ' days')->format('Y-m-d');
            }
        }

        if (!$includeClosed) {
            array_unshift($caseConds, CaseQuery::openConditionSql('c'));
        }

        return [$rowConds, $rowParams, $caseConds, $caseParams];
    }

    /**
     * Podmínka viewGroup chipu (kód saldokonta): 'all' = bez podmínky;
     * 'active' defenzivně také — stale frontend z otevřené session může po
     * nasazení poslat starý docState default. Vrací [podmínka|null, parametr].
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function viewGroupConditionSql(string $value, string $balancesAlias = 'b'): array
    {
        if ($value === 'all' || $value === 'active') {
            return [null, null];
        }
        return [$balancesAlias . '.`code` = %s', $value];
    }

    /**
     * Mapa `id → hodnota` (tvar `filters` + `viewGroup` v akci `open_viewer`)
     * → seznam filtrů ve tvaru vieweru. Přehled firmy tak předává
     * `totals()` přesně to, co vloží do prokliku.
     *
     * @param array<string, mixed> $map
     * @return list<array{id: string, value: mixed}>
     */
    public static function filtersFromMap(array $map): array
    {
        $out = [];
        foreach ($map as $id => $value) {
            $out[] = ['id' => (string) $id, 'value' => $value];
        }
        return $out;
    }
}

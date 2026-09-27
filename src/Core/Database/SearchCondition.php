<?php

declare(strict_types=1);

namespace Shipard\Core\Database;

/**
 * Volné textové hledání bez ohledu na diakritiku.
 *
 * Sloupce mají `utf8mb4_czech_ci`, která rozlišuje háčky (č, ř, š, ž a „ch“
 * jsou v češtině samostatná písmena) — řazení ji potřebuje, hledání ne.
 * Proto se hledaný text porovnává v jazykově neutrální collation nastavené
 * explicitně na parametru; sloupce, indexy ani ORDER BY se nemění.
 * Vyžaduje MariaDB ≥ 10.10 (uca1400) a utf8mb4 spojení (Dibi ho nastavuje).
 *
 * Parametr je surový hledaný text — `%` obaluje Dibi (`%~like~`) a zároveň
 * escapuje `%`, `_` a `\`. Volající už nesmí obalovat `'%' . $x . '%'`.
 * Strukturální prefixy (čísla účtů, symboly) sem nepatří. Viz issue #18.
 */
final class SearchCondition
{
    public const COLLATION = 'utf8mb4_uca1400_ai_ci';

    private function __construct()
    {
    }

    /**
     * Podmínka „sloupec obsahuje hledaný text“ pro jeden hotový SQL výraz
     * sloupce (včetně aliasu a backticků, např. "p.`full_name`").
     * Očekává jeden parametr: surový hledaný text.
     */
    public static function contains(string $columnSql): string
    {
        return $columnSql . ' LIKE %~like~ COLLATE ' . self::COLLATION;
    }

    /**
     * Podmínka „některý ze sloupců obsahuje hledaný text“ včetně parametrů.
     * Prázdný seznam sloupců nebo prázdný text → ['', []].
     *
     * @param list<string> $columnSqls hotové SQL výrazy sloupců
     * @return array{0: string, 1: list<string>} [sql_fragment, params]
     */
    public static function anyContains(array $columnSqls, string $term): array
    {
        if ($columnSqls === [] || $term === '') {
            return ['', []];
        }

        $parts = [];
        $params = [];
        foreach ($columnSqls as $columnSql) {
            $parts[] = self::contains($columnSql);
            $params[] = $term;
        }

        return ['(' . implode(' OR ', $parts) . ')', $params];
    }
}

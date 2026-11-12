<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accounting;

/**
 * Vyhodnocení filtru `query` účtovacího předpisu nad zdrojovým záznamem —
 * jedno místo pro kroky i kategorie `accounts[]` v {@see AccountingEngine}
 * a pro čtení pravidel mimo engine ({@see VatAnalytics}).
 *
 * `query` = `{sloupec: hodnota}`; porovnání je volné (DB vrací řetězce,
 * předpis píše čísla). Hodnota-pole je operátorový objekt: `{"$ne": v}`
 * (nerovnost), `{"$in": [v, …]}`. Neznámý operátor je chyba předpisu a padá
 * hlasitě — tiché „nikdy nematchne“ by krok jen zmizel.
 */
final class RuleQuery
{
    /**
     * @param mixed $query `query` kroku nebo pravidla; ne-pole = bez filtru
     * @param array<string, mixed> $record zdrojový záznam
     */
    public static function matches(mixed $query, array $record): bool
    {
        if (!is_array($query)) {
            return true;
        }
        foreach ($query as $col => $expected) {
            $actual = $record[$col] ?? null;
            if (is_array($expected)) {
                foreach ($expected as $op => $value) {
                    $ok = match ((string) $op) {
                        '$ne'   => $actual != $value,
                        '$in'   => in_array($actual, is_array($value) ? $value : [$value]),
                        default => throw new \LogicException(
                            "Účtovací předpis: neznámý operátor '{$op}' ve query sloupce '{$col}'",
                        ),
                    };
                    if (!$ok) {
                        return false;
                    }
                }
                continue;
            }
            if ($actual != $expected) {
                return false;
            }
        }
        return true;
    }
}

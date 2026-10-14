<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Compare;

/**
 * Rovnocenné tvary (docs/test-corpus.md § 10) — porovnávač je uplatní na
 * obě strany po projekci a normalizaci:
 *
 *   1. `null` ≡ chybějící klíč (dává už {@see Projection::project()});
 *   2. `totals.totalRounding` `0` ≡ `null`;
 *   3. prázdné pole (`rowExceptions`, `vatRecap`) ≡ `null`;
 *   4. objekt se všemi listy `null` (`contact`, `bankAccount`, `address`,
 *      `customer`) ≡ `null`.
 */
final class EquivalentForms
{
    private const ZERO_IS_NULL = ['totals.totalRounding'];

    /**
     * @param array<string, mixed> $projected
     * @return array<string, mixed>
     */
    public static function collapse(array $projected): array
    {
        /** @var array<string, mixed> $out */
        $out = self::collapseNode($projected, '') ?? [];
        return $out;
    }

    private static function collapseNode(mixed $value, string $path): mixed
    {
        if (is_array($value)) {
            if ($value === []) {
                return null;
            }
            if (array_is_list($value)) {
                $out = [];
                foreach ($value as $i => $item) {
                    $out[] = self::collapseNode($item, "{$path}[{$i}]");
                }
                return $out;
            }
            $out = [];
            $allNull = true;
            foreach ($value as $key => $item) {
                $childPath = $path === '' ? (string) $key : "{$path}.{$key}";
                $collapsed = self::collapseNode($item, $childPath);
                $out[$key] = $collapsed;
                if ($collapsed !== null) {
                    $allNull = false;
                }
            }
            return $allNull ? null : $out;
        }
        if (in_array(Projection::nodePath($path), self::ZERO_IS_NULL, true)
            && is_numeric($value) && abs((float) $value) < 1e-9
        ) {
            return null;
        }
        return $value;
    }
}

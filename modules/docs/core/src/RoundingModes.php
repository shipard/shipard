<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

/**
 * Zaokrouhlovací módy dokladu (`total_rounding_mode`, `vat_rounding_mode`).
 *
 * Autorita sémantiky kódů je tato tabulka, ne jsonc: `DocDocument` běží
 * i s `config = null` (unit testy, část cest applieru) a zaokrouhlení nesmí
 * na konfiguraci záviset. `config/roundingModes.jsonc` a
 * `config/vatRoundingModes.jsonc` nesou jen názvy pro UI — test
 * `RoundingModesTest` hlídá, že množiny kódů souhlasí.
 *
 * Historický kód 2 („matematicky na 0,01“) byl totožný s 0 a `ds-upgrade`
 * ho slévá do 0 (#63). Neznámý kód se chová jako 0 — přesně to, co 2 dělal,
 * takže řádky, které migrace ještě nepotkala, počítají dál správně.
 *
 * Módy `up`/`down` mají u záporných částek (dobropisy) matematickou
 * sémantiku PHP ceil/floor: ceil(-1709.05) = -1709.0. Derivace módu
 * v Exchange applieru vybírá mód porovnáním výsledku s deklarovanou
 * částkou, takže směr vždy odpovídá faktuře.
 */
final class RoundingModes
{
    public const ON_CENT        = 0;  // 0,01 matematicky
    public const MATH_UNIT      = 1;  // 1 matematicky
    public const UP_UNIT        = 3;  // 1 nahoru (ceil)
    public const DOWN_UNIT      = 4;  // 1 dolů (floor)
    public const MATH_FIVE_CENT = 5;  // 0,05 matematicky (hotovost SK a část eurozóny)

    /** Módy povolené pro `vat_rounding_mode` = klíče cfgItem docs.core.vatRoundingModes. */
    public const VAT_MODES = [self::ON_CENT, self::MATH_UNIT];

    /** @var array<int, array{step: float, dir: 'math'|'up'|'down'}> */
    public const TABLE = [
        self::ON_CENT        => ['step' => 0.01, 'dir' => 'math'],
        self::MATH_UNIT      => ['step' => 1.0,  'dir' => 'math'],
        self::UP_UNIT        => ['step' => 1.0,  'dir' => 'up'],
        self::DOWN_UNIT      => ['step' => 1.0,  'dir' => 'down'],
        self::MATH_FIVE_CENT => ['step' => 0.05, 'dir' => 'math'],
    ];

    public static function isKnown(int $mode): bool
    {
        return isset(self::TABLE[$mode]);
    }

    /**
     * Matematický mód pro krok hotovostního zaokrouhlení z konfigurace
     * `world.base.cashRounding` (1 → MATH_UNIT, 0,05 → MATH_FIVE_CENT, #73 U2);
     * null pro krok bez módu. Jen `math` — hotovost se zaokrouhluje
     * matematicky, směrové módy jsou pro ruční volbu.
     */
    public static function forStep(float $step): ?int
    {
        foreach (self::TABLE as $mode => $def) {
            if ($def['dir'] === 'math' && abs($def['step'] - $step) < 1e-6) {
                return $mode;
            }
        }
        return null;
    }

    /**
     * Zaokrouhlí částku podle módu. Výsledek má vždy nejvýš 2 desetinná
     * místa (`scale: 2` sloupců), i pro neznámý mód.
     */
    public static function apply(float $amount, int $mode): float
    {
        $def = self::TABLE[$mode] ?? self::TABLE[self::ON_CENT];
        $step = $def['step'];

        // Normalizace před round/ceil/floor: 1709.05 / 0.05 vyjde
        // 34180.999999…, bez ní by ceil/floor u kroku 0,05 uskočil o krok
        // a math by v půli intervalu kolísal.
        $q = round($amount / $step, 6);
        $q = match ($def['dir']) {
            'up'   => ceil($q),
            'down' => floor($q),
            default => round($q),
        };

        return round($q * $step, 2);
    }
}

<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Document;

use Shipard\Module\Docs\Core\RoundingModes;

/**
 * Názvy zaokrouhlovacích módů ve výměnném formátu dokladu ↔ kódy
 * {@see RoundingModes} (#166 D25): `totals.totalRoundingMode` ↔
 * `total_rounding_mode`, `vat.roundingMode` ↔ `vat_rounding_mode`.
 *
 * Jediné místo názvů pro applier i exporter. Úplnost proti
 * `RoundingModes::TABLE` a `RoundingModes::VAT_MODES` hlídá
 * `RoundingModeNamesTest` — nový mód bez názvu by export tiše vynechal.
 */
final class RoundingModeNames
{
    /** @var array<string, int> `totals.totalRoundingMode` → `total_rounding_mode` */
    public const TOTAL = [
        'cent'     => RoundingModes::ON_CENT,
        'unit'     => RoundingModes::MATH_UNIT,
        'unitUp'   => RoundingModes::UP_UNIT,
        'unitDown' => RoundingModes::DOWN_UNIT,
        'fiveCent' => RoundingModes::MATH_FIVE_CENT,
    ];

    /** @var array<string, int> `vat.roundingMode` → `vat_rounding_mode` */
    public const VAT = [
        'cent' => RoundingModes::ON_CENT,
        'unit' => RoundingModes::MATH_UNIT,
    ];

    /** Kód módu celkové částky; null = hodnota chybí nebo není název módu. */
    public static function totalCode(mixed $name): ?int
    {
        return is_string($name) ? (self::TOTAL[$name] ?? null) : null;
    }

    /** Název módu celkové částky; null = kód bez názvu (historický kód 2 se chová jako `cent`). */
    public static function totalName(int $code): ?string
    {
        $name = array_search($code, self::TOTAL, true);
        return $name === false ? null : $name;
    }

    /** Kód módu zaokrouhlení DPH; null = hodnota chybí nebo není název módu. */
    public static function vatCode(mixed $name): ?int
    {
        return is_string($name) ? (self::VAT[$name] ?? null) : null;
    }

    /** Název módu zaokrouhlení DPH; null = kód bez názvu. */
    public static function vatName(int $code): ?string
    {
        $name = array_search($code, self::VAT, true);
        return $name === false ? null : $name;
    }
}

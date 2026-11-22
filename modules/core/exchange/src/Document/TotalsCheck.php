<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Document;

/**
 * `totals` pokynu jako kontrolní součet (#166 D4, D27): každé uvedené číselné
 * pole se porovná s hodnotou, kterou spočítal `DocDocument` — sloupce hlavičky
 * v měně dokladu. Rozdíl větší než 0,01 je nález `totals_check_failed`
 * a doklad se neuloží.
 *
 * Jedna funkce porovnání pro uložení (`DocumentApplier::apply()` nad daty
 * vrácenými `saveDocument()`) i náhled (nad výsledkem `computeAmounts()`).
 * Nic nepočítá znovu: u převzaté rekapitulace i řádků „Mimo DPH“ platí, co
 * `DocDocument` skutečně uložil. Sloupec, který hlavička nenese, se
 * neporovnává.
 *
 * Kontrola `totalRounding` u dokladu s automatikou odhalí i špatně uvedený
 * způsob úhrady — rozdíl ze zaokrouhlení z něj plyne. To je záměr.
 *
 * Čistá funkce bez databáze. V návrhu se nevolá (D28) — tam `totals`
 * zůstávají signálem a zdrojem varování `computed_total_mismatch`.
 */
final class TotalsCheck
{
    public const CODE_FAILED = 'totals_check_failed';
    public const CODE_MODE_IGNORED = 'total_rounding_mode_ignored';

    /** Pole `totals` → sloupec hlavičky dokladu. */
    private const FIELDS = [
        'totalBase'     => 'total_base',
        'totalVat'      => 'total_vat',
        'totalAmount'   => 'total_amount',
        'totalRounding' => 'total_rounding',
    ];

    /** Tolerance v haléřích: rozdíl 0,01 projde, 0,02 je chyba. */
    private const TOLERANCE_CENTS = 1;

    /**
     * Nálezy `totals_check_failed` — jeden za každé pole, které se liší.
     *
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $head hlavička dokladu po výpočtu
     * @return list<array{severity: string, path: string, code: string, message: string, declared: float, computed: float}>
     */
    public static function mismatches(array $canonical, array $head): array
    {
        $totals = $canonical['totals'] ?? null;
        if (!is_array($totals)) {
            return [];
        }
        $issues = [];
        foreach (self::FIELDS as $field => $column) {
            $declared = $totals[$field] ?? null;
            $computed = $head[$column] ?? null;
            if (!self::isNumber($declared) || !self::isNumber($computed)) {
                continue;
            }
            $declaredCents = self::cents($declared);
            $computedCents = self::cents($computed);
            if (abs($declaredCents - $computedCents) <= self::TOLERANCE_CENTS) {
                continue;
            }
            $declaredValue = $declaredCents / 100.0;
            $computedValue = $computedCents / 100.0;
            $issues[] = [
                'severity' => 'error',
                'path'     => "totals.{$field}",
                'code'     => self::CODE_FAILED,
                'message'  => sprintf(
                    'Kontrolní součet nesedí: totals.%s uvádí %s, výpočet dokladu dává %s.',
                    $field,
                    self::format($declaredValue),
                    self::format($computedValue),
                ),
                'declared' => $declaredValue,
                'computed' => $computedValue,
            ];
        }
        return $issues;
    }

    /**
     * Varování `total_rounding_mode_ignored` (#166 D26): doklad má zapnutou
     * automatiku zaokrouhlení a payload uvádí jiný mód, než jaký automatika
     * dopočítala. Platí automatika. Null = není co hlásit.
     *
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $head hlavička dokladu po výpočtu
     * @return array{severity: string, path: string, code: string, message: string}|null
     */
    public static function ignoredMode(array $canonical, array $head): ?array
    {
        $name = $canonical['totals']['totalRoundingMode'] ?? null;
        $declared = RoundingModeNames::totalCode($name);
        if ($declared === null || empty($head['total_rounding_auto']) || !isset($head['total_rounding_mode'])) {
            return null;
        }
        $computed = (int) $head['total_rounding_mode'];
        if ($computed === $declared) {
            return null;
        }
        $computedName = RoundingModeNames::totalName($computed) ?? (string) $computed;
        return [
            'severity' => 'warning',
            'path'     => 'totals.totalRoundingMode',
            'code'     => self::CODE_MODE_IGNORED,
            'message'  => "Doklad má automatické zaokrouhlení podle způsobu úhrady — platí mód „{$computedName}“,"
                . " uvedený „{$name}“ se nepoužil. Pro vlastní mód pošli totals.totalRoundingAuto: false.",
        ];
    }

    private static function isNumber(mixed $value): bool
    {
        return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value));
    }

    /** Částka v celých haléřích — float šum (0,1 + 0,2) nesmí dát rozdíl. */
    private static function cents(mixed $value): int
    {
        return (int) round(((float) $value) * 100);
    }

    private static function format(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}

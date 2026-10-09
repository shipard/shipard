<?php

declare(strict_types=1);

namespace Shipard\Core\Ai;

use Shipard\Core\Logging\ErrorLogger;

/**
 * Statická tabulka cen Anthropic modelů — USD za **milion** tokenů,
 * `[vstup, výstup]` (přenos `ai_analyzer/providers/anthropic_pricing.py`,
 * tasks/mail-analysis-inprocess.md D19).
 *
 * Klíče jsou **prefixy** ID modelu — plné ID z API nese datový suffix
 * (`claude-sonnet-4-5-20260101`); vyhrává nejdelší shodný prefix. Neznámý
 * model = 0 a varování v logu: cena je reportovací pole, ne důvod shodit
 * analýzu. Ceny se mění; tabulka je ruční údržba a dočasné řešení —
 * převezme ji katalog modelů (#85 fáze 1).
 */
final class AnthropicPricing
{
    /** @var array<string, array{0: float, 1: float}> */
    private const PRICES = [
        'claude-opus-4-5' => [15.0, 75.0],
        'claude-opus-4' => [15.0, 75.0],
        'claude-sonnet-4-6' => [3.0, 15.0],
        'claude-sonnet-4-5' => [3.0, 15.0],
        'claude-sonnet-4' => [3.0, 15.0],
        'claude-haiku-4-5' => [0.80, 4.0],
        'claude-haiku-4' => [0.80, 4.0],
        // Pre-4 fallback families
        'claude-3-5-sonnet' => [3.0, 15.0],
        'claude-3-5-haiku' => [0.80, 4.0],
        'claude-3-opus' => [15.0, 75.0],
    ];

    /** Cena jednoho volání v USD na 6 desetinných míst; neznámý model = 0.0. */
    public static function costUsd(string $model, int $tokensInput, int $tokensOutput): float
    {
        $rates = self::rates($model);
        if ($rates === null) {
            ErrorLogger::warn('AnthropicPricing: model missing in the price table — cost reported as 0', [
                'model' => $model,
            ]);
            return 0.0;
        }
        [$inputPerMtok, $outputPerMtok] = $rates;

        return round(($tokensInput * $inputPerMtok + $tokensOutput * $outputPerMtok) / 1_000_000, 6);
    }

    /**
     * Sazby `[vstup, výstup]` podle nejdelšího shodného prefixu, nebo null.
     *
     * @return array{0: float, 1: float}|null
     */
    public static function rates(string $model): ?array
    {
        $best = null;
        $bestLength = -1;
        foreach (self::PRICES as $prefix => $rates) {
            if (str_starts_with($model, $prefix) && strlen($prefix) > $bestLength) {
                $best = $rates;
                $bestLength = strlen($prefix);
            }
        }
        return $best;
    }
}

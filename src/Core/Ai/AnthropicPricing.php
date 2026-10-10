<?php

declare(strict_types=1);

namespace Shipard\Core\Ai;

use Shipard\Core\Logging\ErrorLogger;

/**
 * Statická tabulka cen Anthropic modelů — USD za **milion** tokenů,
 * `[vstup, výstup]` (tasks/mail-analysis-inprocess.md D19, sazby podle
 * skutečnosti k 2026-10-10 — tasks/ai-models-phase0.md F0-D9).
 *
 * Klíče jsou **prefixy** ID modelu — plné ID z API nese datový suffix
 * (`claude-sonnet-4-6-20260101`); vyhrává nejdelší shodný prefix, takže
 * `claude-opus-4` (15 / 75) nechytí `claude-opus-4-5` až `-4-8` (5 / 25).
 * Haiku 5.5 má druhé pásmo pro prompt nad {@see LONG_PROMPT_TOKENS}
 * vstupních tokenů. Ceny cache tokenů a ID s prefixem platformy
 * (`anthropic.…`) tabulka neřeší (fáze 3). Neznámý model = 0 a varování
 * v logu: cena je reportovací pole, ne důvod shodit analýzu. Tabulka je
 * ruční údržba a dočasné řešení — převezme ji katalog modelů (#85 fáze 1).
 */
final class AnthropicPricing
{
    /** Hranice vstupních tokenů, od které platí {@see LONG_PROMPT_PRICES}. */
    public const LONG_PROMPT_TOKENS = 100_000;

    /** @var array<string, array{0: float, 1: float}> */
    private const PRICES = [
        'claude-fable-5' => [10.0, 50.0],
        'claude-mythos-5' => [10.0, 50.0],
        'claude-opus-5-5' => [4.0, 20.0],
        'claude-opus-5' => [5.0, 25.0],
        'claude-opus-4-8' => [5.0, 25.0],
        'claude-opus-4-7' => [5.0, 25.0],
        'claude-opus-4-6' => [5.0, 25.0],
        'claude-opus-4-5' => [5.0, 25.0],
        'claude-opus-4-1' => [15.0, 75.0],
        'claude-opus-4' => [15.0, 75.0],
        'claude-sonnet-5-5' => [2.0, 10.0],
        'claude-sonnet-5' => [2.0, 10.0],
        'claude-sonnet-4-6' => [3.0, 15.0],
        'claude-sonnet-4-5' => [3.0, 15.0],
        'claude-sonnet-4' => [3.0, 15.0],
        'claude-haiku-5-5' => [0.10, 0.50],
        'claude-haiku-4-5' => [1.0, 5.0],
        // Pre-4 fallback families
        'claude-3-5-sonnet' => [3.0, 15.0],
        'claude-3-5-haiku' => [0.80, 4.0],
        'claude-3-opus' => [15.0, 75.0],
    ];

    /**
     * Sazby pro prompt nad {@see LONG_PROMPT_TOKENS} vstupních tokenů;
     * model bez řádku má jedno pásmo.
     *
     * @var array<string, array{0: float, 1: float}>
     */
    private const LONG_PROMPT_PRICES = [
        'claude-haiku-5-5' => [0.50, 2.50],
    ];

    /** Cena jednoho volání v USD na 6 desetinných míst; neznámý model = 0.0. */
    public static function costUsd(string $model, int $tokensInput, int $tokensOutput): float
    {
        $rates = self::rates($model, $tokensInput);
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
     * `$tokensInput` vybírá pásmo u modelů s dražším dlouhým promptem.
     *
     * @return array{0: float, 1: float}|null
     */
    public static function rates(string $model, int $tokensInput = 0): ?array
    {
        $prefix = self::longestPrefix($model);
        if ($prefix === null) {
            return null;
        }
        if ($tokensInput > self::LONG_PROMPT_TOKENS && isset(self::LONG_PROMPT_PRICES[$prefix])) {
            return self::LONG_PROMPT_PRICES[$prefix];
        }
        return self::PRICES[$prefix];
    }

    private static function longestPrefix(string $model): ?string
    {
        $best = null;
        $bestLength = -1;
        foreach (array_keys(self::PRICES) as $prefix) {
            if (str_starts_with($model, $prefix) && strlen($prefix) > $bestLength) {
                $best = $prefix;
                $bestLength = strlen($prefix);
            }
        }
        return $best;
    }
}

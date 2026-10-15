<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Eval;

use Shipard\Core\Ai\AiCallPlan;
use Shipard\Core\Ai\ModelCatalog;

/**
 * Odhad ceny běhu před potvrzením (D34): z posledního běhu téže varianty
 * nad toutéž sadou (průměr na volání), jinak z ceníku katalogu a typické
 * velikosti dokladu (PDF strana + prompt + schéma ≈ {@see TYPICAL_INPUT_TOKENS}
 * vstupních tokenů, výstup ≈ {@see TYPICAL_OUTPUT_TOKENS}) plus volání
 * klasifikace štítků. Vlastní ID modelu mimo katalog cenu nemá.
 */
final class EvalCostEstimator
{
    public const int TYPICAL_INPUT_TOKENS = 12000;
    public const int TYPICAL_OUTPUT_TOKENS = 2500;
    public const int TAG_INPUT_TOKENS = 1500;
    public const int TAG_OUTPUT_TOKENS = 120;

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed>|null $lastRun soubor výsledků posledního běhu
     * @return array{calls: int, perCall: ?float, total: ?float, basis: 'lastRun'|'catalog'|'unknown'}
     */
    public static function estimate(int $calls, ?array $lastRun, AiCallPlan $plan, ?AiCallPlan $tagPlan, ModelCatalog $catalog): array
    {
        $lastTotal = $lastRun['metrics']['cost.usd'] ?? null;
        $lastCalls = (int) ($lastRun['metrics']['calls.total'] ?? 0);
        if (is_numeric($lastTotal) && $lastCalls > 0) {
            $perCall = round((float) $lastTotal / $lastCalls, 6);
            return ['calls' => $calls, 'perCall' => $perCall, 'total' => round($perCall * $calls, 4), 'basis' => 'lastRun'];
        }

        $perCall = self::planCost($plan, $catalog, self::TYPICAL_INPUT_TOKENS, self::TYPICAL_OUTPUT_TOKENS);
        if ($perCall === null) {
            return ['calls' => $calls, 'perCall' => null, 'total' => null, 'basis' => 'unknown'];
        }
        if ($tagPlan !== null) {
            $perCall += self::planCost($tagPlan, $catalog, self::TAG_INPUT_TOKENS, self::TAG_OUTPUT_TOKENS) ?? 0.0;
        }
        $perCall = round($perCall, 6);
        return ['calls' => $calls, 'perCall' => $perCall, 'total' => round($perCall * $calls, 4), 'basis' => 'catalog'];
    }

    private static function planCost(AiCallPlan $plan, ModelCatalog $catalog, int $tokensIn, int $tokensOut): ?float
    {
        if ($plan->catalogModel === null) {
            return null;
        }
        return $catalog->costUsd($plan->catalogModel, $tokensIn, $tokensOut) * $plan->priceMultiplier;
    }
}

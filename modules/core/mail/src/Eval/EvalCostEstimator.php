<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Eval;

use Shipard\Core\Ai\AiCallPlan;
use Shipard\Core\Ai\ModelCatalog;

/**
 * Odhad ceny běhu před potvrzením (D34, D44, D56), v tomto pořadí:
 *
 *   1. z posledního běhu nad sadou se **stejným skutečným modelem** extrakce
 *      (`variant.effective.catalogModel`, bez ohledu na variantu a hash sady —
 *      cena volání téhož modelu se promptem ani očekáváním nemění;
 *      {@see sameModelRun()}): průměr na volání vč. ceny štítků (`tags.usd`
 *      z běhu, u starších běhů bez ní typická velikost volání štítků);
 *   2. z tokenů na doklad posledního běhu nad sadou s modelem **téže řady**
 *      × ceník plánovaného modelu ({@see seriesRun()}) — řada = generace
 *      podle prvního čísla v ID katalogu ({@see modelSeries()}); tokenizace
 *      se liší mezi generacemi, ne mezi velikostmi modelu (4.6 → 5.5 +20 %
 *      vstupu, tasks/testing-phase4.md § 10);
 *   3. z ceníku katalogu a typické velikosti dokladu (PDF strana + prompt
 *      + schéma ≈ {@see TYPICAL_INPUT_TOKENS} vstupních tokenů, výstup
 *      ≈ {@see TYPICAL_OUTPUT_TOKENS}).
 *
 * K 2 a 3 se přičítá volání klasifikace štítků (typická velikost). Vlastní
 * ID modelu mimo katalog cenu nemá.
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
     * @param array<string, mixed>|null $sameModelRun soubor výsledků posledního běhu se stejným modelem ({@see sameModelRun()})
     * @param array<string, mixed>|null $seriesRun    soubor výsledků běhu s modelem téže řady ({@see seriesRun()})
     * @return array{calls: int, perCall: ?float, total: ?float, basis: 'sameModel'|'series'|'catalog'|'unknown', source: ?array{file: string, model: ?string}}
     */
    public static function estimate(int $calls, ?array $sameModelRun, ?array $seriesRun, AiCallPlan $plan, ?AiCallPlan $tagPlan, ModelCatalog $catalog): array
    {
        $lastTotal = $sameModelRun['metrics']['cost.usd'] ?? null;
        $lastCalls = (int) ($sameModelRun['metrics']['calls.total'] ?? 0);
        if ($sameModelRun !== null && is_numeric($lastTotal) && $lastCalls > 0) {
            $lastTags = $sameModelRun['metrics']['tags.usd'] ?? null;
            $tagsTotal = is_numeric($lastTags) ? (float) $lastTags : self::tagCost($tagPlan, $catalog) * $lastCalls;
            $perCall = round(((float) $lastTotal + $tagsTotal) / $lastCalls, 6);
            return ['calls' => $calls, 'perCall' => $perCall, 'total' => round($perCall * $calls, 4), 'basis' => 'sameModel', 'source' => self::source($sameModelRun)];
        }

        if ($seriesRun !== null) {
            $perCall = self::planCost(
                $plan,
                $catalog,
                (int) ($seriesRun['metrics']['tokens.inputPerDocument'] ?? 0),
                (int) ($seriesRun['metrics']['tokens.outputPerDocument'] ?? 0),
            );
            if ($perCall !== null) {
                $perCall = round($perCall + self::tagCost($tagPlan, $catalog), 6);
                return ['calls' => $calls, 'perCall' => $perCall, 'total' => round($perCall * $calls, 4), 'basis' => 'series', 'source' => self::source($seriesRun)];
            }
        }

        $perCall = self::planCost($plan, $catalog, self::TYPICAL_INPUT_TOKENS, self::TYPICAL_OUTPUT_TOKENS);
        if ($perCall === null) {
            return ['calls' => $calls, 'perCall' => null, 'total' => null, 'basis' => 'unknown', 'source' => null];
        }
        $perCall = round($perCall + self::tagCost($tagPlan, $catalog), 6);
        return ['calls' => $calls, 'perCall' => $perCall, 'total' => round($perCall * $calls, 4), 'basis' => 'catalog', 'source' => null];
    }

    /**
     * Řada modelu = první celé číslo v ID katalogu: `claude-sonnet-4-6` → `4`,
     * `claude-3-5-haiku` → `3`, `claude-fable-5-1` → `5`; bez čísla null.
     */
    public static function modelSeries(?string $catalogModel): ?string
    {
        if ($catalogModel !== null && preg_match('/(?:^|-)(\d+)(?=-|$)/', $catalogModel, $m) === 1) {
            return $m[1];
        }
        return null;
    }

    /**
     * Nejnovější běh se stejným skutečným modelem extrakce jako plánovaný
     * (`variant.effective.catalogModel`) a se známou cenou a počtem volání;
     * varianta ani hash sady nerozhodují (D56).
     *
     * @param list<array<string, mixed>> $runs soubory výsledků, nejnovější první
     * @return array<string, mixed>|null
     */
    public static function sameModelRun(array $runs, ?string $catalogModel): ?array
    {
        if ($catalogModel === null) {
            return null;
        }
        foreach ($runs as $run) {
            if (($run['variant']['effective']['catalogModel'] ?? null) !== $catalogModel) {
                continue;
            }
            if ((int) ($run['metrics']['calls.total'] ?? 0) <= 0 || !is_numeric($run['metrics']['cost.usd'] ?? null)) {
                continue;
            }
            return $run;
        }
        return null;
    }

    /**
     * Nejnovější běh s modelem téže řady jako plánovaný model a se známými
     * tokeny na doklad. Hash sady se nebere v úvahu — tokeny závisí na
     * dokumentech `mail/` a tokenizéru, ne na `expected/`.
     *
     * @param list<array<string, mixed>> $runs soubory výsledků, nejnovější první
     * @return array<string, mixed>|null
     */
    public static function seriesRun(array $runs, ?string $catalogModel): ?array
    {
        $series = self::modelSeries($catalogModel);
        if ($series === null) {
            return null;
        }
        foreach ($runs as $run) {
            $model = $run['variant']['effective']['catalogModel'] ?? null;
            if (!is_string($model) || self::modelSeries($model) !== $series) {
                continue;
            }
            if ((int) ($run['metrics']['tokens.inputPerDocument'] ?? 0) <= 0) {
                continue;
            }
            return $run;
        }
        return null;
    }

    private static function planCost(AiCallPlan $plan, ModelCatalog $catalog, int $tokensIn, int $tokensOut): ?float
    {
        if ($plan->catalogModel === null) {
            return null;
        }
        return $catalog->costUsd($plan->catalogModel, $tokensIn, $tokensOut) * $plan->priceMultiplier;
    }

    private static function tagCost(?AiCallPlan $tagPlan, ModelCatalog $catalog): float
    {
        if ($tagPlan === null) {
            return 0.0;
        }
        return self::planCost($tagPlan, $catalog, self::TAG_INPUT_TOKENS, self::TAG_OUTPUT_TOKENS) ?? 0.0;
    }

    /**
     * @param array<string, mixed> $run
     * @return array{file: string, model: ?string}
     */
    private static function source(array $run): array
    {
        $model = $run['variant']['effective']['catalogModel'] ?? null;
        return ['file' => basename((string) ($run['_file'] ?? '')), 'model' => is_string($model) ? $model : null];
    }
}

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
 *
 * Zprávy podoby PDF + ISDOC jdou v evaluaci cestou `isdoc` bez AI extrakce
 * (#103 D76): v odhadu se počítají jen za štítky (`isdocCalls` ×
 * `isdocPerCall`); u 1 se cena na volání bere z kyblíku `byPath.ai`
 * posledního běhu, má-li ho, aby ji volání ISDOC za 0 nestahovala dolů.
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
     * @param int $isdocCalls volání cestou ISDOC (zprávy podoby PDF + ISDOC × opakování) z `$calls` — jen štítky
     * @return array{calls: int, aiCalls: int, isdocCalls: int, perCall: ?float, isdocPerCall: float, total: ?float, basis: 'sameModel'|'series'|'catalog'|'unknown', source: ?array{file: string, model: ?string}}
     */
    public static function estimate(int $calls, ?array $sameModelRun, ?array $seriesRun, AiCallPlan $plan, ?AiCallPlan $tagPlan, ModelCatalog $catalog, int $isdocCalls = 0): array
    {
        $isdocCalls = max(0, min($calls, $isdocCalls));
        $tagCost = self::tagCost($tagPlan, $catalog);

        if ($sameModelRun !== null) {
            // Kyblík cesty `ai` (běhy od D76), jinak metriky celku (starší běhy jsou celé `ai`).
            $bucket = $sameModelRun['byPath']['ai'] ?? null;
            $basis = is_array($bucket) && (int) ($bucket['calls.total'] ?? 0) > 0 ? $bucket : (array) ($sameModelRun['metrics'] ?? []);
            $lastTotal = $basis['cost.usd'] ?? null;
            $lastCalls = (int) ($basis['calls.total'] ?? 0);
            if (is_numeric($lastTotal) && $lastCalls > 0) {
                $lastTags = $basis['tags.usd'] ?? null;
                $tagsTotal = is_numeric($lastTags) ? (float) $lastTags : $tagCost * $lastCalls;
                $perCall = round(((float) $lastTotal + $tagsTotal) / $lastCalls, 6);
                return self::result($calls, $isdocCalls, $perCall, $tagCost, 'sameModel', self::source($sameModelRun));
            }
        }

        if ($seriesRun !== null) {
            $perCall = self::planCost(
                $plan,
                $catalog,
                (int) ($seriesRun['metrics']['tokens.inputPerDocument'] ?? 0),
                (int) ($seriesRun['metrics']['tokens.outputPerDocument'] ?? 0),
            );
            if ($perCall !== null) {
                return self::result($calls, $isdocCalls, round($perCall + $tagCost, 6), $tagCost, 'series', self::source($seriesRun));
            }
        }

        $perCall = self::planCost($plan, $catalog, self::TYPICAL_INPUT_TOKENS, self::TYPICAL_OUTPUT_TOKENS);
        if ($perCall === null) {
            return self::result($calls, $isdocCalls, null, $tagCost, 'unknown', null);
        }
        return self::result($calls, $isdocCalls, round($perCall + $tagCost, 6), $tagCost, 'catalog', null);
    }

    /**
     * @param array{file: string, model: ?string}|null $source
     * @return array{calls: int, aiCalls: int, isdocCalls: int, perCall: ?float, isdocPerCall: float, total: ?float, basis: 'sameModel'|'series'|'catalog'|'unknown', source: ?array{file: string, model: ?string}}
     */
    private static function result(int $calls, int $isdocCalls, ?float $perCall, float $tagCost, string $basis, ?array $source): array
    {
        $aiCalls = $calls - $isdocCalls;
        $isdocPerCall = round($tagCost, 6);
        return [
            'calls' => $calls,
            'aiCalls' => $aiCalls,
            'isdocCalls' => $isdocCalls,
            'perCall' => $perCall,
            'isdocPerCall' => $isdocPerCall,
            'total' => $perCall === null ? null : round($perCall * $aiCalls + $isdocPerCall * $isdocCalls, 4),
            'basis' => $basis,
            'source' => $source,
        ];
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

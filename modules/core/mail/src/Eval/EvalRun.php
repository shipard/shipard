<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Eval;

use Shipard\Core\Ai\AiCallPlan;

/**
 * Sestavení souboru výsledků běhu (`shpd.eval.run.v1`, D36): sada (název,
 * země, profil, hash obsahu, výběr), git (commit, neuložené změny),
 * varianta deklarovaná i rozepsaná na skutečné hodnoty (profil, verze
 * a hash promptu, platforma a původ připojení, model, ladění, strop,
 * ladění štítků), začátek a konec, opakování, plánovaný počet volání
 * a důvod předčasného konce (`stoppedBy`: `maxCost` strop útraty,
 * `cancelled` SIGTERM; null = doběhlo, D61), metriky a per zpráva
 * × opakování jen odchylky.
 */
final class EvalRun
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed>  $set
     * @param array{commit: ?string, dirty: ?bool} $git
     * @param array<string, mixed>  $profile řádek profilu pošty
     * @param array{metrics: array<string, int|float>, range?: array<string, mixed>, fieldStability?: array<string, float>, formatStability?: array<string, float>, byVariant: array<string, mixed>, byForm: array<string, mixed>, deviationsByField: array<string, int>} $metrics
     * @param list<EvalCallResult>  $results
     * @return array<string, mixed>
     */
    public static function build(
        array $set,
        array $git,
        EvalVariant $variant,
        array $profile,
        AiCallPlan $plan,
        ?AiCallPlan $tagPlan,
        string $startedAt,
        string $finishedAt,
        int $repeat,
        array $metrics,
        array $results,
        ?string $stoppedBy = null,
        ?int $plannedCalls = null,
    ): array {
        $promptTemplate = $variant->promptTemplate ?? (string) ($profile['prompt_template'] ?? '');
        return [
            'format' => EvalRunStore::FORMAT,
            'set' => $set,
            'git' => $git,
            'variant' => [
                'name' => $variant->name,
                'file' => basename($variant->path),
                'declared' => $variant->toArray(),
                'effective' => [
                    'profile' => $variant->profile,
                    'profileNdx' => (int) ($profile['id'] ?? 0),
                    'promptVersion' => (string) ($profile['prompt_version'] ?? ''),
                    'promptSource' => $variant->promptTemplate !== null ? 'file' : 'profile',
                    'promptHash' => hash('sha256', $promptTemplate),
                    'platform' => $plan->platform,
                    'connection' => $plan->connectionCode,
                    'connectionOrigin' => $plan->origins['connection'] ?? null,
                    'model' => $plan->model,
                    'catalogModel' => $plan->catalogModel,
                    'thinking' => $plan->thinking,
                    'effort' => $plan->effort,
                    'maxTokens' => $plan->maxTokens,
                    'temperature' => $plan->temperature,
                    'origins' => $plan->origins,
                    'contentTags' => $tagPlan === null ? null : [
                        'connection' => $tagPlan->connectionCode,
                        'model' => $tagPlan->model,
                        'thinking' => $tagPlan->thinking,
                        'effort' => $tagPlan->effort,
                        'maxTokens' => $tagPlan->maxTokens,
                    ],
                ],
            ],
            'startedAt' => $startedAt,
            'finishedAt' => $finishedAt,
            'repeat' => $repeat,
            'plannedCalls' => $plannedCalls ?? count($results),
            'stoppedBy' => $stoppedBy,
            'metrics' => $metrics['metrics'],
            'range' => $metrics['range'] ?? null,
            'fieldStability' => $metrics['fieldStability'] ?? null,
            'formatStability' => $metrics['formatStability'] ?? null,
            'byVariant' => $metrics['byVariant'],
            'byForm' => $metrics['byForm'],
            'deviationsByField' => $metrics['deviationsByField'],
            'calls' => array_map(static fn (EvalCallResult $r): array => $r->toArray(), $results),
        ];
    }
}

<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Eval\Browse;

use Shipard\Module\Core\Mail\Eval\EvalCallResult;

/**
 * Jeden doklad napříč běhy sady (#103 D53): per běh a opakování stav
 * volání a počet odchylek — matice v prohlížeči. Běh bez volání dokladu
 * (`--only`, `--limit`) má prázdná opakování.
 */
final class EvalMessageAcrossRuns
{
    private function __construct()
    {
    }

    /**
     * @param list<array<string, mixed>> $runs běhy z `EvalCatalog::runs()`
     * @return list<array<string, mixed>>
     */
    public static function build(array $runs, string $messageId, ?string $currentHash): array
    {
        $out = [];
        foreach ($runs as $run) {
            $repeats = [];
            foreach ((array) ($run['calls'] ?? []) as $call) {
                if (!is_array($call) || ($call['messageId'] ?? null) !== $messageId) {
                    continue;
                }
                $repeats[] = [
                    'repeat' => (int) ($call['repeat'] ?? 1),
                    'status' => $call['status'] ?? null,
                    'errorType' => $call['errorType'] ?? null,
                    'deviations' => EvalCallResult::deviationCount($call),
                    'costUsd' => $call['costUsd'] ?? null,
                    'durationMs' => $call['durationMs'] ?? null,
                ];
            }
            usort($repeats, static fn (array $x, array $y): int => $x['repeat'] <=> $y['repeat']);
            $summary = EvalRunSummary::fromRun($run, $currentHash);
            $out[] = [
                'file' => $summary['file'],
                'startedAt' => $summary['startedAt'],
                'variant' => $summary['variant'],
                'model' => $summary['model'],
                'tagsModel' => $summary['tagsModel'],
                'repeat' => $summary['repeat'],
                'setHash' => $summary['setHash'],
                'currentSet' => $summary['currentSet'],
                'repeats' => $repeats,
            ];
        }
        return $out;
    }
}

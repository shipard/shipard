<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\Round;

use Shipard\Module\Core\Mail\Eval\EvalEstimateRunner;

/**
 * Odhad ceny evaluací kola před jeho startem (D131): pro každou sadu
 * rozvrhu `ai-eval --estimate --json` nad **existujícím** zdrojem dat kola
 * ({@see EvalEstimateRunner}); součet proti stropu porovnává volající
 * (CLI i dashboard). Chybějící zdroj dat kola = odhad nejde (ai-eval ho
 * potřebuje kvůli profilu a AI připojení) → kolo s `--eval` nezačne, dokud
 * ho nezaloží kolo bez `--eval` nebo dashboard.
 */
final class RoundEstimate
{
    public function __construct(
        private readonly EvalEstimateRunner $runner,
        private readonly string $dataSourcesDir,
    ) {}

    /**
     * @return array{total: float, rows: list<array{dataset: string, total: ?float, calls: ?int, basis: ?string, model: ?string}>, problems: list<string>}
     */
    public function forPlan(RoundPlan $plan, RoundEvalOptions $eval, ?string $variantPath): array
    {
        $rows = [];
        $problems = [];
        $total = 0.0;
        if ($variantPath === null) {
            return ['total' => 0.0, 'rows' => [], 'problems' => ["Varianta '{$eval->variant}' není v tests/eval/variants"]];
        }
        if ($plan->datasets === []) {
            return ['total' => 0.0, 'rows' => [], 'problems' => ['Žádná sada k evaluaci — vrstva sad není vybraná, nebo filtr --dataset nic nevybral']];
        }
        foreach ($plan->datasets as $dataset) {
            if ($dataset->needsCreate()) {
                $problems[] = "Zdroj dat kola pro sadu {$dataset->name} ještě neexistuje — odhad ho potřebuje; spusť nejdřív kolo bez --eval (zdroj dat založí), nebo ho založ z dashboardu";
                continue;
            }
            $dsDir = rtrim($this->dataSourcesDir, '/') . '/' . $dataset->dataSourceId;
            $outcome = $this->runner->estimate($dsDir, $dataset->dir, $variantPath, $eval->repeat, []);
            switch ($outcome['kind']) {
                case EvalEstimateRunner::KIND_OK:
                    $json = $outcome['json'] ?? [];
                    $estimate = is_array($json['estimate'] ?? null) ? $json['estimate'] : [];
                    $rowTotal = is_numeric($estimate['total'] ?? null) ? (float) $estimate['total'] : null;
                    $rows[] = [
                        'dataset' => $dataset->name,
                        'total' => $rowTotal,
                        'calls' => is_int($json['calls'] ?? null) ? $json['calls'] : null,
                        'basis' => is_string($estimate['basis'] ?? null) ? $estimate['basis'] : null,
                        'model' => is_string($json['model'] ?? null) ? $json['model'] : null,
                    ];
                    if ($rowTotal === null) {
                        $problems[] = "Odhad pro sadu {$dataset->name} je neznámý (model mimo katalog) — strop nejde ověřit";
                    } else {
                        $total += $rowTotal;
                    }
                    break;
                case EvalEstimateRunner::KIND_PREFLIGHT:
                    $problems[] = "Sada {$dataset->name}: " . implode(' ', $outcome['problems']);
                    break;
                case EvalEstimateRunner::KIND_TIMEOUT:
                    $problems[] = sprintf('Sada %s: ai-eval --estimate nedoběhl do %d s', $dataset->name, (int) EvalEstimateRunner::TIMEOUT_SECONDS);
                    break;
                default:
                    $problems[] = sprintf('Sada %s: ai-eval --estimate selhal (exit %s)%s', $dataset->name, var_export($outcome['exitCode'], true), trim($outcome['stderr']) !== '' ? ': ' . trim($outcome['stderr']) : '');
            }
        }
        return ['total' => $total, 'rows' => $rows, 'problems' => $problems];
    }
}

<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Ai\Alerts;

use Shipard\Core\Ai\AiFunctionRegistry;
use Shipard\Core\Ai\ModelCatalog;
use Shipard\Core\Alerts\AlertCheck;
use Shipard\Core\Alerts\AlertFinding;

/**
 * „AI model brzy končí“ nad řádky AI funkcí (tasks/ai-functions.md D42,
 * D47): funkce s **výslovně nastaveným** modelem, který podle katalogu
 * do 30 dnů končí nebo už skončil. Modely z mapy úrovní hlídá repo —
 * pro ně se nic nehlásí. Vlastní ID mimo katalog se nehodnotí.
 *
 * Registrace: modules/core/ai/module.jsonc → alertChecks
 * (id core.ai.model_retiring, interval 1d). Profily pošty hlídá
 * `core.mail.profile_model_retiring` nad stejným úsudkem.
 */
class ModelRetiringAlertCheck extends AlertCheck
{
    public function run(): array
    {
        $catalog = ModelCatalog::fromConfig($this->config);
        if ($catalog->isEmpty()) {
            return [];
        }
        $judge = new ModelRetirementJudge($catalog, $this->today());
        $registry = AiFunctionRegistry::fromConfig($this->config);

        $findings = [];
        $rows = $this->db->fetchAll(
            'SELECT id, function_id, model FROM core_ai_function_settings WHERE model IS NOT NULL ORDER BY id',
        );
        foreach ($rows as $row) {
            $verdict = $judge->judge((string) ($row['model'] ?? ''));
            if ($verdict === null) {
                continue;
            }
            $functionId = (string) ($row['function_id'] ?? '');
            $name = $registry->get($functionId)?->name ?? $functionId;
            $findings[] = $this->finding($functionId, $name, $verdict);
        }
        return $findings;
    }

    protected function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('today');
    }

    /** @param array{spec: \Shipard\Core\Ai\ModelSpec, retired: bool, retiresAt: ?string, daysLeft: ?int, replacedBy: ?string} $verdict */
    private function finding(string $functionId, string $name, array $verdict): AlertFinding
    {
        $isCs = $this->language === 'cs';
        return new AlertFinding(
            findingKey: 'function:' . $functionId,
            title: $isCs
                ? ($verdict['retired'] ? "AI model skončil: {$name}" : "AI model brzy končí: {$name}")
                : ($verdict['retired'] ? "AI model retired: {$name}" : "AI model retiring soon: {$name}"),
            message: ModelRetirementJudge::describe($verdict, $this->language)
                . ($isCs
                    ? " Změňte model u AI funkce „{$name}“ v Nastavení aplikace → Pošta → AI funkce, nebo pole vyprázdněte (model podle úrovně)."
                    : " Change the model of the AI function “{$name}” in Application settings → Mail → AI functions, or clear it (tier model)."),
            severity: $verdict['retired'] ? 'error' : 'warning',
            context: [
                'functionId' => $functionId,
                'model' => $verdict['spec']->id,
                'retiresAt' => $verdict['retiresAt'],
                'daysLeft' => $verdict['daysLeft'],
                'replacedBy' => $verdict['replacedBy'],
            ],
        );
    }
}

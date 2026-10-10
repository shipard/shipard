<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Analysis;

use Shipard\Core\Ai\ModelCatalog;
use Shipard\Core\Alerts\AlertCheck;
use Shipard\Core\Alerts\AlertFinding;
use Shipard\Module\Core\Ai\Alerts\ModelRetirementJudge;

/**
 * „AI model brzy končí“ nad AI profily pošty (tasks/ai-functions.md D42,
 * D47): aktivní profil s **výslovně nastaveným** modelem (přepsání funkce
 * `mail.extraction`), který podle katalogu do 30 dnů končí nebo už
 * skončil. Stejný úsudek jako `core.ai.model_retiring`
 * ({@see ModelRetirementJudge}); core.ai tabulku profilů nezná, proto
 * druhá deklarace tady.
 *
 * Registrace: modules/core/mail/module.jsonc → alertChecks
 * (id core.mail.profile_model_retiring, interval 1d).
 */
class ProfileModelRetiringAlertCheck extends AlertCheck
{
    public function run(): array
    {
        $catalog = ModelCatalog::fromConfig($this->config);
        if ($catalog->isEmpty()) {
            return [];
        }
        $judge = new ModelRetirementJudge($catalog, $this->today());

        $findings = [];
        $rows = $this->db->fetchAll(
            'SELECT id, profile_id, name, model FROM core_mail_ai_profiles WHERE model IS NOT NULL AND is_active = %i ORDER BY id',
            1,
        );
        foreach ($rows as $row) {
            $verdict = $judge->judge((string) ($row['model'] ?? ''));
            if ($verdict === null) {
                continue;
            }
            $findings[] = $this->finding((string) ($row['profile_id'] ?? ''), (string) ($row['name'] ?? $row['profile_id'] ?? ''), $verdict);
        }
        return $findings;
    }

    protected function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('today');
    }

    /** @param array{spec: \Shipard\Core\Ai\ModelSpec, retired: bool, retiresAt: ?string, daysLeft: ?int, replacedBy: ?string} $verdict */
    private function finding(string $profileId, string $name, array $verdict): AlertFinding
    {
        $isCs = $this->language === 'cs';
        return new AlertFinding(
            findingKey: 'profile:' . $profileId,
            title: $isCs
                ? ($verdict['retired'] ? "AI model skončil: profil {$name}" : "AI model brzy končí: profil {$name}")
                : ($verdict['retired'] ? "AI model retired: profile {$name}" : "AI model retiring soon: profile {$name}"),
            message: ModelRetirementJudge::describe($verdict, $this->language)
                . ($isCs
                    ? " Změňte model v AI profilu „{$name}“ (Nastavení aplikace → Pošta → AI profily), nebo pole vyprázdněte (model podle funkce)."
                    : " Change the model in the AI profile “{$name}” (Application settings → Mail → AI profiles), or clear it (function model)."),
            severity: $verdict['retired'] ? 'error' : 'warning',
            context: [
                'profileId' => $profileId,
                'model' => $verdict['spec']->id,
                'retiresAt' => $verdict['retiresAt'],
                'daysLeft' => $verdict['daysLeft'],
                'replacedBy' => $verdict['replacedBy'],
            ],
        );
    }
}

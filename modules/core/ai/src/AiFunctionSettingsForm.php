<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Ai;

use Shipard\Core\Ai\AiFunctionRegistry;
use Shipard\Core\Ai\ModelCatalog;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\TableForm;

/**
 * Formulář nastavení AI funkce (tasks/ai-functions.md D38, D43, D44):
 * funkce jen ke čtení (řádky zakládá ds-upgrade), přepsání připojení,
 * modelu a ladění. Pole model je select s `allowCustom` — volby z katalogu
 * (modely s úrovní), vlastní ID jde napsat; vlastní ID vyžaduje
 * `model_like` (validace v AiFunctionSettingsDocument).
 */
class AiFunctionSettingsForm extends TableForm
{
    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        $functionId = (string) ($data['function_id'] ?? '');
        $function = AiFunctionRegistry::fromConfig($this->config)->get($functionId);
        $catalog = ModelCatalog::fromConfig($this->config);
        $tierModel = $function !== null ? $catalog->defaultModel($function->tier) : null;
        $tierName = $function !== null ? ($catalog->tiers()[$function->tier]['name'] ?? $function->tier) : null;

        $modelOptions = ModelOptions::catalogModels($catalog, $data['model'] ?? null);

        $basic = $this->tab('basic', 'AI funkce')
            ->section()
                ->col()
                    ->html($this->functionSummary($function?->name ?? $functionId, $function?->description ?? '', $tierName, $tierModel))
                    ->input('function_id', readOnly: true)
                    ->lookup(
                        'connection',
                        table: 'core_ai_backends',
                        placeholder: 'Výchozí připojení',
                        hint: 'Prázdné = výchozí aktivní AI připojení zdroje dat.',
                    )
                    ->select(
                        'model',
                        options: $modelOptions,
                        required: false,
                        placeholder: $tierModel !== null ? "Podle úrovně ({$tierModel})" : 'Podle úrovně',
                        hint: 'Model z katalogu, nebo vlastní ID tak, jak ho zná platforma. Prázdné = model úrovně funkce.',
                        allowCustom: true,
                    )
                    ->select(
                        'model_like',
                        options: ModelOptions::catalogModels($catalog),
                        required: false,
                        placeholder: 'Jen u vlastního ID modelu',
                        hint: 'U vlastního ID: model z katalogu, podle kterého se požadavek tvaruje (schopnosti, strop tokenů). Cena volání je pak 0.',
                    )
                    ->separator('Ladění')
                    ->select('thinking', options: ModelOptions::tuning($this->config, 'core.ai.thinkingModes'), required: true)
                    ->select('effort', options: ModelOptions::tuning($this->config, 'core.ai.effortLevels'), required: true)
                    ->input(
                        'max_tokens',
                        inputType: 'number',
                        placeholder: $function !== null ? "Podle funkce ({$function->maxTokens})" : 'Podle funkce',
                        hint: 'Strop výstupu, ne cena — na modelech s thinking se přemýšlení počítá do stropu.',
                    )
            ->build();

        return new FormDefinition(
            table: $this->table,
            title: 'AI funkce',
            titleNew: 'AI funkce',
            tabs: [$basic],
        );
    }

    private function functionSummary(string $name, string $description, ?string $tierName, ?string $tierModel): string
    {
        $html = '<p><strong>' . htmlspecialchars($name, ENT_QUOTES) . '</strong>';
        if ($description !== '') {
            $html .= '<br>' . htmlspecialchars($description, ENT_QUOTES);
        }
        if ($tierName !== null) {
            $html .= '<br>Úroveň: ' . htmlspecialchars($tierName, ENT_QUOTES)
                . ($tierModel !== null ? ' → ' . htmlspecialchars($tierModel, ENT_QUOTES) : '');
        }
        return $html . '</p>';
    }
}

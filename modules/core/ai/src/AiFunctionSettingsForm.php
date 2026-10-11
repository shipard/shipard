<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Ai;

use Shipard\Core\Ai\AiFunctionRegistry;
use Shipard\Core\Ai\AiFunctionResolver;
use Shipard\Core\Ai\AiPlatforms;
use Shipard\Core\Ai\ModelCatalog;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\TableForm;

/**
 * Formulář nastavení AI funkce (tasks/ai-functions.md D38, D43, D44):
 * funkce jen ke čtení (řádky zakládá ds-upgrade), přepsání připojení,
 * modelu a ladění. Pole model je select s `allowCustom` — volby z katalogu
 * (modely s úrovní), vlastní ID jde napsat; vlastní ID vyžaduje
 * `model_like` (validace v AiFunctionSettingsDocument). Model úrovně
 * v souhrnu a v placeholderu platí pro platformu efektivního připojení
 * (vybrané, jinak výchozí; `platformModels`, tasks/ai-bedrock-eu.md D58)
 * — změna připojení formulář přestaví (`triggers: reload`).
 */
class AiFunctionSettingsForm extends TableForm
{
    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        $functionId = (string) ($data['function_id'] ?? '');
        $function = AiFunctionRegistry::fromConfig($this->config)->get($functionId);
        $catalog = ModelCatalog::fromConfig($this->config);
        $platform = $this->connectionPlatform($data['connection'] ?? null);
        $tierModel = $function !== null ? $catalog->defaultModel($function->tier, $platform) : null;
        $tierName = $function !== null ? ($catalog->tiers()[$function->tier]['name'] ?? $function->tier) : null;
        // Jiný model než obecný = model pro platformu připojení — řekni kterou.
        $tierPlatform = $function !== null && $tierModel !== null && $tierModel !== $catalog->defaultModel($function->tier)
            ? AiPlatforms::fromConfig($this->config)->label($platform)
            : null;

        $modelOptions = ModelOptions::catalogModels($catalog, $data['model'] ?? null);

        $basic = $this->tab('basic', 'AI funkce')
            ->section()
                ->col()
                    ->html($this->functionSummary($function?->name ?? $functionId, $function?->description ?? '', $tierName, $tierModel, $tierPlatform))
                    ->input('function_id', readOnly: true)
                    ->lookup(
                        'connection',
                        table: 'core_ai_backends',
                        placeholder: 'Výchozí připojení',
                        hint: 'Prázdné = výchozí aktivní AI připojení zdroje dat.',
                        triggers: 'reload',
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

    private function functionSummary(string $name, string $description, ?string $tierName, ?string $tierModel, ?string $tierPlatform): string
    {
        $html = '<p><strong>' . htmlspecialchars($name, ENT_QUOTES) . '</strong>';
        if ($description !== '') {
            $html .= '<br>' . htmlspecialchars($description, ENT_QUOTES);
        }
        if ($tierName !== null) {
            $html .= '<br>Úroveň: ' . htmlspecialchars($tierName, ENT_QUOTES)
                . ($tierModel !== null ? ' → ' . htmlspecialchars($tierModel, ENT_QUOTES) : '')
                . ($tierPlatform !== null ? ' (na platformě ' . htmlspecialchars($tierPlatform, ENT_QUOTES) . ')' : '');
        }
        return $html . '</p>';
    }

    /**
     * Platforma efektivního připojení: připojení vybrané ve formuláři,
     * jinak výchozí aktivní připojení zdroje dat; bez databáze (testy)
     * nebo bez připojení platforma `anthropic`.
     */
    private function connectionPlatform(mixed $connectionId): string
    {
        if ($this->db === null) {
            return AiFunctionResolver::DEFAULT_PLATFORM;
        }
        $row = is_numeric($connectionId) && (int) $connectionId > 0
            ? $this->db->fetchRow('SELECT `provider` FROM `core_ai_backends` WHERE `id` = %i', (int) $connectionId)
            : $this->db->fetchRow('SELECT `provider` FROM `core_ai_backends` WHERE `is_default` = %i AND `is_active` = %i LIMIT 1', 1, 1);
        $platform = trim((string) ($row['provider'] ?? ''));
        return $platform !== '' ? $platform : AiFunctionResolver::DEFAULT_PLATFORM;
    }
}

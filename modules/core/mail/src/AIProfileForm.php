<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail;

use Shipard\Core\Ai\ModelCatalog;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\TableForm;
use Shipard\Module\Core\Ai\ModelOptions;

/**
 * Formulář AI profilu pošty. Nahradil JSONC formulář kvůli sekci
 * „Přepsání AI funkce“ (tasks/ai-functions.md D4, D43, D44): připojení,
 * model z katalogu nebo vlastní ID (select s `allowCustom`), `model_like`,
 * ladění a strop tokenů — prázdné = podle funkce `mail.extraction`.
 */
class AIProfileForm extends TableForm
{
    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        $catalog = ModelCatalog::fromConfig($this->config);

        $basic = $this->tab('basic', 'AI profil')
            ->section()
                ->col()
                    ->input('profile_id', required: true)
                    ->input('name', required: true)
                    ->separator('Záběr')
                    ->textarea('supported_doc_types')
                    ->input('language', required: true)
                    ->separator('Prompt')
                    ->input('prompt_version')
                    ->textarea('prompt_template')
                    ->separator('Výstup')
                    ->textarea('output_schema')
                    ->textarea('confidence_thresholds')
                    ->separator('Přepsání AI funkce Vytěžení došlé pošty')
                    ->lookup(
                        'backend',
                        table: 'core_ai_backends',
                        placeholder: 'Podle funkce',
                        hint: 'Prázdné = připojení podle nastavení AI funkce (jinak výchozí připojení zdroje dat).',
                    )
                    ->select(
                        'model',
                        options: ModelOptions::catalogModels($catalog, $data['model'] ?? null),
                        required: false,
                        placeholder: 'Podle funkce',
                        hint: 'Model z katalogu, nebo vlastní ID tak, jak ho zná platforma. Prázdné = model podle nastavení AI funkce.',
                        allowCustom: true,
                    )
                    ->select(
                        'model_like',
                        options: ModelOptions::catalogModels($catalog),
                        required: false,
                        placeholder: 'Jen u vlastního ID modelu',
                        hint: 'U vlastního ID: model z katalogu, podle kterého se požadavek tvaruje. Cena volání je pak 0.',
                    )
                    ->select('thinking', options: ModelOptions::tuning($this->config, 'core.ai.thinkingModes'), required: true)
                    ->select('effort', options: ModelOptions::tuning($this->config, 'core.ai.effortLevels'), required: true)
                    ->input(
                        'max_tokens',
                        inputType: 'number',
                        hint: '0 = strop podle nastavení AI funkce.',
                    )
                    ->separator('Příznaky')
                    ->checkbox('is_default')
                    ->checkbox('is_active')
            ->build();

        return new FormDefinition(
            table: $this->table,
            title: 'AI profil',
            titleNew: 'Nový AI profil',
            tabs: [$basic],
        );
    }
}

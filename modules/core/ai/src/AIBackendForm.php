<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Ai;

use Shipard\Core\Ai\AiFunctionResolver;
use Shipard\Core\Ai\AiPlatform;
use Shipard\Core\Ai\AiPlatforms;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\TableForm;

/**
 * Formulář AI připojení (`core_ai_backends`, tasks/ai-bedrock-eu.md krok 4):
 * platforma jako výběr z `core.ai.platforms`, po její změně se formulář
 * přestaví (`triggers: reload`) — adresa dostane jako placeholder výchozí
 * adresu platformy (prázdné pole = výchozí, jako dřív) a nápověda ke klíči
 * i k adrese je podle platformy (`AiPlatform::$keyHint` / `$baseUrlHint`).
 * Klíč se ve formuláři nezadává — jen `ai-backend-set-key`; model a ladění
 * jsou u AI funkcí (D39).
 */
class AIBackendForm extends TableForm
{
    private const string DEFAULT_KEY_HINT = 'API klíč se nastavuje přes CLI (ai-backend-set-key); model a ladění jsou u AI funkcí';

    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        $platforms = AiPlatforms::fromConfig($this->config);
        $platformId = trim((string) ($data['provider'] ?? '')) ?: AiFunctionResolver::DEFAULT_PLATFORM;
        $platform = $platforms->getOrFallback($platformId);

        $basic = $this->tab('basic', 'AI připojení')
            ->section()
                ->col()
                    ->input('backend_id', required: true)
                    ->input('name', required: true)
                    ->separator('Platforma')
                    ->select(
                        'provider',
                        options: $this->platformOptions($platforms, $platformId),
                        required: true,
                        triggers: 'reload',
                        hint: 'Kam se volá — určuje dialekt API, výchozí adresu, ID modelů a cenu.',
                    )
                    ->input(
                        'base_url',
                        placeholder: $platform->baseUrl !== '' ? $platform->baseUrl : null,
                        hint: $platform->baseUrlHint !== '' ? $platform->baseUrlHint : 'Prázdné = výchozí adresa platformy.',
                    )
                    ->separator($platform->keyHint !== '' ? $platform->keyHint : self::DEFAULT_KEY_HINT)
                    ->separator('Příznaky')
                    ->checkbox('is_default')
                    ->checkbox('is_active')
            ->build();

        return new FormDefinition(
            table: $this->table,
            title: 'AI připojení',
            titleNew: 'Nové AI připojení',
            tabs: [$basic],
        );
    }

    /**
     * Platformy z cfgItemu podle `order`; uložená hodnota mimo mapu (nebo
     * bez compiled configu) zůstává v nabídce, aby ji formulář neztratil.
     *
     * @return list<array{value: string, label: string}>
     */
    private function platformOptions(AiPlatforms $platforms, string $current): array
    {
        $options = array_map(
            static fn (AiPlatform $p): array => ['value' => $p->id, 'label' => $p->name],
            $platforms->all(),
        );
        if (!$platforms->has($current)) {
            $options[] = ['value' => $current, 'label' => $current];
        }
        return $options;
    }
}

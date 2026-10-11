<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Ai;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Ai\AiPlatform;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormElement;
use Shipard\Module\Core\Ai\AIBackendForm;

/**
 * Formulář AI připojení (tasks/ai-bedrock-eu.md krok 4): platforma jako
 * výběr, výchozí adresa a nápovědy podle vybrané platformy, přestavění po
 * změně platformy, chování bez compiled configu.
 */
final class AIBackendFormTest extends TestCase
{
    private const PLATFORMS = [
        'bedrock-eu' => ['name' => 'Amazon Bedrock (EU)', 'order' => 20, 'baseUrl' => 'https://bedrock-runtime.eu-central-1.amazonaws.com/anthropic',
            'modelIds' => 'bedrock', 'modelIdPrefix' => 'eu.', 'priceMultiplier' => 1.1,
            'keyHint' => 'Dlouhodobý API klíč Bedrocku z konzole AWS, vygenerovaný v regionu adresy.',
            'baseUrlHint' => 'Prázdné = Frankfurt (eu-central-1).'],
        'anthropic' => ['name' => 'Anthropic', 'order' => 10, 'baseUrl' => 'https://api.anthropic.com',
            'keyHint' => 'API klíč z účtu u Anthropic.', 'baseUrlHint' => 'Prázdné = přímé API Anthropicu.'],
    ];

    private function form(bool $withConfig = true): AIBackendForm
    {
        $form = new AIBackendForm('core_ai_backends');
        if ($withConfig) {
            $config = $this->createMock(ConfigRuntime::class);
            $config->method('cfgItem')->willReturnCallback(static fn (string $id): mixed => $id === AiPlatform::CFG_ITEM ? self::PLATFORMS : null);
            $form->setConfig($config);
        }
        return $form;
    }

    /** @return list<FormElement> */
    private function elements(FormDefinition $def): array
    {
        return $def->tabs[0]->sections[0]->columns[0]->elements;
    }

    private function element(FormDefinition $def, string $column): FormElement
    {
        foreach ($this->elements($def) as $el) {
            if ($el->column === $column) {
                return $el;
            }
        }
        $this->fail("element {$column} missing");
    }

    /** @return list<string> popisky separátorů v pořadí */
    private function separators(FormDefinition $def): array
    {
        $labels = [];
        foreach ($this->elements($def) as $el) {
            if ($el->type === 'separator') {
                $labels[] = (string) $el->label;
            }
        }
        return $labels;
    }

    public function testPlatformSelectAndDefaultsForAnthropic(): void
    {
        $def = $this->form()->buildFormDefinition(['provider' => 'anthropic'], false);

        $this->assertSame('AI připojení', $def->title);
        $provider = $this->element($def, 'provider');
        $this->assertSame('select', $provider->type);
        $this->assertTrue($provider->required);
        $this->assertSame('reload', $provider->triggers, 'změna platformy formulář přestaví');
        $this->assertSame(
            [['value' => 'anthropic', 'label' => 'Anthropic'], ['value' => 'bedrock-eu', 'label' => 'Amazon Bedrock (EU)']],
            $provider->options,
            'podle order',
        );

        $baseUrl = $this->element($def, 'base_url');
        $this->assertSame('https://api.anthropic.com', $baseUrl->placeholder, 'výchozí adresa platformy; prázdné pole ji použije');
        $this->assertSame('Prázdné = přímé API Anthropicu.', $baseUrl->hint);
        $this->assertSame(['Platforma', 'API klíč z účtu u Anthropic.', 'Příznaky'], $this->separators($def));
        $this->assertSame('checkbox', $this->element($def, 'is_default')->inputType);
        $this->assertSame('checkbox', $this->element($def, 'is_active')->inputType);
    }

    public function testBedrockEuShowsItsAddressAndKeyAdvice(): void
    {
        $form = $this->form();
        $def = $form->buildFormDefinition(['provider' => 'bedrock-eu'], false);

        $this->assertSame('https://bedrock-runtime.eu-central-1.amazonaws.com/anthropic', $this->element($def, 'base_url')->placeholder);
        $this->assertSame('Prázdné = Frankfurt (eu-central-1).', $this->element($def, 'base_url')->hint);
        $this->assertContains('Dlouhodobý API klíč Bedrocku z konzole AWS, vygenerovaný v regionu adresy.', $this->separators($def));

        // Recalculate po změně platformy = nový build.
        $recalc = $form->recalculate('provider', ['id' => 2, 'provider' => 'bedrock-eu']);
        $this->assertSame('https://bedrock-runtime.eu-central-1.amazonaws.com/anthropic', $this->element($recalc->formDefinition, 'base_url')->placeholder);

        // Nový záznam bez platformy → anthropic.
        $new = $form->buildFormDefinition([], true);
        $this->assertSame('Nové AI připojení', $new->titleNew);
        $this->assertSame('https://api.anthropic.com', $this->element($new, 'base_url')->placeholder);
    }

    public function testUnknownPlatformAndMissingConfigKeepTheStoredValue(): void
    {
        $def = $this->form()->buildFormDefinition(['provider' => 'openai'], false);
        $options = $this->element($def, 'provider')->options;
        $this->assertSame(['value' => 'openai', 'label' => 'openai'], end($options));
        $this->assertNull($this->element($def, 'base_url')->placeholder, 'neznámá platforma bez výchozí adresy');

        $def = $this->form(false)->buildFormDefinition(['provider' => 'anthropic'], false);
        $this->assertSame([['value' => 'anthropic', 'label' => 'anthropic']], $this->element($def, 'provider')->options);
        $this->assertSame('Prázdné = výchozí adresa platformy.', $this->element($def, 'base_url')->hint);
        $this->assertContains('API klíč se nastavuje přes CLI (ai-backend-set-key); model a ladění jsou u AI funkcí', $this->separators($def));
    }
}

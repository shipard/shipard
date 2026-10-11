<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Ai;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Ai\AiFunctionRegistry;
use Shipard\Core\Ai\AiFunctionResolver;
use Shipard\Core\Ai\ModelCatalog;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormElement;
use Shipard\Module\Core\Ai\AiFunctionSettingsForm;

/**
 * Formulář nastavení AI funkce: model úrovně v placeholderu a souhrnu
 * platí pro platformu efektivního připojení (tasks/ai-bedrock-eu.md D58)
 * — vybrané připojení, jinak výchozí; změna připojení formulář přestaví.
 */
final class AiFunctionSettingsFormTest extends TestCase
{
    private const FUNCTIONS = [
        'mail.extraction' => ['name' => 'Vytěžení došlé pošty', 'description' => 'Čte doklady z pošty', 'tier' => 'balanced', 'defaults' => ['maxTokens' => 32768]],
    ];

    private const MODELS = [
        'claude-sonnet-5-5' => ['name' => 'Claude Sonnet 5.5', 'order' => 20, 'tier' => 'balanced',
            'platformIds' => ['anthropic' => 'claude-sonnet-5-5', 'bedrock' => 'anthropic.claude-sonnet-5-5'], 'prices' => ['input' => 2, 'output' => 10]],
        'claude-sonnet-4-6' => ['name' => 'Claude Sonnet 4.6', 'order' => 22, 'tier' => 'balanced',
            'platformIds' => ['anthropic' => 'claude-sonnet-4-6'], 'prices' => ['input' => 3, 'output' => 15]],
    ];

    private const TIERS = [
        'balanced' => ['name' => 'Vyvážený', 'order' => 20, 'model' => 'claude-sonnet-4-6', 'platformModels' => ['bedrock-eu' => 'claude-sonnet-5-5']],
    ];

    private function form(bool $withDb = true): AiFunctionSettingsForm
    {
        $items = [
            AiFunctionRegistry::CFG_ITEM => self::FUNCTIONS,
            ModelCatalog::CFG_ITEM => self::MODELS,
            ModelCatalog::TIERS_CFG_ITEM => self::TIERS,
            AiFunctionResolver::PLATFORMS_CFG_ITEM => ['anthropic' => ['name' => 'Anthropic'], 'bedrock-eu' => ['name' => 'Amazon Bedrock (EU)', 'modelIds' => 'bedrock', 'modelIdPrefix' => 'eu.']],
        ];
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(static fn (string $id): mixed => $items[$id] ?? null);

        $form = new AiFunctionSettingsForm('core_ai_function_settings');
        $form->setConfig($config);
        if ($withDb) {
            // Připojení 7 = Bedrock EU, výchozí aktivní připojení = Anthropic.
            $db = $this->createMock(DataSourceConnection::class);
            $db->method('fetchRow')->willReturnCallback(static function (string $sql, mixed ...$args): ?array {
                if (str_contains($sql, 'WHERE `id` = %i')) {
                    return ($args[0] ?? 0) === 7 ? ['provider' => 'bedrock-eu'] : null;
                }
                return ['provider' => 'anthropic'];
            });
            $form->setDb($db);
        }
        return $form;
    }

    private function element(FormDefinition $def, string $column): FormElement
    {
        foreach ($def->tabs as $tab) {
            foreach ($tab->sections as $section) {
                foreach ($section->columns as $col) {
                    foreach ($col->elements as $el) {
                        if ($el->column === $column) {
                            return $el;
                        }
                    }
                }
            }
        }
        $this->fail("element {$column} missing");
    }

    private function summary(FormDefinition $def): string
    {
        foreach ($def->tabs[0]->sections[0]->columns[0]->elements as $el) {
            if ($el->type === 'html') {
                return (string) $el->content;
            }
        }
        $this->fail('summary missing');
    }

    public function testTierModelFollowsThePlatformOfTheEffectiveConnection(): void
    {
        $form = $this->form();

        // Bez vybraného připojení: výchozí připojení (Anthropic) → obecný model úrovně.
        $def = $form->buildFormDefinition(['function_id' => 'mail.extraction', 'connection' => null], false);
        $this->assertSame('Podle úrovně (claude-sonnet-4-6)', $this->element($def, 'model')->placeholder);
        $this->assertStringContainsString('Úroveň: Vyvážený → claude-sonnet-4-6</p>', $this->summary($def));
        $this->assertSame('reload', $this->element($def, 'connection')->triggers, 'změna připojení formulář přestaví');

        // Připojení na Bedrocku EU → model úrovně pro platformu, souhrn říká kterou.
        $def = $form->buildFormDefinition(['function_id' => 'mail.extraction', 'connection' => 7], false);
        $this->assertSame('Podle úrovně (claude-sonnet-5-5)', $this->element($def, 'model')->placeholder);
        $this->assertStringContainsString('Úroveň: Vyvážený → claude-sonnet-5-5 (na platformě Amazon Bedrock (EU))', $this->summary($def));

        // Recalculate po změně připojení dává totéž co build.
        $recalc = $form->recalculate('connection', ['id' => 3, 'function_id' => 'mail.extraction', 'connection' => 7]);
        $this->assertSame('Podle úrovně (claude-sonnet-5-5)', $this->element($recalc->formDefinition, 'model')->placeholder);

        // Neznámé připojení → platforma anthropic.
        $def = $form->buildFormDefinition(['function_id' => 'mail.extraction', 'connection' => 99], false);
        $this->assertSame('Podle úrovně (claude-sonnet-4-6)', $this->element($def, 'model')->placeholder);
    }

    public function testWithoutDatabaseTheDirectApiModelIsShown(): void
    {
        $def = $this->form(false)->buildFormDefinition(['function_id' => 'mail.extraction', 'connection' => 7], false);
        $this->assertSame('Podle úrovně (claude-sonnet-4-6)', $this->element($def, 'model')->placeholder);
    }
}

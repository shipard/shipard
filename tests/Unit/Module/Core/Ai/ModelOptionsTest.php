<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Ai;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Ai\ModelCatalog;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Core\Ai\ModelOptions;

/** Nabídky modelů a ladění pro formuláře AI funkce / profilu (D43, D44). */
final class ModelOptionsTest extends TestCase
{
    private const MODELS = [
        'claude-haiku-5-5' => ['name' => 'Claude Haiku 5.5', 'order' => 30, 'tier' => 'fast', 'platformIds' => ['anthropic' => 'claude-haiku-5-5'], 'prices' => ['input' => 0.1, 'output' => 0.5]],
        'claude-sonnet-4-6' => ['name' => 'Claude Sonnet 4.6', 'order' => 22, 'tier' => 'balanced', 'platformIds' => ['anthropic' => 'claude-sonnet-4-6'], 'prices' => ['input' => 3, 'output' => 15]],
        'claude-sonnet-4-5' => ['name' => 'Claude Sonnet 4.5', 'order' => 23, 'tier' => 'balanced', 'platformIds' => ['anthropic' => 'claude-sonnet-4-5'], 'prices' => ['input' => 3, 'output' => 15],
            'lifecycle' => ['status' => 'deprecated', 'retiresAt' => '2026-11-30', 'replacedBy' => 'claude-sonnet-4-6']],
        'claude-opus-4-1' => ['name' => 'Claude Opus 4.1', 'order' => 113, 'platformIds' => ['anthropic' => 'claude-opus-4-1'], 'prices' => ['input' => 15, 'output' => 75]],
    ];

    public function testCatalogModelsOfferOnlyTieredModelsOrderedWithRetirementAndCustomValue(): void
    {
        $catalog = ModelCatalog::fromArray(self::MODELS);

        $this->assertSame([
            ['value' => 'claude-sonnet-4-6', 'label' => 'Claude Sonnet 4.6 (claude-sonnet-4-6)'],
            ['value' => 'claude-sonnet-4-5', 'label' => 'Claude Sonnet 4.5 (claude-sonnet-4-5) — končí 2026-11-30'],
            ['value' => 'claude-haiku-5-5', 'label' => 'Claude Haiku 5.5 (claude-haiku-5-5)'],
        ], ModelOptions::catalogModels($catalog));

        // Uložená hodnota mimo katalog zůstává v nabídce jako vlastní ID;
        // hodnota z katalogu (i s datovou příponou) se nepřidává.
        $options = ModelOptions::catalogModels($catalog, 'claude-sonnet-6');
        $this->assertSame(['value' => 'claude-sonnet-6', 'label' => 'claude-sonnet-6 (vlastní ID)'], end($options));
        $this->assertCount(3, ModelOptions::catalogModels($catalog, 'claude-sonnet-4-5-20250929'));
        $this->assertCount(3, ModelOptions::catalogModels($catalog, ' '));
        $this->assertSame([], ModelOptions::catalogModels(ModelCatalog::empty()));
    }

    public function testTuningOptionsComeFromTheCfgItemWithFallback(): void
    {
        $cfg = $this->createMock(ConfigRuntime::class);
        $cfg->method('cfgItem')->willReturnCallback(static fn (string $id): mixed => $id === 'core.ai.thinkingModes'
            ? ['default' => ['name' => 'Podle funkce'], 'auto' => ['name' => 'Výchozí modelu'], 'adaptive' => ['name' => 'Adaptivní']]
            : null);

        $this->assertSame([
            ['value' => 'default', 'label' => 'Podle funkce'],
            ['value' => 'auto', 'label' => 'Výchozí modelu'],
            ['value' => 'adaptive', 'label' => 'Adaptivní'],
        ], ModelOptions::tuning($cfg, 'core.ai.thinkingModes'));

        $fallback = ModelOptions::tuning(null, 'core.ai.effortLevels');
        $this->assertSame(['default', 'auto'], array_column($fallback, 'value'));
    }
}

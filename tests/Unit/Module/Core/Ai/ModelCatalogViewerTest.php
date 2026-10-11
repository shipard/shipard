<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Ai;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Ai\ModelCatalog;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Ai\AiViewerBase;
use Shipard\Module\Core\Ai\ModelCatalogViewer;

/**
 * Přehled modelů (tasks/ai-settings-section.md D50): viewer bez tabulky
 * nad cfgItemy `core.ai.models` + `core.ai.tiers` — řazení podle úrovně,
 * položky jen s ceníkem skryté, stav „končí“, úrovně ukazující na model,
 * hledání, syntetický záznam detailu, popisky z `core.ai.viewerLabels`.
 */
final class ModelCatalogViewerTest extends TestCase
{
    private const MODELS = [
        'claude-opus-5-5' => [
            'name' => 'Claude Opus 5.5', 'order' => 10, 'tier' => 'strong',
            'platformIds' => ['anthropic' => 'claude-opus-5-5', 'bedrock' => 'anthropic.claude-opus-5-5'],
            'capabilities' => ['temperature' => false, 'thinkingModes' => ['adaptive'], 'thinkingDefault' => 'adaptive',
                'effortLevels' => ['low', 'medium', 'high', 'xhigh', 'max'], 'maxOutputTokens' => 128000, 'chatToolLoop' => false],
            'prices' => ['input' => 4, 'output' => 20],
            'lifecycle' => ['status' => 'active', 'retiresNotBefore' => '2027-09-22'],
        ],
        'claude-sonnet-4-6' => [
            'name' => 'Claude Sonnet 4.6', 'order' => 22, 'tier' => 'balanced',
            'platformIds' => ['anthropic' => 'claude-sonnet-4-6'],
            'capabilities' => ['temperature' => true, 'thinkingModes' => ['adaptive', 'disabled', 'enabled'], 'thinkingDefault' => 'off',
                'effortLevels' => ['low', 'medium', 'high', 'max'], 'maxOutputTokens' => 128000, 'chatToolLoop' => true],
            'prices' => ['input' => 3, 'output' => 15],
        ],
        'claude-sonnet-4-5' => [
            'name' => 'Claude Sonnet 4.5', 'order' => 23, 'tier' => 'balanced',
            'platformIds' => ['anthropic' => 'claude-sonnet-4-5', 'bedrock' => 'anthropic.claude-sonnet-4-5'],
            'prices' => ['input' => 3, 'output' => 15],
            'lifecycle' => ['status' => 'deprecated', 'retiresAt' => '2026-11-30', 'replacedBy' => 'claude-sonnet-4-6'],
        ],
        'claude-haiku-5-5' => [
            'name' => 'Claude Haiku 5.5', 'order' => 30, 'tier' => 'fast',
            'platformIds' => ['anthropic' => 'claude-haiku-5-5'],
            'capabilities' => ['effortLevels' => []],
            'prices' => ['input' => 0.10, 'output' => 0.50, 'longPrompt' => ['over' => 100000, 'input' => 0.50, 'output' => 2.50]],
        ],
        // Jen ceník (tier null) — v přehledu se neukazují (D50).
        'claude-opus-4-1' => [
            'name' => 'Claude Opus 4.1', 'order' => 113,
            'platformIds' => ['anthropic' => 'claude-opus-4-1'],
            'prices' => ['input' => 15, 'output' => 75],
            'lifecycle' => ['status' => 'retired', 'retiresAt' => '2026-08-05'],
        ],
        'claude-fable-5-1' => [
            'name' => 'Claude Fable 5.1', 'order' => 100,
            'platformIds' => ['anthropic' => 'claude-fable-5-1'],
            'prices' => ['input' => 10, 'output' => 50],
        ],
    ];

    /** Dvě úrovně na jednom modelu, strong na Sonnetu — badge „Výchozí pro“ s více názvy. */
    private const TIERS = [
        'fast' => ['name' => 'Rychlý', 'order' => 10, 'model' => 'claude-haiku-5-5'],
        'balanced' => ['name' => 'Vyvážený', 'order' => 20, 'model' => 'claude-sonnet-4-6'],
        'strong' => ['name' => 'Silný', 'order' => 30, 'model' => 'claude-sonnet-4-6'],
    ];

    private const LABELS = [
        'badge.defaultFor' => ['name' => 'Výchozí pro: {tiers}'],
        'badge.retiring' => ['name' => 'Končí {date}'],
        'row.prices' => ['name' => 'vstup {input} · výstup {output} / 1M tokenů'],
        'group.model' => ['name' => 'Model'],
        'group.tiers' => ['name' => 'Úrovně'],
        'group.prices' => ['name' => 'Ceny'],
        'group.capabilities' => ['name' => 'Schopnosti'],
        'group.platforms' => ['name' => 'ID na platformách'],
        'label.status' => ['name' => 'Stav'],
        'label.replacedBy' => ['name' => 'Náhrada'],
        'label.retiresNotBefore' => ['name' => 'Dostupný nejméně do'],
        'label.defaultFor' => ['name' => 'Výchozí model úrovní'],
        'label.temperature' => ['name' => 'Přijímá teplotu'],
        'label.thinkingModes' => ['name' => 'Režimy přemýšlení'],
        'label.effortLevels' => ['name' => 'Úrovně úsilí'],
        'label.maxOutputTokens' => ['name' => 'Strop výstupu'],
        'label.longPrompt' => ['name' => 'Dlouhý vstup'],
        'value.active' => ['name' => 'Aktivní'],
        'value.retiring' => ['name' => 'Končí {date}'],
        'value.none' => ['name' => 'Žádná'],
        'value.no' => ['name' => 'Ne'],
        'value.unknown' => ['name' => 'Neznámo — posílá se beze změny'],
        'value.noEffort' => ['name' => 'Nepodporuje'],
        'value.tokens' => ['name' => '{n} tokenů'],
        'value.longPrompt' => ['name' => 'nad {over} vstupních tokenů: vstup {input} · výstup {output} / 1M tokenů'],
    ];

    private function viewer(bool $withLabels = true, ?string $language = 'cs'): ModelCatalogViewer
    {
        $items = [
            ModelCatalog::CFG_ITEM => self::MODELS,
            ModelCatalog::TIERS_CFG_ITEM => self::TIERS,
            'core.ai.thinkingModes' => ['adaptive' => ['name' => 'Adaptivní'], 'disabled' => ['name' => 'Vypnuté'], 'enabled' => ['name' => 'Zapnuté']],
            'core.ai.effortLevels' => ['low' => ['name' => 'Nízké'], 'medium' => ['name' => 'Střední'], 'high' => ['name' => 'Vysoké'], 'xhigh' => ['name' => 'Velmi vysoké'], 'max' => ['name' => 'Maximální']],
            'core.ai.platforms' => ['anthropic' => ['name' => 'Anthropic']],
        ];
        if ($withLabels) {
            $items[AiViewerBase::LABELS_CFG_ITEM] = self::LABELS;
        }
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(static fn (string $id): mixed => $items[$id] ?? null);

        // Viewer bez tabulky: databáze se nikdy nedotkne — mock bez metod by padl.
        $db = $this->createMock(DataSourceConnection::class);
        $db->expects($this->never())->method('fetchAll');
        $db->expects($this->never())->method('fetchRow');

        $viewer = new ModelCatalogViewer($db, '');
        $viewer->setConfig($config);
        if ($language !== null) {
            $viewer->setLanguage($language);
        }
        return $viewer;
    }

    /** @return array<string, string> label → value */
    private function items(array $detail, string $groupTitle): array
    {
        foreach ($detail['tabs'][0]['content']['groups'] as $group) {
            if ($group['title'] === $groupTitle) {
                return array_column($group['items'], 'value', 'label');
            }
        }
        $this->fail("group {$groupTitle} missing");
    }

    public function testRowsAreSortedByTierThenOrderAndHidePriceOnlyModels(): void
    {
        $rows = $this->viewer()->selectRows(null, [], 0);

        $this->assertSame([1, 2, 3, 4], array_column($rows, 'id'), 'id = pozice od 1');
        $this->assertSame(
            ['claude-haiku-5-5', 'claude-sonnet-4-6', 'claude-sonnet-4-5', 'claude-opus-5-5'],
            array_map(static fn (array $r): string => $r['spec']->id, $rows),
            'fast → balanced → strong, v úrovni podle order; Opus 4.1 a Fable jen s ceníkem chybí',
        );
    }

    public function testRowShowsTierDefaultsStatusAndPrices(): void
    {
        $viewer = $this->viewer();
        $rows = $viewer->selectRows(null, [], 0);

        $sonnet46 = $viewer->renderRow($rows[1]);
        $this->assertSame('Claude Sonnet 4.6', $sonnet46['t1']);
        $this->assertSame('claude-sonnet-4-6', $sonnet46['t3']);
        $this->assertSame([
            ['text' => 'Vyvážený', 'class' => 'muted'],
            ['text' => 'Výchozí pro: Vyvážený, Silný', 'class' => 'primary'],
        ], $sonnet46['i1']);
        $this->assertSame(['text' => 'vstup $3 · výstup $15 / 1M tokenů', 'class' => 'muted'], $sonnet46['t2']);

        $sonnet45 = $viewer->renderRow($rows[2]);
        $this->assertSame([
            ['text' => 'Vyvážený', 'class' => 'muted'],
            ['text' => 'Končí 30. 11. 2026', 'class' => 'warning'],
        ], $sonnet45['i1'], 'vyřazovaný model nese datum konce, na nic neukazuje');

        $haiku = $viewer->renderRow($rows[0]);
        $this->assertSame(['text' => 'vstup $0.10 · výstup $0.50 / 1M tokenů', 'class' => 'muted'], $haiku['t2']);
        $this->assertSame('Výchozí pro: Rychlý', $haiku['i1'][1]['text']);
    }

    public function testSearchMatchesNameOrIdAndKeepsIds(): void
    {
        $viewer = $this->viewer();

        $byName = $viewer->selectRows('haiku', [], 0);
        $this->assertCount(1, $byName);
        $this->assertSame(1, $byName[0]['id']);

        $byId = $viewer->selectRows('4-5', [], 0);
        $this->assertCount(1, $byId);
        $this->assertSame(3, $byId[0]['id'], 'id je pozice v celém seznamu, hledání ji nemění');

        $this->assertSame([], $viewer->selectRows('fable', [], 0), 'model jen s ceníkem nenajde ani hledání');
    }

    public function testLoadRecordReturnsSyntheticRecordOrNull(): void
    {
        $viewer = $this->viewer();

        $this->assertSame('claude-opus-5-5', $viewer->loadRecord(4)['spec']->id);
        $this->assertNull($viewer->loadRecord(5));
        $this->assertNull($viewer->loadRecord(0));
        $this->assertSame(['tabs' => []], $viewer->renderDetail(99));
    }

    public function testDetailGroupsForActiveModel(): void
    {
        $detail = $this->viewer()->renderDetail(4);

        $this->assertSame('Claude Opus 5.5', $detail['title']);
        $this->assertSame('claude-opus-5-5', $detail['subtitle']);
        $this->assertSame(
            ['Model', 'Úrovně', 'Ceny', 'Schopnosti', 'ID na platformách'],
            array_column($detail['tabs'][0]['content']['groups'], 'title'),
        );

        $model = $this->items($detail, 'Model');
        $this->assertSame('Aktivní', $model['Stav']);
        $this->assertSame('22. 9. 2027', $model['Dostupný nejméně do']);
        $this->assertArrayNotHasKey('Náhrada', $model);

        $this->assertSame(['Výchozí model úrovní' => 'Žádná'], $this->items($detail, 'Úrovně'));

        $caps = $this->items($detail, 'Schopnosti');
        $this->assertSame('Ne', $caps['Přijímá teplotu']);
        $this->assertSame('Adaptivní', $caps['Režimy přemýšlení']);
        $this->assertSame('Nízké, Střední, Vysoké, Velmi vysoké, Maximální', $caps['Úrovně úsilí']);
        $this->assertSame('128 000 tokenů', $caps['Strop výstupu']);

        $this->assertSame(
            ['Anthropic' => 'claude-opus-5-5', 'bedrock' => 'anthropic.claude-opus-5-5'],
            $this->items($detail, 'ID na platformách'),
            'platforma bez položky v core.ai.platforms zůstane klíčem',
        );
    }

    public function testDetailForRetiringModelAndUnknownCapabilities(): void
    {
        $viewer = $this->viewer();

        $sonnet45 = $viewer->renderDetail(3);
        $model = $this->items($sonnet45, 'Model');
        $this->assertSame('Končí 30. 11. 2026', $model['Stav']);
        $this->assertSame('claude-sonnet-4-6', $model['Náhrada']);
        $caps = $this->items($sonnet45, 'Schopnosti');
        $this->assertSame('Neznámo — posílá se beze změny', $caps['Přijímá teplotu']);
        $this->assertSame('Neznámo — posílá se beze změny', $caps['Režimy přemýšlení']);
        $this->assertSame('Neznámo — posílá se beze změny', $caps['Strop výstupu']);

        $haiku = $viewer->renderDetail(1);
        $this->assertSame('Nepodporuje', $this->items($haiku, 'Schopnosti')['Úrovně úsilí'], '[] = parametr nezná');
        $this->assertSame(
            'nad 100 000 vstupních tokenů: vstup $0.50 · výstup $2.50 / 1M tokenů',
            $this->items($haiku, 'Ceny')['Dlouhý vstup'],
        );
    }

    public function testEnglishFallbackWithoutLabelsAndLanguage(): void
    {
        $viewer = $this->viewer(withLabels: false, language: null);
        $rows = $viewer->selectRows(null, [], 0);

        $sonnet45 = $viewer->renderRow($rows[2]);
        $this->assertSame(['text' => 'Retires 2026-11-30', 'class' => 'warning'], $sonnet45['i1'][1], 'bez cfgItemu anglicky, datum ISO');
        $this->assertSame('in $3 · out $15 / 1M tokens', $sonnet45['t2']['text']);
        $this->assertSame('Default for Vyvážený, Silný', $viewer->renderRow($rows[1])['i1'][1]['text'], 'názvy úrovní jdou z mapy úrovní');
    }

    public function testReadOnlyToolbarAndEmptyCatalog(): void
    {
        $this->assertSame([], $this->viewer()->getToolbarActions(null));
        $this->assertSame([], $this->viewer()->getToolbarActions(['id' => 1]));

        $empty = new ModelCatalogViewer($this->createMock(DataSourceConnection::class), '');
        $this->assertSame([], $empty->selectRows(null, [], 0), 'bez compiled configu prázdný přehled, ne pád');
        $this->assertNull($empty->loadRecord(1));
    }
}

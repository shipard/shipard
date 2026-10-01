<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Assets;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Economy\Assets\AssetPlanService;
use Shipard\Module\Economy\Assets\AssetsViewer;

/**
 * Spodní taby vieweru karet (druhy z cfgItem + Cizí) a jejich promítnutí
 * do dotazu; filtry Typ / Účetní skupina.
 */
class AssetsViewerTest extends TestCase
{
    private const CATEGORIES = [
        'small'    => ['name' => 'Drobný majetek', 'longTerm' => false],
        'tangible' => ['name' => 'Dlouhodobý hmotný', 'longTerm' => true, 'depreciable' => true],
    ];

    private const LABELS = [
        'tab.foreign'       => ['name' => 'Cizí'],
        'tab.taxPlan'       => ['name' => 'Daňové odpisy'],
        'action.activate'   => ['name' => 'Zařadit'],
        'action.depreciate' => ['name' => 'Odepsat'],
        'action.disposal'   => ['name' => 'Vyřadit'],
    ];

    /** @param list<mixed>|null $captured */
    private function viewer(?array &$captured = null): AssetsViewer
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            function (...$args) use (&$captured): array {
                $captured = $args;
                return [];
            },
        );
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([
            ['economy.assets.categories', self::CATEGORIES],
            ['economy.assets.viewerLabels', self::LABELS],
            ['core.system.docStatesArchive', [
                '10' => ['stateName' => 'Koncept', 'stateStyle' => 'concept', 'mainState' => 1, 'viewGroup' => 'active', 'goto' => [40]],
                '40' => ['stateName' => 'V pořádku', 'stateStyle' => 'done', 'mainState' => 3, 'viewGroup' => 'active', 'goto' => [70]],
                '70' => ['stateName' => 'V archívu', 'stateStyle' => 'archive', 'mainState' => 4, 'viewGroup' => 'archive', 'goto' => []],
            ]],
        ]);

        $viewer = new AssetsViewer($db, 'economy_assets_assets');
        $viewer->setConfig($config);
        return $viewer;
    }

    /**
     * Viewer s detailem nad TestAssetPlanService (karta i události v paměti).
     *
     * @param array<int, array{docId: int, docNumber: string}> $posting zaúčtované události
     */
    private function detailViewer(array $card, array $events, array $posting = []): AssetsViewer
    {
        $service = new TestAssetPlanService();
        $service->cards[(int) $card['id']] = $card;
        $service->events = $events;
        $service->posting = $posting;

        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn($card);
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([
            ['economy.assets.categories', self::CATEGORIES],
            ['economy.assets.viewerLabels', self::LABELS],
            ['economy.assets.eventKinds', ['activation' => ['name' => 'Zařazení'], 'depreciation' => ['name' => 'Odpis']]],
            ['world.assets.cz', TestAssetPlanService::config()->cfgItem('world.assets.cz')],
        ]);

        $viewer = new class($db, 'economy_assets_assets', $service) extends AssetsViewer {
            public function __construct(DataSourceConnection $db, string $table, private readonly AssetPlanService $service)
            {
                parent::__construct($db, $table);
            }

            protected function planService(): AssetPlanService
            {
                return $this->service;
            }
        };
        $viewer->setConfig($config);
        return $viewer;
    }

    // --- detail: plán a akce (fáze 2b) ------------------------------------------

    public function testDetailOfActivatedCardHasPlanTabsAndEventActions(): void
    {
        $card = ['id' => 4, 'name' => 'Soustruh', 'category' => 'tangible', 'tax_method' => 'straight', 'tax_rule' => 'cz-2',
            'acc_method' => 'as_tax', 'docState' => 40, 'is_foreign' => 0, 'tracking' => 'single'];
        $events = [['id' => 1, 'asset' => 4, 'event_kind' => 'activation', 'scope' => 'both', 'event_date' => '2022-03-15', 'amount' => 100000, 'docState' => 40]];
        $detail = $this->detailViewer($card, $events)->renderDetail(4);

        $this->assertSame(['overview', 'taxPlan', 'accPlan'], array_column($detail['tabs'], 'id'));
        $this->assertSame('Daňové odpisy', $detail['tabs'][1]['label']);
        $blocks = $detail['tabs'][1]['content']['blocks'];
        $this->assertSame('composite', $detail['tabs'][1]['content']['type']);
        $this->assertSame('properties', $blocks[0]['type']);
        $this->assertSame('table', $blocks[1]['type']);
        $this->assertSame('Zařazení', $blocks[1]['rows'][0]['kind']);
        $this->assertSame('muted', $blocks[1]['rows'][1]['_class']);
        $this->assertSame('11 000,00', $blocks[1]['rows'][1]['amount']);

        $actions = $detail['actions'];
        $this->assertSame(['depreciation_run', 'improvement', 'reduction', 'interruption', 'disposal'], array_column($actions, 'id'));
        $this->assertSame(['assetId' => 4], $actions[0]['target']);
        $this->assertSame('open_form', $actions[4]['kind']);
        $this->assertSame(['asset' => 4, 'event_kind' => 'disposal', 'scope' => 'both'], $actions[4]['target']['preset']);
    }

    public function testAccountingPlanShowsPostingStateOfConfirmedRows(): void
    {
        // D52: zařazení je zaúčtované dokladem, potvrzený účetní odpis
        // 2022 na zaúčtování čeká; plánované řádky a daňový okruh beze změny.
        $card = ['id' => 4, 'name' => 'Soustruh', 'category' => 'tangible', 'tax_method' => 'straight', 'tax_rule' => 'cz-2',
            'acc_method' => 'as_tax', 'docState' => 40, 'is_foreign' => 0, 'tracking' => 'single'];
        $events = [
            ['id' => 1, 'asset' => 4, 'event_kind' => 'activation', 'scope' => 'both', 'event_date' => '2022-03-15', 'amount' => 100000, 'docState' => 40],
            ['id' => 2, 'asset' => 4, 'event_kind' => 'depreciation', 'scope' => 'acc', 'event_date' => '2022-12-31', 'amount' => 11000,
                'period_begin' => '2022-01-01', 'period_end' => '2022-12-31', 'docState' => 40],
        ];
        $detail = $this->detailViewer($card, $events, [1 => ['docId' => 950, 'docNumber' => '60MA220001']])->renderDetail(4);

        $tabs = array_column($detail['tabs'], null, 'id');
        $acc = array_column($tabs['accPlan']['content']['blocks'][1]['rows'], 'status');
        $this->assertSame('Posted — document 60MA220001', $acc[0]);
        $this->assertSame('Waiting for posting', $acc[1]);
        $this->assertSame('Planned', $acc[2]);

        $tax = array_column($tabs['taxPlan']['content']['blocks'][1]['rows'], 'status');
        $this->assertSame('Confirmed', $tax[0], 'daňový okruh se neúčtuje');
    }

    public function testDetailOfNewCardOffersActivationAndOpeningBalances(): void
    {
        $card = ['id' => 5, 'name' => 'Stroj', 'category' => 'tangible', 'tax_method' => 'straight', 'tax_rule' => 'cz-2',
            'acc_method' => 'as_tax', 'docState' => 40, 'is_foreign' => 0, 'tracking' => 'single'];
        $detail = $this->detailViewer($card, [])->renderDetail(5);

        $this->assertSame(['activate', 'openingTax', 'openingAcc'], array_column($detail['actions'], 'id'));
        $this->assertSame('heading', $detail['tabs'][1]['content']['blocks'][1]['type']);

        // Karta mimo V pořádku ani vyřazená nic nenabízí.
        $detail = $this->detailViewer(['docState' => 10] + $card, [])->renderDetail(5);
        $this->assertSame([], $detail['actions']);
        $detail = $this->detailViewer($card, [
            ['id' => 1, 'asset' => 5, 'event_kind' => 'activation', 'scope' => 'both', 'event_date' => '2022-03-15', 'amount' => 100000, 'docState' => 40],
            ['id' => 2, 'asset' => 5, 'event_kind' => 'disposal', 'scope' => 'both', 'event_date' => '2024-05-10', 'amount' => 0, 'docState' => 40],
        ])->renderDetail(5);
        $this->assertSame([], $detail['actions']);
    }

    public function testBottomTabsComeFromCfgItemPlusForeign(): void
    {
        $tabs = $this->viewer()->getBottomTabs();

        $this->assertSame(['all', 'small', 'tangible', 'foreign'], array_column($tabs, 'id'));
        $this->assertSame('Dlouhodobý hmotný', $tabs[2]['label']);
        $this->assertSame(['category' => 'tangible'], $tabs[2]['newRecordDefaults']);
        $this->assertSame(['is_foreign' => 1], $tabs[3]['newRecordDefaults']);
        $this->assertArrayNotHasKey('newRecordDefaults', $tabs[0]);
    }

    public function testCategoryTabFiltersRows(): void
    {
        $captured = null;
        $this->viewer($captured)->selectRows(null, [['id' => 'bottomTab', 'value' => 'tangible']], 0);

        $this->assertStringContainsString('a.`category` = %s', (string) $captured[0]);
        $this->assertContains('tangible', $captured);
    }

    public function testForeignTabFiltersRows(): void
    {
        $captured = null;
        $this->viewer($captured)->selectRows(null, [['id' => 'bottomTab', 'value' => 'foreign']], 0);

        $this->assertStringContainsString('a.`is_foreign` = 1', (string) $captured[0]);
        $this->assertStringNotContainsString('a.`category` = %s', (string) $captured[0]);
    }

    public function testListSelectsUnpostedFlagForTheBadge(): void
    {
        $captured = null;
        $this->viewer($captured)->selectRows(null, [], 0);

        $sql = (string) $captured[0];
        $this->assertStringContainsString('AS `has_unposted`', $sql);
        $this->assertStringContainsString("ue.`scope` <> 'tax'", $sql);
        $this->assertStringContainsString('uh.`docState` IN (30, 90)', $sql);
        $this->assertStringNotContainsString("'opening'", $sql, 'počáteční stav se neúčtuje');
    }

    public function testUnknownTabAndAllTabDoNotFilter(): void
    {
        foreach (['all', 'leasing'] as $tab) {
            $captured = null;
            $this->viewer($captured)->selectRows(null, [['id' => 'bottomTab', 'value' => $tab]], 0);
            $this->assertStringNotContainsString('a.`category` = %s', (string) $captured[0]);
            $this->assertStringNotContainsString('a.`is_foreign` = 1', (string) $captured[0]);
        }
    }

    public function testTypeAndGroupFiltersApply(): void
    {
        $captured = null;
        $this->viewer($captured)->selectRows(
            'vrt',
            [['id' => 'asset_type', 'value' => '5'], ['id' => 'accounting_group', 'value' => '2'], ['id' => 'viewGroup', 'value' => 'all']],
            0,
        );

        $sql = (string) $captured[0];
        $this->assertStringContainsString('a.`asset_type` = %i', $sql);
        $this->assertStringContainsString('a.`accounting_group` = %i', $sql);
        $this->assertStringContainsString('a.`asset_number`', $sql);
        $this->assertContains(5, $captured);
        $this->assertContains(2, $captured);
        $this->assertContains('vrt', $captured);
    }

    public function testRenderRowShowsNumberCategoryForeignAndPriceOnlyForSmall(): void
    {
        $viewer = $this->viewer();

        $small = $viewer->renderRow([
            'id' => 1, 'asset_number' => 'MA0001', 'name' => 'Vrtačka', 'category' => 'small',
            'is_foreign' => 1, 'owner_name' => 'Půjčovna', 'price' => '4990.00',
            'acquired_date' => '2026-03-01', 'docState' => 40, 'type_name' => 'Nářadí',
        ]);
        $this->assertSame('MA0001', $small['i1']);
        $this->assertSame('Vrtačka', $small['t1']);
        $this->assertSame('done', $small['stateStyle']);
        $this->assertContains(['text' => 'Drobný majetek', 'class' => 'muted'], $small['t2']);
        $this->assertContains(['text' => 'Cizí · Půjčovna', 'class' => 'warning'], $small['t2']);
        $this->assertContains(['text' => '4 990,00', 'class' => 'amount'], $small['i2']);
        $this->assertContains(['text' => '01.03.2026', 'class' => 'muted'], $small['i2']);

        $this->assertNotContains(['text' => 'Not posted', 'class' => 'warning'], $small['t2']);

        $long = $viewer->renderRow([
            'id' => 2, 'asset_number' => null, 'name' => 'Stavba', 'category' => 'tangible',
            'is_foreign' => 0, 'price' => '100.00', 'docState' => 10, 'has_unposted' => 1,
        ]);
        // Karta s potvrzenou nezaúčtovanou událostí účetního okruhu (D52).
        $this->assertContains(['text' => 'Not posted', 'class' => 'warning'], $long['t2']);
        $this->assertNull($long['i1']);
        $this->assertNull($long['i2']);
        $this->assertContains(['text' => 'Dlouhodobý hmotný', 'class' => 'primary'], $long['t2']);
    }
}

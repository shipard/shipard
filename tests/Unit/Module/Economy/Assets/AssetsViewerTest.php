<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Assets;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Economy\Assets\AssetsViewer;

/**
 * Spodní taby vieweru karet (druhy z cfgItem + Cizí) a jejich promítnutí
 * do dotazu; filtry Typ / Účetní skupina.
 */
class AssetsViewerTest extends TestCase
{
    private const CATEGORIES = [
        'small'    => ['name' => 'Drobný majetek', 'longTerm' => false],
        'tangible' => ['name' => 'Dlouhodobý hmotný', 'longTerm' => true],
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

        $long = $viewer->renderRow([
            'id' => 2, 'asset_number' => null, 'name' => 'Stavba', 'category' => 'tangible',
            'is_foreign' => 0, 'price' => '100.00', 'docState' => 10,
        ]);
        $this->assertNull($long['i1']);
        $this->assertNull($long['i2']);
        $this->assertContains(['text' => 'Dlouhodobý hmotný', 'class' => 'primary'], $long['t2']);
    }
}

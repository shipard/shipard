<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Accounting;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Accounting\JournalDimension;
use Shipard\Core\Module\ModuleDefinition;
use Shipard\Core\Utils\JsoncParser;

/**
 * Standardní dimenze jádra nad skutečnou konfigurací (#110 D20, D21):
 * deklarace `journalDimensions` v economy.accounting má sloupce a indexy
 * v definicích docs_core_heads, docs_core_rows a economy_accounting_journal,
 * stránka Dimenze na dokladech má pole per dimenzi v pořadí dimenzí
 * a majetek drží invarianty z assets D59–D61 (výchozí hodnota z hlavičky,
 * pole na formulářích, `invpo` bez pole, řádek pořízení kartu z hlavičky
 * nedědí). Modul majetku dimenzi už nevlastní.
 */
class StandardDimensionsTest extends TestCase
{
    private const MODULES = __DIR__ . '/../../../../../modules';
    private const SETTING_PREFIX = 'economy.accounting.dimension.';

    /** @return array<string, mixed> */
    private static function accountingModule(): array
    {
        return JsoncParser::parseFile(self::MODULES . '/economy/accounting/module.jsonc');
    }

    /** @return list<JournalDimension> */
    private static function dimensions(): array
    {
        $module = ModuleDefinition::fromArray(self::accountingModule());
        return array_map(
            static fn(array $dimension): JournalDimension => JournalDimension::fromArray($dimension),
            $module->journalDimensions,
        );
    }

    private static function dimension(string $id): JournalDimension
    {
        foreach (self::dimensions() as $dimension) {
            if ($dimension->id === $id) {
                return $dimension;
            }
        }
        self::fail("Dimenze '{$id}' není v economy.accounting deklarovaná");
    }

    /** @return list<string> */
    private static function ids(array $dimensions): array
    {
        return array_map(static fn(JournalDimension $d): string => $d->id, $dimensions);
    }

    public function testDimensionsAreDeclaredInOrder(): void
    {
        // #110 D20: středisko → zakázka → majetek (pořadí polí na formulářích i v deníku).
        $this->assertSame(['costCenter', 'workOrder', 'asset'], self::ids(self::dimensions()));
    }

    public function testCostCenterIsOnHeadAndRowsOfEveryDocumentIncludingProforma(): void
    {
        $dimension = self::dimension('costCenter');

        $this->assertSame('cost_center', $dimension->headColumn);
        $this->assertNull($dimension->rowFlag, 'Středisko nemá řádek, který by hodnotu nesl sám');
        // T3: zálohová faktura vydaná pole má — periodická fakturace z ní
        // vystavuje zálohy a nese na nich středisko.
        foreach (['invno', 'invpo', 'invni', 'cash', 'cmnbkp'] as $docType) {
            $this->assertTrue($dimension->isOnForm($docType, true), "{$docType}: hlavička");
            $this->assertTrue($dimension->isOnForm($docType, false), "{$docType}: řádek");
        }

        // Řádek bez střediska dědí hlavičku — i řádek pořízení majetku.
        $operations = JsoncParser::parseFile(self::MODULES . '/docs/core/config/rowOperations.jsonc');
        $this->assertSame(3, $dimension->valueOf([], ['cost_center' => 3], $operations['purchase.asset']));
        $this->assertSame(4, $dimension->valueOf(['cost_center' => 4], ['cost_center' => 3]));
    }

    public function testWorkOrderIsOnHeadAndRowsOfEveryDocumentIncludingProforma(): void
    {
        // tasks/work-orders-phase1.md §4: stejné doklady jako středisko,
        // hodnota hlavičky je výchozí pro řádky, žádná vlajka řádku.
        $dimension = self::dimension('workOrder');

        $this->assertSame('work_order', $dimension->headColumn);
        $this->assertSame('work_order', $dimension->rowColumn);
        $this->assertSame('work_order', $dimension->journalColumn);
        $this->assertNull($dimension->rowFlag);
        foreach (['invno', 'invpo', 'invni', 'cash', 'cmnbkp'] as $docType) {
            $this->assertTrue($dimension->isOnForm($docType, true), "{$docType}: hlavička");
            $this->assertTrue($dimension->isOnForm($docType, false), "{$docType}: řádek");
        }
        $this->assertSame(3, $dimension->valueOf([], ['work_order' => 3]));
        $this->assertSame(4, $dimension->valueOf(['work_order' => 4], ['work_order' => 3]));
    }

    public function testEveryDimensionHasColumnsAndIndexesInCoreTables(): void
    {
        $heads = JsoncParser::parseFile(self::MODULES . '/docs/core/tables/docs_core_heads.jsonc');
        $rows = JsoncParser::parseFile(self::MODULES . '/docs/core/tables/docs_core_rows.jsonc');
        $journal = JsoncParser::parseFile(self::MODULES . '/economy/accounting/tables/economy_accounting_journal.jsonc');

        foreach (self::dimensions() as $dimension) {
            $this->assertReferenceColumn($rows, $dimension->rowColumn, $dimension->table, "{$dimension->id}: řádky");
            $this->assertReferenceColumn($journal, $dimension->journalColumn, $dimension->table, "{$dimension->id}: deník");
            $this->assertNotNull($dimension->headColumn, "{$dimension->id}: standardní dimenze má výchozí hodnotu na hlavičce");
            $this->assertReferenceColumn($heads, $dimension->headColumn, $dimension->table, "{$dimension->id}: hlavička");
        }
    }

    /** @param array<string, mixed> $table */
    private function assertReferenceColumn(array $table, string $column, string $reference, string $where): void
    {
        $columns = array_column($table['columns'], null, 'id');
        $this->assertArrayHasKey($column, $columns, "{$where}: sloupec '{$column}' chybí");
        $this->assertSame('int', $columns[$column]['type'], $where);
        $this->assertTrue($columns[$column]['nullable'] ?? false, "{$where}: sloupec musí být nullable");
        $this->assertSame($reference, $columns[$column]['reference'] ?? null, $where);
        $this->assertContains(
            [['column' => $column]],
            array_column($table['indexes'] ?? [], 'columns'),
            "{$where}: index nad '{$column}' chybí",
        );
    }

    public function testExchangeKeysPointToColumnsOfTargetTables(): void
    {
        // #110 T2: středisko kód, majetek inventární číslo — sloupec musí
        // v cílové tabulce existovat, jinak by resolver padal v SQL.
        $tables = [
            'economy_codebooks_cost_centers' => 'economy/codebooks/tables/economy_codebooks_cost_centers.jsonc',
            'economy_work_orders_heads'      => 'economy/workOrders/tables/economy_work_orders_heads.jsonc',
            'economy_assets_assets'          => 'economy/assets/tables/economy_assets_assets.jsonc',
        ];
        $expected = ['costCenter' => 'code', 'workOrder' => 'number', 'asset' => 'asset_number'];
        foreach (self::dimensions() as $dimension) {
            $this->assertSame($expected[$dimension->id] ?? null, $dimension->exchangeKey, $dimension->id);
            $def = JsoncParser::parseFile(self::MODULES . '/' . $tables[$dimension->table]);
            $this->assertContains($dimension->exchangeKey, array_column($def['columns'], 'id'), "{$dimension->id}: klíč v tabulce");
        }
    }

    public function testAssetModuleNoLongerOwnsTheDimension(): void
    {
        $path = self::MODULES . '/economy/assets/module.jsonc';
        $assets = JsoncParser::parseFile($path);

        $this->assertArrayNotHasKey('extensions', $assets);
        $this->assertArrayNotHasKey('journalDimensions', $assets);
        $this->assertDirectoryDoesNotExist(self::MODULES . '/economy/assets/extensions');
        $this->assertNotContains('assetsDocuments', array_column($assets['settingsPages'], 'id'));
        $this->assertNotContains('assetsDocuments', array_column($assets['settingsItems'], 'page'));
        // Staré nastavení se nikde nečte (#110 D21 — bez převzetí hodnoty).
        $this->assertStringNotContainsString('economy.assets.trackExpenses', (string) file_get_contents($path));
    }

    public function testSettingsPageHasOneFieldPerDimensionInOrder(): void
    {
        $module = self::accountingModule();
        $pages = array_column($module['settingsPages'], null, 'id');
        $this->assertArrayHasKey('accountingDimensions', $pages);
        $this->assertContains(['page' => 'accountingDimensions', 'section' => 'accounting'], $module['settingsItems']);

        $dimensions = self::dimensions();
        $this->assertSame(
            array_map(static fn(JournalDimension $d): string => self::SETTING_PREFIX . $d->id, $dimensions),
            array_column($pages['accountingDimensions']['fields'], 'id'),
            'Pořadí polí = pořadí dimenzí',
        );
        foreach ($pages['accountingDimensions']['fields'] as $field) {
            $this->assertSame('select', $field['type']);
            $this->assertSame(['yes', 'no'], array_column($field['options'], 'value'));
        }
        foreach ($dimensions as $dimension) {
            $this->assertSame(self::SETTING_PREFIX . $dimension->id, $dimension->enabledBySetting, $dimension->id);
        }
    }

    public function testCostCentersCodebookIsAnAccountingSettingsItem(): void
    {
        $codebooks = JsoncParser::parseFile(self::MODULES . '/economy/codebooks/module.jsonc');
        foreach ($codebooks['settingsItems'] as $item) {
            if (($item['viewer'] ?? null) === 'economy.codebooks.costCenters') {
                $this->assertSame('accounting', $item['section'], 'Středisko je účetní dimenze');
                return;
            }
        }
        $this->fail('Číselník středisek není v Nastavení');
    }

    public function testAssetInheritsFromHeadAndOffersFormFields(): void
    {
        $dimension = self::dimension('asset');

        $this->assertSame('asset', $dimension->headColumn);
        foreach (['invni', 'invno', 'cash', 'cmnbkp'] as $docType) {
            $this->assertTrue($dimension->isOnForm($docType, true), "{$docType}: hlavička");
            $this->assertTrue($dimension->isOnForm($docType, false), "{$docType}: řádek");
        }
        // Zálohová faktura se neúčtuje do nákladů — pole nemá.
        $this->assertFalse($dimension->isOnForm('invpo', true));
        $this->assertFalse($dimension->isOnForm('invpo', false));
    }

    public function testAcquisitionRowDoesNotInheritHeadCard(): void
    {
        $dimension = self::dimension('asset');
        $operations = JsoncParser::parseFile(self::MODULES . '/docs/core/config/rowOperations.jsonc');

        $this->assertSame('rowAsset', $dimension->rowFlag);
        $this->assertNull($dimension->valueOf([], ['asset' => 8], $operations['purchase.asset']));
        $this->assertSame(8, $dimension->valueOf([], ['asset' => 8], $operations['purchase.goods']));
    }
}

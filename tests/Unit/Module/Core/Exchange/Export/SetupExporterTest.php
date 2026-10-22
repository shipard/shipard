<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Export;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Module\ModuleLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Core\Exchange\Export\SetupExporter;

class SetupExporterTest extends TestCase
{
    private function tableDef(string $relPath): TableDefinition
    {
        $path = dirname(__DIR__, 6) . '/modules/' . $relPath;
        return TableDefinition::fromArray(JsoncParser::parseFile($path));
    }

    public function testCostCentersAreCarriedByCode(): void
    {
        // #110 T2: doklady v sadě odkazují na střediska kódem (objekt dimensions).
        $this->assertSame(
            ['table' => 'economy_codebooks_cost_centers', 'key' => ['code']],
            SetupExporter::TABLES['cost_centers'],
        );
        $schema = JsoncParser::parseFile(dirname(__DIR__, 6) . '/modules/core/exchange/schemas/shpd.dataset.setup.v1.jsonc');
        $this->assertSame(array_keys(SetupExporter::TABLES), $schema['properties']['table']['enum']);
    }

    public function testAccountsCarryOnlyNonSystemRows(): void
    {
        // #131 fáze 2a: analytiky založené nad seedem (221xxx pro účet v cizí
        // měně); systémové účty obnoví provisioner, sada je nenese.
        $this->assertSame(
            ['table' => 'economy_accounting_accounts', 'key' => ['number'], 'where' => ['is_system' => 0]],
            SetupExporter::TABLES['accounts'],
        );

        $def = $this->tableDef('economy/accounting/tables/economy_accounting_accounts.jsonc');
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())->method('fetchAll')->willReturnCallback(function (string $sql): array {
            $this->assertStringContainsString(
                'FROM [economy_accounting_accounts] WHERE [docState] <> 90 AND [is_system] = 0 ORDER BY [number], [id]',
                $sql,
            );
            return [new Row([
                'id' => 310, 'number' => '221200', 'name' => 'Bankovní účet EUR', 'short_name' => 'BÚ EUR',
                'account_level' => 4, 'g1' => '2', 'g2' => '22', 'g3' => '221', 'account_kind' => 0,
                'costs_type' => null, 'results_type' => null, 'valid_from' => null, 'valid_to' => null,
                'is_system' => 0, 'note' => null, 'docState' => 40, 'docStateMain' => 3,
            ])];
        });

        $records = (new SetupExporter($db, ['economy_accounting_accounts' => $def]))->exportAll();

        $this->assertCount(1, $records);
        $this->assertSame('accounts', $records[0]->slug);
        $row = $records[0]->data['rows'][0];
        $this->assertArrayNotHasKey('id', $row);
        $this->assertSame('221200', $row['number']);
        $this->assertSame(4, $row['account_level']);
        $this->assertSame('221', $row['g3']);
        $this->assertFalse($row['is_system']);
        $this->assertSame(40, $row['docState']);
        $this->assertSame([], (new SetupExporter($db, []))->getWarnings());
    }

    public function testExportsOnlyActiveTablesWithTypedRowsAndNaturalOrder(): void
    {
        $tables = [
            'economy_codebooks_bank_accounts' => $this->tableDef('economy/codebooks/tables/economy_codebooks_bank_accounts.jsonc'),
            'base_registry_binders'           => $this->tableDef('base/registry/tables/base_registry_binders.jsonc'),
        ];

        $db = $this->createMock(Connection::class);
        $db->expects($this->exactly(2))->method('fetchAll')->willReturnCallback(function (string $sql): array {
            if (str_contains($sql, '[economy_codebooks_bank_accounts]')) {
                $this->assertStringContainsString('WHERE [docState] <> 90 ORDER BY [code], [id]', $sql);
                return [new Row([
                    'id' => 4, 'code' => 'MAIN', 'name' => 'Hlavní účet', 'notice' => null, 'bank_name' => 'KB',
                    'account_number' => '123456789/0100', 'iban' => 'CZ65…', 'bic' => 'KOMBCZPP', 'currency' => 'czk',
                    'is_default' => 1, 'valid_from' => new \DateTimeImmutable('2024-01-01'), 'valid_to' => null,
                    'sort_order' => '10', 'docState' => 40, 'docStateMain' => 2,
                ])];
            }
            if (str_contains($sql, '[base_registry_binders]')) {
                $this->assertStringContainsString('ORDER BY [name], [id]', $sql);
                return [new Row([
                    'id' => 2, 'name' => 'Smlouvy', 'icon' => 'iconFolder', 'order_pos' => 1, 'notice' => '',
                    'docState' => 40, 'docStateMain' => 2, 'created' => new \DateTimeImmutable('2026-01-01 10:00:00'),
                ])];
            }
            $this->fail("unexpected query: {$sql}");
        });

        $records = (new SetupExporter($db, $tables))->exportAll();

        $this->assertCount(2, $records, 'vat_registrations and mailboxes are not active → skipped');
        $this->assertSame(['bank_accounts', 'binders'], array_map(static fn($r) => $r->slug, $records));

        $bank = $records[0]->data;
        $this->assertSame(SetupExporter::FORMAT, $bank['format']);
        $this->assertSame('bank_accounts', $bank['table']);
        $row = $bank['rows'][0];
        $this->assertArrayNotHasKey('id', $row);
        $this->assertArrayNotHasKey('docStateMain', $row);
        $this->assertSame('MAIN', $row['code']);
        $this->assertTrue($row['is_default'], 'boolean column typed as bool');
        $this->assertSame(10, $row['sort_order'], 'int column typed as int even when MySQL returns a string');
        $this->assertSame('2024-01-01', $row['valid_from']);
        $this->assertNull($row['valid_to']);
        $this->assertSame(40, $row['docState']);

        $binder = $records[1]->data['rows'][0];
        $this->assertArrayNotHasKey('created', $binder, 'audit columns are skipped');
        $this->assertSame('Smlouvy', $binder['name']);
        $this->assertSame(1, $binder['order_pos']);
    }

    public function testReferenceColumnsAreMappedToAccountNumberOrSkipped(): void
    {
        $def = TableDefinition::fromArray([
            'tableId' => 9999,
            'name'    => 'Synthetic',
            'columns' => [
                ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'primaryKey' => true, 'autoIncrement' => true],
                ['id' => 'code', 'name' => 'Code', 'type' => 'varchar', 'length' => 20],
                ['id' => 'accounting_account', 'name' => 'Account', 'type' => 'int', 'nullable' => true, 'reference' => 'economy_accounting_accounts'],
                ['id' => 'owner', 'name' => 'Owner', 'type' => 'int', 'nullable' => true, 'reference' => 'base_persons_persons'],
            ],
        ]);

        $db = $this->createMock(Connection::class);
        $db->method('fetchAll')->willReturn([new Row(['id' => 1, 'code' => 'A', 'accounting_account' => 9, 'owner' => 5])]);
        $db->method('fetch')->willReturnCallback(function (string $sql, int $id): ?Row {
            $this->assertStringContainsString('[economy_accounting_accounts]', $sql);
            return $id === 9 ? new Row(['number' => '221100']) : null;
        });

        $exporter = new SetupExporter($db, ['economy_codebooks_bank_accounts' => $def]);
        $records = $exporter->exportAll();

        $this->assertSame(['code' => 'A', 'accounting_account' => '221100'], $records[0]->data['rows'][0]);
        $this->assertCount(1, $exporter->getWarnings());
        $this->assertStringContainsString("'owner' (FK na base_persons_persons)", $exporter->getWarnings()[0]);
    }

    public function testWorkOrderKindsAndSeriesCarryReferencesByNaturalKey(): void
    {
        // #131 fáze 2b (D29, D37): druh nese řadu dokladů jako {docType, code}
        // a účet kódem, řada zakázek nese druh názvem; řada bez kódu se nepřenese.
        $tables = [
            'economy_work_orders_kinds'         => $this->tableDef('economy/workOrders/tables/economy_work_orders_kinds.jsonc'),
            'economy_work_orders_number_series' => $this->tableDef('economy/workOrders/tables/economy_work_orders_number_series.jsonc'),
        ];
        $db = $this->createMock(Connection::class);
        $db->method('fetchAll')->willReturnCallback(function (string $sql): array {
            if (str_contains($sql, '[economy_work_orders_kinds]')) {
                $this->assertStringContainsString('WHERE [docState] <> 90 ORDER BY [name], [id]', $sql);
                return [new Row([
                    'id' => 3, 'name' => 'Správa IT — paušál', 'type' => 'periodic', 'notice' => null,
                    'inv_doc_type' => 'invno', 'inv_number_series' => 7, 'inv_due_days' => 14, 'inv_timing' => 'start',
                    'inv_vat_mode' => 1, 'inv_payment_method' => 1, 'inv_bank_account' => 4,
                    'docState' => 40, 'docStateMain' => 3,
                ])];
            }
            if (str_contains($sql, '[economy_work_orders_number_series]')) {
                $this->assertStringContainsString('ORDER BY [number_code], [id]', $sql);
                return [
                    new Row([
                        'id' => 11, 'kind' => 3, 'name' => 'Bez kódu', 'notice' => null, 'number_code' => null,
                        'number_pattern' => '%4', 'reset_scope' => 'fiscal_year', 'valid_from' => null, 'valid_to' => null,
                        'docState' => 40, 'docStateMain' => 3,
                    ]),
                    new Row([
                        'id' => 12, 'kind' => 3, 'name' => 'Paušály', 'notice' => null, 'number_code' => 'S',
                        'number_pattern' => '%C%4', 'reset_scope' => 'none', 'valid_from' => null, 'valid_to' => null,
                        'docState' => 40, 'docStateMain' => 3,
                    ]),
                ];
            }
            $this->fail("unexpected query: {$sql}");
        });
        $db->method('fetch')->willReturnCallback(static function (string $sql, int $id): ?Row {
            if (str_contains($sql, '[docs_core_number_series]')) {
                return $id === 7 ? new Row(['doc_type' => 'invno', 'doc_number_code' => null]) : null;
            }
            if (str_contains($sql, '[economy_codebooks_bank_accounts]')) {
                return $id === 4 ? new Row(['code' => 'BU1']) : null;
            }
            if (str_contains($sql, '[economy_work_orders_kinds]')) {
                return $id === 3 ? new Row(['name' => 'Správa IT — paušál']) : null;
            }
            return null;
        });

        $exporter = new SetupExporter($db, $tables);
        $records = $exporter->exportAll();

        $this->assertSame(['work_order_kinds', 'work_order_series'], array_map(static fn($r) => $r->slug, $records));
        $kind = $records[0]->data['rows'][0];
        $this->assertSame(['docType' => 'invno'], $kind['inv_number_series'], 'řada dokladů bez kódu (D37)');
        $this->assertSame('BU1', $kind['inv_bank_account']);
        $this->assertSame(14, $kind['inv_due_days']);
        $this->assertSame(40, $kind['docState']);

        $series = $records[1]->data['rows'];
        $this->assertCount(1, $series, 'řada bez number_code se nepřenáší');
        $this->assertSame('S', $series[0]['number_code']);
        $this->assertSame('Správa IT — paušál', $series[0]['kind'], 'druh názvem');
        $this->assertSame('none', $series[0]['reset_scope']);
        $this->assertSame(1, count($exporter->getWarnings()));
        $this->assertStringContainsString("záznam #11 nemá klíč 'number_code'", $exporter->getWarnings()[0]);
    }

    public function testAssetCodebooksCarryReferencesByNaturalKey(): void
    {
        // #131 fáze 2c (D39): účetní skupina nese účty osnovy číslem, typ
        // majetku skupinu typů názvem a výchozí účetní skupinu kódem.
        $tables = [
            'economy_assets_accounting_groups' => $this->tableDef('economy/assets/tables/economy_assets_accounting_groups.jsonc'),
            'economy_assets_type_groups'       => $this->tableDef('economy/assets/tables/economy_assets_type_groups.jsonc'),
            'economy_assets_types'             => $this->tableDef('economy/assets/tables/economy_assets_types.jsonc'),
        ];
        $db = $this->createMock(Connection::class);
        $db->method('fetchAll')->willReturnCallback(function (string $sql): array {
            if (str_contains($sql, '[economy_assets_accounting_groups]')) {
                $this->assertStringContainsString('WHERE [docState] <> 90 ORDER BY [code], [id]', $sql);
                return [
                    new Row([
                        'id' => 2, 'code' => '022', 'name' => 'Samostatné movité věci', 'note' => null, 'sort_order' => 20,
                        'account_asset' => 101, 'account_acquisition' => 102, 'account_accumulated' => 103,
                        'account_depreciation' => 104, 'account_disposal' => 105, 'docState' => 40, 'docStateMain' => 3,
                    ]),
                    new Row([
                        'id' => 6, 'code' => '031', 'name' => 'Pozemky', 'note' => null, 'sort_order' => 60,
                        'account_asset' => 106, 'account_acquisition' => 102, 'account_accumulated' => null,
                        'account_depreciation' => null, 'account_disposal' => 105, 'docState' => 40, 'docStateMain' => 3,
                    ]),
                ];
            }
            if (str_contains($sql, '[economy_assets_type_groups]')) {
                $this->assertStringContainsString('WHERE [docState] <> 90 ORDER BY [name], [id]', $sql);
                return [new Row(['id' => 1, 'name' => 'Hmotný majetek', 'note' => null, 'sort_order' => 10, 'docState' => 40, 'docStateMain' => 3])];
            }
            if (str_contains($sql, '[economy_assets_types]')) {
                $this->assertStringContainsString('WHERE [docState] <> 90 ORDER BY [name], [id]', $sql);
                return [new Row([
                    'id' => 5, 'name' => 'Výpočetní technika', 'short_name' => null, 'type_group' => 1, 'note' => null,
                    'sort_order' => 10, 'default_category' => 'tangible', 'default_accounting_group' => 2,
                    'docState' => 40, 'docStateMain' => 3,
                ])];
            }
            $this->fail("unexpected query: {$sql}");
        });
        $db->method('fetch')->willReturnCallback(static function (string $sql, int $id): ?Row {
            if (str_contains($sql, '[economy_accounting_accounts]')) {
                $numbers = [101 => '022100', 102 => '042100', 103 => '082100', 104 => '551100', 105 => '541100', 106 => '031100'];
                return isset($numbers[$id]) ? new Row(['number' => $numbers[$id]]) : null;
            }
            if (str_contains($sql, '[economy_assets_type_groups]')) {
                return $id === 1 ? new Row(['name' => 'Hmotný majetek']) : null;
            }
            if (str_contains($sql, '[economy_assets_accounting_groups]')) {
                return $id === 2 ? new Row(['code' => '022']) : null;
            }
            return null;
        });

        $exporter = new SetupExporter($db, $tables);
        $records = $exporter->exportAll();

        $this->assertSame(
            ['asset_accounting_groups', 'asset_type_groups', 'asset_types'],
            array_map(static fn($r) => $r->slug, $records),
            'pořadí souborů podle SetupExporter::TABLES',
        );
        [$movable, $land] = $records[0]->data['rows'];
        $this->assertSame('022', $movable['code']);
        $this->assertSame(
            ['022100', '042100', '082100', '551100', '541100'],
            [$movable['account_asset'], $movable['account_acquisition'], $movable['account_accumulated'], $movable['account_depreciation'], $movable['account_disposal']],
            'účty osnovy číslem',
        );
        $this->assertSame(20, $movable['sort_order']);
        $this->assertSame(40, $movable['docState']);
        $this->assertNull($land['account_accumulated'], 'pozemky bez oprávek a odpisů zůstávají null');
        $this->assertNull($land['account_depreciation']);

        $this->assertSame('Hmotný majetek', $records[1]->data['rows'][0]['name']);

        $type = $records[2]->data['rows'][0];
        $this->assertSame('Výpočetní technika', $type['name']);
        $this->assertSame('Hmotný majetek', $type['type_group'], 'skupina typů názvem');
        $this->assertSame('022', $type['default_accounting_group'], 'výchozí účetní skupina kódem');
        $this->assertSame('tangible', $type['default_category']);
        $this->assertSame([], $exporter->getWarnings());
    }

    public function testSettingsExportKeepsOnlyPortableKeys(): void
    {
        $def = $this->tableDef('core/system/tables/core_system_settings.jsonc');
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())->method('fetchAll')->willReturnCallback(function (string $sql): array {
            $this->assertStringContainsString('[core_system_settings]', $sql);
            return [
                new Row(['key' => 'app.companyLogo', 'value' => '"logo.png"']),
                new Row(['key' => 'app.icon', 'value' => '{"storedAs":"icon.svg"}']),
                new Row(['key' => 'app.name', 'value' => '"Demo firma"']),
                new Row(['key' => 'economy.accountChart', 'value' => '"default"']),
                // Id řad dokladů (D42): na jiném zdroji dat neplatí, nastaví je provisionery.
                new Row(['key' => 'economy.assets.accountingSeries', 'value' => '"6"']),
                new Row(['key' => 'economy.fiscalYearStartMonth', 'value' => '1']),
                new Row(['key' => 'economy.vat.filingAccountingSeries', 'value' => '"5"']),
                new Row(['key' => 'economy.vatAgenda', 'value' => 'true']),
                new Row(['key' => 'test.jedna.b', 'value' => '"x"']),
            ];
        });

        $records = (new SetupExporter($db, ['core_system_settings' => $def]))->exportAll();

        $this->assertCount(1, $records);
        $this->assertSame('settings', $records[0]->data['table']);
        $this->assertSame([
            ['key' => 'app.name', 'value' => 'Demo firma'],
            ['key' => 'economy.accountChart', 'value' => 'default'],
            ['key' => 'economy.fiscalYearStartMonth', 'value' => 1],
            ['key' => 'economy.vatAgenda', 'value' => true],
        ], $records[0]->data['rows'], 'id řad dokladů se nepřenáší (D42)');
        $this->assertFalse(SetupExporter::isPortableSetting('economy.assets.accountingSeries'));
    }

    public function testExcludedSettingsMatchTheClearSettingsOnResetDeclarationsOfModules(): void
    {
        // Jedno místo pravdy = deklarace `clearSettingsOnReset` v module.jsonc
        // (ds-reset je maže s dropnutou tabulkou, #131 D42); exportér moduly
        // v ruce nemá, takže nese kopii — tenhle test ji hlídá.
        $declared = [];
        foreach (ModuleLoader::loadAllModules(new ModulePathResolver([dirname(__DIR__, 6) . '/modules'])) as $module) {
            foreach ($module->clearSettingsOnReset as $item) {
                $declared[$item['key']] = true;
            }
        }
        $declared = array_keys($declared);
        sort($declared);
        $excluded = SetupExporter::SETTINGS_EXCLUDED;
        sort($excluded);

        $this->assertSame($declared, $excluded, 'SetupExporter::SETTINGS_EXCLUDED musí odpovídat clearSettingsOnReset modulů');
    }

    public function testNoActiveTablesYieldsNothing(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('fetchAll');

        $this->assertSame([], (new SetupExporter($db, []))->exportAll());
    }
}

<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Export;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\TableDefinition;
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
                new Row(['key' => 'economy.fiscalYearStartMonth', 'value' => '1']),
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
        ], $records[0]->data['rows']);
    }

    public function testNoActiveTablesYieldsNothing(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('fetchAll');

        $this->assertSame([], (new SetupExporter($db, []))->exportAll());
    }
}

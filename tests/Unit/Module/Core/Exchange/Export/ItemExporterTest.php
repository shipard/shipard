<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Export;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Export\ItemExporter;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;

class ItemExporterTest extends TestCase
{
    /** @return array<string, mixed> */
    private function itemRow(): array
    {
        return [
            'id' => 11, 'code' => 'K-001', 'name' => 'Konzultace IT', 'sku' => null, 'ean' => '8590000000012',
            'item_kind' => 5, 'item_type' => 0, 'content_tags' => '["it.software","services.accounting"]',
            'description' => 'Hodinová sazba', 'valid_from' => '2024-01-01', 'valid_to' => null,
            'sales_price_no_vat' => '1500.0000', 'unit' => 3,
            'source_kind' => null, 'source_ref' => null, 'source_imported_at' => null,
            'docState' => 40, 'docStateMain' => 2,
            'kind_code' => 'service', 'kind_name' => 'Služba', 'kind_item_type' => 0,
            'unit_code' => 'hour', 'unit_shortcut' => 'h', 'unit_name' => 'hodina',
            'account_number' => '518100',
        ];
    }

    private function db(array $supplierCodes = [], bool $hasAccounting = true): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturnCallback(function (string $sql) use ($hasAccounting): ?Row {
            if (str_contains($sql, 'SHOW COLUMNS')) {
                return $hasAccounting ? new Row(['Field' => 'accounting_account']) : null;
            }
            return null;
        });
        $db->method('fetchAll')->willReturnCallback(function (string $sql) use ($supplierCodes): array {
            if (str_contains($sql, '[economy_items_supplier_codes]')) {
                return array_map(static fn(array $r) => new Row($r), $supplierCodes);
            }
            return [];
        });
        return $db;
    }

    public function testItemMapsToCanonicalAndValidates(): void
    {
        $db = $this->db(supplierCodes: [[
            'supplier_code' => 'SUP-77', 'supplier_name' => 'IT consulting',
            'full_name' => 'Dodavatel a.s.', 'company_id' => '87654321', 'tax_id' => null, 'vat_id' => 'CZ87654321', 'gov_e_box_id' => null,
        ]]);

        $record = (new ItemExporter($db))->exportItem($this->itemRow());
        $c = $record->data;

        $this->assertSame(11, $record->id);
        $this->assertSame('K-001 Konzultace IT', $record->slug);
        $this->assertSame('shpd.items.item', $c['format']);
        $this->assertSame('K-001', $c['code']);
        $this->assertSame('Konzultace IT', $c['name']);
        $this->assertSame('8590000000012', $c['ean']);
        $this->assertArrayNotHasKey('sku', $c);
        $this->assertSame(['code' => 'service', 'name' => 'Služba', 'itemType' => 0], $c['kind']);
        $this->assertSame('2024-01-01', $c['validFrom']);
        $this->assertSame(1500.0, $c['salesPriceNoVat']);
        $this->assertSame('hour', $c['unit'], 'unit system_code wins over shortcut');
        $this->assertSame('518100', $c['accountingAccount']);
        $this->assertSame(['it.software', 'services.accounting'], $c['contentTags']);
        $this->assertSame([[
            'supplier'     => ['name' => 'Dodavatel a.s.', 'companyId' => '87654321', 'vatId' => 'CZ87654321'],
            'supplierCode' => 'SUP-77',
            'supplierName' => 'IT consulting',
        ]], $c['supplierCodes']);
        $this->assertSame(['docState' => 40], $c['status']);
        $this->assertSame(
            ['mergeStrategy' => 'createOnly', 'matchStrategy' => 'identifiersOnly', 'targetDocState' => 40],
            $c['applyOptions'],
        );

        $issues = (new SchemaValidator(SchemaLoader::default()))->validate($c, 'shpd.items.item', '1');
        $this->assertSame([], $issues, 'exported canonical must validate against the items schema');
    }

    public function testUnitFallsBackToShortcutAndPcs(): void
    {
        $row = $this->itemRow();
        $row['unit_code'] = null;
        $this->assertSame('h', (new ItemExporter($this->db()))->exportItem($row)->data['unit']);

        $row['unit_shortcut'] = null;
        $row['unit_name'] = null;
        $this->assertSame('pcs', (new ItemExporter($this->db()))->exportItem($row)->data['unit']);
    }

    public function testEmptyOrInvalidContentTagsArePruned(): void
    {
        $row = $this->itemRow();
        $row['content_tags'] = '[]';
        $this->assertArrayNotHasKey('contentTags', (new ItemExporter($this->db()))->exportItem($row)->data);

        $row['content_tags'] = 'not json';
        $this->assertArrayNotHasKey('contentTags', (new ItemExporter($this->db()))->exportItem($row)->data);
    }

    public function testExportAllOmitsAccountingJoinWhenExtensionMissing(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(null); // SHOW COLUMNS → no accounting_account column
        $db->expects($this->atLeastOnce())->method('fetchAll')->willReturnCallback(function (string $sql) {
            if (str_contains($sql, 'FROM [economy_items] AS [i]')) {
                $this->assertStringNotContainsString('economy_accounting_accounts', $sql);
                $this->assertStringContainsString('NULL AS [account_number]', $sql);
                $this->assertStringContainsString('ORDER BY [i.code], [i.name], [i.id]', $sql);
                $row = $this->itemRow();
                $row['account_number'] = null;
                return [new Row($row)];
            }
            return [];
        });

        $records = (new ItemExporter($db))->exportAll();
        $this->assertCount(1, $records);
        $this->assertArrayNotHasKey('accountingAccount', $records[0]->data);
    }

    public function testExportAllJoinsAccountsWhenExtensionPresent(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(new Row(['Field' => 'accounting_account']));
        $db->method('fetchAll')->willReturnCallback(function (string $sql) {
            if (str_contains($sql, 'FROM [economy_items] AS [i]')) {
                $this->assertStringContainsString('LEFT JOIN [economy_accounting_accounts]', $sql);
                return [];
            }
            return [];
        });

        $this->assertSame([], (new ItemExporter($db))->exportAll());
    }

    public function testExportAllLeavesOutExcludedIds(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(new Row(['Field' => 'accounting_account']));
        $db->expects($this->atLeastOnce())->method('fetchAll')->willReturnCallback(function (string $sql, mixed ...$args) {
            if (str_contains($sql, 'FROM [economy_items] AS [i]')) {
                $this->assertStringContainsString(
                    ' WHERE [i.docState] <> 90 AND [i.id] NOT IN %in ORDER BY [i.code], [i.name], [i.id]',
                    $sql,
                );
                $this->assertSame([[11, 12]], $args, 'excluded ids are the only modifier argument');
                $row = $this->itemRow();
                $row['id'] = 13;
                return [new Row($row)];
            }
            return [];
        });

        $records = (new ItemExporter($db, [11, 12]))->exportAll();
        $this->assertSame([13], array_map(static fn($r) => $r->id, $records));
    }

    public function testWithoutExclusionsExportAllHasNoNotIn(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(new Row(['Field' => 'accounting_account']));
        $db->expects($this->atLeastOnce())->method('fetchAll')->willReturnCallback(function (string $sql, mixed ...$args) {
            if (str_contains($sql, 'FROM [economy_items] AS [i]')) {
                $this->assertStringNotContainsString('NOT IN', $sql);
                $this->assertSame([], $args);
            }
            return [];
        });

        $this->assertSame([], (new ItemExporter($db, []))->exportAll());
    }

    public function testExportByIdsIgnoresExclusions(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(new Row(['Field' => 'accounting_account']));
        $db->expects($this->atLeastOnce())->method('fetchAll')->willReturnCallback(function (string $sql, mixed ...$args) {
            if (str_contains($sql, 'FROM [economy_items] AS [i]')) {
                $this->assertStringContainsString(' WHERE [i.id] IN %in AND [i.docState] <> 90 ', $sql);
                $this->assertStringNotContainsString('NOT IN', $sql);
                $this->assertSame([[11]], $args);
                return [new Row($this->itemRow())];
            }
            return [];
        });

        $records = (new ItemExporter($db, [11]))->exportByIds([11]);
        $this->assertSame([11], array_map(static fn($r) => $r->id, $records), 'explicit ids win over the exclusion list');
    }
}

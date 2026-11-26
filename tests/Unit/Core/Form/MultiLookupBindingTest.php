<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Form;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Form\FormElement;
use Shipard\Core\Form\MultiLookupBinding;

/**
 * Vazba prvku `multilookup` na child tabulku (#176 D1–D3, D9): odvození
 * odkazového sloupce a pořadí z definic tabulek, chyby deklarace a převod
 * seznam id ↔ řádky child tabulky.
 */
class MultiLookupBindingTest extends TestCase
{
    private const PARENT = 'contracts';
    private const CHILD = 'contracts_places';
    private const TARGET = 'places';

    /** @param list<array<string, mixed>> $columns */
    private static function table(int $tableId, string $name, array $columns, array $childTables = []): TableDefinition
    {
        return TableDefinition::fromArray([
            'tableId' => $tableId,
            'name' => $name,
            'childTables' => $childTables,
            'columns' => [
                ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true],
                ...$columns,
            ],
        ]);
    }

    private static function parentDef(string $dataKey = 'places', string $foreignKey = 'contract'): TableDefinition
    {
        return self::table(1, self::PARENT, [
            ['id' => 'name', 'name' => 'Name', 'type' => 'varchar', 'length' => 50],
        ], [
            ['table' => self::CHILD, 'foreignKey' => $foreignKey, 'dataKey' => $dataKey],
        ]);
    }

    /** @param list<array<string, mixed>> $extraColumns */
    private static function childDef(array $extraColumns = [], bool $withOrder = true): TableDefinition
    {
        $columns = [
            ['id' => 'contract', 'name' => 'Contract', 'type' => 'int', 'reference' => self::PARENT],
            ['id' => 'place', 'name' => 'Place', 'type' => 'int', 'reference' => self::TARGET],
        ];
        if ($withOrder) {
            $columns[] = ['id' => 'order_pos', 'name' => 'Order', 'type' => 'smallint', 'default' => 0];
        }
        return self::table(2, self::CHILD, [...$columns, ...$extraColumns]);
    }

    private static function element(?string $refColumn = null, string $column = 'places', string $table = self::TARGET): FormElement
    {
        $lookup = ['table' => $table, 'filter' => null];
        if ($refColumn !== null) {
            $lookup['ref_column'] = $refColumn;
        }
        return new FormElement(type: 'multilookup', column: $column, lookup: $lookup);
    }

    private static function binding(?TableDefinition $childDef = null, ?string $refColumn = null): MultiLookupBinding
    {
        return MultiLookupBinding::forElement(
            self::element($refColumn),
            self::PARENT,
            self::parentDef(),
            [self::CHILD => $childDef ?? self::childDef()],
        );
    }

    // ── Odvození vazby ──────────────────────────────────────────────────────

    public function testDerivesChildTableReferenceAndOrderColumn(): void
    {
        $binding = self::binding();

        $this->assertSame('places', $binding->column);
        $this->assertSame(self::TARGET, $binding->lookupTable);
        $this->assertSame(self::CHILD, $binding->childTable);
        $this->assertSame('contract', $binding->foreignKey);
        $this->assertSame('place', $binding->refColumn);
        $this->assertSame('order_pos', $binding->orderColumn);
    }

    public function testChildTableWithoutOrderColumnHasNoOrder(): void
    {
        $this->assertNull(self::binding(self::childDef(withOrder: false))->orderColumn);
    }

    /** Rodič odkazuje sám na sebe: cizí klíč se mezi kandidáty nepočítá. */
    public function testSelfReferenceSkipsForeignKey(): void
    {
        $parent = self::table(1, 'places', [], [
            ['table' => 'places_links', 'foreignKey' => 'place', 'dataKey' => 'related'],
        ]);
        $child = self::table(2, 'places_links', [
            ['id' => 'place', 'name' => 'Place', 'type' => 'int', 'reference' => 'places'],
            ['id' => 'related_place', 'name' => 'Related', 'type' => 'int', 'reference' => 'places'],
        ]);

        $binding = MultiLookupBinding::forElement(
            self::element(column: 'related', table: 'places'),
            'places',
            $parent,
            ['places_links' => $child],
        );

        $this->assertSame('related_place', $binding->refColumn);
    }

    public function testExplicitRefColumnWinsOverAmbiguity(): void
    {
        $child = self::childDef([
            ['id' => 'billing_place', 'name' => 'Billing', 'type' => 'int', 'nullable' => true, 'reference' => self::TARGET],
        ]);

        $this->assertSame('billing_place', self::binding($child, 'billing_place')->refColumn);
    }

    public function testExplicitRefColumnWithoutReferenceIsAccepted(): void
    {
        $child = self::table(2, self::CHILD, [
            ['id' => 'contract', 'name' => 'Contract', 'type' => 'int'],
            ['id' => 'place_id', 'name' => 'Place', 'type' => 'int'],
        ]);

        $this->assertSame('place_id', self::binding($child, 'place_id')->refColumn);
    }

    // ── Chyby deklarace ─────────────────────────────────────────────────────

    public function testMissingChildTableDeclaration(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            "multilookup 'rooms' on table 'contracts': table has no childTables entry with dataKey 'rooms'",
        );

        MultiLookupBinding::forElement(
            self::element(column: 'rooms'),
            self::PARENT,
            self::parentDef(),
            [self::CHILD => self::childDef()],
        );
    }

    public function testUndefinedChildTable(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("child table 'contracts_places' is not defined");

        MultiLookupBinding::forElement(self::element(), self::PARENT, self::parentDef(), []);
    }

    public function testNoReferenceCandidate(): void
    {
        $child = self::table(2, self::CHILD, [
            ['id' => 'contract', 'name' => 'Contract', 'type' => 'int', 'reference' => self::PARENT],
            ['id' => 'place', 'name' => 'Place', 'type' => 'int'],
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            "multilookup 'places' on table 'contracts': child table 'contracts_places' has no column referencing 'places' — declare refColumn",
        );

        self::binding($child);
    }

    public function testMoreReferenceCandidates(): void
    {
        $child = self::childDef([
            ['id' => 'billing_place', 'name' => 'Billing', 'type' => 'int', 'nullable' => true, 'reference' => self::TARGET],
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("has more columns referencing 'places' (place, billing_place) — declare refColumn");

        self::binding($child);
    }

    public function testRefColumnOutsideChildTable(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("refColumn 'room' is not a column of child table 'contracts_places'");

        self::binding(refColumn: 'room');
    }

    public function testRefColumnMustNotBeForeignKey(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("refColumn 'contract' is the foreign key of child table 'contracts_places'");

        self::binding(refColumn: 'contract');
    }

    public function testForeignKeyMustBeChildColumn(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("foreign key 'head' is not a column of child table 'contracts_places'");

        MultiLookupBinding::forElement(
            self::element(),
            self::PARENT,
            self::parentDef(foreignKey: 'head'),
            [self::CHILD => self::childDef()],
        );
    }

    public function testOtherElementTypeIsRejected(): void
    {
        $this->expectException(\LogicException::class);

        MultiLookupBinding::forElement(
            new FormElement(type: 'lookup', column: 'places', lookup: ['table' => self::TARGET]),
            self::PARENT,
            self::parentDef(),
            [self::CHILD => self::childDef()],
        );
    }

    // ── Hodnota z klienta ───────────────────────────────────────────────────

    /** @return array<string, array{mixed, list<int>|null}> */
    public static function idsProvider(): array
    {
        return [
            'prázdný seznam' => [[], []],
            'celá čísla' => [[12, 7], [12, 7]],
            'čísla jako text' => [['12', 7], [12, 7]],
            'duplicita zůstává' => [[7, 7], [7, 7]],
            'null' => [null, null],
            'číslo' => [5, null],
            'text' => ['12,7', null],
            'objekt' => [['a' => 1], null],
            'nula' => [[0], null],
            'záporné' => [[-3], null],
            'desetinné' => [[1.5], null],
            'text s nulou na začátku' => [['07'], null],
            'slovo' => [['abc'], null],
            'vnořené pole' => [[[1]], null],
            'bool' => [[true], null],
            'prvek null' => [[1, null], null],
        ];
    }

    #[DataProvider('idsProvider')]
    public function testParseIds(mixed $value, ?array $expected): void
    {
        $this->assertSame($expected, MultiLookupBinding::parseIds($value));
    }

    public function testFirstDuplicate(): void
    {
        $this->assertNull(MultiLookupBinding::firstDuplicate([]));
        $this->assertNull(MultiLookupBinding::firstDuplicate([3, 1, 2]));
        $this->assertSame(1, MultiLookupBinding::firstDuplicate([3, 1, 2, 1, 3]));
    }

    // ── Řádky → seznam ──────────────────────────────────────────────────────

    public function testIdsFromRowsFollowOrderThenRowId(): void
    {
        $ids = self::binding()->idsFromRows([
            ['id' => 30, 'contract' => 1, 'place' => 5, 'order_pos' => 2],
            ['id' => 10, 'contract' => 1, 'place' => 9, 'order_pos' => 2],
            ['id' => 20, 'contract' => 1, 'place' => 7, 'order_pos' => 1],
        ]);

        $this->assertSame([7, 9, 5], $ids);
    }

    public function testIdsFromRowsWithoutOrderColumnFollowRowId(): void
    {
        $ids = self::binding(self::childDef(withOrder: false))->idsFromRows([
            ['id' => 30, 'contract' => 1, 'place' => 5],
            ['id' => 10, 'contract' => 1, 'place' => 9],
        ]);

        $this->assertSame([9, 5], $ids);
    }

    public function testIdsFromRowsSkipEmptyAndRepeatedReferences(): void
    {
        $ids = self::binding()->idsFromRows([
            ['id' => 1, 'contract' => 1, 'place' => 5, 'order_pos' => 1],
            ['id' => 2, 'contract' => 1, 'place' => null, 'order_pos' => 2],
            ['id' => 3, 'contract' => 1, 'place' => 5, 'order_pos' => 3],
            ['id' => 4, 'contract' => 1, 'place' => '8', 'order_pos' => 4],
        ]);

        $this->assertSame([5, 8], $ids);
    }

    // ── Seznam → řádky ──────────────────────────────────────────────────────

    public function testToRowsForNewRecord(): void
    {
        $this->assertSame(
            [
                ['place' => 12, 'order_pos' => 1],
                ['place' => 7, 'order_pos' => 2],
            ],
            self::binding()->toRows([12, 7], []),
        );
    }

    public function testToRowsKeepsRowIdsReordersAndDropsMissing(): void
    {
        $existing = [
            ['id' => 100, 'contract' => 1, 'place' => 12, 'order_pos' => 1, 'note' => 'kept'],
            ['id' => 101, 'contract' => 1, 'place' => 7, 'order_pos' => 2, 'note' => 'kept'],
            ['id' => 102, 'contract' => 1, 'place' => 3, 'order_pos' => 3, 'note' => 'removed'],
        ];

        $rows = self::binding()->toRows([7, 9, 12], $existing);

        $this->assertSame(
            [
                ['id' => 101, 'place' => 7, 'order_pos' => 1],
                ['place' => 9, 'order_pos' => 2],
                ['id' => 100, 'place' => 12, 'order_pos' => 3],
            ],
            $rows,
            'stejný odkaz si nechá id, nový je bez id, chybějící vypadne; sloupec navíc se neposílá',
        );
    }

    public function testToRowsEmptyListRemovesEverything(): void
    {
        $existing = [['id' => 100, 'contract' => 1, 'place' => 12, 'order_pos' => 1]];

        $this->assertSame([], self::binding()->toRows([], $existing));
    }

    public function testToRowsWithoutOrderColumn(): void
    {
        $rows = self::binding(self::childDef(withOrder: false))->toRows([7, 9], [
            ['id' => 100, 'contract' => 1, 'place' => 9],
        ]);

        $this->assertSame([['place' => 7], ['id' => 100, 'place' => 9]], $rows);
    }

    /** Dva řádky se stejným odkazem (data mimo formulář): id si nechá první podle pořadí, druhý vypadne. */
    public function testToRowsWithRepeatedReferenceKeepsFirstRow(): void
    {
        $existing = [
            ['id' => 201, 'contract' => 1, 'place' => 5, 'order_pos' => 2],
            ['id' => 200, 'contract' => 1, 'place' => 5, 'order_pos' => 1],
        ];

        $this->assertSame([['id' => 200, 'place' => 5, 'order_pos' => 1]], self::binding()->toRows([5], $existing));
    }

    /** Řádek jiného rodiče ani id z klienta se do převodu nedostane — id bere jen z předaných řádků. */
    public function testToRowsNeverInventsRowIds(): void
    {
        $rows = self::binding()->toRows([5, 6], []);

        foreach ($rows as $row) {
            $this->assertArrayNotHasKey('id', $row);
        }
    }
}

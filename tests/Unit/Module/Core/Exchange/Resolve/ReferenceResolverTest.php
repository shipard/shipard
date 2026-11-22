<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Resolve;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\TableDefinition;
use Shipard\Module\Core\Exchange\Resolve\ReferenceException;
use Shipard\Module\Core\Exchange\Resolve\ReferenceResolver;

/**
 * Obecné hledání záznamu přirozeným klíčem (#166 D8–D10): přesně jeden
 * živý záznam, koš se nepočítá, sloupec jen z definice tabulky.
 */
class ReferenceResolverTest extends TestCase
{
    /**
     * @param array<string, mixed> $extra
     */
    private static function table(array $extra, bool $docStates = true): TableDefinition
    {
        return TableDefinition::fromArray($extra + [
            'tableId' => 1,
            'name'    => 'Test',
            'columns' => [
                ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'primaryKey' => true, 'autoIncrement' => true],
                ['id' => 'code', 'name' => 'Code', 'type' => 'varchar', 'length' => 20],
                ['id' => 'company_id', 'name' => 'Company ID', 'type' => 'varchar', 'length' => 30, 'nullable' => true],
                ['id' => 'name', 'name' => 'Name', 'type' => 'varchar', 'length' => 100],
            ],
        ] + ($docStates ? ['docStates' => ['cfgItem' => 'core.system.docStatesArchive']] : []));
    }

    /** @return array<string, TableDefinition> */
    private static function tables(): array
    {
        return [
            'test_persons'  => self::table(['exchangeKey' => 'code', 'exchangeLookupColumns' => ['company_id']]),
            'test_plain'    => self::table(['exchangeKey' => 'code'], docStates: false),
            'test_keyless'  => self::table([]),
        ];
    }

    /**
     * @param array<string, list<int>> $byValue hodnota (nebo id) → nalezená id
     */
    private function db(array $byValue, ?string $expectSql = null, ?int $calls = null): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->expects($calls === null ? $this->any() : $this->exactly($calls))
            ->method('fetchAll')
            ->willReturnCallback(function (string $sql, mixed $arg) use ($byValue, $expectSql): array {
                if ($expectSql !== null) {
                    $this->assertSame($expectSql, $sql);
                }
                return array_map(static fn(int $id): Row => new Row(['id' => $id]), $byValue[(string) $arg] ?? []);
            });
        return $db;
    }

    private function assertFails(string $code, callable $call): ReferenceException
    {
        try {
            $call();
        } catch (ReferenceException $e) {
            $this->assertSame($code, $e->errorCode, $e->getMessage());
            return $e;
        }
        $this->fail("očekávána chyba {$code}");
    }

    public function testResolveByKeyFindsExactlyOneLiveRecordAndRemembersIt(): void
    {
        $db = $this->db(
            ['K00042' => [7]],
            'SELECT [id] FROM [test_persons] WHERE [code] = %s AND [docState] <> 90 ORDER BY [id]',
            calls: 1,
        );
        $resolver = new ReferenceResolver($db, self::tables());

        $this->assertSame(7, $resolver->resolveByKey('test_persons', 'K00042'));
        $this->assertSame(7, $resolver->resolveByKey('test_persons', 'K00042'), 'druhé čtení z paměti');
        $this->assertSame(7, $resolver->resolve('test_persons', '@K00042'), 'tatáž hodnota gramatikou');
        $this->assertSame(7, $resolver->resolve('test_persons', '@code:K00042'), 'klíč jmenovitě');
    }

    public function testNotFoundIsNotRemembered(): void
    {
        // Záznam může během seedu teprve vzniknout — nenalezení se neukládá.
        $db = $this->db([], calls: 2);
        $resolver = new ReferenceResolver($db, self::tables());

        $e = $this->assertFails(ReferenceException::NOT_FOUND, fn() => $resolver->resolveByKey('test_persons', 'K1'));
        $this->assertSame(['test_persons', 'code', 'K1'], [$e->table, $e->column, $e->value]);
        $this->assertStringContainsString('„K1“', $e->getMessage());
        $this->assertFails(ReferenceException::NOT_FOUND, fn() => $resolver->resolve('test_persons', '@K1'));
    }

    public function testAmbiguousValueIsAnErrorWithCountAndIds(): void
    {
        $resolver = new ReferenceResolver($this->db(['Projekty' => [5, 6, 9]]), self::tables());

        $e = $this->assertFails(ReferenceException::AMBIGUOUS, fn() => $resolver->resolveByKey('test_persons', 'Projekty'));
        $this->assertSame([5, 6, 9], $e->ids);
        $this->assertStringContainsString('záznamů: 3 (#5, #6, #9)', $e->getMessage());
    }

    public function testTrashIsExcludedOnlyForTablesWithDocStates(): void
    {
        $withStates = new ReferenceResolver($this->db(
            ['A' => [1]],
            'SELECT [id] FROM [test_persons] WHERE [code] = %s AND [docState] <> 90 ORDER BY [id]',
        ), self::tables());
        $this->assertSame(1, $withStates->resolveByKey('test_persons', 'A'), 'archivovaný záznam se počítá, koš ne');

        $plain = new ReferenceResolver($this->db(
            ['A' => [2]],
            'SELECT [id] FROM [test_plain] WHERE [code] = %s ORDER BY [id]',
        ), self::tables());
        $this->assertSame(2, $plain->resolveByKey('test_plain', 'A'));
    }

    public function testPrimaryKeyMustBeALiveRecordToo(): void
    {
        $db = $this->db(['12' => [12]], 'SELECT [id] FROM [test_keyless] WHERE [id] = %i AND [docState] <> 90');
        $resolver = new ReferenceResolver($db, self::tables());

        $this->assertSame(12, $resolver->resolve('test_keyless', 12), 'id nepotřebuje přirozený klíč');
        $e = $this->assertFails(ReferenceException::NOT_FOUND, fn() => $resolver->resolve('test_keyless', 13));
        $this->assertNull($e->column);
        $this->assertStringContainsString('#13', $e->getMessage());
    }

    public function testNamedColumnMustBeDeclaredByTheTable(): void
    {
        $db = $this->db(
            ['12345678' => [4]],
            'SELECT [id] FROM [test_persons] WHERE [company_id] = %s AND [docState] <> 90 ORDER BY [id]',
        );
        $resolver = new ReferenceResolver($db, self::tables());

        $this->assertSame(4, $resolver->resolve('test_persons', '@company_id:12345678'));
        $this->assertSame(4, $resolver->resolveByColumn('test_persons', 'company_id', '12345678'));

        $e = $this->assertFails(
            ReferenceException::COLUMN_NOT_ALLOWED,
            fn() => $resolver->resolveByColumn('test_persons', 'name', 'Novák'),
        );
        $this->assertSame('name', $e->column);
        $this->assertFails(
            ReferenceException::COLUMN_NOT_ALLOWED,
            fn() => $resolver->resolveByColumn('test_persons', 'id; DROP TABLE x', '1'),
        );
    }

    public function testUndeclaredColumnBeforeColonIsPartOfTheKey(): void
    {
        $resolver = new ReferenceResolver($this->db(
            ['name:Novák' => [3]],
            'SELECT [id] FROM [test_persons] WHERE [code] = %s AND [docState] <> 90 ORDER BY [id]',
        ), self::tables());

        $this->assertSame(3, $resolver->resolve('test_persons', '@name:Novák'));
    }

    public function testTableWithoutKeyOrUnknownTable(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('fetchAll');
        $db->expects($this->never())->method('fetch');
        $resolver = new ReferenceResolver($db, self::tables());

        $this->assertTrue($resolver->hasKey('test_persons'));
        $this->assertSame('code', $resolver->keyColumn('test_persons'));
        $this->assertFalse($resolver->hasKey('test_keyless'));
        $this->assertFalse($resolver->hasKey('test_missing'), 'neaktivní tabulka klíč nemá');

        $this->assertFails(ReferenceException::NO_KEY, fn() => $resolver->resolveByKey('test_keyless', 'A'));
        $this->assertFails(ReferenceException::NO_KEY, fn() => $resolver->resolve('test_keyless', '@A'));
        $this->assertFails(ReferenceException::NO_KEY, fn() => $resolver->keyOf('test_keyless', 1));
        $this->assertFails(ReferenceException::TABLE_UNKNOWN, fn() => $resolver->resolve('test_missing', 1));
        $this->assertFails(ReferenceException::TABLE_UNKNOWN, fn() => $resolver->resolveByKey('test_missing', 'A'));
        $this->assertFails(ReferenceException::TABLE_UNKNOWN, fn() => $resolver->keyOf('test_missing', 1));
    }

    public function testInvalidValueCarriesTheTable(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('fetchAll');
        $resolver = new ReferenceResolver($db, self::tables());

        foreach (['42', 'K00042', '@', 0, 1.5, null, true, ['id' => 1]] as $value) {
            $e = $this->assertFails(ReferenceException::INVALID, fn() => $resolver->resolve('test_persons', $value));
            $this->assertSame('test_persons', $e->table);
        }
        $this->assertFails(ReferenceException::INVALID, fn() => $resolver->resolveByKey('test_persons', ''));
        $this->assertFails(ReferenceException::INVALID, fn() => $resolver->resolveByColumn('test_persons', 'company_id', ''));
    }

    public function testKeyOfReturnsTheNaturalKeyOfARecord(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->exactly(4))->method('fetch')->willReturnCallback(
            function (string $sql, int $id): ?Row {
                // Export koš nefiltruje — odkaz na smazaný záznam odmítne až hledání.
                $this->assertSame('SELECT [code] FROM [test_persons] WHERE [id] = %i', $sql);
                return match ($id) {
                    1 => new Row(['code' => 'K00042']),
                    2 => new Row(['code' => '']),
                    3 => new Row(['code' => null]),
                    default => null,
                };
            },
        );
        $resolver = new ReferenceResolver($db, self::tables());

        $this->assertSame('K00042', $resolver->keyOf('test_persons', 1));
        $this->assertSame('K00042', $resolver->keyOf('test_persons', 1), 'druhé čtení z paměti');
        $this->assertNull($resolver->keyOf('test_persons', 2), 'prázdný klíč');
        $this->assertNull($resolver->keyOf('test_persons', 3));
        $this->assertNull($resolver->keyOf('test_persons', 99), 'záznam není');
        $this->assertNull($resolver->keyOf('test_persons', 99));
    }
}

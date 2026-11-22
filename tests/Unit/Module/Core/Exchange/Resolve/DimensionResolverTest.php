<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Resolve;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Accounting\JournalDimension;
use Shipard\Core\Accounting\JournalDimensionSet;
use Shipard\Module\Core\Exchange\Resolve\DimensionResolver;
use Shipard\Tests\Fixtures\Core\Database\ModuleTables;

/**
 * Hodnota dimenze (#110 T2) → id záznamu přes přirozený klíč cílové
 * tabulky (`exchangeKey` v definici, #166 D8): koš se nepočítá, dimenze
 * bez klíče formát nezná, nenalezená i nejednoznačná hodnota je null.
 */
class DimensionResolverTest extends TestCase
{
    private function dimensions(): JournalDimensionSet
    {
        return new JournalDimensionSet([
            JournalDimension::fromArray([
                'id' => 'costCenter', 'rowColumn' => 'cost_center', 'headColumn' => 'cost_center',
                'journalColumn' => 'cost_center', 'table' => 'economy_codebooks_cost_centers',
                'name' => 'Středisko',
            ]),
            // Cílová tabulka bez přirozeného klíče.
            JournalDimension::fromArray([
                'id' => 'series', 'rowColumn' => 'series', 'journalColumn' => 'series',
                'table' => 'docs_core_number_series', 'name' => 'Řada',
            ]),
            // Cílová tabulka na zdroji dat není.
            JournalDimension::fromArray([
                'id' => 'project', 'rowColumn' => 'project', 'journalColumn' => 'project',
                'table' => 'x_projects', 'name' => 'Projekt',
            ]),
        ]);
    }

    /** @return array<string, \Shipard\Core\Database\TableDefinition> */
    private function tables(): array
    {
        return ModuleTables::load('economy_codebooks_cost_centers', 'docs_core_number_series');
    }

    public function testResolvesByNaturalKeyOfTheTargetTableExcludingTrash(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->exactly(2))->method('fetchAll')->willReturnCallback(
            static function (string $sql, string $value): array {
                self::assertSame(
                    'SELECT [id] FROM [economy_codebooks_cost_centers] WHERE [code] = %s AND [docState] <> 90 ORDER BY [id]',
                    $sql,
                );
                return $value === 'S01' ? [new Row(['id' => 3])] : [];
            },
        );
        $resolver = new DimensionResolver($db, $this->dimensions(), $this->tables());

        $this->assertSame(3, $resolver->resolve('costCenter', ' S01 '), 'hodnota dimenze se ořezává jako dosud');
        $this->assertSame(3, $resolver->resolve('costCenter', 'S01'), 'druhé čtení jde z paměti resolveru');
        $this->assertNull($resolver->resolve('costCenter', 'S99'));
        $this->assertSame('Středisko', $resolver->name('costCenter'));
    }

    public function testAmbiguousValueIsNotResolved(): void
    {
        // #166 D10: víc živých záznamů se stejným klíčem není „první nalezený“.
        $db = $this->createMock(Connection::class);
        $db->method('fetchAll')->willReturn([new Row(['id' => 3]), new Row(['id' => 9])]);

        $this->assertNull((new DimensionResolver($db, $this->dimensions(), $this->tables()))->resolve('costCenter', 'S01'));
    }

    public function testDimensionWithoutNaturalKeyOrUnknownIsNotResolved(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('fetchAll');
        $db->expects($this->never())->method('fetch');
        $resolver = new DimensionResolver($db, $this->dimensions(), $this->tables());

        $this->assertTrue($resolver->knows('costCenter'));
        $this->assertFalse($resolver->knows('series'), 'cílová tabulka nemá exchangeKey — formát dimenzi nenese');
        $this->assertFalse($resolver->knows('project'), 'cílová tabulka není aktivní');
        $this->assertFalse($resolver->knows('asset'), 'nedeklarovaná dimenze');
        $this->assertNull($resolver->resolve('series', 'S'));
        $this->assertNull($resolver->resolve('project', 'P1'));
        $this->assertNull($resolver->resolve('asset', 'MA0001'));
        $this->assertNull($resolver->resolve('costCenter', '  '));
        $this->assertSame('asset', $resolver->name('asset'));
    }

    public function testWithoutTableDefinitionsNoDimensionIsKnown(): void
    {
        $resolver = new DimensionResolver($this->createMock(Connection::class), $this->dimensions());

        $this->assertFalse($resolver->knows('costCenter'));
        $this->assertNull($resolver->resolve('costCenter', 'S01'));
    }
}

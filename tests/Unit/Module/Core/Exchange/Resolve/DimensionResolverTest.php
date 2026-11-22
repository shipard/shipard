<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Resolve;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Accounting\JournalDimension;
use Shipard\Core\Accounting\JournalDimensionSet;
use Shipard\Module\Core\Exchange\Resolve\DimensionResolver;
use Shipard\Module\Core\Exchange\Resolve\ReferenceException;
use Shipard\Tests\Fixtures\Core\Database\ModuleTables;

/**
 * Hodnota dimenze (#110 T2) → id záznamu přes přirozený klíč cílové
 * tabulky (`exchangeKey` v definici, #166 D8): koš se nepočítá, dimenze
 * bez klíče formát nezná, nenalezená i nejednoznačná hodnota je chyba
 * resolveru (#166 D30 — hodnota je reference).
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
                'id' => 'counter', 'rowColumn' => 'counter', 'journalColumn' => 'counter',
                'table' => 'docs_core_number_counters', 'name' => 'Čítač',
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
        return ModuleTables::load('economy_codebooks_cost_centers', 'docs_core_number_counters');
    }

    private function assertFails(string $code, callable $call): void
    {
        try {
            $call();
        } catch (ReferenceException $e) {
            $this->assertSame($code, $e->errorCode, $e->getMessage());
            return;
        }
        $this->fail("očekávána chyba {$code}");
    }

    public function testResolvesReferenceToTheTargetTableExcludingTrash(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->exactly(3))->method('fetchAll')->willReturnCallback(
            static function (string $sql, int|string $value): array {
                if (is_int($value)) {
                    self::assertSame('SELECT [id] FROM [economy_codebooks_cost_centers] WHERE [id] = %i AND [docState] <> 90', $sql);
                    return $value === 3 ? [new Row(['id' => 3])] : [];
                }
                self::assertSame(
                    'SELECT [id] FROM [economy_codebooks_cost_centers] WHERE [code] = %s AND [docState] <> 90 ORDER BY [id]',
                    $sql,
                );
                return $value === 'S01' ? [new Row(['id' => 3])] : [];
            },
        );
        $resolver = new DimensionResolver($db, $this->dimensions(), $this->tables());

        $this->assertSame(3, $resolver->resolve('costCenter', '@S01'));
        $this->assertSame(3, $resolver->resolve('costCenter', '@S01'), 'druhé čtení jde z paměti resolveru');
        $this->assertSame(3, $resolver->resolve('costCenter', 3), 'reference id');
        $this->assertFails(ReferenceException::NOT_FOUND, fn () => $resolver->resolve('costCenter', '@S99'));
        $this->assertFails(ReferenceException::INVALID, fn () => $resolver->resolve('costCenter', 'S01'));
        $this->assertSame('Středisko', $resolver->name('costCenter'));
    }

    public function testAmbiguousValueIsNotResolved(): void
    {
        // #166 D10: víc živých záznamů se stejným klíčem není „první nalezený“.
        $db = $this->createMock(Connection::class);
        $db->method('fetchAll')->willReturn([new Row(['id' => 3]), new Row(['id' => 9])]);
        $resolver = new DimensionResolver($db, $this->dimensions(), $this->tables());

        $this->assertFails(ReferenceException::AMBIGUOUS, fn () => $resolver->resolve('costCenter', '@S01'));
    }

    public function testDimensionWithoutNaturalKeyOrUnknownIsNotResolved(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('fetchAll');
        $db->expects($this->never())->method('fetch');
        $resolver = new DimensionResolver($db, $this->dimensions(), $this->tables());

        $this->assertTrue($resolver->knows('costCenter'));
        $this->assertFalse($resolver->knows('counter'), 'cílová tabulka nemá exchangeKey — formát dimenzi nenese');
        $this->assertFalse($resolver->knows('project'), 'cílová tabulka není aktivní');
        $this->assertFalse($resolver->knows('asset'), 'nedeklarovaná dimenze');
        $this->assertFails(ReferenceException::NO_KEY, fn () => $resolver->resolve('counter', '@S'));
        $this->assertFails(ReferenceException::TABLE_UNKNOWN, fn () => $resolver->resolve('project', '@P1'));
        $this->assertFails(ReferenceException::TABLE_UNKNOWN, fn () => $resolver->resolve('asset', '@MA0001'));
        $this->assertSame('asset', $resolver->name('asset'));
    }

    public function testWithoutTableDefinitionsNoDimensionIsKnown(): void
    {
        $resolver = new DimensionResolver($this->createMock(Connection::class), $this->dimensions());

        $this->assertFalse($resolver->knows('costCenter'));
        $this->assertFails(ReferenceException::TABLE_UNKNOWN, fn () => $resolver->resolve('costCenter', '@S01'));
    }
}

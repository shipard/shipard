<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Resolve;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Accounting\JournalDimension;
use Shipard\Core\Accounting\JournalDimensionSet;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Core\Exchange\Resolve\DimensionResolver;

/**
 * Přirozený klíč dimenze (`exchangeKey`, #110 T2) → id záznamu: koš se
 * vylučuje jen u tabulky se stavy, dimenze bez klíče formát nezná, hodnota
 * se cachuje per běh.
 */
class DimensionResolverTest extends TestCase
{
    private function dimensions(): JournalDimensionSet
    {
        return new JournalDimensionSet([
            JournalDimension::fromArray([
                'id' => 'costCenter', 'rowColumn' => 'cost_center', 'headColumn' => 'cost_center',
                'journalColumn' => 'cost_center', 'table' => 'economy_codebooks_cost_centers',
                'name' => 'Středisko', 'exchangeKey' => 'code',
            ]),
            JournalDimension::fromArray([
                'id' => 'project', 'rowColumn' => 'project', 'journalColumn' => 'project',
                'table' => 'x_projects', 'name' => 'Projekt',
            ]),
        ]);
    }

    private function costCentersTable(): TableDefinition
    {
        return TableDefinition::fromArray(JsoncParser::parseFile(
            dirname(__DIR__, 6) . '/modules/economy/codebooks/tables/economy_codebooks_cost_centers.jsonc',
        ));
    }

    public function testResolvesByNaturalKeyExcludingTrashAndCachesPerValue(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->exactly(2))->method('fetch')->willReturnCallback(
            static function (string $sql, string $value): ?Row {
                self::assertStringContainsString('FROM [economy_codebooks_cost_centers] WHERE [code] = %s AND [docState] <> 90', $sql);
                return $value === 'S01' ? new Row(['id' => 3]) : null;
            },
        );
        $resolver = new DimensionResolver($db, $this->dimensions(), [
            'economy_codebooks_cost_centers' => $this->costCentersTable(),
        ]);

        $this->assertSame(3, $resolver->resolve('costCenter', ' S01 '));
        $this->assertSame(3, $resolver->resolve('costCenter', 'S01'), 'druhé čtení jde z cache');
        $this->assertNull($resolver->resolve('costCenter', 'S99'));
        $this->assertSame('Středisko', $resolver->name('costCenter'));
    }

    public function testTableWithoutDocStatesIsQueriedWithoutStateFilter(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())->method('fetch')->willReturnCallback(
            static function (string $sql): ?Row {
                self::assertStringNotContainsString('docState', $sql);
                return new Row(['id' => 8]);
            },
        );

        $this->assertSame(8, (new DimensionResolver($db, $this->dimensions()))->resolve('costCenter', 'S01'));
    }

    public function testDimensionWithoutExchangeKeyOrUnknownIsNotResolved(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('fetch');
        $resolver = new DimensionResolver($db, $this->dimensions());

        $this->assertTrue($resolver->knows('costCenter'));
        $this->assertFalse($resolver->knows('project'), 'bez exchangeKey formát dimenzi nenese');
        $this->assertFalse($resolver->knows('asset'));
        $this->assertNull($resolver->resolve('project', 'P1'));
        $this->assertNull($resolver->resolve('asset', 'MA0001'));
        $this->assertNull($resolver->resolve('costCenter', '  '));
        $this->assertSame('asset', $resolver->name('asset'));
    }
}

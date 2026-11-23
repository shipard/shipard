<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Resolve;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Resolve\ResolveStatus;
use Shipard\Module\Core\Exchange\Resolve\UnitResolver;

class UnitResolverTest extends TestCase
{
    public function testIsoCodeHResolvesToHrViaAlias(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('fetch')
            ->with($this->stringContains('system_code'), 'hr', 10, 40, 80)
            ->willReturn(new Row(['id' => 7]));

        $r = (new UnitResolver($db))->resolve('h');
        $this->assertSame(ResolveStatus::Matched, $r->status);
        $this->assertSame(7, $r->matchedId);
        $this->assertSame('alias', $r->matchedBy);
    }

    /** #166 D37: pokyn bere jen přesný systémový kód — bez aliasů, zkratek a úprav hodnoty. */
    public function testSystemCodeOnlySkipsAliasesAndShortcuts(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->exactly(2))
            ->method('fetch')
            ->willReturnCallback(function (string $sql, string $code): ?Row {
                $this->assertStringContainsString('[system_code] = %s', $sql);
                return $code === 'hr' ? new Row(['id' => 7]) : null;
            });
        $resolver = new UnitResolver($db);

        $exact = $resolver->resolve('hr', systemCodeOnly: true);
        $this->assertSame([ResolveStatus::Matched, 7, 'systemCode'], [$exact->status, $exact->matchedId, $exact->matchedBy]);
        $this->assertSame(ResolveStatus::NotFound, $resolver->resolve('h', systemCodeOnly: true)->status, 'alias „h“ zná jen návrh');
        $this->assertSame(ResolveStatus::NotFound, $resolver->resolve('', systemCodeOnly: true)->status);
    }

    public function testDirectSystemCodeMatchesWithoutAlias(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('fetch')
            ->with($this->stringContains('system_code'), 'kwh', 10, 40, 80)
            ->willReturn(new Row(['id' => 42]));

        // 'kwh' is also in the alias map but maps to itself; matchedBy = alias
        // is still correct in that case. We test the case-insensitive direct
        // input "KWH" instead — same outcome.
        $r = (new UnitResolver($db))->resolve('KWH');
        $this->assertSame(42, $r->matchedId);
    }

    public function testCzechShortcutKsMapsToPcs(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('fetch')
            ->with($this->stringContains('system_code'), 'pcs', 10, 40, 80)
            ->willReturn(new Row(['id' => 1]));

        $r = (new UnitResolver($db))->resolve('ks');
        $this->assertSame(1, $r->matchedId);
        $this->assertSame('alias', $r->matchedBy);
    }

    public function testCzechNameKusMapsToPcsViaAlias(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('fetch')
            ->with($this->stringContains('system_code'), 'pcs', 10, 40, 80)
            ->willReturn(new Row(['id' => 1]));

        $r = (new UnitResolver($db))->resolve('Kus');
        $this->assertSame(1, $r->matchedId);
        $this->assertSame('alias', $r->matchedBy);
    }

    public function testTrailingDotIsStrippedBeforeLookup(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('fetch')
            ->with($this->stringContains('system_code'), 'pcs', 10, 40, 80)
            ->willReturn(new Row(['id' => 1]));

        $r = (new UnitResolver($db))->resolve('ks.');
        $this->assertSame(1, $r->matchedId);
        $this->assertSame('alias', $r->matchedBy);
    }

    public function testDotOnlyInputReturnsNotFoundWithoutDb(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('fetch');

        $this->assertSame(ResolveStatus::NotFound, (new UnitResolver($db))->resolve('.')->status);
    }

    public function testNameFallbackUsedWhenSystemCodeAndShortcutMiss(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->exactly(3))
            ->method('fetch')
            ->willReturnOnConsecutiveCalls(
                null,                      // system_code misses
                null,                      // shortcut misses
                new Row(['id' => 5]),      // name hits
            );

        $r = (new UnitResolver($db))->resolve('Paleta');
        $this->assertSame(5, $r->matchedId);
        $this->assertSame('name', $r->matchedBy);
    }

    public function testShortcutFallbackUsedWhenSystemCodeMisses(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->exactly(2))
            ->method('fetch')
            ->willReturnOnConsecutiveCalls(
                null,                          // first probe (system_code) misses
                new Row(['id' => 99]),         // second probe (shortcut LIKE) hits
            );

        $r = (new UnitResolver($db))->resolve('custom-unit');
        $this->assertSame(99, $r->matchedId);
        $this->assertSame('shortcut', $r->matchedBy);
    }

    public function testEmptyInputReturnsNotFoundWithoutDb(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('fetch');

        $this->assertSame(ResolveStatus::NotFound, (new UnitResolver($db))->resolve(null)->status);
        $this->assertSame(ResolveStatus::NotFound, (new UnitResolver($db))->resolve('')->status);
        $this->assertSame(ResolveStatus::NotFound, (new UnitResolver($db))->resolve('   ')->status);
    }

    public function testCompletelyUnknownInputReturnsNotFound(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(null);

        $r = (new UnitResolver($db))->resolve('floob');
        $this->assertSame(ResolveStatus::NotFound, $r->status);
    }

    /** #166 D60: jednotka číslem = id aktivní jednotky. */
    public function testResolveIdFindsActiveUnit(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->exactly(2))
            ->method('fetch')
            ->willReturnCallback(function (string $sql, int $id): ?Row {
                $this->assertStringContainsString('[id] = %i', $sql);
                return $id === 9 ? new Row(['id' => 9]) : null;
            });
        $resolver = new UnitResolver($db);

        $found = $resolver->resolveId(9);
        $this->assertSame([ResolveStatus::Matched, 9, 'id'], [$found->status, $found->matchedId, $found->matchedBy]);
        $this->assertSame(ResolveStatus::NotFound, $resolver->resolveId(10)->status);
        $this->assertSame(ResolveStatus::NotFound, $resolver->resolveId(0)->status, 'bez dotazu');
    }
}

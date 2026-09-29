<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Docs\Core\DocsHeadsViewer;

/**
 * Spodní taby souhrnného (cross-type) vieweru dokladů: bez $scopedDocType
 * nejsou žádné záložky a filtr bottomTab se na dotaz neaplikuje.
 */
class DocsHeadsViewerBottomTabsTest extends TestCase
{
    public function testCrossTypeViewerHasNoBottomTabsAndDoesNotQuery(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->expects($this->never())->method('fetchAll');

        $viewer = new DocsHeadsViewer($db, 'docs_core_heads');

        $this->assertSame([], $viewer->getBottomTabs());
        $this->assertNull($viewer->getDefaultBottomTab());
    }

    public function testSelectRowsIgnoresBottomTabWithoutSeriesId(): void
    {
        $captured = [];
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            function (...$args) use (&$captured): array {
                $captured = $args;
                return [];
            },
        );

        $viewer = new DocsHeadsViewer($db, 'docs_core_heads');
        $viewer->selectRows(null, [['id' => 'bottomTab', 'value' => '0']], 0);

        $this->assertStringNotContainsString('number_series', (string) ($captured[0] ?? ''));
    }

    public function testSelectRowsAppliesBottomTabSeriesFilter(): void
    {
        $captured = [];
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            function (...$args) use (&$captured): array {
                $captured = $args;
                return [];
            },
        );

        $viewer = new DocsHeadsViewer($db, 'docs_core_heads');
        $viewer->selectRows(null, [['id' => 'bottomTab', 'value' => '3']], 0);

        $this->assertStringContainsString('h.`number_series` = %i', (string) ($captured[0] ?? ''));
        $this->assertContains(3, $captured);
    }
}

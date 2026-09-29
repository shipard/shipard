<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\InvoicesIn;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Docs\InvoicesIn\ReceivedInvoicesViewer;

class ReceivedInvoicesViewerTest extends TestCase
{
    public function testSelectRowsAppliesInvniFilter(): void
    {
        $captured = [];
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            function (...$args) use (&$captured): array {
                $captured = $args;
                return [];
            },
        );

        $viewer = new ReceivedInvoicesViewer($db, 'docs_core_heads');
        $viewer->selectRows(null, [], 0);

        $sql = (string) ($captured[0] ?? '');
        $this->assertStringContainsString('h.`doc_type` = %s', $sql);
        $this->assertContains('invni', $captured);
    }

    public function testGetNewRecordDefaultsReturnsInvni(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $viewer = new ReceivedInvoicesViewer($db, 'docs_core_heads');

        $this->assertSame(['doc_type' => 'invni'], $viewer->getNewRecordDefaults());
    }

    public function testGetBottomTabsFiltersByTypeAndConfirmedState(): void
    {
        $captured = [];
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            function (...$args) use (&$captured): array {
                $captured = $args;
                return [
                    ['id' => 1, 'name' => 'FPB tuzemsko'],
                    ['id' => 2, 'name' => 'FPB zahraničí'],
                ];
            },
        );

        $viewer = new ReceivedInvoicesViewer($db, 'docs_core_heads');
        $tabs = $viewer->getBottomTabs();

        $sql = (string) ($captured[0] ?? '');
        $this->assertStringContainsString('`doc_type` = %s', $sql);
        $this->assertStringContainsString('`docState` = 40', $sql);
        $this->assertStringContainsString('ORDER BY `name` ASC', $sql);
        $this->assertContains('invni', $captured);
        // Záložka nese řadu jako výchozí hodnotu nového záznamu — frontend
        // ji slije přes newRecordDefaults vieweru, žádné napevno zadrátované
        // number_series na klientu.
        $this->assertSame(
            [
                ['id' => 1, 'label' => 'FPB tuzemsko', 'newRecordDefaults' => ['number_series' => 1]],
                ['id' => 2, 'label' => 'FPB zahraničí', 'newRecordDefaults' => ['number_series' => 2]],
            ],
            $tabs,
        );
        $this->assertNull($viewer->getDefaultBottomTab());
    }

    public function testSelectRowsAppliesBottomTabAsNumberSeriesFilter(): void
    {
        $captured = [];
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            function (...$args) use (&$captured): array {
                $captured = $args;
                return [];
            },
        );

        $viewer = new ReceivedInvoicesViewer($db, 'docs_core_heads');
        // Hodnota filtru přichází z query stringu jako string — viewer ji
        // interpretuje jako id řady.
        $viewer->selectRows(null, [['id' => 'bottomTab', 'value' => '7']], 0);

        $sql = (string) ($captured[0] ?? '');
        $this->assertStringContainsString('h.`number_series` = %i', $sql);
        $this->assertContains(7, $captured);
    }
}

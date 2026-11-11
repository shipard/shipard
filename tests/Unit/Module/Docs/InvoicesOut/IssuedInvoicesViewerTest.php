<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\InvoicesOut;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Docs\InvoicesOut\IssuedInvoicesViewer;

class IssuedInvoicesViewerTest extends TestCase
{
    public function testSelectRowsAppliesInvnoFilter(): void
    {
        $captured = [];
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            function (...$args) use (&$captured): array {
                $captured = $args;
                return [];
            },
        );

        $viewer = new IssuedInvoicesViewer($db, 'docs_core_heads');

        // No viewGroup → should still inject _doc_type filter unconditionally
        $viewer->selectRows(null, [], 0);

        $sql = (string) ($captured[0] ?? '');
        $this->assertStringContainsString('h.`doc_type` = %s', $sql);
        $this->assertContains('invno', $captured, 'invno must be passed as a parameter');
        $this->assertStringContainsString('h.`corrective`', $sql, 'štítek opravného dokladu bez dalšího dotazu (#92)');
    }

    public function testSelectRowsFilterCombinesWithViewGroup(): void
    {
        $captured = [];
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            function (...$args) use (&$captured): array {
                $captured = $args;
                return [];
            },
        );

        $viewer = new IssuedInvoicesViewer($db, 'docs_core_heads');
        $viewer->selectRows(null, [['id' => 'viewGroup', 'value' => 'all']], 0);

        $sql = (string) ($captured[0] ?? '');
        $this->assertStringContainsString('h.`doc_type` = %s', $sql);
        $this->assertContains('invno', $captured);
    }

    public function testGetNewRecordDefaultsReturnsInvno(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $viewer = new IssuedInvoicesViewer($db, 'docs_core_heads');

        $this->assertSame(['doc_type' => 'invno'], $viewer->getNewRecordDefaults());
    }
}

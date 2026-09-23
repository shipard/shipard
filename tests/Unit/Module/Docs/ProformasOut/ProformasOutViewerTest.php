<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\ProformasOut;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Docs\ProformasOut\ProformasOutViewer;

class ProformasOutViewerTest extends TestCase
{
    public function testSelectRowsAppliesInvpoFilter(): void
    {
        $captured = [];
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            function (...$args) use (&$captured): array {
                $captured = $args;
                return [];
            },
        );

        $viewer = new ProformasOutViewer($db, 'docs_core_heads');
        $viewer->selectRows(null, [], 0);

        $sql = (string) ($captured[0] ?? '');
        $this->assertStringContainsString('h.`doc_type` = %s', $sql);
        $this->assertContains('invpo', $captured, 'invpo musí jít do dotazu jako parametr');
    }

    public function testGetNewRecordDefaultsReturnsInvpo(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $viewer = new ProformasOutViewer($db, 'docs_core_heads');

        $this->assertSame(['doc_type' => 'invpo'], $viewer->getNewRecordDefaults());
    }
}

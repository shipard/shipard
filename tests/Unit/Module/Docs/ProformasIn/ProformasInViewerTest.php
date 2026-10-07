<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\ProformasIn;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Docs\ProformasIn\ProformasInViewer;

class ProformasInViewerTest extends TestCase
{
    public function testSelectRowsAppliesInvpiFilter(): void
    {
        $captured = [];
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            function (...$args) use (&$captured): array {
                $captured = $args;
                return [];
            },
        );

        $viewer = new ProformasInViewer($db, 'docs_core_heads');
        $viewer->selectRows(null, [], 0);

        $sql = (string) ($captured[0] ?? '');
        $this->assertStringContainsString('h.`doc_type` = %s', $sql);
        $this->assertContains('invpi', $captured, 'invpi musí jít do dotazu jako parametr');
    }

    public function testGetNewRecordDefaultsReturnsInvpi(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $viewer = new ProformasInViewer($db, 'docs_core_heads');

        $this->assertSame(['doc_type' => 'invpi'], $viewer->getNewRecordDefaults());
    }
}

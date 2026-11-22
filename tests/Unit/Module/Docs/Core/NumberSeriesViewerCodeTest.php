<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Docs\Core\NumberSeriesViewer;

/** Kód řady v seznamu číselných řad (#166 D17, U6). */
class NumberSeriesViewerCodeTest extends TestCase
{
    public function testRowShowsSeriesCodeAndCodeInDocumentNumber(): void
    {
        $viewer = new NumberSeriesViewer($this->createMock(DataSourceConnection::class), 'docs_core_number_series');

        $row = $viewer->renderRow([
            'id' => 6, 'name' => 'Majetek', 'code' => 'cmnbkp-MA', 'doc_type' => 'cmnbkp',
            'doc_number_code' => 'MA', 'doc_number_pattern' => '%D%y%C%4', 'docState' => 40,
        ]);
        $this->assertSame('cmnbkp-MA', $row['i1']);
        $this->assertSame('MA', $row['i2']);

        $row = $viewer->renderRow([
            'id' => 1, 'name' => 'Faktury vydané', 'code' => null, 'doc_type' => 'invno',
            'doc_number_code' => '', 'doc_number_pattern' => '%D%y%4', 'docState' => 40,
        ]);
        $this->assertNull($row['i1'], 'řada před doplněním kódu');
        $this->assertNull($row['i2']);
    }

    public function testSearchCoversTheCode(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->expects($this->once())->method('fetchAll')->with($this->callback(
            static fn (string $sql): bool => str_contains($sql, '`code`') && substr_count($sql, 'LIKE') === 3,
        ))->willReturn([]);

        (new NumberSeriesViewer($db, 'docs_core_number_series'))->selectRows('invno', [['id' => 'viewGroup', 'value' => 'all']], 1);
    }
}

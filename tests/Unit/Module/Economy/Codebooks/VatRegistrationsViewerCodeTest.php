<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Codebooks;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Economy\Codebooks\VatRegistrationsViewer;

/** Kód registrace v seznamu registrací DPH (#166 D17, U6). */
class VatRegistrationsViewerCodeTest extends TestCase
{
    public function testRowShowsRegistrationCodeUnderTheVatId(): void
    {
        $viewer = new VatRegistrationsViewer($this->createMock(DataSourceConnection::class), 'economy_codebooks_vat_registrations');

        $row = $viewer->renderRow([
            'id' => 1, 'name' => 'Naše firma s.r.o.', 'code' => 'cz', 'country' => 'cz',
            'vat_id' => 'CZ12345678', 'taxpayer_kind' => 0, 'tax_period_kind' => 1, 'docState' => 40,
        ]);
        $this->assertSame('CZ12345678', $row['i1']);
        $this->assertSame('cz', $row['i2']);

        $row = $viewer->renderRow(['id' => 2, 'name' => 'Bez kódu', 'code' => null, 'country' => 'cz', 'docState' => 10]);
        $this->assertNull($row['i2'], 'registrace před doplněním kódu');
    }

    public function testSearchCoversTheCode(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->expects($this->once())->method('fetchAll')->with($this->callback(
            static fn (string $sql): bool => str_contains($sql, '`code`') && substr_count($sql, 'LIKE') === 3,
        ))->willReturn([]);

        (new VatRegistrationsViewer($db, 'economy_codebooks_vat_registrations'))
            ->selectRows('cz', [['id' => 'viewGroup', 'value' => 'all']], 1);
    }
}

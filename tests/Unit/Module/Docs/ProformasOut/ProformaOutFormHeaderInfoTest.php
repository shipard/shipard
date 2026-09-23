<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\ProformasOut;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Docs\ProformasOut\ProformaOutForm;

/**
 * Hlavička modalu FVZ: partner = odběratel (customer_snapshot), popisek
 * a ikona per typ — sdílený `buildHeaderInfo()` v base.
 */
class ProformaOutFormHeaderInfoTest extends TestCase
{
    public function testNoPartnerAndNoSnapshotReturnsNull(): void
    {
        $form = new ProformaOutForm('docs_core_heads');

        $this->assertNull($form->buildHeaderInfo(['doc_type' => 'invpo']));
    }

    public function testReadsFromCustomerSnapshotWithProformaLabelAndIcon(): void
    {
        $form = new ProformaOutForm('docs_core_heads');

        $info = $form->buildHeaderInfo([
            'doc_type'          => 'invpo',
            'doc_number'        => '122600001',
            'customer_snapshot' => ['name' => 'Odběratel s.r.o.'],
            'supplier_snapshot' => ['name' => 'Špatný partner'],
        ]);

        $this->assertNotNull($info);
        $this->assertSame('Odběratel s.r.o.', $info->title);
        $this->assertSame(
            [
                ['label' => '',      'value' => 'Zálohová faktura'],
                ['label' => 'Číslo', 'value' => '122600001'],
            ],
            $info->info,
        );
        $this->assertSame('invoice-proforma', $info->icon);
    }

    public function testIgnoresSupplierSnapshotWithoutCustomer(): void
    {
        $form = new ProformaOutForm('docs_core_heads');

        $this->assertNull($form->buildHeaderInfo([
            'doc_type'          => 'invpo',
            'supplier_snapshot' => ['name' => 'Bad supplier ref'],
        ]));
    }
}

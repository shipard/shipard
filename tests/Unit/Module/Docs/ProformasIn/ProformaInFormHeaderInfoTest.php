<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\ProformasIn;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Docs\ProformasIn\ProformaInForm;

/**
 * Hlavička modalu FPZ: partner = dodavatel (supplier_snapshot), popisek
 * a ikona per typ — sdílený `buildHeaderInfo()` v base.
 */
class ProformaInFormHeaderInfoTest extends TestCase
{
    public function testNoPartnerAndNoSnapshotReturnsNull(): void
    {
        $form = new ProformaInForm('docs_core_heads');

        $this->assertNull($form->buildHeaderInfo(['doc_type' => 'invpi']));
    }

    public function testReadsFromSupplierSnapshotWithProformaLabelAndIcon(): void
    {
        $form = new ProformaInForm('docs_core_heads');

        $info = $form->buildHeaderInfo([
            'doc_type'          => 'invpi',
            'doc_number'        => '222600001',
            'supplier_snapshot' => ['name' => 'Dodavatel s.r.o.'],
            'customer_snapshot' => ['name' => 'Špatný partner'],
        ]);

        $this->assertNotNull($info);
        $this->assertSame('Dodavatel s.r.o.', $info->title);
        $this->assertSame(
            [
                ['label' => '',      'value' => 'Zálohová faktura přijatá'],
                ['label' => 'Číslo', 'value' => '222600001'],
            ],
            $info->info,
        );
        $this->assertSame('invoice-proforma-in', $info->icon);
    }

    public function testIgnoresCustomerSnapshotWithoutSupplier(): void
    {
        $form = new ProformaInForm('docs_core_heads');

        $this->assertNull($form->buildHeaderInfo([
            'doc_type'          => 'invpi',
            'customer_snapshot' => ['name' => 'Bad customer ref'],
        ]));
    }
}

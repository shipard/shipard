<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\WorkOrders\Invoicing;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\WorkOrders\Invoicing\InvoicingSettings;
use Shipard\Module\Economy\WorkOrders\Invoicing\InvoicingSettingsResolver;

/**
 * Efektivní předpis (D3): hodnota zakázky → hodnota druhu → pevný default;
 * zdroj hodnoty per sloupec.
 */
class InvoicingSettingsResolverTest extends TestCase
{
    private const KIND = [
        'id' => 13, 'type' => 'periodic',
        'inv_doc_type' => 'invno', 'inv_number_series' => 5, 'inv_due_days' => 14, 'inv_timing' => 'start',
        'inv_vat_mode' => 1, 'inv_payment_method' => 1, 'inv_bank_account' => 3,
    ];

    public function testWorkOrderValueWinsOverKindAndNullFallsBackToKind(): void
    {
        $settings = InvoicingSettingsResolver::resolve(
            ['inv_doc_type' => 'invpo', 'inv_number_series' => 9, 'inv_due_days' => null, 'inv_timing' => '', 'inv_bank_account' => null],
            self::KIND,
        );

        $this->assertSame('invpo', $settings->docType);
        $this->assertSame(9, $settings->numberSeries);
        $this->assertSame(14, $settings->dueDays);
        $this->assertSame('start', $settings->timing);
        $this->assertSame(1, $settings->vatMode);
        $this->assertSame(1, $settings->paymentMethod);
        $this->assertSame(3, $settings->bankAccount);

        $this->assertSame(InvoicingSettings::SOURCE_WORK_ORDER, $settings->source('inv_doc_type'));
        $this->assertSame(InvoicingSettings::SOURCE_WORK_ORDER, $settings->source('inv_number_series'));
        $this->assertSame(InvoicingSettings::SOURCE_KIND, $settings->source('inv_due_days'));
        $this->assertSame(InvoicingSettings::SOURCE_KIND, $settings->source('inv_timing'));
        $this->assertSame(InvoicingSettings::SOURCE_KIND, $settings->source('inv_bank_account'));
    }

    public function testWithoutKindAndWorkOrderValuesDefaultsApply(): void
    {
        $settings = InvoicingSettingsResolver::resolve([], null);

        $this->assertNull($settings->docType);
        $this->assertNull($settings->numberSeries);
        $this->assertNull($settings->bankAccount);
        $this->assertSame(InvoicingSettings::DEFAULT_DUE_DAYS, $settings->dueDays);
        $this->assertSame(InvoicingSettings::TIMING_START, $settings->timing);
        $this->assertSame(InvoicingSettings::DEFAULT_VAT_MODE, $settings->vatMode);
        $this->assertSame(InvoicingSettings::DEFAULT_PAYMENT_METHOD, $settings->paymentMethod);
        $this->assertSame(InvoicingSettings::SOURCE_DEFAULT, $settings->source('inv_due_days'));
        $this->assertSame(InvoicingSettings::SOURCE_DEFAULT, $settings->source('inv_doc_type'));
    }

    public function testZeroIsAValueNotAnAbsence(): void
    {
        // Režim DPH 0 (bez DPH) a splatnost 0 dní jsou platné přepisy.
        $settings = InvoicingSettingsResolver::resolve(['inv_vat_mode' => 0, 'inv_due_days' => 0], self::KIND);
        $this->assertSame(0, $settings->vatMode);
        $this->assertSame(0, $settings->dueDays);
        $this->assertSame(InvoicingSettings::SOURCE_WORK_ORDER, $settings->source('inv_vat_mode'));
    }

    public function testUnknownDocTypeAndTimingAreIgnored(): void
    {
        $settings = InvoicingSettingsResolver::resolve(['inv_doc_type' => 'invni', 'inv_timing' => 'middle'], null);
        $this->assertNull($settings->docType);
        $this->assertSame(InvoicingSettings::TIMING_START, $settings->timing);
    }

    public function testKindValuesListsOnlyFilledColumns(): void
    {
        $values = InvoicingSettingsResolver::kindValues(['inv_doc_type' => 'invno', 'inv_due_days' => null, 'inv_timing' => '', 'name' => 'x']);
        $this->assertSame(['inv_doc_type' => 'invno'], $values);
        $this->assertSame([], InvoicingSettingsResolver::kindValues(null));
    }
}

<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Docs\Core\DocHeadVatContext;

/**
 * Kontext hlavičky pro sub-formuláře řádku a rekapitulace: `load()` dává
 * datum sazby dokladu jako `effective_rate_date` (#159 D14) a sloupce, ze
 * kterých ho `VatRateDates` určuje. Pravidla sama testuje `VatRateDatesTest`.
 */
class DocHeadVatContextTest extends TestCase
{
    public function testLoadReadsCorrectionColumnsAndFillsEffectiveRateDate(): void
    {
        $sqls = [];
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(
            static function (mixed ...$args) use (&$sqls): ?array {
                $sql = (string) ($args[0] ?? '');
                $sqls[] = $sql;
                if (str_contains($sql, 'vat_registrations')) {
                    return ['country' => 'cz'];
                }
                return [
                    'vat_registration' => 1, 'doc_type' => 'invno', 'cash_dir' => 0, 'vat_place' => 0,
                    'vat_duzp' => '2026-05-06', 'vat_dppd' => '2026-05-06', 'issue_date' => '2026-05-06',
                    'corrective' => 1, 'corrected_doc_date' => '2012-06-01',
                    'vat_rate_source' => 0, 'vat_rate_date' => null,
                    'vat_mode' => 1, 'doc_currency' => 'czk', 'home_currency' => 'czk', 'exchange_rate' => 1.0,
                ];
            },
        );

        $context = DocHeadVatContext::load($db, null, 7);

        $this->assertNotNull($context);
        $this->assertSame('2012-06-01', $context['effective_rate_date'], 'sazba k datu plnění původního dokladu');
        $this->assertSame('2012-06-01', $context['automatic_rate_date']);
        $this->assertSame(0, $context['vat_rate_source']);
        $this->assertNull($context['vat_rate_date'], 'vlastní datum dokladu — sloupec hlavičky');
        $this->assertSame('2026-05-06', $context['vat_duzp'], 'DUZP opravného dokladu zůstává (období DPH)');
        $this->assertSame('cz', $context['country']);
        $this->assertStringContainsString('`corrective`', $sqls[0]);
        $this->assertStringContainsString('`corrected_doc_date`', $sqls[0]);
        $this->assertStringContainsString('`vat_dppd`', $sqls[0]);
        $this->assertStringContainsString('`vat_rate_source`', $sqls[0]);
        $this->assertStringContainsString('`vat_rate_date`', $sqls[0]);
    }

    /** Zdroj 1: datum sazby dokladu je vlastní datum hlavičky, automatika zůstává vedle. */
    public function testLoadWithDocumentSourceUsesOwnDate(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn([
            'vat_registration' => null, 'doc_type' => 'invni', 'cash_dir' => 0, 'vat_place' => 0,
            'vat_duzp' => '2013-01-04', 'vat_dppd' => '2013-01-04', 'issue_date' => '2013-01-07',
            'corrective' => 0, 'corrected_doc_date' => null,
            'vat_rate_source' => 1, 'vat_rate_date' => '2012-12-31',
            'vat_mode' => 1, 'doc_currency' => 'czk', 'home_currency' => 'czk', 'exchange_rate' => 1.0,
        ]);

        $context = DocHeadVatContext::load($db, null, 7);

        $this->assertSame(1, $context['vat_rate_source']);
        $this->assertSame('2012-12-31', $context['vat_rate_date']);
        $this->assertSame('2012-12-31', $context['effective_rate_date']);
        $this->assertSame('2013-01-04', $context['automatic_rate_date']);
        $this->assertSame('2013-01-04', $context['vat_dppd'], 'kontext nese sloupce pro VatRateDates');
    }

    public function testLoadWithoutIdOrDbIsNull(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $this->assertNull(DocHeadVatContext::load($db, null, null));
        $this->assertNull(DocHeadVatContext::load($db, null, ''));
        $this->assertNull(DocHeadVatContext::load(null, null, 7));
    }
}

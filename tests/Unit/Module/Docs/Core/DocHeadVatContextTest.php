<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Docs\Core\DocHeadVatContext;

/**
 * Datum, ke kterému se hledá sazba kódu DPH (#92 D4, U6): jedno místo
 * pravidla — u opravného dokladu datum plnění původního dokladu, jinak DUZP,
 * bez něj datum vystavení. `load()` ho dává sub-formulářům jako
 * `vat_rate_date`.
 */
class DocHeadVatContextTest extends TestCase
{
    public function testCorrectiveDocumentUsesOriginalTaxPointDate(): void
    {
        $head = [
            'corrective'         => 1,
            'corrected_doc_date' => '2012-06-01',
            'vat_duzp'           => '2026-05-06',
            'issue_date'         => '2026-05-07',
        ];
        $this->assertSame('2012-06-01', DocHeadVatContext::vatRateDate($head));
    }

    public function testCorrectiveWithoutOriginalDateFallsBackToDuzp(): void
    {
        $head = ['corrective' => 1, 'corrected_doc_date' => null, 'vat_duzp' => '2026-05-06', 'issue_date' => '2026-05-07'];
        $this->assertSame('2026-05-06', DocHeadVatContext::vatRateDate($head));

        $head['corrected_doc_date'] = '';
        $this->assertSame('2026-05-06', DocHeadVatContext::vatRateDate($head));
    }

    public function testFlagOffIgnoresOriginalDate(): void
    {
        $head = ['corrective' => 0, 'corrected_doc_date' => '2012-06-01', 'vat_duzp' => '2026-05-06'];
        $this->assertSame('2026-05-06', DocHeadVatContext::vatRateDate($head), 'běžný doklad: DUZP');

        unset($head['corrective']);
        $this->assertSame('2026-05-06', DocHeadVatContext::vatRateDate($head), 'bez klíče jako dnes');
    }

    public function testDuzpThenIssueDateThenNull(): void
    {
        $this->assertSame('2026-05-06', DocHeadVatContext::vatRateDate(['vat_duzp' => '2026-05-06', 'issue_date' => '2026-05-07']));
        $this->assertSame('2026-05-07', DocHeadVatContext::vatRateDate(['vat_duzp' => null, 'issue_date' => '2026-05-07']));
        $this->assertNull(DocHeadVatContext::vatRateDate(['vat_duzp' => null, 'issue_date' => null]));
        $this->assertNull(DocHeadVatContext::vatRateDate([]));
    }

    public function testDateObjectsAndDateTimesAreNormalized(): void
    {
        $head = ['corrective' => true, 'corrected_doc_date' => new \DateTimeImmutable('2012-06-01 00:00:00')];
        $this->assertSame('2012-06-01', DocHeadVatContext::vatRateDate($head));
        $this->assertSame('2026-05-06', DocHeadVatContext::vatRateDate(['vat_duzp' => '2026-05-06T00:00:00']));
    }

    public function testLoadReadsCorrectionColumnsAndFillsVatRateDate(): void
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
                    'vat_duzp' => '2026-05-06', 'issue_date' => '2026-05-06',
                    'corrective' => 1, 'corrected_doc_date' => '2012-06-01',
                    'vat_mode' => 1, 'doc_currency' => 'czk', 'home_currency' => 'czk', 'exchange_rate' => 1.0,
                ];
            },
        );

        $context = DocHeadVatContext::load($db, null, 7);

        $this->assertNotNull($context);
        $this->assertSame('2012-06-01', $context['vat_rate_date'], 'sazba k datu plnění původního dokladu');
        $this->assertSame('2026-05-06', $context['vat_duzp'], 'DUZP opravného dokladu zůstává (období DPH)');
        $this->assertSame('cz', $context['country']);
        $this->assertStringContainsString('`corrective`', $sqls[0]);
        $this->assertStringContainsString('`corrected_doc_date`', $sqls[0]);
    }

    public function testLoadWithoutIdOrDbIsNull(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $this->assertNull(DocHeadVatContext::load($db, null, null));
        $this->assertNull(DocHeadVatContext::load($db, null, ''));
        $this->assertNull(DocHeadVatContext::load(null, null, 7));
    }
}

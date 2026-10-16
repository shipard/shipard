<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Vat\Overview;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Reports\ReportResult;
use Shipard\Core\Reports\ReportRow;
use Shipard\Core\Reports\ReportRowKind;
use Shipard\Module\Economy\Vat\Overview\VatPositionReader;

/** Řádek 64 vs. 65 vs. nula vs. bez řádků (#120 D16) podle `account`, ne popisku. */
final class VatPositionReaderTest extends TestCase
{
    public function testTaxPayable(): void
    {
        $this->assertSame(['kind' => 'tax', 'amount' => 12345.0], VatPositionReader::read(self::report(12345.0, 0.0)));
    }

    public function testExcessDeduction(): void
    {
        $this->assertSame(['kind' => 'refund', 'amount' => 980.5], VatPositionReader::read(self::report(0.0, 980.5)));
    }

    public function testZeroAndNone(): void
    {
        $this->assertSame(['kind' => 'zero', 'amount' => 0.0], VatPositionReader::read(self::report(0.0, 0.0)));
        $this->assertSame(['kind' => 'none', 'amount' => 0.0], VatPositionReader::read(
            new ReportResult('economy.vat.returnLive', [], 'ds', [], [], []),
        ));
    }

    private static function report(float $tax, float $refund): ReportResult
    {
        $cell = static fn (float $v): array => ['base' => ['md' => 0.0, 'd' => 0.0, 'balance' => 0.0], 'tax' => ['md' => 0.0, 'd' => 0.0, 'balance' => $v], 'taxReduced' => ['md' => 0.0, 'd' => 0.0, 'balance' => 0.0]];
        return new ReportResult('economy.vat.returnLive', [], 'ds', [], [], [
            new ReportRow(ReportRowKind::Detail, 0, '1', 'Nadměrný odpočet', $cell(999.0)), // popisek záměrně matoucí
            new ReportRow(ReportRowKind::Computed, 0, '64', 'Vlastní daň', $cell($tax)),
            new ReportRow(ReportRowKind::Computed, 0, '65', 'Nadměrný odpočet', $cell($refund)),
        ]);
    }
}

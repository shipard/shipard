<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Accounting\Overview;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Reports\ReportResult;
use Shipard\Core\Reports\ReportRow;
use Shipard\Core\Reports\ReportRowKind;
use Shipard\Module\Economy\Accounting\Overview\ProfitLossReader;

/** Čtení výsledovky podle klíčů řádků (#120 D8): `ytd`, znaménka výnosů, chybějící řádky. */
final class ProfitLossReaderTest extends TestCase
{
    public function testReadsYtdByKeysNotLabels(): void
    {
        $report = new ReportResult('economy.accounting.profitLoss', [], 'ds', [], [], [
            new ReportRow(ReportRowKind::Detail, 3, '501', 'Spotřeba', self::values(100.0, 400.0)),
            new ReportRow(ReportRowKind::Subtotal, 1, '5', 'Výsledek hospodaření', self::values(100.0, 400.0), 'costs'),
            new ReportRow(ReportRowKind::Subtotal, 1, '6', 'Náklady', self::values(-300.0, -1000.0), 'revenues'),
            new ReportRow(ReportRowKind::Computed, 0, null, 'Výsledek hospodaření za období', self::values(200.0, 600.0), 'result'),
        ]);

        $this->assertSame(['result' => 600.0, 'costs' => 400.0, 'revenues' => 1000.0], ProfitLossReader::read($report));
    }

    public function testMissingRowsYieldNulls(): void
    {
        $report = new ReportResult('economy.accounting.profitLoss', [], 'ds', [], [], [
            new ReportRow(ReportRowKind::Computed, 0, null, 'x', self::values(0.0, -12.5), 'result'),
        ]);

        $this->assertSame(['result' => -12.5, 'costs' => null, 'revenues' => null], ProfitLossReader::read($report));
        $this->assertSame(['result' => null, 'costs' => null, 'revenues' => null], ProfitLossReader::read(
            new ReportResult('economy.accounting.profitLoss', [], 'ds', [], [], []),
        ));
    }

    /** @return array<string, array{md: float, d: float, balance: float}> */
    private static function values(float $period, float $ytd): array
    {
        return [
            'period' => ['md' => 0.0, 'd' => 0.0, 'balance' => $period],
            'ytd'    => ['md' => 0.0, 'd' => 0.0, 'balance' => $ytd],
        ];
    }
}

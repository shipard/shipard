<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Reports;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Reports\FiscalPeriodProvider;
use Shipard\Core\Reports\ReportColumn;
use Shipard\Core\Reports\ReportDefinition;
use Shipard\Core\Reports\ReportMessage;
use Shipard\Core\Reports\ReportMessageSeverity;
use Shipard\Core\Reports\ReportParamValidator;
use Shipard\Core\Reports\ReportResult;
use Shipard\Core\Reports\ReportRow;
use Shipard\Core\Reports\ReportRowKind;
use Shipard\Core\Reports\SubtotalAggregator;

class ReportCoreTest extends TestCase
{
    // ── ReportResult::toArray + odvození statusu ────────────────────────────

    public function testResultToArrayShapeAndOkStatus(): void
    {
        $result = new ReportResult(
            reportId: 'economy.accounting.generalLedger',
            params: ['period' => ['fiscalYear' => '2026', 'monthFrom' => 1, 'monthTo' => 3], 'detail' => 'analytic'],
            dataSource: 'abcd-efgh-ijkl-mnop',
            messages: [],
            columns: [new ReportColumn('turnover', 'money', 'Obraty za období')],
            rows: [
                new ReportRow(ReportRowKind::Detail, 4, '501001', 'Spotřeba materiálu', [
                    'turnover' => ['md' => 100.5, 'd' => 0.0, 'balance' => 100.5],
                ]),
            ],
            generatedAt: new \DateTimeImmutable('2026-08-21T12:00:00+02:00'),
        );

        $array = $result->toArray();

        $this->assertSame(
            ['reportId', 'params', 'generatedAt', 'dataSource', 'status', 'messages', 'columns', 'rows'],
            array_keys($array),
        );
        $this->assertSame('economy.accounting.generalLedger', $array['reportId']);
        $this->assertSame('2026-08-21T12:00:00+02:00', $array['generatedAt']);
        $this->assertSame('abcd-efgh-ijkl-mnop', $array['dataSource']);
        $this->assertSame('ok', $array['status']);
        $this->assertSame([], $array['messages']);
        $this->assertSame(
            [['id' => 'turnover', 'type' => 'money', 'label' => 'Obraty za období', 'display' => 'balance']],
            $array['columns'],
        );
        $this->assertSame(
            [[
                'kind'    => 'detail',
                'level'   => 4,
                'account' => '501001',
                'label'   => 'Spotřeba materiálu',
                'values'  => ['turnover' => ['md' => 100.5, 'd' => 0.0, 'balance' => 100.5]],
            ]],
            $array['rows'],
        );
    }

    public function testColumnDisplayDefaultAndExplicit(): void
    {
        $default = new ReportColumn('closing', 'money', 'Konečný zůstatek');
        $this->assertSame('balance', $default->toArray()['display']);

        $sides = new ReportColumn('turnover', 'money', 'Obraty', ReportColumn::DISPLAY_SIDES);
        $this->assertSame(
            ['id' => 'turnover', 'type' => 'money', 'label' => 'Obraty', 'display' => 'sides'],
            $sides->toArray(),
        );

        $this->expectException(\InvalidArgumentException::class);
        new ReportColumn('x', 'money', 'X', 'both');
    }

    public function testTextAndDateColumns(): void
    {
        $text = new ReportColumn('evidNumber', 'text', 'Ev. číslo');
        $this->assertSame(
            ['id' => 'evidNumber', 'type' => 'text', 'label' => 'Ev. číslo', 'display' => 'balance'],
            $text->toArray(),
        );

        $date = new ReportColumn('dppd', 'date', 'DPPD');
        $this->assertSame('date', $date->toArray()['type']);

        // String buňky procházejí toArray beze změny.
        $result = new ReportResult('r', [], 'ds', [], [$text], [
            new ReportRow(ReportRowKind::Detail, 0, null, 'FV-2026-001', ['evidNumber' => 'FV-2026-001']),
        ]);
        $this->assertSame(['evidNumber' => 'FV-2026-001'], $result->toArray()['rows'][0]['values']);
    }

    public function testTextColumnRejectsSidesDisplay(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("display 'sides' requires type 'money'");
        new ReportColumn('vatId', 'text', 'DIČ', ReportColumn::DISPLAY_SIDES);
    }

    public function testColumnRejectsUnknownType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("unsupported type 'number'");
        new ReportColumn('x', 'number', 'X');
    }

    public function testStatusDerivedFromMessages(): void
    {
        $info    = new ReportMessage(ReportMessageSeverity::Info, 'x.info', 'info');
        $warning = new ReportMessage(ReportMessageSeverity::Warning, 'x.warn', 'warn');
        $error   = new ReportMessage(ReportMessageSeverity::Error, 'journal.accountNotFound', 'err', 'rows.3');

        $make = fn (array $messages): ReportResult => new ReportResult(
            'r', [], 'ds', $messages, [], [],
        );

        $this->assertSame('ok', $make([])->toArray()['status']);
        $this->assertSame('ok', $make([$info])->toArray()['status']);
        $this->assertSame('warnings', $make([$info, $warning])->toArray()['status']);
        $this->assertSame('errors', $make([$warning, $error])->toArray()['status']);

        $this->assertSame(
            ['severity' => 'error', 'code' => 'journal.accountNotFound', 'text' => 'err', 'rowRef' => 'rows.3'],
            $error->toArray(),
        );
        // rowRef se bez hodnoty neemituje
        $this->assertArrayNotHasKey('rowRef', $info->toArray());
    }

    // ── ReportParamValidator ────────────────────────────────────────────────

    /**
     * Rok "2026" (id 100): otevírací období id 200, běžné měsíce id 201–212,
     * uzavírací id 213.
     */
    private function fakePeriods(): FiscalPeriodProvider
    {
        return new class implements FiscalPeriodProvider {
            public function findYearByName(string $name): ?array
            {
                return $name === '2026' ? ['id' => 100, 'name' => '2026'] : null;
            }

            public function monthsOfYear(int $fiscalYearId): array
            {
                $months = [['id' => 200, 'periodType' => 0]];
                for ($i = 1; $i <= 12; $i++) {
                    $months[] = ['id' => 200 + $i, 'periodType' => 1];
                }
                $months[] = ['id' => 213, 'periodType' => 2];
                return $months;
            }

            public function regularYears(): array
            {
                return [['name' => '2026', 'months' => 12]];
            }

            public function yearForDate(string $date): ?array
            {
                return null;
            }

            public function years(): array
            {
                return [];
            }
        };
    }

    private function definition(array $granularities = ['month', 'quarter', 'halfYear', 'year']): ReportDefinition
    {
        return new ReportDefinition(
            id: 'test.report',
            name: 'Test report',
            builderClass: 'TestBuilder',
            periodGranularities: $granularities,
            params: [
                ['id' => 'detail', 'type' => 'enum', 'options' => ['analytic', 'synthetic'], 'default' => 'analytic'],
            ],
            moduleId: 'test.module',
        );
    }

    public function testValidatorResolvesRangeAndDefaults(): void
    {
        $validator = new ReportParamValidator($this->fakePeriods());

        $result = $validator->validate($this->definition(), [
            'fiscalYear' => '2026',
            'monthFrom'  => '4',
            'monthTo'    => '6',
        ]);

        $range = $result['range'];
        $this->assertSame(100, $range->fiscalYearId);
        $this->assertSame('2026', $range->fiscalYear);
        $this->assertSame(4, $range->monthFrom);
        $this->assertSame(6, $range->monthTo);
        // Před intervalem: otevírací období + měsíce 1–3; uzavírací nikdy.
        $this->assertSame([200, 201, 202, 203], $range->monthIdsBefore);
        $this->assertSame([204, 205, 206], $range->monthIdsInRange);

        $this->assertSame(
            ['fiscalYear' => '2026', 'monthFrom' => 4, 'monthTo' => 6],
            $result['params']['period'],
        );
        // Chybějící parametr → default z deklarace.
        $this->assertSame('analytic', $result['params']['detail']);
    }

    public function testValidatorAcceptsExplicitEnumValue(): void
    {
        $validator = new ReportParamValidator($this->fakePeriods());
        $result    = $validator->validate($this->definition(), [
            'fiscalYear' => '2026', 'monthFrom' => '1', 'monthTo' => '12', 'detail' => 'synthetic',
        ]);
        $this->assertSame('synthetic', $result['params']['detail']);
    }

    public function testValidatorRejectsUnknownParameter(): void
    {
        $validator = new ReportParamValidator($this->fakePeriods());
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown parameter 'bogus'");
        $validator->validate($this->definition(), [
            'fiscalYear' => '2026', 'monthFrom' => '1', 'monthTo' => '1', 'bogus' => 'x',
        ]);
    }

    public function testValidatorRejectsInvalidEnumValue(): void
    {
        $validator = new ReportParamValidator($this->fakePeriods());
        $this->expectException(\InvalidArgumentException::class);
        $validator->validate($this->definition(), [
            'fiscalYear' => '2026', 'monthFrom' => '1', 'monthTo' => '1', 'detail' => 'wrong',
        ]);
    }

    public function testValidatorRejectsUnknownFiscalYear(): void
    {
        $validator = new ReportParamValidator($this->fakePeriods());
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Fiscal year '2031' does not exist");
        $validator->validate($this->definition(), [
            'fiscalYear' => '2031', 'monthFrom' => '1', 'monthTo' => '1',
        ]);
    }

    public function testValidatorRejectsFromGreaterThanTo(): void
    {
        $validator = new ReportParamValidator($this->fakePeriods());
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("'monthFrom' must not be greater than 'monthTo'");
        $validator->validate($this->definition(), [
            'fiscalYear' => '2026', 'monthFrom' => '5', 'monthTo' => '2',
        ]);
    }

    public function testValidatorRejectsMonthOutOfRange(): void
    {
        $validator = new ReportParamValidator($this->fakePeriods());
        $this->expectException(\InvalidArgumentException::class);
        $validator->validate($this->definition(), [
            'fiscalYear' => '2026', 'monthFrom' => '1', 'monthTo' => '13',
        ]);
    }

    public function testValidatorRejectsMisalignedInterval(): void
    {
        $validator = new ReportParamValidator($this->fakePeriods());
        // Délka 3, ale od měsíce 2 — není zarovnané čtvrtletí ani nic jiného.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not match any allowed granularity');
        $validator->validate($this->definition(), [
            'fiscalYear' => '2026', 'monthFrom' => '2', 'monthTo' => '4',
        ]);
    }

    public function testValidatorRejectsGranularityNotDeclared(): void
    {
        $validator = new ReportParamValidator($this->fakePeriods());
        // Zarovnané čtvrtletí, ale report deklaruje jen `month`.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not match any allowed granularity');
        $validator->validate($this->definition(['month']), [
            'fiscalYear' => '2026', 'monthFrom' => '1', 'monthTo' => '3',
        ]);
    }

    public function testValidatorRejectsMissingPeriodParams(): void
    {
        $validator = new ReportParamValidator($this->fakePeriods());
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Missing required parameter 'fiscalYear'");
        $validator->validate($this->definition(), []);
    }

    // ── SubtotalAggregator ──────────────────────────────────────────────────

    private function detailRow(string $account, float $md, float $d): ReportRow
    {
        return new ReportRow(ReportRowKind::Detail, 4, $account, "Účet {$account}", [
            'turnover' => ['md' => $md, 'd' => $d, 'balance' => round($md - $d, 2)],
        ]);
    }

    public function testAggregatorEmptyInput(): void
    {
        $aggregator = new SubtotalAggregator();
        $this->assertSame([], $aggregator->rollup([], [3, 2, 1], fn () => 'x', 'Celkem'));
    }

    public function testAggregatorRollupLevelsSumsAndOrdering(): void
    {
        $aggregator = new SubtotalAggregator();

        $rows = $aggregator->rollup(
            [
                // Schválně neseřazené — aggregator si řadí sám.
                $this->detailRow('602001', 0.0, 200.0),
                $this->detailRow('501001', 100.5, 0.0),
                $this->detailRow('501002', 50.0, 10.0),
            ],
            [3, 2, 1],
            fn (string $prefix, int $length): string => "S{$length}:{$prefix}",
            'Celkem',
        );

        $flat = array_map(
            static fn (ReportRow $r): array => [$r->kind->value, $r->level, $r->account, $r->label],
            $rows,
        );
        $this->assertSame([
            ['detail',   4, '501001', 'Účet 501001'],
            ['detail',   4, '501002', 'Účet 501002'],
            ['subtotal', 3, '501',    'S3:501'],
            ['subtotal', 2, '50',     'S2:50'],
            ['subtotal', 1, '5',      'S1:5'],
            ['detail',   4, '602001', 'Účet 602001'],
            ['subtotal', 3, '602',    'S3:602'],
            ['subtotal', 2, '60',     'S2:60'],
            ['subtotal', 1, '6',      'S1:6'],
            ['total',    0, null,     'Celkem'],
        ], $flat);

        // Syntetika 501 = suma obou analytik.
        $this->assertSame(['md' => 150.5, 'd' => 10.0, 'balance' => 140.5], $rows[2]->values['turnover']);
        // Skupina 50 a třída 5 zde shodné se syntetikou.
        $this->assertSame(['md' => 150.5, 'd' => 10.0, 'balance' => 140.5], $rows[3]->values['turnover']);
        $this->assertSame(['md' => 150.5, 'd' => 10.0, 'balance' => 140.5], $rows[4]->values['turnover']);
        // Třída 6.
        $this->assertSame(['md' => 0.0, 'd' => 200.0, 'balance' => -200.0], $rows[8]->values['turnover']);
        // Total = suma tříd.
        $this->assertSame(['md' => 150.5, 'd' => 210.0, 'balance' => -59.5], $rows[9]->values['turnover']);
    }

    public function testAggregatorIgnoresStringCells(): void
    {
        $aggregator = new SubtotalAggregator();
        $detail     = new ReportRow(ReportRowKind::Detail, 2, '501', 'Spotřeba', [
            'evidNumber' => 'FV-2026-001',
            'turnover'   => ['md' => 5.0, 'd' => 2.0, 'balance' => 3.0],
        ]);

        $rows  = $aggregator->rollup([$detail], [1], fn (string $p): string => "Třída {$p}", 'Celkem');
        $total = end($rows);

        $this->assertSame(ReportRowKind::Total, $total->kind);
        $this->assertArrayNotHasKey('evidNumber', $total->values);
        $this->assertSame(['md' => 5.0, 'd' => 2.0, 'balance' => 3.0], $total->values['turnover']);
    }

    public function testAggregatorMultipleColumns(): void
    {
        $aggregator = new SubtotalAggregator();
        $detail     = new ReportRow(ReportRowKind::Detail, 2, '501', 'Spotřeba', [
            'opening'  => ['md' => 10.0, 'd' => 0.0, 'balance' => 10.0],
            'turnover' => ['md' => 5.0, 'd' => 2.0, 'balance' => 3.0],
        ]);

        $rows  = $aggregator->rollup([$detail], [1], fn (string $p): string => "Třída {$p}", 'Celkem');
        $total = end($rows);

        $this->assertSame(ReportRowKind::Total, $total->kind);
        $this->assertSame(['md' => 10.0, 'd' => 0.0, 'balance' => 10.0], $total->values['opening']);
        $this->assertSame(['md' => 5.0, 'd' => 2.0, 'balance' => 3.0], $total->values['turnover']);
    }
}

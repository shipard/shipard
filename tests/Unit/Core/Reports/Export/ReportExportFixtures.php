<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Reports\Export;

use Shipard\Core\Reports\Export\ReportExportContext;
use Shipard\Core\Reports\Export\ReportExportLabels;
use Shipard\Core\Reports\ReportColumn;
use Shipard\Core\Reports\ReportMessage;
use Shipard\Core\Reports\ReportMessageSeverity;
use Shipard\Core\Reports\ReportResult;
use Shipard\Core\Reports\ReportRow;
use Shipard\Core\Reports\ReportRowKind;

/** Sdílené vzorky pro testy exportu reportů. */
final class ReportExportFixtures
{
    /**
     * Výsledek pokrývající všechny tvary: balance i sides sloupec, text,
     * datum, úrovně 0–4, všechny druhy řádků, chybějící hodnoty.
     *
     * @param ReportMessage[] $messages
     */
    public static function result(array $messages = []): ReportResult
    {
        return new ReportResult(
            reportId: 'test.ledger',
            params: ['period' => ['fiscalYear' => '2026', 'monthFrom' => 5, 'monthTo' => 5], 'detail' => 'analytic'],
            dataSource: 'abcd-efgh-ijkl-mnop',
            messages: $messages,
            columns: [
                new ReportColumn('opening', ReportColumn::TYPE_MONEY, 'Počáteční stav'),
                new ReportColumn('turnover', ReportColumn::TYPE_MONEY, 'Obraty', ReportColumn::DISPLAY_SIDES),
                new ReportColumn('date', ReportColumn::TYPE_DATE, 'Datum'),
                new ReportColumn('note', ReportColumn::TYPE_TEXT, 'Poznámka'),
            ],
            rows: [
                new ReportRow(ReportRowKind::Detail, 4, '501001', 'Spotřeba materiálu', [
                    'opening'  => ['md' => 0.0, 'd' => 0.0, 'balance' => 0.0],
                    'turnover' => ['md' => 1234567.5, 'd' => 10.0, 'balance' => 1234557.5],
                    'date'     => '2026-05-31',
                    'note'     => 'první nákup',
                ]),
                new ReportRow(ReportRowKind::Detail, 4, '504???', '504???', [
                    'turnover' => ['md' => 1.0, 'd' => 0.0, 'balance' => 1.0],
                ]),
                new ReportRow(ReportRowKind::Subtotal, 3, '501', 'Spotřeba; "materiálu"', [
                    'opening' => ['md' => 0.0, 'd' => 5.25, 'balance' => -5.25],
                ]),
                new ReportRow(ReportRowKind::Computed, 1, null, 'Výsledek hospodaření', [
                    'opening' => ['md' => 0.0, 'd' => 0.0, 'balance' => 100.0],
                ]),
                new ReportRow(ReportRowKind::Total, 0, null, 'Celkem', [
                    'opening' => ['md' => 0.0, 'd' => 5.25, 'balance' => -5.25],
                ]),
            ],
            generatedAt: new \DateTimeImmutable('2026-10-01T10:00:00+02:00'),
        );
    }

    public static function errorMessage(): ReportMessage
    {
        return new ReportMessage(
            ReportMessageSeverity::Error,
            'journal.accountNotFound',
            'Účet 504??? nebyl nalezen v rozvrhu',
            'rows.1',
        );
    }

    public static function context(string $language = 'cs'): ReportExportContext
    {
        return new ReportExportContext(
            reportName: 'Hlavní kniha',
            dataSourceName: 'Ukázková firma s.r.o.',
            labels: new ReportExportLabels(
                [
                    'account' => 'Účet', 'label' => 'Název', 'sideMd' => 'MD', 'sideD' => 'D',
                    'sideBalance' => 'Zůstatek', 'period' => 'Období', 'sheetMessages' => 'Zprávy',
                    'severityError' => 'Chyba',
                ],
                ['detail' => ['name' => 'Úroveň detailu', 'options' => ['analytic' => 'Analyticky']]],
            ),
            language: $language,
        );
    }
}

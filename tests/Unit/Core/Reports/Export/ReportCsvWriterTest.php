<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Reports\Export;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Reports\Export\ReportCsvWriter;
use Shipard\Core\Reports\Export\ReportTabularizer;
use Shipard\Core\Reports\ReportColumn;
use Shipard\Core\Reports\ReportResult;
use Shipard\Core\Reports\ReportRow;
use Shipard\Core\Reports\ReportRowKind;

class ReportCsvWriterTest extends TestCase
{
    private function csv(?ReportResult $result = null): string
    {
        $table = (new ReportTabularizer())->tabularize(
            $result ?? ReportExportFixtures::result([ReportExportFixtures::errorMessage()]),
            ReportExportFixtures::context(),
        );
        return (new ReportCsvWriter())->write($table);
    }

    public function testStartsWithBomAndHeaderRow(): void
    {
        $csv = $this->csv();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $lines = explode("\r\n", substr($csv, 3));
        $this->assertSame(
            'Účet;Název;"Počáteční stav";"Obraty — MD";"Obraty — D";"Obraty — Zůstatek";Datum;Poznámka',
            $lines[0],
        );
        // Jen tabulka: hlavička + 5 řádků + prázdný zbytek za posledním CRLF.
        $this->assertCount(7, $lines);
        $this->assertSame('', $lines[6]);
    }

    public function testDecimalCommaIsoDateAndNoIndent(): void
    {
        $lines = explode("\r\n", substr($this->csv(), 3));

        // Desetinná čárka bez oddělovače tisíců, datum ISO, nula jako 0,00.
        $this->assertSame(
            '501001;"Spotřeba materiálu";0,00;1234567,50;10,00;1234557,50;2026-05-31;"první nákup"',
            $lines[1],
        );
        // Chybějící hodnoty = prázdná pole; popisek bez odsazení.
        $this->assertSame('504???;504???;;1,00;0,00;1,00;;', $lines[2]);
        $this->assertSame(';Celkem;-5,25;;;;;', $lines[5]);
    }

    public function testEscapesDelimiterAndQuotes(): void
    {
        $lines = explode("\r\n", substr($this->csv(), 3));

        $this->assertSame('501;"Spotřeba; ""materiálu""";-5,25;;;;;', $lines[3]);
    }

    public function testCarriesNoIntroNorMessages(): void
    {
        $csv = $this->csv();

        $this->assertStringNotContainsString('Ukázková firma', $csv);
        $this->assertStringNotContainsString('journal.accountNotFound', $csv);
    }

    public function testNeutralizesFormulaLikeText(): void
    {
        $result = new ReportResult(
            reportId: 'test.formula',
            params: [],
            dataSource: 'abcd-efgh-ijkl-mnop',
            messages: [],
            columns: [
                new ReportColumn('note', ReportColumn::TYPE_TEXT, 'Poznámka'),
                new ReportColumn('amount', ReportColumn::TYPE_MONEY, 'Částka'),
            ],
            rows: [
                new ReportRow(ReportRowKind::Detail, 1, null, '=HYPERLINK("http://x")', [
                    'note'   => '@cmd',
                    'amount' => ['md' => 0.0, 'd' => 0.0, 'balance' => -12.5],
                ]),
            ],
        );

        $lines = explode("\r\n", substr($this->csv($result), 3));

        // Text s úvodním `=` / `@` dostane apostrof; záporné číslo ne.
        $this->assertSame('"\'=HYPERLINK(""http://x"")";\'@cmd;-12,50', $lines[1]);
    }
}

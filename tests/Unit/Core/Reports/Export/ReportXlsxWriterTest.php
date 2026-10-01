<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Reports\Export;

use OpenSpout\Common\Entity\Cell\DateTimeCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Reports\Export\ReportExporter;
use Shipard\Core\Reports\Export\ReportExportFormat;
use Shipard\Core\Reports\ReportResult;

class ReportXlsxWriterTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
    }

    /** Vyexportuje výsledek a vrátí cestu k dočasnému .xlsx. */
    private function export(ReportResult $result): string
    {
        $file = (new ReportExporter())->export($result, ReportExportFormat::Xlsx, ReportExportFixtures::context());

        $path = tempnam(sys_get_temp_dir(), 'shpd_xlsx_test_') . '.xlsx';
        file_put_contents($path, $file->body);
        $this->tempFiles[] = $path;
        $this->tempFiles[] = substr($path, 0, -5);
        return $path;
    }

    /**
     * @return array<string, list<list<\OpenSpout\Common\Entity\Cell>>> název listu → řádky buněk
     */
    private function read(string $path): array
    {
        $reader = new Reader(new Options(SHOULD_PRESERVE_EMPTY_ROWS: true));
        $reader->open($path);
        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $rows = [];
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = array_values($row->cells);
            }
            $sheets[$sheet->getName()] = $rows;
        }
        $reader->close();
        return $sheets;
    }

    /** Je buňka (např. `B12`) na prvním listu tučná? Čte styl přímo z XML. */
    private function isBold(string $path, string $cellRef): bool
    {
        return str_contains($this->cellFontXml($path, $cellRef), '<b/>');
    }

    private function cellFontXml(string $path, string $cellRef): string
    {
        [$sheetXml, $stylesXml] = $this->xml($path);
        $this->assertSame(1, preg_match('/<c r="' . $cellRef . '" s="(\d+)"/', $sheetXml, $match), "cell {$cellRef}");

        preg_match('/<cellXfs[^>]*>(.*)<\/cellXfs>/s', $stylesXml, $xfs);
        preg_match_all('/<xf [^>]*fontId="(\d+)"/', $xfs[1], $fontIds);
        preg_match('/<fonts[^>]*>(.*)<\/fonts>/s', $stylesXml, $fonts);
        preg_match_all('/<font>.*?<\/font>/s', $fonts[1], $fontXml);

        return $fontXml[0][(int) $fontIds[1][(int) $match[1]]];
    }

    /** Uložený kód číselného formátu buňky (vlastní i vestavěný id 4). */
    private function cellFormat(string $path, string $cellRef): string
    {
        [$sheetXml, $stylesXml] = $this->xml($path);
        $this->assertSame(1, preg_match('/<c r="' . $cellRef . '" s="(\d+)"/', $sheetXml, $match), "cell {$cellRef}");

        preg_match('/<cellXfs[^>]*>(.*)<\/cellXfs>/s', $stylesXml, $xfs);
        preg_match_all('/<xf numFmtId="(\d+)"/', $xfs[1], $formatIds);
        $formatId = (int) $formatIds[1][(int) $match[1]];
        if ($formatId === 4) {
            return '#,##0.00';
        }
        if (preg_match('/<numFmt numFmtId="' . $formatId . '" formatCode="([^"]*)"/', $stylesXml, $code)) {
            return html_entity_decode($code[1], ENT_QUOTES | ENT_XML1);
        }
        return '';
    }

    /** @return array{0: string, 1: string} [sheet1.xml, styles.xml] */
    private function xml(string $path): array
    {
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path));
        $sheet  = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $styles = (string) $zip->getFromName('xl/styles.xml');
        $zip->close();
        return [$sheet, $styles];
    }

    // ── Struktura ───────────────────────────────────────────────────────────

    public function testFileOpensWithIntroBlankRowAndTable(): void
    {
        $sheets = $this->read($this->export(ReportExportFixtures::result()));

        // status ok → jediný list.
        $this->assertSame(['Report'], array_keys($sheets));
        $rows = $sheets['Report'];

        $this->assertSame('Hlavní kniha', $rows[0][0]->getValue());
        $this->assertSame(['Období', '2026 / 5'], [$rows[1][0]->getValue(), $rows[1][1]->getValue()]);
        $this->assertSame(['Data source', 'Ukázková firma s.r.o.'], [$rows[4][0]->getValue(), $rows[4][1]->getValue()]);

        // Titulek + 5 řádků úvodu, prázdný řádek, hlavička, 5 datových řádků.
        $this->assertCount(13, $rows);
        $this->assertTrue((new \OpenSpout\Common\Entity\Row($rows[6]))->isEmpty());
        $this->assertSame(
            ['Účet', 'Název', 'Počáteční stav', 'Obraty — MD', 'Obraty — D', 'Obraty — Zůstatek', 'Datum', 'Poznámka'],
            array_map(static fn ($cell) => $cell->getValue(), $rows[7]),
        );
    }

    // ── Typy buněk ──────────────────────────────────────────────────────────

    public function testCellTypesNumberDateText(): void
    {
        $path = $this->export(ReportExportFixtures::result());
        $row  = $this->read($path)['Report'][8];

        $this->assertInstanceOf(StringCell::class, $row[0]);
        $this->assertSame('501001', $row[0]->getValue());
        $this->assertInstanceOf(StringCell::class, $row[1]);
        // Hodnota popisku bez odsazení — to je jen formát buňky.
        $this->assertSame('Spotřeba materiálu', $row[1]->getValue());

        $this->assertInstanceOf(NumericCell::class, $row[2]);
        $this->assertEquals(0, $row[2]->getValue());
        $this->assertInstanceOf(NumericCell::class, $row[3]);
        $this->assertEqualsWithDelta(1234567.5, $row[3]->getValue(), 0.001);

        $this->assertInstanceOf(DateTimeCell::class, $row[6]);
        $this->assertSame('2026-05-31', $row[6]->getValue()->format('Y-m-d'));
        $this->assertInstanceOf(StringCell::class, $row[7]);

        $this->assertSame('#,##0.00', $this->cellFormat($path, 'D9'));
        $this->assertSame('d.m.yyyy', $this->cellFormat($path, 'G9'));
    }

    public function testFormulaLikeTextStaysString(): void
    {
        $path = $this->export(ReportExportFixtures::result());
        [$sheetXml] = $this->xml($path);

        // Buňky jsou typované — žádný vzorec se v sešitu nesmí objevit.
        $this->assertStringNotContainsString('<f>', $sheetXml);
    }

    // ── Odsazení a tučné součty ─────────────────────────────────────────────

    public function testLabelIndentFollowsLevel(): void
    {
        $path = $this->export(ReportExportFixtures::result());

        // level 4 → 8 mezer, level 3 → 6, level 1 → 2, level 0 → bez formátu.
        $this->assertSame('"        "@', $this->cellFormat($path, 'B9'));
        $this->assertSame('"      "@', $this->cellFormat($path, 'B11'));
        $this->assertSame('"  "@', $this->cellFormat($path, 'B12'));
        $this->assertSame('', $this->cellFormat($path, 'B13'));
    }

    public function testSubtotalComputedAndTotalRowsAreBold(): void
    {
        $path = $this->export(ReportExportFixtures::result());

        // Hlavička tabulky a titulek.
        $this->assertTrue($this->isBold($path, 'A1'));
        $this->assertTrue($this->isBold($path, 'B8'));
        // detail řádky ne.
        $this->assertFalse($this->isBold($path, 'B9'));
        $this->assertFalse($this->isBold($path, 'D9'));
        // subtotal / computed / total ano — popisek i čísla.
        $this->assertTrue($this->isBold($path, 'B11'));
        $this->assertTrue($this->isBold($path, 'C11'));
        $this->assertTrue($this->isBold($path, 'B12'));
        $this->assertTrue($this->isBold($path, 'C13'));
    }

    public function testSingleFontAcrossStyledAndPlainCells(): void
    {
        $path = $this->export(ReportExportFixtures::result());

        // Úvod (bez stylu) i datová buňka (se stylem) musí mít stejný font.
        $this->assertStringContainsString('Calibri', $this->cellFontXml($path, 'A2'));
        $this->assertStringContainsString('Calibri', $this->cellFontXml($path, 'B9'));
    }

    // ── List Zprávy ─────────────────────────────────────────────────────────

    public function testMessagesSheetPointsToSpreadsheetRow(): void
    {
        $sheets = $this->read($this->export(ReportExportFixtures::result([ReportExportFixtures::errorMessage()])));

        $this->assertSame(['Report', 'Zprávy'], array_keys($sheets));

        // Stavový řádek je poslední položka úvodu.
        $report = $sheets['Report'];
        $this->assertSame('Status', $report[6][0]->getValue());

        $messages = $sheets['Zprávy'];
        $this->assertSame(
            ['Severity', 'Code', 'Text', 'Row'],
            array_map(static fn ($cell) => $cell->getValue(), $messages[0]),
        );
        $this->assertSame('Chyba', $messages[1][0]->getValue());
        $this->assertSame('journal.accountNotFound', $messages[1][1]->getValue());

        // rowRef rows.1 → skutečné číslo řádku na listu Report; ten řádek
        // nese odkázaný účet.
        $sheetRow = (int) $messages[1][3]->getValue();
        $this->assertSame('504???', $report[$sheetRow - 1][0]->getValue());
    }
}

<?php

declare(strict_types=1);

namespace Shipard\Core\Reports\Export;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\DateTimeCell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Shipard\Core\Reports\ReportStatus;

/**
 * `ReportTable` → XLSX (OpenSpout). List „Report": název, úvodní blok,
 * prázdný řádek, tabulka; při `status != ok` druhý list „Zprávy".
 *
 * - Čísla jako čísla s formátem `#,##0.00` — to je uložený kód; tabulkový
 *   procesor ho zobrazí dle svého locale (česky `1 234,50`). Literál
 *   `# ##0.00` by u milionů vložil jedinou mezeru.
 * - Datum jako datum (ne text).
 * - Odsazení dle `level`: OpenSpout neumí indent stylu buňky, proto
 *   textový formát `"  "@` — mezery jsou jen zobrazení, hodnota buňky
 *   zůstává čistá pro filtry a hledání.
 * - Součtové a dopočtené řádky tučně.
 */
final class ReportXlsxWriter
{
    private const NUMBER_FORMAT = '#,##0.00';
    /** Mezer na úroveň odsazení. */
    private const INDENT_STEP = 2;
    private const WIDTH_ACCOUNT = 16.0;
    private const WIDTH_LABEL = 50.0;
    private const WIDTH_VALUE = 18.0;
    private const FONT_NAME = 'Calibri';
    private const FONT_SIZE = 11;

    /** @var array<string, Style> */
    private array $styles = [];

    public function write(ReportTable $table, ReportExportContext $context, string $path): void
    {
        $this->styles = [];
        $labels = $context->labels;

        // Stejný font pro buňky se stylem i bez něj — default `Style` je
        // Arial, default sešitu Calibri 12; bez sjednocení by se míchaly.
        $writer = new Writer(new Options(FALLBACK_STYLE: $this->baseStyle()));
        $writer->openToFile($path);
        try {
            $sheet = $writer->getCurrentSheet();
            $sheet->setName(self::sheetName($labels->get('sheetReport'), 'Report'));
            $columnCount = count($table->headers);
            for ($i = 0; $i < $columnCount; $i++) {
                $sheet->setColumnWidth($this->columnWidth($table, $i), $i + 1);
            }

            $writer->addRow(new Row([new StringCell($table->title, $this->baseStyle()->withFontBold(true)->withFontSize(14))]));
            foreach ($table->intro as [$label, $value]) {
                $writer->addRow(new Row([new StringCell($label), new StringCell($value)]));
            }
            $writer->addRow(new Row([]));

            $numericColumns = $this->numericColumns($table);
            $headerCells = [];
            foreach ($table->headers as $i => $header) {
                $headerCells[] = new StringCell($header, $this->style(true, isset($numericColumns[$i]) ? 'right' : 'text'));
            }
            $writer->addRow(new Row($headerCells));

            // 1-based číslo prvního datového řádku na listu — cíl sloupce
            // „Řádek" na listu Zprávy.
            $firstDataRow = 1 + count($table->intro) + 1 + 1 + 1;
            foreach ($table->rows as $row) {
                $writer->addRow(new Row($this->cells($row, $table->labelColumn, $context->language)));
            }

            if ($table->status !== ReportStatus::Ok && $table->messages !== []) {
                $this->writeMessages($writer, $table, $labels, $firstDataRow);
            }
        } finally {
            $writer->close();
        }
    }

    private function writeMessages(Writer $writer, ReportTable $table, ReportExportLabels $labels, int $firstDataRow): void
    {
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName(self::sheetName($labels->get('sheetMessages'), 'Messages'));
        $sheet->setColumnWidth(14.0, 1);
        $sheet->setColumnWidth(32.0, 2);
        $sheet->setColumnWidth(90.0, 3);
        $sheet->setColumnWidth(10.0, 4);

        $bold = $this->style(true, 'text');
        $writer->addRow(new Row(array_map(
            static fn (string $header): Cell => new StringCell($header, $bold),
            $table->messageHeaders,
        )));
        foreach ($table->messages as $message) {
            $writer->addRow(new Row([
                new StringCell($message['severityLabel']),
                new StringCell($message['code']),
                new StringCell($message['text']),
                $message['rowIndex'] !== null
                    ? new NumericCell($firstDataRow + $message['rowIndex'])
                    : new EmptyCell(null),
            ]));
        }
    }

    /** @return list<Cell> */
    private function cells(ReportTableRow $row, int $labelColumn, string $language): array
    {
        $bold  = $row->isEmphasized();
        $cells = [];
        foreach ($row->cells as $index => $value) {
            if ($value === null) {
                $cells[] = new EmptyCell(null);
            } elseif (is_float($value)) {
                $cells[] = new NumericCell($value, $this->style($bold, 'number'));
            } elseif ($value instanceof \DateTimeImmutable) {
                $cells[] = new DateTimeCell($value, $this->style($bold, $language === 'cs' ? 'date-cs' : 'date'));
            } elseif ($index === $labelColumn) {
                $cells[] = new StringCell($value, $this->style($bold, 'indent', max(0, $row->level)));
            } else {
                $cells[] = new StringCell($value, $this->style($bold, 'text'));
            }
        }
        return $cells;
    }

    /** Styly se sdílí přes celý sešit — OpenSpout je registruje per instanci. */
    private function style(bool $bold, string $kind, int $level = 0): Style
    {
        $key = ($bold ? 'b' : 'n') . ':' . $kind . ':' . $level;
        if (isset($this->styles[$key])) {
            return $this->styles[$key];
        }

        $style = $this->baseStyle()->withFontBold($bold);
        $style = match ($kind) {
            'number'  => $style->withFormat(self::NUMBER_FORMAT),
            'date'    => $style->withFormat('yyyy-mm-dd'),
            'date-cs' => $style->withFormat('d.m.yyyy'),
            'right'   => $style->withCellAlignment(CellAlignment::RIGHT),
            'indent'  => $level > 0
                ? $style->withFormat('"' . str_repeat(' ', $level * self::INDENT_STEP) . '"@')
                : $style,
            default   => $style,
        };

        return $this->styles[$key] = $style;
    }

    private function baseStyle(): Style
    {
        return new Style(fontSize: self::FONT_SIZE, fontName: self::FONT_NAME);
    }

    /**
     * Sloupce, ve kterých se vyskytuje číslo — jejich hlavička se zarovnává
     * vpravo jako hodnoty pod ní.
     *
     * @return array<int, true>
     */
    private function numericColumns(ReportTable $table): array
    {
        $numeric = [];
        foreach ($table->rows as $row) {
            foreach ($row->cells as $index => $value) {
                if (is_float($value)) {
                    $numeric[$index] = true;
                }
            }
        }
        return $numeric;
    }

    private function columnWidth(ReportTable $table, int $index): float
    {
        if ($index === $table->labelColumn) {
            return self::WIDTH_LABEL;
        }
        return $index < $table->labelColumn ? self::WIDTH_ACCOUNT : self::WIDTH_VALUE;
    }

    /** Excel: max 31 znaků, bez `\ / ? * : [ ]`, ne prázdný. */
    private static function sheetName(string $name, string $fallback): string
    {
        $clean = trim(str_replace(['\\', '/', '?', '*', ':', '[', ']'], '', $name), " '");
        $clean = mb_substr($clean, 0, 31);
        return $clean === '' ? $fallback : $clean;
    }
}

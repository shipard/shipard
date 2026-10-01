<?php

declare(strict_types=1);

namespace Shipard\Core\Reports\Export;

/**
 * `ReportTable` → CSV pro Excel s českým nastavením: UTF-8 s BOM,
 * oddělovač `;`, desetinná čárka, datum `YYYY-MM-DD`, první řádek hlavička.
 * Jen tabulka — úvodní blok, odsazení ani zprávy CSV nenese (stav reportu
 * jde REST hlavičkou `X-Report-Status`).
 */
final class ReportCsvWriter
{
    private const BOM = "\xEF\xBB\xBF";

    public function write(ReportTable $table): string
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Cannot open a temporary stream for the CSV export');
        }

        fwrite($stream, self::BOM);
        $this->putRow($stream, $table->headers);
        foreach ($table->rows as $row) {
            $this->putRow($stream, array_map($this->cell(...), $row->cells));
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return (string) $csv;
    }

    /**
     * @param resource $stream
     * @param list<string> $fields
     */
    private function putRow($stream, array $fields): void
    {
        fputcsv($stream, $fields, ';', '"', '', "\r\n");
    }

    private function cell(string|float|\DateTimeImmutable|null $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_float($value)) {
            return number_format($value, 2, ',', '');
        }
        if ($value instanceof \DateTimeImmutable) {
            return $value->format('Y-m-d');
        }
        return $this->neutralizeFormula($value);
    }

    /**
     * Text začínající `=`, `+`, `-`, `@` (nebo tabulátorem / CR) by tabulkový
     * procesor při otevření CSV vyhodnotil jako vzorec — názvy účtů a čísla
     * dokladů jsou uživatelská data. Apostrof před hodnotou z ní udělá text.
     * Čísla sem nejdou (záporná částka je `float`), XLSX má buňky typované.
     */
    private function neutralizeFormula(string $value): string
    {
        if ($value !== '' && strpbrk($value[0], "=+-@\t\r") !== false) {
            return "'" . $value;
        }
        return $value;
    }
}

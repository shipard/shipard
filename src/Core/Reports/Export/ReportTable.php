<?php

declare(strict_types=1);

namespace Shipard\Core\Reports\Export;

use Shipard\Core\Reports\ReportStatus;

/**
 * `ReportResult` převedený na tabulku — společný mezitvar pro XLSX i CSV,
 * aby se pravidla převodu mezi formáty nelišila (docs/reports.md §15).
 * Staví výhradně `ReportTabularizer`.
 */
final class ReportTable
{
    /**
     * @param list<array{0: string, 1: string}> $intro Úvodní blok — dvojice
     *        popisek / hodnota (jen XLSX).
     * @param list<string> $headers Hlavičky sloupců tabulky.
     * @param int $labelColumn Index sloupce s popiskem řádku (0-based) —
     *        cíl odsazení dle `level`.
     * @param list<ReportTableRow> $rows
     * @param list<string> $messageHeaders Hlavičky listu Zprávy.
     * @param list<array{severity: string, severityLabel: string, code: string, text: string, rowIndex: ?int}> $messages
     *        `rowIndex` = index do `$rows` (z `rowRef`), null bez vazby.
     */
    public function __construct(
        public readonly string $title,
        public readonly array $intro,
        public readonly array $headers,
        public readonly int $labelColumn,
        public readonly array $rows,
        public readonly ReportStatus $status,
        public readonly array $messageHeaders,
        public readonly array $messages,
    ) {}
}

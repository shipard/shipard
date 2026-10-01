<?php

declare(strict_types=1);

namespace Shipard\Core\Reports\Export;

use Shipard\Core\Reports\ReportRowKind;

/**
 * Řádek tabulky exportu. Typ buňky nese PHP typ hodnoty: `string` = text,
 * `float` = číslo, `DateTimeImmutable` = datum, `null` = prázdná buňka.
 */
final class ReportTableRow
{
    /** @param list<string|float|\DateTimeImmutable|null> $cells */
    public function __construct(
        public readonly ReportRowKind $kind,
        public readonly int $level,
        public readonly array $cells,
    ) {}

    /** Součtové a dopočtené řádky se v XLSX sází tučně. */
    public function isEmphasized(): bool
    {
        return $this->kind !== ReportRowKind::Detail;
    }
}

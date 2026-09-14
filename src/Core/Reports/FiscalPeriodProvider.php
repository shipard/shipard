<?php

declare(strict_types=1);

namespace Shipard\Core\Reports;

/**
 * Zdroj fiskálních období pro `ReportParamValidator` — oddělený od DB kvůli
 * unit testům (in-memory fake). Produkční implementace:
 * `DbFiscalPeriodProvider`.
 */
interface FiscalPeriodProvider
{
    /** @return array{id: int, name: string}|null */
    public function findYearByName(string $name): ?array;

    /**
     * Všechny fiskální měsíce roku seřazené dle `date_begin`
     * (otevírací období první, uzavírací poslední).
     *
     * @return list<array{id: int, periodType: int}>
     */
    public function monthsOfYear(int $fiscalYearId): array;

    /**
     * Fiskální roky s počtem běžných měsíců (`period_type` 1) — data pro
     * picker období v katalogu reportů. Řazené dle `name`.
     *
     * @return list<array{name: string, months: int}>
     */
    public function regularYears(): array;

    /**
     * Fiskální rok obsahující datum (`date_begin <= date <= date_end`,
     * nesmazaný). Otevírací / uzavírací období jsou měsíce, ne roky —
     * rok se vrací i pro 1. 1., které je zároveň datem otevíracího měsíce.
     * Při překryvu vítězí rok s pozdějším začátkem.
     *
     * @return array{id: int, name: string}|null
     */
    public function yearForDate(string $date): ?array;

    /**
     * Všechny nesmazané fiskální roky, nejnovější první (`date_begin DESC`)
     * — options filtru období ve viewerech a fallback „aktuální rok"
     * ({@see \Shipard\Core\Viewer\FiscalYearFilter}).
     *
     * @return list<array{id: int, name: string}>
     */
    public function years(): array;
}

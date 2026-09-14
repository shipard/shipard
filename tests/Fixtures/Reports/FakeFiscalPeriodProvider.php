<?php

declare(strict_types=1);

namespace Shipard\Tests\Fixtures\Reports;

use Shipard\Core\Reports\FiscalPeriodProvider;

/**
 * In-memory provider fiskálních roků pro testy filtru období viewerů
 * (`FiscalYearFilter`, deník, saldokonto): roky v zadaném pořadí
 * (nejnovější první, jako `DbFiscalPeriodProvider::years()`), rok pro
 * datum z mapy `Y-m-d` → rok. Reportové metody vrací prázdno.
 */
final class FakeFiscalPeriodProvider implements FiscalPeriodProvider
{
    /**
     * @param list<array{id: int, name: string}> $years
     * @param array<string, array{id: int, name: string}> $byDate
     */
    public function __construct(private readonly array $years = [], private readonly array $byDate = []) {}

    public function findYearByName(string $name): ?array
    {
        foreach ($this->years as $y) {
            if ($y['name'] === $name) {
                return $y;
            }
        }
        return null;
    }

    public function monthsOfYear(int $fiscalYearId): array
    {
        return [];
    }

    public function regularYears(): array
    {
        return [];
    }

    public function yearForDate(string $date): ?array
    {
        return $this->byDate[$date] ?? null;
    }

    public function years(): array
    {
        return $this->years;
    }
}

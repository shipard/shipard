<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\WorkOrders\Import;

use Shipard\Module\Economy\WorkOrders\Import\WorkOrderImportVerifier;

/** Verifier nad pamětí: DB dotazy nahrazují pole. */
class TestableWorkOrderImportVerifier extends WorkOrderImportVerifier
{
    /** @var array<int, array<string, mixed>> řady zakázek podle id */
    public array $series = [];
    /** @var array<int, array<string, mixed>> druhy podle id */
    public array $kinds = [];
    /** @var array<int, array<string, mixed>> řady dokladů podle id */
    public array $docSeries = [];
    /** @var array<string, list<int>> tabulka → existující id */
    public array $references = [];
    /** @var array<string, int> kód jednotky → id */
    public array $units = [];
    /** @var list<string> */
    public array $vatCodes = [];
    /** @var array<string, array<string, mixed>> číslo → zakázka */
    public array $workOrders = [];
    public ?int $fiscalYearId = 26;
    /** @var list<?string> typy dokladů, pro které se ptal na kódy DPH */
    public array $vatCodeQueries = [];

    protected function loadSeries(int $seriesId): ?array
    {
        return $this->series[$seriesId] ?? null;
    }

    protected function loadKind(int $kindId): ?array
    {
        return $this->kinds[$kindId] ?? null;
    }

    protected function loadDocSeries(int $seriesId): ?array
    {
        return $this->docSeries[$seriesId] ?? null;
    }

    protected function referenceExists(string $table, int $id): bool
    {
        return in_array($id, $this->references[$table] ?? [], true);
    }

    protected function resolveUnit(string $unit): ?int
    {
        return $this->units[$unit] ?? null;
    }

    protected function vatCodes(?string $docType): array
    {
        $this->vatCodeQueries[] = $docType;
        return $this->vatCodes;
    }

    protected function findWorkOrder(string $number): ?array
    {
        return $this->workOrders[$number] ?? null;
    }

    protected function fiscalYearIdForDate(string $date): ?int
    {
        return $this->fiscalYearId;
    }
}

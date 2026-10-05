<?php

declare(strict_types=1);

namespace Shipard\Tests\Fixtures\Module\Docs\Core;

use Shipard\Module\Docs\Core\DocsHeadsDocument;

/**
 * Test-only subclass that exposes DocDocument's protected methods as public,
 * so unit tests can drive each method in isolation without resorting to
 * reflection.
 */
class TestableDocsHeadsDocument extends DocsHeadsDocument
{
    /** @var array<int, array{sql: string, args: array}> */
    public array $executedSql = [];

    protected function executeSql(mixed ...$args): void
    {
        $this->executedSql[] = [
            'sql'  => (string) ($args[0] ?? ''),
            'args' => array_slice($args, 1),
        ];
    }

    public function applyAuthorDefaultPub(array &$data, ?array $originalData): void
    {
        $this->applyAuthorDefault($data, $originalData);
    }

    public function applyDateDefaultsPub(array &$data): void
    {
        $this->applyDateDefaults($data);
    }

    public function applyHomeCurrencyPub(array &$data): void
    {
        $this->applyHomeCurrency($data);
    }

    public function resolveAccountingPeriodsPub(array &$data): void
    {
        $this->resolveAccountingPeriods($data);
    }

    public function resolveFiscalYearIdPub(string $accountingDate): ?int
    {
        return $this->resolveFiscalYearId($accountingDate);
    }

    public function resolveFiscalMonthIdPub(string $accountingDate): ?int
    {
        return $this->resolveFiscalMonthId($accountingDate);
    }


    public function calculateRowPricePub(array &$row): void
    {
        $this->calculateRowPrice($row);
    }

    /** @param array<string, array<string, mixed>>|null $vatCodes */
    public function calculateRowVatPub(array &$row, int $vatMode, ?array $vatCodes = null): void
    {
        $this->calculateRowVat($row, $vatMode, $vatCodes);
    }

    /**
     * @param array<int, array<string, mixed>> $rowsOverride
     * @return array<int, array<string, mixed>>
     */
    public function buildVatRecapitulationPub(array &$data, array $rowsOverride = []): array
    {
        return $this->buildVatRecapitulation($data, $rowsOverride);
    }

    public function sumTotalsPub(array &$data, array $recap, array $rows = []): void
    {
        $this->sumTotals($data, $recap, $rows);
    }

    public function applyTotalRoundingPub(array &$data): void
    {
        $this->applyTotalRounding($data);
    }

    public function applyRoundingPub(float $amount, int $mode): float
    {
        return $this->applyRounding($amount, $mode);
    }

    /** @param array<string, array<string, mixed>>|null $vatCodes */
    public function applyDomesticAmountsPub(
        array &$data,
        array &$rows,
        array $recap,
        ?array $vatCodes = null,
        ?float $tolerance = null,
    ): void {
        $this->applyDomesticAmounts($data, $rows, $recap, $vatCodes, $tolerance);
    }

    /** @param array<int, array<string, mixed>> $recap */
    public function reconcileRowsToRecapPub(
        array &$rows,
        array $recap,
        string $suffix = '',
        ?float $tolerance = null,
    ): void {
        $this->reconcileRowsToRecap($rows, $recap, $suffix, $tolerance);
    }

    /** @param array<string, mixed>|null $originalData */
    public function useDeclaredRecapPub(array $data, ?array $originalData): bool
    {
        return $this->useDeclaredRecap($data, $originalData);
    }

    /** @return array<int, array<string, mixed>> */
    public function takeOverVatRecapitulationPub(array &$data): array
    {
        return $this->takeOverVatRecapitulation($data);
    }

    /** @return array<int, array<string, mixed>> */
    public function getComputedRows(): array
    {
        return $this->computedRows;
    }

    /** @param array<int, array<string, mixed>> $rows */
    public function setComputedRows(array $rows): void
    {
        $this->computedRows = $rows;
    }

    public function processStateTransitionPub(array &$data, ?array $originalData): void
    {
        $this->processStateTransition($data, $originalData);
    }

    public function assignDocumentNumberPub(array &$data): void
    {
        $this->assignDocumentNumber($data);
    }

    /** @param array<string, mixed> $importNumber */
    public function applyImportNumberPub(array &$data, array $importNumber): void
    {
        $this->applyImportNumber($data, $importNumber);
    }

    public function numberSeriesResetScopePub(int $seriesId): string
    {
        return $this->numberSeriesResetScope($seriesId);
    }

    public function beforeSavePub(array &$data, ?array $originalData = null): void
    {
        $this->beforeSave($data, $originalData);
    }

    public function releaseDocumentNumberPub(array &$data, ?array $originalData): void
    {
        $this->releaseDocumentNumber($data, $originalData);
    }

    /** @param array<string, mixed> $series */
    public function resolvePatternPub(string $pattern, array $data, array $series): string
    {
        return $this->resolvePattern($pattern, $data, $series);
    }

    public function maintainSnapshotsPub(array &$data, ?array $originalData): void
    {
        $this->maintainSnapshots($data, $originalData);
    }

    public function buildSnapshotsPub(array &$data): void
    {
        $this->buildSnapshots($data);
    }

    /** @return array<string, mixed> */
    public function buildPersonSnapshotPub(int $personId, mixed $addressId, mixed $bankAccountId, mixed $vatRegistrationId): array
    {
        return $this->buildPersonSnapshot($personId, $addressId, $bankAccountId, $vatRegistrationId);
    }

    public function applyPaymentReferenceDefaultPub(array &$data): void
    {
        $this->applyPaymentReferenceDefault($data);
    }

    public function trackStateChangePub(array &$data, ?array $originalData): void
    {
        $this->trackStateChange($data, $originalData);
    }
}

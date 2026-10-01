<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Assets\Reports;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Reports\FiscalRange;
use Shipard\Core\Reports\ReportRequest;
use Shipard\Core\Reports\ReportResult;
use Shipard\Tests\Unit\Module\Economy\Assets\TestAssetPlanService;

/**
 * Společný základ testů reportů majetku: karty a události v paměti,
 * požadavek reportu nad kalendářními roky 2021–2026 (`TestAssetPlanService`:
 * rok N má id N − 2020, měsíc M roku N id 100 + 12 × (N − 2021) + M).
 */
abstract class AssetReportTestCase extends TestCase
{
    protected TestAssetPlanService $plans;
    protected TestAssetReportSupport $support;
    private int $eventId = 1;

    protected function setUp(): void
    {
        $this->plans = new TestAssetPlanService();
        $this->support = new TestAssetReportSupport($this->plans);
    }

    /** @param array<string, mixed> $o */
    protected function card(int $id, array $o = []): void
    {
        $this->plans->cards[$id] = $o + [
            'id' => $id, 'asset_number' => sprintf('MA%04d', $id), 'name' => "Stroj {$id}", 'category' => 'tangible',
            'tax_method' => 'straight', 'tax_rule' => 'cz-2', 'acc_method' => 'time', 'acc_months' => 60,
            'docState' => 40, 'accounting_group' => 1, 'group_code' => '022', 'group_name' => 'Stroje', 'group_order' => 10,
            'asset_type' => null, 'type_name' => null, 'is_foreign' => 0, 'owner' => null, 'owner_name' => null,
            'acquired_date' => null, 'disposed_date' => null, 'price' => null,
        ];
    }

    /** @param array<string, mixed> $o */
    protected function event(int $asset, string $kind, string $date, array $o = []): int
    {
        $id = $this->eventId++;
        $this->plans->events[] = $o + [
            'id' => $id, 'asset' => $asset, 'event_kind' => $kind, 'scope' => 'both', 'event_date' => $date,
            'amount' => 0, 'origin' => 'manual', 'half_year' => 0, 'docState' => 40, 'doc_head' => null,
        ];
        return $id;
    }

    protected function depreciated(int $asset, string $scope, int $year, float $amount): int
    {
        return $this->event($asset, 'depreciation', "{$year}-12-31", [
            'scope' => $scope, 'amount' => $amount, 'origin' => 'system',
            'period_begin' => "{$year}-01-01", 'period_end' => "{$year}-12-31",
        ]);
    }

    /** @param array<string, mixed> $params */
    protected function request(string $reportId, int $year, array $params = [], int $monthFrom = 1, int $monthTo = 12, string $language = 'cs'): ReportRequest
    {
        $base = 100 + 12 * ($year - 2021);
        $range = new FiscalRange(
            fiscalYearId: $year - 2020,
            fiscalYear: (string) $year,
            monthFrom: $monthFrom,
            monthTo: $monthTo,
            monthIdsBefore: $monthFrom > 1 ? range($base + 1, $base + $monthFrom - 1) : [],
            monthIdsInRange: range($base + $monthFrom, $base + $monthTo),
        );

        return new ReportRequest(
            reportId: $reportId,
            range: $range,
            params: ['period' => $range->toParamsArray()] + $params,
            db: $this->createMock(DataSourceConnection::class),
            config: TestAssetPlanService::config(),
            dataSource: 'test-ds-id',
            language: $language,
        );
    }

    /** @return array<string, array<string, mixed>> key řádku → řádek jako pole */
    protected function rowsByKey(ReportResult $result): array
    {
        $out = [];
        foreach ($result->rows as $row) {
            if ($row->key !== null) {
                $out[$row->key] = $row->toArray();
            }
        }
        return $out;
    }

    /** @return list<?string> klíče řádků v pořadí výsledku (null = řádek bez klíče) */
    protected function keys(ReportResult $result): array
    {
        return array_map(static fn($row): ?string => $row->key, $result->rows);
    }

    /** @return list<string> kódy zpráv */
    protected function codes(ReportResult $result): array
    {
        return array_map(static fn($message): string => $message->code, $result->messages);
    }

    /** @param array<string, mixed> $row */
    protected function balance(array $row, string $column): float
    {
        return (float) $row['values'][$column]['balance'];
    }
}

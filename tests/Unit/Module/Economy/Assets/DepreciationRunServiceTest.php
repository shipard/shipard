<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Assets;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Assets\DepreciationRunService;

/**
 * „Odpisy za období“ (D33): náhled s vyloučenými kartami a důvody,
 * idempotence, měsíční četnost účetních odpisů, provedení přes zapisovač.
 */
class DepreciationRunServiceTest extends TestCase
{
    private TestAssetPlanService $plans;
    private SpyDepreciationWriter $writer;
    private TestableDepreciationRunService $service;

    protected function setUp(): void
    {
        $this->plans = new TestAssetPlanService();
        $this->writer = new SpyDepreciationWriter();
        $this->service = new TestableDepreciationRunService($this->plans, $this->writer);
    }

    /** @param array<string, mixed> $o */
    private function card(int $id, array $o = []): void
    {
        $this->plans->cards[$id] = $o + [
            'id' => $id, 'asset_number' => sprintf('MA%04d', $id), 'name' => "Stroj {$id}", 'category' => 'tangible',
            'tax_method' => 'straight', 'tax_rule' => 'cz-2', 'acc_method' => 'as_tax', 'acc_months' => null, 'docState' => 40,
        ];
    }

    /** @param array<string, mixed> $o */
    private function event(int $asset, string $kind, string $date, array $o = []): void
    {
        static $id = 500;
        $this->plans->events[] = $o + [
            'id' => $id++, 'asset' => $asset, 'event_kind' => $kind, 'scope' => 'both', 'event_date' => $date,
            'amount' => 0, 'origin' => 'manual', 'half_year' => 0, 'docState' => 40,
        ];
    }

    private function depreciated(int $asset, string $scope, int $year, float $amount): void
    {
        $this->event($asset, 'depreciation', "{$year}-12-31", [
            'scope' => $scope, 'amount' => $amount, 'period_begin' => "{$year}-01-01", 'period_end' => "{$year}-12-31",
        ]);
    }

    /** @return array<int, string> id → důvod */
    private function excluded(array $preview): array
    {
        return array_column($preview['excluded'], 'reason', 'id');
    }

    // Kalendářní rok 2024 má v TestAssetPlanService id 4.
    private const YEAR_2024 = 4;

    public function testPreviewListsPlannedDepreciationsAndExclusions(): void
    {
        $this->card(1);                                        // zařazeno 2022, 2022–2023 odepsáno → 2024 plán 22 250
        $this->event(1, 'activation', '2022-03-15', ['amount' => 100000]);
        $this->depreciated(1, 'tax', 2022, 11000.0);
        $this->depreciated(1, 'tax', 2023, 22250.0);
        $this->card(2);                                        // nezařazeno
        $this->card(3);                                        // vyřazeno
        $this->event(3, 'activation', '2022-03-15', ['amount' => 50000]);
        $this->event(3, 'disposal', '2023-06-30');
        $this->card(4);                                        // 2024 už odepsáno
        $this->event(4, 'activation', '2022-03-15', ['amount' => 100000]);
        $this->depreciated(4, 'tax', 2022, 11000.0);
        $this->depreciated(4, 'tax', 2023, 22250.0);
        $this->depreciated(4, 'tax', 2024, 22250.0);
        $this->card(5);                                        // chybí 2023
        $this->event(5, 'activation', '2022-03-15', ['amount' => 100000]);
        $this->depreciated(5, 'tax', 2022, 11000.0);
        $this->card(6, ['tax_method' => 'none', 'tax_rule' => null]); // chybné nastavení (as_tax bez vzorce)
        $this->event(6, 'activation', '2022-03-15', ['amount' => 100000]);
        $this->card(7, ['category' => 'nondepreciable', 'tax_method' => null, 'acc_method' => null]);
        $this->card(8, ['docState' => 80]);                    // mimo V pořádku → není kandidát
        $this->event(8, 'activation', '2022-03-15', ['amount' => 100000]);

        $preview = $this->service->preview('tax', self::YEAR_2024);

        $this->assertSame('2024', $preview['period']['name']);
        $this->assertSame([1], array_column($preview['assets'], 'id'));
        $this->assertSame(22250.0, $preview['assets'][0]['amount']);
        $this->assertSame('100 000,00 × 22,25 %', $preview['assets'][0]['formula']);
        $this->assertSame(22250.0, $preview['total']);
        $this->assertSame([
            2 => DepreciationRunService::REASON_NOT_ACTIVATED,
            3 => DepreciationRunService::REASON_DISPOSED,
            4 => DepreciationRunService::REASON_ALREADY_DONE,
            5 => DepreciationRunService::REASON_EARLIER_MISSING,
        ], $this->excluded($preview));
        $this->assertSame('2023-01-01 – 2023-12-31', $preview['excluded'][3]['detail']);
    }

    public function testPlanErrorExcludesCardWithText(): void
    {
        $this->card(6, ['tax_method' => 'none', 'tax_rule' => null]);
        $this->event(6, 'activation', '2022-03-15', ['amount' => 100000]);

        $preview = $this->service->preview('acc', self::YEAR_2024);
        $this->assertSame([6 => DepreciationRunService::REASON_PLAN_ERROR], $this->excluded($preview));
        $this->assertStringContainsString('tax method', $preview['excluded'][0]['detail']);
    }

    public function testCardsWithNothingToDepreciateAreSkippedSilently(): void
    {
        $this->card(1);                                        // zařazeno až 2025
        $this->event(1, 'activation', '2025-02-01', ['amount' => 100000]);
        $this->card(2);                                        // přerušeno 2024
        $this->event(2, 'activation', '2022-03-15', ['amount' => 100000]);
        $this->depreciated(2, 'tax', 2022, 11000.0);
        $this->depreciated(2, 'tax', 2023, 22250.0);
        $this->event(2, 'interruption', '2024-12-31', ['scope' => 'tax', 'period_begin' => '2024-01-01', 'period_end' => '2024-12-31']);

        $preview = $this->service->preview('tax', self::YEAR_2024);
        $this->assertSame([], $preview['assets']);
        $this->assertSame([], $preview['excluded']);
    }

    public function testLockedMonthExcludesCard(): void
    {
        $this->plans->lockedMonths = [202412];
        $this->card(1);
        $this->event(1, 'activation', '2022-03-15', ['amount' => 100000]);
        $this->depreciated(1, 'tax', 2022, 11000.0);
        $this->depreciated(1, 'tax', 2023, 22250.0);

        $preview = $this->service->preview('tax', self::YEAR_2024);
        $this->assertSame([1 => DepreciationRunService::REASON_MONTH_LOCKED], $this->excluded($preview));
        $this->assertSame('2024/12', $preview['excluded'][0]['detail']);
    }

    public function testMonthlyAccountingPeriodicityUsesFiscalMonths(): void
    {
        $this->plans->periodicity = 'month';
        $this->card(1, ['acc_method' => 'time', 'acc_months' => 60]);
        $this->event(1, 'activation', '2024-03-15', ['amount' => 60000]);

        $options = $this->service->options();
        $this->assertSame('month', $options['accPeriodicity']);
        $this->assertNotSame([], $options['months']);

        // Duben 2024 = id 100 + 3·12 + 4 = 140: 60 000 / 60 = 1 000.
        $april = $this->service->preview('acc', 140);
        $this->assertSame('month', $april['period']['kind']);
        $this->assertSame([1000.0], array_column($april['assets'], 'amount'));

        // Květen bez dubna: dřívější období chybí.
        $this->assertSame([1 => DepreciationRunService::REASON_EARLIER_MISSING], $this->excluded($this->service->preview('acc', 141)));

        // Daňový okruh je i při měsíční četnosti roční.
        $this->assertSame('year', $this->service->preview('tax', self::YEAR_2024)['period']['kind']);
        $this->expectException(\InvalidArgumentException::class);
        $this->service->preview('acc', self::YEAR_2024);
    }

    public function testRunWritesPreviewedRowsAndIsIdempotent(): void
    {
        $this->card(1);
        $this->event(1, 'activation', '2022-03-15', ['amount' => 100000]);
        $this->depreciated(1, 'tax', 2022, 11000.0);
        $this->depreciated(1, 'tax', 2023, 22250.0);
        $this->card(2);
        $this->event(2, 'activation', '2023-11-01', ['amount' => 50000]);
        $this->depreciated(2, 'tax', 2023, 5500.0);

        $result = $this->service->run('tax', self::YEAR_2024);

        $this->assertSame(['count' => 2, 'total' => 33375.0, 'assetIds' => [1, 2]], $result);
        $this->assertSame([22250.0, 11125.0], array_column($this->writer->written['tax'], 'amount'));
        $this->assertSame(['begin', 'commit'], $this->service->transaction);
        $this->assertSame([null], $this->service->locked);

        // Druhý běh nad tímtéž stavem: zapsané odpisy jsou teď potvrzené → nic.
        foreach ($this->writer->written['tax'] as $i => $row) {
            $this->event($i + 1, 'depreciation', $row['date'], [
                'scope' => 'tax', 'amount' => $row['amount'], 'origin' => 'system',
                'period_begin' => $row['period']['begin'], 'period_end' => $row['period']['end'],
            ]);
        }
        $this->writer->written = [];
        $this->assertSame(0, $this->service->run('tax', self::YEAR_2024)['count']);
        $this->assertSame([], $this->writer->written);
    }

    public function testRunForSingleAssetLocksOnlyThatCard(): void
    {
        $this->card(1);
        $this->event(1, 'activation', '2022-03-15', ['amount' => 100000]);
        $this->card(2);
        $this->event(2, 'activation', '2022-03-15', ['amount' => 100000]);

        $result = $this->service->run('tax', 2, 2);
        $this->assertSame([2], $result['assetIds']);
        $this->assertSame([2], $this->service->locked);
    }

    public function testUnknownScopeOrPeriodIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->preview('both', self::YEAR_2024);
    }
}

class TestableDepreciationRunService extends DepreciationRunService
{
    /** @var list<string> */
    public array $transaction = [];
    /** @var list<int|null> */
    public array $locked = [];

    public function __construct(private readonly TestAssetPlanService $plans, SpyDepreciationWriter $writer)
    {
        parent::__construct($plans, $writer, null, new \Shipard\Module\Economy\Assets\Depreciation\PlanMessageTexts(TestAssetPlanService::config()));
    }

    public function run(string $scope, int $periodId, ?int $assetId = null): array
    {
        $this->transaction[] = 'begin';
        $result = parent::run($scope, $periodId, $assetId);
        $this->transaction[] = 'commit';
        return $result;
    }

    protected function loadCandidateCards(?int $assetId): array
    {
        $cards = [];
        foreach ($this->plans->cards as $card) {
            if ((int) $card['docState'] === 40 && ($assetId === null || (int) $card['id'] === $assetId)) {
                $cards[] = $card;
            }
        }
        return $cards;
    }

    protected function lockCards(?int $assetId): void
    {
        $this->locked[] = $assetId;
    }
}

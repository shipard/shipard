<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Assets;

use PHPUnit\Framework\TestCase;

/**
 * Hromadné načítání plánů (docs/assets.md D71): `plansFor` dává totéž co
 * plán per karta a na data sahá jednou za typ, ne po kartách.
 */
class AssetPlanServiceBulkTest extends TestCase
{
    private TestAssetPlanService $plans;
    private int $eventId = 1;

    protected function setUp(): void
    {
        $this->plans = new TestAssetPlanService();
    }

    /** @param array<string, mixed> $o */
    private function card(int $id, array $o = []): void
    {
        $this->plans->cards[$id] = $o + [
            'id' => $id, 'asset_number' => sprintf('MA%04d', $id), 'name' => "Stroj {$id}", 'category' => 'tangible',
            'tax_method' => 'straight', 'tax_rule' => 'cz-2', 'acc_method' => 'time', 'acc_months' => 60, 'docState' => 40,
        ];
    }

    /** @param array<string, mixed> $o */
    private function event(int $asset, string $kind, string $date, array $o = []): void
    {
        $this->plans->events[] = $o + [
            'id' => $this->eventId++, 'asset' => $asset, 'event_kind' => $kind, 'scope' => 'both', 'event_date' => $date,
            'amount' => 0, 'origin' => 'manual', 'half_year' => 0, 'docState' => 40,
        ];
    }

    public function testPlansForMatchesPlanPerCard(): void
    {
        $this->card(1);
        $this->event(1, 'activation', '2022-03-15', ['amount' => 100000]);
        $this->card(2, ['tax_method' => 'accelerated', 'acc_method' => 'as_tax', 'acc_months' => null]);
        $this->event(2, 'activation', '2023-06-01', ['amount' => 250000]);
        $this->event(2, 'improvement', '2024-02-10', ['amount' => 50000]);
        $this->card(3); // bez událostí

        $bulk = $this->plans->plansFor([1, 2, 3, 99]);

        self::assertSame([1, 2, 3], array_keys($bulk));
        foreach ([1, 2, 3] as $id) {
            $single = $this->plans->planForAsset($id);
            self::assertNotNull($single);
            self::assertSame($this->plans->cards[$id], $bulk[$id]['card']);
            self::assertSame($single['tax']->toArray(), $bulk[$id]['tax']->toArray());
            self::assertSame($single['acc']->toArray(), $bulk[$id]['acc']->toArray());
        }
    }

    public function testPlansForLoadsEachDataTypeOnce(): void
    {
        for ($id = 1; $id <= 25; $id++) {
            $this->card($id);
            $this->event($id, 'activation', '2022-03-15', ['amount' => 100000 + $id]);
        }

        $this->plans->plansFor(range(1, 25));

        self::assertSame(['cards' => 1, 'events' => 1, 'years' => 1, 'months' => 0], $this->plans->loads);
    }

    public function testPlansForMonthlyLoadsMonthsOnce(): void
    {
        $this->plans->periodicity = 'month';
        for ($id = 1; $id <= 5; $id++) {
            $this->card($id);
            $this->event($id, 'activation', '2022-03-15', ['amount' => 100000]);
        }

        $this->plans->plansFor(range(1, 5));

        self::assertSame(1, $this->plans->loads['months']);
        self::assertSame(1, $this->plans->loads['years']);
    }

    public function testPlansOfKeepsExtraCardColumns(): void
    {
        $this->card(1);
        $this->event(1, 'activation', '2022-03-15', ['amount' => 100000]);

        $bulk = $this->plans->plansOf([$this->plans->cards[1] + ['group_name' => 'Stroje']]);

        self::assertSame('Stroje', $bulk[1]['card']['group_name']);
        self::assertSame(0, $this->plans->loads['cards']);
        self::assertSame(100000.0, $bulk[1]['tax']->entryPrice);
    }

    public function testAsOfIsPassedToPlans(): void
    {
        $this->card(1);
        $this->event(1, 'activation', '2022-03-15', ['amount' => 100000]);

        $bulk2023 = $this->plans->plansFor([1], '2023-12-31');
        $single = $this->plans->plan($this->plans->cards[1], $this->plans->confirmedEvents(1), '2023-12-31');

        self::assertSame($single['tax']->currentYearAmount, $bulk2023[1]['tax']->currentYearAmount);
    }

    /** Hrubá horní mez — 1 000 karet s ~20 událostmi; hlídá řádové zhoršení, ne čas. */
    public function testThousandCardsWithinRoughBound(): void
    {
        for ($id = 1; $id <= 1000; $id++) {
            $this->card($id, ['acc_method' => 'as_tax', 'acc_months' => null]);
            $this->event($id, 'opening', '2021-01-01', [
                'scope' => 'tax', 'amount' => 500000, 'accumulated' => 0, 'units_done' => 0, 'original_date' => '2021-01-01',
            ]);
            $this->event($id, 'opening', '2021-01-01', [
                'scope' => 'acc', 'amount' => 500000, 'accumulated' => 0, 'units_done' => 0, 'original_date' => '2021-01-01',
            ]);
            // ~18 dalších událostí: technická zhodnocení rozložená do let.
            for ($i = 0; $i < 18; $i++) {
                $this->event($id, 'improvement', sprintf('%04d-%02d-15', 2021 + intdiv($i, 4), 2 + ($i % 4) * 3), ['amount' => 1000]);
            }
        }

        $start = microtime(true);
        $bulk = $this->plans->plansFor(range(1, 1000));
        $elapsed = microtime(true) - $start;

        self::assertCount(1000, $bulk);
        self::assertSame(1, $this->plans->loads['cards']);
        self::assertSame(1, $this->plans->loads['events']);
        self::assertLessThan(20.0, $elapsed, 'plansFor nad 1 000 kartami trvá řádově déle, než má');
    }
}

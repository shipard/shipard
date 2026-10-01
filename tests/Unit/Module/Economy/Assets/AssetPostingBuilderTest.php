<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Assets;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Assets\Depreciation\Plan;
use Shipard\Module\Economy\Assets\Posting\AssetPostingBuilder;
use Shipard\Module\Economy\Assets\Posting\AssetPostingInput;
use Shipard\Module\Economy\Assets\Posting\AssetPostingResult;
use Shipard\Module\Economy\Assets\Posting\AssetPostingRow;

/**
 * Řádky účetního dokladu z událostí majetku (docs/assets.md D49). Plán
 * účetního okruhu pro vyřazení počítá skutečný engine nad
 * TestAssetPlanService (sk. 2 rovnoměrně, účetní `as_tax`).
 */
class AssetPostingBuilderTest extends TestCase
{
    private const ASSET = 5;

    private const ACCOUNTS = [
        'asset' => 22, 'acquisition' => 42, 'accumulated' => 82, 'depreciation' => 551, 'disposal' => 541,
    ];

    private const CARD = [
        'id' => self::ASSET, 'name' => 'Soustruh', 'category' => 'tangible',
        'tax_method' => 'straight', 'tax_rule' => 'cz-2', 'acc_method' => 'as_tax', 'acc_months' => null,
    ];

    /** @param array<string, mixed> $overrides */
    private function event(int $id, string $kind, string $date, float $amount = 0.0, array $overrides = []): array
    {
        return $overrides + [
            'id' => $id, 'asset' => self::ASSET, 'event_kind' => $kind,
            'scope' => $kind === 'depreciation' ? 'acc' : 'both',
            'event_date' => $date, 'amount' => $amount, 'half_year' => 0, 'docState' => 40,
        ];
    }

    private function depreciation(int $id, int $year, float $amount, ?string $date = null): array
    {
        return $this->event($id, 'depreciation', $date ?? "{$year}-12-31", $amount, [
            'period_begin' => "{$year}-01-01", 'period_end' => $date ?? "{$year}-12-31",
        ]);
    }

    /**
     * @param list<array<string, mixed>> $history všechny potvrzené události karty
     * @param array<string, mixed> $card
     */
    private function accPlan(array $history, array $card = self::CARD): Plan
    {
        return (new TestAssetPlanService())->plan($card, $history)['acc'];
    }

    /**
     * @param list<array<string, mixed>> $events
     * @param array<string, int|null> $accounts
     */
    private function build(array $events, ?Plan $plan = null, array $accounts = self::ACCOUNTS): AssetPostingResult
    {
        return (new AssetPostingBuilder())->build(
            new AssetPostingInput(self::ASSET, 'MA0005', 'Soustruh', $accounts, $events, $plan),
        );
    }

    /** @return list<array{string, int, int, float}> [operace, účet, strana, částka] */
    private function compact(AssetPostingResult $result): array
    {
        return array_map(
            static fn(AssetPostingRow $r): array => [$r->operation, $r->account, $r->side, $r->amount],
            $result->rows,
        );
    }

    // --- tabulka D49 ---------------------------------------------------------

    public function testActivationAndImprovementDebitAssetCreditAcquisition(): void
    {
        $result = $this->build([
            $this->event(1, 'activation', '2022-03-15', 100000.0),
            $this->event(2, 'improvement', '2022-06-01', 20000.0),
        ]);

        $this->assertTrue($result->isOk());
        $this->assertSame([
            ['asset.activation', 22, 0, 100000.0],
            ['asset.activation', 42, 1, 100000.0],
            ['asset.improvement', 22, 0, 20000.0],
            ['asset.improvement', 42, 1, 20000.0],
        ], $this->compact($result));
        $this->assertSame([1, 2], $result->eventIds);
    }

    public function testReductionIsReversedActivation(): void
    {
        $result = $this->build([$this->event(3, 'reduction', '2022-09-01', 5000.0)]);

        $this->assertSame([
            ['asset.reduction', 42, 0, 5000.0],
            ['asset.reduction', 22, 1, 5000.0],
        ], $this->compact($result));
    }

    public function testAccountingDepreciationDebitsCostCreditsAccumulated(): void
    {
        $result = $this->build([$this->depreciation(4, 2022, 11000.0)]);

        $this->assertSame([
            ['asset.depreciation', 551, 0, 11000.0],
            ['asset.depreciation', 82, 1, 11000.0],
        ], $this->compact($result));
        $this->assertSame('Odpis MA0005 Soustruh (1. 1. 2022 – 31. 12. 2022)', $result->rows[0]->description);
        $this->assertSame(self::ASSET, $result->rows[0]->assetId);
    }

    public function testOpeningInterruptionAndTaxCircuitAreNotPosted(): void
    {
        $result = $this->build([
            $this->event(10, 'opening', '2023-01-01', 100000.0, ['scope' => 'acc', 'accumulated' => 30000]),
            $this->event(11, 'interruption', '2023-06-01', 0.0, ['scope' => 'tax']),
            $this->depreciation(12, 2023, 22250.0),
            ['scope' => 'tax'] + $this->depreciation(13, 2023, 22250.0),
        ]);

        $this->assertSame([12], $result->eventIds);
        $this->assertCount(2, $result->rows);
        $this->assertFalse(AssetPostingBuilder::isPostable('opening', 'acc'));
        $this->assertFalse(AssetPostingBuilder::isPostable('depreciation', 'tax'));
        $this->assertTrue(AssetPostingBuilder::isPostable('disposal', 'both'));
    }

    // --- vyřazení ------------------------------------------------------------

    public function testDisposalWritesOffAccumulatedAndResidualCleanly(): void
    {
        // 100 000, odpisy 11 000 + 22 250 + 9 271 (do měsíce vyřazení) →
        // oprávky 42 521, zůstatková cena 57 479.
        $history = [
            $this->event(1, 'activation', '2022-03-15', 100000.0),
            $this->depreciation(2, 2022, 11000.0),
            $this->depreciation(3, 2023, 22250.0),
            $this->depreciation(4, 2024, 9271.0, '2024-05-10'),
            $this->event(5, 'disposal', '2024-05-10'),
        ];
        $result = $this->build([$history[3], $history[4]], $this->accPlan($history));

        $this->assertTrue($result->isOk(), (string) $result->errorMessage);
        $this->assertSame([
            ['asset.depreciation', 551, 0, 9271.0],
            ['asset.depreciation', 82, 1, 9271.0],
            ['asset.disposal', 82, 0, 42521.0],
            ['asset.disposal', 22, 1, 42521.0],
            ['asset.disposal', 541, 0, 57479.0],
            ['asset.disposal', 22, 1, 57479.0],
        ], $this->compact($result));
        $this->assertSame('Vyřazení — oprávky MA0005 Soustruh', $result->rows[2]->description);
        $this->assertSame('Vyřazení — zůstatková cena MA0005 Soustruh', $result->rows[4]->description);

        // Čistý zápis: účet majetku se vynuluje vstupní cenou.
        $credited = array_sum(array_map(
            static fn(AssetPostingRow $r): float => $r->operation === 'asset.disposal' && $r->side === 1 ? $r->amount : 0.0,
            $result->rows,
        ));
        $this->assertSame(100000.0, $credited);
    }

    public function testDisposalAfterImprovementUsesIncreasedEntryPrice(): void
    {
        $history = [
            $this->event(1, 'activation', '2022-03-15', 100000.0),
            $this->depreciation(2, 2022, 11000.0),
            $this->event(3, 'improvement', '2023-02-01', 20000.0),
            $this->event(5, 'disposal', '2023-01-31'),
        ];
        // Vyřazení před TZ by neprošlo validací události — tady jen pořadí:
        // TZ v únoru, vyřazení až po něm.
        $history[3]['event_date'] = '2023-03-31';
        $plan = $this->accPlan($history);
        $finals = array_values(array_filter($plan->plannedRows(), static fn($r): bool => $r->isDepreciation()));
        $this->assertNotSame([], $finals, 'plán nabízí odpis roku vyřazení');

        // Poslední odpis potvrdíme tak, jak ho nabízí plán.
        $history[] = $this->depreciation(4, 2023, $finals[0]->amount, '2023-03-31');
        $result = $this->build([$history[3]], $this->accPlan($history));

        $this->assertTrue($result->isOk(), (string) $result->errorMessage);
        $accumulated = 11000.0 + $finals[0]->amount;
        $this->assertSame([
            ['asset.disposal', 82, 0, $accumulated],
            ['asset.disposal', 22, 1, $accumulated],
            ['asset.disposal', 541, 0, 120000.0 - $accumulated],
            ['asset.disposal', 22, 1, 120000.0 - $accumulated],
        ], $this->compact($result));
    }

    public function testDisposalWithOpeningBalanceCountsOpeningAccumulated(): void
    {
        // Počáteční stav k 1. 1. 2023: cena 100 000, oprávky 33 250, časová
        // metoda 60 měsíců (20 odepsáno). Vyřazení v lednu → poslední odpis
        // za leden 66 750 / 40 = 1 669; oprávky k vyřazení 34 919.
        $card = ['acc_method' => 'time', 'acc_months' => 60] + self::CARD;
        $history = [
            $this->event(1, 'opening', '2023-01-01', 100000.0, [
                'scope' => 'acc', 'accumulated' => 33250.0, 'units_done' => 20, 'original_date' => '2021-05-01',
            ]),
            $this->event(2, 'opening', '2023-01-01', 100000.0, [
                'scope' => 'tax', 'accumulated' => 33250.0, 'units_done' => 2, 'original_date' => '2021-05-01',
            ]),
            $this->event(5, 'disposal', '2023-01-20'),
            $this->event(4, 'depreciation', '2023-01-20', 1669.0, [
                'scope' => 'acc', 'period_begin' => '2023-01-01', 'period_end' => '2023-01-31',
            ]),
        ];
        $plan = $this->accPlan($history, $card);
        $this->assertSame([], array_filter($plan->plannedRows(), static fn($r): bool => $r->isDepreciation()));

        $result = $this->build([$history[2]], $plan);

        $this->assertTrue($result->isOk(), (string) $result->errorMessage);
        $this->assertSame([
            ['asset.disposal', 82, 0, 34919.0],
            ['asset.disposal', 22, 1, 34919.0],
            ['asset.disposal', 541, 0, 65081.0],
            ['asset.disposal', 22, 1, 65081.0],
        ], $this->compact($result));
    }

    public function testFullyDepreciatedDisposalHasNoResidualRows(): void
    {
        $card = ['acc_method' => 'time', 'acc_months' => 12] + self::CARD;
        $history = [
            $this->event(1, 'opening', '2023-01-01', 100000.0, [
                'scope' => 'acc', 'accumulated' => 100000.0, 'units_done' => 12, 'original_date' => '2021-05-01',
            ]),
            $this->event(5, 'disposal', '2023-02-01'),
        ];
        // Bez účtu zůstatkové ceny — nulová dvojice ho nepotřebuje.
        $result = $this->build([$history[1]], $this->accPlan($history, $card), ['disposal' => null] + self::ACCOUNTS);

        $this->assertTrue($result->isOk(), (string) $result->errorMessage);
        $this->assertSame([
            ['asset.disposal', 82, 0, 100000.0],
            ['asset.disposal', 22, 1, 100000.0],
        ], $this->compact($result));
        $this->assertSame([5], $result->eventIds);
    }

    public function testNonDepreciableDisposalBooksEntryPriceOnly(): void
    {
        $card = ['category' => 'nondepreciable', 'tax_method' => null, 'tax_rule' => null, 'acc_method' => null] + self::CARD;
        $history = [
            $this->event(1, 'activation', '2022-03-15', 500000.0),
            $this->event(5, 'disposal', '2024-05-10'),
        ];
        $result = $this->build(
            [$history[1]],
            $this->accPlan($history, $card),
            ['accumulated' => null, 'depreciation' => null] + self::ACCOUNTS,
        );

        $this->assertTrue($result->isOk(), (string) $result->errorMessage);
        $this->assertSame([
            ['asset.disposal', 541, 0, 500000.0],
            ['asset.disposal', 22, 1, 500000.0],
        ], $this->compact($result));
    }

    public function testDisposalWithUnconfirmedFinalDepreciationIsCardError(): void
    {
        // Odpis roku vyřazení někdo smazal — plán ho zase nabízí; částky
        // vyřazení by pak neseděly na deník.
        $history = [
            $this->event(1, 'activation', '2022-03-15', 100000.0),
            $this->depreciation(2, 2022, 11000.0),
            $this->depreciation(3, 2023, 22250.0),
            $this->event(5, 'disposal', '2024-05-10'),
        ];
        $result = $this->build([$history[3]], $this->accPlan($history));

        $this->assertFalse($result->isOk());
        $this->assertSame(AssetPostingBuilder::ERROR_DISPOSAL_PLAN, $result->errorCode);
        $this->assertSame([], $result->rows);
        $this->assertSame([], $result->eventIds);

        $this->assertSame(AssetPostingBuilder::ERROR_DISPOSAL_PLAN, $this->build([$history[3]], null)->errorCode);
    }

    // --- účty a pořadí -------------------------------------------------------

    public function testMissingGroupAccountIsCardErrorNotARowWithAHole(): void
    {
        $result = $this->build(
            [$this->event(1, 'activation', '2022-03-15', 100000.0), $this->depreciation(2, 2022, 11000.0)],
            null,
            ['acquisition' => null, 'accumulated' => 0] + self::ACCOUNTS,
        );

        $this->assertFalse($result->isOk());
        $this->assertSame(AssetPostingBuilder::ERROR_ACCOUNT_MISSING, $result->errorCode);
        $this->assertStringContainsString('účet pořízení', (string) $result->errorMessage);
        $this->assertStringContainsString('účet oprávek', (string) $result->errorMessage);
        $this->assertSame([], $result->rows);
    }

    public function testSortGroupsByKindThenInventoryNumber(): void
    {
        $builder = new AssetPostingBuilder();
        $rows = [];
        foreach ([['MA0009', 9], ['MA0002', 2]] as [$number, $assetId]) {
            $result = $builder->build(new AssetPostingInput($assetId, $number, 'Karta', self::ACCOUNTS, [
                ['asset' => $assetId] + $this->depreciation($assetId * 10, 2022, 100.0),
                ['asset' => $assetId] + $this->event($assetId * 10 + 1, 'activation', '2022-03-15', 1000.0),
            ]));
            array_push($rows, ...$result->rows);
        }

        $sorted = AssetPostingBuilder::sort($rows);

        $this->assertSame([
            ['asset.activation', 'MA0002', 0], ['asset.activation', 'MA0002', 1],
            ['asset.activation', 'MA0009', 0], ['asset.activation', 'MA0009', 1],
            ['asset.depreciation', 'MA0002', 0], ['asset.depreciation', 'MA0002', 1],
            ['asset.depreciation', 'MA0009', 0], ['asset.depreciation', 'MA0009', 1],
        ], array_map(static fn(AssetPostingRow $r): array => [$r->operation, $r->assetNumber, $r->side], $sorted));
    }

    public function testDocRowShape(): void
    {
        $row = $this->build([$this->depreciation(4, 2022, 11000.0)])->rows[1];

        $this->assertSame([
            'row_kind' => 1, 'operation' => 'asset.depreciation', 'asset' => self::ASSET, 'account' => 82,
            'acc_side' => 1, 'total_price' => 11000.0, 'price_calc_mode' => 1,
            'description' => 'Odpis MA0005 Soustruh (1. 1. 2022 – 31. 12. 2022)',
        ], $row->toDocRow());
    }
}

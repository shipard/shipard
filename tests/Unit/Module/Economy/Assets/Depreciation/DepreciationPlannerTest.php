<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Assets\Depreciation;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Economy\Assets\Depreciation\AssetEvent;
use Shipard\Module\Economy\Assets\Depreciation\DepreciationPlanner;
use Shipard\Module\Economy\Assets\Depreciation\DepreciationSettings;
use Shipard\Module\Economy\Assets\Depreciation\PeriodCalendar;
use Shipard\Module\Economy\Assets\Depreciation\Plan;
use Shipard\Module\Economy\Assets\Depreciation\PlanMessage;
use Shipard\Module\Economy\Assets\Depreciation\PlanRow;
use Shipard\Module\World\Assets\AccountingOnlyTaxRules;
use Shipard\Module\World\Assets\CzTaxDepreciationRules;
use Shipard\Module\World\Assets\TaxDepreciationRules;

/**
 * Scénáře odpisového enginu (tasks/assets-phase2a.md → Testy, D40).
 *
 * Případy jsou anonymizované podle vzorů ze starých dat — poměry zachované,
 * částky vymyšlené. Očekávané hodnoty jsou spočítané ručně v komentářích.
 * Pravidla jsou skutečná (`assets-cz.jsonc`), kalendář kalendářní roky,
 * pokud test neříká jinak.
 */
class DepreciationPlannerTest extends TestCase
{
    private const CONFIG = __DIR__ . '/../../../../../../modules/world/assets/config/assets-cz.jsonc';

    private const STRAIGHT_2 = ['tax_method' => 'straight', 'tax_rule' => 'cz-2'];
    private const ACCELERATED_2 = ['tax_method' => 'accelerated', 'tax_rule' => 'cz-2'];

    private static ?array $cfg = null;

    // --- pomůcky ------------------------------------------------------------

    private function rules(): CzTaxDepreciationRules
    {
        self::$cfg ??= JsoncParser::parseFile(self::CONFIG);
        return new CzTaxDepreciationRules(self::$cfg);
    }

    /**
     * @param array<string, mixed> $settings
     * @param list<AssetEvent> $events
     * @return array{tax: Plan, acc: Plan}
     */
    private function plan(
        array $settings,
        array $events,
        ?PeriodCalendar $acc = null,
        ?PeriodCalendar $tax = null,
        string $asOf = '2024-06-30',
        ?TaxDepreciationRules $rules = null,
    ): array {
        return (new DepreciationPlanner())->plan(
            DepreciationSettings::fromArray($settings),
            $events,
            $rules ?? $this->rules(),
            $tax ?? PeriodCalendar::yearly([]),
            $acc ?? PeriodCalendar::yearly([]),
            $asOf,
        );
    }

    private function activation(string $date, float $amount): AssetEvent
    {
        return new AssetEvent(AssetEvent::KIND_ACTIVATION, AssetEvent::SCOPE_BOTH, $date, $amount);
    }

    private function improvement(string $date, float $amount): AssetEvent
    {
        return new AssetEvent(AssetEvent::KIND_IMPROVEMENT, AssetEvent::SCOPE_BOTH, $date, $amount);
    }

    private function reduction(string $date, float $amount): AssetEvent
    {
        return new AssetEvent(AssetEvent::KIND_REDUCTION, AssetEvent::SCOPE_BOTH, $date, $amount);
    }

    private function disposal(string $date, bool $halfYear): AssetEvent
    {
        return new AssetEvent(AssetEvent::KIND_DISPOSAL, AssetEvent::SCOPE_BOTH, $date, halfYear: $halfYear);
    }

    private function interruption(int $year): AssetEvent
    {
        return new AssetEvent(
            AssetEvent::KIND_INTERRUPTION, AssetEvent::SCOPE_TAX, "{$year}-12-31",
            periodBegin: "{$year}-01-01", periodEnd: "{$year}-12-31",
        );
    }

    /** Potvrzený odpis za kalendářní rok. */
    private function depreciation(string $scope, int $year, float $amount, string $origin = AssetEvent::ORIGIN_MANUAL): AssetEvent
    {
        return new AssetEvent(
            AssetEvent::KIND_DEPRECIATION, $scope, "{$year}-12-31", $amount,
            periodBegin: "{$year}-01-01", periodEnd: "{$year}-12-31", origin: $origin,
        );
    }

    /** @return list<float> částky odpisů (potvrzených i plánovaných) */
    private function amounts(Plan $plan): array
    {
        return array_values(array_map(
            static fn(PlanRow $r): float => $r->amount,
            array_filter($plan->rows, static fn(PlanRow $r): bool => $r->isDepreciation()),
        ));
    }

    /** @return list<float> */
    private function planned(Plan $plan): array
    {
        return array_map(static fn(PlanRow $r): float => $r->amount, $plan->plannedRows());
    }

    /** @return list<string> */
    private function kinds(Plan $plan): array
    {
        return array_map(static fn(PlanRow $r): string => $r->kind . ($r->isPlanned() ? '~' : ''), $plan->rows);
    }

    /** @return list<string> */
    private function codes(Plan $plan): array
    {
        return array_map(static fn(PlanMessage $m): string => $m->code, $plan->allMessages());
    }

    /** @return array<string, float> součet odpisů per rok konce období */
    private function perYear(Plan $plan): array
    {
        $sums = [];
        foreach ($plan->rows as $row) {
            if ($row->isDepreciation()) {
                $year = substr($row->period->end, 0, 4);
                $sums[$year] = ($sums[$year] ?? 0.0) + $row->amount;
            }
        }
        return $sums;
    }

    // --- daňový okruh: roční metody ------------------------------------------

    public function testStraightGroup2(): void
    {
        // VC 100 000, zařazení 2022: 11 %, pak 4 × 22,25 %. Rok zařazení se
        // odpisuje celý bez ohledu na měsíc (D37).
        $plan = $this->plan(self::STRAIGHT_2, [$this->activation('2022-03-15', 100000.0)])['tax'];

        $this->assertSame([11000.0, 22250.0, 22250.0, 22250.0, 22250.0], $this->amounts($plan));
        $this->assertSame([], $plan->allMessages());

        $first = $plan->rows[1];
        $this->assertSame(PlanRow::STATUS_PLANNED, $first->status);
        $this->assertSame('2022-12-31', $first->date);
        $this->assertSame(['id' => null, 'begin' => '2022-01-01', 'end' => '2022-12-31'], $first->period->toArray());
        $this->assertSame('100 000,00 × 11 %', $first->formula);
        $this->assertSame(11000.0, $first->computed);
        $this->assertSame(100000.0, $first->entryPrice);
        $this->assertSame(11000.0, $first->accumulated);
        $this->assertSame(89000.0, $first->residual);
        $this->assertSame(0.0, $plan->rows[5]->residual);
    }

    public function testAcceleratedGroup2(): void
    {
        // 100 000/5, 2×80 000/5, 2×48 000/4, 2×24 000/3, 2×8 000/2.
        $plan = $this->plan(self::ACCELERATED_2, [$this->activation('2022-03-15', 100000.0)])['tax'];

        $this->assertSame([20000.0, 32000.0, 24000.0, 16000.0, 8000.0], $this->amounts($plan));
    }

    public function testInterruptedYearDoesNotCountIntoYears(): void
    {
        // Skupina 1, VC 90 000, zařazení 12/2017, přerušení 2019:
        // 20 %, 40 %, —, 40 %. Přerušený rok se do pořadí nepočítá (D34).
        $settings = ['tax_method' => 'straight', 'tax_rule' => 'cz-1'];
        $events = [
            $this->activation('2017-12-05', 90000.0),
            $this->depreciation('tax', 2017, 18000.0),
            $this->depreciation('tax', 2018, 36000.0),
            $this->interruption(2019),
        ];
        $plan = $this->plan($settings, $events)['tax'];

        $this->assertSame(
            ['activation', 'depreciation', 'depreciation', 'interruption', 'depreciation~'],
            $this->kinds($plan),
        );
        $this->assertSame([18000.0, 36000.0, 36000.0], $this->amounts($plan));
        $this->assertSame('2020-12-31', $plan->rows[4]->period->end);
        $this->assertSame([], $plan->allMessages());

        // Bez potvrzené historie se roky před přerušením doplní do plánu.
        $plan = $this->plan($settings, [$this->activation('2017-12-05', 90000.0), $this->interruption(2019)])['tax'];
        $this->assertSame(
            ['activation', 'depreciation~', 'depreciation~', 'interruption', 'depreciation~'],
            $this->kinds($plan),
        );
        $this->assertSame([18000.0, 36000.0, 36000.0], $this->amounts($plan));
    }

    public function testDisposalWithHalfYearDepreciation(): void
    {
        // Skupina 2, vyřazení ve 3. roce: 11 000, 22 250, ½ × 22 250 = 11 125.
        // Vyřazená zůstatková cena 100 000 − 44 375 = 55 625.
        $plan = $this->plan(self::STRAIGHT_2, [
            $this->activation('2022-03-15', 100000.0),
            $this->disposal('2024-08-10', true),
        ])['tax'];

        $this->assertSame([11000.0, 22250.0, 11125.0], $this->amounts($plan));
        $last = $plan->rows[3];
        $this->assertTrue($last->halfYear);
        $this->assertSame('2024-08-10', $last->date);
        $this->assertSame('(100 000,00 × 22,25 %) / 2', $last->formula);

        $disposal = $plan->rows[4];
        $this->assertSame(AssetEvent::KIND_DISPOSAL, $disposal->kind);
        $this->assertSame(55625.0, $disposal->amount);
        $this->assertSame(0.0, $disposal->residual);
    }

    public function testConfirmedFinalDepreciationsWithDisposalLeaveNothingToPlan(): void
    {
        // Stav po potvrzení vyřazení ve fázi 2b: poslední odpisy obou okruhů
        // vznikly s vyřazením (D35) — plán už nic nepřidává a nic nehlásí.
        $final = static fn(string $scope, float $amount, bool $half): AssetEvent => new AssetEvent(
            AssetEvent::KIND_DEPRECIATION, $scope, '2024-08-10', $amount,
            periodBegin: '2024-01-01', periodEnd: '2024-08-31',
            origin: AssetEvent::ORIGIN_SYSTEM, halfYear: $half,
        );
        $plans = $this->plan(self::STRAIGHT_2 + ['acc_method' => 'time', 'acc_months' => 60], [
            $this->activation('2022-03-15', 100000.0),
            $this->depreciation('tax', 2022, 11000.0),
            $this->depreciation('tax', 2023, 22250.0),
            $this->depreciation('acc', 2022, 15000.0),
            $this->depreciation('acc', 2023, 20000.0),
            $this->disposal('2024-08-10', true),
            $final('tax', 11125.0, true),
            // 65 000 / 39 × 8 = 13 333,33 → 13 334.
            $final('acc', 13334.0, false),
        ]);

        foreach ($plans as $plan) {
            $this->assertSame([], $plan->plannedRows());
            $this->assertSame([], $plan->allMessages());
            $this->assertSame(AssetEvent::KIND_DISPOSAL, $plan->rows[count($plan->rows) - 1]->kind);
            $this->assertSame(0.0, $plan->residual);
        }
        $this->assertSame(55625.0, $plans['tax']->rows[4]->amount);
        $this->assertSame(51666.0, $plans['acc']->rows[4]->amount);
    }

    public function testDisposalWithoutHalfYearHasNoDepreciationInYearOfDisposal(): void
    {
        $plan = $this->plan(self::STRAIGHT_2, [
            $this->activation('2022-03-15', 100000.0),
            $this->disposal('2024-08-10', false),
        ])['tax'];

        $this->assertSame([11000.0, 22250.0], $this->amounts($plan));
        $this->assertSame(66750.0, $plan->rows[3]->amount);
    }

    public function testHalfYearNeedsAssetEvidencedAtYearStart(): void
    {
        // Zařazeno i vyřazeno v témže roce — na začátku roku v evidenci nebylo.
        $plan = $this->plan(self::STRAIGHT_2, [
            $this->activation('2024-02-10', 100000.0),
            $this->disposal('2024-09-30', true),
        ])['tax'];

        $this->assertSame([], $this->amounts($plan));
        $this->assertSame(['activation', 'disposal'], $this->kinds($plan));
        $this->assertSame(100000.0, $plan->rows[1]->amount);
    }

    public function testStraightWithImprovementInSecondYear(): void
    {
        // TZ 20 000 ve 2. roce: 11 000, pak 120 000 × 20 % = 24 000 čtyřikrát,
        // poslední rok zbytek 13 000.
        $plan = $this->plan(self::STRAIGHT_2, [
            $this->activation('2022-03-15', 100000.0),
            $this->improvement('2023-05-10', 20000.0),
        ])['tax'];

        $this->assertSame([11000.0, 24000.0, 24000.0, 24000.0, 24000.0, 13000.0], $this->amounts($plan));
        $this->assertSame(
            ['activation', 'depreciation~', 'improvement', 'depreciation~'],
            array_slice($this->kinds($plan), 0, 4),
        );
        $this->assertSame(109000.0, $plan->rows[2]->residual);
        $this->assertSame('min(120 000,00 × 20 %; 13 000,00)', $plan->rows[7]->formula);
    }

    public function testAcceleratedWithImprovement(): void
    {
        // § 32 odst. 3 — po dvou letech ZC 48 000, TZ 20 000 ve 3. roce:
        //   2 × 68 000 / 5 = 27 200, 2 × 40 800 / 4 = 20 400,
        //   2 × 20 400 / 3 = 13 600, 2 × 6 800 / 2 = 6 800.
        // Starý engine počitadlo let po TZ nenuloval: 2 × 68 000 / (5 − 2)
        // = 45 334 hned v roce TZ a majetek odepsal o dva roky dřív.
        $plan = $this->plan(self::ACCELERATED_2, [
            $this->activation('2022-03-15', 100000.0),
            $this->improvement('2024-05-10', 20000.0),
        ])['tax'];

        $this->assertSame(
            [20000.0, 32000.0, 27200.0, 20400.0, 13600.0, 6800.0],
            $this->amounts($plan),
        );
    }

    public function testImprovementBeforeFirstDepreciationIsPartOfEntryPrice(): void
    {
        // TZ v roce zařazení: majetek jako by byl pořízen najednou — sazba
        // 1. roku ze 120 000 (13 200), dál běžná sazba 22,25 % (26 700),
        // ne sazba pro zvýšenou vstupní cenu.
        $plan = $this->plan(self::STRAIGHT_2, [
            $this->activation('2022-03-15', 100000.0),
            $this->improvement('2022-09-20', 20000.0),
        ])['tax'];

        $this->assertSame([13200.0, 26700.0, 26700.0, 26700.0, 26700.0], $this->amounts($plan));
    }

    public function testReductionLowersEntryPriceAndResidual(): void
    {
        // Snížení 10 000 ve 2. roce: 11 000, pak 90 000 × 22,25 % = 20 025,
        // poslední rok zbytek 90 000 − 11 000 − 3 × 20 025 = 18 925.
        $plan = $this->plan(self::STRAIGHT_2, [
            $this->activation('2022-03-15', 100000.0),
            $this->reduction('2023-04-01', 10000.0),
        ])['tax'];

        $this->assertSame([11000.0, 20025.0, 20025.0, 20025.0, 18925.0], $this->amounts($plan));
        $this->assertSame(90000.0, $plan->rows[2]->entryPrice);
    }

    public function testMissingEarlierDepreciationIsPlannedBeforeLaterImprovement(): void
    {
        // Odpisy 2022 a 2023 nejsou potvrzené, TZ je z roku 2024: plán starších
        // let se počítá z původní ceny, ne ze zvýšené.
        $events = [
            $this->activation('2022-03-15', 100000.0),
            $this->improvement('2024-05-10', 20000.0),
        ];
        $plan = $this->plan(self::STRAIGHT_2, $events)['tax'];

        $this->assertSame(
            ['activation', 'depreciation~', 'depreciation~', 'improvement', 'depreciation~', 'depreciation~', 'depreciation~', 'depreciation~'],
            $this->kinds($plan),
        );
        // 11 000, 22 250, pak 120 000 × 20 % a zbytek 14 750.
        $this->assertSame([11000.0, 22250.0, 24000.0, 24000.0, 24000.0, 14750.0], $this->amounts($plan));

        // Potvrzený odpis 2024 bez potvrzených předchozích let je chyba plánu.
        $events[] = $this->depreciation('tax', 2024, 24000.0);
        $plan = $this->plan(self::STRAIGHT_2, $events)['tax'];
        $this->assertSame([PlanMessage::MISSING_PERIOD], $this->codes($plan));
        $this->assertTrue($plan->hasErrors());
    }

    public function testFiscalYearCalendarAndExtrapolation(): void
    {
        // Hospodářský rok duben–březen; za posledním založeným rokem se
        // období dopočítávají po 12 měsících.
        $years = [
            ['id' => 11, 'date_begin' => '2022-04-01', 'date_end' => '2023-03-31'],
            ['id' => 12, 'date_begin' => '2023-04-01', 'date_end' => '2024-03-31'],
        ];
        $plan = $this->plan(
            self::STRAIGHT_2,
            [$this->activation('2022-05-10', 100000.0)],
            tax: PeriodCalendar::yearly($years),
        )['tax'];

        $periods = array_map(static fn(PlanRow $r): array => $r->period->toArray(), $plan->plannedRows());
        $this->assertSame(['id' => 11, 'begin' => '2022-04-01', 'end' => '2023-03-31'], $periods[0]);
        $this->assertSame(['id' => 12, 'begin' => '2023-04-01', 'end' => '2024-03-31'], $periods[1]);
        $this->assertSame(['id' => null, 'begin' => '2024-04-01', 'end' => '2025-03-31'], $periods[2]);
        $this->assertSame('2027-03-31', $plan->rows[5]->date);
        $this->assertSame([11000.0, 22250.0, 22250.0, 22250.0, 22250.0], $this->amounts($plan));
    }

    // --- daňový okruh: krátké zdaňovací období (D46) -------------------------

    /**
     * Přechod na hospodářský rok říjen–září: kalendářní roky 2022 a 2023,
     * přechodné období 1–9/2024, pak hospodářské roky.
     *
     * @return list<array<string, mixed>>
     */
    private function yearsWithShortPeriod(): array
    {
        return [
            ['id' => 21, 'date_begin' => '2022-01-01', 'date_end' => '2022-12-31'],
            ['id' => 22, 'date_begin' => '2023-01-01', 'date_end' => '2023-12-31'],
            ['id' => 23, 'date_begin' => '2024-01-01', 'date_end' => '2024-09-30'],
            ['id' => 24, 'date_begin' => '2024-10-01', 'date_end' => '2025-09-30'],
        ];
    }

    public function testShortTaxPeriodGivesHalfOfAnnualDepreciation(): void
    {
        // Skupina 2: 11 000, 22 250, přechodné období ½ × 22 250 = 11 125,
        // pak 22 250, 22 250 a zbytek 11 125.
        $plan = $this->plan(
            self::STRAIGHT_2,
            [$this->activation('2022-03-15', 100000.0)],
            tax: PeriodCalendar::yearly($this->yearsWithShortPeriod()),
        )['tax'];

        $this->assertSame([11000.0, 22250.0, 11125.0, 22250.0, 22250.0, 11125.0], $this->amounts($plan));
        $this->assertSame([], $plan->allMessages());

        $short = $plan->rows[3];
        $this->assertSame(['id' => 23, 'begin' => '2024-01-01', 'end' => '2024-09-30'], $short->period->toArray());
        $this->assertSame('(100 000,00 × 22,25 %) / 2', $short->formula);
        // Polovina z krátkého období není odpis roku vyřazení.
        $this->assertFalse($short->halfYear);
    }

    public function testShortTaxPeriodCountsAsDepreciatedYear(): void
    {
        // Zrychlený odpis, skupina 2 (koeficienty 5 / 6): 100 000 / 5,
        // 2 × 80 000 / (6 − 1), přechodné období ½ × 2 × 48 000 / (6 − 2)
        // = 12 000. Krátké období je rok s uplatněným odpisem (n + 1), takže
        // další rok dělí (6 − 3): 2 × 36 000 / 3 = 24 000, pak 2 × 12 000 / 2.
        // Kdyby se nezapočítalo, vyšlo by 2 × 36 000 / 4 = 18 000.
        $plan = $this->plan(
            self::ACCELERATED_2,
            [$this->activation('2022-03-15', 100000.0)],
            tax: PeriodCalendar::yearly($this->yearsWithShortPeriod()),
        )['tax'];

        $this->assertSame([20000.0, 32000.0, 12000.0, 24000.0, 12000.0], $this->amounts($plan));
        $this->assertSame('(2 × 48 000,00 / (6 − 2)) / 2', $plan->rows[3]->formula);
    }

    public function testTaxPeriodLongerThanTwelveMonthsGivesFullDepreciation(): void
    {
        // Účetní rok 1/2023–3/2024 má 15 měsíců — plný roční odpis.
        $years = [
            ['id' => 31, 'date_begin' => '2022-01-01', 'date_end' => '2022-12-31'],
            ['id' => 32, 'date_begin' => '2023-01-01', 'date_end' => '2024-03-31'],
        ];
        $plan = $this->plan(
            self::STRAIGHT_2,
            [$this->activation('2022-03-15', 100000.0)],
            tax: PeriodCalendar::yearly($years),
        )['tax'];

        $this->assertSame([11000.0, 22250.0, 22250.0, 22250.0, 22250.0], $this->amounts($plan));
        $this->assertSame('100 000,00 × 22,25 %', $plan->rows[2]->formula);
        $this->assertSame('2024-03-31', $plan->rows[2]->period->end);
    }

    public function testDisposalInShortTaxPeriodIsOneHalfNotQuarter(): void
    {
        // Vyřazení s polovinou v přechodném období: pořád jedna polovina.
        $plan = $this->plan(
            self::STRAIGHT_2,
            [
                $this->activation('2022-03-15', 100000.0),
                $this->disposal('2024-05-10', true),
            ],
            tax: PeriodCalendar::yearly($this->yearsWithShortPeriod()),
        )['tax'];

        $this->assertSame([11000.0, 22250.0, 11125.0], $this->amounts($plan));
        $this->assertSame('(100 000,00 × 22,25 %) / 2', $plan->rows[3]->formula);
        $this->assertTrue($plan->rows[3]->halfYear);
        $this->assertSame(55625.0, $plan->rows[4]->amount);
    }

    public function testAsTaxFollowsShortTaxPeriod(): void
    {
        // Účetní `as_tax` krátí krátký rok stejně jako daňový okruh.
        $calendar = PeriodCalendar::yearly($this->yearsWithShortPeriod());
        $plans = $this->plan(
            self::STRAIGHT_2 + ['acc_method' => 'as_tax'],
            [$this->activation('2022-03-15', 100000.0)],
            acc: $calendar,
            tax: $calendar,
        );

        $this->assertSame($this->amounts($plans['tax']), $this->amounts($plans['acc']));
        $this->assertSame([], $plans['acc']->allMessages());
    }

    // --- daňový okruh: měsíční metody ----------------------------------------

    public function testExtraordinaryGroup2(): void
    {
        // § 30a, VC 120 000, zařazení 5/2021 → od 6/2021: 60 % za 12 měsíců
        // (6 000 měsíčně), 40 % za dalších 12 (4 000).
        //   2021: 7 × 6 000 = 42 000; 2022: 5 × 6 000 + 7 × 4 000 = 58 000;
        //   2023: 5 × 4 000 = 20 000.
        $plan = $this->plan(
            ['tax_method' => 'extraordinary', 'tax_rule' => 'cz-30a-2'],
            [$this->activation('2021-05-10', 120000.0)],
        )['tax'];

        $this->assertSame([42000.0, 58000.0, 20000.0], $this->amounts($plan));
        $this->assertSame('120 000,00 × 60 % / 12 × 5 + 120 000,00 × 40 % / 12 × 7', $plan->rows[2]->formula);
    }

    public function testExtraordinaryGroup1ActivatedInDecember(): void
    {
        // Rozpis začíná lednem 2022: za 2021 nic, za 2022 100 %.
        $plan = $this->plan(
            ['tax_method' => 'extraordinary', 'tax_rule' => 'cz-30a-1'],
            [$this->activation('2021-12-10', 50000.0)],
        )['tax'];

        $this->assertSame(['2022' => 50000.0], $this->perYear($plan));
    }

    public function testImprovementOnExtraordinaryMethodIsAnError(): void
    {
        // § 30a odst. 3: TZ se odpisuje samostatně — patří na vlastní kartu (D41).
        $plans = $this->plan(
            ['tax_method' => 'extraordinary', 'tax_rule' => 'cz-30a-2', 'acc_method' => 'time', 'acc_months' => 48],
            [$this->activation('2021-05-10', 120000.0), $this->improvement('2022-03-01', 30000.0)],
        );

        $this->assertSame([PlanMessage::IMPROVEMENT_ON_SCHEDULE], $this->codes($plans['tax']));
        $this->assertTrue($plans['tax']->hasErrors());
        $this->assertSame([], $this->codes($plans['acc']));
    }

    public function testTimeIntangibleThirtySixMonths(): void
    {
        // § 32a, software 36 měsíců, VC 72 000, zařazení 5/2019 → 2 000 měsíčně
        // od 6/2019: 14 000, 24 000, 24 000, 10 000.
        $plan = $this->plan(
            ['tax_method' => 'time', 'tax_rule' => 'cz-nim-software', 'intangible' => true],
            [$this->activation('2019-05-20', 72000.0)],
        )['tax'];

        $this->assertSame([14000.0, 24000.0, 24000.0, 10000.0], $this->amounts($plan));
        $this->assertSame('72 000,00 / 36 × 7', $plan->rows[1]->formula);
    }

    public function testTimeWithImprovementContinuesAtLeastMinimumMonths(): void
    {
        // § 32a odst. 6. TZ 18 000 v 3/2021: do března 3 × 2 000; k dubnu
        // uplynulo 22 měsíců, zbývá 14 → nejméně 18 měsíců. Základ = ZC 28 000
        // + 18 000 = 46 000, tj. 2 555,56 měsíčně:
        //   2021: 6 000 + 9 × 46 000 / 18 = 29 000; 2022: zbylých 9 měsíců = 23 000.
        $plan = $this->plan(
            ['tax_method' => 'time', 'tax_rule' => 'cz-nim-software'],
            [$this->activation('2019-05-20', 72000.0), $this->improvement('2021-03-10', 18000.0)],
        )['tax'];

        $this->assertSame([14000.0, 24000.0, 29000.0, 23000.0], $this->amounts($plan));
        $this->assertSame('72 000,00 / 36 × 3 + 46 000,00 / 18 × 9', $plan->rows[4]->formula);
        // Řádek je za celé zdaňovací období, i když rozpis končí v září.
        $this->assertSame('46 000,00 / 18 × 9', $plan->rows[5]->formula);
        $this->assertSame('2022-12-31', $plan->rows[5]->period->end);
    }

    public function testMonthlyTaxMethodDepreciatesUpToMonthOfDisposal(): void
    {
        // Časová metoda nemá poloviční odpis — odpis připadá na měsíce do
        // vyřazení včetně: 2019 14 000, 2020 leden–duben 4 × 2 000.
        $plan = $this->plan(
            ['tax_method' => 'time', 'tax_rule' => 'cz-nim-software'],
            [$this->activation('2019-05-20', 72000.0), $this->disposal('2020-04-20', true)],
        )['tax'];

        $this->assertSame([14000.0, 8000.0], $this->amounts($plan));
        $this->assertSame('2020-04-30', $plan->rows[2]->period->end);
        $this->assertSame(50000.0, $plan->rows[3]->amount);
    }

    // --- počáteční stav (D16) ------------------------------------------------

    public function testOpeningBalanceContinuesWithRightRate(): void
    {
        // Skupina 5, původní zařazení 2014, 10 let odpisů: 1,4 % + 9 × 3,4 %
        // = 32 % z 3 000 000 = 960 000. 11. rok: 3 000 000 × 3,4 % = 102 000.
        $opening = new AssetEvent(
            AssetEvent::KIND_OPENING, AssetEvent::SCOPE_TAX, '2024-01-01', 3000000.0,
            accumulated: 960000.0, unitsDone: 10, originalDate: '2014-06-01',
        );
        $plan = $this->plan(['tax_method' => 'straight', 'tax_rule' => 'cz-5'], [$opening])['tax'];

        $this->assertSame([], $plan->allMessages());
        $this->assertSame(102000.0, $plan->rows[1]->amount);
        $this->assertSame('2024-12-31', $plan->rows[1]->period->end);
        $this->assertSame(2040000.0, $plan->rows[0]->residual);
        $this->assertSame(960000.0, $plan->accumulated);
    }

    public function testOpeningBalanceNotMatchingRulesWarns(): void
    {
        $opening = new AssetEvent(
            AssetEvent::KIND_OPENING, AssetEvent::SCOPE_TAX, '2024-01-01', 3000000.0,
            accumulated: 950000.0, unitsDone: 10, originalDate: '2014-06-01',
        );
        $plan = $this->plan(['tax_method' => 'straight', 'tax_rule' => 'cz-5'], [$opening])['tax'];

        $messages = $plan->rows[0]->messages;
        $this->assertCount(1, $messages);
        $this->assertSame(PlanMessage::OPENING_MISMATCH, $messages[0]->code);
        $this->assertSame(PlanMessage::SEVERITY_WARNING, $messages[0]->severity);
        $this->assertSame(['accumulated' => 950000.0, 'expected' => 960000.0, 'units' => 10], $messages[0]->params);
        // Varování neblokuje — plán pokračuje.
        $this->assertFalse($plan->hasErrors());
        $this->assertSame(102000.0, $plan->rows[1]->amount);
    }

    public function testOpeningBalanceOfAcceleratedMethodWithIncreasedPrice(): void
    {
        // Zvýšená cena: `unitsDone` = roky odpisované ze zvýšené ZC. Po roce
        // TZ zbývá ZC 40 800: 2 × 40 800 / (5 − 1), 2 × 20 400 / (5 − 2), zbytek.
        // Oprávky se se zvýšenou cenou nekontrolují.
        $opening = new AssetEvent(
            AssetEvent::KIND_OPENING, AssetEvent::SCOPE_TAX, '2024-01-01', 120000.0,
            accumulated: 79200.0, unitsDone: 1, priceIncreased: true, originalDate: '2019-04-01',
        );
        $plan = $this->plan(self::ACCELERATED_2, [$opening])['tax'];

        $this->assertSame([20400.0, 13600.0, 6800.0], $this->amounts($plan));
        $this->assertSame([], $plan->allMessages());
    }

    public function testOpeningBalanceOfMonthlyMethodContinuesSchedule(): void
    {
        // Mimořádné odpisy po 7 měsících rozpisu (42 000) — pokračuje 8. měsícem.
        $opening = new AssetEvent(
            AssetEvent::KIND_OPENING, AssetEvent::SCOPE_TAX, '2022-01-01', 120000.0,
            accumulated: 42000.0, unitsDone: 7, originalDate: '2021-05-10',
        );
        $plan = $this->plan(['tax_method' => 'extraordinary', 'tax_rule' => 'cz-30a-2'], [$opening])['tax'];

        $this->assertSame([58000.0, 20000.0], $this->amounts($plan));
        $this->assertSame([], $plan->allMessages());
    }

    public function testOpeningBalanceOfAccountingTimeMethod(): void
    {
        // 60 měsíců, uplynulo 21 (21 000 z 60 000): zbývá 39 měsíců po 1 000.
        $opening = static fn(float $accumulated): AssetEvent => new AssetEvent(
            AssetEvent::KIND_OPENING, AssetEvent::SCOPE_ACC, '2024-01-01', 60000.0,
            accumulated: $accumulated, unitsDone: 21, originalDate: '2022-03-15',
        );
        $settings = ['tax_method' => 'none', 'acc_method' => 'time', 'acc_months' => 60];

        $plan = $this->plan($settings, [$opening(21000.0)])['acc'];
        $this->assertSame([12000.0, 12000.0, 12000.0, 3000.0], $this->amounts($plan));
        $this->assertSame([], $plan->allMessages());

        $plan = $this->plan($settings, [$opening(18000.0)])['acc'];
        $this->assertSame([PlanMessage::OPENING_MISMATCH], $this->codes($plan));
    }

    // --- účetní okruh ---------------------------------------------------------

    public function testAccountingTimeSixtyMonthsYearly(): void
    {
        // 60 000 / 60 měsíců, zařazení 3/2022 → od dubna (D37):
        // 9 × 1 000, 4 × 12 000, 3 × 1 000.
        $plan = $this->plan(
            ['tax_method' => 'none', 'acc_method' => 'time', 'acc_months' => 60],
            [$this->activation('2022-03-15', 60000.0)],
        )['acc'];

        $this->assertSame([9000.0, 12000.0, 12000.0, 12000.0, 12000.0, 3000.0], $this->amounts($plan));
        $this->assertSame('60 000,00 / 60 × 9', $plan->rows[1]->formula);
        $this->assertSame('51 000,00 / 51 × 12', $plan->rows[2]->formula);
    }

    public function testAccountingTimeElapsedPlansWholeResidualWithWarning(): void
    {
        // D83: starý systém po TZ dobu prodloužil, takže na konci původní
        // doby (360 měsíců, 1/1994–12/2023) zbývá 4 800 000 − 30 × 100 000
        // = 1 800 000. Plán 2024 odepíše celý zůstatek najednou a řekne to
        // varováním — jen na plánovaném řádku, potvrzené ho nenesou.
        $events = [$this->activation('1993-12-15', 3600000.0), $this->improvement('2015-06-10', 1200000.0)];
        foreach (range(1994, 2023) as $year) {
            $events[] = $this->depreciation('acc', $year, 100000.0, AssetEvent::ORIGIN_IMPORT);
        }
        $settings = ['tax_method' => 'none', 'acc_method' => 'time', 'acc_months' => 360];

        $plan = $this->plan($settings, $events)['acc'];

        $planned = $plan->plannedRows();
        $this->assertCount(1, $planned);
        $this->assertSame(1800000.0, $planned[0]->amount);
        $this->assertSame('2024-12-31', $planned[0]->period?->end);
        $this->assertSame('1 800 000,00 / 1 × 1', $planned[0]->formula);
        $this->assertCount(1, $planned[0]->messages);
        $this->assertSame(PlanMessage::ACC_PERIOD_ELAPSED, $planned[0]->messages[0]->code);
        $this->assertSame(PlanMessage::SEVERITY_WARNING, $planned[0]->messages[0]->severity);
        $this->assertSame(['end' => '2023-12-31', 'amount' => 1800000.0], $planned[0]->messages[0]->params);
        $this->assertSame(1, array_count_values($this->codes($plan))[PlanMessage::ACC_PERIOD_ELAPSED]);
        $this->assertFalse($plan->hasErrors());

        // Delší doba na kartě (600 měsíců, do 12/2043): plán se přepočítá
        // rovnoměrně a varování zmizí.
        $plan = $this->plan(['acc_months' => 600] + $settings, $events)['acc'];

        $this->assertSame(90000.0, $plan->plannedRows()[0]->amount);
        $this->assertSame('1 800 000,00 / 240 × 12', $plan->plannedRows()[0]->formula);
        $this->assertNotContains(PlanMessage::ACC_PERIOD_ELAPSED, $this->codes($plan));
    }

    public function testAccountingTimeRoundsUpOncePerPeriod(): void
    {
        // 100 000 / 36 × 9 = 25 000; další rok 75 000 / 27 × 12 = 33 333,33 → 33 334,
        // (75 000 − 33 334) / 15 × 12 = 33 332,8 → 33 333, zbytek 8 333.
        $plan = $this->plan(
            ['tax_method' => 'none', 'acc_method' => 'time', 'acc_months' => 36],
            [$this->activation('2022-03-15', 100000.0)],
        )['acc'];

        $this->assertSame([25000.0, 33334.0, 33333.0, 8333.0], $this->amounts($plan));
    }

    public function testAccountingTimeWithImprovementYearly(): void
    {
        // D44: po TZ se (ZC + TZ) rozpustí do zbývajících měsíců původní doby.
        // TZ 12 000 v 6/2023: leden–červen 51 000 / 51 × 6 = 6 000, pak
        // (45 000 + 12 000) / 45 × 6 = 7 600 → 13 600. Dál 49 400 / 39 × 12
        // = 15 200 tři roky, 2027 zbylé 3 měsíce 3 800.
        // Starý engine počítal (VC + TZ) / celá doba = 1 200 měsíčně a doba
        // odpisování se mu prodloužila.
        $plan = $this->plan(
            ['tax_method' => 'none', 'acc_method' => 'time', 'acc_months' => 60],
            [$this->activation('2022-03-15', 60000.0), $this->improvement('2023-06-15', 12000.0)],
        )['acc'];

        $this->assertSame([9000.0, 13600.0, 15200.0, 15200.0, 15200.0, 3800.0], $this->amounts($plan));
        $this->assertSame('51 000,00 / 51 × 6 + 57 000,00 / 45 × 6', $plan->rows[3]->formula);
    }

    public function testAccountingTimeWithImprovementMonthly(): void
    {
        // Měsíčně: do června 2023 1 000, od července 57 000 / 45 = 1 266,67 →
        // 1 267. Zůstatek / zbývající měsíce se samo dorovnává: 30 × 1 267
        // + 15 × 1 266 = 57 000, konec v původním termínu 3/2027.
        $plan = $this->plan(
            ['tax_method' => 'none', 'acc_method' => 'time', 'acc_months' => 60],
            [$this->activation('2022-03-15', 60000.0), $this->improvement('2023-06-15', 12000.0)],
            acc: PeriodCalendar::monthly([]),
        )['acc'];

        $amounts = $this->amounts($plan);
        $this->assertCount(60, $amounts);
        $this->assertSame(array_fill(0, 15, 1000.0), array_slice($amounts, 0, 15));
        $this->assertSame(array_fill(0, 30, 1267.0), array_slice($amounts, 15, 30));
        $this->assertSame(array_fill(0, 15, 1266.0), array_slice($amounts, 45));
        $this->assertSame(72000.0, array_sum($amounts));

        $rows = $plan->rows;
        $this->assertSame('2022-04-30', $rows[1]->period->end);
        $this->assertSame('2027-03-31', $rows[count($rows) - 1]->period->end);
        // Roční součty se od roční četnosti liší jen zaokrouhlením po měsících.
        $this->assertSame(9000.0, $this->perYear($plan)['2022']);
        $this->assertSame(13602.0, $this->perYear($plan)['2023']);
    }

    public function testReductionInLastMonthDoesNotOverdepreciate(): void
    {
        // 24 000 / 12 měsíců od února 2022; snížení 500 v posledním měsíci
        // (1/2023) platí až od dalšího, zůstatek už ale zmenšilo — poslední
        // odpis je 1 500, ne 2 000, a zůstatek nejde do minusu.
        $plan = $this->plan(
            ['tax_method' => 'none', 'acc_method' => 'time', 'acc_months' => 12],
            [$this->activation('2022-01-10', 24000.0), $this->reduction('2023-01-15', 500.0)],
            acc: PeriodCalendar::monthly([]),
        )['acc'];

        $amounts = $this->amounts($plan);
        $this->assertSame(array_fill(0, 11, 2000.0), array_slice($amounts, 0, 11));
        $this->assertSame(1500.0, $amounts[11]);
        $this->assertSame(0.0, $plan->rows[count($plan->rows) - 1]->residual);
    }

    public function testAccountingTimeDepreciatesUpToMonthOfDisposal(): void
    {
        // Vyřazení 8/2023: 9 000, pak leden–srpen 51 000 / 51 × 8 = 8 000.
        $plan = $this->plan(
            ['tax_method' => 'none', 'acc_method' => 'time', 'acc_months' => 60],
            [$this->activation('2022-03-15', 60000.0), $this->disposal('2023-08-10', false)],
        )['acc'];

        $this->assertSame([9000.0, 8000.0], $this->amounts($plan));
        $this->assertSame('2023-08-10', $plan->rows[2]->date);
        $this->assertSame(['id' => null, 'begin' => '2023-01-01', 'end' => '2023-08-31'], $plan->rows[2]->period->toArray());
        $this->assertSame(43000.0, $plan->rows[3]->amount);
    }

    public function testAsTaxYearlyCopiesTaxFormula(): void
    {
        $plans = $this->plan(
            self::STRAIGHT_2 + ['acc_method' => 'as_tax'],
            [$this->activation('2022-03-15', 100000.0)],
        );

        $this->assertSame([11000.0, 22250.0, 22250.0, 22250.0, 22250.0], $this->amounts($plans['acc']));
        $this->assertSame('100 000,00 × 11 %', $plans['acc']->rows[1]->formula);
    }

    public function testAsTaxIgnoresTaxInterruption(): void
    {
        // Přerušení je jen daňové (D34) — účetní okruh běží dál vlastním
        // počitadlem let.
        $plans = $this->plan(
            self::STRAIGHT_2 + ['acc_method' => 'as_tax'],
            [$this->activation('2022-03-15', 100000.0), $this->interruption(2023)],
        );

        $this->assertSame(['2022' => 11000.0, '2024' => 22250.0], array_slice($this->perYear($plans['tax']), 0, 2, true));
        $this->assertSame(['2022' => 11000.0, '2023' => 22250.0], array_slice($this->perYear($plans['acc']), 0, 2, true));
    }

    public function testAsTaxMonthlySpreadsAnnualAmountOverMonthsInUse(): void
    {
        // D45: roční daňový odpis se rozpustí do měsíců v užívání, poslední
        // měsíc dorovná. 2022: 11 000 do dubna–prosince (2 × 1 223 + 7 × 1 222),
        // 2023: 22 250 do 12 měsíců (2 × 1 855 + 10 × 1 854).
        $plan = $this->plan(
            self::STRAIGHT_2 + ['acc_method' => 'as_tax'],
            [$this->activation('2022-03-15', 100000.0)],
            acc: PeriodCalendar::monthly([]),
        )['acc'];

        $amounts = $this->amounts($plan);
        $this->assertSame(
            [1223.0, 1223.0, 1222.0, 1222.0, 1222.0, 1222.0, 1222.0, 1222.0, 1222.0],
            array_slice($amounts, 0, 9),
        );
        $this->assertSame([1855.0, 1855.0, 1854.0], array_slice($amounts, 9, 3));
        $this->assertSame('2022-04-30', $plan->rows[1]->period->end);

        // Součet měsíců každého roku = roční daňový vzorec.
        $this->assertSame(
            ['2022' => 11000.0, '2023' => 22250.0, '2024' => 22250.0, '2025' => 22250.0, '2026' => 22250.0],
            $this->perYear($plan),
        );
    }

    public function testAsTaxMonthlyActivatedInLastMonthOfYear(): void
    {
        // Zařazení v prosinci: v roce nezbývá měsíc užívání, celý roční odpis
        // jde do prosince — roční součet je stejný jako při roční četnosti.
        $plan = $this->plan(
            self::STRAIGHT_2 + ['acc_method' => 'as_tax'],
            [$this->activation('2022-12-10', 100000.0)],
            acc: PeriodCalendar::monthly([]),
        )['acc'];

        $this->assertSame(11000.0, $plan->rows[1]->amount);
        $this->assertSame('2022-12-31', $plan->rows[1]->period->end);
        $this->assertSame('2023-01-31', $plan->rows[2]->period->end);
        $this->assertSame(
            ['2022' => 11000.0, '2023' => 22250.0, '2024' => 22250.0, '2025' => 22250.0, '2026' => 22250.0],
            $this->perYear($plan),
        );
    }

    public function testAsTaxAcceleratedMonthlyUsesResidualAtYearStart(): void
    {
        // Zrychlený vzorec počítá ze zůstatku na začátku roku, ne z průběžného:
        // 2023 = 2 × 80 000 / (6 − 1) = 32 000 i při měsíčních odpisech.
        $plan = $this->plan(
            self::ACCELERATED_2 + ['acc_method' => 'as_tax'],
            [$this->activation('2022-03-15', 100000.0)],
            acc: PeriodCalendar::monthly([]),
        )['acc'];

        $this->assertSame(
            ['2022' => 20000.0, '2023' => 32000.0, '2024' => 24000.0, '2025' => 16000.0, '2026' => 8000.0],
            $this->perYear($plan),
        );
    }

    public function testAsTaxInYearOfDisposalIsProportionalToMonthsInUse(): void
    {
        // Vyřazení 6/2023: 22 250 × 6 / 12 = 11 125, rozpuštěno do ledna–června
        // (1 855 + 5 × 1 854).
        $events = [$this->activation('2022-03-15', 100000.0), $this->disposal('2023-06-20', false)];
        $settings = self::STRAIGHT_2 + ['acc_method' => 'as_tax'];

        $yearly = $this->plan($settings, $events)['acc'];
        $this->assertSame([11000.0, 11125.0], $this->amounts($yearly));
        $this->assertSame('(100 000,00 × 22,25 %) × 6 / 12', $yearly->rows[2]->formula);

        $monthly = $this->plan($settings, $events, acc: PeriodCalendar::monthly([]))['acc'];
        $this->assertSame(['2022' => 11000.0, '2023' => 11125.0], $this->perYear($monthly));
        $this->assertSame([1855.0, 1854.0, 1854.0, 1854.0, 1854.0, 1854.0], array_slice($this->amounts($monthly), 9));
    }

    public function testAsTaxMonthlyWithImprovementMatchesTaxPlanPerYear(): void
    {
        // TZ 20 000 v 5/2023. Leden–duben běží ze staré roční částky 22 250
        // (1 855 + 1 855 + 1 854 + 1 854 = 7 418), od května se do zbylých
        // 8 měsíců rozpouští 120 000 × 20 % − 7 418 = 16 582 (první 2 073).
        $plans = $this->plan(
            self::STRAIGHT_2 + ['acc_method' => 'as_tax'],
            [$this->activation('2022-03-15', 100000.0), $this->improvement('2023-05-10', 20000.0)],
            acc: PeriodCalendar::monthly([]),
        );

        $amounts = $this->amounts($plans['acc']);
        $this->assertSame([1855.0, 1855.0, 1854.0, 1854.0, 2073.0], array_slice($amounts, 9, 5));
        $this->assertSame($this->perYear($plans['tax']), $this->perYear($plans['acc']));
        $this->assertSame(24000.0, $this->perYear($plans['acc'])['2023']);
    }

    public function testConfirmedYearlyHistoryContinuesMonthly(): void
    {
        // Četnost přepnutá na měsíční: potvrzený roční odpis 2022 zůstává,
        // plán pokračuje lednem 2023 po měsících.
        $confirmed = $this->depreciation('acc', 2022, 9000.0);
        $plan = $this->plan(
            ['tax_method' => 'none', 'acc_method' => 'time', 'acc_months' => 60],
            [$this->activation('2022-03-15', 60000.0), $confirmed],
            acc: PeriodCalendar::monthly([]),
        )['acc'];

        $this->assertSame([], $plan->allMessages());
        $this->assertSame(9000.0, $plan->rows[1]->computed);
        $this->assertSame('2023-01-31', $plan->rows[2]->period->end);
        $this->assertSame(1000.0, $plan->rows[2]->amount);
        $this->assertCount(51, $plan->plannedRows());
    }

    public function testAsTaxOverMonthlyTaxMethod(): void
    {
        // Časová daňová metoda: účetní okruh jde po stejném rozpisu.
        $plans = $this->plan(
            ['tax_method' => 'time', 'tax_rule' => 'cz-nim-software', 'acc_method' => 'as_tax'],
            [$this->activation('2019-05-20', 72000.0)],
            acc: PeriodCalendar::monthly([]),
        );

        $this->assertSame([14000.0, 24000.0, 24000.0, 10000.0], $this->amounts($plans['tax']));
        $this->assertSame(array_fill(0, 36, 2000.0), $this->amounts($plans['acc']));
    }

    // --- daňová metoda „podle účetních odpisů“ --------------------------------

    public function testTaxFromAccountingSumsAccountingPlanPerYear(): void
    {
        $settings = ['tax_method' => 'accounting', 'acc_method' => 'time', 'acc_months' => 60, 'intangible' => true];
        $events = [$this->activation('2022-03-15', 60000.0)];
        $expected = [9000.0, 12000.0, 12000.0, 12000.0, 12000.0, 3000.0];

        $this->assertSame($expected, $this->amounts($this->plan($settings, $events)['tax']));
        $this->assertSame(
            $expected,
            $this->amounts($this->plan($settings, $events, acc: PeriodCalendar::monthly([]))['tax']),
        );
    }

    public function testCountryWithoutRulesCanOnlyFollowAccounting(): void
    {
        $plans = $this->plan(
            ['tax_method' => 'accounting', 'acc_method' => 'time', 'acc_months' => 24],
            [$this->activation('2023-06-10', 48000.0)],
            rules: new AccountingOnlyTaxRules('sk'),
        );

        // 48 000 / 24 = 2 000 měsíčně od července 2023.
        $this->assertSame([12000.0, 24000.0, 12000.0], $this->amounts($plans['acc']));
        $this->assertSame([12000.0, 24000.0, 12000.0], $this->amounts($plans['tax']));
    }

    // --- potvrzená historie a hlášení -----------------------------------------

    public function testConfirmedDepreciationIsTakenOverAndMismatchReported(): void
    {
        // Potvrzeno 10 000 místo 11 000: engine nepřepočítává, jen hlásí.
        // Plán pokračuje ze skutečného zůstatku — rozdíl doběhne v 6. roce.
        $plan = $this->plan(self::STRAIGHT_2, [
            $this->activation('2022-03-15', 100000.0),
            $this->depreciation('tax', 2022, 10000.0),
        ])['tax'];

        $confirmed = $plan->rows[1];
        $this->assertSame(PlanRow::STATUS_CONFIRMED, $confirmed->status);
        $this->assertSame(10000.0, $confirmed->amount);
        $this->assertSame(11000.0, $confirmed->computed);
        $this->assertSame('100 000,00 × 11 %', $confirmed->formula);
        $this->assertSame(90000.0, $confirmed->residual);

        $this->assertCount(1, $confirmed->messages);
        $this->assertSame(PlanMessage::MISMATCH, $confirmed->messages[0]->code);
        $this->assertSame(PlanMessage::SEVERITY_WARNING, $confirmed->messages[0]->severity);
        $this->assertSame(['confirmed' => 10000.0, 'computed' => 11000.0], $confirmed->messages[0]->params);

        $this->assertFalse($plan->hasErrors());
        $this->assertSame([22250.0, 22250.0, 22250.0, 22250.0, 1000.0], $this->planned($plan));
    }

    public function testUnconfirmedEventsAreIgnored(): void
    {
        $draft = new AssetEvent(
            AssetEvent::KIND_DEPRECIATION, AssetEvent::SCOPE_TAX, '2022-12-31', 5000.0,
            periodBegin: '2022-01-01', periodEnd: '2022-12-31', confirmed: false,
        );
        $plan = $this->plan(self::STRAIGHT_2, [$this->activation('2022-03-15', 100000.0), $draft])['tax'];

        $this->assertSame([11000.0, 22250.0, 22250.0, 22250.0, 22250.0], $this->planned($plan));
    }

    public function testGapInConfirmedDepreciationIsAnError(): void
    {
        // Rok 2021 nemá odpis ani přerušení. Do počtu let se nepočítá —
        // rok 2022 je druhý odpisovaný (22 250 sedí).
        $plan = $this->plan(self::STRAIGHT_2, [
            $this->activation('2020-02-10', 100000.0),
            $this->depreciation('tax', 2020, 11000.0),
            $this->depreciation('tax', 2022, 22250.0),
        ])['tax'];

        $this->assertSame([PlanMessage::MISSING_PERIOD], $this->codes($plan));
        $this->assertSame(PlanMessage::MISSING_PERIOD, $plan->rows[2]->messages[0]->code);
        $this->assertTrue($plan->hasErrors());
    }

    public function testDepreciationDateOutsideFiscalYearOfItsPeriod(): void
    {
        $wrongDate = new AssetEvent(
            AssetEvent::KIND_DEPRECIATION, AssetEvent::SCOPE_TAX, '2023-01-15', 11000.0,
            periodBegin: '2022-01-01', periodEnd: '2022-12-31',
        );
        $plan = $this->plan(self::STRAIGHT_2, [$this->activation('2022-03-15', 100000.0), $wrongDate])['tax'];

        $this->assertSame([PlanMessage::DATE_OUTSIDE_PERIOD], $this->codes($plan));
        $this->assertTrue($plan->hasErrors());
    }

    public function testNotWholeAmountIsAnErrorExceptForImport(): void
    {
        // D10: necelé koruny smí mít jen importovaná historie.
        $events = static fn(AssetEvent $depreciation): array => [
            new AssetEvent(AssetEvent::KIND_ACTIVATION, AssetEvent::SCOPE_BOTH, '2022-03-15', 100000.0),
            $depreciation,
        ];

        $manual = $this->plan(self::STRAIGHT_2, $events($this->depreciation('tax', 2022, 10999.5)))['tax'];
        $this->assertSame([PlanMessage::MISMATCH, PlanMessage::NOT_WHOLE_UNITS], $this->codes($manual));
        $this->assertTrue($manual->hasErrors());

        $imported = $this->plan(
            self::STRAIGHT_2,
            $events($this->depreciation('tax', 2022, 10999.5, AssetEvent::ORIGIN_IMPORT)),
        )['tax'];
        $this->assertSame([PlanMessage::MISMATCH], $this->codes($imported));
        $this->assertFalse($imported->hasErrors());
    }

    public function testClaimUnrecordedFlagIsCarriedToPlanRow(): void
    {
        // D11: historický odpis „uplatněná částka neevidována“ snižuje
        // zůstatek a počítá se do let jako každý jiný.
        $historic = new AssetEvent(
            AssetEvent::KIND_DEPRECIATION, AssetEvent::SCOPE_TAX, '2022-12-31', 11000.0,
            periodBegin: '2022-01-01', periodEnd: '2022-12-31',
            origin: AssetEvent::ORIGIN_IMPORT, claimUnrecorded: true, id: 77,
        );
        $plan = $this->plan(self::STRAIGHT_2, [$this->activation('2022-03-15', 100000.0), $historic])['tax'];

        $this->assertTrue($plan->rows[1]->claimUnrecorded);
        $this->assertSame(77, $plan->rows[1]->eventId);
        $this->assertSame(22250.0, $plan->rows[2]->amount);
        $this->assertSame([], $plan->allMessages());
    }

    public function testRuleNotValidForAcquisitionDateBlocksPlan(): void
    {
        // Skupina 6 vznikla až 2004.
        $plan = $this->plan(
            ['tax_method' => 'straight', 'tax_rule' => 'cz-6'],
            [$this->activation('2003-03-15', 600000.0), $this->depreciation('tax', 2003, 6120.0)],
        )['tax'];

        $this->assertCount(1, $plan->messages);
        $this->assertSame(PlanMessage::RULE_NOT_VALID, $plan->messages[0]->code);
        $this->assertSame(
            ['rule' => 'cz-6', 'method' => 'straight', 'date' => '2003-03-15'],
            $plan->messages[0]->params,
        );
        $this->assertTrue($plan->hasErrors());
        // Potvrzené události v plánu zůstávají, jen bez výpočtu a bez plánu.
        $this->assertSame(['activation', 'depreciation'], $this->kinds($plan));
        $this->assertNull($plan->rows[1]->computed);
        $this->assertSame(593880.0, $plan->residual);
    }

    public function testRuleValidityFollowsOriginalDateOfOpeningBalance(): void
    {
        // Počáteční stav k 2024, původní zařazení 2003 (skupina 2 tehdy 6 let:
        // 8,5 % + 18,3 % ročně) — sazby se volí podle původního data (D43).
        $opening = new AssetEvent(
            AssetEvent::KIND_OPENING, AssetEvent::SCOPE_TAX, '2024-01-01', 100000.0,
            accumulated: 81700.0, unitsDone: 5, originalDate: '2003-06-30',
        );
        $plan = $this->plan(self::STRAIGHT_2, [$opening])['tax'];

        $this->assertSame([], $plan->allMessages());
        $this->assertSame([18300.0], $this->amounts($plan));
    }

    public function testInvalidCombinationsOfMethods(): void
    {
        $events = [$this->activation('2022-03-15', 60000.0)];
        $reason = static fn(Plan $plan): array => array_map(
            static fn(PlanMessage $m): string => $m->code . ':' . $m->params['reason'],
            $plan->messages,
        );

        // Daňově „podle účetních“ a účetně „jako daňové“ — kruh.
        $plans = $this->plan(['tax_method' => 'accounting', 'acc_method' => 'as_tax'], $events);
        $this->assertSame(['settingsInvalid:accountingWithoutAccMethod'], $reason($plans['tax']));
        $this->assertSame(['settingsInvalid:asTaxWithoutFormula'], $reason($plans['acc']));
        $this->assertSame([], $plans['acc']->plannedRows());

        // Daňově se neodepisuje — účetní „jako daňové“ nemá vzorec.
        $plans = $this->plan(['tax_method' => 'none', 'acc_method' => 'as_tax'], $events);
        $this->assertSame([], $plans['tax']->messages);
        $this->assertSame(['settingsInvalid:asTaxWithoutFormula'], $reason($plans['acc']));

        // Časová účetní metoda bez délky.
        $plans = $this->plan(['tax_method' => 'none', 'acc_method' => 'time'], $events);
        $this->assertSame(['settingsInvalid:accMonthsMissing'], $reason($plans['acc']));

        // Metoda, kterou pravidla státu neznají.
        $plans = $this->plan(['tax_method' => 'units'], $events);
        $this->assertSame(['settingsInvalid:unknownTaxMethod'], $reason($plans['tax']));
    }

    // --- neodepisovaný majetek, souhrn ---------------------------------------

    public function testNonDepreciableAssetShowsOnlyValueHistory(): void
    {
        $plans = $this->plan([], [
            $this->activation('2022-03-15', 100000.0),
            $this->improvement('2023-05-10', 20000.0),
            $this->disposal('2024-08-10', false),
        ]);

        foreach ($plans as $plan) {
            $this->assertSame(['activation', 'improvement', 'disposal'], $this->kinds($plan));
            $this->assertSame(120000.0, $plan->rows[2]->amount);
            $this->assertSame([], $plan->allMessages());
        }
    }

    public function testCardWithoutActivationHasEmptyPlan(): void
    {
        $plans = $this->plan(self::STRAIGHT_2 + ['acc_method' => 'as_tax'], []);

        $this->assertSame([], $plans['tax']->rows);
        $this->assertSame([], $plans['acc']->rows);
        $this->assertSame(0.0, $plans['tax']->entryPrice);
    }

    public function testSummaryReflectsConfirmedStateAndCurrentYear(): void
    {
        $plans = $this->plan(
            self::STRAIGHT_2 + ['acc_method' => 'time', 'acc_months' => 60],
            [
                $this->activation('2022-03-15', 100000.0),
                $this->improvement('2023-05-10', 20000.0),
                $this->depreciation('tax', 2022, 11000.0),
                $this->depreciation('tax', 2023, 24000.0),
            ],
            asOf: '2024-06-30',
        );

        $tax = $plans['tax'];
        $this->assertSame('tax', $tax->circuit);
        $this->assertSame(120000.0, $tax->entryPrice);
        $this->assertSame(35000.0, $tax->accumulated);
        $this->assertSame(85000.0, $tax->residual);
        // Letošní odpis = plánovaný odpis roku 2024.
        $this->assertSame(24000.0, $tax->currentYearAmount);

        // Účetní okruh nemá potvrzený žádný odpis — souhrn je jen vstupní cena.
        $acc = $plans['acc'];
        $this->assertSame(120000.0, $acc->entryPrice);
        $this->assertSame(0.0, $acc->accumulated);
        $this->assertSame(120000.0, $acc->residual);

        $array = $tax->toArray();
        $this->assertSame('tax', $array['circuit']);
        $this->assertSame('depreciation', $array['rows'][1]['kind']);
        $this->assertSame('confirmed', $array['rows'][1]['status']);
    }

    public function testDisposedAssetHasZeroResidualInSummary(): void
    {
        $plan = $this->plan(self::STRAIGHT_2, [
            $this->activation('2022-03-15', 100000.0),
            $this->depreciation('tax', 2022, 11000.0),
            $this->disposal('2023-04-01', false),
        ])['tax'];

        $this->assertSame(0.0, $plan->residual);
        $this->assertSame(11000.0, $plan->accumulated);
        $this->assertSame(89000.0, $plan->rows[2]->amount);
    }
}

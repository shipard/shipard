<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\World\Assets;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\World\Assets\CzTaxDepreciationRules;
use Shipard\Module\World\Assets\TaxDepreciationRules;
use Shipard\Module\World\Assets\TaxScheduleInput;
use Shipard\Module\World\Assets\TaxYearInput;

/**
 * Pravidla CZ nad **skutečným** `assets-cz.jsonc` (vzor VatRateResolverTest)
 * — testy hlídají i data: sazby § 31, koeficienty § 32, rozpis § 30a.
 * tasks/assets-phase2a.md.
 */
class CzTaxDepreciationRulesTest extends TestCase
{
    private const CONFIG = __DIR__ . '/../../../../../modules/world/assets/config/assets-cz.jsonc';

    private static ?array $cfg = null;

    private function rules(?array $cfg = null): CzTaxDepreciationRules
    {
        self::$cfg ??= JsoncParser::parseFile(self::CONFIG);
        return new CzTaxDepreciationRules($cfg ?? self::$cfg);
    }

    private function year(
        string $method,
        string $rule,
        float $entryPrice,
        float $residual,
        int $yearsApplied,
        bool $increased = false,
        int $yearsSinceIncrease = 0,
        bool $halfYear = false,
        string $acquired = '2022-03-15',
        bool $shortPeriod = false,
    ): TaxYearInput {
        return new TaxYearInput(
            $method, $rule, $acquired, $entryPrice, $residual,
            $yearsApplied, $increased, $yearsSinceIncrease, $halfYear, $shortPeriod,
        );
    }

    // --- konfigurace --------------------------------------------------------

    public function testRealConfigIsConsistent(): void
    {
        $this->assertSame([], $this->rules()->validateConfig());
    }

    public function testValidateConfigReportsBrokenReferences(): void
    {
        $errors = $this->rules([
            'methods' => ['straight' => ['kind' => 'yearly']],
            'interruptible' => ['accelerated'],
            'groups' => ['x-1' => ['name' => 'G1'], 'x-1-b' => ['group' => 'x-9']],
            'straightRates' => [
                ['code' => 'x-1', 'from' => null, 'to' => '2010-12-31', 'first' => 10, 'next' => 20, 'increased' => 20],
                ['code' => 'x-1', 'from' => '2010-01-01', 'to' => null, 'first' => 10, 'next' => 20, 'increased' => 0],
                ['code' => 'x-2', 'from' => null, 'to' => null, 'first' => 10, 'next' => 20, 'increased' => 20],
            ],
            'extraordinaryRules' => [
                'x-e' => ['schedule' => [['months' => 12, 'pct' => 60], ['months' => 12, 'pct' => 30]]],
            ],
        ])->validateConfig();

        $all = implode("\n", $errors);
        $this->assertStringContainsString("unknown kind 'yearly'", $all);
        $this->assertStringContainsString("interruptible: unknown method 'accelerated'", $all);
        $this->assertStringContainsString("unknown group 'x-9'", $all);
        $this->assertStringContainsString("interval of 'x-1' overlaps", $all);
        $this->assertStringContainsString("'increased' must be positive", $all);
        $this->assertStringContainsString("unknown group 'x-2'", $all);
        $this->assertStringContainsString('schedule sums to 90 %', $all);
    }

    // --- nabídka metod a pravidel -------------------------------------------

    public function testAvailableMethodsForTangibleAssetFollowExtraordinaryValidity(): void
    {
        $r = $this->rules();
        $this->assertSame(
            ['straight', 'accelerated', 'accounting', 'none'],
            $r->availableMethods('2019-06-01', false),
        );
        // § 30a: skupiny 1 a 2 pořízené 2020–2023, bezemisní vozidla 2024–2028.
        $this->assertSame(
            ['straight', 'accelerated', 'extraordinary', 'accounting', 'none'],
            $r->availableMethods('2022-06-01', false),
        );
        $this->assertContains('extraordinary', $r->availableMethods('2024-06-01', false));
        $this->assertNotContains('extraordinary', $r->availableMethods('2029-01-01', false));
    }

    public function testIntangibleAssetFrom2021HasOnlyAccountingAndNone(): void
    {
        $r = $this->rules();
        $this->assertSame(['accounting', 'none'], $r->availableMethods('2022-02-01', true));
        $this->assertSame(['accounting', 'none'], $r->availableMethods('2021-01-01', true));
        $this->assertSame(['time', 'accounting', 'none'], $r->availableMethods('2020-12-31', true));
    }

    public function testRulesOfAnnualMethodsRespectMethodRestrictionAndValidity(): void
    {
        $r = $this->rules();

        $straight = array_column($r->rules('straight', '2022-05-01'), 'code');
        $this->assertCount(15, $straight);
        $this->assertContains('cz-2-b10', $straight);

        // Varianty se zvýšeným odpisem 1. roku jsou jen rovnoměrné (D42).
        $this->assertSame(
            ['cz-1', 'cz-2', 'cz-3', 'cz-4', 'cz-5', 'cz-6'],
            array_column($r->rules('accelerated', '2022-05-01'), 'code'),
        );

        // Skupina 6 vznikla 2004, varianty +10/15/20 % máme od 2005.
        $this->assertSame(
            ['cz-1', 'cz-2', 'cz-3', 'cz-4', 'cz-5'],
            array_column($r->rules('straight', '2003-05-01'), 'code'),
        );
        $this->assertSame('Depreciation group 2', $r->rules('straight', '2022-05-01')[4]['name']);
    }

    public function testRulesOfMonthlyMethods(): void
    {
        $r = $this->rules();
        $this->assertSame(
            ['cz-30a-1', 'cz-30a-2'],
            array_column($r->rules('extraordinary', '2021-05-10'), 'code'),
        );
        $this->assertSame(['cz-30a-ev'], array_column($r->rules('extraordinary', '2024-01-01'), 'code'));
        $this->assertSame([], $r->rules('extraordinary', '2019-12-31'));

        $this->assertCount(5, $r->rules('time', '2019-05-01'));
        $this->assertSame([], $r->rules('time', '2021-01-01'));

        $this->assertSame([], $r->rules('accounting', '2022-01-01'));
        $this->assertSame([], $r->rules('none', '2022-01-01'));
    }

    public function testMethodFlags(): void
    {
        $r = $this->rules();
        $this->assertSame(TaxDepreciationRules::KIND_ANNUAL, $r->methodKind('straight'));
        $this->assertSame(TaxDepreciationRules::KIND_ANNUAL, $r->methodKind('accelerated'));
        $this->assertSame(TaxDepreciationRules::KIND_MONTHLY, $r->methodKind('extraordinary'));
        $this->assertSame(TaxDepreciationRules::KIND_MONTHLY, $r->methodKind('time'));
        $this->assertSame(TaxDepreciationRules::KIND_ACCOUNTING, $r->methodKind('accounting'));
        $this->assertSame(TaxDepreciationRules::KIND_NONE, $r->methodKind('none'));
        $this->assertNull($r->methodKind('units'));

        $this->assertTrue($r->isInterruptible('straight'));
        $this->assertFalse($r->isInterruptible('extraordinary'));
        $this->assertFalse($r->isInterruptible('time'));

        $this->assertTrue($r->allowsHalfYearOnDisposal('accelerated'));
        $this->assertFalse($r->allowsHalfYearOnDisposal('time'));

        $this->assertSame('Straight-line', $r->methodName('straight'));
        $this->assertSame('units', $r->methodName('units'));

        $this->assertTrue($r->allowsShortPeriodHalfYear('straight'));
        $this->assertTrue($r->allowsShortPeriodHalfYear('accelerated'));
        $this->assertFalse($r->allowsShortPeriodHalfYear('extraordinary'));

        // § 30a odst. 3: TZ mimořádně odpisovaného majetku se odpisuje samostatně.
        $this->assertFalse($r->allowsImprovement('extraordinary'));
        $this->assertTrue($r->allowsImprovement('straight'));
        $this->assertTrue($r->allowsImprovement('time'));
        $this->assertSame('cz', $r->country());
    }

    // --- zaokrouhlení -------------------------------------------------------

    public function testWithoutAcquisitionDateAllMethodsAndRulesAreOffered(): void
    {
        // Karta před zařazením: nabídka bez ohledu na platnost k datu.
        $r = $this->rules();
        $this->assertSame(['straight', 'accelerated', 'extraordinary', 'accounting', 'none'], $r->availableMethods(null, false));
        $this->assertSame(['time', 'accounting', 'none'], $r->availableMethods(null, true));

        $all = array_column($r->rules('extraordinary', null), 'code');
        $this->assertContains('cz-30a-2', $all);
        $this->assertSame([], array_diff(array_column($r->rules('extraordinary', '2022-05-01'), 'code'), $all));
    }

    public function testRoundCeilsToWholeCrowns(): void
    {
        $r = $this->rules();
        $this->assertSame(2576.0, $r->round(2575.01));
        $this->assertSame(2575.0, $r->round(2575.0));
        $this->assertSame(0.0, $r->round(0.0));
    }

    public function testRoundIsImmuneToFloatingPointNoise(): void
    {
        // 50 000 × 5,15 % = 2 575 přesně, ale v plovoucí čárce 2575.0000000000005
        // — prosté ceil() dá 2 576 (starý engine).
        $naive = 50000 * 5.15 / 100.0;
        $this->assertSame(2576.0, ceil($naive));
        $this->assertSame(2575.0, $this->rules()->round($naive));

        $amount = $this->rules()->annualAmount(
            $this->year('straight', 'cz-4', 50000.0, 40000.0, 1),
        );
        $this->assertSame(2575.0, $amount->amount);
    }

    public function testRoundRespectsConfiguredModeAndPrecision(): void
    {
        $this->assertSame(10.13, $this->rules(['rounding' => ['mode' => 'round', 'precision' => 2]])->round(10.125001));
        $this->assertSame(10.0, $this->rules(['rounding' => ['mode' => 'floor', 'precision' => 0]])->round(10.99));
    }

    // --- rovnoměrné odpisy (§ 31) -------------------------------------------

    public function testStraightGroup2FirstAndNextYears(): void
    {
        $r = $this->rules();

        $first = $r->annualAmount($this->year('straight', 'cz-2', 100000.0, 100000.0, 0));
        $this->assertSame(11000.0, $first->amount);
        $this->assertSame('100 000,00 × 11 %', $first->formula);

        $next = $r->annualAmount($this->year('straight', 'cz-2', 100000.0, 89000.0, 1));
        $this->assertSame(22250.0, $next->amount);
        $this->assertSame('100 000,00 × 22,25 %', $next->formula);
    }

    public function testStraightIncreasedPriceUsesIncreasedRateAndIsCappedByResidual(): void
    {
        $r = $this->rules();

        // VC 100 000 + TZ 20 000: 120 000 × 20 % = 24 000.
        $increased = $r->annualAmount($this->year('straight', 'cz-2', 120000.0, 109000.0, 1, true));
        $this->assertSame(24000.0, $increased->amount);
        $this->assertSame('120 000,00 × 20 %', $increased->formula);

        $last = $r->annualAmount($this->year('straight', 'cz-2', 120000.0, 13000.0, 5, true, 4));
        $this->assertSame(13000.0, $last->amount);
        $this->assertSame('min(120 000,00 × 20 %; 13 000,00)', $last->formula);
        $this->assertSame(24000.0, $last->exact);
    }

    public function testStraightHalfYearOnDisposal(): void
    {
        $half = $this->rules()->annualAmount(
            $this->year('straight', 'cz-2', 100000.0, 66750.0, 2, halfYear: true),
        );
        $this->assertSame(11125.0, $half->amount);
        $this->assertSame('(100 000,00 × 22,25 %) / 2', $half->formula);
    }

    public function testShortTaxPeriodGivesHalfOfAnnualAmount(): void
    {
        // § 26 odst. 7 písm. a) bod 3 (D46).
        $r = $this->rules();

        $short = $r->annualAmount($this->year('straight', 'cz-2', 100000.0, 66750.0, 2, shortPeriod: true));
        $this->assertSame(11125.0, $short->amount);
        $this->assertSame('(100 000,00 × 22,25 %) / 2', $short->formula);

        // První rok odpisování v krátkém období: polovina sazby 1. roku.
        $first = $r->annualAmount($this->year('straight', 'cz-2', 100000.0, 100000.0, 0, shortPeriod: true));
        $this->assertSame(5500.0, $first->amount);

        $accelerated = $r->annualAmount($this->year('accelerated', 'cz-2', 100000.0, 48000.0, 2, shortPeriod: true));
        $this->assertSame(12000.0, $accelerated->amount);
        $this->assertSame('(2 × 48 000,00 / (6 − 2)) / 2', $accelerated->formula);
    }

    public function testShortPeriodWithDisposalIsStillOneHalf(): void
    {
        $both = $this->rules()->annualAmount(
            $this->year('straight', 'cz-2', 100000.0, 66750.0, 2, halfYear: true, shortPeriod: true),
        );
        $this->assertSame(11125.0, $both->amount);
        $this->assertSame('(100 000,00 × 22,25 %) / 2', $both->formula);
    }

    public function testShortPeriodIsARuleOfTheCountryConfig(): void
    {
        // Bez `shortPeriodHalfYear` v konfiguraci se krátké období nekrátí.
        $cfg = self::$cfg;
        unset($cfg['shortPeriodHalfYear']);

        $full = $this->rules($cfg)->annualAmount(
            $this->year('straight', 'cz-2', 100000.0, 66750.0, 2, shortPeriod: true),
        );
        $this->assertSame(22250.0, $full->amount);
        $this->assertSame('100 000,00 × 22,25 %', $full->formula);
    }

    public function testStraightRatesFollowAcquisitionDate(): void
    {
        $r = $this->rules();
        // Skupina 2: do 1998 8 let (6,2 %), 1999–2004 6 let (8,5 %), od 2005 5 let (11 %).
        $this->assertSame(6200.0, $r->annualAmount($this->year('straight', 'cz-2', 100000.0, 100000.0, 0, acquired: '1998-12-31'))->amount);
        $this->assertSame(8500.0, $r->annualAmount($this->year('straight', 'cz-2', 100000.0, 100000.0, 0, acquired: '2003-06-30'))->amount);
        $this->assertSame(18300.0, $r->annualAmount($this->year('straight', 'cz-2', 100000.0, 91500.0, 1, acquired: '2003-06-30'))->amount);
        $this->assertSame(11000.0, $r->annualAmount($this->year('straight', 'cz-2', 100000.0, 100000.0, 0, acquired: '2005-01-01'))->amount);
    }

    public function testStraightVariantWithIncreasedFirstYear(): void
    {
        $r = $this->rules();
        // Skupina 1 (+10 %): 30 % a 35 % + 35 % = 100 %.
        $this->assertSame(27000.0, $r->annualAmount($this->year('straight', 'cz-1-b10', 90000.0, 90000.0, 0))->amount);
        $this->assertSame(31500.0, $r->annualAmount($this->year('straight', 'cz-1-b10', 90000.0, 63000.0, 1))->amount);
    }

    // --- zrychlené odpisy (§ 32) --------------------------------------------

    public function testAcceleratedGroup2FullSequence(): void
    {
        // VC 100 000: 100 000/5, 2×80 000/(6−1), 2×48 000/(6−2), 2×24 000/(6−3), 2×8 000/(6−4).
        $r = $this->rules();
        $residual = 100000.0;
        $amounts = [];
        $formulas = [];
        for ($year = 0; $residual > 0; $year++) {
            $a = $r->annualAmount($this->year('accelerated', 'cz-2', 100000.0, $residual, $year));
            $amounts[] = $a->amount;
            $formulas[] = $a->formula;
            $residual -= $a->amount;
        }
        $this->assertSame([20000.0, 32000.0, 24000.0, 16000.0, 8000.0], $amounts);
        $this->assertSame('100 000,00 / 5', $formulas[0]);
        $this->assertSame('2 × 48 000,00 / (6 − 2)', $formulas[2]);
    }

    public function testAcceleratedAfterImprovementCountsYearsFromIncrease(): void
    {
        // § 32 odst. 3: po dvou letech (ZC 48 000) TZ 20 000 → zvýšená ZC 68 000.
        //   rok TZ:   2 × 68 000 / 5       = 27 200
        //   další:    2 × 40 800 / (5 − 1) = 20 400
        //             2 × 20 400 / (5 − 2) = 13 600
        //             2 ×  6 800 / (5 − 3) =  6 800
        // Starý engine po TZ počitadlo let nenuloval (2 × ZC / (5 − 2) hned
        // v roce TZ) — odpisoval tak rychleji, než zákon dovoluje.
        $r = $this->rules();
        $residual = 68000.0;
        $amounts = [];
        for ($sinceIncrease = 0; $residual > 0; $sinceIncrease++) {
            $a = $r->annualAmount(
                $this->year('accelerated', 'cz-2', 120000.0, $residual, 2 + $sinceIncrease, true, $sinceIncrease),
            );
            $amounts[] = $a->amount;
            $residual -= $a->amount;
        }
        $this->assertSame([27200.0, 20400.0, 13600.0, 6800.0], $amounts);

        $inYearOfIncrease = $r->annualAmount($this->year('accelerated', 'cz-2', 120000.0, 68000.0, 2, true, 0));
        $this->assertSame('2 × 68 000,00 / 5', $inYearOfIncrease->formula);
    }

    public function testAcceleratedBeyondCoefficientTakesWholeResidual(): void
    {
        // Doba odpisování uplynula, zůstatek zbyl (např. po snížení hodnoty).
        $a = $this->rules()->annualAmount($this->year('accelerated', 'cz-1', 90000.0, 500.0, 4));
        $this->assertSame(500.0, $a->amount);
    }

    public function testAnnualAmountRejectsRuleNotValidForMethodOrDate(): void
    {
        $r = $this->rules();
        try {
            $r->annualAmount($this->year('accelerated', 'cz-1-b10', 90000.0, 90000.0, 0));
            $this->fail('Varianta +10 % nemá koeficient zrychleného odpisu');
        } catch (\LogicException $e) {
            $this->assertStringContainsString("'cz-1-b10'", $e->getMessage());
        }

        $this->expectException(\LogicException::class);
        $r->annualAmount($this->year('straight', 'cz-6', 100000.0, 100000.0, 0, acquired: '2003-12-31'));
    }

    // --- mimořádné odpisy (§ 30a) -------------------------------------------

    public function testExtraordinaryGroup2SplitsSixtyAndFortyPercent(): void
    {
        // VC 120 000, zařazení 5/2021 → rozpis od 6/2021:
        //   2021: 7 měsíců × 6 000 (60 % / 12)             = 42 000
        //   2022: 5 × 6 000 + 7 × 4 000 (40 % / 12)        = 58 000
        //   2023: zbylých 5 měsíců                          = 20 000
        $r = $this->rules();
        $input = static fn(float $residual, int $done, int $months): TaxScheduleInput => new TaxScheduleInput(
            'extraordinary', 'cz-30a-2', '2021-05-10', 120000.0, $residual, $done, $months,
        );

        $this->assertSame(24, $r->scheduleMonths($input(120000.0, 0, 7)));

        $y1 = $r->scheduleAmount($input(120000.0, 0, 7));
        $this->assertSame(42000.0, $y1->amount);
        $this->assertSame('120 000,00 × 60 % / 12 × 7', $y1->formula);

        $y2 = $r->scheduleAmount($input(78000.0, 7, 12));
        $this->assertSame(58000.0, $y2->amount);
        $this->assertSame('120 000,00 × 60 % / 12 × 5 + 120 000,00 × 40 % / 12 × 7', $y2->formula);

        // Období přesahuje konec rozpisu — počítá se jen zbylých 5 měsíců.
        $y3 = $r->scheduleAmount($input(20000.0, 19, 12));
        $this->assertSame(20000.0, $y3->amount);
        $this->assertSame('120 000,00 × 40 % / 12 × 5', $y3->formula);

        $this->assertSame(0.0, $r->scheduleAmount($input(0.0, 24, 12))->amount);
    }

    public function testExtraordinaryGroup1IsHundredPercentInTwelveMonths(): void
    {
        $r = $this->rules();
        $a = $r->scheduleAmount(new TaxScheduleInput(
            'extraordinary', 'cz-30a-1', '2021-12-05', 50000.0, 50000.0, 0, 12,
        ));
        $this->assertSame(50000.0, $a->amount);
    }

    public function testLastScheduleSegmentTakesWholeResidual(): void
    {
        // Historie odepsala méně, než odpovídá rozpisu — poslední úsek dorovná do 100 %.
        $a = $this->rules()->scheduleAmount(new TaxScheduleInput(
            'extraordinary', 'cz-30a-2', '2021-05-10', 120000.0, 21500.0, 19, 5,
        ));
        $this->assertSame(21500.0, $a->amount);
        $this->assertSame(20000.0, $a->exact);
    }

    // --- časové odpisy nehmotného majetku (§ 32a do 2020) --------------------

    public function testTimeSoftwareThirtySixMonths(): void
    {
        // VC 72 000 / 36 měsíců = 2 000 měsíčně.
        $r = $this->rules();
        $a = $r->scheduleAmount(new TaxScheduleInput(
            'time', 'cz-nim-software', '2019-05-20', 72000.0, 72000.0, 0, 7,
        ));
        $this->assertSame(14000.0, $a->amount);
        $this->assertSame('72 000,00 / 36 × 7', $a->formula);

        // Zaokrouhluje se součet měsíců období, ne jednotlivé měsíce.
        $odd = $r->scheduleAmount(new TaxScheduleInput(
            'time', 'cz-nim-software', '2019-05-20', 100000.0, 100000.0, 0, 7,
        ));
        $this->assertSame(19445.0, $odd->amount);
        $this->assertEqualsWithDelta(19444.4444, $odd->exact, 0.0001);
    }

    public function testTimeAfterImprovementRunsAtLeastMinimumMonths(): void
    {
        // § 32a odst. 6: po zbývající dobu, nejméně však 18 měsíců (software).
        $r = $this->rules();
        $input = static fn(string $rule, int $remaining): TaxScheduleInput => new TaxScheduleInput(
            'time', $rule, '2018-02-01', 60000.0, 60000.0, 0, 12, true, $remaining,
        );

        $this->assertSame(18, $r->scheduleMonths($input('cz-nim-software', 10)));
        $this->assertSame(30, $r->scheduleMonths($input('cz-nim-software', 30)));
        // Zřizovací výdaje nemají spodní hranici; rozpis má vždy aspoň měsíc.
        $this->assertSame(7, $r->scheduleMonths($input('cz-nim-setup', 7)));
        $this->assertSame(1, $r->scheduleMonths($input('cz-nim-setup', 0)));

        // 60 000 / 18 × 12 = 40 000.
        $a = $r->scheduleAmount($input('cz-nim-software', 10));
        $this->assertSame(40000.0, $a->amount);
        $this->assertSame('60 000,00 / 18 × 12', $a->formula);
    }

    public function testScheduleAmountRejectsAnnualMethodAndUnknownRule(): void
    {
        $r = $this->rules();
        try {
            $r->scheduleAmount(new TaxScheduleInput('straight', 'cz-2', '2022-01-01', 1000.0, 1000.0, 0, 12));
            $this->fail('Roční metoda nemá měsíční rozpis');
        } catch (\LogicException $e) {
            $this->assertStringContainsString("'straight'", $e->getMessage());
        }

        $this->expectException(\LogicException::class);
        $r->scheduleAmount(new TaxScheduleInput('time', 'cz-nim-unknown', '2019-01-01', 1000.0, 1000.0, 0, 12));
    }
}

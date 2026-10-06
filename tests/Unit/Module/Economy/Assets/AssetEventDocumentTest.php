<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Assets;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Assets\AssetEventDocument;
use Shipard\Module\Economy\Assets\AssetPlanService;
use Shipard\Module\Economy\Assets\SystemDepreciationWriter;
use Shipard\Module\Economy\Assets\Depreciation\PlanRow;

/**
 * Pravidla událostí per druh (tasks/assets-phase2b.md → tabulka Události),
 * efekty na kartu (D38) a poslední odpisy při vyřazení (D35). DB nahrazuje
 * TestAssetPlanService v paměti, zapisovač odpisů je spy.
 */
class AssetEventDocumentTest extends TestCase
{
    private const ASSET = 5;

    private TestableAssetEventDocument $doc;
    private TestAssetPlanService $service;

    protected function setUp(): void
    {
        $this->service = new TestAssetPlanService();
        $this->doc = new TestableAssetEventDocument($this->service);
        $this->doc->setConfig(TestAssetPlanService::config());
        $this->card();
    }

    // --- pomůcky ------------------------------------------------------------

    /** @param array<string, mixed> $overrides */
    private function card(array $overrides = []): void
    {
        $this->service->cards[self::ASSET] = $overrides + [
            'id'               => self::ASSET,
            'name'             => 'Soustruh',
            'category'         => 'tangible',
            'accounting_group' => 1,
            'tax_method'       => 'straight',
            'tax_rule'         => 'cz-2',
            'acc_method'       => 'as_tax',
            'acc_months'       => null,
            'acquired_date'    => null,
            'disposed_date'    => null,
            'docState'         => 40,
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function confirmed(string $kind, string $date, array $overrides = []): void
    {
        static $nextId = 100;
        $this->service->events[] = $overrides + [
            'id'         => $nextId++,
            'asset'      => self::ASSET,
            'event_kind' => $kind,
            'scope'      => in_array($kind, ['depreciation', 'opening'], true) ? 'tax' : ($kind === 'interruption' ? 'tax' : 'both'),
            'event_date' => $date,
            'amount'     => 0,
            'origin'     => 'manual',
            'half_year'  => 0,
            'docState'   => 40,
        ];
    }

    private function activated(string $date = '2022-03-15', float $amount = 100000.0): void
    {
        $this->confirmed('activation', $date, ['amount' => $amount]);
    }

    private function depreciated(string $scope, int $year, float $amount): void
    {
        $this->confirmed('depreciation', "{$year}-12-31", [
            'scope' => $scope, 'amount' => $amount,
            'period_begin' => "{$year}-01-01", 'period_end' => "{$year}-12-31",
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function event(string $kind, string $date, array $overrides = []): array
    {
        return $overrides + [
            'asset'      => self::ASSET,
            'event_kind' => $kind,
            'event_date' => $date,
            'amount'     => 0,
            'docState'   => 40,
        ];
    }

    /** @return list<string> kódy chyb (column:code) */
    private function codes(array $data): array
    {
        $result = $this->doc->validate($data);
        return array_map(
            static fn(array $e): string => $e['column'] . ':' . $e['code'],
            $result->toArray(),
        );
    }

    private function assertValid(array $data): void
    {
        $this->assertSame([], $this->codes($data));
    }

    // --- společná pravidla ---------------------------------------------------

    public function testActivationOnConfirmedCardIsValid(): void
    {
        $data = $this->event('activation', '2022-03-15', ['amount' => 100000]);
        $this->assertTrue($this->doc->validate($data)->isValid());
        $this->assertSame('both', $data['scope']);
    }

    public function testEventNeedsLongTermCard(): void
    {
        $this->card(['category' => 'small']);
        $this->assertSame(['_form:notLongTerm'], $this->codes($this->event('activation', '2022-03-15', ['amount' => 10])));
    }

    public function testNonDepreciableCardHasNoDepreciationEvents(): void
    {
        $this->card(['category' => 'nondepreciable', 'tax_method' => null, 'acc_method' => null]);
        $this->assertSame(['_form:notDepreciable'], $this->codes($this->event('depreciation', '2022-12-31')));
        $this->assertSame(['_form:notDepreciable'], $this->codes($this->event('interruption', '2022-12-31')));
        $this->assertValid($this->event('activation', '2022-03-15', ['amount' => 5000]));
    }

    public function testConfirmationNeedsCardInConfirmedState(): void
    {
        $this->card(['docState' => 10]);
        $this->assertSame(['_form:cardNotConfirmed'], $this->codes($this->event('activation', '2022-03-15', ['amount' => 10])));

        // Koncept události projde i u karty v konceptu.
        $this->assertValid($this->event('activation', '2022-03-15', ['amount' => 10, 'docState' => 10]));
    }

    public function testNothingAfterConfirmedDisposal(): void
    {
        $this->activated();
        $this->confirmed('disposal', '2024-05-10');
        $this->assertSame(['_form:afterDisposal'], $this->codes($this->event('improvement', '2024-06-01', ['amount' => 10])));
    }

    public function testEventBeforeConfirmedDepreciationIsRejected(): void
    {
        // Historie se rozebírá od konce (D29): TZ v roce 2023 při potvrzeném
        // daňovém odpisu 2023 nejde; TZ v roce 2024 ano. Účetní odpis se
        // TZ (both) taky týká.
        $this->activated();
        $this->depreciated('tax', 2022, 11000.0);
        $this->depreciated('tax', 2023, 22250.0);

        $this->assertSame(['_form:notAtEnd'], $this->codes($this->event('improvement', '2023-05-10', ['amount' => 20000])));
        $this->assertValid($this->event('improvement', '2024-05-10', ['amount' => 20000]));

        $this->depreciated('acc', 2024, 10000.0);
        $this->assertSame(['_form:notAtEnd'], $this->codes($this->event('improvement', '2024-05-10', ['amount' => 20000])));
        // Daňový odpis 2024 na účetní odpis 2024 nenaráží.
        $this->assertValid($this->event('depreciation', '2024-12-31', [
            'scope' => 'tax', 'amount' => 22250, 'period_begin' => '2024-01-01', 'period_end' => '2024-12-31',
        ]));
    }

    public function testStoredEventCannotChangeKindOrCard(): void
    {
        $this->doc->stored[7] = $this->event('activation', '2022-03-15', ['id' => 7, 'amount' => 100, 'docState' => 10]);

        $this->assertSame(
            ['event_kind:immutable', 'asset:immutable'],
            $this->codes(['id' => 7, 'event_kind' => 'improvement', 'asset' => 9, 'docState' => 10]),
        );
    }

    // --- zařazení a počáteční stav ------------------------------------------

    public function testActivationRules(): void
    {
        $this->assertSame(['amount:positive'], $this->codes($this->event('activation', '2022-03-15')));

        $this->activated();
        $this->assertSame(['_form:alreadyActivated'], $this->codes($this->event('activation', '2023-01-01', ['amount' => 10])));
    }

    public function testActivationChecksCardSettingsForItsDate(): void
    {
        // Mimořádné odpisy jen pro zařazení 2020–2023 (§ 30a).
        $this->card(['tax_method' => 'extraordinary', 'tax_rule' => 'cz-30a-2']);
        $this->assertValid($this->event('activation', '2022-03-15', ['amount' => 100000]));
        // 2025: metoda existuje (bezemisní vozidla), skupina 2 už ne.
        $this->assertSame(['_form:ruleNotValid'], $this->codes($this->event('activation', '2025-03-15', ['amount' => 100000])));
        // 2019: mimořádné odpisy ještě nejsou — zařazení před prvním účetním
        // rokem jde jen počátečním stavem, ten ověří původní datum.
        $this->assertSame(['_form:methodNotAvailable'], $this->codes($this->event('opening', '2024-01-01', [
            'scope' => 'tax', 'amount' => 100000, 'original_date' => '2019-03-15',
        ])));
    }

    public function testManualEventMustLieInFiscalYear(): void
    {
        // D58: účetní roky testu jsou 2021–2026.
        $this->assertSame(
            ['event_date:outsideFiscalYear'],
            $this->codes($this->event('activation', '2019-03-15', ['amount' => 100000])),
        );
        $this->assertSame(
            ['event_date:outsideFiscalYear'],
            $this->codes($this->event('activation', '2027-01-01', ['amount' => 100000])),
        );
        // Koncept projde, datum jde opravit před potvrzením.
        $this->assertValid($this->event('activation', '2019-03-15', ['amount' => 100000, 'docState' => 10]));

        // Původ z payloadu se nebere — nový záznam je vždy ruční.
        $this->assertSame(
            ['event_date:outsideFiscalYear'],
            $this->codes($this->event('activation', '2019-03-15', ['amount' => 100000, 'origin' => 'import'])),
        );
    }

    public function testImportedEventMayLieBeforeFirstFiscalYear(): void
    {
        $this->card(['tax_method' => 'straight', 'tax_rule' => 'cz-2']);
        $this->doc->stored[12] = $this->event('activation', '2019-03-15', [
            'id' => 12, 'amount' => 100000, 'origin' => 'import', 'docState' => 80,
        ]);
        $this->assertValid(['id' => 12, 'docState' => 40]);
    }

    public function testOpeningBalanceRules(): void
    {
        $opening = fn(array $o = []): array => $this->event('opening', '2024-01-01', $o + [
            'scope' => 'tax', 'amount' => 100000, 'accumulated' => 33250, 'units_done' => 2, 'original_date' => '2022-03-15',
        ]);
        $this->assertValid($opening());

        $this->assertSame(['event_date:openingNotAtYearStart'], $this->codes($opening(['event_date' => '2024-02-01'])));
        $this->assertSame(['accumulated:invalid_range'], $this->codes($opening(['accumulated' => 100001])));
        $this->assertSame(['original_date:required'], $this->codes($opening(['original_date' => null])));
        $this->assertSame(['scope:invalidScope'], $this->codes($opening(['scope' => 'both'])));

        $this->confirmed('opening', '2024-01-01', ['scope' => 'tax', 'amount' => 100000, 'original_date' => '2022-03-15']);
        $this->assertSame(['_form:openingExists'], $this->codes($opening()));
        $this->assertValid($opening(['scope' => 'acc']));
    }

    public function testOpeningBalanceNotAfterActivation(): void
    {
        $this->activated();
        $this->assertSame(['_form:alreadyActivated'], $this->codes($this->event('opening', '2024-01-01', [
            'scope' => 'acc', 'amount' => 100000, 'original_date' => '2022-03-15',
        ])));
    }

    // --- technické zhodnocení a snížení --------------------------------------

    public function testImprovementNeedsActivationAndAllowedMethod(): void
    {
        $this->assertSame(['_form:notActivated'], $this->codes($this->event('improvement', '2023-05-10', ['amount' => 20000])));

        $this->activated('2022-03-15');
        $this->assertSame(['event_date:beforeActivation'], $this->codes($this->event('improvement', '2022-03-01', ['amount' => 20000])));
        $this->assertValid($this->event('improvement', '2023-05-10', ['amount' => 20000]));

        $this->card(['tax_method' => 'extraordinary', 'tax_rule' => 'cz-30a-2']);
        $this->assertSame(['_form:improvementOnSchedule'], $this->codes($this->event('improvement', '2023-05-10', ['amount' => 20000])));
    }

    public function testImprovementAfterOpeningBalancesOfBothCircuits(): void
    {
        $this->confirmed('opening', '2024-01-01', ['scope' => 'tax', 'amount' => 100000, 'original_date' => '2022-03-15']);
        $this->assertSame(['_form:notActivated'], $this->codes($this->event('improvement', '2024-05-10', ['amount' => 20000])));

        $this->confirmed('opening', '2024-01-01', ['scope' => 'acc', 'amount' => 100000, 'original_date' => '2022-03-15']);
        $this->assertValid($this->event('improvement', '2024-05-10', ['amount' => 20000]));
    }

    public function testReductionIsCappedByResidualOfBothCircuits(): void
    {
        // Zůstatek daňový 89 000, účetní 85 000 → snížení nejvýš 85 000.
        $this->activated();
        $this->depreciated('tax', 2022, 11000.0);
        $this->depreciated('acc', 2022, 15000.0);

        $this->assertValid($this->event('reduction', '2023-05-10', ['amount' => 85000]));
        $this->assertSame(['amount:reductionAboveResidual'], $this->codes($this->event('reduction', '2023-05-10', ['amount' => 85001])));
    }

    // --- odpis ---------------------------------------------------------------

    public function testDepreciationRules(): void
    {
        $this->activated();
        $dep = fn(array $o = []): array => $this->event('depreciation', '2022-12-31', $o + [
            'scope' => 'tax', 'amount' => 11000, 'period_begin' => '2022-01-01', 'period_end' => '2022-12-31',
        ]);
        $this->assertValid($dep());

        $this->assertSame(['period_begin:required', 'period_end:required'], $this->codes($dep(['period_begin' => null, 'period_end' => null])));
        $this->assertSame(['period_end:invalid_range'], $this->codes($dep(['period_end' => '2021-12-31'])));
        $this->assertSame(['event_date:dateOutsidePeriod'], $this->codes($dep(['event_date' => '2023-01-15'])));
        $this->assertSame(['amount:notWholeUnits'], $this->codes($dep(['amount' => 11000.5])));
        $this->assertSame(['amount:aboveResidual'], $this->codes($dep(['amount' => 100001])));

        $this->depreciated('tax', 2022, 11000.0);
        $this->assertSame(['period_begin:periodOverlap'], $this->codes($dep(['event_date' => '2022-12-31'])));
    }

    public function testDepreciationNeedsStartOfItsCircuit(): void
    {
        $this->confirmed('opening', '2024-01-01', ['scope' => 'tax', 'amount' => 100000, 'original_date' => '2022-03-15']);
        $this->assertSame(['_form:notActivated'], $this->codes($this->event('depreciation', '2024-12-31', [
            'scope' => 'acc', 'amount' => 10000, 'period_begin' => '2024-01-01', 'period_end' => '2024-12-31',
        ])));
    }

    public function testImportedDepreciationMayHaveFractions(): void
    {
        $this->activated();
        $this->doc->stored[9] = $this->event('depreciation', '2022-12-31', [
            'id' => 9, 'scope' => 'tax', 'amount' => 11000.4, 'origin' => 'import',
            'period_begin' => '2022-01-01', 'period_end' => '2022-12-31', 'docState' => 80,
        ]);
        $this->assertValid(['id' => 9, 'docState' => 40]);
    }

    // --- importní mód (fáze 6, §5.7) ------------------------------------------

    public function testImportMarkerSetsOriginAndConfirmsOnDraftCard(): void
    {
        $this->card(['docState' => 10]);
        $data = $this->event('activation', '2019-03-15', ['amount' => 100000, '_import' => true]);

        $this->assertTrue($this->doc->isLockExempt($data));
        $this->assertValid($data);

        $this->doc->beforeSave($data, null);
        $this->assertSame('import', $data['origin']);
        $this->assertArrayNotHasKey('_import', $data, 'marker nesmí dojít do SQL');
    }

    public function testImportMarkerRelaxesHistoricalRules(): void
    {
        $this->activated();
        // Odpis nad zůstatek okruhu a necelé koruny.
        $this->assertValid($this->event('depreciation', '2022-12-31', [
            'scope' => 'tax', 'amount' => 150000.4, 'period_begin' => '2022-01-01', 'period_end' => '2022-12-31', '_import' => true,
        ]));
        // Snížení nad zůstatkovou cenu.
        $this->assertValid($this->event('reduction', '2022-06-01', ['amount' => 250000, '_import' => true]));
        // Polovina odpisu v roce zařazení.
        $this->assertValid($this->event('disposal', '2022-09-01', ['half_year' => 1, '_import' => true]));
        // Přerušení v roce s potvrzeným daňovým odpisem (odpis dřív v roce,
        // aby přerušení nebylo před ním — historie od konce platí i importu).
        $this->confirmed('depreciation', '2022-05-10', [
            'scope' => 'tax', 'amount' => 11000.0, 'period_begin' => '2022-01-01', 'period_end' => '2022-12-31',
        ]);
        $this->assertValid($this->event('interruption', '2022-12-31', ['_import' => true]));
        // Bez markeru platí pravidla dál.
        $this->assertSame(['_form:yearDepreciated'], $this->codes($this->event('interruption', '2022-12-31')));
    }

    public function testImportedDisposalIgnoresPlanErrorsAndWritesNoFinalDepreciations(): void
    {
        // as_tax bez daňového vzorce = settingsInvalid; import projde.
        $this->card(['tax_method' => 'none', 'tax_rule' => null, 'acc_method' => 'as_tax']);
        $this->activated();
        $data = $this->event('disposal', '2024-05-10', ['id' => 60, '_import' => true]);
        $this->assertValid($data);

        $this->doc->beforeSave($data, null);
        $this->confirmed('disposal', '2024-05-10', ['id' => 60, 'origin' => 'import']);
        $this->doc->afterPersist($data);

        $this->assertSame([], $this->doc->writer->written, 'poslední odpisy posílá runner (D76)');
        $this->assertSame([[self::ASSET, [
            'acquired_date' => '2022-03-15', 'disposed_date' => '2024-05-10', 'docState' => 70, 'docStateMain' => 4,
        ]]], $this->doc->cardUpdates);
    }

    // --- přerušení -----------------------------------------------------------

    public function testInterruptionRules(): void
    {
        $this->activated();
        $this->depreciated('tax', 2022, 11000.0);

        $this->assertValid($this->event('interruption', '2023-12-31'));
        // Odpis roku je v historii za přerušením → nejdřív pravidlo „od konce“.
        $this->assertSame(['_form:notAtEnd'], $this->codes($this->event('interruption', '2022-06-30')));
        // Odpis roku datovaný před přerušením: rok už je odepsaný.
        $this->service->events = [];
        $this->activated();
        $this->confirmed('depreciation', '2022-06-30', [
            'scope' => 'tax', 'amount' => 11000, 'period_begin' => '2022-01-01', 'period_end' => '2022-12-31',
        ]);
        $this->assertSame(['_form:yearDepreciated'], $this->codes($this->event('interruption', '2022-12-31')));

        $this->confirmed('interruption', '2023-12-31');
        $this->assertSame(['_form:alreadyInterrupted'], $this->codes($this->event('interruption', '2023-06-30')));

        $this->card(['tax_method' => 'extraordinary', 'tax_rule' => 'cz-30a-2']);
        $this->assertSame(['_form:notInterruptible'], $this->codes($this->event('interruption', '2024-12-31')));
    }

    public function testInterruptionGetsFiscalYearAndZeroAmount(): void
    {
        $data = $this->event('interruption', '2023-06-30', ['amount' => 500, 'scope' => 'acc']);
        $this->doc->beforeSave($data, null);

        $this->assertSame('tax', $data['scope']);
        $this->assertSame('2023-01-01', $data['period_begin']);
        $this->assertSame('2023-12-31', $data['period_end']);
        $this->assertSame(0, $data['amount']);
        $this->assertSame('manual', $data['origin']);
    }

    // --- vyřazení ------------------------------------------------------------

    public function testDisposalRules(): void
    {
        $this->assertSame(['_form:notActivated'], $this->codes($this->event('disposal', '2024-05-10')));

        $this->activated('2022-03-15');
        $this->assertSame(['event_date:beforeActivation'], $this->codes($this->event('disposal', '2022-03-01')));
        $this->assertValid($this->event('disposal', '2024-05-10', ['half_year' => 1]));

        $this->confirmed('improvement', '2024-08-01', ['amount' => 5000]);
        $this->assertSame(['event_date:eventsAfterDisposal'], $this->codes($this->event('disposal', '2024-05-10')));
    }

    public function testHalfYearOnlyWhenEvidencedAtYearStartAndAllowed(): void
    {
        $this->activated('2024-02-10');
        $this->assertSame(['half_year:halfYearNotAllowed'], $this->codes($this->event('disposal', '2024-09-30', ['half_year' => 1])));
        $this->assertValid($this->event('disposal', '2024-09-30', ['half_year' => 0]));

        $this->service->events = [];
        $this->card(['tax_method' => 'extraordinary', 'tax_rule' => 'cz-30a-2']);
        $this->activated('2022-03-15');
        $this->assertSame(['half_year:halfYearNotAllowed'], $this->codes($this->event('disposal', '2024-09-30', ['half_year' => 1])));
    }

    public function testDisposalNeedsCleanPlan(): void
    {
        // as_tax bez daňového vzorce = settingsInvalid v obou okruzích.
        $this->card(['tax_method' => 'none', 'tax_rule' => null, 'acc_method' => 'as_tax']);
        $this->activated();

        $this->assertSame(['_form:planHasErrors'], $this->codes($this->event('disposal', '2024-05-10')));
    }

    public function testConfirmedDisposalWritesFinalDepreciationsAndArchivesCard(): void
    {
        // Sk. 2 rovnoměrně, as_tax: potvrzené 2022 a 2023 v obou okruzích,
        // vyřazení 10. 5. 2024 s polovinou → daňový ½ × 22 250 = 11 125,
        // účetní 22 250 × 5 / 12 = 9 271 (as_tax v roce vyřazení poměrně).
        $this->activated();
        $this->depreciated('tax', 2022, 11000.0);
        $this->depreciated('tax', 2023, 22250.0);
        $this->depreciated('acc', 2022, 11000.0);
        $this->depreciated('acc', 2023, 22250.0);

        $data = $this->event('disposal', '2024-05-10', ['id' => 50, 'half_year' => 1]);
        $this->doc->beforeSave($data, ['docState' => 10] + $data);
        // Vyřazení je v DB už potvrzené, když afterPersist běží.
        $this->confirmed('disposal', '2024-05-10', ['id' => 50, 'half_year' => 1]);
        $this->doc->afterPersist($data);

        $written = $this->doc->writer->written;
        $this->assertSame(['tax', 'acc'], array_keys($written));
        $this->assertSame([11125.0], array_column($written['tax'], 'amount'));
        $this->assertTrue($written['tax'][0]['halfYear']);
        $this->assertSame('2024-05-10', $written['tax'][0]['date']);
        $this->assertSame([9271.0], array_column($written['acc'], 'amount'));

        $this->assertSame([
            [self::ASSET, [
                'acquired_date' => '2022-03-15', 'disposed_date' => '2024-05-10',
                'docState' => 70, 'docStateMain' => 4,
            ]],
        ], $this->doc->cardUpdates);
    }

    public function testDisposalWithoutHalfYearWritesNoTaxDepreciation(): void
    {
        $this->activated();
        $this->depreciated('tax', 2022, 11000.0);
        $this->depreciated('tax', 2023, 22250.0);
        $this->depreciated('acc', 2022, 11000.0);
        $this->depreciated('acc', 2023, 22250.0);

        $data = $this->event('disposal', '2024-05-10', ['id' => 51, 'half_year' => 0]);
        $this->doc->beforeSave($data, ['docState' => 10] + $data);
        $this->confirmed('disposal', '2024-05-10', ['id' => 51]);
        $this->doc->afterPersist($data);

        $this->assertSame(['acc'], array_keys($this->doc->writer->written));
    }

    public function testDisposalWritesAlsoEarlierMissingPeriods(): void
    {
        // Nic neodepsáno: vyřazení 2024 doplní daňové 2022, 2023 a polovinu
        // 2024, účetní 2022, 2023 a část 2024.
        $this->activated();
        $data = $this->event('disposal', '2024-05-10', ['id' => 52, 'half_year' => 1]);
        $this->doc->beforeSave($data, ['docState' => 10] + $data);
        $this->confirmed('disposal', '2024-05-10', ['id' => 52, 'half_year' => 1]);
        $this->doc->afterPersist($data);

        $this->assertSame([11000.0, 22250.0, 11125.0], array_column($this->doc->writer->written['tax'], 'amount'));
        $this->assertCount(3, $this->doc->writer->written['acc']);
    }

    public function testDisposalWithPlanErrorsRollsBack(): void
    {
        $this->card(['tax_method' => 'none', 'tax_rule' => null, 'acc_method' => 'as_tax']);
        $this->activated();
        $data = $this->event('disposal', '2024-05-10', ['id' => 53]);
        $this->doc->beforeSave($data, ['docState' => 10] + $data);
        $this->confirmed('disposal', '2024-05-10', ['id' => 53]);

        $this->expectException(\DomainException::class);
        $this->doc->afterPersist($data);
    }

    public function testCancelledDisposalReturnsCardToEditing(): void
    {
        $this->activated();
        $this->card(['docState' => 70, 'disposed_date' => '2024-05-10']);

        $data = $this->event('disposal', '2024-05-10', ['id' => 54, 'docState' => 80]);
        $this->doc->beforeSave($data, ['docState' => 40] + $data);
        $this->doc->afterPersist($data);

        $this->assertSame([], $this->doc->writer->written);
        $this->assertSame([
            [self::ASSET, ['acquired_date' => '2022-03-15', 'disposed_date' => null, 'docState' => 80, 'docStateMain' => 2]],
        ], $this->doc->cardUpdates);
    }

    public function testCancelledDisposalRemovesItsSystemDepreciations(): void
    {
        // D56: odpisy k datu vyřazení, které vyřazení založilo, se smažou
        // s ním — k původnímu datu, i kdyby payload datum měnil.
        $this->activated();
        $this->card(['docState' => 70, 'disposed_date' => '2024-05-10']);

        $original = $this->event('disposal', '2024-05-10', ['id' => 55]);
        $data = ['docState' => 80, 'event_date' => '2024-06-01'] + $original;
        $this->doc->beforeSave($data, $original);
        $this->doc->afterPersist($data);

        $this->assertSame([[self::ASSET, '2024-05-10']], $this->doc->writer->removed);
    }

    public function testConfirmedDisposalRemovesNothing(): void
    {
        $this->activated();
        $data = $this->event('disposal', '2024-05-10', ['id' => 56]);
        $this->doc->beforeSave($data, ['docState' => 10] + $data);
        $this->confirmed('disposal', '2024-05-10', ['id' => 56]);
        $this->doc->afterPersist($data);

        $this->assertSame([], $this->doc->writer->removed);
    }

    public function testCancelledNonDisposalEventRemovesNothing(): void
    {
        $original = $this->event('activation', '2022-03-15', ['id' => 57, 'amount' => 100000]);
        $data = ['docState' => 80] + $original;
        $this->doc->beforeSave($data, $original);
        $this->doc->afterPersist($data);

        $this->assertSame([], $this->doc->writer->removed);
    }

    public function testDisposalWithPostedDepreciationsCannotBeCancelled(): void
    {
        $this->activated();
        $this->doc->stored[58] = $this->event('disposal', '2024-05-10', ['id' => 58, 'scope' => 'both']);
        $this->doc->postedDocs = ['MAJ240001'];

        foreach ([80, 90] as $state) {
            $codes = $this->codes(['id' => 58, 'docState' => $state] + $this->doc->stored[58]);
            $this->assertSame(['_form:disposalPosted'], $codes);
        }
        $this->assertSame([[self::ASSET, '2024-05-10'], [self::ASSET, '2024-05-10']], $this->doc->postedQueries);

        // Bez zaúčtovaných odpisů přechod projde; uložení beze změny stavu se neptá.
        $this->doc->postedDocs = [];
        $this->assertValid(['id' => 58, 'docState' => 80] + $this->doc->stored[58]);
    }

    public function testDocumentNeverWritesAccountingDocumentLink(): void
    {
        $data = $this->event('activation', '2022-03-15', ['amount' => 100000, 'doc_head' => 77]);
        $this->doc->beforeSave($data, null);

        $this->assertArrayNotHasKey('doc_head', $data);
    }

    // --- efekty na kartu -----------------------------------------------------

    public function testConfirmedActivationSetsAcquiredDate(): void
    {
        $data = $this->event('activation', '2022-03-15', ['id' => 60, 'amount' => 100000]);
        $this->doc->beforeSave($data, ['docState' => 10] + $data);
        $this->confirmed('activation', '2022-03-15', ['id' => 60, 'amount' => 100000]);
        $this->doc->afterPersist($data);

        $this->assertSame([[self::ASSET, ['acquired_date' => '2022-03-15', 'disposed_date' => null]]], $this->doc->cardUpdates);
    }

    public function testConfirmedOpeningBalanceSetsOriginalAcquisitionDate(): void
    {
        $data = $this->event('opening', '2024-01-01', ['id' => 61, 'scope' => 'tax', 'amount' => 100000, 'original_date' => '2019-06-01']);
        $this->doc->beforeSave($data, null);
        $this->confirmed('opening', '2024-01-01', ['id' => 61, 'scope' => 'tax', 'amount' => 100000, 'original_date' => '2019-06-01']);
        $this->doc->afterPersist($data);

        $this->assertSame([[self::ASSET, ['acquired_date' => '2019-06-01', 'disposed_date' => null]]], $this->doc->cardUpdates);
    }

    public function testCancelledActivationClearsAcquiredDate(): void
    {
        $data = $this->event('activation', '2022-03-15', ['id' => 62, 'amount' => 100000, 'docState' => 90]);
        $this->doc->beforeSave($data, ['docState' => 40] + $data);
        $this->doc->afterPersist($data);

        $this->assertSame([[self::ASSET, ['acquired_date' => null, 'disposed_date' => null]]], $this->doc->cardUpdates);
    }

    public function testSaveWithoutConfirmationChangeTouchesNothing(): void
    {
        $data = $this->event('activation', '2022-03-15', ['id' => 63, 'amount' => 100000, 'docState' => 10]);
        $this->doc->beforeSave($data, $data);
        $this->doc->afterPersist($data);

        $this->assertSame([], $this->doc->cardUpdates);
    }

    // --- normalizace ---------------------------------------------------------

    public function testBeforeSaveClearsFieldsOfOtherKinds(): void
    {
        $data = $this->event('improvement', '2023-05-10', [
            'amount' => 20000, 'accumulated' => 5, 'units_done' => 3, 'price_increased' => 1,
            'original_date' => '2020-01-01', 'period_begin' => '2023-01-01', 'half_year' => 1,
            'claim_unrecorded' => 1, 'origin' => 'import', 'note' => '  TZ  ',
        ]);
        $this->doc->beforeSave($data, null);

        $this->assertSame('both', $data['scope']);
        $this->assertNull($data['accumulated']);
        $this->assertNull($data['units_done']);
        $this->assertSame(0, $data['price_increased']);
        $this->assertNull($data['original_date']);
        $this->assertNull($data['period_begin']);
        $this->assertSame(0, $data['half_year']);
        $this->assertSame(0, $data['claim_unrecorded']);
        $this->assertSame('manual', $data['origin']);
        $this->assertSame('TZ', $data['note']);
    }

    public function testBeforeSaveKeepsOriginOfStoredEvent(): void
    {
        $data = ['id' => 70, 'event_kind' => 'depreciation', 'amount' => 11000, 'docState' => 40];
        $this->doc->beforeSave($data, ['id' => 70, 'event_kind' => 'depreciation', 'origin' => 'system', 'docState' => 80]);

        $this->assertSame('system', $data['origin']);
        $this->assertSame(['old' => 80, 'new' => 40], $this->doc->getStateTransition());
    }
}

/** Dokument nad pamětí: plán z TestAssetPlanService, zápisy do spy polí. */
class TestableAssetEventDocument extends AssetEventDocument
{
    /** @var array<int, array<string, mixed>> uložené události (loadEvent) */
    public array $stored = [];
    /** @var list<array{int, array<string, mixed>}> */
    public array $cardUpdates = [];
    public SpyDepreciationWriter $writer;
    /** @var list<string> čísla dokladů, kterými jsou odpisy vyřazení zaúčtované */
    public array $postedDocs = [];
    /** @var list<array{int, string}> dotazy na zaúčtované odpisy vyřazení */
    public array $postedQueries = [];

    public function __construct(private readonly TestAssetPlanService $service)
    {
        $this->writer = new SpyDepreciationWriter();
    }

    protected function planService(): AssetPlanService
    {
        return $this->service;
    }

    protected function writer(): SystemDepreciationWriter
    {
        return $this->writer;
    }

    protected function loadEvent(int $id): ?array
    {
        return $this->stored[$id] ?? null;
    }

    protected function updateCard(int $assetId, array $values): void
    {
        $this->cardUpdates[] = [$assetId, $values];
    }

    protected function postedDisposalDepreciations(int $assetId, string $date): array
    {
        $this->postedQueries[] = [$assetId, $date];
        return $this->postedDocs;
    }
}

class SpyDepreciationWriter extends SystemDepreciationWriter
{
    /** @var array<string, list<array{amount: float, date: string, halfYear: bool, period: array}>> okruh → řádky */
    public array $written = [];
    /** @var list<array{int, string}> [karta, datum vyřazení] */
    public array $removed = [];

    public function __construct()
    {
        parent::__construct(null);
    }

    public function removeForDisposal(int $assetId, string $disposalDate): int
    {
        $this->removed[] = [$assetId, $disposalDate];
        return 0;
    }

    public function write(int $assetId, string $scope, array $rows): float
    {
        $total = 0.0;
        foreach ($rows as $row) {
            \assert($row instanceof PlanRow);
            $this->written[$scope][] = [
                'amount' => $row->amount, 'date' => $row->date, 'halfYear' => $row->halfYear,
                'period' => $row->period?->toArray(),
            ];
            $total += $row->amount;
        }
        return $total;
    }
}

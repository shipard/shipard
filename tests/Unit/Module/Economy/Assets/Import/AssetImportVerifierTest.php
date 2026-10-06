<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Assets\Import;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Reports\ReportMessage;
use Shipard\Core\Reports\ReportMessageSeverity;
use Shipard\Core\Reports\ReportResult;
use Shipard\Module\Economy\Assets\Depreciation\PlanMessageTexts;
use Shipard\Module\Economy\Assets\Import\AssetImportVerifier;
use Shipard\Tests\Unit\Module\Economy\Assets\TestAssetPlanService;

/** Ověřovač nad pamětí: karty a události z TestAssetPlanService, deník a roky v polích. */
class TestAssetImportVerifier extends AssetImportVerifier
{
    /** @var array<int, array<int, float>> id karty → id roku → obrat odpisů */
    public array $journal = [];
    /** @var int|null id prvního roku s dimenzí majetku v deníku */
    public ?int $firstYearId = null;

    public function __construct(private readonly TestAssetPlanService $memory, ?\Closure $reports)
    {
        parent::__construct($memory, null, $reports, new PlanMessageTexts(TestAssetPlanService::config()));
    }

    protected function loadCards(?string $assetNumber): array
    {
        $categories = $this->plans->categories();
        $out = [];
        foreach ($this->memory->cards as $id => $card) {
            if ($categories->isLongTerm((string) $card['category'])
                && ($assetNumber === null || $card['asset_number'] === $assetNumber)
            ) {
                $out[$id] = $card;
            }
        }
        return $out;
    }

    protected function loadDepreciationJournal(array $assetIds): array
    {
        return array_intersect_key($this->journal, array_flip($assetIds));
    }

    protected function firstYearWithAssets(): ?array
    {
        foreach ($this->plans->fiscalYears() as $year) {
            if ($year['id'] === $this->firstYearId) {
                return $year;
            }
        }
        return null;
    }
}

/**
 * Ověření importu majetku (docs/assets.md D82): zlatý test daňového
 * okruhu z `PlanRow::computed`, účetní okruh × deník po letech, kontrola
 * evidence × deník po letech od prvního s dimenzí; čistý stav a rozdíly.
 * Kalendářní roky 2021–2026 mají id 1–6.
 */
class AssetImportVerifierTest extends TestCase
{
    private TestAssetPlanService $plans;
    /** @var list<array{string, int}> volání kontroly: [rok, měsíce] */
    private array $reportCalls = [];
    /** @var array<string, list<ReportMessage>> rok → zprávy kontroly */
    private array $reportMessages = [];
    private int $eventId = 1;

    protected function setUp(): void
    {
        $this->plans = new TestAssetPlanService();
        $this->plans->asOf = '2025-03-10';
    }

    private function verifier(): TestAssetImportVerifier
    {
        $reports = function (string $year, int $months): ReportResult {
            $this->reportCalls[] = [$year, $months];
            return new ReportResult('economy.assets.journalCheck', [], 'test', $this->reportMessages[$year] ?? [], [], []);
        };
        return new TestAssetImportVerifier($this->plans, $reports);
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
    private function event(int $asset, string $kind, string $date, array $o = []): int
    {
        $id = $this->eventId++;
        $this->plans->events[] = $o + [
            'id' => $id, 'asset' => $asset, 'event_kind' => $kind, 'scope' => 'both', 'event_date' => $date,
            'amount' => 0, 'origin' => 'import', 'half_year' => 0, 'claim_unrecorded' => 0, 'docState' => 40, 'doc_head' => null,
        ];
        return $id;
    }

    /** @param array<string, mixed> $o */
    private function depreciation(int $asset, string $scope, int $year, float $amount, array $o = []): int
    {
        return $this->event($asset, 'depreciation', "{$year}-12-31", $o + [
            'scope' => $scope, 'amount' => $amount, 'period_begin' => "{$year}-01-01", 'period_end' => "{$year}-12-31",
        ]);
    }

    /** Karta zařazená 2022 za 100 000, sk. 2 rovnoměrně: 2022 11 000, 2023 22 250 v obou okruzích. */
    private function importedCard(int $id = 1): void
    {
        $this->card($id);
        $this->event($id, 'activation', '2022-03-15', ['amount' => 100000]);
        foreach ([2022 => 11000.0, 2023 => 22250.0] as $year => $amount) {
            $this->depreciation($id, 'tax', $year, $amount);
            $this->depreciation($id, 'acc', $year, $amount);
        }
    }

    public function testCleanImportPasses(): void
    {
        $this->importedCard();
        $verifier = $this->verifier();
        $verifier->journal[1] = [2 => 11000.0, 3 => 22250.0];
        $verifier->firstYearId = 2;

        $result = $verifier->run();

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['tax']);
        $this->assertSame([], $result['planErrors']);
        $this->assertSame([], $result['accounting']);
        $this->assertSame(['2022', '2023', '2024', '2025', '2026'], array_column($result['journalCheck'], 'year'));
        $this->assertSame(['ok', 'ok', 'ok', 'ok', 'ok'], array_column($result['journalCheck'], 'status'));
        $this->assertSame([['2022', 12], ['2023', 12], ['2024', 12], ['2025', 12], ['2026', 12]], $this->reportCalls);
        $this->assertSame(
            ['cards' => 1, 'taxChecked' => 2, 'taxDifferences' => 0, 'planErrors' => 0, 'accChecked' => 2, 'accDifferences' => 0,
                'checkYears' => 5, 'checkErrors' => 0],
            $result['summary'],
        );
    }

    public function testTaxDepreciationDifferentFromEngineIsReported(): void
    {
        $this->card(1);
        $this->event(1, 'activation', '2022-03-15', ['amount' => 100000]);
        $this->depreciation(1, 'tax', 2022, 11000.0);
        // Rok 2023: starý systém uplatnil 22 000 místo 22 250, s příznakem neevidované částky.
        $this->depreciation(1, 'tax', 2023, 22000.0, ['claim_unrecorded' => 1]);
        // Ruční odpis nového Shipardu zlatý test neposuzuje, i když se liší.
        $this->depreciation(1, 'tax', 2024, 20000.0, ['origin' => 'manual']);

        $result = $this->verifier()->run();

        $this->assertFalse($result['ok']);
        $this->assertCount(1, $result['tax']);
        $difference = $result['tax'][0];
        $this->assertSame('MA0001', $difference['number']);
        $this->assertSame('2023-12-31', $difference['periodEnd']);
        $this->assertSame(22000.0, $difference['imported']);
        $this->assertSame(22250.0, $difference['computed']);
        $this->assertSame(-250.0, $difference['difference']);
        $this->assertTrue($difference['claimUnrecorded']);
        $this->assertSame(2, $result['summary']['taxChecked']);
    }

    public function testAccountingCircuitDiffersFromJournal(): void
    {
        $this->importedCard();
        $verifier = $this->verifier();
        // Deník: 2023 jen 20 000, 2024 odpis bez události v evidenci.
        $verifier->journal[1] = [2 => 11000.0, 3 => 20000.0, 4 => 22250.0];

        $result = $verifier->run();

        $this->assertFalse($result['ok']);
        $this->assertSame(
            [['2023', 22250.0, 20000.0, 2250.0], ['2024', 0.0, 22250.0, -22250.0]],
            array_map(static fn(array $r): array => [$r['year'], $r['evidence'], $r['journal'], $r['difference']], $result['accounting']),
        );
        $this->assertSame(3, $result['summary']['accChecked']);
        $this->assertSame([], $result['journalCheck'], 'bez roku s dimenzí v deníku');
    }

    public function testPlanErrorsAndSingleCardFilter(): void
    {
        $this->importedCard(1);
        // Karta s nepočitatelným okruhem (neznámá daňová metoda, as_tax bez vzorce).
        $this->card(2, ['tax_method' => 'bogus', 'tax_rule' => null]);
        $this->event(2, 'activation', '2022-03-15', ['amount' => 50000]);
        $this->depreciation(2, 'tax', 2022, 5000.0);
        // Drobný majetek se neposuzuje.
        $this->card(3, ['category' => 'small', 'tax_method' => null, 'tax_rule' => null, 'acc_method' => null]);
        $verifier = $this->verifier();
        $verifier->journal[1] = [2 => 11000.0, 3 => 22250.0];
        $verifier->firstYearId = 2;

        $result = $verifier->run();

        $this->assertFalse($result['ok']);
        $this->assertSame(2, $result['summary']['cards']);
        $this->assertSame([], $result['tax'], 'zablokovaný okruh nemá spočtenou hodnotu');
        $this->assertSame(['tax', 'acc'], array_column($result['planErrors'], 'circuit'));
        $this->assertSame('MA0002', $result['planErrors'][0]['number']);
        $this->assertSame('settingsInvalid', $result['planErrors'][0]['code']);
        $this->assertNotSame('settingsInvalid', $result['planErrors'][0]['message'], 'text z katalogu hlášení');

        // Filtr na kartu: jen ta karta, kontrola po letech se vynechá.
        $this->reportCalls = [];
        $single = $this->verifier();
        $single->journal[1] = [2 => 11000.0, 3 => 22250.0];
        $single->firstYearId = 2;
        $result = $single->run('MA0001');
        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['summary']['cards']);
        $this->assertSame([], $result['journalCheck']);
        $this->assertSame([], $this->reportCalls);
    }

    public function testJournalCheckErrorsFailAndWarningsPass(): void
    {
        $this->importedCard();
        $this->reportMessages = [
            '2022' => [new ReportMessage(ReportMessageSeverity::Warning, 'assets.journalCheck.acquisitionAccountDifference', 'x', 'rows.1')],
            '2024' => [
                new ReportMessage(ReportMessageSeverity::Error, 'assets.journalCheck.accountMismatch', 'x', 'rows.1'),
                new ReportMessage(ReportMessageSeverity::Error, 'assets.journalCheck.accountMismatch', 'y', 'rows.2'),
                new ReportMessage(ReportMessageSeverity::Warning, 'assets.journalCheck.withoutAsset', 'z', 'rows.2'),
            ],
        ];
        $verifier = $this->verifier();
        $verifier->journal[1] = [2 => 11000.0, 3 => 22250.0];
        $verifier->firstYearId = 2;

        $result = $verifier->run();

        $this->assertFalse($result['ok']);
        $byYear = array_column($result['journalCheck'], null, 'year');
        $this->assertSame('warnings', $byYear['2022']['status']);
        $this->assertSame(['assets.journalCheck.acquisitionAccountDifference' => 1], $byYear['2022']['codes']);
        $this->assertSame('errors', $byYear['2024']['status']);
        $this->assertSame(['assets.journalCheck.accountMismatch' => 2, 'assets.journalCheck.withoutAsset' => 1], $byYear['2024']['codes']);
        $this->assertSame(1, $result['summary']['checkErrors']);
    }
}

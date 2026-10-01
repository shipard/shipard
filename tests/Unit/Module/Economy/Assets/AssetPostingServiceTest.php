<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Assets;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Assets\Depreciation\PlanMessageTexts;
use Shipard\Module\Economy\Assets\Depreciation\PlanRow;
use Shipard\Module\Economy\Assets\DepreciationRunService;
use Shipard\Module\Economy\Assets\Posting\AssetPostingBuilder;
use Shipard\Module\Economy\Assets\Posting\AssetPostingDocuments;
use Shipard\Module\Economy\Assets\Posting\AssetPostingException;
use Shipard\Module\Economy\Assets\Posting\AssetPostingService;
use Shipard\Module\Economy\Assets\SystemDepreciationWriter;

/**
 * „Odpisy a zaúčtování za období“ (docs/assets.md D50–D55) nad pamětí:
 * náhled a vyloučení, zaúčtování (odpisy jen zaúčtovaných karet, doklad,
 * vazba událostí), idempotence, překážky běhu, rollback a zrušení jen
 * posledního období. Reálnou DB a účtování dokladu kryje integrační
 * AssetPostingServiceTest.
 */
class AssetPostingServiceTest extends TestCase
{
    // Kalendářní roky v TestAssetPlanService: 2021 = 1 … 2026 = 6.
    private const YEAR_2023 = 3;
    private const YEAR_2024 = 4;

    private const ACCOUNTS = [
        'asset' => 22, 'acquisition' => 42, 'accumulated' => 82, 'depreciation' => 551, 'disposal' => 541,
    ];

    private TestAssetPlanService $plans;
    private PostingStoreWriter $writer;
    private SpyPostingDocuments $documents;
    private TestablePostingService $service;

    protected function setUp(): void
    {
        $this->plans = new TestAssetPlanService();
        $this->writer = new PostingStoreWriter($this->plans);
        $this->documents = new SpyPostingDocuments();
        $this->service = new TestablePostingService($this->plans, $this->writer, $this->documents);
    }

    /** @param array<string, int|null> $accounts */
    private function card(int $id, array $accounts = self::ACCOUNTS): void
    {
        $this->plans->cards[$id] = [
            'id' => $id, 'asset_number' => sprintf('MA%04d', $id), 'name' => "Stroj {$id}", 'category' => 'tangible',
            'tax_method' => 'straight', 'tax_rule' => 'cz-2', 'acc_method' => 'as_tax', 'acc_months' => null, 'docState' => 40,
        ];
        $this->service->accounts[$id] = $accounts;
    }

    /** @param array<string, mixed> $o */
    private function event(int $asset, string $kind, string $date, array $o = []): int
    {
        static $id = 700;
        $this->plans->events[] = $o + [
            'id' => ++$id, 'asset' => $asset, 'event_kind' => $kind, 'scope' => 'both', 'event_date' => $date,
            'amount' => 0, 'origin' => 'manual', 'half_year' => 0, 'docState' => 40, 'doc_head' => null,
        ];
        return $id;
    }

    /** Karta zařazená 2022 se zaúčtovanou historií do roku 2023 (doklad 900). */
    private function postedUntil2023(int $id, array $accounts = self::ACCOUNTS): void
    {
        $this->card($id, $accounts);
        $this->event($id, 'activation', '2022-03-15', ['amount' => 100000, 'doc_head' => 900]);
        foreach ([2022 => 11000.0, 2023 => 22250.0] as $year => $amount) {
            foreach (['tax', 'acc'] as $scope) {
                $this->event($id, 'depreciation', "{$year}-12-31", [
                    'scope' => $scope, 'amount' => $amount, 'doc_head' => $scope === 'acc' ? 900 : null,
                    'period_begin' => "{$year}-01-01", 'period_end' => "{$year}-12-31",
                ]);
            }
        }
    }

    /** @return array<int, string> id → důvod */
    private function excluded(array $result): array
    {
        return array_column($result['excluded'], 'reason', 'id');
    }

    // ── náhled ──────────────────────────────────────────────────────────────

    public function testPreviewCombinesNewDepreciationsAndUnpostedEvents(): void
    {
        $this->postedUntil2023(1);                              // jen odpis 2024
        $this->postedUntil2023(2);                              // odpis + TZ z června 2024
        $this->event(2, 'improvement', '2024-06-01', ['amount' => 20000]);

        $preview = $this->service->preview(self::YEAR_2024);

        $this->assertSame([1, 2], array_column($preview['depreciations'], 'id'));
        $this->assertSame(22250.0, $preview['depreciations'][0]['amount']);
        $this->assertSame([[2, 'improvement', '2024-06-01', 20000.0]], array_map(
            static fn(array $e): array => [$e['assetId'], $e['kind'], $e['date'], $e['amount']],
            $preview['events'],
        ));
        $this->assertSame(6, $preview['rowCount']);
        $this->assertSame($preview['totalDebit'], $preview['totalCredit']);
        $this->assertSame($preview['depreciationTotal'] + 20000.0, $preview['totalDebit']);
        $this->assertSame(['022000', '042000', '082000', '551000'], array_column($preview['accounts'], 'number'));
        $this->assertSame([], $preview['excluded']);
        $this->assertSame([], $preview['blockers']);
        $this->assertTrue($preview['canPost']);
        // Náhled nezapisuje a transakci neotvírá.
        $this->assertSame([], $this->service->log);
        $this->assertSame([], $this->documents->created);
    }

    public function testPreviewExcludesCardsWithReasons(): void
    {
        $this->postedUntil2023(1);
        // Nezaúčtované zařazení z dřívějšího období — účtuje se bez děr.
        $this->card(3);
        $this->event(3, 'activation', '2023-05-01', ['amount' => 50000]);
        $this->event(3, 'depreciation', '2023-12-31', [
            'scope' => 'acc', 'amount' => 5500, 'doc_head' => 900, 'period_begin' => '2023-01-01', 'period_end' => '2023-12-31',
        ]);
        // Účetní skupina bez účtu odpisů a oprávek.
        $this->postedUntil2023(4, ['accumulated' => null, 'depreciation' => null] + self::ACCOUNTS);
        // Neodepsaný rok 2023 (vyloučení už z běhu odpisů).
        $this->card(5);
        $this->event(5, 'activation', '2022-03-15', ['amount' => 100000, 'doc_head' => 900]);
        $this->event(5, 'depreciation', '2022-12-31', [
            'scope' => 'acc', 'amount' => 11000, 'doc_head' => 900, 'period_begin' => '2022-01-01', 'period_end' => '2022-12-31',
        ]);

        $preview = $this->service->preview(self::YEAR_2024);

        $this->assertSame([1], array_column($preview['depreciations'], 'id'));
        $this->assertSame([
            3 => AssetPostingService::REASON_EARLIER_UNPOSTED,
            4 => AssetPostingBuilder::ERROR_ACCOUNT_MISSING,
            5 => DepreciationRunService::REASON_EARLIER_MISSING,
        ], $this->excluded($preview));
        $this->assertSame('1. 5. 2023', array_column($preview['excluded'], 'detail', 'id')[3]);
    }

    public function testTaxCircuitAndOpeningBalanceAreNeverOffered(): void
    {
        // Karta s počátečním stavem a jen daňovým odpisem: nic k zaúčtování.
        $this->card(1);
        $this->plans->cards[1]['acc_method'] = 'time';
        $this->plans->cards[1]['acc_months'] = 60;
        foreach (['tax', 'acc'] as $scope) {
            $this->event(1, 'opening', '2024-01-01', [
                'scope' => $scope, 'amount' => 100000, 'accumulated' => 33250, 'units_done' => $scope === 'tax' ? 2 : 20,
                'original_date' => '2022-03-15',
            ]);
        }
        $this->event(1, 'depreciation', '2024-12-31', [
            'scope' => 'tax', 'amount' => 22250, 'period_begin' => '2024-01-01', 'period_end' => '2024-12-31',
        ]);

        $preview = $this->service->preview(self::YEAR_2024);

        $this->assertSame([], $preview['events']);
        $this->assertSame([1], array_column($preview['depreciations'], 'id'), 'účetní odpis roku se nabízí');
        $this->assertSame(2, $preview['rowCount']);
    }

    public function testBlockersStopTheRun(): void
    {
        $this->postedUntil2023(1);
        $this->service->series = null;
        $this->plans->lockedMonths = [202412];
        $this->documents->available = false;

        $preview = $this->service->preview(self::YEAR_2024);

        $this->assertFalse($preview['canPost']);
        $this->assertSame(
            [AssetPostingService::ERROR_SERIES_MISSING, AssetPostingService::ERROR_MONTH_LOCKED, AssetPostingService::ERROR_DOCUMENTS_UNAVAILABLE],
            array_column($preview['blockers'], 'code'),
        );
        // Odpis by padl do zamčeného měsíce — karta je vyloučená i sama o sobě.
        $this->assertSame([1 => DepreciationRunService::REASON_MONTH_LOCKED], $this->excluded($preview));

        try {
            $this->service->post(self::YEAR_2024);
            $this->fail('Běh s překážkou měl být odmítnut');
        } catch (AssetPostingException $e) {
            $this->assertSame(AssetPostingService::ERROR_SERIES_MISSING, $e->errorCode);
            $this->assertCount(3, $e->details);
        }
        $this->assertSame([], $this->service->log, 'odmítnutí před transakcí');
        $this->assertSame([], $this->writer->written);
    }

    // ── zaúčtování ──────────────────────────────────────────────────────────

    public function testPostWritesDepreciationsCreatesDocumentAndLinksEvents(): void
    {
        $this->postedUntil2023(2);
        $improvement = $this->event(2, 'improvement', '2024-06-01', ['amount' => 20000]);
        $this->postedUntil2023(1);
        $this->postedUntil2023(4, ['depreciation' => null] + self::ACCOUNTS);   // vyloučená — odpis nedostane

        $result = $this->service->post(self::YEAR_2024);

        $this->assertTrue($result['posted']);
        $this->assertSame(1000, $result['docId']);
        $this->assertSame('60MA240001', $result['docNumber']);
        $this->assertSame(2, $result['depreciationCount']);
        $this->assertSame([1, 2], $this->writer->written, 'odpisy jen pro zaúčtované karty');
        $this->assertSame([4 => AssetPostingBuilder::ERROR_ACCOUNT_MISSING], $this->excluded($result));
        $this->assertSame(['begin', 'lock', 'commit'], $this->service->log);

        $this->assertCount(1, $this->documents->created);
        $head = $this->documents->created[0];
        $this->assertSame('cmnbkp', $head['doc_type']);
        $this->assertSame(77, $head['number_series']);
        $this->assertSame('2024-12-31', $head['accounting_date']);
        $this->assertSame(0, $head['vat_mode']);
        $this->assertSame('Majetek — zaúčtování 2024', $head['doc_text']);
        // TZ před odpisy, v rámci druhu podle inventárního čísla; MD před DAL.
        $this->assertSame([
            [1, 'asset.improvement', 2, 22, 0], [2, 'asset.improvement', 2, 42, 1],
            [3, 'asset.depreciation', 1, 551, 0], [4, 'asset.depreciation', 1, 82, 1],
            [5, 'asset.depreciation', 2, 551, 0], [6, 'asset.depreciation', 2, 82, 1],
        ], array_map(
            static fn(array $r): array => [$r['order_pos'], $r['operation'], $r['asset'], $r['account'], $r['acc_side']],
            $head['rows'],
        ));

        // Navázané jsou TZ a oba nové odpisy — a nic jiného.
        $linked = array_keys($this->service->linked);
        sort($linked);
        $new = array_values(array_filter(
            $this->plans->events,
            static fn(array $e): bool => $e['origin'] === 'system' && $e['scope'] === 'acc',
        ));
        $this->assertCount(2, $new);
        $expected = [$improvement, ...array_column($new, 'id')];
        sort($expected);
        $this->assertSame($expected, $linked);
        $this->assertSame([1000], array_values(array_unique($this->service->linked)));
        $this->assertSame(3, $result['eventCount']);
    }

    public function testPostOfAlreadyPostedPeriodCreatesNothing(): void
    {
        $this->postedUntil2023(1);
        $this->assertTrue($this->service->post(self::YEAR_2024)['posted']);
        $this->service->log = [];

        $again = $this->service->post(self::YEAR_2024);

        $this->assertFalse($again['posted']);
        $this->assertNull($again['docId']);
        $this->assertCount(1, $this->documents->created);
        $this->assertSame(['begin', 'lock', 'rollback'], $this->service->log);
    }

    public function testFailedDocumentRollsBackDepreciationsAndLinks(): void
    {
        $this->postedUntil2023(1);
        $eventsBefore = $this->plans->events;
        $this->documents->failWith = new AssetPostingException('accounting_failed', 'Účet nenalezen');

        try {
            $this->service->post(self::YEAR_2024);
            $this->fail('Selhání dokladu se mělo propsat');
        } catch (AssetPostingException $e) {
            $this->assertSame('accounting_failed', $e->errorCode);
        }

        $this->assertSame(['begin', 'lock', 'rollback'], $this->service->log);
        $this->assertSame($eventsBefore, $this->plans->events, 'odpis běhu se vrátil s transakcí');
        $this->assertSame([], $this->service->linked);
    }

    public function testUnknownPeriodIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->preview(999);
    }

    // ── zrušení zaúčtování ──────────────────────────────────────────────────

    public function testUnpostCancelsOnlyTheLastPostedPeriod(): void
    {
        $this->postedUntil2023(1);
        $this->service->documentsPosted = [
            ['id' => 900, 'doc_number' => '60MA230001', 'accounting_date' => '2023-12-31'],
            ['id' => 950, 'doc_number' => '60MA240001', 'accounting_date' => '2024-12-31'],
        ];

        try {
            $this->service->unpost(self::YEAR_2023);
            $this->fail('Starší období nejde zrušit');
        } catch (AssetPostingException $e) {
            $this->assertSame(AssetPostingService::ERROR_NOT_LAST_PERIOD, $e->errorCode);
            $this->assertStringContainsString('60MA240001', $e->getMessage());
        }
        $this->assertSame([], $this->documents->cancelled);
        $this->assertSame([], $this->service->log);

        $last = $this->service->lastPosting();
        $this->assertSame(self::YEAR_2024, $last['periodId']);
        $this->assertSame([['id' => 950, 'number' => '60MA240001']], $last['docs']);

        $result = $this->service->unpost(self::YEAR_2024);

        $this->assertSame([950], $result['docIds']);
        $this->assertSame(['60MA240001'], $result['docNumbers']);
        $this->assertSame([950], $this->documents->cancelled);
        $this->assertSame([[950]], $this->service->unlinked);
        $this->assertSame(['begin', 'lock', 'commit'], $this->service->log);
    }

    public function testUnpostOfPeriodWithoutPostingIsRejected(): void
    {
        $this->service->documentsPosted = [
            ['id' => 900, 'doc_number' => '60MA230001', 'accounting_date' => '2023-12-31'],
        ];

        try {
            $this->service->unpost(self::YEAR_2024);
            $this->fail('Nezaúčtované období nejde zrušit');
        } catch (AssetPostingException $e) {
            $this->assertSame(AssetPostingService::ERROR_NOTHING_POSTED, $e->errorCode);
        }
        $this->assertNull((new TestablePostingService($this->plans, $this->writer, $this->documents))->lastPosting());
    }

    public function testFailedCancelRollsBack(): void
    {
        $this->service->documentsPosted = [
            ['id' => 950, 'doc_number' => '60MA240001', 'accounting_date' => '2024-12-31'],
        ];
        $this->documents->failWith = new AssetPostingException('document_save_failed', 'Fiskální měsíc 2024/12 je uzamčený');

        try {
            $this->service->unpost(self::YEAR_2024);
            $this->fail('Selhání storna se mělo propsat');
        } catch (AssetPostingException $e) {
            $this->assertSame('document_save_failed', $e->errorCode);
        }
        $this->assertSame(['begin', 'lock', 'rollback'], $this->service->log);
        $this->assertSame([], $this->service->unlinked);
    }
}

/** Služba nad pamětí TestAssetPlanService; transakce = snímek událostí. */
class TestablePostingService extends AssetPostingService
{
    /** @var array<int, array<string, int|null>> id karty → účty skupiny */
    public array $accounts = [];
    /** @var array{id: int, name: string}|null */
    public ?array $series = ['id' => 77, 'name' => 'Majetek'];
    /** @var list<array{id: int, doc_number: string, accounting_date: string}> */
    public array $documentsPosted = [];
    /** @var list<string> */
    public array $log = [];
    /** @var array<int, int> id události → id dokladu */
    public array $linked = [];
    /** @var list<list<int>> */
    public array $unlinked = [];

    /** @var list<array<string, mixed>>|null */
    private ?array $snapshot = null;

    public function __construct(
        private readonly TestAssetPlanService $plans,
        SystemDepreciationWriter $writer,
        AssetPostingDocuments $documents,
    ) {
        $run = new class($plans, $writer) extends DepreciationRunService {
            public function __construct(private readonly TestAssetPlanService $store, SystemDepreciationWriter $writer)
            {
                parent::__construct($store, $writer, null, new PlanMessageTexts(TestAssetPlanService::config()));
            }

            protected function loadCandidateCards(?int $assetId): array
            {
                return array_values(array_filter(
                    $this->store->cards,
                    static fn(array $card): bool => (int) $card['docState'] === 40,
                ));
            }
        };
        parent::__construct(null, $plans, $run, $writer, $documents, null);
    }

    protected function resolveSeries(): ?array
    {
        return $this->series;
    }

    protected function loadUnpostedEvents(string $until): array
    {
        $events = array_values(array_filter(
            $this->plans->events,
            static fn(array $e): bool => (int) $e['docState'] === 40 && $e['scope'] !== 'tax'
                && empty($e['doc_head']) && $e['event_date'] <= $until,
        ));
        usort($events, static fn(array $a, array $b): int
            => [$a['asset'], $a['event_date'], $a['id']] <=> [$b['asset'], $b['event_date'], $b['id']]);
        return $events;
    }

    protected function loadCards(array $ids): array
    {
        $cards = [];
        foreach ($this->plans->cards as $card) {
            if (in_array((int) $card['id'], $ids, true)) {
                $cards[] = $card + ['accounts' => $this->accounts[(int) $card['id']]];
            }
        }
        usort($cards, static fn(array $a, array $b): int => $a['asset_number'] <=> $b['asset_number']);
        return $cards;
    }

    protected function loadAccountLabels(array $accountIds): array
    {
        $labels = [];
        foreach ($accountIds as $id) {
            $labels[$id] = ['number' => sprintf('%03d000', $id), 'name' => "Účet {$id}"];
        }
        return $labels;
    }

    protected function loadPostedDocuments(): array
    {
        return $this->documentsPosted;
    }

    protected function linkEvents(array $eventIds, int $docId): void
    {
        foreach ($eventIds as $id) {
            $this->linked[$id] = $docId;
        }
        foreach ($this->plans->events as &$event) {
            if (in_array((int) $event['id'], $eventIds, true)) {
                $event['doc_head'] = $docId;
            }
        }
    }

    protected function unlinkEvents(array $docIds): int
    {
        $this->unlinked[] = $docIds;
        return 0;
    }

    protected function lockCards(): void
    {
        $this->log[] = 'lock';
    }

    protected function begin(): void
    {
        $this->log[] = 'begin';
        $this->snapshot = $this->plans->events;
    }

    protected function commit(): void
    {
        $this->log[] = 'commit';
        $this->snapshot = null;
    }

    protected function rollback(): void
    {
        $this->log[] = 'rollback';
        if ($this->snapshot !== null) {
            $this->plans->events = $this->snapshot;
            $this->snapshot = null;
        }
        $this->linked = [];
    }
}

/** Zapisovač, který systémové odpisy ukládá do paměti jako potvrzené události. */
class PostingStoreWriter extends SystemDepreciationWriter
{
    /** @var list<int> karty, kterým běh založil odpis */
    public array $written = [];
    private int $nextId = 5000;

    public function __construct(private readonly TestAssetPlanService $plans)
    {
        parent::__construct(null);
    }

    public function write(int $assetId, string $scope, array $rows): float
    {
        $total = 0.0;
        foreach ($rows as $row) {
            \assert($row instanceof PlanRow);
            $this->plans->events[] = [
                'id' => $this->nextId++, 'asset' => $assetId, 'event_kind' => 'depreciation', 'scope' => $scope,
                'event_date' => $row->date, 'period_begin' => $row->period?->begin, 'period_end' => $row->period?->end,
                'amount' => $row->amount, 'half_year' => 0, 'origin' => 'system', 'docState' => 40, 'doc_head' => null,
            ];
            $total += $row->amount;
        }
        $this->written[] = $assetId;
        sort($this->written);
        return $total;
    }
}

/** Doklady bez gatewaye: zaznamená hlavičku, vrátí id a číslo. */
class SpyPostingDocuments extends AssetPostingDocuments
{
    public bool $available = true;
    public ?AssetPostingException $failWith = null;
    /** @var list<array<string, mixed>> */
    public array $created = [];
    /** @var list<int> */
    public array $cancelled = [];

    public function __construct()
    {
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function create(array $head): array
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }
        $this->created[] = $head;
        return ['id' => 999 + count($this->created), 'number' => '60MA24000' . count($this->created)];
    }

    public function cancel(int $docId): void
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }
        $this->cancelled[] = $docId;
    }
}

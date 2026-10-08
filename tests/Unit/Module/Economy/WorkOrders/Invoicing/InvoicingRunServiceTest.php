<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\WorkOrders\Invoicing;

use Dibi\Connection;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Core\Exchange\Common\ApplyResult;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Economy\WorkOrders\Invoicing\InvoiceBuilder;
use Shipard\Module\Economy\WorkOrders\Invoicing\InvoicingRunService;
use Shipard\Module\Economy\WorkOrders\Invoicing\Period;
use Shipard\Module\Economy\WorkOrders\Invoicing\PeriodRepository;
use Shipard\Module\Economy\WorkOrders\Invoicing\RunOptions;
use Shipard\Module\Economy\WorkOrders\Invoicing\RunReport;
use Shipard\Module\Economy\WorkOrders\WorkOrderTypes;

/**
 * Běh (D5, Q4): idempotence, dohánění po V opravě, chyba jedné zakázky,
 * atomicita (výjimka applieru → období bez dokladu), zastaveno se
 * nevystavuje, pojistka dohánění a force, dry-run nic nezapíše.
 */
class InvoicingRunServiceTest extends TestCase
{
    private const TYPES = [
        'periodic' => ['name' => 'Periodická', 'external' => true, 'oneOff' => false, 'invoicing' => 'periodic'],
        'project'  => ['name' => 'Projekt', 'external' => true, 'oneOff' => true, 'invoicing' => null],
    ];

    private const KIND = ['id' => 4, 'type' => 'periodic', 'inv_doc_type' => 'invno', 'inv_number_series' => 1, 'inv_timing' => 'start'];

    /** @return array<string, mixed> */
    private function workOrder(array $overrides = []): array
    {
        return array_merge([
            'id' => 6, 'number' => 'S260001', 'title' => 'Nájem', 'customer' => 2, 'currency' => 'czk', 'kind' => 4,
            'type' => 'periodic', 'docState' => 40, 'date_start' => '2026-08-01', 'date_end' => null,
            'inv_periodicity' => 'month', 'inv_from' => '2026-08-01', 'inv_doc_text' => null, 'cost_center' => null,
            'payment_reference' => null, 'inv_doc_type' => null, 'inv_number_series' => null, 'inv_due_days' => null,
            'inv_timing' => null, 'inv_vat_mode' => null, 'inv_payment_method' => null, 'inv_bank_account' => null,
        ], $overrides);
    }

    public function testRunIssuesDuePeriodsOnceAndSecondRunDoesNothing(): void
    {
        $service = $this->service([$this->workOrder()]);
        $report = $service->run(new RunOptions('2026-10-08', now: '2026-10-08 03:17:00'));

        $this->assertSame(['2026-08-01', '2026-09-01', '2026-10-01'], array_column(array_map(static fn($l) => $l->toArray(), $report->lines), 'periodFrom'));
        $this->assertSame(3, $report->count(RunReport::OUTCOME_ISSUED));
        $this->assertSame([1001, 1002, 1003], array_column(array_map(static fn($l) => $l->toArray(), $report->lines), 'docId'));
        $this->assertSame(3, $service->fakeApplier->applied);
        $this->assertSame(['2026-08-01', '2026-09-01', '2026-10-01'], array_keys($service->fakeRepo->periods[6]));
        foreach ($service->fakeRepo->periods[6] as $period) {
            $this->assertSame('issued', $period['state']);
            $this->assertNotNull($period['doc']);
            $this->assertNotNull($period['content_hash']);
        }
        // Atomicita: zámek řádku, apply a zápis uvnitř jedné transakce.
        $this->assertSame(['begin', 'commit', 'begin', 'commit', 'begin', 'commit'], $service->transactions);
        $this->assertSame(3, $service->fakeRepo->locks);

        // Druhý běh: nic nového.
        $second = $service->run(new RunOptions('2026-10-08', now: '2026-10-08 03:17:00'));
        $this->assertSame([], $second->lines);
        $this->assertSame(3, $service->fakeApplier->applied);

        // Běh 1. 11.: jen listopad.
        $third = $service->run(new RunOptions('2026-11-01', now: '2026-11-01 03:17:00'));
        $this->assertSame(1, $third->count(RunReport::OUTCOME_ISSUED));
        $this->assertSame('2026-11-01', $third->lines[0]->periodFrom);
    }

    public function testDryRunListsPeriodsAndWritesNothing(): void
    {
        $service = $this->service([$this->workOrder()]);
        $report = $service->run(new RunOptions('2026-10-08', dryRun: true));

        $this->assertSame(3, $report->count(RunReport::OUTCOME_PLANNED));
        $this->assertSame('Nájem říjen 2026', $report->lines[2]->message);
        $this->assertSame([], $service->fakeRepo->periods);
        $this->assertSame(0, $service->fakeApplier->applied);
        $this->assertSame([], $service->transactions);
    }

    public function testWorkOrderInRepairIsSkippedAndCaughtUpLater(): void
    {
        // Kandidáti = jen V pořádku; zakázka V opravě ve výběru není.
        $service = $this->service([$this->workOrder(['docState' => 80])]);
        $report = $service->run(new RunOptions('2026-10-08'));
        $this->assertSame([], $report->lines);

        // Po návratu do V pořádku dožene všechna splatná období (3 = ještě bez pojistky).
        $service->workOrders[0]['docState'] = 40;
        $report = $service->run(new RunOptions('2026-10-08'));
        $this->assertSame(3, $report->count(RunReport::OUTCOME_ISSUED));
    }

    public function testCatchUpGuardBlocksMoreThanThreePlannedPeriodsUnlessForced(): void
    {
        $service = $this->service([$this->workOrder(['inv_from' => '2026-05-01'])]);
        $report = $service->run(new RunOptions('2026-10-08', now: '2026-10-08 03:17:00'));

        $this->assertSame(6, $report->count(RunReport::OUTCOME_CATCHUP));
        $this->assertSame(0, $service->fakeApplier->applied);
        // Období existují jako planned s výsledkem catchup — pro upozornění.
        $this->assertCount(6, $service->fakeRepo->periods[6]);
        foreach ($service->fakeRepo->periods[6] as $period) {
            $this->assertSame('planned', $period['state']);
            $this->assertSame('catchup', $period['result']);
        }

        // Vystavit dlužná období = force.
        $forced = $service->run(new RunOptions('2026-10-08', workOrderId: 6, force: true, now: '2026-10-08 03:17:00'));
        $this->assertSame(6, $forced->count(RunReport::OUTCOME_ISSUED));
        foreach ($service->fakeRepo->periods[6] as $period) {
            $this->assertSame('issued', $period['state']);
            $this->assertNull($period['result']);
        }
    }

    public function testFailedApplyLeavesPeriodWithoutDocumentAndRunContinues(): void
    {
        $service = $this->service([$this->workOrder(), $this->workOrder(['id' => 7, 'number' => 'S260002', 'title' => 'Druhá', 'inv_from' => '2026-10-01'])]);
        $service->fakeApplier->failFor = ['2026-09-01'];
        $report = $service->run(new RunOptions('2026-10-08', now: '2026-10-08 03:17:00'));

        $this->assertSame(3, $report->count(RunReport::OUTCOME_ISSUED));
        $this->assertSame(1, $report->count(RunReport::OUTCOME_FAILED));
        $failed = $report->lines[1];
        $this->assertSame('2026-09-01', $failed->periodFrom);
        $this->assertStringContainsString('validation_failed', (string) $failed->message);
        $this->assertTrue($report->hasFailures());

        $september = $service->fakeRepo->periods[6]['2026-09-01'];
        $this->assertSame('planned', $september['state']);
        $this->assertNull($september['doc']);
        $this->assertSame('failed', $september['result']);
        // Transakce září se vrátila zpět.
        $this->assertSame(['begin', 'commit', 'begin', 'rollback', 'begin', 'commit', 'begin', 'commit'], $service->transactions);
        // Druhá zakázka proběhla.
        $this->assertSame(7, $report->lines[3]->workOrderId);
    }

    public function testNoValidRowsIsRecordedAsBuildReason(): void
    {
        $service = $this->service([$this->workOrder(['inv_from' => '2026-10-01'])]);
        $service->rows = [['id' => 1, 'description' => 'Nájem', 'quantity' => 1, 'unit_price' => 1000, 'valid_from' => '2026-11-01', 'valid_to' => null]];
        $report = $service->run(new RunOptions('2026-10-08', now: '2026-10-08 03:17:00'));

        $this->assertSame(1, $report->count(RunReport::OUTCOME_FAILED));
        $this->assertSame('no_rows', $service->fakeRepo->periods[6]['2026-10-01']['result']);
        $this->assertSame(0, $service->fakeApplier->applied);
    }

    public function testStoppedAndIssuedPeriodsAreNeverReissued(): void
    {
        $service = $this->service([$this->workOrder()]);
        $service->fakeRepo->periods[6] = [
            '2026-08-01' => ['id' => 1, 'work_order' => 6, 'period_from' => '2026-08-01', 'period_to' => '2026-08-31', 'state' => 'issued', 'doc' => 500, 'result' => null],
            // Září: doklad v koši = zastaveno — běh ho nevystaví.
            '2026-09-01' => ['id' => 2, 'work_order' => 6, 'period_from' => '2026-09-01', 'period_to' => '2026-09-30', 'state' => 'issued', 'doc' => 501, 'result' => null],
        ];
        $report = $service->run(new RunOptions('2026-10-08', now: '2026-10-08 03:17:00'));

        $this->assertSame(1, $report->count(RunReport::OUTCOME_ISSUED));
        $this->assertSame('2026-10-01', $report->lines[0]->periodFrom);
        $this->assertSame(500, $service->fakeRepo->periods[6]['2026-08-01']['doc']);
        $this->assertSame(501, $service->fakeRepo->periods[6]['2026-09-01']['doc']);
    }

    public function testDateEndStopsFuturePeriodsAndMissingPeriodicityIsReported(): void
    {
        $service = $this->service([$this->workOrder(['date_end' => '2026-10-31'])]);
        $report = $service->run(new RunOptions('2026-11-15', now: '2026-11-15 03:17:00'));
        $this->assertSame(['2026-08-01', '2026-09-01', '2026-10-01'], array_map(static fn($l) => $l->periodFrom, $report->lines));

        $service = $this->service([$this->workOrder(['inv_periodicity' => null])]);
        $report = $service->run(new RunOptions('2026-10-08'));
        $this->assertSame(1, $report->count(RunReport::OUTCOME_FAILED));
        $this->assertNull($report->lines[0]->periodFrom);
    }

    public function testConcurrentRunSkipsPeriodIssuedMeanwhile(): void
    {
        $service = $this->service([$this->workOrder(['inv_from' => '2026-10-01'])]);
        $service->fakeRepo->issuedUnderLock = ['2026-10-01' => 777];
        $report = $service->run(new RunOptions('2026-10-08', now: '2026-10-08 03:17:00'));

        $this->assertSame(1, $report->count(RunReport::OUTCOME_SKIPPED));
        $this->assertSame(0, $service->fakeApplier->applied);
    }

    // ── Přegenerovat a Obnovit (D24) ────────────────────────────────────────

    public function testRegenerateReplacesDraftInPlaceAndRefreshesHash(): void
    {
        $service = $this->service([$this->workOrder()]);
        $service->run(new RunOptions('2026-10-08', now: '2026-10-08 03:17:00'));
        $october = $service->fakeRepo->periods[6]['2026-10-01'];
        $service->fakeRepo->docs = [1003 => ['id' => 1003, 'docState' => 10, 'doc_type' => 'invno']];
        $service->contentHashes[1003] = 'hash-after-regenerate';

        $line = $service->regenerate((int) $october['id'], new RunOptions('2026-10-20', now: '2026-10-20 10:00:00'));

        $this->assertSame(RunReport::OUTCOME_ISSUED, $line->outcome);
        $this->assertSame(1003, $line->docId, 'doklad si drží id');
        $last = end($service->fakeApplier->canonicals);
        $this->assertSame(1003, $last['applyOptions']['replaceConcept']);
        $this->assertSame(10, $last['applyOptions']['targetDocState']);
        $this->assertSame('hash-after-regenerate', $service->fakeRepo->periods[6]['2026-10-01']['content_hash']);
        $this->assertSame('issued', $service->fakeRepo->periods[6]['2026-10-01']['state']);
        $this->assertSame(['begin', 'commit'], array_slice($service->transactions, -2));
    }

    public function testRegenerateRefusesConfirmedOrMissingDocument(): void
    {
        $service = $this->service([$this->workOrder()]);
        $service->run(new RunOptions('2026-10-08', now: '2026-10-08 03:17:00'));
        $id = (int) $service->fakeRepo->periods[6]['2026-10-01']['id'];

        $service->fakeRepo->docs = [1003 => ['id' => 1003, 'docState' => 40, 'doc_type' => 'invno']];
        try {
            $service->regenerate($id, new RunOptions('2026-10-20'));
            $this->fail('potvrzený doklad');
        } catch (\DomainException $e) {
            $this->assertSame(409, $e->getCode());
        }
        $service->fakeRepo->docs = [];
        try {
            $service->regenerate($id, new RunOptions('2026-10-20'));
            $this->fail('chybějící doklad');
        } catch (\DomainException $e) {
            $this->assertSame(409, $e->getCode());
        }
        try {
            $service->regenerate(999, new RunOptions('2026-10-20'));
            $this->fail('neexistující období');
        } catch (\DomainException $e) {
            $this->assertSame(404, $e->getCode());
        }
    }

    public function testRestoreUnlinksDeletedDraftAndIssuesNewOne(): void
    {
        $service = $this->service([$this->workOrder()]);
        $service->run(new RunOptions('2026-10-08', now: '2026-10-08 03:17:00'));
        $september = $service->fakeRepo->periods[6]['2026-09-01'];
        // Září v koši = zastaveno; běh ho sám znovu nevystaví.
        $service->fakeRepo->docs = [
            1001 => ['id' => 1001, 'docState' => 10, 'doc_type' => 'invno'],
            1002 => ['id' => 1002, 'docState' => 90, 'doc_type' => 'invno'],
            1003 => ['id' => 1003, 'docState' => 10, 'doc_type' => 'invno'],
        ];
        $this->assertSame([], $service->run(new RunOptions('2026-10-08', now: '2026-10-08 03:17:00'))->lines);

        $line = $service->restore((int) $september['id'], new RunOptions('2026-10-08', now: '2026-10-08 12:00:00'));

        $this->assertSame(RunReport::OUTCOME_ISSUED, $line->outcome);
        $this->assertSame(1004, $line->docId, 'nový koncept, starý zůstává v koši');
        $restored = $service->fakeRepo->periods[6]['2026-09-01'];
        $this->assertSame('issued', $restored['state']);
        $this->assertSame(1004, $restored['doc']);

        // Nezastavené období obnovit nejde.
        try {
            $service->restore((int) $service->fakeRepo->periods[6]['2026-08-01']['id'], new RunOptions('2026-10-08'));
            $this->fail('vystavené období s živým dokladem');
        } catch (\DomainException $e) {
            $this->assertSame(409, $e->getCode());
        }
    }

    // ── testovací služba ────────────────────────────────────────────────────

    /** @param list<array<string, mixed>> $workOrders */
    private function service(array $workOrders): TestableInvoicingRunService
    {
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(static fn(string $id): mixed => match ($id) {
            WorkOrderTypes::CFG_ITEM => self::TYPES,
            'world.base.documentLanguages' => ['cs' => [], 'en' => []],
            'world.base.countries' => ['cz' => ['languages' => ['cs']]],
            default => null,
        });
        $db = $this->createMock(Connection::class);
        $db->method('fetchSingle')->willReturn(0);
        $service = new TestableInvoicingRunService(
            $db,
            $config,
            new FakePeriodRepository($db),
            new FakeInvoiceBuilder($db, $config, 'cz'),
            new FakeApplier(),
            new WorkOrderTypes($config),
        );
        $service->workOrders = $workOrders;
        $service->kinds = [4 => self::KIND];
        $service->rows = [['id' => 1, 'description' => 'Nájem', 'quantity' => 1, 'unit_price' => 12000, 'valid_from' => null, 'valid_to' => null]];
        $db->method('begin')->willReturnCallback(function () use ($service): void { $service->transactions[] = 'begin'; });
        $db->method('commit')->willReturnCallback(function () use ($service): void { $service->transactions[] = 'commit'; });
        $db->method('rollback')->willReturnCallback(function () use ($service): void { $service->transactions[] = 'rollback'; });
        return $service;
    }
}

class TestableInvoicingRunService extends InvoicingRunService
{
    /** @var list<array<string, mixed>> */
    public array $workOrders = [];
    /** @var array<int, array<string, mixed>> */
    public array $kinds = [];
    /** @var list<array<string, mixed>> */
    public array $rows = [];
    /** @var list<string> */
    public array $transactions = [];

    public function __construct(
        Connection $db,
        ?ConfigRuntime $config,
        public readonly FakePeriodRepository $fakeRepo,
        InvoiceBuilder $builder,
        public readonly FakeApplier $fakeApplier,
        WorkOrderTypes $types,
    ) {
        parent::__construct($db, $config, $fakeRepo, $builder, $fakeApplier, $types);
    }

    protected function candidates(?int $workOrderId): array
    {
        return array_values(array_filter(
            $this->workOrders,
            static fn(array $wo): bool => (int) $wo['docState'] === 40 && ($workOrderId === null || (int) $wo['id'] === $workOrderId),
        ));
    }

    protected function kind(int $kindId): ?array
    {
        return $this->kinds[$kindId] ?? null;
    }

    protected function rowsOf(int $workOrderId): array
    {
        return $this->rows;
    }

    /** @var array<int, string> doklad → otisk (výchozí hash-<id>) */
    public array $contentHashes = [];

    protected function contentHashOf(int $docId): string
    {
        return $this->contentHashes[$docId] ?? 'hash-' . $docId;
    }

    protected function workOrder(int $workOrderId): ?array
    {
        foreach ($this->workOrders as $wo) {
            if ((int) $wo['id'] === $workOrderId) {
                return $wo;
            }
        }
        return null;
    }
}

/** Evidence období v paměti, klíč = začátek období. */
class FakePeriodRepository extends PeriodRepository
{
    /** @var array<int, array<string, array<string, mixed>>> zakázka → začátek → období */
    public array $periods = [];
    public int $locks = 0;
    /** @var array<string, int> začátek → doklad, který „mezitím“ vystavil jiný běh */
    public array $issuedUnderLock = [];
    /** @var array<int, array<string, mixed>> doklady podle id (docState, doc_type) — pro docInfo */
    public array $docs = [];

    public function find(int $id): ?array
    {
        foreach ($this->periods as $rows) {
            foreach ($rows as $period) {
                if ((int) $period['id'] === $id) {
                    return $period;
                }
            }
        }
        return null;
    }

    public function docInfo(array $docIds): array
    {
        $out = [];
        foreach ($docIds as $docId) {
            if (isset($this->docs[(int) $docId])) {
                $out[(int) $docId] = $this->docs[(int) $docId];
            }
        }
        return $out;
    }

    public function listFor(int $workOrderId): array
    {
        $rows = array_values($this->periods[$workOrderId] ?? []);
        usort($rows, static fn(array $a, array $b): int => strcmp($b['period_from'], $a['period_from']));
        return $rows;
    }

    public function ensurePlanned(int $workOrderId, array $periods, string $now): void
    {
        foreach ($periods as $period) {
            if (isset($this->periods[$workOrderId][$period->from])) {
                continue;
            }
            $this->periods[$workOrderId][$period->from] = [
                'id' => $this->nextId(), 'work_order' => $workOrderId, 'period_from' => $period->from, 'period_to' => $period->to,
                'state' => self::STATE_PLANNED, 'doc' => null, 'content_hash' => null, 'result' => null, 'message' => null, 'waiting_since' => null,
            ];
        }
        ksort($this->periods[$workOrderId]);
    }

    public function lockForUpdate(int $id): ?array
    {
        $this->locks++;
        foreach ($this->periods as $workOrderId => $rows) {
            foreach ($rows as $from => $period) {
                if ((int) $period['id'] === $id) {
                    if (isset($this->issuedUnderLock[$from])) {
                        $this->periods[$workOrderId][$from]['state'] = self::STATE_ISSUED;
                        $this->periods[$workOrderId][$from]['doc'] = $this->issuedUnderLock[$from];
                    }
                    return $this->periods[$workOrderId][$from];
                }
            }
        }
        return null;
    }

    private function nextId(): int
    {
        $max = 0;
        foreach ($this->periods as $rows) {
            foreach ($rows as $period) {
                $max = max($max, (int) $period['id']);
            }
        }
        return $max + 1;
    }

    public function markIssued(int $id, int $docId, string $contentHash, string $now): void
    {
        $this->set($id, ['state' => self::STATE_ISSUED, 'doc' => $docId, 'content_hash' => $contentHash, 'result' => null, 'message' => null, 'waiting_since' => null]);
    }

    public function markWaiting(int $id, int $docId, string $contentHash, ?string $message, string $now, ?string $waitingSince): void
    {
        $this->set($id, ['state' => self::STATE_WAITING, 'doc' => $docId, 'content_hash' => $contentHash, 'result' => self::RESULT_WAITING, 'message' => $message, 'waiting_since' => $waitingSince ?? $now]);
    }

    public function markPlanned(int $id, ?string $result, ?string $message, string $now): void
    {
        $this->set($id, ['state' => self::STATE_PLANNED, 'doc' => null, 'result' => $result, 'message' => $message]);
    }

    public function markResult(int $id, ?string $result, ?string $message, string $now): void
    {
        $this->set($id, ['result' => $result, 'message' => $message]);
    }

    public function unlinkDoc(int $id, string $now): void
    {
        $this->set($id, ['state' => self::STATE_PLANNED, 'doc' => null, 'content_hash' => null, 'result' => null, 'message' => null, 'waiting_since' => null]);
    }

    protected function execute(string $sql, mixed ...$args): void
    {
        throw new \LogicException('FakePeriodRepository nepíše do DB');
    }

    private function set(int $id, array $values): void
    {
        foreach ($this->periods as $workOrderId => $rows) {
            foreach ($rows as $from => $period) {
                if ((int) $period['id'] === $id) {
                    $this->periods[$workOrderId][$from] = array_merge($period, $values);
                }
            }
        }
    }
}

/** Builder bez DB: zákazník, jazyk, kódy. */
class FakeInvoiceBuilder extends InvoiceBuilder
{
    protected function customerParty(int $personId): ?array
    {
        return ['name' => 'Zákazník', 'companyId' => '12345678', 'country' => 'cz'];
    }

    protected function personLanguage(int $personId): ?string
    {
        return 'cs';
    }

    protected function costCenterCode(int $costCenterId): ?string
    {
        return null;
    }

    protected function itemCode(int $itemId): ?string
    {
        return null;
    }

    protected function unitShortcut(?int $unitId): ?string
    {
        return null;
    }

    protected function defaultBankAccount(): ?int
    {
        return 3;
    }

    protected function periodTexts(string $language): ?array
    {
        return null;
    }
}

/** Applier: každé apply = nový doklad 1001, 1002, …; selhání pro vybraná období. */
class FakeApplier extends DocumentApplier
{
    public int $applied = 0;
    /** @var list<string> začátky období, jejichž apply selže */
    public array $failFor = [];
    /** @var list<array<string, mixed>> */
    public array $canonicals = [];

    public function __construct()
    {
    }

    public function apply(array $canonical): ApplyResult
    {
        $this->canonicals[] = $canonical;
        if (in_array($canonical['dates']['periodFrom'] ?? '', $this->failFor, true)) {
            return ApplyResult::error('validation_failed', 'Doklad neprošel validací', $canonical, statusCode: 422);
        }
        $this->applied++;
        $replace = $canonical['applyOptions']['replaceConcept'] ?? null;
        return ApplyResult::ok($canonical, is_int($replace) ? $replace : 1000 + $this->applied);
    }
}

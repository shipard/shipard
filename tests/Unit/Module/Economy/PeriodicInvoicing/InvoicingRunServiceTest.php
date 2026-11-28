<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\PeriodicInvoicing;

use Dibi\Connection;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Economy\PeriodicInvoicing\Contributor\ContributionResult;
use Shipard\Module\Economy\PeriodicInvoicing\Contributor\InvoiceContributorRegistry;
use Shipard\Module\Economy\PeriodicInvoicing\RunOptions;
use Shipard\Module\Economy\PeriodicInvoicing\RunReport;

/**
 * Běh (D5, Q4): idempotence, dohánění po V opravě, chyba jedné zakázky,
 * atomicita (výjimka applieru → období bez dokladu), zastaveno se
 * nevystavuje, pojistka dohánění a force, dry-run nic nezapíše.
 */
class InvoicingRunServiceTest extends TestCase
{
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
        // Generovaný doklad jde applierem jako pokyn (#166 U10) — volby
        // interpretace neposílá.
        foreach ($service->fakeApplier->canonicals as $canonical) {
            $this->assertArrayNotHasKey('interpret', $canonical['applyOptions'] ?? []);
            $this->assertArrayNotHasKey('_resolve', $canonical);
        }
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
        $forced = $service->run(new RunOptions('2026-10-08', sourceId: 'workOrders', recordId: 6, force: true, now: '2026-10-08 03:17:00'));
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
        $this->assertSame(7, $report->lines[3]->recordId);
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

    // ── Přispěvatelé obsahu (D7, D10) ───────────────────────────────────────

    /** Řádky předpisu: pevný nájem + řádek přispěvatele spotřeby. */
    private function rowsWithContributor(): array
    {
        return [
            ['id' => 1, 'description' => 'Nájem', 'quantity' => 1, 'unit_price' => 12000, 'valid_from' => null, 'valid_to' => null, 'contributor' => null],
            ['id' => 2, 'description' => 'Spotřeba energií', 'quantity' => 1, 'unit_price' => 1, 'valid_from' => null, 'valid_to' => null, 'contributor' => 'energy.consumption'],
        ];
    }

    public function testReadyContributorReplacesItsRowsBeforeApply(): void
    {
        $contributor = new FakeContributor(ContributionResult::ready([
            ['rowKind' => 'item', 'description' => 'Elektřina 10/2026', 'quantity' => 120.0, 'unitPrice' => 6.5],
            ['rowKind' => 'item', 'description' => 'Plyn 10/2026', 'quantity' => 30.0, 'unitPrice' => 20.0],
        ]));
        $service = $this->service([$this->workOrder(['inv_from' => '2026-10-01'])], $contributor);
        $service->rows = $this->rowsWithContributor();
        $report = $service->run(new RunOptions('2026-10-08', now: '2026-10-08 03:17:00'));

        $this->assertSame(1, $report->count(RunReport::OUTCOME_ISSUED));
        $canonical = $service->fakeApplier->canonicals[0];
        $this->assertSame(['Nájem', 'Elektřina 10/2026', 'Plyn 10/2026'], array_column($canonical['rows'], 'description'));
        $this->assertSame([1, 2, 3], array_column($canonical['rows'], 'orderPos'));
        $this->assertSame('issued', $service->fakeRepo->periods[6]['2026-10-01']['state']);
        // Kontext přispěvatele: jeho řádky předpisu a jejich pozice v dokladu.
        $this->assertSame([2], array_column($contributor->contexts[0]->rows, 'id'));
        $this->assertSame([1], $contributor->contexts[0]->rowIndexes);
        $this->assertSame(0.0, $contributor->contexts[0]->canonical['rows'][1]['quantity']);
    }

    public function testWaitingContributorCreatesDraftAndPeriodWaitsThenIssuesWhenReady(): void
    {
        $contributor = new FakeContributor(ContributionResult::waiting('Podklady dodavatele za říjen ještě nepřišly.'));
        $service = $this->service([$this->workOrder(['inv_from' => '2026-10-01'])], $contributor);
        $service->rows = $this->rowsWithContributor();
        $report = $service->run(new RunOptions('2026-10-08', now: '2026-10-08 03:17:00'));

        $this->assertSame(1, $report->count(RunReport::OUTCOME_WAITING));
        $this->assertSame(1001, $report->lines[0]->docId, 'koncept vznikl hned');
        $period = $service->fakeRepo->periods[6]['2026-10-01'];
        $this->assertSame('waiting', $period['state']);
        $this->assertSame(1001, $period['doc']);
        $this->assertSame('2026-10-08 03:17:00', $period['waiting_since']);
        $this->assertSame(0.0, $service->fakeApplier->canonicals[0]['rows'][1]['quantity']);

        // Další běh: stále čeká — waiting_since zůstává.
        $second = $service->run(new RunOptions('2026-10-12', now: '2026-10-12 03:17:00'));
        $this->assertSame(1, $second->count(RunReport::OUTCOME_WAITING));
        $this->assertSame('2026-10-08 03:17:00', $service->fakeRepo->periods[6]['2026-10-01']['waiting_since']);
        $this->assertSame(1, $service->fakeApplier->applied);

        // Podklady přišly, koncept nikdo neupravil → přegenerování v místě.
        $contributor->result = ContributionResult::ready([['rowKind' => 'item', 'description' => 'Elektřina', 'quantity' => 120.0, 'unitPrice' => 6.5]]);
        $third = $service->run(new RunOptions('2026-10-15', now: '2026-10-15 03:17:00'));
        $this->assertSame(1, $third->count(RunReport::OUTCOME_ISSUED));
        $last = end($service->fakeApplier->canonicals);
        $this->assertSame(1001, $last['applyOptions']['replaceConcept']);
        $this->assertSame(['Nájem', 'Elektřina'], array_column($last['rows'], 'description'));
        $this->assertSame('issued', $service->fakeRepo->periods[6]['2026-10-01']['state']);
        $this->assertNull($service->fakeRepo->periods[6]['2026-10-01']['result']);
    }

    public function testEditedDraftIsNotRegeneratedAutomatically(): void
    {
        $contributor = new FakeContributor(ContributionResult::waiting('čekám'));
        $service = $this->service([$this->workOrder(['inv_from' => '2026-10-01'])], $contributor);
        $service->rows = $this->rowsWithContributor();
        $service->run(new RunOptions('2026-10-08', now: '2026-10-08 03:17:00'));

        // Ruční úprava konceptu = jiný otisk obsahu.
        $service->contentHashes[1001] = 'edited-by-user';
        $contributor->result = ContributionResult::ready([['rowKind' => 'item', 'description' => 'Elektřina', 'quantity' => 1.0, 'unitPrice' => 1.0]]);
        $report = $service->run(new RunOptions('2026-10-15', now: '2026-10-15 03:17:00'));

        $this->assertSame(1, $report->count(RunReport::OUTCOME_WAITING));
        $this->assertStringContainsString('Přegenerovat', (string) $report->lines[0]->message);
        $period = $service->fakeRepo->periods[6]['2026-10-01'];
        $this->assertSame('waiting', $period['state']);
        $this->assertSame('edited', $period['result']);
        $this->assertSame(1, $service->fakeApplier->applied, 'žádný další apply');
    }

    public function testFailedOrUnknownContributorLeavesPeriodPlanned(): void
    {
        $service = $this->service([$this->workOrder(['inv_from' => '2026-10-01'])], new FakeContributor(ContributionResult::failed('Dodavatel vrátil chybu.')));
        $service->rows = $this->rowsWithContributor();
        $report = $service->run(new RunOptions('2026-10-08', now: '2026-10-08 03:17:00'));
        $this->assertSame(1, $report->count(RunReport::OUTCOME_FAILED));
        $this->assertStringContainsString('Dodavatel vrátil chybu.', (string) $report->lines[0]->message);
        $this->assertSame('contributor_failed', $service->fakeRepo->periods[6]['2026-10-01']['result']);
        $this->assertSame(0, $service->fakeApplier->applied);

        // Řádek s neregistrovaným přispěvatelem.
        $service = $this->service([$this->workOrder(['inv_from' => '2026-10-01'])]);
        $service->rows = $this->rowsWithContributor();
        $report = $service->run(new RunOptions('2026-10-08', now: '2026-10-08 03:17:00'));
        $this->assertSame(1, $report->count(RunReport::OUTCOME_FAILED));
        $this->assertStringContainsString('není registrovaný', (string) $report->lines[0]->message);
    }

    // ── Přegenerovat a Obnovit (D24) ────────────────────────────────────────

    public function testRegenerateReplacesDraftInPlaceAndRefreshesHash(): void
    {
        $service = $this->service([$this->workOrder()]);
        $service->run(new RunOptions('2026-10-08', now: '2026-10-08 03:17:00'));
        $october = $service->fakeRepo->periods[6]['2026-10-01'];
        $service->fakeRepo->docs = [1003 => ['id' => 1003, 'docState' => 10, 'doc_type' => 'invno']];
        $service->contentHashes[1003] = 'hash-after-regenerate';

        $line = $service->regenerate($service->fakeSource, (int) $october['id'], new RunOptions('2026-10-20', now: '2026-10-20 10:00:00'));

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
            $service->regenerate($service->fakeSource, $id, new RunOptions('2026-10-20'));
            $this->fail('potvrzený doklad');
        } catch (\DomainException $e) {
            $this->assertSame(409, $e->getCode());
        }
        $service->fakeRepo->docs = [];
        try {
            $service->regenerate($service->fakeSource, $id, new RunOptions('2026-10-20'));
            $this->fail('chybějící doklad');
        } catch (\DomainException $e) {
            $this->assertSame(409, $e->getCode());
        }
        try {
            $service->regenerate($service->fakeSource, 999, new RunOptions('2026-10-20'));
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

        $line = $service->restore($service->fakeSource, (int) $september['id'], new RunOptions('2026-10-08', now: '2026-10-08 12:00:00'));

        $this->assertSame(RunReport::OUTCOME_ISSUED, $line->outcome);
        $this->assertSame(1004, $line->docId, 'nový koncept, starý zůstává v koši');
        $restored = $service->fakeRepo->periods[6]['2026-09-01'];
        $this->assertSame('issued', $restored['state']);
        $this->assertSame(1004, $restored['doc']);

        // Nezastavené období obnovit nejde.
        try {
            $service->restore($service->fakeSource, (int) $service->fakeRepo->periods[6]['2026-08-01']['id'], new RunOptions('2026-10-08'));
            $this->fail('vystavené období s živým dokladem');
        } catch (\DomainException $e) {
            $this->assertSame(409, $e->getCode());
        }
    }

    // ── víc zdrojů (#175 D1, D3) ────────────────────────────────────────────

    public function testTwoSourcesKeepSeparatePeriodRegistersAndOwnDimensions(): void
    {
        // Druhý zdroj se záznamem stejného id jako zakázka — evidence období
        // jsou oddělené, id období se v nich kryjí.
        $contracts = $this->contractsSource();
        $service = $this->service([$this->workOrder(['inv_from' => '2026-10-01'])], null, [$contracts]);

        $report = $service->run(new RunOptions('2026-10-08', now: '2026-10-08 03:17:00'));

        $this->assertSame(
            [['workOrders', 6, 'S260001 Nájem', 1001], ['contracts', 6, 'N-1 Byt 12', 1002]],
            array_map(static fn($l): array => [$l->sourceId, $l->recordId, $l->recordLabel, $l->docId], $report->lines),
        );
        $workOrderPeriod = $service->fakeRepo->periods[6]['2026-10-01'];
        $contractPeriod = $service->repos['contracts']->periods[6]['2026-10-01'];
        $this->assertSame([1, 6, 1001], [$workOrderPeriod['id'], $workOrderPeriod['work_order'], $workOrderPeriod['doc']]);
        $this->assertSame([1, 6, 1002], [$contractPeriod['id'], $contractPeriod['contract'], $contractPeriod['doc']]);
        $this->assertArrayNotHasKey('work_order', $contractPeriod);

        // Dimenze i řádky dává zdroj, druh dokladu zdroje zůstává společný (D7).
        $this->assertSame(['workOrder' => 6], $service->fakeApplier->canonicals[0]['dimensions']);
        $this->assertSame(['place' => 9], $service->fakeApplier->canonicals[1]['dimensions']);
        $this->assertSame(5000.0, $service->fakeApplier->canonicals[1]['rows'][0]['unitPrice']);
        $this->assertSame(['kind' => 'workOrder'], $service->fakeApplier->canonicals[1]['source']);

        // Běh jen pro jeden zdroj.
        $november = $service->run(new RunOptions('2026-11-01', sourceId: 'contracts', now: '2026-11-01 03:17:00'));
        $this->assertSame([['contracts', '2026-11-01']], array_map(static fn($l): array => [$l->sourceId, $l->periodFrom], $november->lines));
        $this->assertCount(1, $service->fakeRepo->periods[6], 'evidence zakázek beze změny');
    }

    public function testRegenerateAndRestoreTouchOnlyTheGivenSource(): void
    {
        $contracts = $this->contractsSource();
        $service = $this->service([$this->workOrder(['inv_from' => '2026-10-01'])], null, [$contracts]);
        $service->run(new RunOptions('2026-10-08', now: '2026-10-08 03:17:00'));
        $docs = [
            1001 => ['id' => 1001, 'docState' => 10, 'doc_type' => 'invno'],
            1002 => ['id' => 1002, 'docState' => 10, 'doc_type' => 'invno'],
        ];
        $service->fakeRepo->docs = $docs;
        $service->repos['contracts']->docs = $docs;

        // Období 1 existuje v obou evidencích — rozhoduje zdroj.
        $line = $service->regenerate($contracts, 1, new RunOptions('2026-10-20', now: '2026-10-20 10:00:00'));
        $this->assertSame(['contracts', 1002], [$line->sourceId, $line->docId]);
        $last = end($service->fakeApplier->canonicals);
        $this->assertSame(1002, $last['applyOptions']['replaceConcept']);
        $this->assertSame(['place' => 9], $last['dimensions']);

        // Obnovit: doklad smlouvy v koši, zakázka zůstává vystavená.
        $contractsRepo = $service->repos['contracts'];
        $contractsRepo->docs[1002]['docState'] = 90;
        $restored = $service->restore($contracts, 1, new RunOptions('2026-10-20', now: '2026-10-20 11:00:00'));
        $this->assertSame(['contracts', RunReport::OUTCOME_ISSUED], [$restored->sourceId, $restored->outcome]);
        $this->assertNotSame(1002, $service->repos['contracts']->periods[6]['2026-10-01']['doc']);
        $this->assertSame(1001, $service->fakeRepo->periods[6]['2026-10-01']['doc']);
    }

    public function testUnknownSourceAndRecordWithoutSourceAreRejected(): void
    {
        $service = $this->service([$this->workOrder()]);
        try {
            $service->run(new RunOptions('2026-10-08', sourceId: 'nope'));
            $this->fail('neznámý zdroj');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString("'nope'", $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        new RunOptions('2026-10-08', recordId: 6);
    }

    public function testContributorContextCarriesSourceAndRecord(): void
    {
        $contributor = new FakeContributor(ContributionResult::waiting('čeká'));
        $service = $this->service([$this->workOrder(['inv_from' => '2026-10-01'])], $contributor);
        $service->rows[] = ['id' => 2, 'description' => 'Spotřeba', 'quantity' => 1, 'unit_price' => 1, 'valid_from' => null, 'valid_to' => null, 'contributor' => 'energy.consumption'];
        $service->run(new RunOptions('2026-10-08', now: '2026-10-08 03:17:00'));

        $this->assertSame('workOrders', $contributor->contexts[0]->source);
        $this->assertSame(6, $contributor->contexts[0]->record['id']);
    }

    private function contractsSource(): FakeInvoicingSource
    {
        $contracts = new FakeInvoicingSource(
            id: 'contracts',
            table: 'test_contracts_heads',
            periodsTable: 'test_contracts_periods',
            recordColumn: 'contract',
            viewerId: 'test.contracts.heads',
            navSection: 'buildings',
            noun: 'Smlouva',
            openLabel: 'Otevřít smlouvu',
            dimensions: static fn(array $record): array => ['place' => 9],
        );
        $contracts->records = [$this->workOrder(['number' => 'N-1', 'title' => 'Byt 12', 'inv_from' => '2026-10-01'])];
        $contracts->kinds = [4 => self::KIND];
        $contracts->rows = [['id' => 1, 'description' => 'Nájemné', 'quantity' => 1, 'unit_price' => 5000, 'valid_from' => null, 'valid_to' => null]];
        return $contracts;
    }

    // ── testovací služba ────────────────────────────────────────────────────

    /**
     * @param list<array<string, mixed>> $workOrders záznamy prvního zdroje (`workOrders`)
     * @param list<FakeInvoicingSource> $moreSources další zdroje se svými záznamy
     */
    private function service(array $workOrders, ?FakeContributor $contributor = null, array $moreSources = []): TestableInvoicingRunService
    {
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(static fn(string $id): mixed => match ($id) {
            'world.base.documentLanguages' => ['cs' => [], 'en' => []],
            'world.base.countries' => ['cz' => ['languages' => ['cs']]],
            default => null,
        });
        $db = $this->createMock(Connection::class);
        $db->method('fetchSingle')->willReturn(0);
        $sources = [new FakeInvoicingSource(), ...$moreSources];
        $repos = [];
        foreach ($sources as $source) {
            $repos[$source->id()] = new FakePeriodRepository($db, $source->periodsTable(), $source->periodsRecordColumn());
        }
        $service = new TestableInvoicingRunService(
            $db,
            $config,
            $sources,
            $repos,
            new FakeInvoiceBuilder($db, $config, 'cz'),
            new FakeApplier(),
            $contributor !== null ? new InvoiceContributorRegistry([$contributor->id() => $contributor]) : InvoiceContributorRegistry::empty(),
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

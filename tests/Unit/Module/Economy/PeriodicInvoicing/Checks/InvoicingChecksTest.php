<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\PeriodicInvoicing\Checks;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Economy\PeriodicInvoicing\Checks\CatchupBlockedCheck;
use Shipard\Module\Economy\PeriodicInvoicing\Checks\InvoicesToReviewCheck;
use Shipard\Module\Economy\PeriodicInvoicing\Checks\PeriodFailedCheck;
use Shipard\Module\Economy\PeriodicInvoicing\Checks\PeriodWaitingCheck;
use Shipard\Module\Economy\PeriodicInvoicing\Source\InvoicingSources;
use Shipard\Tests\Unit\Module\Economy\PeriodicInvoicing\FakeInvoicingSource;

/**
 * Upozornění periodické fakturace (tasks §6, #175 D6): jeden souhrnný
 * nález konceptů ke kontrole, per záznam zdroje selhání, pojistka dohánění
 * a čekání na podklady — jednou pro všechny zdroje. Výběr drží SQL —
 * ověřuje se tvar a parametry.
 */
class InvoicingChecksTest extends TestCase
{
    /** Zdroj zakázek nad poli: záznamy 6 a 7 jako v řádcích období. */
    private function workOrders(): FakeInvoicingSource
    {
        $source = new FakeInvoicingSource();
        $source->records = [
            ['id' => 6, 'number' => 'S260001', 'title' => 'Nájem'],
            ['id' => 7, 'number' => null, 'title' => 'Druhá'],
        ];
        return $source;
    }

    private function sources(): InvoicingSources
    {
        return new InvoicingSources([$this->workOrders()]);
    }

    /** @param list<array<string, mixed>> $rows */
    private function db(array $rows, ?array &$captured = null): DataSourceConnection
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(function (...$args) use ($rows, &$captured): array {
            $captured = $args;
            return $rows;
        });
        return $db;
    }

    private function config(): ConfigRuntime
    {
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(static fn(string $id): mixed => $id === 'docs.core.docTypes'
            ? ['invno' => ['name' => 'Faktura vydaná'], 'invpo' => ['name' => 'Zálohová faktura vydaná']]
            : null);
        return $config;
    }

    // --- koncepty ke kontrole --------------------------------------------------

    public function testInvoicesToReviewIsOneSummaryFindingWithViewerActions(): void
    {
        $captured = null;
        $check = (new InvoicesToReviewCheck($this->db([['doc_type' => 'invno', 'n' => 3], ['doc_type' => 'invpo', 'n' => 1]], $captured), $this->config(), 'cs'))->withSources($this->sources());
        $findings = $check->run();

        $this->assertCount(1, $findings);
        $f = $findings[0];
        $this->assertSame('summary', $f->findingKey);
        $this->assertSame('info', $f->severity);
        $this->assertSame('4 koncepty z periodické fakturace ke kontrole', $f->title);
        $this->assertNull($f->subjectTableId);
        $this->assertSame(['open_invno', 'open_invpo'], array_column($f->actions, 'id'));
        $this->assertTrue($f->actions[0]['primary']);
        $this->assertFalse($f->actions[1]['primary']);
        $this->assertSame('Otevřít: Faktura vydaná (3)', $f->actions[0]['label']);
        $this->assertSame(['viewerId' => 'docs.invoicesOut.heads', 'filters' => ['source_kind' => 'workOrder']], $f->actions[0]['target']);
        $this->assertSame('docs.proformasOut.heads', $f->actions[1]['target']['viewerId']);
        $this->assertSame(['total' => 4, 'byDocType' => ['invno' => 3, 'invpo' => 1], 'navSection' => 'workOrders'], $f->context);
        $this->assertStringContainsString('[source_kind] = %s AND [docState] = %i', (string) $captured[0]);
        $this->assertSame(['workOrder', 10], array_slice($captured, 1));

        $this->assertSame('1 koncept z periodické fakturace ke kontrole', (new InvoicesToReviewCheck($this->db([['doc_type' => 'invno', 'n' => 1]]), $this->config(), 'cs'))->withSources($this->sources())->run()[0]->title);
        $this->assertSame('5 konceptů z periodické fakturace ke kontrole', (new InvoicesToReviewCheck($this->db([['doc_type' => 'invno', 'n' => 5]]), $this->config(), 'cs'))->withSources($this->sources())->run()[0]->title);
        $this->assertSame('2 periodic invoicing drafts to review', (new InvoicesToReviewCheck($this->db([['doc_type' => 'invno', 'n' => 2]]), $this->config(), 'en'))->withSources($this->sources())->run()[0]->title);
        $this->assertSame([], (new InvoicesToReviewCheck($this->db([]), $this->config(), 'cs'))->withSources($this->sources())->run());
    }

    // --- per záznam zdroje -----------------------------------------------------

    /** @return list<array<string, mixed>> */
    private function periodRows(): array
    {
        return [
            ['id' => 2, 'work_order' => 6, 'period_from' => '2026-09-01', 'period_to' => '2026-09-30', 'state' => 'planned', 'result' => 'no_rows', 'message' => 'K datu 2026-09-01 nemá zakázka žádný platný řádek předpisu.', 'waiting_since' => null, 'number' => 'S260001', 'title' => 'Nájem'],
            ['id' => 1, 'work_order' => 6, 'period_from' => '2026-08-01', 'period_to' => '2026-08-31', 'state' => 'planned', 'result' => 'failed', 'message' => 'number_series_not_found', 'waiting_since' => null, 'number' => 'S260001', 'title' => 'Nájem'],
            ['id' => 9, 'work_order' => 7, 'period_from' => '2026-10-01', 'period_to' => '2026-10-31', 'state' => 'planned', 'result' => 'failed', 'message' => 'validation_failed', 'waiting_since' => null, 'number' => null, 'title' => 'Druhá'],
        ];
    }

    public function testPeriodFailedGroupsPerWorkOrderWithLatestReason(): void
    {
        $captured = null;
        $findings = (new PeriodFailedCheck($this->db($this->periodRows(), $captured), $this->config(), 'cs'))->withSources($this->sources())->run();

        $this->assertCount(2, $findings);
        $this->assertSame('workOrders:6', $findings[0]->findingKey, 'klíč nálezu = zdroj:id (#175 D6)');
        $this->assertSame('warning', $findings[0]->severity);
        $this->assertSame(460, $findings[0]->subjectTableId);
        $this->assertSame(6, $findings[0]->subjectRowId);
        $this->assertSame('Zakázka S260001 Nájem: 2 období se nepodařila vystavit', $findings[0]->title);
        $this->assertSame('Období: 01.09.2026 – 30.09.2026, 01.08.2026 – 31.08.2026 — K datu 2026-09-01 nemá zakázka žádný platný řádek předpisu.', $findings[0]->message);
        $this->assertSame(['viewerId' => 'economy.workOrders.heads', 'recordId' => 6], $findings[0]->actions[0]['target']);
        $this->assertSame(['periods' => ['2026-09-01', '2026-08-01'], 'navSection' => 'workOrders'], $findings[0]->context);
        $this->assertSame('Otevřít zakázku', $findings[0]->actions[0]['label']);
        $this->assertSame('Zakázka Druhá: 1 období se nepodařilo vystavit', $findings[1]->title);

        $this->assertStringContainsString('[p].[state] IN %in', (string) $captured[0]);
        $this->assertStringContainsString('[p].[result] IN %in', (string) $captured[0]);
        $this->assertSame([['planned'], PeriodFailedCheck::RESULTS], array_slice($captured, 1));
    }

    public function testCatchupBlockedReportsOldestDuePeriod(): void
    {
        $captured = null;
        $rows = [];
        foreach (['2026-10-01', '2026-09-01', '2026-08-01', '2026-07-01'] as $i => $from) {
            $rows[] = ['id' => $i + 1, 'work_order' => 6, 'period_from' => $from, 'period_to' => $from, 'state' => 'planned', 'result' => 'catchup', 'message' => 'x', 'waiting_since' => null, 'number' => 'S260001', 'title' => 'Nájem'];
        }
        $findings = (new CatchupBlockedCheck($this->db($rows, $captured), $this->config(), 'cs'))->withSources($this->sources())->run();

        $this->assertCount(1, $findings);
        $this->assertSame('Zakázka S260001 Nájem: 4 dlužná období čeká na vystavení', $findings[0]->title);
        $this->assertStringContainsString('od 01.07.2026', $findings[0]->message);
        $this->assertStringContainsString('Vystavit dlužná období', $findings[0]->message);
        $this->assertSame([['planned'], ['catchup']], array_slice($captured, 1));
    }

    public function testPeriodWaitingUsesWaitDaysSettingAndDistinguishesEditedDrafts(): void
    {
        $captured = null;
        $rows = [
            ['id' => 3, 'work_order' => 6, 'period_from' => '2026-10-01', 'period_to' => '2026-10-31', 'state' => 'waiting', 'result' => 'waiting', 'message' => 'čekám', 'waiting_since' => '2026-09-20 03:17:00', 'number' => 'S260001', 'title' => 'Nájem'],
            ['id' => 2, 'work_order' => 6, 'period_from' => '2026-09-01', 'period_to' => '2026-09-30', 'state' => 'waiting', 'result' => 'edited', 'message' => 'upraveno', 'waiting_since' => '2026-10-05 03:17:00', 'number' => 'S260001', 'title' => 'Nájem'],
        ];
        $check = new class($this->db($rows, $captured), $this->config(), 'cs') extends PeriodWaitingCheck {
            protected function today(): string
            {
                return '2026-10-08';
            }

            protected function waitDays(): int
            {
                return 7;
            }
        };
        $findings = $check->withSources($this->sources())->run();

        $this->assertCount(1, $findings);
        $this->assertSame('Zakázka S260001 Nájem: období čeká na podklady', $findings[0]->title);
        $this->assertSame(
            'Čeká na podklady déle než 7 dní: 01.10.2026 – 31.10.2026; podklady jsou k dispozici, ale koncept byl ručně upraven — použij Přegenerovat: 01.09.2026 – 30.09.2026',
            $findings[0]->message,
        );
        $this->assertStringContainsString('([p].[result] IN %in OR [p].[waiting_since] < %s)', (string) $captured[0]);
        $this->assertSame([['waiting'], ['edited'], '2026-10-01 00:00:00'], array_slice($captured, 1));
    }

    public function testWaitDaysComeFromSettingsWithDefault(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchSingle')->willReturn('"21"');
        $check = new class($db, $this->config(), 'cs') extends PeriodWaitingCheck {
            public function days(): int
            {
                return $this->waitDays();
            }
        };
        $this->assertSame(21, $check->days());

        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchSingle')->willReturn(null);
        $check = new class($db, $this->config(), 'cs') extends PeriodWaitingCheck {
            public function days(): int
            {
                return $this->waitDays();
            }
        };
        $this->assertSame(PeriodWaitingCheck::DEFAULT_WAIT_DAYS, $check->days());
    }

    // --- víc zdrojů (#175 D6, U4) ----------------------------------------------

    public function testOneCheckCoversAllSourcesWithSourceScopedFindings(): void
    {
        // Druhý zdroj se záznamem téhož id: vlastní evidence období, vlastní
        // sloupec záznamu, viewer, sekce i pojmenování.
        $contracts = new FakeInvoicingSource(
            id: 'contracts',
            table: 'test_contracts_heads',
            periodsTable: 'test_contracts_periods',
            recordColumn: 'contract',
            viewerId: 'test.contracts.heads',
            navSection: 'buildings',
            noun: 'Smlouva',
            openLabel: 'Otevřít smlouvu',
            tableId: 9002,
        );
        $contracts->records = [['id' => 6, 'number' => 'N-1', 'title' => 'Byt 12']];

        $queries = [];
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(function (string $sql) use (&$queries): array {
            $queries[] = $sql;
            return str_contains($sql, '[test_contracts_periods]')
                ? [['id' => 1, 'contract' => 6, 'period_from' => '2026-10-01', 'period_to' => '2026-10-31', 'state' => 'planned', 'result' => 'no_customer', 'message' => 'Předpis nemá zákazníka, nebo zákazník neexistuje.', 'waiting_since' => null]]
                : [['id' => 1, 'work_order' => 6, 'period_from' => '2026-09-01', 'period_to' => '2026-09-30', 'state' => 'planned', 'result' => 'failed', 'message' => 'x', 'waiting_since' => null]];
        });

        $findings = (new PeriodFailedCheck($db, $this->config(), 'cs'))
            ->withSources(new InvoicingSources([$this->workOrders(), $contracts]))
            ->run();

        $this->assertCount(2, $queries);
        $this->assertStringContainsString('FROM [economy_work_orders_periods] [p]', $queries[0]);
        $this->assertStringContainsString('ORDER BY [p].[work_order], [p].[period_from] DESC', $queries[0]);
        $this->assertStringContainsString('SELECT [p].[id], [p].[contract],', $queries[1]);
        $this->assertStringContainsString('FROM [test_contracts_periods] [p]', $queries[1]);

        $this->assertSame(['workOrders:6', 'contracts:6'], array_column($findings, 'findingKey'));
        [$workOrder, $contract] = $findings;
        $this->assertSame([460, 6], [$workOrder->subjectTableId, $workOrder->subjectRowId]);
        $this->assertSame([9002, 6], [$contract->subjectTableId, $contract->subjectRowId]);
        $this->assertSame('Smlouva N-1 Byt 12: 1 období se nepodařilo vystavit', $contract->title);
        $this->assertSame('Otevřít smlouvu', $contract->actions[0]['label']);
        $this->assertSame(['viewerId' => 'test.contracts.heads', 'recordId' => 6], $contract->actions[0]['target']);
        $this->assertSame('buildings', $contract->context['navSection']);
        $this->assertSame('workOrders', $workOrder->context['navSection']);
    }

    public function testPeriodOfUnknownRecordIsNotReported(): void
    {
        // Období smazaného záznamu (zdroj ho nezná) nález nedává.
        $rows = [['id' => 1, 'work_order' => 99, 'period_from' => '2026-09-01', 'period_to' => '2026-09-30', 'state' => 'planned', 'result' => 'failed', 'message' => 'x', 'waiting_since' => null]];
        $this->assertSame([], (new PeriodFailedCheck($this->db($rows), $this->config(), 'cs'))->withSources($this->sources())->run());
    }

    public function testNoRegisteredSourceGivesNoFindings(): void
    {
        $this->assertSame([], (new PeriodFailedCheck($this->db($this->periodRows()), $this->config(), 'cs'))->withSources(InvoicingSources::empty())->run());
    }

    public function testSummaryFindingHasSectionOnlyWhenAllSourcesShareIt(): void
    {
        $rows = [['doc_type' => 'invno', 'n' => 2]];
        $other = new FakeInvoicingSource(id: 'contracts', table: 'test_contracts_heads', navSection: 'buildings');
        $same = new FakeInvoicingSource(id: 'leases', table: 'test_leases_heads', navSection: 'workOrders');
        $none = new FakeInvoicingSource(id: 'plain', table: 'test_plain_heads', navSection: null);

        $context = fn(InvoicingSources $sources): array => (new InvoicesToReviewCheck($this->db($rows), $this->config(), 'cs'))->withSources($sources)->run()[0]->context;

        $this->assertSame('workOrders', $context(new InvoicingSources([$this->workOrders(), $same]))['navSection']);
        $this->assertArrayNotHasKey('navSection', $context(new InvoicingSources([$this->workOrders(), $other])));
        $this->assertArrayNotHasKey('navSection', $context(new InvoicingSources([$this->workOrders(), $none])));
        $this->assertArrayNotHasKey('navSection', $context(InvoicingSources::empty()));
    }

    public function testSourcesComeFromConfigurationByDefault(): void
    {
        // Bez registrace zdrojů v konfiguraci (cfgItem chybí) check nic nehlásí.
        $this->assertSame([], (new PeriodFailedCheck($this->db($this->periodRows()), $this->config(), 'cs'))->run());
    }
}

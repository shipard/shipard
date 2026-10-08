<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\WorkOrders;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Economy\WorkOrders\Invoicing\PeriodRepository;
use Shipard\Module\Economy\WorkOrders\WorkOrderJournalService;
use Shipard\Module\Economy\WorkOrders\WorkOrdersViewer;
use Shipard\Module\Economy\WorkOrders\WorkOrderTreeService;
use Shipard\Module\Economy\WorkOrders\WorkOrderTypes;

/**
 * Viewer zakázek: skupiny stavů z vlastní sady, spodní taby = řady
 * s předvyplněním řady (P6), filtry druh / typ, hledání, řádek a Přehled.
 */
class WorkOrdersViewerTest extends TestCase
{
    private const MODULE = __DIR__ . '/../../../../../modules/economy/workOrders';

    private const TYPES = [
        'project'  => ['name' => 'Externí jednorázová', 'external' => true, 'oneOff' => true, 'invoicing' => null],
        'periodic' => ['name' => 'Periodická', 'external' => true, 'oneOff' => false, 'invoicing' => 'periodic'],
    ];

    /** @param list<list<mixed>> $queries */
    /**
     * @param list<array<string, mixed>> $children podzakázky z WorkOrderTreeService
     * @param array<string, mixed>|null $customer efektivní zákazník z WorkOrderTreeService
     */
    private function viewer(
        ?array &$queries = null,
        ?array $detailRow = null,
        array $fetchAllRows = [],
        array $journal = ['years' => [], 'rows' => [], 'more' => false],
        array $children = [],
        ?array $customer = null,
    ): TestableWorkOrdersViewer {
        $queries = [];
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(function (...$args) use (&$queries, $fetchAllRows): array {
            $queries[] = $args;
            return $fetchAllRows;
        });
        $db->method('fetchRow')->willReturn($detailRow);
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([
            [WorkOrderTypes::CFG_ITEM, self::TYPES],
            ['economy.workOrders.docStates', JsoncParser::parseFile(self::MODULE . '/config/docStates.jsonc')],
            ['economy.workOrders.viewerLabels', ['label.kind' => ['name' => 'Druh'], 'group.customer' => ['name' => 'Zákazník']]],
            ['economy.workOrders.periodStates', ['planned' => ['name' => 'Naplánováno'], 'waiting' => ['name' => 'Čeká na podklady'], 'issued' => ['name' => 'Vystaveno'], 'stopped' => ['name' => 'Zastaveno']]],
            ['economy.workOrders.periodicities', ['month' => ['name' => 'Měsíčně']]],
            ['economy.workOrders.invoiceTimings', ['start' => ['name' => 'Na počátku období']]],
            ['docs.core.docTypes', ['invno' => ['name' => 'Faktura vydaná'], 'invpo' => ['name' => 'Zálohová faktura vydaná']]],
        ]);
        $viewer = new TestableWorkOrdersViewer($db, 'economy_work_orders_heads');
        $viewer->setConfig($config);
        $viewer->journal = $journal;
        $viewer->children = $children;
        $viewer->customer = $customer;
        return $viewer;
    }

    public function testViewGroupsComeFromOwnStateSet(): void
    {
        $this->assertSame(['active', 'archive', 'trash'], $this->viewer()->getViewGroups());
    }

    public function testBottomTabsAreConfirmedSeriesWithSeriesDefault(): void
    {
        $viewer = $this->viewer($queries, fetchAllRows: [['id' => 1, 'name' => 'Projekty 2026'], ['id' => 2, 'name' => 'Režie']]);

        $this->assertSame([
            ['id' => 1, 'label' => 'Projekty 2026', 'newRecordDefaults' => ['number_series' => 1]],
            ['id' => 2, 'label' => 'Režie', 'newRecordDefaults' => ['number_series' => 2]],
        ], $viewer->getBottomTabs());
        $this->assertStringContainsString('WHERE s.`docState` = 40', $queries[0][0]);
    }

    public function testSelectRowsAppliesTabKindTypeAndSearch(): void
    {
        $viewer = $this->viewer($queries);

        $viewer->selectRows('hala', [
            ['id' => 'viewGroup', 'value' => 'archive'],
            ['id' => 'bottomTab', 'value' => '1'],
            ['id' => 'kind', 'value' => '11'],
            ['id' => 'type', 'value' => 'project'],
            ['id' => 'type', 'value' => 'bogus'],
        ], 1);

        $sql = $queries[0][0];
        $this->assertStringContainsString('w.`docState` IN (%i, %i)', $sql);
        $this->assertStringContainsString('w.`number_series` = %i', $sql);
        $this->assertStringContainsString('w.`kind` = %i', $sql);
        $this->assertSame(1, substr_count($sql, 'w.`type` = %s'), 'neznámý typ se nefiltruje');
        $this->assertStringContainsString('p.`full_name`', $sql);
        $this->assertStringContainsString('ORDER BY w.`docStateMain` ASC, w.`number` DESC', $sql);
        // archiv = Ukončeno (70) a Zrušeno (30)
        $this->assertSame([70, 30, 1, 11, 'project'], array_slice($queries[0], 1, 5));
    }

    public function testFiltersOfferKindsAndTypes(): void
    {
        $viewer = $this->viewer($queries, fetchAllRows: [['id' => 11, 'name' => 'Projekty']]);
        $filters = $viewer->getFilters();

        $this->assertSame(['kind', 'type'], array_column($filters, 'id'));
        $this->assertSame('Druh', $filters[0]['label']);
        $this->assertSame([['value' => 11, 'label' => 'Projekty']], $filters[0]['options']);
        $this->assertSame(['project', 'periodic'], array_column($filters[1]['options'], 'value'));
    }

    public function testRowAndDetail(): void
    {
        $record = [
            'id' => 5, 'number' => 'Z260007', 'title' => 'Rekonstrukce haly', 'type' => 'project',
            'date_start' => '2026-03-01', 'date_end' => null, 'docState' => 40, 'kind' => 11,
            'kind_name' => 'Projekty', 'customer_name' => 'Alfa s.r.o.', 'series_name' => 'Projekty 2026',
            'currency' => 'czk', 'payment_reference' => '2026007', 'parent' => 2, 'parent_number' => 'Z260002', 'parent_doc_state' => 40,
            'parent_title' => 'Areál', 'cost_center_code' => 'S1', 'cost_center_name' => 'Stavby', 'internal_note' => null,
        ];
        $viewer = $this->viewer($queries, $record);

        $row = $viewer->renderRow($record);
        $this->assertSame('Rekonstrukce haly', $row['t1']);
        $this->assertSame('Z260007', $row['i1']);
        $this->assertSame(
            [
                ['text' => 'Alfa s.r.o.', 'class' => 'primary'],
                ['text' => 'Projekty', 'class' => 'muted'],
                ['text' => '01.03.2026'],
                ['text' => 'Confirmed', 'class' => 'success'],
            ],
            $row['t2'],
        );
        $this->assertSame('done', $row['stateStyle']);

        $detail = $viewer->renderDetail(5);
        // Nadřazená zakázka: vedle Přehledu tabulka stromu s odkazem do detailu.
        $this->assertSame(['overview'], array_column($detail['tabs'], 'id'));
        $this->assertArrayNotHasKey('actions', $detail);
        $blocks = $detail['tabs'][0]['content']['blocks'];
        $groups = $blocks[0]['groups'];
        $this->assertSame(['Identity', 'Zákazník', 'Hierarchy', 'Validity'], array_column($groups, 'title'));
        $this->assertSame(['label' => 'Druh', 'value' => 'Projekty'], $groups[0]['items'][2]);
        $this->assertSame('CZK', $groups[1]['items'][1]['value']);
        $this->assertSame('S1 — Stavby', $groups[2]['items'][0]['value']);
        $this->assertSame('table', $blocks[2]['type']);
        $this->assertSame(
            ['relation' => 'Parent', 'number' => 'Z260002', 'title' => 'Areál', 'customer' => '', 'state' => 'Confirmed',
                '_action' => ['id' => 'openWorkOrder', 'kind' => 'open_detail', 'target' => ['viewerId' => 'economy.workOrders.heads', 'recordId' => 2]]],
            $blocks[2]['rows'][0],
        );
    }

    public function testDetailShowsCustomerFromParentSubOrdersAndJournal(): void
    {
        $record = [
            'id' => 7, 'number' => 'I0007', 'title' => 'Střecha', 'type' => 'internal', 'date_start' => '2026-04-01',
            'date_end' => null, 'docState' => 40, 'kind' => 12, 'kind_name' => 'Interní', 'customer_name' => null,
            'series_name' => 'Interní 2026', 'currency' => null, 'payment_reference' => null, 'parent' => null,
            'cost_center_code' => null, 'cost_center_name' => null, 'internal_note' => 'pozn.',
        ];
        $viewer = $this->viewer(
            $queries,
            $record,
            journal: [
                'years' => [['year' => '2026', 'expenses' => 4200.0, 'revenues' => 0.0, 'otherDr' => 882.0, 'otherCr' => 5082.0]],
                'rows'  => [['docId' => 21, 'docNumber' => '2260021', 'date' => '2026-06-10', 'accountNumber' => '518100', 'text' => 'Servis', 'moneyDr' => 4200.0, 'moneyCr' => 0.0]],
                'more'  => true,
            ],
            children: [['id' => 8, 'number' => null, 'title' => 'Krov', 'type' => 'internal', 'docState' => 10, 'customerName' => null]],
            customer: ['id' => 50, 'name' => 'Alfa s.r.o.', 'from' => ['id' => 1, 'number' => 'Z260001', 'title' => 'Areál']],
        );

        $detail = $viewer->renderDetail(7);

        $this->assertSame(['overview', 'journal'], array_column($detail['tabs'], 'id'));
        $groups = $detail['tabs'][0]['content']['blocks'][0]['groups'];
        $this->assertSame(
            [['label' => 'Customer', 'value' => 'Alfa s.r.o.'], ['label' => 'Customer from', 'value' => 'Z260001 — Areál']],
            $groups[1]['items'],
        );
        $treeRows = $detail['tabs'][0]['content']['blocks'][2]['rows'];
        $this->assertSame(['Sub-order', '—', 'Krov', 8], [$treeRows[0]['relation'], $treeRows[0]['number'], $treeRows[0]['title'], $treeRows[0]['_action']['target']['recordId']]);

        $journal = $detail['tabs'][1]['content']['blocks'];
        $this->assertSame(['year' => '2026', 'expenses' => '4 200,00', 'revenues' => '0,00', 'otherDr' => '882,00', 'otherCr' => '5 082,00'], $journal[0]['rows'][0]);
        $this->assertSame('2260021', $journal[2]['rows'][0]['document']);
        $this->assertSame(['viewerId' => 'docs.core.heads', 'recordId' => 21], $journal[2]['rows'][0]['_action']['target']);
        $this->assertStringContainsString('200', $journal[3]['text']);
        $this->assertSame(
            [['id' => 'openJournal', 'label' => 'Open in journal', 'kind' => 'open_viewer', 'variant' => 'secondary',
                'target' => ['viewerId' => 'economy.accounting.journal', 'filters' => ['fiscal_year' => '', 'dim_workOrder' => '#7']]]],
            $detail['actions'],
        );
    }

    public function testPeriodicDetailHasInvoicingTabWithPeriodsAndActions(): void
    {
        $record = [
            'id' => 6, 'number' => 'S260001', 'title' => 'Nájem kanceláře', 'type' => 'periodic', 'date_start' => '2026-08-01',
            'date_end' => null, 'docState' => 40, 'kind' => 4, 'kind_name' => 'Smlouvy', 'customer_name' => 'MP toner',
            'series_name' => 'Smlouvy', 'currency' => 'czk', 'payment_reference' => '20260001', 'parent' => null,
            'cost_center_code' => 'S01', 'cost_center_name' => 'Správa', 'internal_note' => null,
            'inv_periodicity' => 'month', 'inv_from' => '2026-08-01', 'inv_doc_type' => null, 'inv_number_series' => null,
            'inv_due_days' => null, 'inv_timing' => null, 'inv_vat_mode' => null, 'inv_payment_method' => null, 'inv_bank_account' => null,
        ];
        $viewer = $this->viewer($queries, $record);
        $viewer->kind = ['id' => 4, 'type' => 'periodic', 'inv_doc_type' => 'invno', 'inv_number_series' => 1, 'inv_timing' => 'start'];
        $viewer->today = '2026-10-08';
        $viewer->periods = [
            ['id' => 3, 'work_order' => 6, 'period_from' => '2026-10-01', 'period_to' => '2026-10-31', 'state' => 'issued', 'doc' => 605, 'result' => null, 'message' => null],
            ['id' => 2, 'work_order' => 6, 'period_from' => '2026-09-01', 'period_to' => '2026-09-30', 'state' => 'issued', 'doc' => 604, 'result' => null, 'message' => null],
            ['id' => 1, 'work_order' => 6, 'period_from' => '2026-08-01', 'period_to' => '2026-08-31', 'state' => 'planned', 'doc' => null, 'result' => 'failed', 'message' => 'Chybí řada'],
        ];
        $viewer->docs = [
            605 => ['id' => 605, 'docState' => 10, 'doc_type' => 'invno', 'doc_number' => '!0000000605', 'total_amount' => '17545.00', 'doc_currency' => 'czk'],
            604 => ['id' => 604, 'docState' => 90, 'doc_type' => 'invpo', 'doc_number' => null, 'total_amount' => '16940.00', 'doc_currency' => 'czk'],
        ];

        $detail = $viewer->renderDetail(6);

        $this->assertSame(['overview', 'invoicing'], array_column($detail['tabs'], 'id'));
        $tab = $detail['tabs'][1];
        $this->assertSame('Invoicing', $tab['label']);
        $blocks = $tab['content']['blocks'];
        $items = $blocks[0]['groups'][0]['items'];
        $this->assertSame(['label' => 'Next period due', 'value' => '01.11.2026 – 30.11.2026'], $items[0]);
        $this->assertSame(['label' => 'Periodicity', 'value' => 'Měsíčně'], $items[1]);
        $this->assertSame(['label' => 'Document type', 'value' => 'Faktura vydaná'], $items[4]);

        $rows = $blocks[2]['rows'];
        $this->assertSame(['period', 'state', 'document', 'amount', 'message'], array_column($blocks[2]['columns'], 'id'));
        $this->assertSame('01.10.2026 – 31.10.2026', $rows[0]['period']);
        $this->assertSame('Vystaveno', $rows[0]['state']);
        $this->assertSame('draft #605', $rows[0]['document']);
        $this->assertSame('17 545,00 CZK', $rows[0]['amount']);
        $this->assertSame(['viewerId' => 'docs.invoicesOut.heads', 'recordId' => 605], $rows[0]['_action']['target']);
        // Září: doklad v koši = zastaveno, odkaz na viewer zálohových faktur.
        $this->assertSame('Zastaveno', $rows[1]['state']);
        $this->assertSame('docs.proformasOut.heads', $rows[1]['_action']['target']['viewerId']);
        // Srpen: bez dokladu, se zprávou.
        $this->assertSame('Naplánováno', $rows[2]['state']);
        $this->assertSame('', $rows[2]['document']);
        $this->assertArrayNotHasKey('_action', $rows[2]);
        $this->assertSame('Chybí řada', $rows[2]['message']);

        $actions = $detail['actions'];
        $this->assertSame(['workOrderIssueDue', 'workOrderRegenerate', 'workOrderRestore'], array_column($actions, 'id'));
        $this->assertSame('button', $actions[0]['kind']);
        $this->assertSame('dropdown', $actions[1]['kind']);
        $this->assertSame([['label' => '01.10.2026 – 31.10.2026 — draft #605', 'value' => '3']], $actions[1]['items']);
        $this->assertSame([['label' => '01.09.2026 – 30.09.2026', 'value' => '2']], $actions[2]['items']);

        // Koncept zakázky bez období: bez akcí, místo tabulky jen text.
        $draftViewer = $this->viewer($q2, ['docState' => 10] + $record);
        $draftViewer->kind = $viewer->kind;
        $draft = $draftViewer->renderDetail(6);
        $this->assertArrayNotHasKey('actions', $draft);
        $this->assertSame('No period has been issued yet.', $draft['tabs'][1]['content']['blocks'][2]['text']);
    }
}

class TestableWorkOrdersViewer extends WorkOrdersViewer
{
    /** @var array{years: list<mixed>, rows: list<mixed>, more: bool} */
    public array $journal = ['years' => [], 'rows' => [], 'more' => false];
    /** @var list<array<string, mixed>> */
    public array $children = [];
    /** @var array<string, mixed>|null */
    public ?array $customer = null;
    /** @var array<string, mixed>|null druh zakázky (inv_*) */
    public ?array $kind = null;
    /** @var list<array<string, mixed>> období (nejnovější nahoře) */
    public array $periods = [];
    /** @var array<int, array<string, mixed>> doklady období podle id */
    public array $docs = [];
    public string $today = '2026-10-08';

    protected function periodRepository(): PeriodRepository
    {
        $viewer = $this;
        return new class($viewer) extends PeriodRepository {
            public function __construct(private readonly TestableWorkOrdersViewer $viewer)
            {
            }

            public function listFor(int $workOrderId): array
            {
                return $this->viewer->periods;
            }

            public function docInfo(array $docIds): array
            {
                return array_intersect_key($this->viewer->docs, array_flip(array_map('intval', $docIds)));
            }
        };
    }

    protected function kindRow(int $kindId): ?array
    {
        return $this->kind;
    }

    protected function today(): string
    {
        return $this->today;
    }

    protected function treeService(): WorkOrderTreeService
    {
        $viewer = $this;
        return new class($viewer) extends WorkOrderTreeService {
            public function __construct(private readonly TestableWorkOrdersViewer $viewer)
            {
                parent::__construct(null, new WorkOrderTypes(null));
            }

            public function children(int $id): array
            {
                return $this->viewer->children;
            }

            public function effectiveCustomer(array $record): ?array
            {
                return $this->viewer->customer;
            }
        };
    }

    protected function journalService(): WorkOrderJournalService
    {
        $viewer = $this;
        return new class($viewer) extends WorkOrderJournalService {
            public function __construct(private readonly TestableWorkOrdersViewer $viewer)
            {
                parent::__construct(null);
            }

            public function overview(int $workOrderId): array
            {
                return $this->viewer->journal;
            }
        };
    }
}

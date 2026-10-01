<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Assets;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Economy\Assets\AssetAcquisitionService;
use Shipard\Module\Economy\Assets\AssetJournalCheck;
use Shipard\Module\Economy\Assets\AssetJournalService;
use Shipard\Module\Economy\Assets\AssetPlanService;
use Shipard\Module\Economy\Assets\AssetsViewer;

/**
 * Spodní taby vieweru karet (druhy z cfgItem + Cizí) a jejich promítnutí
 * do dotazu; filtry Typ / Účetní skupina.
 */
class AssetsViewerTest extends TestCase
{
    private const CATEGORIES = [
        'small'    => ['name' => 'Drobný majetek', 'longTerm' => false],
        'tangible' => ['name' => 'Dlouhodobý hmotný', 'longTerm' => true, 'depreciable' => true],
    ];

    private const LABELS = [
        'tab.foreign'       => ['name' => 'Cizí'],
        'tab.taxPlan'       => ['name' => 'Daňové odpisy'],
        'action.activate'   => ['name' => 'Zařadit'],
        'action.depreciate' => ['name' => 'Odepsat'],
        'action.disposal'   => ['name' => 'Vyřadit'],
    ];

    /** @param list<mixed>|null $captured */
    private function viewer(?array &$captured = null): AssetsViewer
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            function (...$args) use (&$captured): array {
                $captured = $args;
                return [];
            },
        );
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([
            ['economy.assets.categories', self::CATEGORIES],
            ['economy.assets.viewerLabels', self::LABELS],
            ['core.system.docStatesArchive', [
                '10' => ['stateName' => 'Koncept', 'stateStyle' => 'concept', 'mainState' => 1, 'viewGroup' => 'active', 'goto' => [40]],
                '40' => ['stateName' => 'V pořádku', 'stateStyle' => 'done', 'mainState' => 3, 'viewGroup' => 'active', 'goto' => [70]],
                '70' => ['stateName' => 'V archívu', 'stateStyle' => 'archive', 'mainState' => 4, 'viewGroup' => 'archive', 'goto' => []],
            ]],
        ]);

        $viewer = new AssetsViewer($db, 'economy_assets_assets');
        $viewer->setConfig($config);
        return $viewer;
    }

    /**
     * Viewer s detailem nad TestAssetPlanService (karta i události v paměti).
     *
     * @param array<int, array{docId: int, docNumber: string}> $posting zaúčtované události
     * @param list<array<string, mixed>> $acquisitionRows řádky pořízení z dokladů
     * @param array{rows: list<array<string, mixed>>, years: list<array<string, mixed>>} $journal řádky a roční obraty deníku
     */
    private function detailViewer(
        array $card,
        array $events,
        array $posting = [],
        array $acquisitionRows = [],
        array $journal = ['rows' => [], 'years' => []],
        ?AssetJournalCheck $journalCheck = null,
    ): AssetsViewer {
        $service = new TestAssetPlanService();
        $service->cards[(int) $card['id']] = $card;
        $service->events = $events;
        $service->posting = $posting;

        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn($card);
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([
            ['economy.assets.categories', self::CATEGORIES],
            ['economy.assets.viewerLabels', self::LABELS],
            ['economy.assets.eventKinds', ['activation' => ['name' => 'Zařazení'], 'depreciation' => ['name' => 'Odpis']]],
            ['world.assets.cz', TestAssetPlanService::config()->cfgItem('world.assets.cz')],
        ]);

        $acquisition = new class($acquisitionRows) extends AssetAcquisitionService {
            /** @param list<array<string, mixed>> $rows */
            public function __construct(private readonly array $rows)
            {
                parent::__construct(null);
            }

            protected function loadRows(int $assetId): array
            {
                return $this->rows;
            }
        };

        $journalService = new class($journal) extends AssetJournalService {
            /** @param array{rows: list<array<string, mixed>>, years: list<array<string, mixed>>} $journal */
            public function __construct(private readonly array $journal)
            {
                parent::__construct(null);
            }

            protected function loadRows(int $assetId, int $limit): array
            {
                return array_slice($this->journal['rows'], 0, $limit);
            }

            protected function loadYearTotals(int $assetId): array
            {
                return $this->journal['years'];
            }
        };

        $journalCheck ??= new TestAssetJournalCheck();
        $viewer = new class($db, 'economy_assets_assets', $service, $acquisition, $journalService, $journalCheck) extends AssetsViewer {
            public function __construct(
                DataSourceConnection $db,
                string $table,
                private readonly AssetPlanService $service,
                private readonly AssetAcquisitionService $acquisition,
                private readonly AssetJournalService $journalService,
                private readonly AssetJournalCheck $journalCheck,
            ) {
                parent::__construct($db, $table);
            }

            protected function journalCheck(): AssetJournalCheck
            {
                return $this->journalCheck;
            }

            protected function journalService(): AssetJournalService
            {
                return $this->journalService;
            }

            protected function planService(): AssetPlanService
            {
                return $this->service;
            }

            protected function acquisitionService(): AssetAcquisitionService
            {
                return $this->acquisition;
            }
        };
        $viewer->setConfig($config);
        return $viewer;
    }

    // --- detail: plán a akce (fáze 2b) ------------------------------------------

    public function testDetailOfActivatedCardHasPlanTabsAndEventActions(): void
    {
        $card = ['id' => 4, 'name' => 'Soustruh', 'category' => 'tangible', 'tax_method' => 'straight', 'tax_rule' => 'cz-2',
            'acc_method' => 'as_tax', 'docState' => 40, 'is_foreign' => 0, 'tracking' => 'single'];
        $events = [['id' => 1, 'asset' => 4, 'event_kind' => 'activation', 'scope' => 'both', 'event_date' => '2022-03-15', 'amount' => 100000, 'docState' => 40]];
        $detail = $this->detailViewer($card, $events)->renderDetail(4);

        $this->assertSame(['overview', 'taxPlan', 'accPlan'], array_column($detail['tabs'], 'id'));
        $this->assertSame('Daňové odpisy', $detail['tabs'][1]['label']);
        $blocks = $detail['tabs'][1]['content']['blocks'];
        $this->assertSame('composite', $detail['tabs'][1]['content']['type']);
        $this->assertSame('properties', $blocks[0]['type']);
        $this->assertSame('table', $blocks[1]['type']);
        $this->assertSame('Zařazení', $blocks[1]['rows'][0]['kind']);
        $this->assertSame('muted', $blocks[1]['rows'][1]['_class']);
        $this->assertSame('11 000,00', $blocks[1]['rows'][1]['amount']);

        $actions = $detail['actions'];
        $this->assertSame(['depreciation_run', 'improvement', 'reduction', 'interruption', 'disposal'], array_column($actions, 'id'));
        $this->assertSame(['assetId' => 4], $actions[0]['target']);
        $this->assertSame('open_form', $actions[4]['kind']);
        $this->assertSame(['asset' => 4, 'event_kind' => 'disposal', 'scope' => 'both'], $actions[4]['target']['preset']);
    }

    // --- detail: pořízení z dokladů (fáze 4, D63) -------------------------------

    /** @return list<array<string, mixed>> */
    private function acquisitionRows(): array
    {
        return [
            ['id' => 1, 'doc_head' => 10, 'doc_number' => '2260010', 'accounting_date' => '2026-03-05',
                'description' => 'Soustruh', 'account_number' => '042100', 'vat_base_dom' => 80000.0],
            ['id' => 2, 'doc_head' => 11, 'doc_number' => '2260011', 'accounting_date' => '2026-04-20',
                'description' => 'Montáž', 'account_number' => '042100', 'vat_base_dom' => 12500.0],
        ];
    }

    public function testOverviewShowsAcquisitionWithDocumentLinksAndTotal(): void
    {
        $card = ['id' => 4, 'name' => 'Soustruh', 'category' => 'tangible', 'tax_method' => 'straight', 'tax_rule' => 'cz-2',
            'acc_method' => 'as_tax', 'docState' => 40, 'is_foreign' => 0, 'tracking' => 'single'];
        $detail = $this->detailViewer($card, [], [], $this->acquisitionRows())->renderDetail(4);

        $overview = $detail['tabs'][0]['content'];
        $this->assertSame('composite', $overview['type']);
        $this->assertSame(['properties', 'heading', 'table'], array_column($overview['blocks'], 'type'));
        $table = $overview['blocks'][2];
        $this->assertTrue($table['columns'][0]['link']);
        $this->assertSame('2260010', $table['rows'][0]['document']);
        $this->assertSame('05.03.2026', $table['rows'][0]['date']);
        $this->assertSame('042100', $table['rows'][0]['account']);
        $this->assertSame('80 000,00', $table['rows'][0]['amount']);
        $this->assertSame(
            ['id' => 'openDocument', 'kind' => 'open_detail', 'target' => ['viewerId' => 'docs.core.heads', 'recordId' => 10]],
            $table['rows'][0]['_action'],
        );
        $this->assertSame(['document' => 'Total', 'amount' => '92 500,00', '_class' => 'total'], $table['rows'][2]);
    }

    public function testActivateIsPrefilledFromAcquisition(): void
    {
        // Nezařazená karta s pořízením na 04x: Zařadit předvyplní součet
        // základů a datum posledního dokladu; počáteční stavy beze změny.
        $card = ['id' => 4, 'name' => 'Soustruh', 'category' => 'tangible', 'tax_method' => 'straight', 'tax_rule' => 'cz-2',
            'acc_method' => 'as_tax', 'docState' => 40, 'is_foreign' => 0, 'tracking' => 'single'];
        $actions = array_column(
            $this->detailViewer($card, [], [], $this->acquisitionRows())->renderDetail(4)['actions'],
            null,
            'id',
        );

        $this->assertSame(
            ['asset' => 4, 'event_kind' => 'activation', 'scope' => 'both', 'amount' => 92500.0, 'event_date' => '2026-04-20'],
            $actions['activate']['target']['preset'],
        );
        $this->assertSame(['asset' => 4, 'event_kind' => 'opening', 'scope' => 'tax'], $actions['openingTax']['target']['preset']);
    }

    // --- detail: kontrola proti deníku (D67) ------------------------------------

    public function testOverviewWarnsAboutJournalAndAcquisitionMismatch(): void
    {
        $card = ['id' => 4, 'name' => 'Fréza', 'category' => 'tangible', 'tax_method' => 'straight', 'tax_rule' => 'cz-2',
            'acc_method' => 'as_tax', 'docState' => 40, 'is_foreign' => 0, 'tracking' => 'single'];
        $events = [['id' => 1, 'asset' => 4, 'event_kind' => 'activation', 'scope' => 'both', 'event_date' => '2022-03-15', 'amount' => 268500, 'docState' => 40]];

        $check = new TestAssetJournalCheck();
        $check->card(4);
        // (b) pořízení 269 500 ≠ zařazení 268 500; (a) zaúčtované zařazení v deníku chybí.
        $check->journal(4, '042100', 269500, 0, '2022-03-10', 'purchase.asset');
        $check->event(4, 'activation', '2022-03-15', 268500);

        $content = $this->detailViewer($card, $events, journalCheck: $check)->renderDetail(4)['tabs'][0]['content'];

        $this->assertSame('composite', $content['type']);
        // Varování stojí před vlastnostmi karty.
        $this->assertSame(['heading', 'table', 'properties'], array_column($content['blocks'], 'type'));
        $this->assertSame('Journal check', $content['blocks'][0]['text']);
        $table = $content['blocks'][1];
        $this->assertSame([['id' => 'text', 'label' => 'Mismatch'], ['id' => 'report', 'label' => '', 'link' => true]], $table['columns']);
        $this->assertCount(2, $table['rows']);
        $this->assertStringContainsString('022100, 042100', $table['rows'][0]['text']);
        $this->assertStringContainsString('269 500,00', $table['rows'][1]['text']);
        $this->assertStringContainsString('268 500,00', $table['rows'][1]['text']);
        $this->assertStringContainsString('1 000,00', $table['rows'][1]['text']);
        foreach ($table['rows'] as $row) {
            $this->assertSame('error', $row['_class']);
            $this->assertSame('Open the check', $row['report']);
            // Report Kontrola za účetní rok, do kterého patří dnešek (TestAssetPlanService: 30. 6. 2024).
            $this->assertSame(
                [
                    'id'     => 'openJournalCheck',
                    'kind'   => 'open_report',
                    'target' => [
                        'reportId' => 'economy.assets.journalCheck',
                        'params'   => ['fiscalYear' => '2024', 'monthFrom' => 1, 'monthTo' => 12],
                    ],
                ],
                $row['_action'],
            );
        }
    }

    public function testAcquisitionWithoutActivationWarnsOnlyAfterThirtyDays(): void
    {
        $card = ['id' => 4, 'name' => 'Fréza', 'category' => 'tangible', 'docState' => 40, 'is_foreign' => 0, 'tracking' => 'single'];

        $recent = new TestAssetJournalCheck();
        $recent->card(4);
        $recent->journal(4, '042100', 50000, 0, '2024-06-20', 'purchase.asset');
        $content = $this->detailViewer($card, [], journalCheck: $recent)->renderDetail(4)['tabs'][0]['content'];
        $this->assertSame('properties', $content['type']);

        $old = new TestAssetJournalCheck();
        $old->card(4);
        $old->journal(4, '042100', 50000, 0, '2024-05-10', 'purchase.asset');
        $content = $this->detailViewer($card, [], journalCheck: $old)->renderDetail(4)['tabs'][0]['content'];
        $this->assertSame('table', $content['blocks'][1]['type']);
        $this->assertStringContainsString('50 000,00', $content['blocks'][1]['rows'][0]['text']);
        $this->assertStringContainsString('30', $content['blocks'][1]['rows'][0]['text']);
    }

    public function testCardWithoutAcquisitionKeepsPlainOverviewAndPreset(): void
    {
        $card = ['id' => 4, 'name' => 'Soustruh', 'category' => 'tangible', 'tax_method' => 'straight', 'tax_rule' => 'cz-2',
            'acc_method' => 'as_tax', 'docState' => 40, 'is_foreign' => 0, 'tracking' => 'single'];
        $detail = $this->detailViewer($card, [])->renderDetail(4);

        $this->assertSame('properties', $detail['tabs'][0]['content']['type']);
        $actions = array_column($detail['actions'], null, 'id');
        $this->assertSame(['asset' => 4, 'event_kind' => 'activation', 'scope' => 'both'], $actions['activate']['target']['preset']);
    }

    public function testSmallAssetShowsExpenseAcquisition(): void
    {
        // Drobný majetek pořízený do nákladů: sekce Pořízení ano, akce žádné.
        $card = ['id' => 2, 'name' => 'Svěrák', 'category' => 'small', 'docState' => 40, 'is_foreign' => 0, 'tracking' => 'single'];
        $rows = [['id' => 3, 'doc_head' => 12, 'doc_number' => '', 'accounting_date' => '2026-05-02',
            'description' => 'Svěrák', 'account_number' => '501201', 'vat_base_dom' => 4990.0]];
        $detail = $this->detailViewer($card, [], [], $rows)->renderDetail(2);

        $table = $detail['tabs'][0]['content']['blocks'][2];
        $this->assertSame('#12', $table['rows'][0]['document'], 'doklad bez čísla');
        $this->assertSame('4 990,00', $table['rows'][1]['amount']);
        $this->assertArrayNotHasKey('actions', $detail);
    }

    // --- detail: náklady a výnosy (fáze 4, D64) ---------------------------------

    /** @return array{rows: list<array<string, mixed>>, years: list<array<string, mixed>>} */
    private function journal(): array
    {
        return [
            'rows' => [
                ['doc_head' => 21, 'doc_number' => '2260021', 'accounting_date' => '2026-06-10', 'account_number' => '518100',
                    'text' => 'Servis', 'money_dr' => 4200.0, 'money_cr' => 0.0],
                ['doc_head' => 0, 'doc_number' => '', 'accounting_date' => '2025-11-02', 'account_number' => '602100',
                    'text' => 'Pronájem stroje', 'money_dr' => 0.0, 'money_cr' => 9000.0],
            ],
            'years' => [
                ['year_name' => '2026', 'expenses' => 4200.0, 'revenues' => 0.0, 'other_dr' => 882.0, 'other_cr' => 5082.0],
                ['year_name' => '2025', 'expenses' => 0.0, 'revenues' => 9000.0, 'other_dr' => 0.0, 'other_cr' => 0.0],
            ],
        ];
    }

    public function testExpensesTabShowsYearTotalsRowsAndJournalAction(): void
    {
        $card = ['id' => 4, 'name' => 'Soustruh', 'category' => 'tangible', 'tax_method' => 'straight', 'tax_rule' => 'cz-2',
            'acc_method' => 'as_tax', 'docState' => 40, 'is_foreign' => 0, 'tracking' => 'single'];
        $events = [['id' => 1, 'asset' => 4, 'event_kind' => 'activation', 'scope' => 'both', 'event_date' => '2022-03-15', 'amount' => 100000, 'docState' => 40]];
        $detail = $this->detailViewer($card, $events, [], [], $this->journal())->renderDetail(4);

        // Tab je až za plány odpisů.
        $this->assertSame(['overview', 'taxPlan', 'accPlan', 'expenses'], array_column($detail['tabs'], 'id'));
        $blocks = $detail['tabs'][3]['content']['blocks'];
        $this->assertSame(['table', 'heading', 'table'], array_column($blocks, 'type'));
        $this->assertSame(
            ['year' => '2026', 'expenses' => '4 200,00', 'revenues' => '0,00', 'otherDr' => '882,00', 'otherCr' => '5 082,00'],
            $blocks[0]['rows'][0],
        );
        $this->assertSame('2260021', $blocks[2]['rows'][0]['document']);
        $this->assertSame('4 200,00', $blocks[2]['rows'][0]['moneyDr']);
        $this->assertSame('', $blocks[2]['rows'][0]['moneyCr'], 'nulová strana se nevypisuje');
        $this->assertSame(['viewerId' => 'docs.core.heads', 'recordId' => 21], $blocks[2]['rows'][0]['_action']['target']);
        // Řádek bez dokladu (počáteční stavy) odkaz nemá.
        $this->assertArrayNotHasKey('_action', $blocks[2]['rows'][1]);
        $this->assertTrue($blocks[2]['columns'][1]['link']);

        $action = array_column($detail['actions'], null, 'id')['openJournal'];
        $this->assertSame('open_viewer', $action['kind']);
        $this->assertSame(
            ['viewerId' => 'economy.accounting.journal', 'filters' => ['fiscal_year' => '', 'dim_asset' => '#4']],
            $action['target'],
        );
    }

    public function testCardWithoutJournalRowsHasNoExpensesTab(): void
    {
        $card = ['id' => 4, 'name' => 'Soustruh', 'category' => 'tangible', 'tax_method' => 'straight', 'tax_rule' => 'cz-2',
            'acc_method' => 'as_tax', 'docState' => 40, 'is_foreign' => 0, 'tracking' => 'single'];
        $detail = $this->detailViewer($card, [])->renderDetail(4);

        $this->assertNotContains('expenses', array_column($detail['tabs'], 'id'));
        $this->assertNotContains('openJournal', array_column($detail['actions'], 'id'));
    }

    public function testSmallAssetGetsExpensesTabAndJournalAction(): void
    {
        $card = ['id' => 2, 'name' => 'Svěrák', 'category' => 'small', 'docState' => 40, 'is_foreign' => 0, 'tracking' => 'single'];
        $detail = $this->detailViewer($card, [], [], [], $this->journal())->renderDetail(2);

        $this->assertSame(['overview', 'expenses'], array_column($detail['tabs'], 'id'));
        $this->assertSame(['openJournal'], array_column($detail['actions'], 'id'));
    }

    public function testExpensesTabSaysWhenRowsAreCut(): void
    {
        $journal = $this->journal();
        $journal['rows'] = array_fill(0, AssetJournalService::ROW_LIMIT + 1, $journal['rows'][0]);
        $card = ['id' => 2, 'name' => 'Svěrák', 'category' => 'small', 'docState' => 40, 'is_foreign' => 0, 'tracking' => 'single'];
        $blocks = $this->detailViewer($card, [], [], [], $journal)->renderDetail(2)['tabs'][1]['content']['blocks'];

        $this->assertCount(AssetJournalService::ROW_LIMIT, $blocks[2]['rows']);
        $this->assertSame('heading', $blocks[3]['type']);
        $this->assertStringContainsString('200', $blocks[3]['text']);
    }

    public function testAccountingPlanShowsPostingStateOfConfirmedRows(): void
    {
        // D52: zařazení je zaúčtované dokladem, potvrzený účetní odpis
        // 2022 na zaúčtování čeká; plánované řádky a daňový okruh beze změny.
        $card = ['id' => 4, 'name' => 'Soustruh', 'category' => 'tangible', 'tax_method' => 'straight', 'tax_rule' => 'cz-2',
            'acc_method' => 'as_tax', 'docState' => 40, 'is_foreign' => 0, 'tracking' => 'single'];
        $events = [
            ['id' => 1, 'asset' => 4, 'event_kind' => 'activation', 'scope' => 'both', 'event_date' => '2022-03-15', 'amount' => 100000, 'docState' => 40],
            ['id' => 2, 'asset' => 4, 'event_kind' => 'depreciation', 'scope' => 'acc', 'event_date' => '2022-12-31', 'amount' => 11000,
                'period_begin' => '2022-01-01', 'period_end' => '2022-12-31', 'docState' => 40],
        ];
        $detail = $this->detailViewer($card, $events, [1 => ['docId' => 950, 'docNumber' => '60MA220001']])->renderDetail(4);

        $tabs = array_column($detail['tabs'], null, 'id');
        $acc = array_column($tabs['accPlan']['content']['blocks'][1]['rows'], 'status');
        $this->assertSame('Posted — document 60MA220001', $acc[0]);
        $this->assertSame('Waiting for posting', $acc[1]);
        $this->assertSame('Planned', $acc[2]);

        $tax = array_column($tabs['taxPlan']['content']['blocks'][1]['rows'], 'status');
        $this->assertSame('Confirmed', $tax[0], 'daňový okruh se neúčtuje');
    }

    public function testDetailOfNewCardOffersActivationAndOpeningBalances(): void
    {
        $card = ['id' => 5, 'name' => 'Stroj', 'category' => 'tangible', 'tax_method' => 'straight', 'tax_rule' => 'cz-2',
            'acc_method' => 'as_tax', 'docState' => 40, 'is_foreign' => 0, 'tracking' => 'single'];
        $detail = $this->detailViewer($card, [])->renderDetail(5);

        $this->assertSame(['activate', 'openingTax', 'openingAcc'], array_column($detail['actions'], 'id'));
        $this->assertSame('heading', $detail['tabs'][1]['content']['blocks'][1]['type']);

        // Karta mimo V pořádku ani vyřazená nic nenabízí.
        $detail = $this->detailViewer(['docState' => 10] + $card, [])->renderDetail(5);
        $this->assertSame([], $detail['actions']);
        $detail = $this->detailViewer($card, [
            ['id' => 1, 'asset' => 5, 'event_kind' => 'activation', 'scope' => 'both', 'event_date' => '2022-03-15', 'amount' => 100000, 'docState' => 40],
            ['id' => 2, 'asset' => 5, 'event_kind' => 'disposal', 'scope' => 'both', 'event_date' => '2024-05-10', 'amount' => 0, 'docState' => 40],
        ])->renderDetail(5);
        $this->assertSame([], $detail['actions']);
    }

    public function testBottomTabsComeFromCfgItemPlusForeign(): void
    {
        $tabs = $this->viewer()->getBottomTabs();

        $this->assertSame(['all', 'small', 'tangible', 'foreign'], array_column($tabs, 'id'));
        $this->assertSame('Dlouhodobý hmotný', $tabs[2]['label']);
        $this->assertSame(['category' => 'tangible'], $tabs[2]['newRecordDefaults']);
        $this->assertSame(['is_foreign' => 1], $tabs[3]['newRecordDefaults']);
        $this->assertArrayNotHasKey('newRecordDefaults', $tabs[0]);
    }

    public function testCategoryTabFiltersRows(): void
    {
        $captured = null;
        $this->viewer($captured)->selectRows(null, [['id' => 'bottomTab', 'value' => 'tangible']], 0);

        $this->assertStringContainsString('a.`category` = %s', (string) $captured[0]);
        $this->assertContains('tangible', $captured);
    }

    public function testForeignTabFiltersRows(): void
    {
        $captured = null;
        $this->viewer($captured)->selectRows(null, [['id' => 'bottomTab', 'value' => 'foreign']], 0);

        $this->assertStringContainsString('a.`is_foreign` = 1', (string) $captured[0]);
        $this->assertStringNotContainsString('a.`category` = %s', (string) $captured[0]);
    }

    public function testListSelectsUnpostedFlagForTheBadge(): void
    {
        $captured = null;
        $this->viewer($captured)->selectRows(null, [], 0);

        $sql = (string) $captured[0];
        $this->assertStringContainsString('AS `has_unposted`', $sql);
        $this->assertStringContainsString("ue.`scope` <> 'tax'", $sql);
        $this->assertStringContainsString('uh.`docState` IN (30, 90)', $sql);
        $this->assertStringNotContainsString("'opening'", $sql, 'počáteční stav se neúčtuje');
    }

    public function testUnknownTabAndAllTabDoNotFilter(): void
    {
        foreach (['all', 'leasing'] as $tab) {
            $captured = null;
            $this->viewer($captured)->selectRows(null, [['id' => 'bottomTab', 'value' => $tab]], 0);
            $this->assertStringNotContainsString('a.`category` = %s', (string) $captured[0]);
            $this->assertStringNotContainsString('a.`is_foreign` = 1', (string) $captured[0]);
        }
    }

    public function testTypeAndGroupFiltersApply(): void
    {
        $captured = null;
        $this->viewer($captured)->selectRows(
            'vrt',
            [['id' => 'asset_type', 'value' => '5'], ['id' => 'accounting_group', 'value' => '2'], ['id' => 'viewGroup', 'value' => 'all']],
            0,
        );

        $sql = (string) $captured[0];
        $this->assertStringContainsString('a.`asset_type` = %i', $sql);
        $this->assertStringContainsString('a.`accounting_group` = %i', $sql);
        $this->assertStringContainsString('a.`asset_number`', $sql);
        $this->assertContains(5, $captured);
        $this->assertContains(2, $captured);
        $this->assertContains('vrt', $captured);
    }

    public function testRenderRowShowsNumberCategoryForeignAndPriceOnlyForSmall(): void
    {
        $viewer = $this->viewer();

        $small = $viewer->renderRow([
            'id' => 1, 'asset_number' => 'MA0001', 'name' => 'Vrtačka', 'category' => 'small',
            'is_foreign' => 1, 'owner_name' => 'Půjčovna', 'price' => '4990.00',
            'acquired_date' => '2026-03-01', 'docState' => 40, 'type_name' => 'Nářadí',
        ]);
        $this->assertSame('MA0001', $small['i1']);
        $this->assertSame('Vrtačka', $small['t1']);
        $this->assertSame('done', $small['stateStyle']);
        $this->assertContains(['text' => 'Drobný majetek', 'class' => 'muted'], $small['t2']);
        $this->assertContains(['text' => 'Cizí · Půjčovna', 'class' => 'warning'], $small['t2']);
        $this->assertContains(['text' => '4 990,00', 'class' => 'amount'], $small['i2']);
        $this->assertContains(['text' => '01.03.2026', 'class' => 'muted'], $small['i2']);

        $this->assertNotContains(['text' => 'Not posted', 'class' => 'warning'], $small['t2']);

        $long = $viewer->renderRow([
            'id' => 2, 'asset_number' => null, 'name' => 'Stavba', 'category' => 'tangible',
            'is_foreign' => 0, 'price' => '100.00', 'docState' => 10, 'has_unposted' => 1,
        ]);
        // Karta s potvrzenou nezaúčtovanou událostí účetního okruhu (D52).
        $this->assertContains(['text' => 'Not posted', 'class' => 'warning'], $long['t2']);
        $this->assertNull($long['i1']);
        $this->assertNull($long['i2']);
        $this->assertContains(['text' => 'Dlouhodobý hmotný', 'class' => 'primary'], $long['t2']);
    }
}

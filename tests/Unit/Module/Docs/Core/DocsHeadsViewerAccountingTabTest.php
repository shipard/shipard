<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Docs\Core\DocsHeadsViewer;

/**
 * Tab Zaúčtování v detailu dokladu (Fáze 3 účtování): podmíněné zobrazení
 * (extension sloupec accounting_state + řádky deníku), stavový blok —
 * štítek a hláška s accounting_messages (#137 D30) —, tabulka řádků deníku se Σ řádkem,
 * cizoměnové sloupce a klasifikace chybových řádků.
 */
class DocsHeadsViewerAccountingTabTest extends TestCase
{
    private const DOC_STATES = [
        '10' => ['stateName' => 'Koncept', 'stateStyle' => 'concept', 'mainState' => 1],
        '40' => ['stateName' => 'V pořádku', 'stateStyle' => 'done', 'mainState' => 3],
    ];

    private const DOC_TYPES = [
        'invni' => ['name' => 'Faktura přijatá', 'trade_dir' => 2],
    ];

    private const ACCOUNTING_STATES = [
        '0' => ['name' => 'Neúčtováno'],
        '1' => ['name' => 'Zaúčtováno'],
        '2' => ['name' => 'Chyba účtování'],
    ];

    /** @return array<string, mixed> hlavička dokladu vč. accounting extension sloupců */
    private function baseRecord(array $overrides = []): array
    {
        return array_merge([
            'id'                  => 7,
            'doc_type'            => 'invni',
            'doc_number'          => '!0000000016',
            'doc_text'            => 'testtest',
            'docState'            => 40,
            'partner'             => null,
            'partner_address'     => null,
            'bank_account'        => null,
            'vat_registration'    => null,
            'supplier_snapshot'   => json_encode(['name' => 'Dodavatel s.r.o.']),
            'customer_snapshot'   => json_encode(['name' => 'My a.s.']),
            'issue_date'          => '2026-05-28',
            'due_date'            => '2026-06-11',
            'accounting_date'     => '2026-05-28',
            'vat_duzp'            => '2026-05-28',
            'doc_currency'        => 'czk',
            'home_currency'       => 'czk',
            'exchange_rate'       => null,
            'payment_method'      => null,
            'payment_reference'     => null,
            'specific_symbol'     => null,
            'constant_symbol'     => null,
            'total_base'          => 6000.0,
            'total_vat'           => 1260.0,
            'total_amount'        => 7260.0,
            'total_rounding'      => 0.0,
            'total_base_dom'      => 0.0,
            'total_vat_dom'       => 0.0,
            'total_amount_dom'    => 0.0,
            'partner_name'        => null,
            'accounting_state'    => 0,
            'accounting_messages' => null,
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $record           hlavička dokladu
     * @param list<array<string, mixed>> $journal    řádky economy_accounting_journal
     * @param list<array<string, mixed>> $journalQueries  zachycené dotazy na deník (out)
     */
    private function makeViewer(
        array $record,
        array $journal = [],
        array &$journalQueries = [],
        bool $rowReferenceText = true,
    ): DocsHeadsViewer {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn($record);
        $db->method('fetchAll')->willReturnCallback(
            function (string $sql, ...$params) use ($journal, &$journalQueries): array {
                if (str_contains($sql, 'economy_accounting_journal')) {
                    $journalQueries[] = ['sql' => $sql, 'params' => $params];
                    return $journal;
                }
                return [];
            },
        );

        $labels = ['tabs' => ['accounting' => ['name' => 'Zaúčtování']]];
        if ($rowReferenceText) {
            $labels['texts'] = ['rowReference' => ['name' => '(řádek {n})']];
        }

        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn (string $id): mixed => match ($id) {
                'docs.core.docStates'                     => self::DOC_STATES,
                'docs.core.docTypes'                      => self::DOC_TYPES,
                'economy.accounting.accountingStates'     => self::ACCOUNTING_STATES,
                'economy.accounting.viewerDetailLabels'   => $labels,
                default                                   => null,
            },
        );

        $viewer = new DocsHeadsViewer($db, 'docs_core_heads');
        $viewer->setConfig($config);
        $viewer->setLanguage('cs');
        return $viewer;
    }

    /** Běžný zaúčtovaný doklad: 518/343 proti 321. */
    private function okJournal(): array
    {
        return [
            [
                'account_number' => '518100', 'text' => 'Konzultace',
                'money_dr' => '6000.00', 'money_cr' => '0.00',
                'money_dr_cur' => '0.00', 'money_cr_cur' => '0.00', 'is_error' => 0,
            ],
            [
                'account_number' => '343210', 'text' => 'DPH 21 %',
                'money_dr' => '1260.00', 'money_cr' => '0.00',
                'money_dr_cur' => '0.00', 'money_cr_cur' => '0.00', 'is_error' => 0,
            ],
            [
                'account_number' => '321100', 'text' => 'testtest',
                'money_dr' => '0.00', 'money_cr' => '7260.00',
                'money_dr_cur' => '0.00', 'money_cr_cur' => '0.00', 'is_error' => 0,
            ],
        ];
    }

    /** @return array<string, mixed>|null tab accounting z renderDetail, nebo null */
    private function accountingTab(DocsHeadsViewer $viewer): ?array
    {
        $tabs = $viewer->renderDetail(7)['tabs'];
        foreach ($tabs as $tab) {
            if ($tab['id'] === 'accounting') {
                return $tab;
            }
        }
        return null;
    }

    // ── Podmíněné zobrazení ─────────────────────────────────────────────────

    public function testNoExtensionColumnSkipsTabAndJournalQuery(): void
    {
        // DS bez economy.accounting — SELECT h.* extension sloupce nevrátí.
        $record = $this->baseRecord();
        unset($record['accounting_state'], $record['accounting_messages']);

        $journalQueries = [];
        $viewer = $this->makeViewer($record, journalQueries: $journalQueries);
        $detail = $viewer->renderDetail(7);

        $this->assertCount(1, $detail['tabs'], 'Jen overview tab');
        $this->assertSame([], $journalQueries, 'Na tabulku deníku se nesmí sáhnout');
    }

    public function testStateZeroWithoutJournalRowsHasNoTab(): void
    {
        $viewer = $this->makeViewer($this->baseRecord(['docState' => 10]));
        $this->assertNull($this->accountingTab($viewer));
    }

    public function testStateZeroWithJournalRowsShowsTab(): void
    {
        // Hraniční stav (invariant říká, že nenastává) — řádky mají přednost.
        $viewer = $this->makeViewer($this->baseRecord(), $this->okJournal());
        $this->assertNotNull($this->accountingTab($viewer));
    }

    // ── Zaúčtovaný doklad ───────────────────────────────────────────────────

    public function testAccountedDocumentRendersStatusTableAndTotals(): void
    {
        $viewer = $this->makeViewer(
            $this->baseRecord(['accounting_state' => 1]),
            $this->okJournal(),
        );
        $tab = $this->accountingTab($viewer);

        $this->assertSame('Zaúčtování', $tab['label']);
        $this->assertSame('composite', $tab['content']['type']);

        $this->assertSame(['badges', 'table'], array_column($tab['content']['blocks'], 'type'), 'Bez chyb není hláška');
        [$status, $table] = $tab['content']['blocks'];

        $this->assertSame([['label' => 'Zaúčtováno', 'style' => 'success']], $status['items']);

        $this->assertSame('table', $table['type']);
        $this->assertSame(['account', 'text', 'md', 'dal'], array_column($table['columns'], 'id'));
        $this->assertSame('right', $table['columns'][2]['align']);

        // 3 řádky deníku + Σ
        $this->assertCount(4, $table['rows']);
        $this->assertSame(
            ['account' => '518100', 'text' => 'Konzultace', 'md' => '6 000,00', 'dal' => null],
            $table['rows'][0],
        );
        $this->assertNull($table['rows'][2]['md'], 'Nulová strana zápisu zůstává prázdná');
        $this->assertSame('7 260,00', $table['rows'][2]['dal']);

        $total = $table['rows'][3];
        $this->assertSame('Σ', $total['account']);
        $this->assertSame('total', $total['_class']);
        $this->assertSame('7 260,00', $total['md']);
        $this->assertSame('7 260,00', $total['dal']);
    }

    // ── Chybový doklad ──────────────────────────────────────────────────────

    public function testErrorStateRendersBannerWithMessagesAndRowReference(): void
    {
        $messages = [
            ['code' => 'account_not_found', 'message' => 'Účet 504??? nenalezen', 'rowId' => 12],
            ['code' => 'unbalanced', 'message' => 'MD <> DAL', 'rowId' => null],
        ];
        $journal = [
            [
                'account_number' => '504???', 'text' => 'Zboží',
                'money_dr' => '6000.00', 'money_cr' => '0.00',
                'money_dr_cur' => '0.00', 'money_cr_cur' => '0.00', 'is_error' => 1,
            ],
        ];
        $viewer = $this->makeViewer(
            $this->baseRecord([
                'accounting_state'    => 2,
                'accounting_messages' => json_encode($messages),
            ]),
            $journal,
        );
        $tab = $this->accountingTab($viewer);
        $this->assertSame(['badges', 'notice', 'table'], array_column($tab['content']['blocks'], 'type'));
        [$status, $notice, $table] = $tab['content']['blocks'];

        $this->assertSame([['label' => 'Chyba účtování', 'style' => 'danger']], $status['items']);
        $this->assertSame('danger', $notice['tone']);
        // Čistý text bez escapování; odkaz na řádek z katalogu, bez rowId bez přípony.
        $this->assertSame(['Účet 504??? nenalezen (řádek 12)', 'MD <> DAL'], $notice['items']);

        $this->assertSame('error', $table['rows'][0]['_class']);
        $this->assertSame('504???', $table['rows'][0]['account']);
    }

    public function testErrorStateWithEmptyJournalShowsBannerWithoutTable(): void
    {
        // Např. fiscal_period_missing — deník prázdný, ale stav 2 s message.
        $viewer = $this->makeViewer($this->baseRecord([
            'accounting_state'    => 2,
            'accounting_messages' => json_encode([
                ['code' => 'fiscal_period_missing', 'message' => 'Chybí fiskální období', 'rowId' => null],
            ]),
        ]));
        $tab = $this->accountingTab($viewer);

        $this->assertSame(
            ['badges', 'notice'],
            array_column($tab['content']['blocks'], 'type'),
            'Štítek a hláška, prázdná tabulka se vynechá',
        );
        $this->assertSame(['Chybí fiskální období'], $tab['content']['blocks'][1]['items']);
    }

    public function testRowReferenceFallsBackToEnglishWithoutCatalogText(): void
    {
        // Nezkompilovaná konfigurace bez sekce `texts` — přípona řádku anglicky.
        $viewer = $this->makeViewer($this->baseRecord([
            'accounting_state'    => 2,
            'accounting_messages' => json_encode([
                ['code' => 'account_not_found', 'message' => 'Účet nenalezen', 'rowId' => 3],
            ]),
        ]), rowReferenceText: false);
        $tab = $this->accountingTab($viewer);

        $this->assertSame(['Účet nenalezen (row 3)'], $tab['content']['blocks'][1]['items']);
    }

    // ── Akce Přeúčtovat ─────────────────────────────────────────────────────

    public function testReaccountActionPresentForStateOkDocument(): void
    {
        $viewer = $this->makeViewer(
            $this->baseRecord(['accounting_state' => 1]),
            $this->okJournal(),
        );
        $detail = $viewer->renderDetail(7);

        $this->assertSame([[
            'id'      => 'reaccount',
            'label'   => 'Přeúčtovat',
            'kind'    => 'button',
            'variant' => 'secondary',
        ]], $detail['actions']);
    }

    public function testReaccountActionAvailableEvenForErrorFreeAccounting(): void
    {
        // Přeúčtovat lze i doklad bez chyb účtování (accounting_state 0/1/2
        // nehraje roli) — rozhoduje jen docState 40.
        $viewer = $this->makeViewer($this->baseRecord(['accounting_state' => 0]));
        $this->assertArrayHasKey('actions', $viewer->renderDetail(7));
    }

    public function testNoReaccountActionOutsideStateOk(): void
    {
        $viewer = $this->makeViewer(
            $this->baseRecord(['docState' => 10, 'accounting_state' => 0]),
        );
        $this->assertArrayNotHasKey('actions', $viewer->renderDetail(7));
    }

    public function testNoReaccountActionWithoutAccountingExtension(): void
    {
        $record = $this->baseRecord();
        unset($record['accounting_state'], $record['accounting_messages']);

        $viewer = $this->makeViewer($record);
        $this->assertArrayNotHasKey('actions', $viewer->renderDetail(7));
    }

    // ── Cizí měna ───────────────────────────────────────────────────────────

    public function testForeignCurrencyAddsCurrencyColumnsAndSums(): void
    {
        $journal = [
            [
                'account_number' => '518100', 'text' => 'Služby',
                'money_dr' => '2450.00', 'money_cr' => '0.00',
                'money_dr_cur' => '100.00', 'money_cr_cur' => '0.00', 'is_error' => 0,
            ],
            [
                'account_number' => '321100', 'text' => 'testtest',
                'money_dr' => '0.00', 'money_cr' => '2450.00',
                'money_dr_cur' => '0.00', 'money_cr_cur' => '100.00', 'is_error' => 0,
            ],
        ];
        $viewer = $this->makeViewer(
            $this->baseRecord(['accounting_state' => 1, 'doc_currency' => 'eur']),
            $journal,
        );
        $table = $this->accountingTab($viewer)['content']['blocks'][1];

        $this->assertSame(
            ['account', 'text', 'md', 'dal', 'md_cur', 'dal_cur'],
            array_column($table['columns'], 'id'),
        );
        $this->assertSame('MD EUR', $table['columns'][4]['label']);
        $this->assertSame('DAL EUR', $table['columns'][5]['label']);

        $this->assertSame('100,00', $table['rows'][0]['md_cur']);
        $this->assertNull($table['rows'][0]['dal_cur']);

        $total = $table['rows'][2];
        $this->assertSame('2 450,00', $total['md']);
        $this->assertSame('100,00', $total['md_cur']);
        $this->assertSame('100,00', $total['dal_cur']);
    }
}

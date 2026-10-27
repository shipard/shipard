<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Bank;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Economy\Bank\BankTransactionsViewer;

/**
 * Grid layout bankovních transakcí (F2, docs/viewer-grid.md §7.3, D11):
 * sloupce se sortable Datum/Částka, renderGridRow (znaménko dle direction,
 * badge zaúčtování, stateStyle z txStates, bez rowClass), sort mapa
 * v selectRows (výchozí docStateMain/date/id vs. injektovaný sort).
 */
class BankTransactionsViewerTest extends TestCase
{
    /** @var list<array{sql: string, params: array}> */
    private array $queries = [];

    private function makeViewer(?array $detailRow = null): BankTransactionsViewer
    {
        $this->queries = [];

        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn($detailRow);
        $db->method('fetchAll')->willReturnCallback(
            function (string $sql, ...$params): array {
                $this->queries[] = ['sql' => $sql, 'params' => $params];
                return [];
            },
        );

        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn (string $id): mixed => match ($id) {
                'economy.bank.txStates' => [
                    '10' => ['stateName' => 'Nová', 'stateStyle' => 'concept', 'mainState' => 0],
                    '40' => ['stateName' => 'Zaúčtováno', 'stateStyle' => 'done', 'mainState' => 2],
                ],
                'economy.bank.txOperations' => [
                    'payment.in' => ['name' => 'Příchozí platba'],
                ],
                default => null,
            },
        );

        $viewer = new BankTransactionsViewer($db, 'economy_bank_transactions');
        $viewer->setConfig($config);
        $viewer->setLanguage('cs');
        return $viewer;
    }

    private function txRow(array $overrides = []): array
    {
        return array_merge([
            'id'                => 7,
            'date_transaction'  => '2026-06-02',
            'direction'         => 1,
            'amount'            => '1500.00',
            'currency'          => 'czk',
            'counterparty_name' => 'ACME s.r.o.',
            'payment_reference' => '20260042',
            'operation'         => 'payment.in',
            'accounting_state'  => 0,
            'docState'          => 40,
            'docStateMain'      => 2,
            'partner_name'      => 'ACME, spol. s r.o.',
        ], $overrides);
    }

    // ── grid sloupce ────────────────────────────────────────────────────────

    public function testGridDefaultLayoutAndColumnsShape(): void
    {
        $viewer = $this->makeViewer();

        $this->assertSame('grid', $viewer->getDefaultLayout());

        $columns = $viewer->getGridColumns();
        $this->assertSame(
            ['date_transaction', 'amount', 'counterparty_name', 'partner_name', 'payment_reference', 'operation', 'accounting'],
            array_column($columns, 'id'),
        );
        $byId = array_column($columns, null, 'id');
        $this->assertTrue($byId['date_transaction']['sortable']);
        $this->assertTrue($byId['amount']['sortable']);
        $this->assertSame('right', $byId['amount']['align']);
        $this->assertTrue($byId['counterparty_name']['grow']);
        $this->assertArrayNotHasKey('sortable', $byId['counterparty_name'], 'Jen Datum a Částka jsou sortable');
    }

    public function testGridFooterStaysDisabled(): void
    {
        // D11 — SUM přes mix měn nedává smysl, footer se neimplementuje.
        $this->assertNull($this->makeViewer()->renderGridFooter(null, []));
    }

    // ── renderGridRow ───────────────────────────────────────────────────────

    public function testRenderGridRowMapsCellsWithSignAndCurrency(): void
    {
        $row = $this->makeViewer()->renderGridRow($this->txRow());

        $this->assertSame(7, $row['id']);
        $this->assertSame('done', $row['stateStyle'], 'Proužek ze stavu transakce (txStates)');
        $this->assertArrayNotHasKey('rowClass', $row, 'Chybu nese badge, řádek se nečervená');
        $this->assertSame('2. 6. 2026', $row['cells']['date_transaction']);
        $this->assertSame(
            [
                ['text' => '+1 500,00', 'class' => 'amount'],
                ['text' => 'CZK', 'class' => 'muted'],
            ],
            $row['cells']['amount'],
        );
        $this->assertSame('ACME s.r.o.', $row['cells']['counterparty_name']);
        $this->assertSame('Příchozí platba', $row['cells']['operation']);
        $this->assertNull($row['cells']['accounting'], 'Stav 0 = bez badge');
    }

    public function testOutgoingDirectionGetsMinusSign(): void
    {
        $row = $this->makeViewer()->renderGridRow($this->txRow(['direction' => 2]));

        $this->assertSame('−1 500,00', $row['cells']['amount'][0]['text']);
    }

    public function testAccountingStateBadges(): void
    {
        $viewer = $this->makeViewer();

        $posted = $viewer->renderGridRow($this->txRow(['accounting_state' => 1]));
        $this->assertSame(['text' => 'zaúčtováno', 'badge' => 'success'], $posted['cells']['accounting']);

        $error = $viewer->renderGridRow($this->txRow(['accounting_state' => 2]));
        $this->assertSame(['text' => 'chyba účtování', 'badge' => 'danger'], $error['cells']['accounting']);
    }

    public function testUnknownDocStateFallsBackToConceptStyle(): void
    {
        $row = $this->makeViewer()->renderGridRow($this->txRow(['docState' => 99]));

        $this->assertSame('concept', $row['stateStyle']);
    }

    // ── selectRows: řazení ──────────────────────────────────────────────────

    public function testDefaultOrderByWithoutSort(): void
    {
        $viewer = $this->makeViewer();
        $viewer->selectRows(null, [], 0);

        $this->assertStringContainsString(
            'ORDER BY t.`docStateMain` ASC, t.`date_transaction` DESC, t.`id` DESC',
            $this->queries[0]['sql'],
        );
    }

    public function testInjectedSortOverridesOrderByWithUniqueTail(): void
    {
        $viewer = $this->makeViewer();
        $viewer->setSort(['column' => 'amount', 'dir' => 'asc']);
        $viewer->selectRows(null, [], 0);

        $sql = $this->queries[0]['sql'];
        $this->assertStringContainsString('ORDER BY t.`amount` ASC, t.`id` ASC', $sql);
        $this->assertStringNotContainsString('t.`docStateMain` ASC', $sql);
    }

    public function testSortOutsideMapKeepsDefaultOrder(): void
    {
        $viewer = $this->makeViewer();
        $viewer->setSort(['column' => 'counterparty_name', 'dir' => 'asc']);
        $viewer->selectRows(null, [], 0);

        $this->assertStringContainsString(
            'ORDER BY t.`docStateMain` ASC, t.`date_transaction` DESC, t.`id` DESC',
            $this->queries[0]['sql'],
        );
    }

    // ── detail: Přehled bez údajů z hlavičky (#140 D26) ────────────────────

    public function testOverviewOmitsHeaderValuesAndShowsCounterpartyOnlyBesidePartner(): void
    {
        $detail = $this->makeViewer(detailRow: $this->txRow())->renderDetail(7);

        $this->assertSame('+1 500,00 CZK', $detail['title']);
        $this->assertSame('ACME, spol. s r.o. · 2. 6. 2026', $detail['subtitle']);
        $byTitle = array_column($detail['tabs'][0]['content']['groups'], 'items', 'title');
        $this->assertArrayNotHasKey('Částky', $byTitle, 'částku nese titulek; bez cizí měny skupina chybí');
        $tx = array_column($byTitle['Transakce'], 'value', 'label');
        $this->assertArrayNotHasKey('Datum transakce', $tx);
        $this->assertArrayNotHasKey('Stav', $tx);
        $cp = array_column($byTitle['Protistrana'], 'value', 'label');
        $this->assertSame('ACME s.r.o.', $cp['Název protistrany']);
        $this->assertArrayNotHasKey('Partner', $cp);

        // Bez partnera je v podtitulku název protistrany — v Přehledu pak není (#140 §2).
        $detail = $this->makeViewer(detailRow: $this->txRow(['partner_name' => null]))->renderDetail(7);
        $this->assertSame('ACME s.r.o. · 2. 6. 2026', $detail['subtitle']);
        $byTitle = array_column($detail['tabs'][0]['content']['groups'], 'items', 'title');
        $this->assertArrayNotHasKey('Název protistrany', array_column($byTitle['Protistrana'], 'value', 'label'));
    }
}

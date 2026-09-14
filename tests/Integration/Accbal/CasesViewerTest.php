<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Accbal;

use Shipard\Module\Economy\Accbal\CaseQuery;
use Shipard\Module\Economy\Accbal\CasesViewer;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Viewer saldokonta po případech nad reálným DS (#69 D1): fixtura dva
 * partneři, čtyři klíče (dluh po splatnosti, přeplatek, dluh v termínu,
 * uzavřený). Ověřuje se GROUP BY agregát, výchozí otevřenost, filtry typu
 * a po splatnosti, součet partnera ve skupinovém řádku, detail z row_id
 * a footer = Σ ledgeru pro stejný filtr (kritérium „Hotovo když").
 */
class CasesViewerTest extends IntegrationTestCase
{
    private const PARTNER_A = 990008;
    private const PARTNER_B = 990009;
    private const VS_PREFIX = 'IT-CV-';
    private const ACC_DATE  = '2026-06-10';

    private int $fiscalYear = 0;
    /** @var list<int> */
    private array $seededDocs = [];
    /** @var list<int> */
    private array $seededTxs = [];
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $fy = $this->db->fetchRow(
            'SELECT id FROM economy_codebooks_fiscal_years WHERE date_begin <= %s AND date_end >= %s LIMIT 1',
            self::ACC_DATE, self::ACC_DATE,
        );
        if ($fy === null) {
            $this->markTestSkipped('DS nemá fiskální rok pro ' . self::ACC_DATE);
        }
        $this->fiscalYear = (int) $fy['id'];
    }

    protected function onTearDown(): void
    {
        $dibi = $this->db->getDibiConnection();
        foreach ($this->seededDocs as $id) {
            $dibi->delete('economy_accbal_ledger')->where('doc_head = %i', $id)->execute();
        }
        foreach ($this->seededTxs as $id) {
            $dibi->delete('economy_accbal_ledger')->where('bank_transaction = %i', $id)->execute();
        }
    }

    /**
     * A1: dluh po splatnosti 600; A2: přeplatek −300; B1: dluh 200 v
     * termínu; B2: uzavřený. Vrací id předpisu A1 (= row_id případu).
     */
    private function seedFixture(int $balance): int
    {
        $a1 = $this->seed($balance, 0, self::PARTNER_A, 'A1', 1000.00, ['due_date' => '2026-05-31']);
        $this->seed($balance, 1, self::PARTNER_A, 'A1', 400.00);
        $this->seed($balance, 0, self::PARTNER_A, 'A2', 500.00, ['due_date' => '2026-06-30']);
        $this->seed($balance, 1, self::PARTNER_A, 'A2', 800.00);
        $this->seed($balance, 0, self::PARTNER_B, 'B1', 200.00, ['due_date' => '2099-12-31']);
        $this->seed($balance, 0, self::PARTNER_B, 'B2', 100.00, ['due_date' => '2026-06-30']);
        $this->seed($balance, 1, self::PARTNER_B, 'B2', 100.00);
        return $a1;
    }

    public function testOpenCasesGroupedByPartnerWithSubtotals(): void
    {
        $recv = $this->balanceId('receivables');
        $a1 = $this->seedFixture($recv);
        $viewer = $this->viewer();

        $rows = $viewer->selectRows(null, $this->filters(), 0);

        $this->assertCount(3, $rows, 'uzavřený B2 je bez „Včetně uzavřených" schovaný');
        $byVs = [];
        foreach ($rows as $r) {
            $byVs[substr((string) $r['payment_reference'], strlen(self::VS_PREFIX))] = $r;
        }
        $this->assertSame(['A1', 'A2', 'B1'], array_keys($byVs), 'partner, pak klíč');

        $this->assertSame(600.0, (float) $byVs['A1']['residual']);
        $this->assertSame(2, (int) $byVs['A1']['moves']);
        $this->assertSame($a1, (int) $byVs['A1']['row_id']);
        $this->assertSame(-300.0, (float) $byVs['A2']['residual']);
        $this->assertSame(300.0, (float) $byVs['A1']['partner_residual_hc'], 'součet partnera A: 600 − 300');
        $this->assertSame(300.0, (float) $byVs['A2']['partner_residual_hc']);
        $this->assertSame(200.0, (float) $byVs['B1']['partner_residual_hc']);

        $grid = $viewer->renderGridRow($byVs['A1']);
        $this->assertSame('cancelled', $grid['stateStyle'], 'dluh po splatnosti');
        $this->assertSame('p' . self::PARTNER_A, $grid['group']['key']);
        $this->assertStringEndsWith('zůstatek 300,00 CZK', $grid['group']['label']);
        $this->assertSame('concept', $viewer->renderGridRow($byVs['A2'])['stateStyle'], 'přeplatek');
        $this->assertNull($viewer->renderGridRow($byVs['B1'])['stateStyle'], 'dluh v termínu');
    }

    public function testFiltersByKindOverdueAndIncludeClosed(): void
    {
        $recv = $this->balanceId('receivables');
        $this->seedFixture($recv);
        $viewer = $this->viewer();

        $all = $viewer->selectRows(null, $this->filters(['include_closed' => '1']), 0);
        $this->assertCount(4, $all);

        $over = $viewer->selectRows(null, $this->filters(['kind' => CaseQuery::KIND_OVERPAYMENT]), 0);
        $this->assertCount(1, $over);
        $this->assertSame(self::VS_PREFIX . 'A2', (string) $over[0]['payment_reference']);

        $overdue = $viewer->selectRows(null, $this->filters(['overdue' => '1']), 0);
        $this->assertCount(1, $overdue);
        $this->assertSame(self::VS_PREFIX . 'A1', (string) $overdue[0]['payment_reference']);

        $closed = $viewer->selectRows(null, $this->filters(['kind' => CaseQuery::KIND_CLOSED]), 0);
        $this->assertCount(1, $closed);
        $this->assertSame(self::VS_PREFIX . 'B2', (string) $closed[0]['payment_reference']);

        $partnerB = $viewer->selectRows(null, $this->filters(['payment_reference' => self::VS_PREFIX . 'B']), 0);
        $this->assertCount(1, $partnerB, 'filtr VS je prefixový, jde před GROUP BY');

        $thisYear = $viewer->selectRows(null, $this->filters(['fiscal_year' => (string) $this->fiscalYear]), 0);
        $this->assertCount(3, $thisYear, 'období fixtury = tytéž otevřené případy');
        $this->assertSame(
            $viewer->renderGridFooter(null, $this->filters()),
            $viewer->renderGridFooter(null, $this->filters(['fiscal_year' => (string) $this->fiscalYear])),
            'fixtura leží v jednom období → footer s filtrem období = footer bez něj',
        );

        $otherYear = $this->db->fetchRow(
            'SELECT id FROM economy_codebooks_fiscal_years WHERE id <> %i AND docState != 90 ORDER BY id LIMIT 1',
            $this->fiscalYear,
        );
        if ($otherYear !== null) {
            $this->assertSame([], $viewer->selectRows(null, $this->filters(['fiscal_year' => (string) $otherYear['id']]), 0));
        }
    }

    public function testDefaultPeriodFilterIsYearOfToday(): void
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $current = $this->db->fetchRow(
            'SELECT id FROM economy_codebooks_fiscal_years WHERE docState != 90 AND date_begin <= %s AND date_end >= %s',
            $today, $today,
        );
        if ($current === null) {
            $this->markTestSkipped('DS nemá fiskální rok pro dnešek');
        }

        $period = $this->viewer()->getFilters()[0];

        $this->assertSame('fiscal_year', $period['id']);
        $this->assertSame((string) $current['id'], $period['default']);
        $this->assertContains((int) $current['id'], array_column($period['options'], 'value'));
    }

    public function testFooterMatchesLedgerSumsForSameFilter(): void
    {
        $recv = $this->balanceId('receivables');
        $this->seedFixture($recv);
        $viewer = $this->viewer();

        $footer = $viewer->renderGridFooter(null, $this->filters());

        // Σ ledgeru přes pohyby otevřených případů se stejným VS prefixem.
        $expected = $this->db->fetchRow(
            'SELECT SUM(CASE WHEN l.bal_side = 0 THEN l.amount_hc ELSE 0 END) AS r,'
            . ' SUM(CASE WHEN l.bal_side = 1 THEN l.amount_hc ELSE 0 END) AS p'
            . ' FROM economy_accbal_ledger l'
            . ' WHERE l.balance = %i AND l.payment_reference LIKE %s AND ' . CaseQuery::residualSubquerySql('l') . ' <> 0',
            $recv, self::VS_PREFIX . '%',
        );
        $this->assertSame(1700.0, (float) $expected['r']);
        $this->assertSame(1200.0, (float) $expected['p']);
        $this->assertSame('1 700,00 CZK', $footer['sum_requests'][1]['text']);
        $this->assertSame('1 200,00 CZK', $footer['sum_payments'][1]['text']);
        $this->assertSame('500,00 CZK', $footer['residual_hc'][1]['text'], 'Σ zůstatků = Σ předpisy − Σ úhrady ledgeru');
    }

    public function testDetailFromRowIdOffersCaseMovements(): void
    {
        $recv = $this->balanceId('receivables');
        $a1 = $this->seedFixture($recv);

        $detail = $this->viewer()->renderDetail($a1);

        $groups = $detail['tabs'][0]['content']['groups'];
        $this->assertSame('Případ', $groups[0]['title']);
        $items = array_column($groups[1]['items'], 'value', 'label');
        $this->assertSame('600,00 CZK', $items['Zůstatek']);
        $this->assertSame('Dluh', $items['Stav']);
        $this->assertSame('2', $items['Pohybů']);
        $this->assertArrayHasKey('Dní po splatnosti', $items);

        $action = $detail['actions'][0];
        $this->assertSame('open_viewer', $action['kind']);
        $this->assertSame('economy.accbal.ledger', $action['viewerId']);
        $this->assertSame('receivables', $action['viewGroup']);
        $this->assertSame(
            ['fiscal_year' => (string) $this->fiscalYear, 'payment_reference' => self::VS_PREFIX . 'A1'],
            $action['filters'],
            'období případu (přebíjí výchozí rok pohybů) + VS; partner bez osoby se neposílá',
        );
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function viewer(): CasesViewer
    {
        $viewer = new CasesViewer($this->db, 'economy_accbal_ledger');
        $viewer->setLanguage('cs');
        return $viewer;
    }

    /** Filtry vieweru s izolací na testovací VS prefix a skupinu. @return list<array{id: string, value: string}> */
    private function filters(array $extra = []): array
    {
        $out = [['id' => 'viewGroup', 'value' => 'receivables'], ['id' => 'payment_reference', 'value' => self::VS_PREFIX]];
        foreach ($extra as $id => $value) {
            $out[] = ['id' => $id, 'value' => $value];
        }
        return $out;
    }

    private function balanceId(string $code): int
    {
        $row = $this->db->fetchRow('SELECT id FROM economy_accbal_balances WHERE code = %s', $code);
        if ($row === null) {
            $this->markTestSkipped("DS nemá naseedované saldokonto '{$code}'");
        }
        return (int) $row['id'];
    }

    /** @param array<string, mixed> $over  @return int id pohybu */
    private function seed(int $balance, int $balSide, int $partner, string $vs, float $amount, array $over = []): int
    {
        $dibi = $this->db->getDibiConnection();
        $sourceId = ($balSide === 0 ? 974_000_000 : 975_000_000) + (++$this->seq);
        if ($balSide === 0) {
            $this->seededDocs[] = $sourceId;
        } else {
            $this->seededTxs[] = $sourceId;
        }
        $dibi->insert('economy_accbal_ledger', array_merge([
            'balance'           => $balance,
            'bal_side'          => $balSide,
            'source_kind'       => $balSide === 0 ? 'doc' : 'bankTransaction',
            'source_id'         => $sourceId,
            'doc_head'          => $balSide === 0 ? $sourceId : null,
            'bank_transaction'  => $balSide === 1 ? $sourceId : null,
            'account_number'    => '311100',
            'fiscal_year'       => $this->fiscalYear,
            'partner'           => $partner,
            'payment_reference' => self::VS_PREFIX . $vs,
            'currency'          => 'czk',
            'home_currency'     => 'czk',
            'amount'            => $amount,
            'amount_hc'         => $amount,
        ], $over))->execute();
        return (int) $dibi->getInsertId();
    }
}

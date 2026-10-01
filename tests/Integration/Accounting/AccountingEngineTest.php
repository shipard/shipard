<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Accounting;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Document\AbstractJournalEventHandler;
use Shipard\Core\Document\JournalEventDispatcher;
use Shipard\Module\Economy\Accounting\AccountingEngine;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Scénáře W3.3 z tasks/accounting-phase2.md — engine nad reálným dev DS
 * (účtový rozvrh, fiskální období a osoby z provisioneru).
 *
 * Dokladová data se vkládají přímo SQL s ručně spočtenými computed
 * hodnotami — engine čte DB, nezajímá ho, jak vznikla. Vše vytvořené se
 * v tearDown maže.
 */
class AccountingEngineTest extends IntegrationTestCase
{
    private const ACC_DATE = '2026-06-10';

    /** @var list<int> */
    private array $createdHeads = [];

    /** @var list<int> */
    private array $createdItems = [];

    /** @var list<int> */
    private array $createdAccounts = [];

    private ?AccountingEngine $engine = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new AccountingEngine(
            $this->db->getDibiConnection(),
            ConfigRuntime::load($this->realDsPath, 'cs'),
        );
    }

    protected function onTearDown(): void
    {
        $dibi = $this->db->getDibiConnection();
        foreach ($this->createdHeads as $id) {
            $dibi->delete('economy_accounting_journal')->where('doc_head = %i', $id)->execute();
            $dibi->delete('docs_core_vat_recap')->where('doc_head = %i', $id)->execute();
            $dibi->delete('docs_core_rows')->where('doc_head = %i', $id)->execute();
            $dibi->delete('docs_core_heads')->where('id = %i', $id)->execute();
        }
        foreach ($this->createdItems as $id) {
            $dibi->delete('economy_items')->where('id = %i', $id)->execute();
        }
        foreach ($this->createdAccounts as $id) {
            $dibi->delete('economy_accounting_accounts')->where('id = %i', $id)->execute();
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function fiscalIds(): array
    {
        $fy = $this->db->fetchRow(
            'SELECT id FROM economy_codebooks_fiscal_years WHERE date_begin <= %s AND date_end >= %s LIMIT 1',
            self::ACC_DATE, self::ACC_DATE,
        );
        $fm = $this->db->fetchRow(
            'SELECT id FROM economy_codebooks_fiscal_months WHERE date_begin <= %s AND date_end >= %s AND period_type = 1 LIMIT 1',
            self::ACC_DATE, self::ACC_DATE,
        );
        if ($fy === null || $fm === null) {
            $this->markTestSkipped('Dev DS nemá fiskální období pro ' . self::ACC_DATE);
        }
        return [(int) $fy['id'], (int) $fm['id']];
    }

    private function anyPartnerId(): int
    {
        $row = $this->db->fetchRow('SELECT id FROM base_persons_persons ORDER BY id LIMIT 1');
        if ($row === null) {
            $this->markTestSkipped('Dev DS nemá žádnou osobu');
        }
        return (int) $row['id'];
    }

    private function seriesId(string $docType): int
    {
        $row = $this->db->fetchRow(
            'SELECT id FROM docs_core_number_series WHERE doc_type = %s LIMIT 1',
            $docType,
        );
        if ($row === null) {
            $this->markTestSkipped("Dev DS nemá číselnou řadu pro {$docType}");
        }
        return (int) $row['id'];
    }

    /**
     * Vloží hlavičku s defaulty CZK dokladu ve stavu 40. $overrides
     * přepisují cokoliv (totals, měnu, fiskální období…).
     */
    private function insertHead(string $docType, array $overrides = []): int
    {
        [$fy, $fm] = $this->fiscalIds();
        $head = array_merge([
            'doc_type'        => $docType,
            'number_series'   => $this->seriesId($docType),
            'doc_number'      => 'IT-ACC-' . uniqid(),
            'issue_date'      => self::ACC_DATE,
            'accounting_date' => self::ACC_DATE,
            'fiscal_year'     => $fy,
            'fiscal_month'    => $fm,
            'partner'         => $this->anyPartnerId(),
            'doc_currency'    => 'czk',
            'home_currency'   => 'czk',
            'exchange_rate'   => 1.0,
            'doc_text'        => 'IT test doklad',
            'docState'        => 40,
            'docStateMain'    => 2,
        ], $overrides);

        $dibi = $this->db->getDibiConnection();
        $dibi->insert('docs_core_heads', $head)->execute();
        $id = (int) $dibi->getInsertId();
        $this->createdHeads[] = $id;
        return $id;
    }

    /**
     * Vloží běžný řádek s computed hodnotami. Pro CZK doklady _dom = cur.
     */
    private function insertRow(int $headId, string $operation, float $base, float $vatPct, array $overrides = []): int
    {
        $amount = round($base * $vatPct / 100.0, 2);
        $row = array_merge([
            'doc_head'       => $headId,
            'row_kind'       => 1,
            'operation'      => $operation,
            'description'    => "Řádek {$operation}",
            'vat_code'       => 'cz-101',
            'vat_pct'        => $vatPct,
            'vat_base'       => $base,
            'vat_amount'     => $amount,
            'vat_total'      => round($base + $amount, 2),
            'vat_base_dom'   => $base,
            'vat_amount_dom' => $amount,
            'vat_total_dom'  => round($base + $amount, 2),
        ], $overrides);

        $dibi = $this->db->getDibiConnection();
        $dibi->insert('docs_core_rows', $row)->execute();
        return (int) $dibi->getInsertId();
    }

    private function insertRecap(int $headId, float $base, float $tax, array $overrides = []): void
    {
        $recap = array_merge([
            'doc_head'        => $headId,
            'vat_code'        => 'cz-101',
            'vat_pct'         => 21.0,
            'base'            => $base,
            'tax'             => $tax,
            'total'           => round($base + $tax, 2),
            'base_dom'        => $base,
            'tax_dom'         => $tax,
            'total_dom'       => round($base + $tax, 2),
            'sum_base'        => 1,
            'sum_tax'         => 1,
            'sum_total'       => 1,
            'is_reverse_pair' => 0,
            'order_pos'       => 0,
        ], $overrides);
        $this->db->getDibiConnection()->insert('docs_core_vat_recap', $recap)->execute();
    }

    /** @return list<array<string, mixed>> */
    private function journalOf(int $headId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM economy_accounting_journal WHERE doc_head = %i ORDER BY id',
            $headId,
        );
        return array_map(fn($r) => is_array($r) ? $r : $r->toArray(), $rows);
    }

    /** @return array<string, mixed> Jediný řádek deníku s daným prefixem čísla účtu */
    private function lineByPrefix(array $journal, string $prefix): array
    {
        $matches = array_values(array_filter(
            $journal,
            fn($l) => str_starts_with((string) $l['account_number'], $prefix),
        ));
        $this->assertCount(1, $matches, "Očekáván právě jeden řádek deníku {$prefix}*");
        return $matches[0];
    }

    private function assertBalanced(array $journal): void
    {
        $dr = array_sum(array_map(fn($l) => (float) $l['money_dr'], $journal));
        $cr = array_sum(array_map(fn($l) => (float) $l['money_cr'], $journal));
        $this->assertEqualsWithDelta($dr, $cr, 0.001, 'MD != DAL (dom)');

        $drCur = array_sum(array_map(fn($l) => (float) $l['money_dr_cur'], $journal));
        $crCur = array_sum(array_map(fn($l) => (float) $l['money_cr_cur'], $journal));
        $this->assertEqualsWithDelta($drCur, $crCur, 0.001, 'MD != DAL (cur)');
    }

    private function accountingState(int $headId): int
    {
        $row = $this->db->fetchRow('SELECT accounting_state FROM docs_core_heads WHERE id = %i', $headId);
        return (int) $row['accounting_state'];
    }

    // ── Scénáře ─────────────────────────────────────────────────────────────

    public function testInvnoCzkBasic(): void
    {
        $headId = $this->insertHead('invno', [
            'total_base' => 1000.0, 'total_vat' => 210.0, 'total_amount' => 1210.0,
            'total_base_dom' => 1000.0, 'total_vat_dom' => 210.0, 'total_amount_dom' => 1210.0,
        ]);
        $this->insertRow($headId, 'sale.services', 1000.0, 21.0);
        $this->insertRecap($headId, 1000.0, 210.0);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(1, $result['state']);
        $this->assertSame([], $result['messages']);
        $this->assertSame(1, $this->accountingState($headId));

        $journal = $this->journalOf($headId);
        $this->assertCount(3, $journal);
        $this->assertBalanced($journal);

        $revenue = $this->lineByPrefix($journal, '602');
        $this->assertEqualsWithDelta(1000.0, (float) $revenue['money_cr'], 0.001);
        $this->assertSame('sale.services', $revenue['operation']);

        $vat = $this->lineByPrefix($journal, '343');
        $this->assertEqualsWithDelta(210.0, (float) $vat['money_cr'], 0.001);

        $receivable = $this->lineByPrefix($journal, '311');
        $this->assertEqualsWithDelta(1210.0, (float) $receivable['money_dr'], 0.001);

        // CZK doklad: *_cur == domácí hodnoty
        foreach ($journal as $line) {
            $this->assertEqualsWithDelta((float) $line['money_dr'], (float) $line['money_dr_cur'], 0.001);
            $this->assertEqualsWithDelta((float) $line['money_cr'], (float) $line['money_cr_cur'], 0.001);
            $this->assertSame('czk', $line['currency']);
        }
    }

    public function testStampsPaymentIdentityFromHead(): void
    {
        // accbal Fáze 0: každý řádek deníku nese párovací symboly +
        // splatnost z hlavičky (konstantní přes doklad).
        $headId = $this->insertHead('invno', [
            'total_base' => 1000.0, 'total_vat' => 210.0, 'total_amount' => 1210.0,
            'total_base_dom' => 1000.0, 'total_vat_dom' => 210.0, 'total_amount_dom' => 1210.0,
            'payment_reference' => '20260042',
            'specific_symbol'   => '777',
            'constant_symbol'   => '0308',
            'due_date'          => '2026-07-10',
        ]);
        $this->insertRow($headId, 'sale.services', 1000.0, 21.0);
        $this->insertRecap($headId, 1000.0, 210.0);

        $this->assertSame(1, $this->engine->accountDocument($headId)['state']);

        $journal = $this->journalOf($headId);
        $this->assertNotEmpty($journal);
        foreach ($journal as $line) {
            $this->assertSame('20260042', $line['payment_reference']);
            $this->assertSame('777', $line['specific_symbol']);
            $this->assertSame('0308', $line['constant_symbol']);
            $this->assertSame(
                '2026-07-10',
                $line['due_date'] instanceof \DateTimeInterface
                    ? $line['due_date']->format('Y-m-d')
                    : (string) $line['due_date'],
            );
        }
    }

    public function testEmitsJournalWrittenOnAccountReaccountAndClear(): void
    {
        AcctJournalSpy::$calls = [];
        $dispatcher = new JournalEventDispatcher([
            ['class' => AcctJournalSpy::class, 'events' => ['journalWritten']],
        ]);
        $engine = new AccountingEngine(
            $this->db->getDibiConnection(),
            ConfigRuntime::load($this->realDsPath, 'cs'),
            $dispatcher,
        );

        $headId = $this->insertHead('invno', [
            'total_base' => 1000.0, 'total_vat' => 210.0, 'total_amount' => 1210.0,
            'total_base_dom' => 1000.0, 'total_vat_dom' => 210.0, 'total_amount_dom' => 1210.0,
        ]);
        $this->insertRow($headId, 'sale.services', 1000.0, 21.0);
        $this->insertRecap($headId, 1000.0, 210.0);

        $engine->accountDocument($headId);            // zápis deníku
        $engine->accountDocument($headId);            // reaccount (bez změny stavu)
        $engine->clearDocument($headId);              // vymazání deníku

        $this->assertSame(
            [['doc', $headId], ['doc', $headId], ['doc', $headId]],
            AcctJournalSpy::$calls,
            'journalWritten se vyšle po zápisu, reaccountu i clear',
        );
    }

    public function testJournalHandlerExceptionDoesNotBreakAccounting(): void
    {
        // Pozn.: spolknutá výjimka se zaloguje (ErrorLogger → stderr) — očekávané.
        $dispatcher = new JournalEventDispatcher([
            ['class' => ThrowingAcctJournalSpy::class, 'events' => ['journalWritten']],
        ]);
        $engine = new AccountingEngine(
            $this->db->getDibiConnection(),
            ConfigRuntime::load($this->realDsPath, 'cs'),
            $dispatcher,
        );

        $headId = $this->insertHead('invno', [
            'total_base' => 1000.0, 'total_vat' => 210.0, 'total_amount' => 1210.0,
            'total_base_dom' => 1000.0, 'total_vat_dom' => 210.0, 'total_amount_dom' => 1210.0,
        ]);
        $this->insertRow($headId, 'sale.services', 1000.0, 21.0);
        $this->insertRecap($headId, 1000.0, 210.0);

        // Výjimka v journal handleru nesmí shodit účtování — commit už proběhl.
        $result = $engine->accountDocument($headId);
        $this->assertSame(1, $result['state']);
        $this->assertSame(1, $this->accountingState($headId));
        $this->assertCount(3, $this->journalOf($headId));
    }

    /**
     * Engine volaný uvnitř cizí transakce (zaúčtování majetku, import) ji
     * nesmí commitnout — zapisuje přes savepoint a rollback volajícího
     * vrátí i deník.
     */
    public function testEngineInsideOuterTransactionLeavesItToTheCaller(): void
    {
        $dibi = $this->db->getDibiConnection();
        $engine = new AccountingEngine($dibi, ConfigRuntime::load($this->realDsPath, 'cs'));

        $headId = $this->insertHead('invno', [
            'total_base' => 1000.0, 'total_vat' => 210.0, 'total_amount' => 1210.0,
            'total_base_dom' => 1000.0, 'total_vat_dom' => 210.0, 'total_amount_dom' => 1210.0,
        ]);
        $this->insertRow($headId, 'sale.services', 1000.0, 21.0);
        $this->insertRecap($headId, 1000.0, 210.0);

        $dibi->begin();
        try {
            $result = $engine->accountDocument($headId);
            $this->assertSame(1, $result['state']);
            $this->assertCount(3, $this->journalOf($headId), 'uvnitř transakce je deník vidět');
            $this->assertSame(1, (int) $dibi->fetchSingle('SELECT @@in_transaction'), 'vnější transakce stále běží');
        } finally {
            $dibi->rollback();
        }

        $this->assertSame([], $this->journalOf($headId), 'rollback volajícího vrátil i deník');
        $this->assertSame(0, $this->accountingState($headId));
    }

    public function testInvnoPdpOutputBooksNoVat(): void
    {
        // W4: PDP výstup (cz-150) — daň odvádí zákazník, doklad je jen základ.
        // Recap dle opraveného vat-cz.jsonc: tax 0, sum_tax 0, žádný pár.
        $headId = $this->insertHead('invno', [
            'total_base' => 1000.0, 'total_vat' => 0.0, 'total_amount' => 1000.0,
            'total_base_dom' => 1000.0, 'total_vat_dom' => 0.0, 'total_amount_dom' => 1000.0,
        ]);
        $this->insertRow($headId, 'sale.services', 1000.0, 21.0, ['vat_code' => 'cz-150']);
        $this->insertRecap($headId, 1000.0, 0.0, ['vat_code' => 'cz-150', 'sum_tax' => 0]);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(1, $result['state']);
        $journal = $this->journalOf($headId);
        $this->assertCount(2, $journal, 'PDP výstup nesmí generovat žádný řádek 343');
        $this->assertBalanced($journal);
        $this->assertEqualsWithDelta(1000.0, (float) $this->lineByPrefix($journal, '602')['money_cr'], 0.001);
        $this->assertEqualsWithDelta(1000.0, (float) $this->lineByPrefix($journal, '311')['money_dr'], 0.001);
    }

    public function testInvniCzkThreeOperations(): void
    {
        $headId = $this->insertHead('invni', [
            'total_base' => 350.0, 'total_vat' => 73.5, 'total_amount' => 423.5,
            'total_base_dom' => 350.0, 'total_vat_dom' => 73.5, 'total_amount_dom' => 423.5,
        ]);
        $this->insertRow($headId, 'purchase.goods', 100.0, 21.0);
        $this->insertRow($headId, 'purchase.services', 200.0, 21.0);
        $this->insertRow($headId, 'purchase.other', 50.0, 21.0);
        $this->insertRecap($headId, 350.0, 73.5);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(1, $result['state']);
        $journal = $this->journalOf($headId);
        $this->assertCount(5, $journal);
        $this->assertBalanced($journal);

        // Náklady MD, DPH MD, závazek DAL
        $this->assertEqualsWithDelta(100.0, (float) $this->lineByPrefix($journal, '504')['money_dr'], 0.001);
        $this->assertEqualsWithDelta(200.0, (float) $this->lineByPrefix($journal, '518')['money_dr'], 0.001);
        $this->assertEqualsWithDelta(50.0, (float) $this->lineByPrefix($journal, '548')['money_dr'], 0.001);
        $this->assertEqualsWithDelta(73.5, (float) $this->lineByPrefix($journal, '343')['money_dr'], 0.001);
        $this->assertEqualsWithDelta(423.5, (float) $this->lineByPrefix($journal, '321')['money_cr'], 0.001);
    }

    /**
     * W1: EU pořízení služeb (invni, cz-217, základ 1000, 21 %).
     * Recap dle DocDocument: primární cz-217 nese odpočet (tax 210,
     * sum_tax 0), pár cz-207 oddanění (is_reverse_pair 1). Engine:
     * odpočet MD (strana kroku), oddanění DAL (opačná strana).
     */
    public function testInvniEuAcquisitionBooksVatBothSides(): void
    {
        $headId = $this->insertHead('invni', [
            'total_base' => 1000.0, 'total_vat' => 0.0, 'total_amount' => 1000.0,
            'total_base_dom' => 1000.0, 'total_vat_dom' => 0.0, 'total_amount_dom' => 1000.0,
        ]);
        $this->insertRow($headId, 'purchase.services', 1000.0, 21.0, ['vat_code' => 'cz-217']);
        $this->insertRecap($headId, 1000.0, 210.0, [
            'vat_code' => 'cz-217', 'total' => 1000.0, 'total_dom' => 1000.0, 'sum_tax' => 0,
        ]);
        $this->insertRecap($headId, 1000.0, 210.0, [
            'vat_code' => 'cz-207', 'is_reverse_pair' => 1, 'order_pos' => 1,
            'sum_base' => 0, 'sum_tax' => 0, 'sum_total' => 0,
        ]);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(1, $result['state'], 'EU pořízení musí být vyrovnané: ' . json_encode($result['messages']));
        $journal = $this->journalOf($headId);
        $this->assertCount(4, $journal);
        $this->assertBalanced($journal);

        $this->assertEqualsWithDelta(1000.0, (float) $this->lineByPrefix($journal, '518')['money_dr'], 0.001);
        $this->assertEqualsWithDelta(1000.0, (float) $this->lineByPrefix($journal, '321')['money_cr'], 0.001);

        // Odpočet i oddanění na analytice svého kódu (mapování v předpisu)
        $deduction = $this->lineByPrefix($journal, '343217');
        $this->assertEqualsWithDelta(210.0, (float) $deduction['money_dr'], 0.001, 'Odpočet cz-217 na MD');
        $selfAssessment = $this->lineByPrefix($journal, '343207');
        $this->assertEqualsWithDelta(210.0, (float) $selfAssessment['money_cr'], 0.001, 'Oddanění cz-207 na DAL');
    }

    /**
     * W1: tuzemské PDP4 na vstupu (invni, cz-115 → pár cz-203).
     */
    public function testInvniDomesticPdpInputBooksVatBothSides(): void
    {
        $headId = $this->insertHead('invni', [
            'total_base' => 1000.0, 'total_vat' => 0.0, 'total_amount' => 1000.0,
            'total_base_dom' => 1000.0, 'total_vat_dom' => 0.0, 'total_amount_dom' => 1000.0,
        ]);
        $this->insertRow($headId, 'purchase.other', 1000.0, 21.0, ['vat_code' => 'cz-115']);
        $this->insertRecap($headId, 1000.0, 210.0, [
            'vat_code' => 'cz-115', 'total' => 1000.0, 'total_dom' => 1000.0, 'sum_tax' => 0,
        ]);
        $this->insertRecap($headId, 1000.0, 210.0, [
            'vat_code' => 'cz-203', 'is_reverse_pair' => 1, 'order_pos' => 1,
            'sum_base' => 0, 'sum_tax' => 0, 'sum_total' => 0,
        ]);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(1, $result['state'], 'PDP vstup musí být vyrovnaný: ' . json_encode($result['messages']));
        $journal = $this->journalOf($headId);
        $this->assertCount(4, $journal);
        $this->assertBalanced($journal);

        $this->assertEqualsWithDelta(1000.0, (float) $this->lineByPrefix($journal, '548')['money_dr'], 0.001);
        $this->assertEqualsWithDelta(1000.0, (float) $this->lineByPrefix($journal, '321')['money_cr'], 0.001);

        $this->assertEqualsWithDelta(210.0, (float) $this->lineByPrefix($journal, '343115')['money_dr'], 0.001);
        $this->assertEqualsWithDelta(210.0, (float) $this->lineByPrefix($journal, '343203')['money_cr'], 0.001);
    }

    /**
     * Vlna C: invni s odpočtem poskytnuté zálohy — odpočet je na dokladu
     * záporný (−5 000 základ, −1 050 DPH), reverseSign ho otočí na kladný
     * DAL; rekapitulace záporné řádky netuje (5 000 / 1 050). Účet dohledá
     * kategorie advances.given z rozvrhu DS (D10): vat_amount ≠ 0 → maska
     * 3149 → 314900. Zálohový řádek nese payment_reference (číslo
     * zálohového dokladu).
     */
    public function testInvniAdvanceDeductionBooksReversedSide(): void
    {
        $headId = $this->insertHead('invni', [
            'total_base' => 5000.0, 'total_vat' => 1050.0, 'total_amount' => 6050.0,
            'total_base_dom' => 5000.0, 'total_vat_dom' => 1050.0, 'total_amount_dom' => 6050.0,
        ]);
        $this->insertRow($headId, 'purchase.services', 10000.0, 21.0);
        $this->insertRow($headId, 'purchase.advanceDeduction', -5000.0, 21.0, [
            'payment_reference' => 'ZAL-1',
        ]);
        $this->insertRecap($headId, 5000.0, 1050.0);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(1, $result['state'], json_encode($result['messages']));
        $journal = $this->journalOf($headId);
        $this->assertCount(4, $journal);
        $this->assertBalanced($journal);

        $this->assertEqualsWithDelta(10000.0, (float) $this->lineByPrefix($journal, '518')['money_dr'], 0.001);
        $this->assertEqualsWithDelta(1050.0, (float) $this->lineByPrefix($journal, '343')['money_dr'], 0.001);

        $deduction = $this->lineByPrefix($journal, '314900');
        $this->assertEqualsWithDelta(5000.0, (float) $deduction['money_cr'], 0.001, 'Odpočet otočený na kladný DAL');
        $this->assertEqualsWithDelta(0.0, (float) $deduction['money_dr'], 0.001);
        $this->assertSame('purchase.advanceDeduction', $deduction['operation']);
        $this->assertSame('ZAL-1', (string) $deduction['payment_reference']);

        $payable = $this->lineByPrefix($journal, '321');
        $this->assertEqualsWithDelta(6050.0, (float) $payable['money_cr'], 0.001, 'DAL 321 = zbytek k úhradě');
        $this->assertNull($payable['payment_reference'], 'Závazek nese identitu hlavičky, ne zálohy');
    }

    /**
     * Vlna C: invni „daňový doklad k záloze" (vzor starý doklad 56036) —
     * zdanění zálohy (+7 290 vč. DPH 21 z ceny) + odpočet tax0 (−7 290).
     * Účty dohledá kategorie (D10): zdaněná daň ≠ 0 → 3149 → 314900,
     * odpočet tax0 → brutto maska 314 → 314100. Netto 0 → žádný řádek 321,
     * deník vyrovnaný.
     */
    public function testInvniAdvanceVatDocumentNetsToZero(): void
    {
        $headId = $this->insertHead('invni', [
            'total_base' => -1265.21, 'total_vat' => 1265.21, 'total_amount' => 0.0,
            'total_base_dom' => -1265.21, 'total_vat_dom' => 1265.21, 'total_amount_dom' => 0.0,
        ]);
        $this->insertRow($headId, 'purchase.advanceVat', 6024.79, 21.0, [
            'payment_reference' => 'ZAL-2',
        ]);
        $this->insertRow($headId, 'purchase.advanceDeduction', -7290.0, 0.0, [
            'vat_code'          => null,
            'vat_pct'           => null,
            'payment_reference' => 'ZAL-2',
        ]);
        $this->insertRecap($headId, 6024.79, 1265.21);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(1, $result['state'], json_encode($result['messages']));
        $journal = $this->journalOf($headId);
        $this->assertCount(3, $journal, 'Netto 0 → žádný řádek 321');
        $this->assertBalanced($journal);

        $advanceVat = $this->lineByPrefix($journal, '314900');
        $this->assertEqualsWithDelta(6024.79, (float) $advanceVat['money_dr'], 0.001);
        $this->assertSame('purchase.advanceVat', $advanceVat['operation']);
        $this->assertSame('ZAL-2', (string) $advanceVat['payment_reference']);

        $this->assertEqualsWithDelta(1265.21, (float) $this->lineByPrefix($journal, '343')['money_dr'], 0.001);

        $deduction = $this->lineByPrefix($journal, '314100');
        $this->assertEqualsWithDelta(7290.0, (float) $deduction['money_cr'], 0.001, 'Odpočet tax0 otočený na DAL');
        $this->assertSame('ZAL-2', (string) $deduction['payment_reference']);
    }

    /**
     * Vlna C: invno zrcadlo — odpočet přijaté zálohy tax0 (−3 000) se
     * otočí na MD, pohledávka 311 = zbytek k úhradě. Účet dohledá
     * kategorie advances.received (D10): tax0 → brutto maska 324 → 324100.
     */
    public function testInvnoAdvanceDeductionMirror(): void
    {
        $headId = $this->insertHead('invno', [
            'total_base' => 7000.0, 'total_vat' => 2100.0, 'total_amount' => 9100.0,
            'total_base_dom' => 7000.0, 'total_vat_dom' => 2100.0, 'total_amount_dom' => 9100.0,
        ]);
        $this->insertRow($headId, 'sale.services', 10000.0, 21.0);
        $this->insertRow($headId, 'sale.advanceDeduction', -3000.0, 0.0, [
            'vat_code'          => null,
            'vat_pct'           => null,
            'payment_reference' => 'ZAL-3',
        ]);
        $this->insertRecap($headId, 10000.0, 2100.0);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(1, $result['state'], json_encode($result['messages']));
        $journal = $this->journalOf($headId);
        $this->assertCount(4, $journal);
        $this->assertBalanced($journal);

        $this->assertEqualsWithDelta(10000.0, (float) $this->lineByPrefix($journal, '602')['money_cr'], 0.001);
        $this->assertEqualsWithDelta(2100.0, (float) $this->lineByPrefix($journal, '343')['money_cr'], 0.001);

        $deduction = $this->lineByPrefix($journal, '324100');
        $this->assertEqualsWithDelta(3000.0, (float) $deduction['money_dr'], 0.001, 'Odpočet otočený na kladný MD');
        $this->assertSame('sale.advanceDeduction', $deduction['operation']);
        $this->assertSame('ZAL-3', (string) $deduction['payment_reference']);

        $receivable = $this->lineByPrefix($journal, '311');
        $this->assertEqualsWithDelta(9100.0, (float) $receivable['money_dr'], 0.001, 'MD 311 = zbytek k úhradě');
        $this->assertNull($receivable['payment_reference']);
    }

    /**
     * D10: řetěz masek ["3249", "324"] kategorie advances.received —
     * s 3249xx v rozvrhu vyhrává první maska (324900), po jejím dočasném
     * skrytí (vzor DS B, který 3249xx nemá) spadne dohledání na
     * druhou masku (324100). Skryté účty se v finally obnoví.
     */
    public function testInvnoAdvanceVatMaskChainFallsBackTo324(): void
    {
        $hidden = $this->db->fetchAll(
            'SELECT id, docState FROM economy_accounting_accounts
             WHERE number LIKE %like~ AND account_level = 4 AND docState IN (10, 40, 80)',
            '3249',
        );
        if ($hidden === []) {
            $this->markTestSkipped('Dev DS nemá žádnou aktivní analytiku 3249xx');
        }

        $headId = $this->insertHead('invno', [
            'total_base' => 1000.0, 'total_vat' => 210.0, 'total_amount' => 1210.0,
            'total_base_dom' => 1000.0, 'total_vat_dom' => 210.0, 'total_amount_dom' => 1210.0,
        ]);
        $this->insertRow($headId, 'sale.advanceVat', 1000.0, 21.0, [
            'payment_reference' => 'ZAL-4',
        ]);
        $this->insertRecap($headId, 1000.0, 210.0);

        // S 3249xx v rozvrhu: první maska řetězu.
        $this->assertSame(1, $this->engine->accountDocument($headId)['state']);
        $advance = $this->lineByPrefix($this->journalOf($headId), '3249');
        $this->assertEqualsWithDelta(1000.0, (float) $advance['money_cr'], 0.001);
        $this->assertSame('sale.advanceVat', $advance['operation']);
        $this->assertSame('ZAL-4', (string) $advance['payment_reference']);

        $dibi = $this->db->getDibiConnection();
        try {
            foreach ($hidden as $acc) {
                $dibi->update('economy_accounting_accounts', ['docState' => 90])
                    ->where('id = %i', (int) $acc['id'])->execute();
            }

            $result = $this->engine->accountDocument($headId);

            $this->assertSame(1, $result['state'], json_encode($result['messages']));
            $journal = $this->journalOf($headId);
            $this->assertBalanced($journal);
            $fallback = $this->lineByPrefix($journal, '324100');
            $this->assertEqualsWithDelta(1000.0, (float) $fallback['money_cr'], 0.001, 'Fallback na masku 324');
            $this->assertEqualsWithDelta(1210.0, (float) $this->lineByPrefix($journal, '311')['money_dr'], 0.001);
        } finally {
            foreach ($hidden as $acc) {
                $dibi->update('economy_accounting_accounts', ['docState' => (int) $acc['docState']])
                    ->where('id = %i', (int) $acc['id'])->execute();
            }
        }
    }

    /**
     * W2: tuzemský kód účtuje na svou analytiku (343120 výstup, 343110
     * vstup), ne na syntetiku 343.
     */
    public function testDomesticVatCodeBooksOnOwnAnalytics(): void
    {
        $headId = $this->insertHead('invno', [
            'total_base' => 1000.0, 'total_vat' => 210.0, 'total_amount' => 1210.0,
            'total_base_dom' => 1000.0, 'total_vat_dom' => 210.0, 'total_amount_dom' => 1210.0,
        ]);
        $this->insertRow($headId, 'sale.services', 1000.0, 21.0, ['vat_code' => 'cz-120']);
        $this->insertRecap($headId, 1000.0, 210.0, ['vat_code' => 'cz-120']);

        $this->assertSame(1, $this->engine->accountDocument($headId)['state']);
        $vat = $this->lineByPrefix($this->journalOf($headId), '343');
        $this->assertSame('343120', $vat['account_number']);
        $this->assertEqualsWithDelta(210.0, (float) $vat['money_cr'], 0.001);

        $headId2 = $this->insertHead('invni', [
            'total_base' => 500.0, 'total_vat' => 105.0, 'total_amount' => 605.0,
            'total_base_dom' => 500.0, 'total_vat_dom' => 105.0, 'total_amount_dom' => 605.0,
        ]);
        $this->insertRow($headId2, 'purchase.services', 500.0, 21.0, ['vat_code' => 'cz-110']);
        $this->insertRecap($headId2, 500.0, 105.0, ['vat_code' => 'cz-110']);

        $this->assertSame(1, $this->engine->accountDocument($headId2)['state']);
        $vat2 = $this->lineByPrefix($this->journalOf($headId2), '343');
        $this->assertSame('343110', $vat2['account_number']);
        $this->assertEqualsWithDelta(105.0, (float) $vat2['money_dr'], 0.001);
    }

    /**
     * W2: neznámý tuzemský kód spadne na syntetickou masku 343 (fallback
     * s query vat_code_country = cz) — chová se jako dřív.
     */
    public function testUnknownDomesticCodeFallsBackToSynthetic343(): void
    {
        $headId = $this->insertHead('invno', [
            'total_base' => 100.0, 'total_vat' => 21.0, 'total_amount' => 121.0,
            'total_base_dom' => 100.0, 'total_vat_dom' => 21.0, 'total_amount_dom' => 121.0,
        ]);
        $this->insertRow($headId, 'sale.services', 100.0, 21.0, ['vat_code' => 'cz-999']);
        $this->insertRecap($headId, 100.0, 21.0, ['vat_code' => 'cz-999']);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(1, $result['state']);
        $vat = $this->lineByPrefix($this->journalOf($headId), '343');
        // fallback maska 343 → první aktivní analytika dle čísla
        $this->assertSame(0, (int) $vat['is_error']);
        $this->assertEqualsWithDelta(21.0, (float) $vat['money_cr'], 0.001);
    }

    /**
     * W2: zahraniční kód bez mapování skončí hlasitě — chybový řádek
     * 343???, account_not_found, state 2. Žádné tiché smíchání cizí
     * DPH s tuzemskou na 343.
     */
    public function testForeignCodeWithoutMappingFailsLoudly(): void
    {
        $headId = $this->insertHead('invno', [
            'total_base' => 1000.0, 'total_vat' => 190.0, 'total_amount' => 1190.0,
            'total_base_dom' => 1000.0, 'total_vat_dom' => 190.0, 'total_amount_dom' => 1190.0,
        ]);
        $this->insertRow($headId, 'sale.services', 1000.0, 19.0, ['vat_code' => 'de-120']);
        $this->insertRecap($headId, 1000.0, 190.0, ['vat_code' => 'de-120', 'vat_pct' => 19.0]);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(2, $result['state']);
        $this->assertSame(2, $this->accountingState($headId));
        $codes = array_column($result['messages'], 'code');
        $this->assertContains('account_not_found', $codes);

        $journal = $this->journalOf($headId);
        $errorLines = array_values(array_filter($journal, fn($l) => (int) $l['is_error'] === 1));
        $this->assertCount(1, $errorLines);
        $this->assertSame('343???', $errorLines[0]['account_number']);
        $this->assertNull($errorLines[0]['account']);
        $this->assertEqualsWithDelta(190.0, (float) $errorLines[0]['money_cr'], 0.001);
    }

    public function testInvnoForeignCurrencyBothAmountSetsBalance(): void
    {
        $rate = 25.285;
        // Hodnoty konzistentní s Fází 1: dom = round(cur × rate), rounding_dom odvozeně 0
        $headId = $this->insertHead('invno', [
            'doc_currency'     => 'eur',
            'exchange_rate'    => $rate,
            'total_base'       => 100.0,  'total_base_dom'   => 2528.50,
            'total_vat'        => 21.0,   'total_vat_dom'    => 530.99,
            'total_amount'     => 121.0,  'total_amount_dom' => 3059.49,
        ]);
        $this->insertRow($headId, 'sale.services', 100.0, 21.0, [
            'vat_base_dom' => 2528.50, 'vat_amount_dom' => 530.99, 'vat_total_dom' => 3059.49,
        ]);
        $this->insertRecap($headId, 100.0, 21.0, ['base_dom' => 2528.50, 'tax_dom' => 530.99]);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(1, $result['state']);
        $journal = $this->journalOf($headId);
        $this->assertCount(3, $journal);
        $this->assertBalanced($journal);

        $revenue = $this->lineByPrefix($journal, '602');
        $this->assertEqualsWithDelta(2528.50, (float) $revenue['money_cr'], 0.001);
        $this->assertEqualsWithDelta(100.0, (float) $revenue['money_cr_cur'], 0.001);
        $this->assertSame('eur', $revenue['currency']);

        $receivable = $this->lineByPrefix($journal, '311');
        $this->assertEqualsWithDelta(3059.49, (float) $receivable['money_dr'], 0.001);
        $this->assertEqualsWithDelta(121.0, (float) $receivable['money_dr_cur'], 0.001);
    }

    public function testRoundingPositiveGoesTo648(): void
    {
        // 999.80 + 209.96 = 1209.76 → zaokrouhleno 1210.00, rounding +0.24
        $headId = $this->insertHead('invno', [
            'total_base' => 999.80, 'total_vat' => 209.96,
            'total_amount' => 1210.0, 'total_rounding' => 0.24,
            'total_base_dom' => 999.80, 'total_vat_dom' => 209.96,
            'total_amount_dom' => 1210.0, 'total_rounding_dom' => 0.24,
        ]);
        $this->insertRow($headId, 'sale.services', 999.80, 21.0);
        $this->insertRecap($headId, 999.80, 209.96);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(1, $result['state']);
        $journal = $this->journalOf($headId);
        $this->assertBalanced($journal);
        $rounding = $this->lineByPrefix($journal, '648');
        $this->assertEqualsWithDelta(0.24, (float) $rounding['money_cr'], 0.001);
    }

    public function testRoundingNegativeReverseSignGoesTo548(): void
    {
        // 1000.41 + 210.08 = 1210.49 → zaokrouhleno 1210.00, rounding −0.49
        $headId = $this->insertHead('invno', [
            'total_base' => 1000.41, 'total_vat' => 210.08,
            'total_amount' => 1210.0, 'total_rounding' => -0.49,
            'total_base_dom' => 1000.41, 'total_vat_dom' => 210.08,
            'total_amount_dom' => 1210.0, 'total_rounding_dom' => -0.49,
        ]);
        $this->insertRow($headId, 'sale.services', 1000.41, 21.0);
        $this->insertRecap($headId, 1000.41, 210.08);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(1, $result['state']);
        $journal = $this->journalOf($headId);
        $this->assertBalanced($journal);
        // reverseSign: −0.49 → MD +0.49 na zaokrouhlení-náklad
        $rounding = $this->lineByPrefix($journal, '548');
        $this->assertEqualsWithDelta(0.49, (float) $rounding['money_dr'], 0.001);
    }

    public function testAccEntryUsesItemAccount(): void
    {
        $account = $this->db->fetchRow(
            'SELECT id, number FROM economy_accounting_accounts
             WHERE account_level = 4 AND number LIKE %s ORDER BY number LIMIT 1',
            '568%',
        );
        if ($account === null) {
            $account = $this->db->fetchRow(
                'SELECT id, number FROM economy_accounting_accounts WHERE account_level = 4 ORDER BY number LIMIT 1',
            );
        }
        $itemId = $this->insertAccEntryItem((int) $account['id']);

        $headId = $this->insertHead('invno', [
            'total_base' => 1050.0, 'total_vat' => 220.5, 'total_amount' => 1270.5,
            'total_base_dom' => 1050.0, 'total_vat_dom' => 220.5, 'total_amount_dom' => 1270.5,
        ]);
        $this->insertRow($headId, 'sale.services', 1000.0, 21.0);
        $this->insertRow($headId, 'acc.entry', 50.0, 21.0, ['item' => $itemId]);
        $this->insertRecap($headId, 1050.0, 220.5);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(1, $result['state']);
        $journal = $this->journalOf($headId);
        $this->assertBalanced($journal);

        $itemLine = $this->lineByPrefix($journal, (string) $account['number']);
        $this->assertEqualsWithDelta(50.0, (float) $itemLine['money_cr'], 0.001);
        $this->assertSame((int) $account['id'], (int) $itemLine['account']);
    }

    public function testAccEntryWithoutItemAccountProducesErrorRow(): void
    {
        $itemId = $this->insertAccEntryItem(null);

        $headId = $this->insertHead('invno', [
            'total_base' => 50.0, 'total_vat' => 10.5, 'total_amount' => 60.5,
            'total_base_dom' => 50.0, 'total_vat_dom' => 10.5, 'total_amount_dom' => 60.5,
        ]);
        $this->insertRow($headId, 'acc.entry', 50.0, 21.0, ['item' => $itemId]);
        $this->insertRecap($headId, 50.0, 10.5);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(2, $result['state']);
        $this->assertSame(2, $this->accountingState($headId));
        $codes = array_column($result['messages'], 'code');
        $this->assertContains('item_account_missing', $codes);

        $journal = $this->journalOf($headId);
        $errorLines = array_values(array_filter($journal, fn($l) => (int) $l['is_error'] === 1));
        $this->assertCount(1, $errorLines);
        $this->assertSame('??????', $errorLines[0]['account_number']);
        $this->assertNull($errorLines[0]['account']);
        // ostatní řádky (DPH, pohledávka) zapsané i tak
        $this->assertGreaterThan(1, count($journal));
    }

    public function testMissingAccountInChartProducesMaskedErrorRow(): void
    {
        $dibi = $this->db->getDibiConnection();
        // Dočasně archivuj všechny analytické 311* — engine masku nedohledá
        $affected = $this->db->fetchAll(
            'SELECT id, docState FROM economy_accounting_accounts WHERE number LIKE %s AND account_level = 4',
            '311%',
        );
        $dibi->update('economy_accounting_accounts', ['docState' => 90])
            ->where('number LIKE %s AND account_level = 4', '311%')->execute();

        try {
            $headId = $this->insertHead('invno', [
                'total_base' => 1000.0, 'total_vat' => 210.0, 'total_amount' => 1210.0,
                'total_base_dom' => 1000.0, 'total_vat_dom' => 210.0, 'total_amount_dom' => 1210.0,
            ]);
            $this->insertRow($headId, 'sale.services', 1000.0, 21.0);
            $this->insertRecap($headId, 1000.0, 210.0);

            $result = $this->engine->accountDocument($headId);

            $this->assertSame(2, $result['state']);
            $codes = array_column($result['messages'], 'code');
            $this->assertContains('account_not_found', $codes);

            $journal = $this->journalOf($headId);
            $masked = $this->lineByPrefix($journal, '311');
            $this->assertSame('311???', $masked['account_number']);
            $this->assertSame(1, (int) $masked['is_error']);
            $this->assertNull($masked['account']);
            // ostatní řádky zapsané
            $this->assertCount(3, $journal);
        } finally {
            foreach ($affected as $acc) {
                $a = is_array($acc) ? $acc : $acc->toArray();
                $dibi->update('economy_accounting_accounts', ['docState' => $a['docState']])
                    ->where('id = %i', $a['id'])->execute();
            }
        }
    }

    public function testGroupingSumsSameAccountPartnerOperation(): void
    {
        $headId = $this->insertHead('invno', [
            'total_base' => 300.0, 'total_vat' => 63.0, 'total_amount' => 363.0,
            'total_base_dom' => 300.0, 'total_vat_dom' => 63.0, 'total_amount_dom' => 363.0,
        ]);
        $this->insertRow($headId, 'sale.services', 100.0, 21.0);
        $this->insertRow($headId, 'sale.services', 200.0, 21.0);
        $this->insertRecap($headId, 300.0, 63.0);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(1, $result['state']);
        $journal = $this->journalOf($headId);
        $this->assertCount(3, $journal, 'Dva řádky sale.services se měly sloučit do jednoho');
        $revenue = $this->lineByPrefix($journal, '602');
        $this->assertEqualsWithDelta(300.0, (float) $revenue['money_cr'], 0.001);
    }

    public function testSecondRunIsIdempotent(): void
    {
        $headId = $this->insertHead('invno', [
            'total_base' => 1000.0, 'total_vat' => 210.0, 'total_amount' => 1210.0,
            'total_base_dom' => 1000.0, 'total_vat_dom' => 210.0, 'total_amount_dom' => 1210.0,
        ]);
        $this->insertRow($headId, 'sale.services', 1000.0, 21.0);
        $this->insertRecap($headId, 1000.0, 210.0);

        $this->engine->accountDocument($headId);
        $first = $this->journalOf($headId);
        $this->engine->accountDocument($headId);
        $second = $this->journalOf($headId);

        $this->assertCount(count($first), $second, 'Druhý běh nesmí zdvojit řádky');
        $this->assertSame(1, $this->accountingState($headId));
    }

    public function testClearDocumentRemovesJournalAndResetsState(): void
    {
        $headId = $this->insertHead('invno', [
            'total_base' => 1000.0, 'total_vat' => 210.0, 'total_amount' => 1210.0,
            'total_base_dom' => 1000.0, 'total_vat_dom' => 210.0, 'total_amount_dom' => 1210.0,
        ]);
        $this->insertRow($headId, 'sale.services', 1000.0, 21.0);
        $this->insertRecap($headId, 1000.0, 210.0);
        $this->engine->accountDocument($headId);
        $this->assertNotSame([], $this->journalOf($headId));

        $this->engine->clearDocument($headId);

        $this->assertSame([], $this->journalOf($headId));
        $this->assertSame(0, $this->accountingState($headId));
    }

    public function testMissingFiscalPeriodSkipsJournal(): void
    {
        $headId = $this->insertHead('invno', [
            'fiscal_year' => null, 'fiscal_month' => null,
            'total_base' => 1000.0, 'total_vat' => 210.0, 'total_amount' => 1210.0,
            'total_base_dom' => 1000.0, 'total_vat_dom' => 210.0, 'total_amount_dom' => 1210.0,
        ]);
        $this->insertRow($headId, 'sale.services', 1000.0, 21.0);
        $this->insertRecap($headId, 1000.0, 210.0);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(2, $result['state']);
        $this->assertSame('fiscal_period_missing', $result['messages'][0]['code']);
        $this->assertSame([], $this->journalOf($headId));
    }

    public function testReaccountEndpointReturnsStateAndMessages(): void
    {
        $headId = $this->insertHead('invno', [
            'total_base' => 1000.0, 'total_vat' => 210.0, 'total_amount' => 1210.0,
            'total_base_dom' => 1000.0, 'total_vat_dom' => 210.0, 'total_amount_dom' => 1210.0,
        ]);
        $this->insertRow($headId, 'sale.services', 1000.0, 21.0);
        $this->insertRecap($headId, 1000.0, 210.0);

        $ctrl = new \Shipard\Module\Economy\Accounting\AccountingController(
            $this->db,
            ConfigRuntime::load($this->realDsPath, 'cs'),
        );

        $request = \Shipard\Api\Request::fromArray(
            'POST', '/_accounting/reaccount', [], json_encode(['docId' => $headId]), [],
        );
        $response = $ctrl->reaccount($request);
        $payload = $response->getPayload()['data'];

        $this->assertSame(1, $payload['accountingState']);
        $this->assertSame([], $payload['messages']);
        $this->assertCount(3, $this->journalOf($headId));

        // doklad mimo 40 → 422
        $this->db->getDibiConnection()->update('docs_core_heads', ['docState' => 80])
            ->where('id = %i', $headId)->execute();
        $denied = $ctrl->reaccount(\Shipard\Api\Request::fromArray(
            'POST', '/_accounting/reaccount', [], json_encode(['docId' => $headId]), [],
        ));
        $this->assertSame(422, $this->responseStatus($denied));

        // neexistující doklad → 404
        $missing = $ctrl->reaccount(\Shipard\Api\Request::fromArray(
            'POST', '/_accounting/reaccount', [], json_encode(['docId' => 999999999]), [],
        ));
        $this->assertSame(404, $this->responseStatus($missing));
    }

    private function responseStatus(\Shipard\Api\Response $response): int
    {
        $ref = new \ReflectionClass($response);
        return $ref->getProperty('status')->getValue($response);
    }

    public function testAccountingErrorsCheckFindsErrorDoc(): void
    {
        $headId = $this->insertHead('invno', [
            'accounting_state'    => 2,
            'accounting_messages' => json_encode([
                ['code' => 'account_not_found', 'message' => 'Účet nenalezen pro masku 311', 'rowId' => null],
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $check = new \Shipard\Module\Economy\Accounting\Checks\AccountingErrorsCheck(
            $this->db,
            ConfigRuntime::load($this->realDsPath, 'cs'),
            'cs',
        );
        $findings = $check->run();

        $ours = array_values(array_filter(
            $findings,
            fn($f) => $f->findingKey === (string) $headId,
        ));
        $this->assertCount(1, $ours);
        $this->assertStringContainsString('chybu účtování', $ours[0]->title);
        $this->assertStringContainsString('Účet nenalezen pro masku 311', $ours[0]->message);
        $this->assertSame($headId, $ours[0]->subjectRowId);

        // Doklad mimo 40 / bez chyby se nehlásí
        $this->db->getDibiConnection()->update('docs_core_heads', ['accounting_state' => 1])
            ->where('id = %i', $headId)->execute();
        $after = array_filter(
            $check->run(),
            fn($f) => $f->findingKey === (string) $headId,
        );
        $this->assertCount(0, $after);
    }

    /**
     * Hotovo-když #5: alfanumerický účet (OSS konvence 343{CC}{NNN})
     * projde deriveStructure, maska ho dohledá a řádek deníku ho unese.
     */
    public function testAlphanumericAccountWorksEndToEnd(): void
    {
        $dibi = $this->db->getDibiConnection();
        $structure = \Shipard\Module\Economy\Accounting\AccountDocument::deriveStructure('343DE123');
        $this->assertSame(4, $structure['account_level']);
        $this->assertSame('343', $structure['g3']);

        $dibi->insert('economy_accounting_accounts', array_merge($structure, [
            'number'       => '343DE123',
            'name'         => 'DPH OSS DE základní (IT test)',
            'short_name'   => 'DPH OSS DE',
            'account_kind' => 1,
            'docState'     => 40,
            'docStateMain' => 3,
        ]))->execute();
        $accountId = (int) $dibi->getInsertId();

        try {
            // maska 343DE123 účet dohledá (LIKE, ci kolace)
            $resolver = new \Shipard\Module\Economy\Accounting\AccountMaskResolver($dibi);
            $found = $resolver->resolve('343DE123', self::ACC_DATE);
            $this->assertNotNull($found, 'AccountMaskResolver musí alfanumerický účet najít');
            $this->assertSame('343DE123', $found['number']);
            $this->assertSame($accountId, $found['id']);

            // řádek deníku ho unese (varchar 12) — přes acc.entry položku
            $itemId = $this->insertAccEntryItem($accountId);
            $headId = $this->insertHead('invno', [
                'total_base' => 50.0, 'total_vat' => 10.5, 'total_amount' => 60.5,
                'total_base_dom' => 50.0, 'total_vat_dom' => 10.5, 'total_amount_dom' => 60.5,
            ]);
            $this->insertRow($headId, 'acc.entry', 50.0, 21.0, ['item' => $itemId]);
            $this->insertRecap($headId, 50.0, 10.5);

            $result = $this->engine->accountDocument($headId);

            $this->assertSame(1, $result['state']);
            $line = $this->lineByPrefix($this->journalOf($headId), '343DE123');
            $this->assertSame($accountId, (int) $line['account']);
            $this->assertEqualsWithDelta(50.0, (float) $line['money_cr'], 0.001);
        } finally {
            $dibi->delete('economy_accounting_accounts')->where('id = %i', $accountId)->execute();
        }
    }

    private function accountId(string $number): int
    {
        $row = $this->db->fetchRow(
            'SELECT id FROM economy_accounting_accounts WHERE number = %s LIMIT 1',
            $number,
        );
        if ($row === null) {
            $this->markTestSkipped("Dev DS nemá účet {$number}");
        }
        return (int) $row['id'];
    }

    /**
     * Vloží kontační řádek účetního dokladu (cmnbkp): bez DPH, částka v
     * vat_base(_dom), strana + účet + identita přes $overrides.
     */
    private function insertAccRow(int $headId, string $operation, float $amount, int $side, array $overrides = []): int
    {
        return $this->insertRow($headId, $operation, $amount, 0.0, array_merge([
            'vat_code' => null,
            'vat_pct'  => null,
            'acc_side' => $side,
        ], $overrides));
    }

    // ── Účetní doklad (cmnbkp) ──────────────────────────────────────────────

    public function testCmnbkpAccountRecordBalancedWithPerRowIdentity(): void
    {
        // 518100 MD / 321100 DAL, 1000 Kč. Hlavička bez partnera; partner +
        // VS jen na DAL řádku (závazek) — engine je razítkuje per řádek.
        $headId = $this->insertHead('cmnbkp', [
            'partner'         => null,
            'payment_reference' => null,
            'total_base'      => 1000.0, 'total_vat' => 0.0, 'total_amount' => 1000.0,
            'total_base_dom'  => 1000.0, 'total_vat_dom' => 0.0, 'total_amount_dom' => 1000.0,
        ]);
        $partnerId = $this->anyPartnerId();
        $this->insertAccRow($headId, 'acc.record', 1000.0, 0, [
            'account' => $this->accountId('518100'), 'order_pos' => 0,
        ]);
        $this->insertAccRow($headId, 'acc.record', 1000.0, 1, [
            'account' => $this->accountId('321100'), 'order_pos' => 1,
            'partner' => $partnerId, 'payment_reference' => 'VS12345',
        ]);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(1, $result['state'], json_encode($result['messages']));
        $this->assertSame([], $result['messages']);

        $journal = $this->journalOf($headId);
        $this->assertCount(2, $journal);
        $this->assertBalanced($journal);

        $md = $this->lineByPrefix($journal, '518');
        $this->assertEqualsWithDelta(1000.0, (float) $md['money_dr'], 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $md['money_cr'], 0.001);
        // Nákladový řádek bez partnera (řádek partnera nenese) — null.
        $this->assertNull($md['partner']);
        $this->assertNull($md['payment_reference']);

        $dal = $this->lineByPrefix($journal, '321');
        $this->assertEqualsWithDelta(1000.0, (float) $dal['money_cr'], 0.001);
        $this->assertSame($partnerId, (int) $dal['partner']);
        $this->assertSame('VS12345', (string) $dal['payment_reference']);
    }

    /**
     * Dimenze deníku `asset` (assets D47): karta z řádku dokladu se propíše
     * do řádku deníku a dvě karty na tomtéž účtu se neslijí.
     */
    public function testCmnbkpRowAssetReachesJournalAsDimension(): void
    {
        $columns = $this->db->fetchAll('SHOW COLUMNS FROM economy_accounting_journal LIKE %s', 'asset');
        if ($columns === []) {
            $this->markTestSkipped('Dev DS nemá modul economy.assets (dimenze asset)');
        }

        $headId = $this->insertHead('cmnbkp', [
            'partner'        => null,
            'total_base'     => 1500.0, 'total_vat' => 0.0, 'total_amount' => 1500.0,
            'total_base_dom' => 1500.0, 'total_vat_dom' => 0.0, 'total_amount_dom' => 1500.0,
        ]);
        $cost = $this->accountId('518100');
        $liability = $this->accountId('321100');
        // Dimenze nemá FK — smyšlená id karet stačí.
        $this->insertAccRow($headId, 'acc.record', 1000.0, 0, ['account' => $cost, 'asset' => 990001]);
        $this->insertAccRow($headId, 'acc.record', 500.0, 0, ['account' => $cost, 'asset' => 990002]);
        $this->insertAccRow($headId, 'acc.record', 1500.0, 1, ['account' => $liability]);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(1, $result['state']);
        $journal = $this->journalOf($headId);
        $this->assertCount(3, $journal);
        $byAsset = [];
        foreach ($journal as $line) {
            $byAsset[(string) ($line['asset'] ?? '')] = (float) $line['money_dr'] + (float) $line['money_cr'];
        }
        $this->assertSame(['990001' => 1000.0, '990002' => 500.0, '' => 1500.0], $byAsset);
    }

    public function testCmnbkpSameAccountDifferentVsNotMerged(): void
    {
        // Dva závazky na 321100, různý VS → dva řádky deníku (D7), ne sloučený.
        $headId = $this->insertHead('cmnbkp', [
            'partner'        => null,
            'total_base'     => 300.0, 'total_vat' => 0.0, 'total_amount' => 300.0,
            'total_base_dom' => 300.0, 'total_vat_dom' => 0.0, 'total_amount_dom' => 300.0,
        ]);
        $partnerId = $this->anyPartnerId();
        $acc321 = $this->accountId('321100');
        $this->insertAccRow($headId, 'acc.record', 300.0, 0, [
            'account' => $this->accountId('518100'), 'order_pos' => 0,
        ]);
        $this->insertAccRow($headId, 'acc.record', 100.0, 1, [
            'account' => $acc321, 'order_pos' => 1, 'partner' => $partnerId, 'payment_reference' => 'AAA',
        ]);
        $this->insertAccRow($headId, 'acc.record', 200.0, 1, [
            'account' => $acc321, 'order_pos' => 2, 'partner' => $partnerId, 'payment_reference' => 'BBB',
        ]);

        $this->engine->accountDocument($headId);
        $journal = $this->journalOf($headId);

        $dal321 = array_values(array_filter(
            $journal,
            fn($l) => str_starts_with((string) $l['account_number'], '321'),
        ));
        $this->assertCount(2, $dal321, 'Dva závazky s různým VS se nesmí slít do jednoho řádku deníku');
        $this->assertCount(3, $journal);
        $this->assertBalanced($journal);

        $vs = array_map(fn($l) => (string) $l['payment_reference'], $dal321);
        sort($vs);
        $this->assertSame(['AAA', 'BBB'], $vs);
    }

    public function testCmnbkpAccountRecordOnArchivedAccountBooks(): void
    {
        // Linkable states: historický doklad smí účtovat na archivní účet
        // (70) — vzor DS A 221xxx (zrušené termínované vklady).
        $archivedId = $this->insertTempAccount('899IT901', 70, 4);
        $headId = $this->insertHead('cmnbkp', [
            'partner'        => null,
            'total_base'     => 100.0, 'total_vat' => 0.0, 'total_amount' => 100.0,
            'total_base_dom' => 100.0, 'total_vat_dom' => 0.0, 'total_amount_dom' => 100.0,
        ]);
        $this->insertAccRow($headId, 'acc.record', 100.0, 0, [
            'account' => $archivedId, 'order_pos' => 0,
        ]);
        $this->insertAccRow($headId, 'acc.record', 100.0, 1, [
            'account' => $this->accountId('321100'), 'order_pos' => 1,
        ]);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(1, $result['state'], json_encode($result['messages']));
        $journal = $this->journalOf($headId);
        $this->assertCount(2, $journal);
        $this->assertBalanced($journal);

        $line = $this->lineByPrefix($journal, '899IT901');
        $this->assertSame($archivedId, (int) $line['account']);
        $this->assertEqualsWithDelta(100.0, (float) $line['money_dr'], 0.001);
    }

    public function testCmnbkpAccountRecordOnDeletedAccountFailsLoudly(): void
    {
        // Smazaný účet (90) je jediný neodkazovatelný stav — chybový řádek.
        $deletedId = $this->insertTempAccount('899IT902', 90, 5);
        $headId = $this->insertHead('cmnbkp', [
            'partner'        => null,
            'total_base'     => 100.0, 'total_vat' => 0.0, 'total_amount' => 100.0,
            'total_base_dom' => 100.0, 'total_vat_dom' => 0.0, 'total_amount_dom' => 100.0,
        ]);
        $this->insertAccRow($headId, 'acc.record', 100.0, 0, [
            'account' => $deletedId, 'order_pos' => 0,
        ]);
        $this->insertAccRow($headId, 'acc.record', 100.0, 1, [
            'account' => $this->accountId('321100'), 'order_pos' => 1,
        ]);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(2, $result['state']);
        $codes = array_column($result['messages'], 'code');
        $this->assertContains('row_account_missing', $codes);

        $errorLines = array_values(array_filter(
            $this->journalOf($headId),
            fn($l) => (int) $l['is_error'] === 1,
        ));
        $this->assertCount(1, $errorLines);
        $this->assertSame('??????', $errorLines[0]['account_number']);
        $this->assertNull($errorLines[0]['account']);
    }

    public function testCmnbkpAccountFromItem(): void
    {
        // acc.item: účet přijde z položky typu 2 (Účetní položka).
        $headId = $this->insertHead('cmnbkp', [
            'partner'        => null,
            'total_base'     => 500.0, 'total_vat' => 0.0, 'total_amount' => 500.0,
            'total_base_dom' => 500.0, 'total_vat_dom' => 0.0, 'total_amount_dom' => 500.0,
        ]);
        $itemId = $this->insertAccEntryItem($this->accountId('518100'));
        $this->insertAccRow($headId, 'acc.item', 500.0, 0, ['item' => $itemId, 'order_pos' => 0]);
        $this->insertAccRow($headId, 'acc.record', 500.0, 1, [
            'account' => $this->accountId('321100'), 'order_pos' => 1,
        ]);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(1, $result['state'], json_encode($result['messages']));
        $journal = $this->journalOf($headId);
        $this->assertCount(2, $journal);
        $this->assertBalanced($journal);
        $md = $this->lineByPrefix($journal, '518');
        $this->assertEqualsWithDelta(500.0, (float) $md['money_dr'], 0.001);
    }

    public function testCmnbkpFxLossReceivableTwoLinesWithIdentity(): void
    {
        // Kurzová ztráta — pohledávka (D12): jeden řádek → dva zápisy
        // MD 563xxx (fx.loss) / DAL 311xxx (receivables), strany fixní
        // z kroků předpisu, identita (partner, payment_reference = staré
        // symbol1) na obou. Vzor ze zdroje: DS B doc 719.
        $this->requireAccountWithPrefix('563');
        $this->requireAccountWithPrefix('311');
        $headId = $this->insertHead('cmnbkp', [
            'partner'           => null,
            'payment_reference' => null,
            'total_base'      => 50806.73, 'total_vat' => 0.0, 'total_amount' => 50806.73,
            'total_base_dom'  => 50806.73, 'total_vat_dom' => 0.0, 'total_amount_dom' => 50806.73,
        ]);
        $partnerId = $this->anyPartnerId();
        // FX řádek nenese stranu (rowSide: 0 — form volbu nenabízí).
        $this->insertAccRow($headId, 'acc.fxLossReceivable', 50806.73, 0, [
            'acc_side' => null, 'order_pos' => 0,
            'partner' => $partnerId, 'payment_reference' => '1300001',
        ]);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(1, $result['state'], json_encode($result['messages']));
        $this->assertSame([], $result['messages']);

        $journal = $this->journalOf($headId);
        $this->assertCount(2, $journal);
        $this->assertBalanced($journal);

        $md = $this->lineByPrefix($journal, '563');
        $this->assertEqualsWithDelta(50806.73, (float) $md['money_dr'], 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $md['money_cr'], 0.001);

        $dal = $this->lineByPrefix($journal, '311');
        $this->assertEqualsWithDelta(50806.73, (float) $dal['money_cr'], 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $dal['money_dr'], 0.001);

        // Identita na OBOU zápisech — saldo strana je párovatelná accbal
        // FX fází, P&L strana dohledatelná k případu.
        foreach ([$md, $dal] as $line) {
            $this->assertSame($partnerId, (int) $line['partner']);
            $this->assertSame('1300001', (string) $line['payment_reference']);
        }
    }

    public function testCmnbkpFxGainPayableMirrorsSides(): void
    {
        // Zrcadlo: kurzový zisk — závazek → MD 321xxx (payables) /
        // DAL 663xxx (fx.gain), identita na obou.
        $this->requireAccountWithPrefix('663');
        $this->requireAccountWithPrefix('321');
        $headId = $this->insertHead('cmnbkp', [
            'partner'           => null,
            'payment_reference' => null,
            'total_base'      => 1234.56, 'total_vat' => 0.0, 'total_amount' => 1234.56,
            'total_base_dom'  => 1234.56, 'total_vat_dom' => 0.0, 'total_amount_dom' => 1234.56,
        ]);
        $partnerId = $this->anyPartnerId();
        $this->insertAccRow($headId, 'acc.fxGainPayable', 1234.56, 0, [
            'acc_side' => null, 'order_pos' => 0,
            'partner' => $partnerId, 'payment_reference' => '2400007',
        ]);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(1, $result['state'], json_encode($result['messages']));
        $journal = $this->journalOf($headId);
        $this->assertCount(2, $journal);
        $this->assertBalanced($journal);

        $md = $this->lineByPrefix($journal, '321');
        $this->assertEqualsWithDelta(1234.56, (float) $md['money_dr'], 0.001);
        $dal = $this->lineByPrefix($journal, '663');
        $this->assertEqualsWithDelta(1234.56, (float) $dal['money_cr'], 0.001);

        foreach ([$md, $dal] as $line) {
            $this->assertSame($partnerId, (int) $line['partner']);
            $this->assertSame('2400007', (string) $line['payment_reference']);
        }
    }

    /**
     * FX kategorie dohledávají analytiku maskou per DS — test potřebuje
     * v rozvrhu aspoň jeden analytický účet s daným prefixem, jinak skip.
     */
    private function requireAccountWithPrefix(string $prefix): void
    {
        $row = $this->db->fetchRow(
            'SELECT id FROM economy_accounting_accounts WHERE number LIKE %s'
            . ' AND account_level = 4 LIMIT 1',
            $prefix . '%',
        );
        if ($row === null) {
            $this->markTestSkipped("Dev DS nemá analytický účet {$prefix}*");
        }
    }

    /** Dočasný účet rozvrhu (alfanumerický prefix 899IT — v reálném rozvrhu neexistuje). */
    private function insertTempAccount(string $number, int $docState, int $docStateMain): int
    {
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('economy_accounting_accounts', array_merge(
            \Shipard\Module\Economy\Accounting\AccountDocument::deriveStructure($number),
            [
                'number'       => $number,
                'name'         => "IT test účet {$number}",
                'short_name'   => $number,
                'account_kind' => 1,
                'docState'     => $docState,
                'docStateMain' => $docStateMain,
            ],
        ))->execute();
        $id = (int) $dibi->getInsertId();
        $this->createdAccounts[] = $id;
        return $id;
    }

    private function insertAccEntryItem(?int $accountId): int
    {
        $kind = $this->db->fetchRow('SELECT id FROM economy_items_kinds ORDER BY id LIMIT 1');
        $unit = $this->db->fetchRow('SELECT id FROM core_units ORDER BY id LIMIT 1');
        if ($kind === null || $unit === null) {
            $this->markTestSkipped('Dev DS nemá druhy položek / jednotky');
        }

        $dibi = $this->db->getDibiConnection();
        $dibi->insert('economy_items', [
            'code'               => 'IT-ACC-' . uniqid(),
            'name'               => 'IT účetní položka',
            'item_kind'          => (int) $kind['id'],
            'unit'               => (int) $unit['id'],
            'item_type'          => 2,
            'accounting_account' => $accountId,
        ])->execute();
        $id = (int) $dibi->getInsertId();
        $this->createdItems[] = $id;
        return $id;
    }
}

/** Spy journal handler — zaznamenává (sourceKind, sourceId) staticky. */
class AcctJournalSpy extends AbstractJournalEventHandler
{
    /** @var list<array{0: string, 1: int}> */
    public static array $calls = [];

    public function onJournalWritten(string $sourceKind, int $sourceId): void
    {
        self::$calls[] = [$sourceKind, $sourceId];
    }
}

/** Vyhazuje výjimku — ověřuje, že nespadne účtování. */
class ThrowingAcctJournalSpy extends AbstractJournalEventHandler
{
    public function onJournalWritten(string $sourceKind, int $sourceId): void
    {
        throw new \RuntimeException('saldo boom');
    }
}

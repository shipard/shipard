<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Accounting;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Docs\Core\BoundNumberSeriesProvisioner;
use Shipard\Module\Economy\Accounting\AccountDocument;
use Shipard\Module\Economy\Accounting\AccountingEngine;
use Shipard\Module\Economy\Bank\BankTransactionAccountingEngine;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Převody peněz pokladna ↔ banka ↔ pokladna (#59 Task D,
 * tasks/cash-transfers.md §3 kontrolní příklady) nad reálným dev DS.
 *
 * Obě strany převodu jdou přes 261100 Peníze na cestě (cash.transit):
 * pokladní doklad s pohybem transfer.* a bankovní transakce s operací
 * transfer.* (nebo pokladní doklad druhé pokladny). Po zaúčtování obou
 * stran má 261100 nulový zůstatek. Karty tranzit nemají (#72 D1): jsou to
 * pohledávky 311 za plátcem, 261100 se jich nedotkne.
 *
 * Pokladny, vázané řady a bankovní účet si test zakládá sám a po sobě je
 * maže; doklady i transakce vkládá přímo SQL (engine čte DB).
 */
class CashTransferAccountingTest extends IntegrationTestCase
{
    private const ACC_DATE = '2026-06-10';

    /** @var list<int> */
    private array $createdHeads = [];
    /** @var list<int> */
    private array $createdTxs = [];
    /** @var list<int> */
    private array $createdCashDesks = [];
    /** @var list<int> */
    private array $createdSeries = [];
    /** @var list<int> */
    private array $createdBankAccounts = [];
    /** @var list<int> účty doplněné do rozvrhu jen pro test */
    private array $createdAccounts = [];

    private ?AccountingEngine $docEngine = null;
    private ?BankTransactionAccountingEngine $bankEngine = null;
    private ?ConfigRuntime $config = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = ConfigRuntime::load($this->realDsPath, 'cs');
        $dibi = $this->db->getDibiConnection();
        $this->docEngine = new AccountingEngine($dibi, $this->config);
        $this->bankEngine = new BankTransactionAccountingEngine($dibi, $this->config);
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
        foreach ($this->createdTxs as $id) {
            $dibi->delete('economy_accounting_journal')->where('bank_transaction = %i', $id)->execute();
            $dibi->delete('economy_bank_transactions')->where('id = %i', $id)->execute();
        }
        foreach ($this->createdSeries as $id) {
            $dibi->delete('docs_core_number_counters')->where('number_series = %i', $id)->execute();
            $dibi->delete('docs_core_number_series')->where('id = %i', $id)->execute();
        }
        foreach ($this->createdCashDesks as $id) {
            $dibi->delete('economy_codebooks_cash_desks')->where('id = %i', $id)->execute();
        }
        foreach ($this->createdBankAccounts as $id) {
            $dibi->delete('economy_codebooks_bank_accounts')->where('id = %i', $id)->execute();
        }
        foreach ($this->createdAccounts as $id) {
            $dibi->delete('economy_accounting_accounts')->where('id = %i', $id)->execute();
        }
    }

    // ── Kontrolní příklady ──────────────────────────────────────────────────

    public function testDisbursementTransferOutBooksTransitAgainstCashDesk(): void
    {
        // Odvod hotovosti do banky 20 000 — výdajový PD, transfer.out:
        // 261100 MD 20 000 / 211 DAL 20 000; řádek bez partnera a bez VS.
        $this->ensureAccountByNumber('261100');
        $deskId = $this->createCashDesk($this->accountIdByPrefix('211'));
        $headId = $this->insertCashHead($deskId, cashDir: 2, amount: 20000.0);
        $this->insertTransferRow($headId, 'transfer.out', 20000.0);

        $result = $this->docEngine->accountDocument($headId);

        $this->assertSame(1, $result['state'], json_encode($result['messages']));
        $journal = $this->journalOfHead($headId);
        $this->assertCount(2, $journal);
        $this->assertBalanced($journal);

        $transit = $this->lineByPrefix($journal, '261100');
        $this->assertEqualsWithDelta(20000.0, (float) $transit['money_dr'], 0.001, 'převod MD 261100');
        $this->assertSame('transfer.out', $transit['operation']);
        $this->assertNull($transit['partner'], 'převod je bez partnera (T3)');
        $this->assertNull($transit['payment_reference'], 'VS nepovinný');

        $this->assertEqualsWithDelta(20000.0, (float) $this->lineByPrefix($journal, '211')['money_cr'], 0.001, 'pokladna DAL');
        $this->assertNoLine($journal, '261400');
        $this->assertNoLine($journal, '343');
    }

    public function testReceiptTransferInCarriesOptionalReference(): void
    {
        // Dotace pokladny z banky 5 000 — příjmový PD, transfer.in:
        // 211 MD 5 000 / 261100 DAL 5 000; payment_reference = identifikace
        // bankovní transakce se razítkuje z řádku (rowPaymentId).
        $this->ensureAccountByNumber('261100');
        $deskId = $this->createCashDesk($this->accountIdByPrefix('211'));
        $headId = $this->insertCashHead($deskId, cashDir: 1, amount: 5000.0);
        $this->insertTransferRow($headId, 'transfer.in', 5000.0, ['payment_reference' => 'TX 2026-06-10 #4711']);

        $result = $this->docEngine->accountDocument($headId);

        $this->assertSame(1, $result['state'], json_encode($result['messages']));
        $journal = $this->journalOfHead($headId);
        $this->assertCount(2, $journal);
        $this->assertBalanced($journal);

        $transit = $this->lineByPrefix($journal, '261100');
        $this->assertEqualsWithDelta(5000.0, (float) $transit['money_cr'], 0.001, 'převod DAL 261100');
        $this->assertSame('transfer.in', $transit['operation']);
        $this->assertSame('TX 2026-06-10 #4711', (string) $transit['payment_reference']);
        $this->assertNull($transit['partner']);

        $this->assertEqualsWithDelta(5000.0, (float) $this->lineByPrefix($journal, '211')['money_dr'], 0.001, 'pokladna MD');
    }

    public function testCashToBankTransferNetsToZeroOnTransit(): void
    {
        // Odvod do banky 20 000: výdajový PD transfer.out + bankovní
        // transakce transfer.in (221 MD / 261100 DAL) → 261100 = 0.
        $this->ensureAccountByNumber('261100');
        $deskId = $this->createCashDesk($this->accountIdByPrefix('211'));
        $headId = $this->insertCashHead($deskId, cashDir: 2, amount: 20000.0);
        $this->insertTransferRow($headId, 'transfer.out', 20000.0);
        $this->assertSame(1, $this->docEngine->accountDocument($headId)['state']);

        $baId = $this->insertBankAccount($this->accountIdByPrefix('221'));
        $txId = $this->insertTx(['bank_account' => $baId, 'direction' => 1, 'operation' => 'transfer.in', 'amount' => 20000.0, 'amount_dom' => 20000.0]);
        $result = $this->bankEngine->accountTransaction($txId);
        $this->assertSame(1, $result['state'], json_encode($result['messages'] ?? null));

        $bankJournal = $this->journalOfTx($txId);
        $this->assertCount(2, $bankJournal);
        $this->assertEqualsWithDelta(20000.0, (float) $this->lineByPrefix($bankJournal, '221')['money_dr'], 0.001, 'banka MD');
        $transit = $this->lineByPrefix($bankJournal, '261100');
        $this->assertEqualsWithDelta(20000.0, (float) $transit['money_cr'], 0.001, 'převod DAL 261100');
        $this->assertSame('transfer.in', $transit['operation']);
        $this->assertNoLine($bankJournal, '2612');
        $this->assertNoLine($bankJournal, '2613');

        $this->assertEqualsWithDelta(0.0, $this->transitBalance('261100'), 0.001, '261100 po obou stranách převodu nulový');
    }

    public function testBankToCashTransferNetsToZeroOnTransit(): void
    {
        // Dotace pokladny z banky 5 000: bankovní transakce transfer.out
        // (261100 MD / 221 DAL) + příjmový PD transfer.in → 261100 = 0.
        $this->ensureAccountByNumber('261100');
        $baId = $this->insertBankAccount($this->accountIdByPrefix('221'));
        $txId = $this->insertTx(['bank_account' => $baId, 'direction' => 2, 'operation' => 'transfer.out', 'amount' => 5000.0, 'amount_dom' => 5000.0]);
        $this->assertSame(1, $this->bankEngine->accountTransaction($txId)['state']);

        $bankJournal = $this->journalOfTx($txId);
        $this->assertEqualsWithDelta(5000.0, (float) $this->lineByPrefix($bankJournal, '261100')['money_dr'], 0.001, 'převod MD 261100');
        $this->assertEqualsWithDelta(5000.0, (float) $this->lineByPrefix($bankJournal, '221')['money_cr'], 0.001, 'banka DAL');

        $deskId = $this->createCashDesk($this->accountIdByPrefix('211'));
        $headId = $this->insertCashHead($deskId, cashDir: 1, amount: 5000.0);
        $this->insertTransferRow($headId, 'transfer.in', 5000.0);
        $this->assertSame(1, $this->docEngine->accountDocument($headId)['state']);

        $this->assertEqualsWithDelta(0.0, $this->transitBalance('261100'), 0.001);
    }

    public function testCashDeskToCashDeskTransferNetsToZeroOnTransit(): void
    {
        // Převod mezi pokladnami A → B 3 000: výdajový PD na A (transfer.out)
        // + příjmový PD na B (transfer.in) → 261100 = 0, obě pokladny 211.
        $this->ensureAccountByNumber('261100');
        $cashAccount = $this->accountIdByPrefix('211');
        $deskA = $this->createCashDesk($cashAccount);
        $deskB = $this->createCashDesk($cashAccount);

        $outId = $this->insertCashHead($deskA, cashDir: 2, amount: 3000.0);
        $this->insertTransferRow($outId, 'transfer.out', 3000.0, ['payment_reference' => 'pokladna B']);
        $inId = $this->insertCashHead($deskB, cashDir: 1, amount: 3000.0);
        $this->insertTransferRow($inId, 'transfer.in', 3000.0, ['payment_reference' => 'pokladna A']);

        $this->assertSame(1, $this->docEngine->accountDocument($outId)['state']);
        $this->assertSame(1, $this->docEngine->accountDocument($inId)['state']);

        $this->assertEqualsWithDelta(3000.0, (float) $this->lineByPrefix($this->journalOfHead($outId), '211')['money_cr'], 0.001);
        $this->assertEqualsWithDelta(3000.0, (float) $this->lineByPrefix($this->journalOfHead($inId), '211')['money_dr'], 0.001);
        $this->assertEqualsWithDelta(0.0, $this->transitBalance('261100'), 0.001);
    }

    public function testCardRetailSaleBooksReceivableNotOnCashTransit(): void
    {
        // Prodejka kartou 1 000 + 21 %: 604 DAL · 343120 DAL · 311 MD 1 210 za
        // plátcem (#72 D1) — peníze na cestě 261100 bez řádku, 261400 už se
        // neúčtuje vůbec.
        $deskId = $this->createCashDesk($this->accountIdByPrefix('211'));
        $partner = $this->db->fetchRow('SELECT id FROM base_persons_persons ORDER BY id LIMIT 1');
        if ($partner === null) {
            $this->markTestSkipped('Dev DS nemá osobu pro plátce');
        }
        $headId = $this->insertHead('cashreg', $deskId, 1000.0, 210.0, [
            'payment_method' => 2, 'partner_balance' => (int) $partner['id'],
        ]);
        $this->insertVatRow($headId, 'sale.goods', 1000.0, 21.0);
        $this->insertRecap($headId, 1000.0, 210.0);

        $result = $this->docEngine->accountDocument($headId);

        $this->assertSame(1, $result['state'], json_encode($result['messages']));
        $journal = $this->journalOfHead($headId);
        $this->assertBalanced($journal);
        $receivable = $this->lineByPrefix($journal, '311');
        $this->assertEqualsWithDelta(1210.0, (float) $receivable['money_dr'], 0.001, 'karta → 311 za plátcem');
        $this->assertSame((int) $partner['id'], (int) $receivable['partner']);
        $this->assertNoLine($journal, '261');
        $this->assertNoLine($journal, '211');
        $this->assertEqualsWithDelta(0.0, $this->transitBalance('261100'), 0.001, '261100 se prodejky kartou nedotkne');
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    /** @return array{0: int, 1: int} [fiscal_year, fiscal_month] */
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

    private function accountIdByPrefix(string $prefix): int
    {
        $row = $this->db->fetchRow(
            'SELECT id FROM economy_accounting_accounts
             WHERE number LIKE %like~ AND account_level = 4 AND docState IN (10,40,80)
             ORDER BY number LIMIT 1',
            $prefix,
        );
        if ($row === null) {
            $this->markTestSkipped("Dev DS nemá analytický účet {$prefix}*");
        }
        return (int) $row['id'];
    }

    /**
     * Účet daného čísla — existující, nebo doplněný jen pro test (DS bez
     * provisioningu nemusí 261100 mít; po ds-upgrade se seedem Task D ho
     * 4l3j má).
     */
    private function ensureAccountByNumber(string $number): int
    {
        $row = $this->db->fetchRow(
            'SELECT id FROM economy_accounting_accounts WHERE number = %s AND docState IN (10,40,80) LIMIT 1',
            $number,
        );
        if ($row !== null) {
            return (int) $row['id'];
        }

        $dibi = $this->db->getDibiConnection();
        $dibi->insert('economy_accounting_accounts', array_merge(AccountDocument::deriveStructure($number), [
            'number'       => $number,
            'name'         => 'IT transit ' . $number,
            'short_name'   => 'IT ' . $number,
            'account_kind' => 0,
            'docState'     => 40,
            'docStateMain' => 3,
        ]))->execute();
        $id = (int) $dibi->getInsertId();
        $this->createdAccounts[] = $id;
        return $id;
    }

    private function createCashDesk(int $accountingAccount): int
    {
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('economy_codebooks_cash_desks', [
            'code'               => 'IT' . substr(uniqid(), -5),
            'name'               => 'IT pokladna převody',
            'currency'           => 'czk',
            'accounting_account' => $accountingAccount,
            'docState'           => 40,
            'docStateMain'       => 3,
        ])->execute();
        $deskId = (int) $dibi->getInsertId();
        $this->createdCashDesks[] = $deskId;

        (new BoundNumberSeriesProvisioner($this->db, $this->config))->provisionForCashDesk($deskId);
        foreach ($this->db->fetchAll('SELECT id FROM docs_core_number_series WHERE cash_desk = %i', $deskId) as $s) {
            $this->createdSeries[] = (int) $s['id'];
        }
        return $deskId;
    }

    private function seriesFor(string $docType, int $deskId): int
    {
        $row = $this->db->fetchRow(
            'SELECT id FROM docs_core_number_series WHERE doc_type = %s AND cash_desk = %i LIMIT 1',
            $docType, $deskId,
        );
        if ($row === null) {
            $this->markTestSkipped("Chybí řada {$docType} pro pokladnu");
        }
        return (int) $row['id'];
    }

    /** Hlavička ve stavu 40 (cash/cashreg) s ručně spočtenými součty. */
    private function insertHead(string $docType, int $deskId, float $base, float $vat, array $overrides = []): int
    {
        [$fy, $fm] = $this->fiscalIds();
        $total = round($base + $vat, 2);
        $head = array_merge([
            'doc_type'         => $docType,
            'number_series'    => $this->seriesFor($docType, $deskId),
            'cash_desk'        => $deskId,
            'cash_dir'         => 0,
            'payment_method'   => 0,
            'doc_number'       => 'IT-TRF-' . uniqid(),
            'issue_date'       => self::ACC_DATE,
            'accounting_date'  => self::ACC_DATE,
            'due_date'         => self::ACC_DATE,
            'fiscal_year'      => $fy,
            'fiscal_month'     => $fm,
            'partner'          => null,
            'doc_currency'     => 'czk',
            'home_currency'    => 'czk',
            'exchange_rate'    => 1.0,
            'doc_text'         => 'IT převod peněz',
            'total_base'       => $base,  'total_vat'     => $vat, 'total_amount'     => $total,
            'total_base_dom'   => $base,  'total_vat_dom' => $vat, 'total_amount_dom' => $total,
            'docState'         => 40,
            'docStateMain'     => 2,
        ], $overrides);

        $dibi = $this->db->getDibiConnection();
        $dibi->insert('docs_core_heads', $head)->execute();
        $id = (int) $dibi->getInsertId();
        $this->createdHeads[] = $id;
        return $id;
    }

    /** Pokladní doklad hotově bez DPH — jediný převodový řádek dává celý součet. */
    private function insertCashHead(int $deskId, int $cashDir, float $amount): int
    {
        return $this->insertHead('cash', $deskId, $amount, 0.0, ['cash_dir' => $cashDir, 'payment_method' => 0]);
    }

    /**
     * Řádek převodu: kontační (price_calc_mode 1, částka přímo), bez DPH
     * (vat_code NULL, mimo rekapitulaci), bez partnera; engine bere částku
     * z vat_base(_dom) stejně jako u payment.*.
     */
    private function insertTransferRow(int $headId, string $operation, float $amount, array $overrides = []): int
    {
        $row = array_merge([
            'doc_head'        => $headId,
            'row_kind'        => 1,
            'operation'       => $operation,
            'description'     => "Řádek {$operation}",
            'price_calc_mode' => 1,
            'total_price'     => $amount,
            'vat_code'        => null,
            'vat_pct'         => 0,
            'vat_base'        => $amount,
            'vat_amount'      => 0,
            'vat_total'       => $amount,
            'vat_base_dom'    => $amount,
            'vat_amount_dom'  => 0,
            'vat_total_dom'   => $amount,
        ], $overrides);
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('docs_core_rows', $row)->execute();
        return (int) $dibi->getInsertId();
    }

    private function insertVatRow(int $headId, string $operation, float $base, float $vatPct): int
    {
        $amount = round($base * $vatPct / 100.0, 2);
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('docs_core_rows', [
            'doc_head'       => $headId,
            'row_kind'       => 1,
            'operation'      => $operation,
            'description'    => "Řádek {$operation}",
            'vat_code'       => 'cz-120',
            'vat_pct'        => $vatPct,
            'vat_base'       => $base,
            'vat_amount'     => $amount,
            'vat_total'      => round($base + $amount, 2),
            'vat_base_dom'   => $base,
            'vat_amount_dom' => $amount,
            'vat_total_dom'  => round($base + $amount, 2),
        ])->execute();
        return (int) $dibi->getInsertId();
    }

    private function insertRecap(int $headId, float $base, float $tax): void
    {
        $this->db->getDibiConnection()->insert('docs_core_vat_recap', [
            'doc_head'  => $headId,  'vat_code' => 'cz-120', 'vat_pct' => 21.0,
            'base'      => $base,    'tax'      => $tax,     'total'   => round($base + $tax, 2),
            'base_dom'  => $base,    'tax_dom'  => $tax,     'total_dom' => round($base + $tax, 2),
            'sum_base'  => 1, 'sum_tax' => 1, 'sum_total' => 1, 'is_reverse_pair' => 0, 'order_pos' => 0,
        ])->execute();
    }

    private function insertBankAccount(int $accountingAccountId): int
    {
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('economy_codebooks_bank_accounts', [
            'code'               => 'IT' . substr(uniqid(), -8),
            'name'               => 'IT bankovní účet převody',
            'currency'           => 'czk',
            'accounting_account' => $accountingAccountId,
            'docState'           => 40,
            'docStateMain'       => 3,
        ])->execute();
        $id = (int) $dibi->getInsertId();
        $this->createdBankAccounts[] = $id;
        return $id;
    }

    /** @param array<string, mixed> $overrides */
    private function insertTx(array $overrides): int
    {
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('economy_bank_transactions', array_merge([
            'currency'          => 'czk',
            'exchange_rate'     => 1,
            'date_transaction'  => self::ACC_DATE,
            'counterparty_name' => 'IT vlastní pokladna',
            'accounting_state'  => 0,
            'docState'          => 40,
            'docStateMain'      => 3,
        ], $overrides))->execute();
        $id = (int) $dibi->getInsertId();
        $this->createdTxs[] = $id;
        return $id;
    }

    // ── Assert helpers ──────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private function journalOfHead(int $headId): array
    {
        $rows = $this->db->fetchAll('SELECT * FROM economy_accounting_journal WHERE doc_head = %i ORDER BY id', $headId);
        return array_map(fn($r) => is_array($r) ? $r : $r->toArray(), $rows);
    }

    /** @return list<array<string, mixed>> */
    private function journalOfTx(int $txId): array
    {
        $rows = $this->db->fetchAll('SELECT * FROM economy_accounting_journal WHERE bank_transaction = %i ORDER BY id', $txId);
        return array_map(fn($r) => is_array($r) ? $r : $r->toArray(), $rows);
    }

    /** Σ MD − Σ DAL účtu napříč deníkem všeho, co test vytvořil (doklady i transakce). */
    private function transitBalance(string $number): float
    {
        $sum = 0.0;
        if ($this->createdHeads !== []) {
            $row = $this->db->fetchRow(
                'SELECT COALESCE(SUM(money_dr),0) - COALESCE(SUM(money_cr),0) AS bal
                 FROM economy_accounting_journal WHERE account_number = %s AND doc_head IN %in',
                $number, $this->createdHeads,
            );
            $sum += (float) $row['bal'];
        }
        if ($this->createdTxs !== []) {
            $row = $this->db->fetchRow(
                'SELECT COALESCE(SUM(money_dr),0) - COALESCE(SUM(money_cr),0) AS bal
                 FROM economy_accounting_journal WHERE account_number = %s AND bank_transaction IN %in',
                $number, $this->createdTxs,
            );
            $sum += (float) $row['bal'];
        }
        return $sum;
    }

    private function lineByPrefix(array $journal, string $prefix): array
    {
        $matches = array_values(array_filter($journal, fn($l) => str_starts_with((string) $l['account_number'], $prefix)));
        $this->assertCount(1, $matches, "Očekáván právě jeden řádek deníku {$prefix}*");
        return $matches[0];
    }

    private function assertNoLine(array $journal, string $prefix): void
    {
        $matches = array_filter($journal, fn($l) => str_starts_with((string) $l['account_number'], $prefix));
        $this->assertCount(0, $matches, "Řádek {$prefix}* nemá existovat");
    }

    private function assertBalanced(array $journal): void
    {
        $dr = array_sum(array_map(fn($l) => (float) $l['money_dr'], $journal));
        $cr = array_sum(array_map(fn($l) => (float) $l['money_cr'], $journal));
        $this->assertEqualsWithDelta($dr, $cr, 0.001, 'MD != DAL');
    }
}

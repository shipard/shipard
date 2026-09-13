<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Accbal;

use Shipard\Api\JournalEventHandlerLoader;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Document\JournalEventDispatcher;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Economy\Accbal\BalanceMatcher;
use Shipard\Module\Economy\Accbal\MatchResult;
use Shipard\Module\Economy\Accounting\AccountDocument;
use Shipard\Module\Economy\Bank\BankTransactionAccountingEngine;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Round-trip matcheru nad reálným DS (seed Fáze 1: receivables 311,
 * unmatched_payments 261200/261300). Ověřuje celý řetězec
 * reaccount → re-derivace ledgeru → zápis allocations a zpětné rozpárování.
 *
 * Bankovní transakce se účtuje přes reálný engine (clearing pohyb vznikne přes
 * journalWritten handler), předpis se seeduje přímo do ledgeru (izolace od
 * generátoru i dokladů).
 */
class BalanceMatcherTest extends IntegrationTestCase
{
    private const ACC_DATE = '2026-06-10';
    private const PARTNER  = 990001;

    private ?JournalEventDispatcher $journalEvents = null;
    private ?ConfigRuntime $config = null;

    /** @var list<int> */
    private array $createdTxs = [];
    /** @var list<int> */
    private array $createdBankAccounts = [];
    /** @var list<int> */
    private array $createdAccounts = [];
    /** @var list<int> seedované předpisové pohyby (doc_head) */
    private array $seededDocs = [];

    private int $seq = 0;

    protected function setUp(): void
    {
        // #69 D3 (T1 bank-payment-routing): matched operace zrušeny, routing
        // dělá engine dohledáním předpisu. BalanceMatcher je mrtvý kód —
        // i s tímto testem ho maže T2 (accbal-symbol-key).
        $this->markTestSkipped('BalanceMatcher zrušen #69 (routing enginem, T1); maže T2 accbal-symbol-key');
        parent::setUp();
        $this->config = ConfigRuntime::load($this->realDsPath, 'cs');
        $resolver = new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        $this->journalEvents = JournalEventHandlerLoader::load(
            $this->dsConfig,
            $resolver,
            $this->db->getDibiConnection(),
            $this->config,
        );
        $this->ensureFiscalPeriod();
    }

    protected function onTearDown(): void
    {
        $dibi = $this->db->getDibiConnection();
        foreach ($this->createdTxs as $id) {
            $dibi->delete('economy_accbal_allocations')
                ->where('payment_entry IN (SELECT id FROM economy_accbal_ledger WHERE bank_transaction = %i)', $id)
                ->execute();
            $dibi->delete('economy_accbal_ledger')->where('bank_transaction = %i', $id)->execute();
            $dibi->delete('economy_accounting_journal')->where('bank_transaction = %i', $id)->execute();
            $dibi->delete('economy_bank_transactions')->where('id = %i', $id)->execute();
        }
        foreach ($this->seededDocs as $id) {
            $dibi->delete('economy_accbal_allocations')
                ->where('request_entry IN (SELECT id FROM economy_accbal_ledger WHERE doc_head = %i)', $id)
                ->execute();
            $dibi->delete('economy_accbal_ledger')->where('doc_head = %i', $id)->execute();
        }
        foreach ($this->createdBankAccounts as $id) {
            $dibi->delete('economy_codebooks_bank_accounts')->where('id = %i', $id)->execute();
        }
        foreach ($this->createdAccounts as $id) {
            $dibi->delete('economy_accounting_accounts')->where('id = %i', $id)->execute();
        }
    }

    public function testMatchMovesClearingToReceivablesAndAllocates(): void
    {
        $recv = $this->balanceId('receivables');
        $this->prepareAccounts();
        $txId = $this->accountIncomingPayment(1210.00);

        // Clearing pohyb existuje, na 311 zatím nic.
        $this->assertNotNull($this->clearingMove($txId), 'Před párováním platba na clearingu');

        // Otevřený předpis 311 pro téhož partnera.
        $reqId = $this->seedReceivableRequest(1210.00);

        $matcher = $this->matcher();
        $result  = $matcher->matchTransaction($txId);

        $this->assertSame(MatchResult::STATUS_ALLOCATED, $result->status);
        $this->assertNull($this->clearingMove($txId), 'Po spárování clearing pohyb zmizí');

        $payment = $this->receivablesPayment($txId);
        $this->assertNotNull($payment, 'Vznikla úhrada na Pohledávkách');

        $allocs = $this->allocationsFor((int) $payment['id']);
        $this->assertCount(1, $allocs);
        $this->assertSame($reqId, (int) $allocs[0]['request_entry']);
        $this->assertEqualsWithDelta(1210.00, (float) $allocs[0]['amount'], 0.001);
        $this->assertEqualsWithDelta(1210.00, (float) $allocs[0]['amount_hc'], 0.001);
        $this->assertSame(0, (int) $allocs[0]['created_by'], 'Auto allocation');
    }

    public function testIdempotentSecondRunDoesNothing(): void
    {
        $this->balanceId('receivables');
        $this->prepareAccounts();
        $txId = $this->accountIncomingPayment(500.00);
        $this->seedReceivableRequest(500.00);

        $matcher = $this->matcher();
        $matcher->matchTransaction($txId);
        $payment = $this->receivablesPayment($txId);
        $this->assertNotNull($payment);
        $countAfterFirst = count($this->allocationsFor((int) $payment['id']));

        // Druhý běh: platba už není na clearingu → kandidát nenalezen, nic se nepřidá.
        $second = $matcher->matchTransaction($txId);
        $this->assertSame(MatchResult::STATUS_SKIPPED, $second->status);
        $this->assertSame('not_on_clearing', $second->reason);
        $this->assertCount($countAfterFirst, $this->allocationsFor((int) $payment['id']));
    }

    public function testUnmatchReturnsToClearingAndDropsAllocations(): void
    {
        $this->balanceId('receivables');
        $this->prepareAccounts();
        $txId = $this->accountIncomingPayment(800.00);
        $this->seedReceivableRequest(800.00);

        $matcher = $this->matcher();
        $matcher->matchTransaction($txId);
        $payment = $this->receivablesPayment($txId);
        $this->assertNotNull($payment);
        $paymentId = (int) $payment['id'];
        $this->assertCount(1, $this->allocationsFor($paymentId));

        $matcher->unmatch($txId);

        $this->assertNull($this->receivablesPayment($txId), 'Úhrada na 311 zmizela');
        $this->assertNotNull($this->clearingMove($txId), 'Platba zpět na clearingu');
        $this->assertCount(0, $this->allocationsFor($paymentId), 'Allocations cascade smazány');
    }

    public function testOverpaymentStaysOnClearing(): void
    {
        $this->balanceId('receivables');
        $this->prepareAccounts();
        $txId = $this->accountIncomingPayment(1000.00);
        $this->seedReceivableRequest(600.00); // reziduum < platba → přeplatek

        $result = $this->matcher()->matchTransaction($txId);

        $this->assertSame(MatchResult::STATUS_SKIPPED, $result->status);
        $this->assertSame('overpayment', $result->reason);
        $this->assertNotNull($this->clearingMove($txId), 'Přeplatek zůstává na clearingu');
        $this->assertNull($this->receivablesPayment($txId));
    }

    /**
     * Převod peněz (#59 Task D): transakce transfer.* účtuje na 261100, které
     * v žádné saldo skupině není → ledger nic nevyrobí, matcher ji nevidí
     * a operation zůstává transfer.in (nikdy payment.in.matched).
     */
    public function testTransferTransactionIsNotAMatchingCandidate(): void
    {
        $this->balanceId('receivables');
        $this->prepareAccounts();
        $this->ensureAccountByNumber('261100');
        $this->seedReceivableRequest(20000.00); // otevřený předpis by jinak sedl na částku

        $txId = $this->insertTx([
            'bank_account' => $this->bankAccountId,
            'direction'    => 1,
            'operation'    => 'transfer.in',
            'amount'       => 20000.00,
            'amount_dom'   => 20000.00,
            'partner'      => self::PARTNER,
        ]);
        $engine = new BankTransactionAccountingEngine($this->db->getDibiConnection(), $this->config, $this->journalEvents);
        $this->assertSame(1, $engine->accountTransaction($txId)['state']);

        $this->assertNull($this->clearingMove($txId), 'převod není na clearingu');
        $ledger = $this->db->fetchAll('SELECT id FROM economy_accbal_ledger WHERE bank_transaction = %i', $txId);
        $this->assertCount(0, $ledger, '261100 není v žádné saldo skupině → žádný pohyb ledgeru');

        $result = $this->matcher()->matchTransaction($txId);
        $this->assertSame(MatchResult::STATUS_SKIPPED, $result->status);
        $this->assertSame('not_on_clearing', $result->reason);

        $tx = $this->db->fetchRow('SELECT operation FROM economy_bank_transactions WHERE id = %i', $txId);
        $this->assertSame('transfer.in', (string) $tx['operation'], 'matcher operaci převodu nepřepisuje');
    }

    // ── Setup helpers ─────────────────────────────────────────────────────────

    private function matcher(): BalanceMatcher
    {
        return new BalanceMatcher(
            $this->db->getDibiConnection(),
            $this->config,
            $this->journalEvents,
            $this->dsConfig,
        );
    }

    private function prepareAccounts(): void
    {
        $this->bankAccountId ??= $this->insertBankAccount($this->ensureAccountByMask('221'));
        $this->ensureAccountByMask('311');
        $this->ensureAccountByNumber('261200');
    }

    private ?int $bankAccountId = null;

    /** Zaúčtuje nespárovaný příjem → clearing pohyb (přes journalWritten handler). */
    private function accountIncomingPayment(float $amount): int
    {
        $txId = $this->insertTx([
            'bank_account' => $this->bankAccountId,
            'direction'    => 1,
            'operation'    => 'payment.in',
            'amount'       => $amount,
            'amount_dom'   => $amount,
            'partner'      => self::PARTNER,
        ]);
        $engine = new BankTransactionAccountingEngine(
            $this->db->getDibiConnection(),
            $this->config,
            $this->journalEvents,
        );
        $engine->accountTransaction($txId);
        return $txId;
    }

    /** Seeduje otevřený předpis 311 (bal_side=0) pro testovacího partnera. */
    private function seedReceivableRequest(float $amount): int
    {
        $recv  = $this->balanceId('receivables');
        $docId = 980_000_000 + (++$this->seq);
        $this->seededDocs[] = $docId;
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('economy_accbal_ledger', [
            'balance'        => $recv,
            'bal_side'       => 0,
            'source_kind'    => 'doc',
            'source_id'      => $docId,
            'doc_head'       => $docId,
            'account_number' => '311100',
            'partner'        => self::PARTNER,
            'currency'       => 'czk',
            'home_currency'  => 'czk',
            'amount'         => $amount,
            'amount_hc'      => $amount,
            'due_date'       => '2026-06-30',
        ])->execute();
        return (int) $dibi->getInsertId();
    }

    // ── Dotazy ──────────────────────────────────────────────────────────────

    /** @return array<string, mixed>|null */
    private function clearingMove(int $txId): ?array
    {
        $clearing = $this->balanceId('unmatched_payments');
        $row = $this->db->getDibiConnection()->fetch(
            'SELECT * FROM economy_accbal_ledger
             WHERE source_kind = %s AND source_id = %i AND balance = %i AND bal_side = 1',
            'bankTransaction', $txId, $clearing,
        );
        return $row?->toArray();
    }

    /** @return array<string, mixed>|null */
    private function receivablesPayment(int $txId): ?array
    {
        $recv = $this->balanceId('receivables');
        $row = $this->db->getDibiConnection()->fetch(
            'SELECT * FROM economy_accbal_ledger
             WHERE source_kind = %s AND source_id = %i AND balance = %i AND bal_side = 1',
            'bankTransaction', $txId, $recv,
        );
        return $row?->toArray();
    }

    /** @return list<array<string, mixed>> */
    private function allocationsFor(int $paymentId): array
    {
        $rows = $this->db->getDibiConnection()->fetchAll(
            'SELECT * FROM economy_accbal_allocations WHERE payment_entry = %i ORDER BY id',
            $paymentId,
        );
        return array_map(fn($r) => $r->toArray(), $rows);
    }

    private function balanceId(string $code): int
    {
        $row = $this->db->fetchRow('SELECT id FROM economy_accbal_balances WHERE code = %s', $code);
        if ($row === null) {
            $this->markTestSkipped("DS nemá naseedované saldokonto '{$code}'");
        }
        return (int) $row['id'];
    }

    // ── Account/tx helpers (zrcadlí BankTransactionAccountingEngineTest) ──────

    private function ensureFiscalPeriod(): void
    {
        $row = $this->db->fetchRow(
            'SELECT id FROM economy_codebooks_fiscal_months
             WHERE date_begin <= %s AND date_end >= %s AND period_type = 1 LIMIT 1',
            self::ACC_DATE, self::ACC_DATE,
        );
        if ($row === null) {
            $this->markTestSkipped('DS nemá fiskální období pro ' . self::ACC_DATE);
        }
    }

    private function ensureAccountByMask(string $mask): int
    {
        $row = $this->db->fetchRow(
            'SELECT id FROM economy_accounting_accounts
             WHERE number LIKE %like~ AND account_level = 4 AND docState IN (10,40,80)
             ORDER BY number LIMIT 1',
            $mask,
        );
        if ($row === null) {
            $this->markTestSkipped("DS nemá analytický účet pro masku {$mask}");
        }
        return (int) $row['id'];
    }

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
        $structure = AccountDocument::deriveStructure($number);
        $dibi->insert('economy_accounting_accounts', array_merge($structure, [
            'number'       => $number,
            'name'         => 'IT clearing ' . $number,
            'short_name'   => 'IT ' . $number,
            'account_kind' => 1,
            'docState'     => 40,
            'docStateMain' => 3,
        ]))->execute();
        $id = (int) $dibi->getInsertId();
        $this->createdAccounts[] = $id;
        return $id;
    }

    private function insertBankAccount(?int $accountingAccountId): int
    {
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('economy_codebooks_bank_accounts', [
            'code'               => 'IT' . substr(uniqid(), -8),
            'name'               => 'IT test bankovní účet',
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
        $tx = array_merge([
            'currency'          => 'czk',
            'exchange_rate'     => 1,
            'date_transaction'  => self::ACC_DATE,
            'counterparty_name' => 'IT protistrana',
            'accounting_state'  => 0,
            'docState'          => 40,
            'docStateMain'      => 3,
        ], $overrides);
        $dibi->insert('economy_bank_transactions', $tx)->execute();
        $id = (int) $dibi->getInsertId();
        $this->createdTxs[] = $id;
        return $id;
    }
}

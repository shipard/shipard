<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Accbal;

use Shipard\Api\JournalEventHandlerLoader;
use Shipard\Api\OpenItemLookupLoader;
use Shipard\Core\Accounting\OpenItemLookup;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Document\AbstractJournalEventHandler;
use Shipard\Core\Document\JournalEventDispatcher;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Economy\Accbal\ClearingRerouteHandler;
use Shipard\Module\Economy\Accbal\ClearingRouter;
use Shipard\Module\Economy\Accbal\JournalLedgerHandler;
use Shipard\Module\Economy\Accounting\AccountDocument;
use Shipard\Module\Economy\Accounting\AccountingEngine;
use Shipard\Module\Economy\Bank\BankTransactionAccountingEngine;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Routing bankovní úhrady enginem (#69 D3/D4, T1): účet protistrany určuje
 * dohledání otevřeného předpisu (OpenItemLookup nad ledgerem), clearing
 * 261200/261300 jen při miss; reaccount je idempotentní a bez paměti.
 *
 * Předpis se seeduje přímo do ledgeru (izolace od dokladů), transakce se
 * účtuje reálným enginem s reálným journalWritten dispatcherem (re-derivace
 * ledgeru) a lookupem z module.jsonc.
 */
class BankPaymentRoutingTest extends IntegrationTestCase
{
    private const ACC_DATE = '2026-06-10';
    private const PARTNER  = 990004;
    private const VS       = 'IT-ROUTE-2026';

    private ?JournalEventDispatcher $journalEvents = null;
    private ?OpenItemLookup $openItems = null;
    private ?ConfigRuntime $config = null;

    /** @var list<int> */
    private array $createdTxs = [];
    /** @var list<int> */
    private array $createdBankAccounts = [];
    /** @var list<int> */
    private array $createdAccounts = [];
    /** @var list<int> seedované předpisové pohyby (doc_head) */
    private array $seededDocs = [];
    /** @var list<int> reálné faktury (docs_core_heads) */
    private array $createdHeads = [];

    private ?int $bankAccountId = null;
    private string $receivableAccount = '311100';
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = ConfigRuntime::load($this->realDsPath, 'cs');
        $resolver = new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        $dibi = $this->db->getDibiConnection();
        $this->journalEvents = JournalEventHandlerLoader::load($this->dsConfig, $resolver, $dibi, $this->config);
        $this->openItems = OpenItemLookupLoader::load($this->dsConfig, $resolver, $dibi, $this->config);
        $this->ensureFiscalPeriod();
        $this->prepareAccounts();
    }

    protected function onTearDown(): void
    {
        $dibi = $this->db->getDibiConnection();
        foreach ($this->createdTxs as $id) {
            $dibi->delete('economy_accbal_ledger')->where('bank_transaction = %i', $id)->execute();
            $dibi->delete('economy_accounting_journal')->where('bank_transaction = %i', $id)->execute();
            $dibi->delete('economy_bank_transactions')->where('id = %i', $id)->execute();
        }
        foreach ($this->seededDocs as $id) {
            $dibi->delete('economy_accbal_ledger')->where('doc_head = %i', $id)->execute();
        }
        foreach ($this->createdHeads as $id) {
            $dibi->delete('economy_accbal_ledger')->where('doc_head = %i', $id)->execute();
            $dibi->delete('economy_accounting_journal')->where('doc_head = %i', $id)->execute();
            $dibi->delete('docs_core_vat_recap')->where('doc_head = %i', $id)->execute();
            $dibi->delete('docs_core_rows')->where('doc_head = %i', $id)->execute();
            $dibi->delete('docs_core_heads')->where('id = %i', $id)->execute();
        }
        foreach ($this->createdBankAccounts as $id) {
            $dibi->delete('economy_codebooks_bank_accounts')->where('id = %i', $id)->execute();
        }
        foreach ($this->createdAccounts as $id) {
            $dibi->delete('economy_accounting_accounts')->where('id = %i', $id)->execute();
        }
    }

    // ── 1. Otevřený předpis → účet předpisu ──────────────────────────────────

    public function testOpenRequestRoutesPaymentToRequestAccount(): void
    {
        $this->seedRequest('receivables', $this->receivableAccount, 1210.00);

        [$txId, $result] = $this->accountPayment(1210.00);

        $this->assertSame(1, $result['state'], json_encode($result['messages']));
        $this->assertSame($this->receivableAccount, $this->counterpartyAccount($txId), 'úhrada přesně na účtu předpisu');
        $this->assertNull($this->ledgerMove($txId, 'unmatched_payments'), 'na clearingu nic');
        $payment = $this->ledgerMove($txId, 'receivables');
        $this->assertNotNull($payment, 'ledger: úhrada v Pohledávkách');
        $this->assertSame(1, (int) $payment['bal_side']);
        $this->assertEqualsWithDelta(1210.00, (float) $payment['amount'], 0.001);

        $tx = $this->db->fetchRow('SELECT operation FROM economy_bank_transactions WHERE id = %i', $txId);
        $this->assertSame('payment.in', (string) $tx['operation'], 'operation spárovanost nenese');
    }

    public function testOutgoingPaymentRoutesToPayableAccount(): void
    {
        $payableAccount = $this->ensureAccountByMask('321')['number'];
        $this->ensureAccountByNumber('261300');
        $this->seedRequest('payables', $payableAccount, 800.00);

        [$txId, $result] = $this->accountPayment(800.00, ['direction' => 2, 'operation' => 'payment.out']);

        $this->assertSame(1, $result['state'], json_encode($result['messages']));
        $this->assertSame($payableAccount, $this->counterpartyAccount($txId));
        $this->assertNotNull($this->ledgerMove($txId, 'payables'), 'ledger: úhrada v Závazcích');
        $this->assertNull($this->ledgerMove($txId, 'unmatched_payments'));
    }

    public function testOverpaymentStillRoutesToRequestAccount(): void
    {
        $this->seedRequest('receivables', $this->receivableAccount, 600.00);

        [$txId] = $this->accountPayment(1000.00);

        $this->assertSame($this->receivableAccount, $this->counterpartyAccount($txId), 'reziduum > 0 stačí (symbolový model)');
    }

    // ── 2. Miss → clearing ───────────────────────────────────────────────────

    public function testNoOpenRequestStaysOnClearing(): void
    {
        [$txId, $result] = $this->accountPayment(500.00);

        $this->assertSame(1, $result['state']);
        $this->assertSame('261200', $this->counterpartyAccount($txId));
        $this->assertNotNull($this->ledgerMove($txId, 'unmatched_payments'));
        $this->assertNull($this->ledgerMove($txId, 'receivables'));
    }

    public function testNoPartnerStaysOnClearing(): void
    {
        $this->seedRequest('receivables', $this->receivableAccount, 500.00);

        [$txId] = $this->accountPayment(500.00, ['partner' => null]);

        $this->assertSame('261200', $this->counterpartyAccount($txId));
    }

    public function testSpecificSymbolMismatchStaysOnClearing(): void
    {
        $this->seedRequest('receivables', $this->receivableAccount, 500.00, ['specific_symbol' => '77']);

        [$without] = $this->accountPayment(500.00);
        $this->assertSame('261200', $this->counterpartyAccount($without), 'prázdný SS nesedí na 77');

        [$with] = $this->accountPayment(500.00, ['specific_symbol' => '77']);
        $this->assertSame($this->receivableAccount, $this->counterpartyAccount($with));
    }

    public function testClosedKeySendsNextPaymentToClearing(): void
    {
        $this->seedRequest('receivables', $this->receivableAccount, 500.00);

        [$first]  = $this->accountPayment(500.00);
        [$second] = $this->accountPayment(500.00);

        $this->assertSame($this->receivableAccount, $this->counterpartyAccount($first));
        $this->assertSame('261200', $this->counterpartyAccount($second), 'klíč uzavřen první úhradou');
    }

    // ── 4. Reaccount idempotentní, bez smyčky ────────────────────────────────

    public function testReaccountAfterRoutingIsIdempotent(): void
    {
        $this->seedRequest('receivables', $this->receivableAccount, 1210.00);
        [$txId] = $this->accountPayment(1210.00);
        $this->assertSame($this->receivableAccount, $this->counterpartyAccount($txId));
        $paymentId = (int) $this->ledgerMove($txId, 'receivables')['id'];

        RoutingJournalSpy::$calls = [];
        $dispatcher = new JournalEventDispatcher([
            ['class' => JournalLedgerHandler::class, 'events' => ['journalWritten']],
            ['class' => RoutingJournalSpy::class, 'events' => ['journalWritten']],
        ], $this->db->getDibiConnection(), $this->config, $this->dsConfig);
        $engine = $this->engine($dispatcher);

        $engine->accountTransaction($txId);
        $engine->accountTransaction($txId);

        $this->assertSame($this->receivableAccount, $this->counterpartyAccount($txId), 'vlastní úhrada reziduum nenuluje');
        $this->assertSame(
            [['bankTransaction', $txId], ['bankTransaction', $txId]],
            RoutingJournalSpy::$calls,
            'přesně jedna emise journalWritten per reaccount — žádná smyčka',
        );
        $payment = $this->ledgerMove($txId, 'receivables');
        $this->assertNotNull($payment);
        $this->assertSame($paymentId, (int) $payment['id'], 'stabilní identita pohybu přežije reaccount');
    }

    // ── 3. Platba dřív než faktura (trigger D4) ──────────────────────────────

    public function testPaymentBeforeInvoiceIsReroutedWhenInvoicePosts(): void
    {
        [$txId] = $this->accountPayment(1210.00);
        $this->assertSame('261200', $this->counterpartyAccount($txId), 'bez předpisu na clearingu');

        $headId = $this->insertInvoice(1000.00, 21.0);
        $result = (new AccountingEngine($this->db->getDibiConnection(), $this->config, $this->journalEvents))
            ->accountDocument($headId);
        $this->assertSame(1, $result['state'], json_encode($result['messages']));

        $request = $this->db->fetchRow(
            'SELECT account_number FROM economy_accbal_ledger WHERE doc_head = %i AND bal_side = 0',
            $headId,
        );
        $this->assertNotNull($request, 'faktura má předpis v ledgeru');
        $this->assertSame((string) $request['account_number'], $this->counterpartyAccount($txId), 'trigger přeúčtoval úhradu na účet předpisu');
        $this->assertNull($this->ledgerMove($txId, 'unmatched_payments'), 'clearing pohyb zmizel');
        $this->assertNotNull($this->ledgerMove($txId, 'receivables'), 'úhrada v Pohledávkách');
    }

    public function testInvoicePostingDoesNotLoop(): void
    {
        [$txId] = $this->accountPayment(1210.00);
        $headId = $this->insertInvoice(1000.00, 21.0);

        RoutingJournalSpy::$calls = [];
        $dispatcher = new JournalEventDispatcher([
            ['class' => JournalLedgerHandler::class, 'events' => ['journalWritten']],
            ['class' => ClearingRerouteHandler::class, 'events' => ['journalWritten']],
            ['class' => RoutingJournalSpy::class, 'events' => ['journalWritten']],
        ], $this->db->getDibiConnection(), $this->config, $this->dsConfig);
        $docEngine = new AccountingEngine($this->db->getDibiConnection(), $this->config, $dispatcher);

        $docEngine->accountDocument($headId);
        $this->assertSame(
            [['bankTransaction', $txId], ['doc', $headId]],
            RoutingJournalSpy::$calls,
            'zaúčtování předpisu → jedno přeúčtování transakce (vnořeně), nic dalšího',
        );

        RoutingJournalSpy::$calls = [];
        $docEngine->accountDocument($headId);
        $this->assertSame([['doc', $headId]], RoutingJournalSpy::$calls, 'transakce už není na clearingu → žádný reroute');

        RoutingJournalSpy::$calls = [];
        $this->engine($dispatcher)->accountTransaction($txId);
        $this->assertSame([['bankTransaction', $txId]], RoutingJournalSpy::$calls, 'reaccount transakce trigger nespouští');
        $this->assertNotNull($this->ledgerMove($txId, 'receivables'));
    }

    // ── 5. Dávka: dry-run nic nezapíše, ostrý běh přeúčtuje ─────────────────

    public function testBatchDryRunWritesNothingAndRealRunRoutes(): void
    {
        [$txId] = $this->accountPayment(500.00);
        $this->seedRequest('receivables', $this->receivableAccount, 500.00);
        $router = new ClearingRouter($this->db->getDibiConnection(), $this->config, $this->journalEvents, $this->openItems);

        $plan = $router->rerouteAll(['partner' => self::PARTNER], true);
        $this->assertSame(1, $plan->planned);
        $this->assertSame(0, $plan->routed);
        $this->assertSame($this->receivableAccount, $plan->results[0]->targetAccount);
        $this->assertEqualsWithDelta(500.00, $plan->routedAmount, 0.001);
        $this->assertSame('261200', $this->counterpartyAccount($txId), 'dry-run nic nezapsal');
        $this->assertNotNull($this->ledgerMove($txId, 'unmatched_payments'));

        $run = $router->rerouteAll(['partner' => self::PARTNER], false);
        $this->assertSame(1, $run->routed);
        $this->assertSame([], $run->skipped);
        $this->assertSame($this->receivableAccount, $this->counterpartyAccount($txId));
        $this->assertNull($this->ledgerMove($txId, 'unmatched_payments'));

        $again = $router->rerouteAll(['partner' => self::PARTNER], false);
        $this->assertSame(0, $again->candidates(), 'idempotentní: přeúčtovaná úhrada už není kandidát');
    }

    public function testBatchSkipsPaymentWithoutOpenRequest(): void
    {
        [$txId] = $this->accountPayment(500.00);
        $router = new ClearingRouter($this->db->getDibiConnection(), $this->config, $this->journalEvents, $this->openItems);

        $summary = $router->rerouteAll(['partner' => self::PARTNER], false);

        $this->assertSame(['no_open_item' => 1], $summary->skipped);
        $this->assertSame(0, $summary->routed);
        $this->assertSame('261200', $this->counterpartyAccount($txId));
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** Vydaná faktura (invno) ve stavu 40 pro testovacího partnera s VS = self::VS. */
    private function insertInvoice(float $base, float $vatPct): int
    {
        $fy = $this->db->fetchRow(
            'SELECT id FROM economy_codebooks_fiscal_years WHERE date_begin <= %s AND date_end >= %s LIMIT 1',
            self::ACC_DATE, self::ACC_DATE,
        );
        $fm = $this->db->fetchRow(
            'SELECT id FROM economy_codebooks_fiscal_months WHERE date_begin <= %s AND date_end >= %s AND period_type = 1 LIMIT 1',
            self::ACC_DATE, self::ACC_DATE,
        );
        $series = $this->db->fetchRow('SELECT id FROM docs_core_number_series WHERE doc_type = %s LIMIT 1', 'invno');
        if ($fy === null || $fm === null || $series === null) {
            $this->markTestSkipped('DS nemá fiskální období / řadu invno pro ' . self::ACC_DATE);
        }
        $vat   = round($base * $vatPct / 100.0, 2);
        $total = round($base + $vat, 2);

        $dibi = $this->db->getDibiConnection();
        $dibi->insert('docs_core_heads', [
            'doc_type'          => 'invno',
            'number_series'     => (int) $series['id'],
            'doc_number'        => 'IT-ROUTE-' . uniqid(),
            'issue_date'        => self::ACC_DATE,
            'accounting_date'   => self::ACC_DATE,
            'due_date'          => '2026-06-30',
            'fiscal_year'       => (int) $fy['id'],
            'fiscal_month'      => (int) $fm['id'],
            'partner'           => self::PARTNER,
            'payment_reference' => self::VS,
            'doc_currency'      => 'czk',
            'home_currency'     => 'czk',
            'exchange_rate'     => 1.0,
            'doc_text'          => 'IT routing faktura',
            'total_base'        => $base, 'total_vat' => $vat, 'total_amount' => $total,
            'total_base_dom'    => $base, 'total_vat_dom' => $vat, 'total_amount_dom' => $total,
            'docState'          => 40,
            'docStateMain'      => 2,
        ])->execute();
        $headId = (int) $dibi->getInsertId();
        $this->createdHeads[] = $headId;

        $dibi->insert('docs_core_rows', [
            'doc_head' => $headId, 'row_kind' => 1, 'operation' => 'sale.services',
            'description' => 'IT služba', 'vat_code' => 'cz-101', 'vat_pct' => $vatPct,
            'vat_base' => $base, 'vat_amount' => $vat, 'vat_total' => $total,
            'vat_base_dom' => $base, 'vat_amount_dom' => $vat, 'vat_total_dom' => $total,
        ])->execute();
        $dibi->insert('docs_core_vat_recap', [
            'doc_head' => $headId, 'vat_code' => 'cz-101', 'vat_pct' => $vatPct,
            'base' => $base, 'tax' => $vat, 'total' => $total,
            'base_dom' => $base, 'tax_dom' => $vat, 'total_dom' => $total,
            'sum_base' => 1, 'sum_tax' => 1, 'sum_total' => 1, 'is_reverse_pair' => 0, 'order_pos' => 0,
        ])->execute();

        return $headId;
    }

    private function engine(?JournalEventDispatcher $dispatcher = null): BankTransactionAccountingEngine
    {
        return new BankTransactionAccountingEngine(
            $this->db->getDibiConnection(),
            $this->config,
            $dispatcher ?? $this->journalEvents,
            $this->openItems,
        );
    }

    /**
     * Vloží a zaúčtuje úhradu s klíčem testovacího partnera (VS = self::VS).
     *
     * @param array<string, mixed> $over
     * @return array{0: int, 1: array{state: int, messages: list<array<string, mixed>>}}
     */
    private function accountPayment(float $amount, array $over = []): array
    {
        $txId = $this->insertTx(array_merge([
            'bank_account'      => $this->bankAccountId,
            'direction'         => 1,
            'operation'         => 'payment.in',
            'amount'            => $amount,
            'amount_dom'        => $amount,
            'partner'           => self::PARTNER,
            'payment_reference' => self::VS,
        ], $over));
        return [$txId, $this->engine()->accountTransaction($txId)];
    }

    /** @param array<string, mixed> $over */
    private function seedRequest(string $balanceCode, string $account, float $amount, array $over = []): int
    {
        $docId = 980_500_000 + (++$this->seq);
        $this->seededDocs[] = $docId;
        $this->db->getDibiConnection()->insert('economy_accbal_ledger', array_merge([
            'balance'           => $this->balanceId($balanceCode),
            'bal_side'          => 0,
            'source_kind'       => 'doc',
            'source_id'         => $docId,
            'doc_head'          => $docId,
            'account_number'    => $account,
            'partner'           => self::PARTNER,
            'payment_reference' => self::VS,
            'currency'          => 'czk',
            'home_currency'     => 'czk',
            'amount'            => $amount,
            'amount_hc'         => $amount,
            'due_date'          => '2026-06-30',
        ], $over))->execute();
        return $docId;
    }

    /** Účet protistrany v deníku transakce (řádek mimo bankovní 221). */
    private function counterpartyAccount(int $txId): ?string
    {
        $row = $this->db->fetchRow(
            "SELECT account_number FROM economy_accounting_journal
             WHERE bank_transaction = %i AND account_number NOT LIKE '221%%' ORDER BY id LIMIT 1",
            $txId,
        );
        return $row !== null ? (string) $row['account_number'] : null;
    }

    /** @return array<string, mixed>|null */
    private function ledgerMove(int $txId, string $balanceCode): ?array
    {
        $row = $this->db->getDibiConnection()->fetch(
            'SELECT * FROM economy_accbal_ledger
             WHERE source_kind = %s AND source_id = %i AND balance = %i AND bal_side = 1',
            'bankTransaction', $txId, $this->balanceId($balanceCode),
        );
        return $row?->toArray();
    }

    private function balanceId(string $code): int
    {
        $row = $this->db->fetchRow('SELECT id FROM economy_accbal_balances WHERE code = %s', $code);
        if ($row === null) {
            $this->markTestSkipped("DS nemá naseedované saldokonto '{$code}'");
        }
        return (int) $row['id'];
    }

    private function prepareAccounts(): void
    {
        $this->bankAccountId ??= $this->insertBankAccount($this->ensureAccountByMask('221')['id']);
        $this->receivableAccount = $this->ensureAccountByMask('311')['number'];
        $this->ensureAccountByNumber('261200');
    }

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

    /** @return array{id: int, number: string} */
    private function ensureAccountByMask(string $mask): array
    {
        $row = $this->db->fetchRow(
            'SELECT id, number FROM economy_accounting_accounts
             WHERE number LIKE %like~ AND account_level = 4 AND docState IN (10,40,80)
             ORDER BY number LIMIT 1',
            $mask,
        );
        if ($row === null) {
            $this->markTestSkipped("DS nemá analytický účet pro masku {$mask}");
        }
        return ['id' => (int) $row['id'], 'number' => (string) $row['number']];
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
        $dibi->insert('economy_accounting_accounts', array_merge(AccountDocument::deriveStructure($number), [
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

    private function insertBankAccount(int $accountingAccountId): int
    {
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('economy_codebooks_bank_accounts', [
            'code'               => 'IT' . substr(uniqid(), -8),
            'name'               => 'IT routing bankovní účet',
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
            'counterparty_name' => 'IT protistrana',
            'accounting_state'  => 0,
            'docState'          => 40,
            'docStateMain'      => 3,
        ], $overrides))->execute();
        $id = (int) $dibi->getInsertId();
        $this->createdTxs[] = $id;
        return $id;
    }
}

class RoutingJournalSpy extends AbstractJournalEventHandler
{
    /** @var list<array{0: string, 1: int}> */
    public static array $calls = [];

    public function onJournalWritten(string $sourceKind, int $sourceId): void
    {
        self::$calls[] = [$sourceKind, $sourceId];
    }
}

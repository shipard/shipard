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
use Shipard\Module\Economy\Accbal\JournalLedgerHandler;
use Shipard\Module\Economy\Accounting\AccountDocument;
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

    // ── Helpers ─────────────────────────────────────────────────────────────

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

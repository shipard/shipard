<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Accbal;

use Shipard\Api\JournalContributorLoader;
use Shipard\Api\JournalEventHandlerLoader;
use Shipard\Api\OpenItemLookupLoader;
use Shipard\Core\Accounting\JournalContributorSet;
use Shipard\Core\Accounting\OpenItemLookup;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Document\AbstractJournalEventHandler;
use Shipard\Core\Document\JournalEventDispatcher;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Economy\Accbal\CaseClosureRerouteHandler;
use Shipard\Module\Economy\Accbal\CaseQuery;
use Shipard\Module\Economy\Accbal\ClearingRerouteHandler;
use Shipard\Module\Economy\Accbal\JournalLedgerHandler;
use Shipard\Module\Economy\Accounting\AccountDocument;
use Shipard\Module\Economy\Accounting\AccountingEngine;
use Shipard\Module\Economy\Bank\BankTransactionAccountingEngine;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Uzavírání zálohových faktur přijatých při úhradě (#106 D2,
 * tasks/doc-proforma-in.md) nad reálným DS — zrcadlo ProformaClosureTest
 * na vstupní straně, bez nového kódu v enginech: výzva na podrozvaze
 * (799 MD / 757 DAL) → bankovní výdaj nebo pokladní `advance.given` s jejím
 * VS → v deníku úhrady 314/221 (resp. 314/211) + 757/799, případ
 * v Zálohových fakturách přijatých uzavřený, v Poskytnutých zálohách
 * otevřený předpis; částečné úhrady, přeplatek, idempotence reaccountu,
 * cizí měna kurzem výzvy, jiný fiskální rok, platba dřív než výzva (banka
 * přes clearing 261300, pokladna přes CaseClosureRerouteHandler), konečná
 * faktura přijatá s ručním odpočtem zálohy, storno neuhrazené výzvy.
 * Enginy s reálným journalWritten dispatcherem, lookupem a contributory
 * z module.jsonc.
 */
class ProformaInClosureTest extends IntegrationTestCase
{
    private const ACC_DATE = '2026-06-10';
    private const PARTNER  = 990008;
    private const VS       = 'IT-CLOSEIN-2026';
    private const SS       = '32';

    private ?ConfigRuntime $config = null;
    private ?JournalEventDispatcher $journalEvents = null;
    private ?OpenItemLookup $openItems = null;
    private ?JournalContributorSet $contributors = null;

    private int $fiscalYear = 0;
    private int $fiscalMonth = 0;
    private ?int $bankAccountId = null;
    private int $seq = 0;

    /** @var list<int> */
    private array $createdHeads = [];
    /** @var list<int> */
    private array $createdTxs = [];
    /** @var list<int> */
    private array $createdBankAccounts = [];
    /** @var list<int> */
    private array $createdAccounts = [];
    /** @var list<int> */
    private array $seededDocs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = ConfigRuntime::load($this->realDsPath, 'cs');
        $resolver = new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        $dibi = $this->db->getDibiConnection();
        $this->contributors  = JournalContributorLoader::load($this->dsConfig, $resolver, $dibi, $this->config);
        $this->journalEvents = JournalEventHandlerLoader::load($this->dsConfig, $resolver, $dibi, $this->config, $this->contributors);
        $this->openItems     = OpenItemLookupLoader::load($this->dsConfig, $resolver, $dibi, $this->config);
        $this->assertFalse($this->contributors->isEmpty(), 'economy.accbal registruje CaseClosureContributor');

        $fy = $this->db->fetchRow(
            'SELECT id FROM economy_codebooks_fiscal_years WHERE date_begin <= %s AND date_end >= %s LIMIT 1',
            self::ACC_DATE, self::ACC_DATE,
        );
        $fm = $this->db->fetchRow(
            'SELECT id FROM economy_codebooks_fiscal_months WHERE date_begin <= %s AND date_end >= %s AND period_type = 1 LIMIT 1',
            self::ACC_DATE, self::ACC_DATE,
        );
        if ($fy === null || $fm === null) {
            $this->markTestSkipped('DS nemá fiskální období pro ' . self::ACC_DATE);
        }
        $this->fiscalYear  = (int) $fy['id'];
        $this->fiscalMonth = (int) $fm['id'];
        $this->balanceId('proformas_in');
        $this->ensureAccountByNumber('757100');
        $this->ensureAccountByNumber('799100');
        $this->ensureAccountByNumber('261300');
        $this->accountByMask('314');
        $this->bankAccountId = $this->insertBankAccount($this->accountByMask('221')['id']);
    }

    protected function onTearDown(): void
    {
        $dibi = $this->db->getDibiConnection();
        foreach ($this->createdTxs as $id) {
            $dibi->delete('economy_accbal_ledger')->where('bank_transaction = %i', $id)->execute();
            $dibi->delete('economy_accounting_journal')->where('bank_transaction = %i', $id)->execute();
            $dibi->delete('economy_bank_transactions')->where('id = %i', $id)->execute();
        }
        foreach ([...$this->createdHeads, ...$this->seededDocs] as $id) {
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

    // ── Banka ────────────────────────────────────────────────────────────────

    public function testBankPaymentClosesReceivedProformaAndOpensGivenAdvance(): void
    {
        $proformaId = $this->postProforma(10000.0, 21.0);
        $case = $this->caseOf('proformas_in');
        $this->assertEqualsWithDelta(12100.0, $case['residual'], 0.001, 'výzva otevřená');
        $this->assertSame(CaseQuery::KIND_DEBT, $case['kind'], 'předpis na DAL = dluh k úhradě');

        [$txId, $result] = $this->payByBank(12100.0);

        $this->assertSame(1, $result['state'], json_encode($result['messages']));
        $this->assertSame([], $result['messages']);
        $journal = $this->journalOfTx($txId);
        $this->assertCount(4, $journal, '314/221 + 757/799');
        $this->assertBalanced($journal);
        $this->assertEqualsWithDelta(12100.0, (float) $this->lineByPrefix($journal, '221')['money_cr'], 0.001);
        $advance = $this->lineByPrefix($journal, '314');
        $this->assertEqualsWithDelta(12100.0, (float) $advance['money_dr'], 0.001, 'peníze na poskytnutou zálohu');
        $this->assertSame('payment.out', (string) $advance['operation']);
        $closing = $this->lineByPrefix($journal, '757100');
        $this->assertEqualsWithDelta(12100.0, (float) $closing['money_dr'], 0.001, 'uzavření: 757100 MD');
        $this->assertNull($closing['operation'], 'příspěvek bez operace');
        $this->assertSame('Uzavření zálohové faktury přijaté ' . self::VS, (string) $closing['text']);
        $this->assertSame(self::VS, (string) $closing['payment_reference']);
        $this->assertSame(self::SS, (string) $closing['specific_symbol']);
        $this->assertSame(self::PARTNER, (int) $closing['partner']);
        $contra = $this->lineByPrefix($journal, '799100');
        $this->assertEqualsWithDelta(12100.0, (float) $contra['money_cr'], 0.001, 'uzavření: 799100 DAL');

        // Saldo: výzva uzavřená, poskytnutá záloha otevřený předpis pod klíčem výzvy.
        $proforma = $this->caseOf('proformas_in');
        $this->assertEqualsWithDelta(0.0, $proforma['residual'], 0.001);
        $this->assertSame(CaseQuery::KIND_CLOSED, $proforma['kind']);
        $closure = $this->ledgerMove($txId, 'proformas_in');
        $this->assertNotNull($closure);
        $this->assertSame(1, (int) $closure['bal_side'], '757100 MD = úhrada v Zálohových fakturách přijatých');
        $this->assertSame('757100', (string) $closure['account_number']);
        $given = $this->caseOf('advances_given');
        $this->assertEqualsWithDelta(12100.0, $given['residual'], 0.001, 'poskytnutá záloha otevřená');
        $this->assertSame(CaseQuery::KIND_DEBT, $given['kind']);
        $this->assertOffBalanceNetsToZero($proformaId, [$txId]);
    }

    public function testPartialPaymentsConsumeResidualInOrder(): void
    {
        $proformaId = $this->postProforma(10000.0, 21.0);

        [$first] = $this->payByBank(5000.0);
        $this->assertEqualsWithDelta(5000.0, (float) $this->lineByPrefix($this->journalOfTx($first), '757100')['money_dr'], 0.001);
        $this->assertEqualsWithDelta(7100.0, $this->caseOf('proformas_in')['residual'], 0.001, 'zbytek otevřený');

        [$second] = $this->payByBank(10000.0);
        $journal = $this->journalOfTx($second);
        $this->assertEqualsWithDelta(10000.0, (float) $this->lineByPrefix($journal, '314')['money_dr'], 0.001, 'celá platba jde na zálohu');
        $this->assertEqualsWithDelta(7100.0, (float) $this->lineByPrefix($journal, '757100')['money_dr'], 0.001, 'uzavření jen do výše rezidua');
        $this->assertEqualsWithDelta(0.0, $this->caseOf('proformas_in')['residual'], 0.001);
        $this->assertEqualsWithDelta(15000.0, $this->caseOf('advances_given')['sum_requests'], 0.001, 'přebytek zůstává na zálohách');
        $this->assertOffBalanceNetsToZero($proformaId, [$first, $second]);

        // Třetí platba už výzvu nenajde (uzavřená) → výdajový clearing, bez uzavření.
        [$third] = $this->payByBank(100.0);
        $journal = $this->journalOfTx($third);
        $this->assertCount(2, $journal);
        $this->assertSame('261300', (string) $this->lineByPrefix($journal, '261')['account_number']);
    }

    public function testOverpaymentClosesOnlyResidual(): void
    {
        $proformaId = $this->postProforma(10000.0, 21.0);

        [$txId] = $this->payByBank(15000.0);

        $journal = $this->journalOfTx($txId);
        $this->assertEqualsWithDelta(15000.0, (float) $this->lineByPrefix($journal, '314')['money_dr'], 0.001);
        $this->assertEqualsWithDelta(12100.0, (float) $this->lineByPrefix($journal, '757100')['money_dr'], 0.001);
        $this->assertEqualsWithDelta(0.0, $this->caseOf('proformas_in')['residual'], 0.001, 'výzva není přeplacená');
        $this->assertOffBalanceNetsToZero($proformaId, [$txId]);
    }

    public function testReaccountIsIdempotentAndKeepsMovementIds(): void
    {
        $this->postProforma(10000.0, 21.0);
        [$txId] = $this->payByBank(12100.0);
        $before = $this->journalShape($this->journalOfTx($txId));
        $closureIdBefore = (int) $this->ledgerMove($txId, 'proformas_in')['id'];

        $result = $this->bankEngine()->accountTransaction($txId);

        $this->assertSame(1, $result['state'], json_encode($result['messages']));
        $this->assertSame($before, $this->journalShape($this->journalOfTx($txId)), 'reaccount = shodný deník');
        $this->assertEqualsWithDelta(0.0, $this->caseOf('proformas_in')['residual'], 0.001, 'výzva dál uzavřená, ne dvakrát');
        $this->assertSame($closureIdBefore, (int) $this->ledgerMove($txId, 'proformas_in')['id'], 'id pohybu přežije reaccount (D13)');
    }

    public function testForeignCurrencyClosesAtProformaRateAndSettlesRemainder(): void
    {
        // Výzva 1 000 EUR kurzem 25,123 = 25 123 Kč; platby 333 + 333 + 334
        // EUR vlastními kurzy. Uzavření jde kurzem výzvy, poslední dorovná
        // haléře: Σ 757100 MD v Kč = 25 123 = 757100 DAL výzvy.
        $proformaId = $this->postProforma(1000.0, 0.0, ['doc_currency' => 'eur', 'exchange_rate' => 25.123, 'rate' => 25.123]);
        $this->assertEqualsWithDelta(25123.0, (float) $this->lineByPrefix($this->journalOfDoc($proformaId), '757100')['money_cr'], 0.001);

        [$a] = $this->payByBank(333.0, ['currency' => 'eur', 'amount_dom' => 8325.0]);
        [$b] = $this->payByBank(333.0, ['currency' => 'eur', 'amount_dom' => 8391.6]);
        [$c] = $this->payByBank(334.0, ['currency' => 'eur', 'amount_dom' => 8283.2]);

        foreach ([[$a, 333.0, 8365.96], [$b, 333.0, 8365.96], [$c, 334.0, 8391.08]] as [$txId, $cur, $dom]) {
            $closing = $this->lineByPrefix($this->journalOfTx($txId), '757100');
            $this->assertEqualsWithDelta($cur, (float) $closing['money_dr_cur'], 0.001, "tx {$txId}: EUR");
            $this->assertEqualsWithDelta($dom, (float) $closing['money_dr'], 0.001, "tx {$txId}: Kč kurzem výzvy");
            $this->assertSame('eur', (string) $closing['currency']);
        }
        $proforma = $this->caseOf('proformas_in', 'eur');
        $this->assertEqualsWithDelta(0.0, $proforma['residual'], 0.001);
        $this->assertEqualsWithDelta(0.0, $proforma['residual_hc'], 0.001, 'podrozvaha v Kč na nulu');
        $this->assertOffBalanceNetsToZero($proformaId, [$a, $b, $c]);
        // Kurzový rozdíl zůstává na zálohách (Σ dom plateb ≠ Σ dom uzavření).
        $this->assertEqualsWithDelta(8325.0 + 8391.6 + 8283.2, $this->caseOf('advances_given', 'eur')['sum_requests_hc'], 0.001);
    }

    public function testProformaInOtherFiscalYearIsNotClosed(): void
    {
        // Předpis výzvy v jiném období (D11): lookup ji nenajde → platba na
        // clearing bez uzavření; předpis zůstává otevřený.
        $this->seedLedgerRequest('proformas_in', '757100', 12100.0, ['fiscal_year' => $this->otherFiscalYear()]);

        [$txId] = $this->payByBank(12100.0);

        $journal = $this->journalOfTx($txId);
        $this->assertCount(2, $journal);
        $this->assertSame('261300', (string) $this->lineByPrefix($journal, '261')['account_number']);
        $this->assertNull($this->ledgerMove($txId, 'proformas_in'));
    }

    // ── Pokladna ─────────────────────────────────────────────────────────────

    public function testCashAdvanceGivenClosesReceivedProforma(): void
    {
        $proformaId = $this->postProforma(10000.0, 21.0);

        $cashId = $this->postCashAdvance(12100.0);

        $journal = $this->journalOfDoc($cashId);
        $this->assertCount(4, $journal, '314/211 + 757/799');
        $this->assertBalanced($journal);
        $this->assertEqualsWithDelta(12100.0, (float) $this->lineByPrefix($journal, '211')['money_cr'], 0.001);
        $advance = $this->lineByPrefix($journal, '314');
        $this->assertEqualsWithDelta(12100.0, (float) $advance['money_dr'], 0.001);
        $this->assertSame('advance.given', (string) $advance['operation']);
        $this->assertEqualsWithDelta(12100.0, (float) $this->lineByPrefix($journal, '799100')['money_cr'], 0.001);
        $closing = $this->lineByPrefix($journal, '757100');
        $this->assertEqualsWithDelta(12100.0, (float) $closing['money_dr'], 0.001);
        $this->assertNull($closing['operation']);
        $this->assertSame(self::VS, (string) $closing['payment_reference']);

        $this->assertEqualsWithDelta(0.0, $this->caseOf('proformas_in')['residual'], 0.001, 'výzva uzavřená pokladnou');
        $this->assertEqualsWithDelta(12100.0, $this->caseOf('advances_given')['residual'], 0.001);
        $this->assertOffBalanceNetsToZero($proformaId, [], [$cashId]);
    }

    // ── Platba dřív než výzva ────────────────────────────────────────────────

    public function testCashAdvanceBeforeProformaIsClosedWhenProformaPosts(): void
    {
        // Pokladní záloha s VS výzvy zaúčtovaná dřív: 314/211, na clearingu
        // nikdy není. Po zaúčtování výzvy ji CaseClosureRerouteHandler
        // přeúčtuje a contributor doplní 757/799.
        $cashId = $this->postCashAdvance(12100.0);
        $this->assertCount(2, $this->journalOfDoc($cashId), 'bez výzvy jen 314/211');
        $this->assertEqualsWithDelta(12100.0, $this->caseOf('advances_given')['residual'], 0.001);

        $proformaId = $this->postProforma(10000.0, 21.0);

        $journal = $this->journalOfDoc($cashId);
        $this->assertCount(4, $journal, 'trigger přeúčtoval pokladní doklad s uzavřením');
        $this->assertBalanced($journal);
        $this->assertEqualsWithDelta(12100.0, (float) $this->lineByPrefix($journal, '757100')['money_dr'], 0.001);
        $this->assertEqualsWithDelta(12100.0, (float) $this->lineByPrefix($journal, '799100')['money_cr'], 0.001);
        $this->assertEqualsWithDelta(0.0, $this->caseOf('proformas_in')['residual'], 0.001, 'výzva uzavřená');
        $this->assertEqualsWithDelta(12100.0, $this->caseOf('advances_given')['residual'], 0.001, 'záloha dál otevřená');
        $this->assertOffBalanceNetsToZero($proformaId, [], [$cashId]);
    }

    public function testBankPaymentBeforeProformaIsClosedViaClearingReroute(): void
    {
        [$txId] = $this->payByBank(12100.0);
        $journal = $this->journalOfTx($txId);
        $this->assertCount(2, $journal);
        $this->assertSame('261300', (string) $this->lineByPrefix($journal, '261')['account_number'], 'bez výzvy na výdajovém clearingu');

        $proformaId = $this->postProforma(10000.0, 21.0);

        $journal = $this->journalOfTx($txId);
        $this->assertCount(4, $journal, 'clearing trigger přeúčtoval úhradu, contributor uzavřel');
        $this->assertBalanced($journal);
        $this->assertEqualsWithDelta(12100.0, (float) $this->lineByPrefix($journal, '314')['money_dr'], 0.001);
        $this->assertEqualsWithDelta(12100.0, (float) $this->lineByPrefix($journal, '757100')['money_dr'], 0.001);
        $this->assertNull($this->ledgerMove($txId, 'unmatched_payments'), 'clearing pohyb zmizel');
        $this->assertEqualsWithDelta(0.0, $this->caseOf('proformas_in')['residual'], 0.001);
        $this->assertOffBalanceNetsToZero($proformaId, [$txId]);
    }

    public function testProformaRepostReroutesOnceAndDoesNotLoop(): void
    {
        // Dvě platby před výzvou (pokladna 5 000 + banka 7 100 = 12 100):
        // clearing trigger přeúčtuje transakci první (uzavře 7 100), uzavírací
        // trigger pak pokladní doklad (uzavře zbylých 5 000).
        $cashId = $this->postCashAdvance(5000.0);
        [$txId] = $this->payByBank(7100.0);
        $proformaId = $this->insertHead('invpi', 10000.0, 21.0, 1.0, ['doc_text' => 'IT výzva']);

        ClosureInJournalSpy::$calls = [];
        $dispatcher = new JournalEventDispatcher([
            ['class' => JournalLedgerHandler::class, 'events' => ['journalWritten']],
            ['class' => ClearingRerouteHandler::class, 'events' => ['journalWritten']],
            ['class' => CaseClosureRerouteHandler::class, 'events' => ['journalWritten']],
            ['class' => ClosureInJournalSpy::class, 'events' => ['journalWritten']],
        ], $this->db->getDibiConnection(), $this->config, $this->dsConfig, $this->contributors);
        $engine = new AccountingEngine($this->db->getDibiConnection(), $this->config, $dispatcher, $this->contributors);

        $result = $engine->accountDocument($proformaId);
        $this->assertSame(1, $result['state'], json_encode($result['messages']));
        $this->assertSame(
            [['bankTransaction', $txId], ['doc', $cashId], ['doc', $proformaId]],
            ClosureInJournalSpy::$calls,
            'výzva → clearing trigger přeúčtuje transakci, uzavírací trigger pokladní doklad, nic dalšího',
        );
        $this->assertCount(4, $this->journalOfTx($txId));
        $this->assertCount(4, $this->journalOfDoc($cashId));
        $this->assertEqualsWithDelta(7100.0, (float) $this->lineByPrefix($this->journalOfTx($txId), '757100')['money_dr'], 0.001);
        $this->assertEqualsWithDelta(5000.0, (float) $this->lineByPrefix($this->journalOfDoc($cashId), '757100')['money_dr'], 0.001);
        $this->assertEqualsWithDelta(0.0, $this->caseOf('proformas_in')['residual'], 0.001);
        $this->assertOffBalanceNetsToZero($proformaId, [$txId], [$cashId]);

        ClosureInJournalSpy::$calls = [];
        $engine->accountDocument($proformaId);
        $this->assertSame([['doc', $proformaId]], ClosureInJournalSpy::$calls, 'oba zdroje už mají uzavření → žádný reroute');
    }

    // ── Konečná faktura přijatá ──────────────────────────────────────────────

    public function testFinalReceivedInvoiceWithDeductionClosesGivenAdvance(): void
    {
        $this->postProforma(10000.0, 21.0);
        [$txId] = $this->payByBank(12100.0);
        $this->assertEqualsWithDelta(12100.0, $this->caseOf('advances_given')['residual'], 0.001);

        $invoiceId = $this->postFinalReceivedInvoice(10000.0, 21.0, 12100.0);

        $journal = $this->journalOfDoc($invoiceId);
        $this->assertBalanced($journal);
        $deduction = $this->lineByPrefix($journal, '314');
        $this->assertEqualsWithDelta(12100.0, (float) $deduction['money_cr'], 0.001, 'odpočet poskytnuté zálohy 314 DAL');
        $this->assertSame(self::VS, (string) $deduction['payment_reference']);
        $this->assertSame(self::SS, (string) $deduction['specific_symbol']);
        foreach ($journal as $line) {
            $this->assertStringStartsNotWith('757', (string) $line['account_number'], 'faktura podrozvahu neuzavírá znovu');
            $this->assertStringStartsNotWith('799', (string) $line['account_number']);
        }

        $this->assertSame(CaseQuery::KIND_CLOSED, $this->caseOf('advances_given')['kind'], 'poskytnutá záloha uzavřená fakturou');
        $this->assertSame(CaseQuery::KIND_CLOSED, $this->caseOf('proformas_in')['kind'], 'výzva uzavřená úhradou');
        $this->assertCount(4, $this->journalOfTx($txId), 'deník úhrady beze změny');
    }

    // ── Storno ───────────────────────────────────────────────────────────────

    public function testClearingUnpaidProformaRemovesItFromBalances(): void
    {
        $proformaId = $this->postProforma(10000.0, 21.0);
        $this->assertSame(CaseQuery::KIND_DEBT, $this->caseOf('proformas_in')['kind']);

        $this->docEngine()->clearDocument($proformaId);

        $this->assertSame([], $this->journalOfDoc($proformaId));
        $this->assertSame('none', $this->caseOf('proformas_in')['kind'], 'případ ze saldokonta zmizel');
    }

    // ── Enginy ───────────────────────────────────────────────────────────────

    private function docEngine(): AccountingEngine
    {
        return new AccountingEngine($this->db->getDibiConnection(), $this->config, $this->journalEvents, $this->contributors);
    }

    private function bankEngine(): BankTransactionAccountingEngine
    {
        return new BankTransactionAccountingEngine(
            $this->db->getDibiConnection(), $this->config, $this->journalEvents, $this->openItems, $this->contributors,
        );
    }

    /** Bankovní výdaj s klíčem výzvy. @param array<string, mixed> $over @return array{0: int, 1: array{state: int, messages: list<array<string, mixed>>}} */
    private function payByBank(float $amount, array $over = []): array
    {
        $txId = $this->insertTx(array_merge([
            'amount'     => $amount,
            'amount_dom' => $amount,
        ], $over));
        return [$txId, $this->bankEngine()->accountTransaction($txId)];
    }

    /** Výzva ve stavu 40 zaúčtovaná enginem (799 MD / 757 DAL). @param array<string, mixed> $over */
    private function postProforma(float $base, float $vatPct, array $over = []): int
    {
        $rate = (float) ($over['rate'] ?? 1.0);
        unset($over['rate']);
        $headId = $this->insertHead('invpi', $base, $vatPct, $rate, array_merge(['doc_text' => 'IT výzva'], $over));
        $result = $this->docEngine()->accountDocument($headId);
        $this->assertSame(1, $result['state'], json_encode($result['messages']));
        return $headId;
    }

    /** Pokladní výdaj se zálohou (advance.given) pod klíčem výzvy, zaúčtovaný enginem. */
    private function postCashAdvance(float $amount): int
    {
        $desk = $this->db->fetchRow(
            'SELECT id, accounting_account FROM economy_codebooks_cash_desks
             WHERE docState IN (10,40,80) AND accounting_account IS NOT NULL ORDER BY id LIMIT 1',
        );
        if ($desk === null) {
            $this->markTestSkipped('DS nemá pokladnu s účtem 211');
        }
        $series = $this->db->fetchRow(
            'SELECT id FROM docs_core_number_series WHERE doc_type = %s AND docState IN (10,40,80)
             ORDER BY (cash_desk = %i) DESC, id LIMIT 1',
            'cash', (int) $desk['id'],
        );
        if ($series === null) {
            $this->markTestSkipped('DS nemá řadu pokladních dokladů');
        }
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('docs_core_heads', array_merge($this->headDefaults('cash', (int) $series['id']), [
            'cash_dir'         => 2,
            'cash_desk'        => (int) $desk['id'],
            'payment_method'   => 0,
            'doc_text'         => 'IT pokladní záloha dodavateli',
            'total_base'       => $amount, 'total_vat' => 0.0, 'total_amount' => $amount,
            'total_base_dom'   => $amount, 'total_vat_dom' => 0.0, 'total_amount_dom' => $amount,
        ]))->execute();
        $headId = (int) $dibi->getInsertId();
        $this->createdHeads[] = $headId;
        $dibi->insert('docs_core_rows', [
            'doc_head' => $headId, 'row_kind' => 1, 'operation' => 'advance.given',
            'description' => 'Záloha na výzvu', 'vat_code' => null, 'vat_pct' => null,
            'vat_base' => $amount, 'vat_amount' => 0.0, 'vat_total' => $amount,
            'vat_base_dom' => $amount, 'vat_amount_dom' => 0.0, 'vat_total_dom' => $amount,
            'partner' => self::PARTNER, 'payment_reference' => self::VS, 'specific_symbol' => self::SS,
        ])->execute();

        $result = $this->docEngine()->accountDocument($headId);
        $this->assertSame(1, $result['state'], json_encode($result['messages']));
        return $headId;
    }

    /** Konečná faktura přijatá: plnění + ruční odpočet poskytnuté zálohy tax0 s VS a SS výzvy. */
    private function postFinalReceivedInvoice(float $base, float $vatPct, float $advance): int
    {
        $vat = round($base * $vatPct / 100.0, 2);
        $headId = $this->insertHead('invni', $base, $vatPct, 1.0, [
            'doc_text'         => 'IT konečná faktura přijatá',
            'total_base'       => round($base - $advance, 2), 'total_vat' => $vat, 'total_amount' => round($base + $vat - $advance, 2),
            'total_base_dom'   => round($base - $advance, 2), 'total_vat_dom' => $vat, 'total_amount_dom' => round($base + $vat - $advance, 2),
        ]);
        $this->db->getDibiConnection()->insert('docs_core_rows', [
            'doc_head' => $headId, 'row_kind' => 1, 'operation' => 'purchase.advanceDeduction',
            'description' => 'Odpočet poskytnuté zálohy', 'vat_code' => null, 'vat_pct' => null,
            'vat_base' => -$advance, 'vat_amount' => 0.0, 'vat_total' => -$advance,
            'vat_base_dom' => -$advance, 'vat_amount_dom' => 0.0, 'vat_total_dom' => -$advance,
            'payment_reference' => self::VS, 'specific_symbol' => self::SS,
        ])->execute();

        $result = $this->docEngine()->accountDocument($headId);
        $this->assertSame(1, $result['state'], json_encode($result['messages']));
        return $headId;
    }

    // ── Fixtury ──────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function headDefaults(string $docType, int $seriesId): array
    {
        return [
            'doc_type'          => $docType,
            'number_series'     => $seriesId,
            'doc_number'        => 'IT-CLOSEIN-' . uniqid(),
            'issue_date'        => self::ACC_DATE,
            'accounting_date'   => self::ACC_DATE,
            'due_date'          => '2026-06-24',
            'fiscal_year'       => $this->fiscalYear,
            'fiscal_month'      => $this->fiscalMonth,
            'partner'           => self::PARTNER,
            'payment_reference' => self::VS,
            'specific_symbol'   => self::SS,
            'payment_method'    => 1,
            'doc_currency'      => 'czk',
            'home_currency'     => 'czk',
            'exchange_rate'     => 1.0,
            'docState'          => 40,
            'docStateMain'      => 2,
        ];
    }

    /**
     * Hlavička přijatého dokladu se řádkem plnění a rekapitulací (CZK nebo
     * cizí měna kurzem).
     *
     * @param array<string, mixed> $over
     */
    private function insertHead(string $docType, float $base, float $vatPct, float $rate, array $over = []): int
    {
        $series = $this->db->fetchRow(
            'SELECT id FROM docs_core_number_series WHERE doc_type = %s AND docState IN (10,40,80) LIMIT 1',
            $docType,
        );
        if ($series === null) {
            $this->markTestSkipped("DS nemá řadu {$docType}");
        }
        $vat   = round($base * $vatPct / 100.0, 2);
        $total = round($base + $vat, 2);
        $dom   = static fn(float $v): float => round($v * $rate, 2);

        $dibi = $this->db->getDibiConnection();
        $dibi->insert('docs_core_heads', array_merge($this->headDefaults($docType, (int) $series['id']), [
            'total_base'     => $base, 'total_vat' => $vat, 'total_amount' => $total,
            'total_base_dom' => $dom($base), 'total_vat_dom' => $dom($vat), 'total_amount_dom' => $dom($total),
        ], $over))->execute();
        $headId = (int) $dibi->getInsertId();
        $this->createdHeads[] = $headId;

        $dibi->insert('docs_core_rows', [
            'doc_head' => $headId, 'row_kind' => 1, 'operation' => 'purchase.services',
            'description' => 'IT služba', 'vat_code' => $vatPct > 0 ? 'cz-101' : 'cz-201', 'vat_pct' => $vatPct,
            'vat_base' => $base, 'vat_amount' => $vat, 'vat_total' => $total,
            'vat_base_dom' => $dom($base), 'vat_amount_dom' => $dom($vat), 'vat_total_dom' => $dom($total),
        ])->execute();
        if ($vat > 0) {
            $dibi->insert('docs_core_vat_recap', [
                'doc_head' => $headId, 'vat_code' => 'cz-101', 'vat_pct' => $vatPct,
                'base' => $base, 'tax' => $vat, 'total' => $total,
                'base_dom' => $dom($base), 'tax_dom' => $dom($vat), 'total_dom' => $dom($total),
                'sum_base' => 1, 'sum_tax' => 1, 'sum_total' => 1, 'is_reverse_pair' => 0, 'order_pos' => 0,
            ])->execute();
        }
        return $headId;
    }

    /** Bankovní výdaj (direction 2, payment.out) s klíčem výzvy. @param array<string, mixed> $over */
    private function insertTx(array $over): int
    {
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('economy_bank_transactions', array_merge([
            'bank_account'      => $this->bankAccountId,
            'direction'         => 2,
            'operation'         => 'payment.out',
            'currency'          => 'czk',
            'exchange_rate'     => 1,
            'date_transaction'  => self::ACC_DATE,
            'counterparty_name' => 'IT dodavatel',
            'partner'           => self::PARTNER,
            'payment_reference' => self::VS,
            'specific_symbol'   => self::SS,
            'accounting_state'  => 0,
            'docState'          => 40,
            'docStateMain'      => 3,
        ], $over))->execute();
        $id = (int) $dibi->getInsertId();
        $this->createdTxs[] = $id;
        return $id;
    }

    /** Předpis přímo v ledgeru (bez dokladu) — jiné období apod. @param array<string, mixed> $over */
    private function seedLedgerRequest(string $balanceCode, string $account, float $amount, array $over = []): int
    {
        $docId = 980_800_000 + (++$this->seq);
        $this->seededDocs[] = $docId;
        $this->db->getDibiConnection()->insert('economy_accbal_ledger', array_merge([
            'balance'           => $this->balanceId($balanceCode),
            'bal_side'          => 0,
            'source_kind'       => 'doc',
            'source_id'         => $docId,
            'doc_head'          => $docId,
            'account_number'    => $account,
            'fiscal_year'       => $this->fiscalYear,
            'partner'           => self::PARTNER,
            'payment_reference' => self::VS,
            'specific_symbol'   => self::SS,
            'currency'          => 'czk',
            'home_currency'     => 'czk',
            'amount'            => $amount,
            'amount_hc'         => $amount,
            'due_date'          => '2026-06-30',
        ], $over))->execute();
        return $docId;
    }

    private function otherFiscalYear(): int
    {
        $row = $this->db->fetchRow(
            'SELECT id FROM economy_codebooks_fiscal_years WHERE id <> %i ORDER BY id LIMIT 1',
            $this->fiscalYear,
        );
        return $row !== null ? (int) $row['id'] : $this->fiscalYear + 100_000;
    }

    private function insertBankAccount(int $accountingAccountId): int
    {
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('economy_codebooks_bank_accounts', [
            'code'               => 'IT' . substr(uniqid(), -8),
            'name'               => 'IT closure-in bankovní účet',
            'currency'           => 'czk',
            'accounting_account' => $accountingAccountId,
            'docState'           => 40,
            'docStateMain'       => 3,
        ])->execute();
        $id = (int) $dibi->getInsertId();
        $this->createdBankAccounts[] = $id;
        return $id;
    }

    /** @return array{id: int, number: string} */
    private function accountByMask(string $mask): array
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

    private function ensureAccountByNumber(string $number): void
    {
        $row = $this->db->fetchRow(
            'SELECT id FROM economy_accounting_accounts WHERE number = %s AND docState IN (10,40,80) LIMIT 1',
            $number,
        );
        if ($row !== null) {
            return;
        }
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('economy_accounting_accounts', array_merge(AccountDocument::deriveStructure($number), [
            'number'       => $number,
            'name'         => 'IT účet ' . $number,
            'short_name'   => 'IT ' . $number,
            'account_kind' => str_starts_with($number, '7') ? 6 : 1,
            'docState'     => 40,
            'docStateMain' => 3,
        ]))->execute();
        $this->createdAccounts[] = (int) $dibi->getInsertId();
    }

    private function balanceId(string $code): int
    {
        $row = $this->db->fetchRow('SELECT id FROM economy_accbal_balances WHERE code = %s AND docState IN (10,40,80)', $code);
        if ($row === null) {
            $this->markTestSkipped("DS nemá naseedované saldokonto '{$code}'");
        }
        return (int) $row['id'];
    }

    // ── Čtení ────────────────────────────────────────────────────────────────

    /**
     * Případ testovacího klíče ve skupině (CaseQuery); bez pohybů prázdný agregát.
     *
     * @return array<string, mixed>
     */
    private function caseOf(string $balanceCode, string $currency = 'czk'): array
    {
        $case = (new CaseQuery($this->db->getDibiConnection()))->caseOf([
            'balance'           => $this->balanceId($balanceCode),
            'fiscal_year'       => $this->fiscalYear,
            'partner'           => self::PARTNER,
            'payment_reference' => self::VS,
            'specific_symbol'   => self::SS,
            'currency'          => $currency,
        ]);
        return $case ?? ['residual' => 0.0, 'residual_hc' => 0.0, 'sum_requests' => 0.0, 'sum_requests_hc' => 0.0, 'sum_payments' => 0.0, 'kind' => 'none'];
    }

    /** @return array<string, mixed>|null */
    private function ledgerMove(int $txId, string $balanceCode): ?array
    {
        $row = $this->db->getDibiConnection()->fetch(
            'SELECT * FROM economy_accbal_ledger WHERE source_kind = %s AND source_id = %i AND balance = %i',
            'bankTransaction', $txId, $this->balanceId($balanceCode),
        );
        return $row?->toArray();
    }

    /** @return list<array<string, mixed>> */
    private function journalOfTx(int $txId): array
    {
        $rows = $this->db->getDibiConnection()->fetchAll(
            'SELECT * FROM economy_accounting_journal WHERE bank_transaction = %i ORDER BY id', $txId,
        );
        return array_map(fn($r) => $r->toArray(), $rows);
    }

    /** @return list<array<string, mixed>> */
    private function journalOfDoc(int $headId): array
    {
        $rows = $this->db->getDibiConnection()->fetchAll(
            'SELECT * FROM economy_accounting_journal WHERE doc_head = %i ORDER BY id', $headId,
        );
        return array_map(fn($r) => $r->toArray(), $rows);
    }

    /** @param list<array<string, mixed>> $journal @return list<array<string, mixed>> */
    private function journalShape(array $journal): array
    {
        return array_map(static function (array $line): array {
            unset($line['id']);
            return array_map(static fn($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v, $line);
        }, $journal);
    }

    /** @param list<array<string, mixed>> $journal @return array<string, mixed> */
    private function lineByPrefix(array $journal, string $prefix): array
    {
        foreach ($journal as $line) {
            if (str_starts_with((string) $line['account_number'], $prefix)) {
                return $line;
            }
        }
        $this->fail("Řádek deníku s účtem {$prefix}* nenalezen");
    }

    /** @param list<array<string, mixed>> $journal */
    private function assertBalanced(array $journal): void
    {
        $dr = 0.0;
        $cr = 0.0;
        foreach ($journal as $line) {
            $dr += (float) $line['money_dr'];
            $cr += (float) $line['money_cr'];
        }
        $this->assertEqualsWithDelta($dr, $cr, 0.001, 'Σ MD == Σ DAL');
    }

    /**
     * Účty 757100 a 799100 mají přes výzvu a její úhrady nulový zůstatek
     * v domácí měně.
     *
     * @param list<int> $txIds
     * @param list<int> $docIds
     */
    private function assertOffBalanceNetsToZero(int $proformaId, array $txIds, array $docIds = []): void
    {
        $lines = $this->journalOfDoc($proformaId);
        foreach ($txIds as $txId) {
            $lines = [...$lines, ...$this->journalOfTx($txId)];
        }
        foreach ($docIds as $docId) {
            $lines = [...$lines, ...$this->journalOfDoc($docId)];
        }
        foreach (['757100', '799100'] as $account) {
            $net = 0.0;
            foreach ($lines as $line) {
                if ((string) $line['account_number'] === $account) {
                    $net += (float) $line['money_dr'] - (float) $line['money_cr'];
                }
            }
            $this->assertEqualsWithDelta(0.0, $net, 0.001, "{$account} v součtu nula");
        }
    }
}

class ClosureInJournalSpy extends AbstractJournalEventHandler
{
    /** @var list<array{0: string, 1: int}> */
    public static array $calls = [];

    public function onJournalWritten(string $sourceKind, int $sourceId): void
    {
        self::$calls[] = [$sourceKind, $sourceId];
    }
}

<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Accbal;

use Shipard\Api\JournalContributorLoader;
use Shipard\Api\JournalEventHandlerLoader;
use Shipard\Api\OpenItemLookupLoader;
use Shipard\Core\Accounting\JournalContributorSet;
use Shipard\Core\Accounting\OpenItemLookup;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Document\JournalEventDispatcher;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Economy\Accbal\CaseQuery;
use Shipard\Module\Economy\Accounting\AccountDocument;
use Shipard\Module\Economy\Accounting\AccountingEngine;
use Shipard\Module\Economy\Bank\BankTransactionAccountingEngine;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Uzavírání zálohových faktur při úhradě (#79 D3b/D3c,
 * tasks/accbal-proforma-closure.md) nad reálným DS: proforma na
 * podrozvaze → úhrada s jejím VS (banka i pokladna) → v deníku úhrady
 * 221/324 + 799/756, případ v Zálohových fakturách vydaných uzavřený,
 * v Přijatých zálohách otevřený předpis; částečné úhrady, přeplatek,
 * idempotence reaccountu, cizí měna kurzem proformy, jiný fiskální rok,
 * konečná faktura s odpočtem zálohy. Enginy s reálným journalWritten
 * dispatcherem, lookupem a contributory z module.jsonc.
 */
class ProformaClosureTest extends IntegrationTestCase
{
    private const ACC_DATE = '2026-06-10';
    private const PARTNER  = 990007;
    private const VS       = 'IT-CLOSE-2026';
    private const SS       = '31';

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
        $this->balanceId('proformas_out');
        $this->ensureAccountByNumber('756100');
        $this->ensureAccountByNumber('799100');
        $this->ensureAccountByNumber('261200');
        $this->accountByMask('324');
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

    public function testBankPaymentClosesProformaAndOpensReceivedAdvance(): void
    {
        $proformaId = $this->postProforma(10000.0, 21.0);
        $this->assertEqualsWithDelta(12100.0, $this->caseOf('proformas_out')['residual'], 0.001, 'proforma otevřená');

        [$txId, $result] = $this->payByBank(12100.0);

        $this->assertSame(1, $result['state'], json_encode($result['messages']));
        $this->assertSame([], $result['messages']);
        $journal = $this->journalOfTx($txId);
        $this->assertCount(4, $journal, '221/324 + 799/756');
        $this->assertBalanced($journal);
        $this->assertEqualsWithDelta(12100.0, (float) $this->lineByPrefix($journal, '221')['money_dr'], 0.001);
        $advance = $this->lineByPrefix($journal, '324');
        $this->assertEqualsWithDelta(12100.0, (float) $advance['money_cr'], 0.001, 'peníze na přijatou zálohu');
        $this->assertSame('payment.in', (string) $advance['operation']);
        $contra = $this->lineByPrefix($journal, '799100');
        $this->assertEqualsWithDelta(12100.0, (float) $contra['money_dr'], 0.001, 'uzavření: 799 MD');
        $this->assertNull($contra['operation'], 'příspěvek bez operace');
        $this->assertSame('Uzavření zálohové faktury vydané ' . self::VS, (string) $contra['text']);
        $closing = $this->lineByPrefix($journal, '756100');
        $this->assertEqualsWithDelta(12100.0, (float) $closing['money_cr'], 0.001, 'uzavření: 756100 DAL');
        $this->assertSame(self::VS, (string) $closing['payment_reference']);
        $this->assertSame(self::SS, (string) $closing['specific_symbol']);
        $this->assertSame(self::PARTNER, (int) $closing['partner']);

        // Saldo: proforma uzavřená, přijatá záloha otevřený předpis pod klíčem proformy.
        $proforma = $this->caseOf('proformas_out');
        $this->assertEqualsWithDelta(0.0, $proforma['residual'], 0.001);
        $this->assertSame(CaseQuery::KIND_CLOSED, $proforma['kind']);
        $closure = $this->ledgerMove($txId, 'proformas_out');
        $this->assertNotNull($closure);
        $this->assertSame(1, (int) $closure['bal_side'], '756100 DAL = úhrada v Zálohových fakturách vydaných');
        $this->assertSame('756100', (string) $closure['account_number']);
        $received = $this->caseOf('advances_received');
        $this->assertEqualsWithDelta(12100.0, $received['residual'], 0.001, 'přijatá záloha otevřená');
        $this->assertSame(CaseQuery::KIND_DEBT, $received['kind']);
        $this->assertOffBalanceNetsToZero($proformaId, [$txId]);
    }

    public function testPartialPaymentsConsumeResidualInOrder(): void
    {
        $proformaId = $this->postProforma(10000.0, 21.0);

        [$first] = $this->payByBank(5000.0);
        $this->assertEqualsWithDelta(5000.0, (float) $this->lineByPrefix($this->journalOfTx($first), '756100')['money_cr'], 0.001);
        $this->assertEqualsWithDelta(7100.0, $this->caseOf('proformas_out')['residual'], 0.001, 'zbytek otevřený');

        [$second] = $this->payByBank(10000.0);
        $journal = $this->journalOfTx($second);
        $this->assertEqualsWithDelta(10000.0, (float) $this->lineByPrefix($journal, '324')['money_cr'], 0.001, 'celá platba jde na zálohu');
        $this->assertEqualsWithDelta(7100.0, (float) $this->lineByPrefix($journal, '756100')['money_cr'], 0.001, 'uzavření jen do výše rezidua');
        $this->assertEqualsWithDelta(0.0, $this->caseOf('proformas_out')['residual'], 0.001);
        $this->assertEqualsWithDelta(15000.0, $this->caseOf('advances_received')['sum_requests'], 0.001, 'přebytek zůstává na zálohách');
        $this->assertOffBalanceNetsToZero($proformaId, [$first, $second]);

        // Třetí platba už proformu nenajde (uzavřená) → clearing, bez uzavření.
        [$third] = $this->payByBank(100.0);
        $journal = $this->journalOfTx($third);
        $this->assertCount(2, $journal);
        $this->assertSame('261200', (string) $this->lineByPrefix($journal, '261')['account_number']);
    }

    public function testOverpaymentClosesOnlyResidual(): void
    {
        $proformaId = $this->postProforma(10000.0, 21.0);

        [$txId] = $this->payByBank(15000.0);

        $journal = $this->journalOfTx($txId);
        $this->assertEqualsWithDelta(15000.0, (float) $this->lineByPrefix($journal, '324')['money_cr'], 0.001);
        $this->assertEqualsWithDelta(12100.0, (float) $this->lineByPrefix($journal, '756100')['money_cr'], 0.001);
        $this->assertEqualsWithDelta(0.0, $this->caseOf('proformas_out')['residual'], 0.001, 'proforma není přeplacená');
        $this->assertOffBalanceNetsToZero($proformaId, [$txId]);
    }

    public function testReaccountIsIdempotentAndKeepsMovementIds(): void
    {
        $this->postProforma(10000.0, 21.0);
        [$txId] = $this->payByBank(12100.0);
        $before = $this->journalShape($this->journalOfTx($txId));
        $closureIdBefore = (int) $this->ledgerMove($txId, 'proformas_out')['id'];

        $result = $this->bankEngine()->accountTransaction($txId);

        $this->assertSame(1, $result['state'], json_encode($result['messages']));
        $this->assertSame($before, $this->journalShape($this->journalOfTx($txId)), 'reaccount = shodný deník');
        $this->assertEqualsWithDelta(0.0, $this->caseOf('proformas_out')['residual'], 0.001, 'proforma dál uzavřená, ne dvakrát');
        $this->assertSame($closureIdBefore, (int) $this->ledgerMove($txId, 'proformas_out')['id'], 'id pohybu přežije reaccount (D13)');
    }

    public function testForeignCurrencyClosesAtProformaRateAndSettlesRemainder(): void
    {
        // Proforma 1 000 EUR kurzem 25,123 = 25 123 Kč; platby 333 + 333 + 334
        // EUR vlastními kurzy. Uzavření jde kurzem proformy, poslední dorovná
        // haléře: Σ 756100 DAL v Kč = 25 123 = 756100 MD proformy.
        $proformaId = $this->postProforma(1000.0, 0.0, ['doc_currency' => 'eur', 'exchange_rate' => 25.123, 'rate' => 25.123]);
        $this->assertEqualsWithDelta(25123.0, (float) $this->lineByPrefix($this->journalOfDoc($proformaId), '756100')['money_dr'], 0.001);

        [$a] = $this->payByBank(333.0, ['currency' => 'eur', 'amount_dom' => 8325.0]);
        [$b] = $this->payByBank(333.0, ['currency' => 'eur', 'amount_dom' => 8391.6]);
        [$c] = $this->payByBank(334.0, ['currency' => 'eur', 'amount_dom' => 8283.2]);

        foreach ([[$a, 333.0, 8365.96], [$b, 333.0, 8365.96], [$c, 334.0, 8391.08]] as [$txId, $cur, $dom]) {
            $closing = $this->lineByPrefix($this->journalOfTx($txId), '756100');
            $this->assertEqualsWithDelta($cur, (float) $closing['money_cr_cur'], 0.001, "tx {$txId}: EUR");
            $this->assertEqualsWithDelta($dom, (float) $closing['money_cr'], 0.001, "tx {$txId}: Kč kurzem proformy");
            $this->assertSame('eur', (string) $closing['currency']);
        }
        $proforma = $this->caseOf('proformas_out', 'eur');
        $this->assertEqualsWithDelta(0.0, $proforma['residual'], 0.001);
        $this->assertEqualsWithDelta(0.0, $proforma['residual_hc'], 0.001, 'podrozvaha v Kč na nulu');
        $this->assertOffBalanceNetsToZero($proformaId, [$a, $b, $c]);
        // Kurzový rozdíl zůstává na zálohách (Σ dom plateb ≠ Σ dom uzavření).
        $this->assertEqualsWithDelta(8325.0 + 8391.6 + 8283.2, $this->caseOf('advances_received', 'eur')['sum_requests_hc'], 0.001);
    }

    public function testProformaInOtherFiscalYearIsNotClosed(): void
    {
        // Předpis proformy v jiném období (D11): lookup ji nenajde → platba
        // na clearing bez uzavření; předpis zůstává otevřený.
        $this->seedLedgerRequest('proformas_out', '756100', 12100.0, ['fiscal_year' => $this->otherFiscalYear()]);

        [$txId] = $this->payByBank(12100.0);

        $journal = $this->journalOfTx($txId);
        $this->assertCount(2, $journal);
        $this->assertSame('261200', (string) $this->lineByPrefix($journal, '261')['account_number']);
        $this->assertNull($this->ledgerMove($txId, 'proformas_out'));
    }

    // ── Pokladna ─────────────────────────────────────────────────────────────

    public function testCashAdvanceClosesProforma(): void
    {
        $proformaId = $this->postProforma(10000.0, 21.0);

        $cashId = $this->postCashAdvance(12100.0);

        $journal = $this->journalOfDoc($cashId);
        $this->assertCount(4, $journal, '211/324 + 799/756');
        $this->assertBalanced($journal);
        $this->assertEqualsWithDelta(12100.0, (float) $this->lineByPrefix($journal, '211')['money_dr'], 0.001);
        $advance = $this->lineByPrefix($journal, '324');
        $this->assertEqualsWithDelta(12100.0, (float) $advance['money_cr'], 0.001);
        $this->assertSame('advance.received', (string) $advance['operation']);
        $this->assertEqualsWithDelta(12100.0, (float) $this->lineByPrefix($journal, '799100')['money_dr'], 0.001);
        $closing = $this->lineByPrefix($journal, '756100');
        $this->assertEqualsWithDelta(12100.0, (float) $closing['money_cr'], 0.001);
        $this->assertNull($closing['operation']);
        $this->assertSame(self::VS, (string) $closing['payment_reference']);

        $this->assertEqualsWithDelta(0.0, $this->caseOf('proformas_out')['residual'], 0.001, 'proforma uzavřená pokladnou');
        $this->assertEqualsWithDelta(12100.0, $this->caseOf('advances_received')['residual'], 0.001);
        $this->assertOffBalanceNetsToZero($proformaId, [], [$cashId]);
    }

    // ── Konečná faktura ──────────────────────────────────────────────────────

    public function testFinalInvoiceWithDeductionClosesReceivedAdvance(): void
    {
        $this->postProforma(10000.0, 21.0);
        [$txId] = $this->payByBank(12100.0);
        $this->assertEqualsWithDelta(12100.0, $this->caseOf('advances_received')['residual'], 0.001);

        $invoiceId = $this->postFinalInvoice(10000.0, 21.0, 12100.0);

        $journal = $this->journalOfDoc($invoiceId);
        $this->assertBalanced($journal);
        $deduction = $this->lineByPrefix($journal, '324');
        $this->assertEqualsWithDelta(12100.0, (float) $deduction['money_dr'], 0.001, 'odpočet zálohy 324 MD');
        $this->assertSame(self::VS, (string) $deduction['payment_reference']);
        $this->assertSame(self::SS, (string) $deduction['specific_symbol']);
        foreach ($journal as $line) {
            $this->assertStringStartsNotWith('756', (string) $line['account_number'], 'faktura podrozvahu neuzavírá znovu');
            $this->assertStringStartsNotWith('799', (string) $line['account_number']);
        }

        $this->assertSame(CaseQuery::KIND_CLOSED, $this->caseOf('advances_received')['kind'], 'přijatá záloha uzavřená fakturou');
        $this->assertSame(CaseQuery::KIND_CLOSED, $this->caseOf('proformas_out')['kind'], 'proforma uzavřená úhradou');
        $this->assertCount(4, $this->journalOfTx($txId), 'deník úhrady beze změny');
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

    /** @param array<string, mixed> $over @return array{0: int, 1: array{state: int, messages: list<array<string, mixed>>}} */
    private function payByBank(float $amount, array $over = []): array
    {
        $txId = $this->insertTx(array_merge([
            'amount'     => $amount,
            'amount_dom' => $amount,
        ], $over));
        return [$txId, $this->bankEngine()->accountTransaction($txId)];
    }

    /** Proforma ve stavu 40 zaúčtovaná enginem (756 MD / 799 DAL). @param array<string, mixed> $over */
    private function postProforma(float $base, float $vatPct, array $over = []): int
    {
        $rate = (float) ($over['rate'] ?? 1.0);
        unset($over['rate']);
        $headId = $this->insertHead('invpo', $base, $vatPct, $rate, array_merge(['doc_text' => 'IT proforma'], $over));
        $result = $this->docEngine()->accountDocument($headId);
        $this->assertSame(1, $result['state'], json_encode($result['messages']));
        return $headId;
    }

    /** Pokladní příjem se zálohou (advance.received) pod klíčem proformy, zaúčtovaný enginem. */
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
            'cash_dir'         => 1,
            'cash_desk'        => (int) $desk['id'],
            'payment_method'   => 0,
            'doc_text'         => 'IT pokladní záloha',
            'total_base'       => $amount, 'total_vat' => 0.0, 'total_amount' => $amount,
            'total_base_dom'   => $amount, 'total_vat_dom' => 0.0, 'total_amount_dom' => $amount,
        ]))->execute();
        $headId = (int) $dibi->getInsertId();
        $this->createdHeads[] = $headId;
        $dibi->insert('docs_core_rows', [
            'doc_head' => $headId, 'row_kind' => 1, 'operation' => 'advance.received',
            'description' => 'Záloha na proformu', 'vat_code' => null, 'vat_pct' => null,
            'vat_base' => $amount, 'vat_amount' => 0.0, 'vat_total' => $amount,
            'vat_base_dom' => $amount, 'vat_amount_dom' => 0.0, 'vat_total_dom' => $amount,
            'partner' => self::PARTNER, 'payment_reference' => self::VS, 'specific_symbol' => self::SS,
        ])->execute();

        $result = $this->docEngine()->accountDocument($headId);
        $this->assertSame(1, $result['state'], json_encode($result['messages']));
        return $headId;
    }

    /** Konečná faktura: plnění + odpočet zálohy tax0 s VS a SS proformy. */
    private function postFinalInvoice(float $base, float $vatPct, float $advance): int
    {
        $vat = round($base * $vatPct / 100.0, 2);
        $headId = $this->insertHead('invno', $base, $vatPct, 1.0, [
            'doc_text'         => 'IT konečná faktura',
            'total_base'       => round($base - $advance, 2), 'total_vat' => $vat, 'total_amount' => round($base + $vat - $advance, 2),
            'total_base_dom'   => round($base - $advance, 2), 'total_vat_dom' => $vat, 'total_amount_dom' => round($base + $vat - $advance, 2),
        ]);
        $this->db->getDibiConnection()->insert('docs_core_rows', [
            'doc_head' => $headId, 'row_kind' => 1, 'operation' => 'sale.advanceDeduction',
            'description' => 'Odpočet přijaté zálohy', 'vat_code' => null, 'vat_pct' => null,
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
            'doc_number'        => 'IT-CLOSE-' . uniqid(),
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
     * Hlavička se řádkem plnění a rekapitulací (CZK nebo cizí měna kurzem).
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
            'doc_head' => $headId, 'row_kind' => 1, 'operation' => 'sale.services',
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

    /** @param array<string, mixed> $over */
    private function insertTx(array $over): int
    {
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('economy_bank_transactions', array_merge([
            'bank_account'      => $this->bankAccountId,
            'direction'         => 1,
            'operation'         => 'payment.in',
            'currency'          => 'czk',
            'exchange_rate'     => 1,
            'date_transaction'  => self::ACC_DATE,
            'counterparty_name' => 'IT protistrana',
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
        $docId = 980_700_000 + (++$this->seq);
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
            'name'               => 'IT closure bankovní účet',
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
     * Účty 756100 a 799100 mají přes proformu a její úhrady nulový zůstatek
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
        foreach (['756100', '799100'] as $account) {
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

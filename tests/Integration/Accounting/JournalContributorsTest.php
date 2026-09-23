<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Accounting;

use Shipard\Core\Accounting\JournalContributor;
use Shipard\Core\Accounting\JournalContributorSet;
use Shipard\Core\Accounting\JournalLineRequest;
use Shipard\Core\Accounting\JournalLineView;
use Shipard\Core\Accounting\JournalSourceContext;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Economy\Accounting\AccountDocument;
use Shipard\Module\Economy\Accounting\AccountingEngine;
use Shipard\Module\Economy\Bank\BankTransactionAccountingEngine;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Contributoři deníku v obou enginech (#79 D3b) nad reálným DS s fake
 * contributorem: kontext a pohledy na řádky, doplnění požadavků
 * (kategorie i přesný účet), nenalezený účet → chybový řádek,
 * nevyrovnané požadavky → `unbalanced`, výjimka contributoru → deník bez
 * příspěvku + varování se stavem OK, bankovní engine odmítne cizí
 * identitu, prázdná sada = výstup shodný s enginem bez sady.
 */
class JournalContributorsTest extends IntegrationTestCase
{
    private const ACC_DATE = '2026-06-10';
    private const PARTNER  = 990006;
    private const VS       = 'IT-CONTRIB-2026';
    private const SS       = '55';

    private ?ConfigRuntime $config = null;
    private int $fiscalYear = 0;
    private ?int $bankAccountId = null;

    /** @var list<int> */
    private array $createdHeads = [];
    /** @var list<int> */
    private array $createdTxs = [];
    /** @var list<int> */
    private array $createdBankAccounts = [];
    /** @var list<int> */
    private array $createdAccounts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = ConfigRuntime::load($this->realDsPath, 'cs');
        $fy = $this->db->fetchRow(
            'SELECT id FROM economy_codebooks_fiscal_years WHERE date_begin <= %s AND date_end >= %s LIMIT 1',
            self::ACC_DATE, self::ACC_DATE,
        );
        if ($fy === null) {
            $this->markTestSkipped('DS nemá fiskální rok pro ' . self::ACC_DATE);
        }
        $this->fiscalYear = (int) $fy['id'];
        $this->ensureAccountByNumber('756100');
        $this->ensureAccountByNumber('799100');
        $this->ensureAccountByNumber('261200');
    }

    protected function onTearDown(): void
    {
        $dibi = $this->db->getDibiConnection();
        foreach ($this->createdHeads as $id) {
            $dibi->delete('economy_accbal_ledger')->where('doc_head = %i', $id)->execute();
            $dibi->delete('economy_accounting_journal')->where('doc_head = %i', $id)->execute();
            $dibi->delete('docs_core_vat_recap')->where('doc_head = %i', $id)->execute();
            $dibi->delete('docs_core_rows')->where('doc_head = %i', $id)->execute();
            $dibi->delete('docs_core_heads')->where('id = %i', $id)->execute();
        }
        foreach ($this->createdTxs as $id) {
            $dibi->delete('economy_accbal_ledger')->where('bank_transaction = %i', $id)->execute();
            $dibi->delete('economy_accounting_journal')->where('bank_transaction = %i', $id)->execute();
            $dibi->delete('economy_bank_transactions')->where('id = %i', $id)->execute();
        }
        foreach ($this->createdBankAccounts as $id) {
            $dibi->delete('economy_codebooks_bank_accounts')->where('id = %i', $id)->execute();
        }
        foreach ($this->createdAccounts as $id) {
            $dibi->delete('economy_accounting_accounts')->where('id = %i', $id)->execute();
        }
    }

    // ── Dokladový engine ─────────────────────────────────────────────────────

    public function testDocEngineAppendsRequestedLinesAndPassesContext(): void
    {
        $headId = $this->insertInvoice(1000.0, 21.0);
        $spy = new SpyContributor(static fn(JournalSourceContext $ctx, array $lines): array => [
            new JournalLineRequest(0, 'offbalance.contra', null, self::PARTNER, self::VS, self::SS, 100.0, 100.0, 'Příspěvek MD'),
            new JournalLineRequest(1, null, '756100', self::PARTNER, self::VS, self::SS, 100.0, 100.0, 'Příspěvek DAL'),
        ]);

        $result = $this->docEngine(new JournalContributorSet([$spy]))->accountDocument($headId);

        $this->assertSame(1, $result['state'], json_encode($result['messages']));
        $this->assertSame([], $result['messages']);

        // Kontext zdroje.
        $this->assertNotNull($spy->context);
        $this->assertSame('doc', $spy->context->sourceKind);
        $this->assertSame($headId, $spy->context->sourceId);
        $this->assertSame(self::ACC_DATE, $spy->context->accountingDate);
        $this->assertSame($this->fiscalYear, $spy->context->fiscalYear);
        $this->assertSame('czk', $spy->context->currency);

        // Pohledy: 311 MD, 602 DAL, 343 DAL — identita z hlavičky, částka strany.
        $this->assertCount(3, $spy->lines);
        $receivable = $this->viewByPrefix($spy->lines, '311');
        $this->assertSame(0, $receivable->side);
        $this->assertEqualsWithDelta(1210.0, $receivable->moneyDom, 0.001);
        $this->assertEqualsWithDelta(1210.0, $receivable->moneyCur, 0.001);
        $this->assertSame(self::PARTNER, $receivable->partner);
        $this->assertSame(self::VS, $receivable->paymentReference);
        $this->assertSame(self::SS, $receivable->specificSymbol);
        $this->assertSame(1, $this->viewByPrefix($spy->lines, '602')->side);
        $this->assertSame('sale.services', $this->viewByPrefix($spy->lines, '602')->operation);

        // Deník: 3 vlastní + 2 doplněné, oba dohledané, s identitou z požadavku.
        $journal = $this->journalOf($headId);
        $this->assertCount(5, $journal);
        $this->assertBalanced($journal);
        $contra = $this->lineByPrefix($journal, '799100');
        $this->assertNotNull($contra['account'], 'kategorie dohledána maskou v rozvrhu');
        $this->assertSame(0, (int) $contra['is_error']);
        $this->assertEqualsWithDelta(100.0, (float) $contra['money_dr'], 0.001);
        $this->assertNull($contra['operation'], 'příspěvek nenese operaci');
        $this->assertSame('Příspěvek MD', (string) $contra['text']);
        $this->assertSame(self::VS, (string) $contra['payment_reference']);
        $this->assertSame(self::SS, (string) $contra['specific_symbol']);
        $this->assertSame(self::PARTNER, (int) $contra['partner']);
        $this->assertNull($contra['due_date']);
        $closing = $this->lineByPrefix($journal, '756100');
        $this->assertNotNull($closing['account'], 'přesný účet ověřen v rozvrhu');
        $this->assertEqualsWithDelta(100.0, (float) $closing['money_cr'], 0.001);
        $this->assertSame('Příspěvek DAL', (string) $closing['text']);
        $this->assertSame(1, $this->accountingStateOfDoc($headId));
    }

    public function testDocEngineUnknownAccountsBecomeErrorRows(): void
    {
        $headId = $this->insertInvoice(1000.0, 21.0);
        $set = new JournalContributorSet([new SpyContributor(static fn(): array => [
            new JournalLineRequest(0, 'no.such.category', null, self::PARTNER, self::VS, self::SS, 50.0, 50.0, 'a'),
            new JournalLineRequest(1, null, '999999', self::PARTNER, self::VS, self::SS, 50.0, 50.0, 'b'),
        ])]);

        $result = $this->docEngine($set)->accountDocument($headId);

        $this->assertSame(2, $result['state']);
        $codes = array_column($result['messages'], 'code');
        $this->assertSame(['account_not_found', 'account_not_found'], $codes);
        $journal = $this->journalOf($headId);
        $this->assertCount(5, $journal);
        $unknownCat = $this->lineByPrefix($journal, '??????');
        $this->assertSame(1, (int) $unknownCat['is_error']);
        $this->assertNull($unknownCat['account']);
        $unknownAcc = $this->lineByPrefix($journal, '999999');
        $this->assertSame(1, (int) $unknownAcc['is_error']);
        $this->assertNull($unknownAcc['account']);
    }

    public function testDocEngineUnbalancedRequestsAreReported(): void
    {
        $headId = $this->insertInvoice(1000.0, 21.0);
        $set = new JournalContributorSet([new SpyContributor(static fn(): array => [
            new JournalLineRequest(0, 'offbalance.contra', null, self::PARTNER, self::VS, self::SS, 100.0, 100.0, 'jen MD'),
        ])]);

        $result = $this->docEngine($set)->accountDocument($headId);

        $this->assertSame(2, $result['state']);
        $this->assertSame(['unbalanced'], array_column($result['messages'], 'code'));
        $this->assertCount(4, $this->journalOf($headId), 'příspěvek se zapíše, nevyrovnanost je zpráva');
    }

    public function testDocEngineContributorExceptionIsWarningOnly(): void
    {
        $headId = $this->insertInvoice(1000.0, 21.0);
        $set = new JournalContributorSet([
            new SpyContributor(static function (): array {
                throw new \RuntimeException('contributor boom');
            }),
            new SpyContributor(static fn(): array => [
                new JournalLineRequest(0, 'offbalance.contra', null, self::PARTNER, self::VS, self::SS, 10.0, 10.0, 'x'),
                new JournalLineRequest(1, null, '756100', self::PARTNER, self::VS, self::SS, 10.0, 10.0, 'y'),
            ]),
        ]);

        $result = $this->docEngine($set)->accountDocument($headId);

        $this->assertSame(1, $result['state'], 'varování stav nemění');
        $this->assertCount(1, $result['messages']);
        $this->assertSame('contributor_failed', $result['messages'][0]['code']);
        $this->assertSame('warning', $result['messages'][0]['level']);
        $this->assertStringContainsString('contributor boom', $result['messages'][0]['message']);
        $this->assertCount(5, $this->journalOf($headId), 'druhý contributor běží dál');
        $this->assertSame(1, $this->accountingStateOfDoc($headId));
        $stored = $this->db->fetchRow('SELECT accounting_messages FROM docs_core_heads WHERE id = %i', $headId);
        $this->assertStringContainsString('contributor_failed', (string) $stored['accounting_messages'], 'varování je uložené');
    }

    public function testDocEngineEmptySetMatchesEngineWithoutSet(): void
    {
        $headId = $this->insertInvoice(1000.0, 21.0);

        $plain = $this->docEngine(null)->accountDocument($headId);
        $expected = $this->journalShape($this->journalOf($headId));

        $withEmpty = $this->docEngine(JournalContributorSet::empty())->accountDocument($headId);

        $this->assertSame($plain, $withEmpty);
        $this->assertSame($expected, $this->journalShape($this->journalOf($headId)));
        $this->assertCount(3, $expected);
    }

    // ── Bankovní engine ──────────────────────────────────────────────────────

    public function testBankEngineAppendsRequestedLinesWithTransactionIdentity(): void
    {
        $txId = $this->insertPayment(1210.0);
        $spy = new SpyContributor(static fn(): array => [
            new JournalLineRequest(0, 'offbalance.contra', null, self::PARTNER, self::VS, self::SS, 1210.0, 1210.0, 'Uzavření'),
            new JournalLineRequest(1, null, '756100', self::PARTNER, self::VS, self::SS, 1210.0, 1210.0, 'Uzavření'),
        ]);

        $result = $this->bankEngine(new JournalContributorSet([$spy]))->accountTransaction($txId);

        $this->assertSame(1, $result['state'], json_encode($result['messages']));
        $this->assertNotNull($spy->context);
        $this->assertSame('bankTransaction', $spy->context->sourceKind);
        $this->assertSame($txId, $spy->context->sourceId);
        $this->assertSame(self::ACC_DATE, $spy->context->accountingDate);
        $this->assertSame($this->fiscalYear, $spy->context->fiscalYear);
        $this->assertSame('czk', $spy->context->currency);

        // Pohledy nesou identitu z transakce (řádky bankovního enginu ji samy nemají).
        $this->assertCount(2, $spy->lines);
        $clearing = $this->viewByPrefix($spy->lines, '261200');
        $this->assertSame(1, $clearing->side);
        $this->assertSame('payment.in', $clearing->operation);
        $this->assertSame(self::PARTNER, $clearing->partner);
        $this->assertSame(self::VS, $clearing->paymentReference);
        $this->assertSame(self::SS, $clearing->specificSymbol);
        $this->assertEqualsWithDelta(1210.0, $clearing->moneyDom, 0.001);
        $this->assertNull($this->viewByPrefix($spy->lines, '221')->operation);

        $journal = $this->journalOfTx($txId);
        $this->assertCount(4, $journal);
        $this->assertBalanced($journal);
        $contra = $this->lineByPrefix($journal, '799100');
        $this->assertEqualsWithDelta(1210.0, (float) $contra['money_dr'], 0.001);
        $this->assertSame('Uzavření', (string) $contra['text']);
        $this->assertNull($contra['operation']);
        $this->assertSame(self::VS, (string) $contra['payment_reference']);
        $closing = $this->lineByPrefix($journal, '756100');
        $this->assertEqualsWithDelta(1210.0, (float) $closing['money_cr'], 0.001);
        $this->assertSame(1, $this->accountingStateOfTx($txId));
    }

    public function testBankEngineRejectsForeignIdentity(): void
    {
        $txId = $this->insertPayment(1210.0);
        $set = new JournalContributorSet([new SpyContributor(static fn(): array => [
            new JournalLineRequest(0, 'offbalance.contra', null, self::PARTNER + 1, self::VS, self::SS, 1.0, 1.0, 'x'),
            new JournalLineRequest(1, null, '756100', self::PARTNER, self::VS, self::SS, 1.0, 1.0, 'y'),
        ])]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('identity');
        $this->bankEngine($set)->accountTransaction($txId);
    }

    public function testBankEngineContributorExceptionIsWarningOnly(): void
    {
        $txId = $this->insertPayment(1210.0);
        $set = new JournalContributorSet([new SpyContributor(static function (): array {
            throw new \RuntimeException('bank contributor boom');
        })]);

        $result = $this->bankEngine($set)->accountTransaction($txId);

        $this->assertSame(1, $result['state']);
        $this->assertSame('contributor_failed', $result['messages'][0]['code']);
        $this->assertSame('warning', $result['messages'][0]['level']);
        $this->assertCount(2, $this->journalOfTx($txId), 'deník bez příspěvku');
        $this->assertSame(1, $this->accountingStateOfTx($txId));
    }

    public function testBankEngineEmptySetMatchesEngineWithoutSet(): void
    {
        $txId = $this->insertPayment(1210.0);

        $plain = $this->bankEngine(null)->accountTransaction($txId);
        $expected = $this->journalShape($this->journalOfTx($txId));

        $withEmpty = $this->bankEngine(JournalContributorSet::empty())->accountTransaction($txId);

        $this->assertSame($plain, $withEmpty);
        $this->assertSame($expected, $this->journalShape($this->journalOfTx($txId)));
        $this->assertCount(2, $expected);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function docEngine(?JournalContributorSet $set): AccountingEngine
    {
        return new AccountingEngine($this->db->getDibiConnection(), $this->config, null, $set);
    }

    private function bankEngine(?JournalContributorSet $set): BankTransactionAccountingEngine
    {
        return new BankTransactionAccountingEngine($this->db->getDibiConnection(), $this->config, null, null, $set);
    }

    /** @param list<JournalLineView> $lines */
    private function viewByPrefix(array $lines, string $prefix): JournalLineView
    {
        foreach ($lines as $line) {
            if (str_starts_with($line->accountNumber, $prefix)) {
                return $line;
            }
        }
        $this->fail("Pohled na řádek s účtem {$prefix}* nenalezen");
    }

    /**
     * Tvar deníku bez volatilního id — pro porovnání dvou běhů.
     *
     * @param list<array<string, mixed>> $journal
     * @return list<array<string, mixed>>
     */
    private function journalShape(array $journal): array
    {
        return array_map(static function (array $line): array {
            unset($line['id']);
            return array_map(static fn($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v, $line);
        }, $journal);
    }

    private function insertInvoice(float $base, float $vatPct): int
    {
        $fm = $this->db->fetchRow(
            'SELECT id FROM economy_codebooks_fiscal_months WHERE date_begin <= %s AND date_end >= %s AND period_type = 1 LIMIT 1',
            self::ACC_DATE, self::ACC_DATE,
        );
        $series = $this->db->fetchRow('SELECT id FROM docs_core_number_series WHERE doc_type = %s LIMIT 1', 'invno');
        if ($fm === null || $series === null) {
            $this->markTestSkipped('DS nemá fiskální měsíc / řadu invno pro ' . self::ACC_DATE);
        }
        $vat   = round($base * $vatPct / 100.0, 2);
        $total = round($base + $vat, 2);

        $dibi = $this->db->getDibiConnection();
        $dibi->insert('docs_core_heads', [
            'doc_type'          => 'invno',
            'number_series'     => (int) $series['id'],
            'doc_number'        => 'IT-CONTRIB-' . uniqid(),
            'issue_date'        => self::ACC_DATE,
            'accounting_date'   => self::ACC_DATE,
            'due_date'          => '2026-06-24',
            'fiscal_year'       => $this->fiscalYear,
            'fiscal_month'      => (int) $fm['id'],
            'partner'           => self::PARTNER,
            'payment_reference' => self::VS,
            'specific_symbol'   => self::SS,
            'payment_method'    => 1,
            'doc_currency'      => 'czk',
            'home_currency'     => 'czk',
            'exchange_rate'     => 1.0,
            'doc_text'          => 'IT contributor faktura',
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

    /** Příjem bez otevřeného předpisu (jde na clearing 261200) s identitou testu. */
    private function insertPayment(float $amount): int
    {
        $this->bankAccountId ??= $this->insertBankAccount($this->accountIdByMask('221'));
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('economy_bank_transactions', [
            'bank_account'      => $this->bankAccountId,
            'direction'         => 1,
            'operation'         => 'payment.in',
            'amount'            => $amount,
            'amount_dom'        => $amount,
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
        ])->execute();
        $id = (int) $dibi->getInsertId();
        $this->createdTxs[] = $id;
        return $id;
    }

    private function insertBankAccount(int $accountingAccountId): int
    {
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('economy_codebooks_bank_accounts', [
            'code'               => 'IT' . substr(uniqid(), -8),
            'name'               => 'IT contributor bankovní účet',
            'currency'           => 'czk',
            'accounting_account' => $accountingAccountId,
            'docState'           => 40,
            'docStateMain'       => 3,
        ])->execute();
        $id = (int) $dibi->getInsertId();
        $this->createdBankAccounts[] = $id;
        return $id;
    }

    private function accountIdByMask(string $mask): int
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

    /** @return list<array<string, mixed>> */
    private function journalOf(int $headId): array
    {
        $rows = $this->db->getDibiConnection()->fetchAll(
            'SELECT * FROM economy_accounting_journal WHERE doc_head = %i ORDER BY id', $headId,
        );
        return array_map(fn($r) => $r->toArray(), $rows);
    }

    /** @return list<array<string, mixed>> */
    private function journalOfTx(int $txId): array
    {
        $rows = $this->db->getDibiConnection()->fetchAll(
            'SELECT * FROM economy_accounting_journal WHERE bank_transaction = %i ORDER BY id', $txId,
        );
        return array_map(fn($r) => $r->toArray(), $rows);
    }

    /**
     * @param list<array<string, mixed>> $journal
     * @return array<string, mixed>
     */
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

    private function accountingStateOfDoc(int $headId): int
    {
        $row = $this->db->fetchRow('SELECT accounting_state FROM docs_core_heads WHERE id = %i', $headId);
        return (int) $row['accounting_state'];
    }

    private function accountingStateOfTx(int $txId): int
    {
        $row = $this->db->fetchRow('SELECT accounting_state FROM economy_bank_transactions WHERE id = %i', $txId);
        return (int) $row['accounting_state'];
    }
}

/** Fake contributor: zaznamená kontext a pohledy, vrátí, co mu dá callback. */
class SpyContributor implements JournalContributor
{
    public ?JournalSourceContext $context = null;
    /** @var list<JournalLineView> */
    public array $lines = [];

    /** @param \Closure(JournalSourceContext, list<JournalLineView>): list<JournalLineRequest> $factory */
    public function __construct(private readonly \Closure $factory) {}

    public function contribute(JournalSourceContext $context, array $lines): array
    {
        $this->context = $context;
        $this->lines = $lines;
        return ($this->factory)($context, $lines);
    }
}

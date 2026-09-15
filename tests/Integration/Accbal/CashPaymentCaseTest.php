<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Accbal;

use Shipard\Api\JournalEventHandlerLoader;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Document\JournalEventDispatcher;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Docs\Core\BoundNumberSeriesProvisioner;
use Shipard\Module\Economy\Accbal\CaseQuery;
use Shipard\Module\Economy\Accounting\AccountingEngine;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Úhrada pohledávky na pokladním dokladu (payment.receivable, #59 D7)
 * uzavře případ otevřené FVB: deník nese partnera + VS z řádku,
 * LedgerGenerator z něj udělá úhradu (bal_side 1) v saldokontu receivables
 * a případ (klíč skupina / období / partner / VS, #69 D1) má zůstatek 0 —
 * žádný matcher ani alokace, jen agregát z ledgeru (CaseQuery).
 *
 * Předpis 311 se seeduje přímo do ledgeru (izolace od generátoru), PD se
 * účtuje reálným enginem s journalWritten handlery.
 */
class CashPaymentCaseTest extends IntegrationTestCase
{
    private const ACC_DATE = '2026-06-10';
    private const PARTNER  = 990002;
    private const VS       = '2026000077';
    /** Protistrana terminálu — plátce PD kartou (#72): pohledávka 311 s VS = číslo PD. */
    private const TERMINAL_PARTNER = 990003;
    private const PD_VS            = '2026100077';

    private ?JournalEventDispatcher $journalEvents = null;
    private ?ConfigRuntime $config = null;
    private int $fiscalYear = 0;

    /** @var list<int> */
    private array $createdHeads = [];
    /** @var list<int> */
    private array $createdCashDesks = [];
    /** @var list<int> */
    private array $createdSeries = [];
    /** @var list<int> */
    private array $seededDocs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = ConfigRuntime::load($this->realDsPath, 'cs');
        $resolver = new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        $this->journalEvents = JournalEventHandlerLoader::load(
            $this->dsConfig,
            $resolver,
            $this->db->getDibiConnection(),
            $this->config,
        );
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
        foreach (array_merge($this->createdHeads, $this->seededDocs) as $id) {
            $dibi->delete('economy_accbal_ledger')->where('doc_head = %i', $id)->execute();
        }
        foreach ($this->createdHeads as $id) {
            $dibi->delete('economy_accounting_journal')->where('doc_head = %i', $id)->execute();
            $dibi->delete('docs_core_rows')->where('doc_head = %i', $id)->execute();
            $dibi->delete('docs_core_heads')->where('id = %i', $id)->execute();
        }
        foreach ($this->createdSeries as $id) {
            $dibi->delete('docs_core_number_counters')->where('number_series = %i', $id)->execute();
            $dibi->delete('docs_core_number_series')->where('id = %i', $id)->execute();
        }
        foreach ($this->createdCashDesks as $id) {
            $dibi->delete('economy_codebooks_cash_desks')->where('id = %i', $id)->execute();
        }
    }

    public function testCashPaymentOfReceivableClosesCase(): void
    {
        $recv = $this->balanceId('receivables');
        $this->seedReceivableRequest(1210.00);
        $key = [
            'balance'           => $recv,
            'fiscal_year'       => $this->fiscalYear,
            'partner'           => self::PARTNER,
            'payment_reference' => self::VS,
            'specific_symbol'   => null,
            'currency'          => 'czk',
        ];
        $query = new CaseQuery($this->db->getDibiConnection());

        $before = $query->caseOf($key);
        $this->assertNotNull($before);
        $this->assertSame(CaseQuery::KIND_DEBT, $before['kind'], 'před úhradou dluh');
        $this->assertEqualsWithDelta(1210.00, $before['residual'], 0.001);

        $headId = $this->accountCashPayment(1210.00);

        // deník: 311 DAL s identitou řádku
        $line = $this->db->fetchRow(
            'SELECT * FROM economy_accounting_journal WHERE doc_head = %i AND account_number LIKE %like~',
            $headId, '311',
        );
        $this->assertNotNull($line, 'úhrada na 311');
        $this->assertSame(self::PARTNER, (int) $line['partner']);
        $this->assertSame(self::VS, (string) $line['payment_reference']);

        // ledger: úhrada (bal_side 1) v receivables se stejným klíčem, ne clearing
        $payment = $this->db->fetchRow(
            'SELECT * FROM economy_accbal_ledger WHERE doc_head = %i AND balance = %i AND bal_side = 1',
            $headId, $recv,
        );
        $this->assertNotNull($payment, 'LedgerGenerator udělal z 311 DAL úhradu v receivables');
        $this->assertSame(self::PARTNER, (int) $payment['partner']);
        $this->assertSame(self::VS, (string) $payment['payment_reference']);
        $this->assertSame($this->fiscalYear, (int) $payment['fiscal_year'], 'období z deníku = období klíče');
        $this->assertEqualsWithDelta(1210.00, (float) $payment['amount'], 0.001);

        // případ: Σ předpisy − Σ úhrady = 0, žádný matcher
        $after = $query->caseOf($key);
        $this->assertNotNull($after);
        $this->assertEqualsWithDelta(0.0, $after['residual'], 0.001, 'případ uzavřen úhradou z PD');
        $this->assertSame(CaseQuery::KIND_CLOSED, $after['kind']);
        $this->assertFalse($after['is_open']);
        $this->assertSame(2, $after['moves']);

        // Karta (#72 D1): hlavičkový řádek 311 MD za protistranou terminálu
        // s VS = číslo PD otevírá nový případ dlužníka (terminál) — uzavře ho
        // vyúčtování úhrad / banka, ne tento doklad.
        $terminalCase = $query->caseOf([
            'balance'           => $recv,
            'fiscal_year'       => $this->fiscalYear,
            'partner'           => self::TERMINAL_PARTNER,
            'payment_reference' => self::PD_VS,
            'specific_symbol'   => null,
            'currency'          => 'czk',
        ]);
        $this->assertNotNull($terminalCase, 'PD kartou založil pohledávku za terminálem');
        $this->assertSame(CaseQuery::KIND_DEBT, $terminalCase['kind']);
        $this->assertEqualsWithDelta(1210.00, $terminalCase['residual'], 0.001);
        $this->assertTrue($terminalCase['is_open']);
        $this->assertSame(0, (int) $this->db->fetchRow(
            'SELECT COUNT(*) AS c FROM economy_accounting_journal WHERE doc_head = %i AND account_number LIKE %like~',
            $headId, '261',
        )['c'], 'tranzit 261400 se neúčtuje');
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function balanceId(string $code): int
    {
        $row = $this->db->fetchRow('SELECT id FROM economy_accbal_balances WHERE code = %s', $code);
        if ($row === null) {
            $this->markTestSkipped("DS nemá naseedované saldokonto '{$code}'");
        }
        return (int) $row['id'];
    }

    /** Otevřený předpis 311 (bal_side 0) partnera s VS — jako zaúčtovaná FVB. */
    private function seedReceivableRequest(float $amount): int
    {
        $docId = 980_100_000 + random_int(1, 99_999);
        $this->seededDocs[] = $docId;
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('economy_accbal_ledger', [
            'balance'           => $this->balanceId('receivables'),
            'bal_side'          => 0,
            'source_kind'       => 'doc',
            'source_id'         => $docId,
            'doc_head'          => $docId,
            'account_number'    => '311100',
            'fiscal_year'       => $this->fiscalYear,
            'partner'           => self::PARTNER,
            'payment_reference' => self::VS,
            'currency'          => 'czk',
            'home_currency'     => 'czk',
            'amount'            => $amount,
            'amount_hc'         => $amount,
            'due_date'          => '2026-06-30',
        ])->execute();
        return (int) $dibi->getInsertId();
    }

    /** Příjmový PD kartou s řádkem payment.receivable, zaúčtovaný reálným enginem. */
    private function accountCashPayment(float $amount): int
    {
        $fm = $this->db->fetchRow(
            'SELECT id FROM economy_codebooks_fiscal_months WHERE date_begin <= %s AND date_end >= %s AND period_type = 1 LIMIT 1',
            self::ACC_DATE, self::ACC_DATE,
        );
        if ($fm === null) {
            $this->markTestSkipped('DS nemá fiskální měsíc pro ' . self::ACC_DATE);
        }
        $account211 = $this->db->fetchRow(
            'SELECT id FROM economy_accounting_accounts WHERE number LIKE %like~ AND account_level = 4 AND docState IN (10,40,80) ORDER BY number LIMIT 1',
            '211',
        );
        if ($account211 === null) {
            $this->markTestSkipped('DS nemá analytiku 211');
        }

        $dibi = $this->db->getDibiConnection();
        $dibi->insert('economy_codebooks_cash_desks', [
            'code' => 'IT' . substr(uniqid(), -5), 'name' => 'IT pokladna', 'currency' => 'czk',
            'accounting_account' => (int) $account211['id'], 'docState' => 40, 'docStateMain' => 3,
        ])->execute();
        $deskId = (int) $dibi->getInsertId();
        $this->createdCashDesks[] = $deskId;
        (new BoundNumberSeriesProvisioner($this->db, $this->config))->provisionForCashDesk($deskId);
        foreach ($this->db->fetchAll('SELECT id FROM docs_core_number_series WHERE cash_desk = %i', $deskId) as $s) {
            $this->createdSeries[] = (int) $s['id'];
        }
        $series = $this->db->fetchRow(
            'SELECT id FROM docs_core_number_series WHERE doc_type = %s AND cash_desk = %i', 'cash', $deskId,
        );
        $this->assertNotNull($series, 'provisioner založil řadu cash pro pokladnu');

        $dibi->insert('docs_core_heads', [
            'doc_type' => 'cash', 'number_series' => (int) $series['id'], 'cash_desk' => $deskId,
            'cash_dir' => 1, 'payment_method' => 2,
            // plátce = protistrana terminálu (PartnerBalanceResolver by ji odvodil
            // z default terminálu pokladny; hlavička jde raw SQL, proto přímo)
            'partner_balance' => self::TERMINAL_PARTNER, 'payment_reference' => self::PD_VS,
            'doc_number' => 'IT-CASHPAY-' . uniqid(),
            'issue_date' => self::ACC_DATE, 'accounting_date' => self::ACC_DATE, 'due_date' => self::ACC_DATE,
            'fiscal_year' => $this->fiscalYear, 'fiscal_month' => (int) $fm['id'],
            'partner' => null, 'doc_currency' => 'czk', 'home_currency' => 'czk', 'exchange_rate' => 1.0,
            'doc_text' => 'IT úhrada kartou',
            'total_base' => $amount, 'total_vat' => 0.0, 'total_amount' => $amount,
            'total_base_dom' => $amount, 'total_vat_dom' => 0.0, 'total_amount_dom' => $amount,
            'docState' => 40, 'docStateMain' => 2,
        ])->execute();
        $headId = (int) $dibi->getInsertId();
        $this->createdHeads[] = $headId;

        $dibi->insert('docs_core_rows', [
            'doc_head' => $headId, 'row_kind' => 1, 'operation' => 'payment.receivable',
            'description' => 'Úhrada FVB', 'price_calc_mode' => 1, 'total_price' => $amount,
            'partner' => self::PARTNER, 'payment_reference' => self::VS,
            'vat_code' => null, 'vat_pct' => 0,
            'vat_base' => $amount, 'vat_amount' => 0.0, 'vat_total' => $amount,
            'vat_base_dom' => $amount, 'vat_amount_dom' => 0.0, 'vat_total_dom' => $amount,
        ])->execute();

        $engine = new AccountingEngine($dibi, $this->config, $this->journalEvents);
        $result = $engine->accountDocument($headId);
        $this->assertSame(1, $result['state'], json_encode($result['messages']));
        return $headId;
    }
}

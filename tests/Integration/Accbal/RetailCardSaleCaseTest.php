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
 * Prodejka kartou (#72 D1/D3) = pohledávka 311 za protistranou terminálu
 * s VS = číslo prodejky; interní doklad „Vyúčtování úhrad" s řádkem
 * acc.balanceReceivable DAL 311 za týmž partnerem a VS ji v saldokontu
 * uzavře (klíč skupina / období / partner / VS, #69). Model starého
 * Shipardu, na kterém stojí porovnání DS btpg-p (M2).
 *
 * Oba doklady jdou raw SQL a účtují se reálným enginem s journalWritten
 * handlery (LedgerGenerator) — stejně jako CashPaymentCaseTest.
 */
class RetailCardSaleCaseTest extends IntegrationTestCase
{
    private const ACC_DATE = '2026-06-10';
    private const TERMINAL_PARTNER = 990004;
    private const VS = '2026300021';
    /** VS dávky vyúčtování — pohledávka 315 za bránou má vlastní klíč, ne VS prodejky. */
    private const BATCH_VS = 'D2026300021';

    private ?JournalEventDispatcher $journalEvents = null;
    private ?ConfigRuntime $config = null;
    private int $fiscalYear = 0;
    private int $fiscalMonth = 0;

    /** @var list<int> */
    private array $createdHeads = [];
    /** @var list<int> */
    private array $createdCashDesks = [];
    /** @var list<int> */
    private array $createdSeries = [];
    /** @var list<int> */
    private array $createdTerminals = [];

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
        $fm = $this->db->fetchRow(
            'SELECT id FROM economy_codebooks_fiscal_months WHERE date_begin <= %s AND date_end >= %s AND period_type = 1 LIMIT 1',
            self::ACC_DATE, self::ACC_DATE,
        );
        if ($fy === null || $fm === null) {
            $this->markTestSkipped('DS nemá fiskální období pro ' . self::ACC_DATE);
        }
        $this->fiscalYear = (int) $fy['id'];
        $this->fiscalMonth = (int) $fm['id'];
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
        foreach ($this->createdSeries as $id) {
            $dibi->delete('docs_core_number_counters')->where('number_series = %i', $id)->execute();
            $dibi->delete('docs_core_number_series')->where('id = %i', $id)->execute();
        }
        foreach ($this->createdTerminals as $id) {
            $dibi->delete('economy_codebooks_payment_terminals')->where('id = %i', $id)->execute();
        }
        foreach ($this->createdCashDesks as $id) {
            $dibi->delete('economy_codebooks_cash_desks')->where('id = %i', $id)->execute();
        }
    }

    public function testRetailCardSaleOpensCaseForTerminalAndSettlementClosesIt(): void
    {
        $recv = $this->balanceId('receivables');
        $query = new CaseQuery($this->db->getDibiConnection());
        $key = [
            'balance'           => $recv,
            'fiscal_year'       => $this->fiscalYear,
            'partner'           => self::TERMINAL_PARTNER,
            'payment_reference' => self::VS,
            'specific_symbol'   => null,
            'currency'          => 'czk',
        ];
        $this->assertNull($query->caseOf($key), 'před prodejkou žádný případ terminálu');

        // 1. Prodejka kartou → 311 MD za terminálem, VS = číslo prodejky.
        $saleId = $this->accountRetailCardSale(1000.0, 210.0);
        $request = $this->db->fetchRow(
            'SELECT * FROM economy_accounting_journal WHERE doc_head = %i AND account_number LIKE %like~',
            $saleId, '311',
        );
        $this->assertNotNull($request, 'pohledávka na 311');
        $this->assertSame(self::TERMINAL_PARTNER, (int) $request['partner'], 'partner = protistrana terminálu');
        $this->assertSame(self::VS, (string) $request['payment_reference']);
        $this->assertEqualsWithDelta(1210.0, (float) $request['money_dr'], 0.001);
        $this->assertSame(0, (int) $this->db->fetchRow(
            'SELECT COUNT(*) AS c FROM economy_accounting_journal WHERE doc_head = %i AND account_number LIKE %like~',
            $saleId, '261',
        )['c'], 'žádný tranzit 261400');

        $open = $query->caseOf($key);
        $this->assertNotNull($open, 'ledger má předpis za terminálem');
        $this->assertSame(CaseQuery::KIND_DEBT, $open['kind']);
        $this->assertEqualsWithDelta(1210.0, $open['residual'], 0.001);
        $this->assertTrue($open['is_open']);

        // 2. Vyúčtování úhrad: acc.balanceReceivable DAL 311 za terminálem s týmž VS.
        $settlementId = $this->accountSettlement(1210.0);
        $payment = $this->db->fetchRow(
            'SELECT * FROM economy_accbal_ledger WHERE doc_head = %i AND balance = %i AND bal_side = 1',
            $settlementId, $recv,
        );
        $this->assertNotNull($payment, 'LedgerGenerator udělal z 311 DAL úhradu');
        $this->assertSame(self::TERMINAL_PARTNER, (int) $payment['partner']);
        $this->assertSame(self::VS, (string) $payment['payment_reference']);

        $closed = $query->caseOf($key);
        $this->assertNotNull($closed);
        $this->assertEqualsWithDelta(0.0, $closed['residual'], 0.001, 'vyúčtování případ uzavřelo');
        $this->assertSame(CaseQuery::KIND_CLOSED, $closed['kind']);
        $this->assertFalse($closed['is_open']);
        $this->assertSame(2, $closed['moves']);

        // 3. 315 je v Pohledávkách (#72 D6): dávka vyúčtování otevírá vlastní
        // případ (terminál, VS dávky), který uzavře až připsání bankou.
        $batch = $query->caseOf(['payment_reference' => self::BATCH_VS] + $key);
        $this->assertNotNull($batch, '315 MD založil pohledávku dávky');
        $this->assertSame(CaseQuery::KIND_DEBT, $batch['kind']);
        $this->assertEqualsWithDelta(1210.0, $batch['residual'], 0.001);
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

    private function accountIdByPrefix(string $prefix): int
    {
        $row = $this->db->fetchRow(
            'SELECT id FROM economy_accounting_accounts WHERE number LIKE %like~ AND account_level = 4 AND docState IN (10,40,80) ORDER BY number LIMIT 1',
            $prefix,
        );
        if ($row === null) {
            $this->markTestSkipped("DS nemá analytiku {$prefix}");
        }
        return (int) $row['id'];
    }

    /** @return array<string, mixed> společná hlavička ve stavu 40 */
    private function headBase(string $docType, int $seriesId, float $base, float $vat): array
    {
        $total = round($base + $vat, 2);
        return [
            'doc_type' => $docType, 'number_series' => $seriesId, 'cash_dir' => 0,
            'doc_number' => 'IT-RCS-' . uniqid(),
            'issue_date' => self::ACC_DATE, 'accounting_date' => self::ACC_DATE, 'due_date' => self::ACC_DATE,
            'fiscal_year' => $this->fiscalYear, 'fiscal_month' => $this->fiscalMonth,
            'partner' => null, 'doc_currency' => 'czk', 'home_currency' => 'czk', 'exchange_rate' => 1.0,
            'total_base' => $base, 'total_vat' => $vat, 'total_amount' => $total,
            'total_base_dom' => $base, 'total_vat_dom' => $vat, 'total_amount_dom' => $total,
            'docState' => 40, 'docStateMain' => 2,
        ];
    }

    private function insertHead(array $head): int
    {
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('docs_core_heads', $head)->execute();
        $id = (int) $dibi->getInsertId();
        $this->createdHeads[] = $id;
        return $id;
    }

    /** Prodejka kartou nad novou pokladnou s terminálem, zaúčtovaná enginem. */
    private function accountRetailCardSale(float $base, float $vat): int
    {
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('economy_codebooks_cash_desks', [
            'code' => 'IT' . substr(uniqid(), -5), 'name' => 'IT pokladna', 'currency' => 'czk',
            'accounting_account' => $this->accountIdByPrefix('211'), 'docState' => 40, 'docStateMain' => 3,
        ])->execute();
        $deskId = (int) $dibi->getInsertId();
        $this->createdCashDesks[] = $deskId;
        (new BoundNumberSeriesProvisioner($this->db, $this->config))->provisionForCashDesk($deskId);
        foreach ($this->db->fetchAll('SELECT id FROM docs_core_number_series WHERE cash_desk = %i', $deskId) as $s) {
            $this->createdSeries[] = (int) $s['id'];
        }
        $series = $this->db->fetchRow(
            'SELECT id FROM docs_core_number_series WHERE doc_type = %s AND cash_desk = %i', 'cashreg', $deskId,
        );
        $this->assertNotNull($series, 'provisioner založil řadu cashreg pro pokladnu');

        $dibi->insert('economy_codebooks_payment_terminals', [
            'code' => 'IT' . substr(uniqid(), -5), 'name' => 'IT terminál', 'kind' => 0,
            'cash_desk' => $deskId, 'partner' => self::TERMINAL_PARTNER, 'is_default' => 1,
            'docState' => 40, 'docStateMain' => 3,
        ])->execute();
        $terminalId = (int) $dibi->getInsertId();
        $this->createdTerminals[] = $terminalId;

        $headId = $this->insertHead($this->headBase('cashreg', (int) $series['id'], $base, $vat) + [
            'cash_desk' => $deskId, 'payment_method' => 2,
            'payment_terminal' => $terminalId, 'partner_balance' => self::TERMINAL_PARTNER,
            'payment_reference' => self::VS, 'doc_text' => 'IT prodejka kartou',
        ]);
        $amount = round($base * 0.21, 2);
        $dibi->insert('docs_core_rows', [
            'doc_head' => $headId, 'row_kind' => 1, 'operation' => 'sale.goods',
            'description' => 'Zboží', 'vat_code' => 'cz-120', 'vat_pct' => 21.0,
            'vat_base' => $base, 'vat_amount' => $amount, 'vat_total' => round($base + $amount, 2),
            'vat_base_dom' => $base, 'vat_amount_dom' => $amount, 'vat_total_dom' => round($base + $amount, 2),
        ])->execute();
        $dibi->insert('docs_core_vat_recap', [
            'doc_head' => $headId, 'vat_code' => 'cz-120', 'vat_pct' => 21.0,
            'base' => $base, 'tax' => $vat, 'total' => round($base + $vat, 2),
            'base_dom' => $base, 'tax_dom' => $vat, 'total_dom' => round($base + $vat, 2),
            'sum_base' => 1, 'sum_tax' => 1, 'sum_total' => 1, 'is_reverse_pair' => 0, 'order_pos' => 0,
        ])->execute();

        $engine = new AccountingEngine($dibi, $this->config, $this->journalEvents);
        $result = $engine->accountDocument($headId);
        $this->assertSame(1, $result['state'], json_encode($result['messages']));
        return $headId;
    }

    /**
     * Účetní doklad „Vyúčtování úhrad": acc.balanceReceivable DAL 311 za
     * terminálem s VS prodejky, protistrana MD 315 (pohledávka za bránou /
     * terminálem per dávka, kterou pak zaplatí banka — #72 D6).
     */
    private function accountSettlement(float $amount): int
    {
        $series = $this->db->fetchRow('SELECT id FROM docs_core_number_series WHERE doc_type = %s LIMIT 1', 'cmnbkp');
        if ($series === null) {
            $this->markTestSkipped('DS nemá řadu účetních dokladů (cmnbkp)');
        }
        $headId = $this->insertHead($this->headBase('cmnbkp', (int) $series['id'], $amount, 0.0) + [
            'payment_method' => 1, 'vat_mode' => 0, 'doc_text' => 'IT vyúčtování úhrad',
        ]);
        $rowBase = [
            'doc_head' => $headId, 'row_kind' => 1, 'price_calc_mode' => 1, 'total_price' => $amount,
            'partner' => self::TERMINAL_PARTNER, 'payment_reference' => self::VS,
            'vat_code' => null, 'vat_pct' => 0,
            'vat_base' => $amount, 'vat_amount' => 0.0, 'vat_total' => $amount,
            'vat_base_dom' => $amount, 'vat_amount_dom' => 0.0, 'vat_total_dom' => $amount,
        ];
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('docs_core_rows', $rowBase + [
            'operation' => 'acc.balanceReceivable', 'description' => 'Vyúčtování úhrad — terminál', 'acc_side' => 1,
        ])->execute();
        $dibi->insert('docs_core_rows', array_merge($rowBase, [
            'operation' => 'acc.record', 'description' => 'Pohledávka za bránou (dávka)', 'acc_side' => 0,
            'account' => $this->accountIdByPrefix('315'), 'payment_reference' => self::BATCH_VS,
        ]))->execute();

        $engine = new AccountingEngine($this->db->getDibiConnection(), $this->config, $this->journalEvents);
        $result = $engine->accountDocument($headId);
        $this->assertSame(1, $result['state'], json_encode($result['messages']));
        return $headId;
    }
}

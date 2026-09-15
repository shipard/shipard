<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Accounting;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Docs\Core\BoundNumberSeriesProvisioner;
use Shipard\Module\Economy\Accounting\AccountingEngine;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Kontrolní příklady účtování pokladny (#59 D8, tasks/cash-docs-phase1.md
 * §5) nad reálným dev DS. Pokladnu a její vázané řady si test zakládá sám
 * (dev DS žádnou nemá) přes BoundNumberSeriesProvisioner a po sobě je maže.
 *
 * Dokladová data se vkládají přímo SQL s ručně spočtenými computed
 * hodnotami — engine čte DB, nezajímá ho, jak vznikla.
 */
class CashDocsAccountingTest extends IntegrationTestCase
{
    private const ACC_DATE = '2026-06-10';

    /** @var list<int> */
    private array $createdHeads = [];
    /** @var list<int> */
    private array $createdCashDesks = [];
    /** @var list<int> */
    private array $createdSeries = [];
    /** @var list<int> */
    private array $createdTerminals = [];
    /** @var list<int> */
    private array $createdTransports = [];

    private ?AccountingEngine $engine = null;
    private ?ConfigRuntime $config = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = ConfigRuntime::load($this->realDsPath, 'cs');
        $this->engine = new AccountingEngine($this->db->getDibiConnection(), $this->config);
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
        foreach ($this->createdSeries as $id) {
            $dibi->delete('docs_core_number_counters')->where('number_series = %i', $id)->execute();
            $dibi->delete('docs_core_number_series')->where('id = %i', $id)->execute();
        }
        foreach ($this->createdTerminals as $id) {
            $dibi->delete('economy_codebooks_payment_terminals')->where('id = %i', $id)->execute();
        }
        foreach ($this->createdTransports as $id) {
            $dibi->delete('economy_codebooks_transports')->where('id = %i', $id)->execute();
        }
        foreach ($this->createdCashDesks as $id) {
            $dibi->delete('economy_codebooks_cash_desks')->where('id = %i', $id)->execute();
        }
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

    private function anyPartnerId(): int
    {
        $row = $this->db->fetchRow('SELECT id FROM base_persons_persons ORDER BY id LIMIT 1');
        if ($row === null) {
            $this->markTestSkipped('Dev DS nemá žádnou osobu');
        }
        return (int) $row['id'];
    }

    /** Druhá osoba (protistrana terminálu / brány / dopravce) — jiná než anyPartnerId(). */
    private function intermediaryPartnerId(): int
    {
        $row = $this->db->fetchRow('SELECT id FROM base_persons_persons ORDER BY id LIMIT 1 OFFSET 1');
        if ($row === null) {
            $this->markTestSkipped('Dev DS nemá druhou osobu pro protistranu terminálu');
        }
        return (int) $row['id'];
    }

    /** Terminál (kind 0, pokladna) nebo brána (kind 1) s osobou pro saldokonto, ve stavu 40. */
    private function createTerminal(?int $deskId, int $partner, int $kind = 0): int
    {
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('economy_codebooks_payment_terminals', [
            'code'         => 'IT' . substr(uniqid(), -5),
            'name'         => $kind === 0 ? 'IT terminál' : 'IT brána',
            'kind'         => $kind,
            'cash_desk'    => $kind === 0 ? $deskId : null,
            'partner'      => $partner,
            'is_default'   => 1,
            'docState'     => 40,
            'docStateMain' => 3,
        ])->execute();
        $id = (int) $dibi->getInsertId();
        $this->createdTerminals[] = $id;
        return $id;
    }

    private function createTransport(?int $partner): int
    {
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('economy_codebooks_transports', [
            'code'         => 'IT' . substr(uniqid(), -5),
            'name'         => 'IT dopravce',
            'partner'      => $partner,
            'docState'     => 40,
            'docStateMain' => 3,
        ])->execute();
        $id = (int) $dibi->getInsertId();
        $this->createdTransports[] = $id;
        return $id;
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
     * Pokladna ve stavu V pořádku + vázané řady (cash, cashreg) přes
     * provisioner — stejná cesta jako ds-upgrade / uložení pokladny.
     */
    private function createCashDesk(?int $accountingAccount, string $currency = 'czk'): int
    {
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('economy_codebooks_cash_desks', [
            'code'               => 'IT' . substr(uniqid(), -5),
            'name'               => 'IT pokladna',
            'currency'           => $currency,
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

    private function seriesFor(string $docType, ?int $deskId): int
    {
        $row = $deskId !== null
            ? $this->db->fetchRow(
                'SELECT id FROM docs_core_number_series WHERE doc_type = %s AND cash_desk = %i LIMIT 1',
                $docType, $deskId,
            )
            : $this->db->fetchRow('SELECT id FROM docs_core_number_series WHERE doc_type = %s LIMIT 1', $docType);
        if ($row === null) {
            $this->markTestSkipped("Chybí řada {$docType}" . ($deskId !== null ? ' pro pokladnu' : ''));
        }
        return (int) $row['id'];
    }

    /**
     * Hlavička ve stavu 40. Pro cash/cashreg: řada pokladny, cash_desk,
     * cash_dir, payment_method z $overrides.
     */
    private function insertHead(string $docType, ?int $deskId, float $base, float $vat, array $overrides = []): int
    {
        [$fy, $fm] = $this->fiscalIds();
        $total = round($base + $vat, 2);
        $head = array_merge([
            'doc_type'         => $docType,
            'number_series'    => $this->seriesFor($docType, in_array($docType, ['cash', 'cashreg'], true) ? $deskId : null),
            'cash_desk'        => $deskId,
            'cash_dir'         => 0,
            'payment_method'   => 0,
            'doc_number'       => 'IT-CASH-' . uniqid(),
            'issue_date'       => self::ACC_DATE,
            'accounting_date'  => self::ACC_DATE,
            'due_date'         => self::ACC_DATE,
            'fiscal_year'      => $fy,
            'fiscal_month'     => $fm,
            'partner'          => null,
            'doc_currency'     => 'czk',
            'home_currency'    => 'czk',
            'exchange_rate'    => 1.0,
            'doc_text'         => 'IT pokladní test',
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

    private function insertRow(int $headId, string $operation, float $base, float $vatPct, array $overrides = []): int
    {
        $amount = round($base * $vatPct / 100.0, 2);
        $row = array_merge([
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
        ], $overrides);
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('docs_core_rows', $row)->execute();
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

    // ── Assert helpers ──────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private function journalOf(int $headId): array
    {
        $rows = $this->db->fetchAll('SELECT * FROM economy_accounting_journal WHERE doc_head = %i ORDER BY id', $headId);
        return array_map(fn($r) => is_array($r) ? $r : $r->toArray(), $rows);
    }

    private function lineByPrefix(array $journal, string $prefix): array
    {
        $matches = array_values(array_filter($journal, fn($l) => str_starts_with((string) $l['account_number'], $prefix)));
        $this->assertCount(1, $matches, "Očekáván právě jeden řádek deníku {$prefix}*");
        return $matches[0];
    }

    /** Řádek deníku daného prefixu účtu a partnera (dva řádky 311 s různou identitou). */
    private function lineByPartner(array $journal, string $prefix, int $partner): array
    {
        $matches = array_values(array_filter(
            $journal,
            fn($l) => str_starts_with((string) $l['account_number'], $prefix) && (int) ($l['partner'] ?? 0) === $partner,
        ));
        $this->assertCount(1, $matches, "Očekáván právě jeden řádek deníku {$prefix}* partnera {$partner}");
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

    // ── Kontrolní příklady ──────────────────────────────────────────────────

    public function testReceiptCashSaleOfServices(): void
    {
        // Příjmový PD, hotově, prodej služby 1 000 + 21 %:
        // 602 DAL 1 000 · 343120 DAL 210 · 211 MD 1 210
        $deskId = $this->createCashDesk($this->accountIdByPrefix('211'));
        $headId = $this->insertHead('cash', $deskId, 1000.0, 210.0, ['cash_dir' => 1, 'payment_method' => 0]);
        $this->insertRow($headId, 'sale.services', 1000.0, 21.0);
        $this->insertRecap($headId, 1000.0, 210.0);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(1, $result['state'], json_encode($result['messages']));
        $journal = $this->journalOf($headId);
        $this->assertCount(3, $journal);
        $this->assertBalanced($journal);
        $this->assertEqualsWithDelta(1000.0, (float) $this->lineByPrefix($journal, '602')['money_cr'], 0.001);
        $this->assertEqualsWithDelta(210.0, (float) $this->lineByPrefix($journal, '343120')['money_cr'], 0.001);
        $this->assertEqualsWithDelta(1210.0, (float) $this->lineByPrefix($journal, '211')['money_dr'], 0.001);
        $this->assertNoLine($journal, '311');
        $this->assertNoLine($journal, '261');
    }

    public function testReceiptCardPaymentOfReceivableCarriesRowIdentity(): void
    {
        // Příjmový PD, kartou, úhrada FVB 1 210 (payment.receivable, VS):
        // 311 DAL 1 210 (zákazník + VS FVB z řádku) · 311 MD 1 210 za
        // protistranou terminálu s VS = číslo PD (#72 D1/D3) — žádný tranzit
        // 261400, dva řádky 311 se liší identitou (partner, VS).
        $partner  = $this->anyPartnerId();
        $terminalPartner = $this->intermediaryPartnerId();
        $deskId   = $this->createCashDesk($this->accountIdByPrefix('211'));
        $terminal = $this->createTerminal($deskId, $terminalPartner);
        $headId = $this->insertHead('cash', $deskId, 1210.0, 0.0, [
            'cash_dir' => 1, 'payment_method' => 2,
            'payment_terminal' => $terminal, 'partner_balance' => $terminalPartner,
            'payment_reference' => '2026100007',
        ]);
        $this->insertRow($headId, 'payment.receivable', 1210.0, 0.0, [
            'vat_code'          => null,
            'vat_pct'           => 0,
            'partner'           => $partner,
            'payment_reference' => '2026000042',
            'price_calc_mode'   => 1,
            'total_price'       => 1210.0,
        ]);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(1, $result['state'], json_encode($result['messages']));
        $journal = $this->journalOf($headId);
        $this->assertCount(2, $journal);
        $this->assertBalanced($journal);

        $payment = $this->lineByPartner($journal, '311', $partner);
        $this->assertEqualsWithDelta(1210.0, (float) $payment['money_cr'], 0.001);
        $this->assertSame('2026000042', (string) $payment['payment_reference'], 'VS faktury z řádku');
        $this->assertSame('payment.receivable', $payment['operation']);

        $request = $this->lineByPartner($journal, '311', $terminalPartner);
        $this->assertEqualsWithDelta(1210.0, (float) $request['money_dr'], 0.001, 'pohledávka za terminálem');
        $this->assertSame('2026100007', (string) $request['payment_reference'], 'VS = číslo PD');
        $this->assertNull($request['operation']);

        $this->assertNoLine($journal, '261');
        $this->assertNoLine($journal, '211');
    }

    public function testReceiptCardWithoutTerminalBooksReceivableForHeadPartner(): void
    {
        // DS bez terminálů: plátce = partner hlavičky (fallback odvození;
        // engine bere partner_balance ?? partner) — funguje jako dřív, jen na 311.
        $partner = $this->anyPartnerId();
        $deskId = $this->createCashDesk($this->accountIdByPrefix('211'));
        $headId = $this->insertHead('cash', $deskId, 1000.0, 210.0, [
            'cash_dir' => 1, 'payment_method' => 2, 'partner' => $partner, 'payment_reference' => '2026100008',
        ]);
        $this->insertRow($headId, 'sale.services', 1000.0, 21.0);
        $this->insertRecap($headId, 1000.0, 210.0);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(1, $result['state'], json_encode($result['messages']));
        $journal = $this->journalOf($headId);
        $this->assertBalanced($journal);
        $receivable = $this->lineByPrefix($journal, '311');
        $this->assertEqualsWithDelta(1210.0, (float) $receivable['money_dr'], 0.001);
        $this->assertSame($partner, (int) $receivable['partner']);
        $this->assertSame('2026100008', (string) $receivable['payment_reference']);
        $this->assertNoLine($journal, '261');
        $this->assertNoLine($journal, '211');
    }

    public function testRetailSaleByCardBooksReceivableForTerminalPartner(): void
    {
        // Prodejka kartou 1 000 + 21 %: 604 DAL · 343 DAL · 311 MD 1 210 za
        // protistranou terminálu pokladny s VS = číslo prodejky; 261400 nikde.
        $terminalPartner = $this->intermediaryPartnerId();
        $deskId   = $this->createCashDesk($this->accountIdByPrefix('211'));
        $terminal = $this->createTerminal($deskId, $terminalPartner);
        $headId = $this->insertHead('cashreg', $deskId, 1000.0, 210.0, [
            'payment_method' => 2, 'payment_terminal' => $terminal,
            'partner_balance' => $terminalPartner, 'payment_reference' => '2026200001',
        ]);
        $this->insertRow($headId, 'sale.goods', 1000.0, 21.0);
        $this->insertRecap($headId, 1000.0, 210.0);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(1, $result['state'], json_encode($result['messages']));
        $journal = $this->journalOf($headId);
        $this->assertBalanced($journal);
        $this->assertEqualsWithDelta(1000.0, (float) $this->lineByPrefix($journal, '604')['money_cr'], 0.001);
        $receivable = $this->lineByPrefix($journal, '311');
        $this->assertEqualsWithDelta(1210.0, (float) $receivable['money_dr'], 0.001);
        $this->assertSame($terminalPartner, (int) $receivable['partner'], 'plátce = protistrana terminálu');
        $this->assertSame('2026200001', (string) $receivable['payment_reference']);
        $this->assertNoLine($journal, '261');
        $this->assertNoLine($journal, '211');
    }

    public function testRetailSaleCashOnDeliveryBooksReceivableForCarrier(): void
    {
        // Prodejka na dobírku s dopravcem: 311 MD za dopravcem, VS = číslo prodejky.
        $carrier   = $this->intermediaryPartnerId();
        $deskId    = $this->createCashDesk($this->accountIdByPrefix('211'));
        $transport = $this->createTransport($carrier);
        $headId = $this->insertHead('cashreg', $deskId, 1000.0, 210.0, [
            'payment_method' => 3, 'transport' => $transport,
            'partner' => $this->anyPartnerId(), 'partner_balance' => $carrier, 'payment_reference' => '2026200002',
        ]);
        $this->insertRow($headId, 'sale.goods', 1000.0, 21.0);
        $this->insertRecap($headId, 1000.0, 210.0);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(1, $result['state'], json_encode($result['messages']));
        $journal = $this->journalOf($headId);
        $this->assertBalanced($journal);
        $receivable = $this->lineByPrefix($journal, '311');
        $this->assertEqualsWithDelta(1210.0, (float) $receivable['money_dr'], 0.001);
        $this->assertSame($carrier, (int) $receivable['partner'], 'plátce = dopravce, ne odběratel');
        $this->assertNoLine($journal, '211');
    }

    public function testRetailSaleByGatewayBooksReceivableForGatewayPartner(): void
    {
        // Prodejka bránou (5): 311 MD za protistranou brány.
        $gatewayPartner = $this->intermediaryPartnerId();
        $deskId  = $this->createCashDesk($this->accountIdByPrefix('211'));
        $gateway = $this->createTerminal(null, $gatewayPartner, 1);
        $headId = $this->insertHead('cashreg', $deskId, 1000.0, 210.0, [
            'payment_method' => 5, 'payment_terminal' => $gateway,
            'partner_balance' => $gatewayPartner, 'payment_reference' => '2026200003',
        ]);
        $this->insertRow($headId, 'sale.goods', 1000.0, 21.0);
        $this->insertRecap($headId, 1000.0, 210.0);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(1, $result['state'], json_encode($result['messages']));
        $journal = $this->journalOf($headId);
        $this->assertBalanced($journal);
        $receivable = $this->lineByPrefix($journal, '311');
        $this->assertEqualsWithDelta(1210.0, (float) $receivable['money_dr'], 0.001);
        $this->assertSame($gatewayPartner, (int) $receivable['partner']);
        $this->assertNoLine($journal, '261');
        $this->assertNoLine($journal, '211');
    }

    public function testDisbursementCashPurchaseOfGoods(): void
    {
        // Výdajový PD, hotově, nákup materiálu 500 + 21 %:
        // 504 MD 500 · 343120 MD 105 · 211 DAL 605
        $deskId = $this->createCashDesk($this->accountIdByPrefix('211'));
        $headId = $this->insertHead('cash', $deskId, 500.0, 105.0, ['cash_dir' => 2, 'payment_method' => 0]);
        $this->insertRow($headId, 'purchase.goods', 500.0, 21.0);
        $this->insertRecap($headId, 500.0, 105.0);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(1, $result['state'], json_encode($result['messages']));
        $journal = $this->journalOf($headId);
        $this->assertCount(3, $journal);
        $this->assertBalanced($journal);
        $this->assertEqualsWithDelta(500.0, (float) $this->lineByPrefix($journal, '504')['money_dr'], 0.001);
        $this->assertEqualsWithDelta(105.0, (float) $this->lineByPrefix($journal, '343120')['money_dr'], 0.001);
        $this->assertEqualsWithDelta(605.0, (float) $this->lineByPrefix($journal, '211')['money_cr'], 0.001);
        $this->assertNoLine($journal, '6', 'headQuery: příjmové kroky se u výdeje nepoužijí');
    }

    public function testRetailSaleCash(): void
    {
        // Prodejka hotově, zboží 1 000 + 21 %: 604 DAL · 343120 DAL · 211 MD
        $deskId = $this->createCashDesk($this->accountIdByPrefix('211'));
        $headId = $this->insertHead('cashreg', $deskId, 1000.0, 210.0, ['payment_method' => 0]);
        $this->insertRow($headId, 'sale.goods', 1000.0, 21.0);
        $this->insertRecap($headId, 1000.0, 210.0);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(1, $result['state'], json_encode($result['messages']));
        $journal = $this->journalOf($headId);
        $this->assertBalanced($journal);
        $this->assertEqualsWithDelta(1000.0, (float) $this->lineByPrefix($journal, '604')['money_cr'], 0.001);
        $this->assertEqualsWithDelta(1210.0, (float) $this->lineByPrefix($journal, '211')['money_dr'], 0.001);
    }

    public function testRetailSaleByBankTransferBooksReceivable(): void
    {
        // Prodejka „na převod“ (partner povinný): 604 DAL · 343 DAL · 311 MD s partnerem;
        // pokladna ani karty na cestě se nedotčou (#59, reimport 2026-09-08).
        $deskId  = $this->createCashDesk($this->accountIdByPrefix('211'));
        $partner = $this->anyPartnerId();
        $headId  = $this->insertHead('cashreg', $deskId, 1000.0, 210.0, ['payment_method' => 1, 'partner' => $partner]);
        $this->insertRow($headId, 'sale.goods', 1000.0, 21.0);
        $this->insertRecap($headId, 1000.0, 210.0);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(1, $result['state'], json_encode($result['messages']));
        $journal = $this->journalOf($headId);
        $this->assertBalanced($journal);
        $receivable = $this->lineByPrefix($journal, '311');
        $this->assertEqualsWithDelta(1210.0, (float) $receivable['money_dr'], 0.001);
        $this->assertSame($partner, (int) $receivable['partner'], 'pohledávka nese partnera hlavičky');
        $this->assertNoLine($journal, '211');
        $this->assertNoLine($journal, '261');
    }

    public function testRetailSaleRefundBooksNegativeAmounts(): void
    {
        // Vratka (D9): záporné řádky → záporné částky na obou stranách, vyrovnané.
        $deskId = $this->createCashDesk($this->accountIdByPrefix('211'));
        $headId = $this->insertHead('cashreg', $deskId, -1000.0, -210.0, ['payment_method' => 0]);
        $this->insertRow($headId, 'sale.goods', -1000.0, 21.0);
        $this->insertRecap($headId, -1000.0, -210.0);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(1, $result['state'], json_encode($result['messages']));
        $journal = $this->journalOf($headId);
        $this->assertBalanced($journal);
        $this->assertEqualsWithDelta(-1000.0, (float) $this->lineByPrefix($journal, '604')['money_cr'], 0.001);
        $this->assertEqualsWithDelta(-210.0, (float) $this->lineByPrefix($journal, '343120')['money_cr'], 0.001);
        $this->assertEqualsWithDelta(-1210.0, (float) $this->lineByPrefix($journal, '211')['money_dr'], 0.001);
    }

    public function testIssuedInvoicePaidInCashBooksOnCashDeskInsteadOfReceivable(): void
    {
        $deskId = $this->createCashDesk($this->accountIdByPrefix('211'));
        $headId = $this->insertHead('invno', $deskId, 1000.0, 210.0, [
            'payment_method' => 0, 'partner' => $this->anyPartnerId(),
        ]);
        $this->insertRow($headId, 'sale.services', 1000.0, 21.0);
        $this->insertRecap($headId, 1000.0, 210.0);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(1, $result['state'], json_encode($result['messages']));
        $journal = $this->journalOf($headId);
        $this->assertBalanced($journal);
        $this->assertEqualsWithDelta(1210.0, (float) $this->lineByPrefix($journal, '211')['money_dr'], 0.001);
        $this->assertNoLine($journal, '311');
    }

    public function testReceivedInvoicePaidInCashBooksOnCashDeskInsteadOfPayable(): void
    {
        $deskId = $this->createCashDesk($this->accountIdByPrefix('211'));
        $headId = $this->insertHead('invni', $deskId, 500.0, 105.0, [
            'payment_method' => 0, 'partner' => $this->anyPartnerId(),
        ]);
        $this->insertRow($headId, 'purchase.services', 500.0, 21.0);
        $this->insertRecap($headId, 500.0, 105.0);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(1, $result['state'], json_encode($result['messages']));
        $journal = $this->journalOf($headId);
        $this->assertBalanced($journal);
        $this->assertEqualsWithDelta(605.0, (float) $this->lineByPrefix($journal, '211')['money_cr'], 0.001);
        $this->assertNoLine($journal, '321');
    }

    public function testIssuedInvoiceByBankTransferStillBooksReceivable(): void
    {
        // regrese: {"$ne": 0} nesmí zlomit běžnou fakturu
        $headId = $this->insertHead('invno', null, 1000.0, 210.0, [
            'payment_method' => 1, 'partner' => $this->anyPartnerId(),
        ]);
        $this->insertRow($headId, 'sale.services', 1000.0, 21.0);
        $this->insertRecap($headId, 1000.0, 210.0);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(1, $result['state'], json_encode($result['messages']));
        $journal = $this->journalOf($headId);
        $this->assertEqualsWithDelta(1210.0, (float) $this->lineByPrefix($journal, '311')['money_dr'], 0.001);
        $this->assertNoLine($journal, '211');
    }

    public function testInvoicePaidInCashWithoutCashDeskProducesErrorLine(): void
    {
        $headId = $this->insertHead('invno', null, 1000.0, 210.0, [
            'payment_method' => 0, 'partner' => $this->anyPartnerId(),
        ]);
        $this->insertRow($headId, 'sale.services', 1000.0, 21.0);
        $this->insertRecap($headId, 1000.0, 210.0);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(2, $result['state']);
        $this->assertContains('cash_desk_account_missing', array_column($result['messages'], 'code'));
        $journal = $this->journalOf($headId);
        $error = $this->lineByPrefix($journal, '211???');
        $this->assertSame(1, (int) $error['is_error']);
        $this->assertNull($error['account']);
        $this->assertCount(3, $journal, 'ostatní řádky se zapíšou');
    }

    public function testCashDeskWithoutAccountingAccountProducesErrorLine(): void
    {
        $deskId = $this->createCashDesk(null);
        $headId = $this->insertHead('cash', $deskId, 1000.0, 210.0, ['cash_dir' => 1, 'payment_method' => 0]);
        $this->insertRow($headId, 'sale.services', 1000.0, 21.0);
        $this->insertRecap($headId, 1000.0, 210.0);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(2, $result['state']);
        $this->assertContains('cash_desk_account_missing', array_column($result['messages'], 'code'));
        $this->assertSame(1, (int) $this->lineByPrefix($this->journalOf($headId), '211???')['is_error']);
    }
}

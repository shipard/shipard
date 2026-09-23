<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Accounting;

use Shipard\Api\ReportDefinitionLoader;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Reports\ReportRunner;
use Shipard\Module\Economy\Accounting\AccountDocument;
use Shipard\Module\Economy\Accounting\AccountingEngine;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Zálohová faktura vydaná na podrozvaze (#79 D2, tasks/accbal-proformas-out.md)
 * nad reálným dev DS: potvrzená proforma dá deník 756100 MD / 799100 DAL
 * celkovou částkou hlavičky s identitou hlavičky (partner, VS, SS,
 * splatnost); řádky, DPH ani pohledávka se neúčtují. Rozvaha (třídy 0–4)
 * a výsledovka (5–6) třídu 7 nečtou — po zaúčtování jsou beze změny
 * a invariant vyrovnaného deníku drží.
 *
 * Vyžaduje řadu invpo a fiskální období pro ACC_DATE; účty 756100/799100
 * si test doplní, když chybí (DS před ds-upgrade), a uklidí.
 */
class ProformaAccountingTest extends IntegrationTestCase
{
    private const ACC_DATE = '2026-06-10';
    private const VS       = 'IT-PRO-2026';
    private const SS       = '77';

    /** @var list<int> */
    private array $createdHeads = [];
    /** @var list<int> */
    private array $createdAccounts = [];

    private ?AccountingEngine $engine = null;
    private ?ConfigRuntime $config = null;
    private int $partner = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = ConfigRuntime::load($this->realDsPath, 'cs');
        $this->engine = new AccountingEngine($this->db->getDibiConnection(), $this->config);
        $this->partner = $this->anyPartnerId();
        $this->ensureAccountByNumber('756100');
        $this->ensureAccountByNumber('799100');
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
        foreach ($this->createdAccounts as $id) {
            $dibi->delete('economy_accounting_accounts')->where('id = %i', $id)->execute();
        }
    }

    public function testConfirmedProformaBooksTotalOnOffBalanceOnly(): void
    {
        // Proforma 10 000 + 21 % = 12 100: řádek s DPH i rekapitulace jsou na
        // dokladu (informativně), účtuje se jen celkem hlavičky.
        $headId = $this->insertProforma(10000.0, 21.0);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(1, $result['state'], json_encode($result['messages']));
        $journal = $this->journalOf($headId);
        $this->assertCount(2, $journal, 'jen dva podrozvahové řádky');

        $request = $this->lineByPrefix($journal, '756100');
        $this->assertEqualsWithDelta(12100.0, (float) $request['money_dr'], 0.001, '756100 MD celkem');
        $this->assertEqualsWithDelta(0.0, (float) $request['money_cr'], 0.001);
        $contra = $this->lineByPrefix($journal, '799100');
        $this->assertEqualsWithDelta(12100.0, (float) $contra['money_cr'], 0.001, '799100 DAL celkem');
        $this->assertEqualsWithDelta(0.0, (float) $contra['money_dr'], 0.001);

        foreach ($journal as $line) {
            $this->assertSame(0, (int) $line['is_error']);
            $this->assertNull($line['operation'], 'hlavičkový krok bez operace → saldo přes nastavení');
            $this->assertSame($this->partner, (int) $line['partner'], 'partner z hlavičky');
            $this->assertSame(self::VS, (string) $line['payment_reference']);
            $this->assertSame(self::SS, (string) $line['specific_symbol']);
            $this->assertSame('2026-06-24', $this->dateString($line['due_date']), 'splatnost z hlavičky');
            $this->assertSame('Zálohová faktura vydaná', (string) $line['text']);
        }
        foreach (['343', '6', '311', '324', '211'] as $prefix) {
            $this->assertNoLine($journal, $prefix);
        }
        $this->assertBalanced($journal);
    }

    public function testProformaLeavesBalanceSheetAndProfitLossUnchanged(): void
    {
        [$yearName, $monthOrdinal] = $this->reportPeriod();
        $before = [
            'bs' => $this->runReport('economy.accounting.balanceSheet', $yearName, $monthOrdinal),
            'pl' => $this->runReport('economy.accounting.profitLoss', $yearName, $monthOrdinal),
        ];

        $headId = $this->insertProforma(10000.0, 21.0);
        $this->assertSame(1, $this->engine->accountDocument($headId)['state']);
        $this->assertCount(2, $this->journalOf($headId));

        foreach ($before as $report => $expected) {
            $actual = $this->runReport(
                $report === 'bs' ? 'economy.accounting.balanceSheet' : 'economy.accounting.profitLoss',
                $yearName,
                $monthOrdinal,
            );
            $this->assertSame($expected['rows'], $actual['rows'], "{$report}: řádky po zaúčtování proformy beze změny");
            $this->assertSame($expected['messages'], $actual['messages'], "{$report}: zprávy (vč. invariantů) beze změny");
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Rok a pořadí běžného měsíce účetního data pro parametry reportu.
     *
     * @return array{0: string, 1: string}
     */
    private function reportPeriod(): array
    {
        $year = $this->db->fetchRow(
            'SELECT id, name FROM economy_codebooks_fiscal_years
             WHERE date_begin <= %s AND date_end >= %s AND docState != 90 LIMIT 1',
            self::ACC_DATE, self::ACC_DATE,
        );
        if ($year === null) {
            $this->markTestSkipped('DS nemá fiskální rok pro ' . self::ACC_DATE);
        }
        $months = $this->db->fetchAll(
            'SELECT id, date_begin, date_end FROM economy_codebooks_fiscal_months
             WHERE fiscal_year = %i AND period_type = 1 ORDER BY date_begin, id',
            (int) $year['id'],
        );
        foreach ($months as $i => $m) {
            if ($this->dateString($m['date_begin']) <= self::ACC_DATE && $this->dateString($m['date_end']) >= self::ACC_DATE) {
                return [(string) $year['name'], (string) ($i + 1)];
            }
        }
        $this->markTestSkipped('DS nemá běžný měsíc pro ' . self::ACC_DATE);
    }

    /** @return array{rows: mixed, messages: mixed} */
    private function runReport(string $reportId, string $yearName, string $monthOrdinal): array
    {
        $resolver = new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        $registry = ReportDefinitionLoader::load($this->dsConfig, $resolver, 'cs');
        $runner   = new ReportRunner($registry, $this->db, null, $this->dsConfig->getId(), 'cs');

        $array = $runner->run($reportId, [
            'fiscalYear' => $yearName,
            'monthFrom'  => $monthOrdinal,
            'monthTo'    => $monthOrdinal,
            'detail'     => 'analytic',
        ])->toArray();
        return ['rows' => $array['rows'], 'messages' => $array['messages']];
    }

    private function anyPartnerId(): int
    {
        $row = $this->db->fetchRow('SELECT id FROM base_persons_persons WHERE docState IN (10,40,80) ORDER BY id LIMIT 1');
        if ($row === null) {
            $this->markTestSkipped('Dev DS nemá žádnou osobu');
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
            'name'         => 'IT podrozvaha ' . $number,
            'short_name'   => 'IT ' . $number,
            'account_kind' => 6,
            'docState'     => 40,
            'docStateMain' => 3,
        ]))->execute();
        $this->createdAccounts[] = (int) $dibi->getInsertId();
    }

    /** Proforma (invpo) ve stavu 40 s řádkem, DPH rekapitulací a identitou hlavičky. */
    private function insertProforma(float $base, float $vatPct): int
    {
        $fy = $this->db->fetchRow(
            'SELECT id FROM economy_codebooks_fiscal_years WHERE date_begin <= %s AND date_end >= %s LIMIT 1',
            self::ACC_DATE, self::ACC_DATE,
        );
        $fm = $this->db->fetchRow(
            'SELECT id FROM economy_codebooks_fiscal_months WHERE date_begin <= %s AND date_end >= %s AND period_type = 1 LIMIT 1',
            self::ACC_DATE, self::ACC_DATE,
        );
        $series = $this->db->fetchRow(
            'SELECT id FROM docs_core_number_series WHERE doc_type = %s AND docState IN (10,40,80) LIMIT 1',
            'invpo',
        );
        if ($fy === null || $fm === null || $series === null) {
            $this->markTestSkipped('DS nemá fiskální období / řadu invpo pro ' . self::ACC_DATE);
        }
        $vat   = round($base * $vatPct / 100.0, 2);
        $total = round($base + $vat, 2);

        $dibi = $this->db->getDibiConnection();
        $dibi->insert('docs_core_heads', [
            'doc_type'          => 'invpo',
            'number_series'     => (int) $series['id'],
            'doc_number'        => 'IT-PRO-' . uniqid(),
            'issue_date'        => self::ACC_DATE,
            'accounting_date'   => self::ACC_DATE,
            'due_date'          => '2026-06-24',
            'fiscal_year'       => (int) $fy['id'],
            'fiscal_month'      => (int) $fm['id'],
            'partner'           => $this->partner,
            'payment_reference' => self::VS,
            'specific_symbol'   => self::SS,
            'payment_method'    => 1,
            'doc_currency'      => 'czk',
            'home_currency'     => 'czk',
            'exchange_rate'     => 1.0,
            'doc_text'          => 'IT proforma',
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

    /** @return list<array<string, mixed>> */
    private function journalOf(int $headId): array
    {
        $rows = $this->db->getDibiConnection()->fetchAll(
            'SELECT * FROM economy_accounting_journal WHERE doc_head = %i ORDER BY id',
            $headId,
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
    private function assertNoLine(array $journal, string $prefix): void
    {
        foreach ($journal as $line) {
            $this->assertStringStartsNotWith($prefix, (string) $line['account_number'], "proforma nesmí účtovat {$prefix}*");
        }
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

    private function dateString(mixed $value): string
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : (string) $value;
    }
}

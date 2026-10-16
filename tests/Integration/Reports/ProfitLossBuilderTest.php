<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Reports;

use Shipard\Api\ReportDefinitionLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Reports\ReportResult;
use Shipard\Core\Reports\ReportRow;
use Shipard\Core\Reports\ReportRowKind;
use Shipard\Core\Reports\ReportRunner;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Výsledovka nad reálným dev DS — vzor GeneralLedgerBuilderTest: deník se
 * seeduje přímými INSERTy na účty s neobvyklými analytikami (5979xx/6979xx),
 * na sdíleném DS ale mohou v třídách 5/6 existovat cizí pohyby. Proto se
 * sumy ověřují jen na seedovaných účtech; computed výsledek se ověřuje
 * proti nezávislé SQL agregaci (zisk) a proti baseline běhu před seedem
 * (ztráta — delta záporná).
 */
class ProfitLossBuilderTest extends IntegrationTestCase
{
    private const REPORT_ID  = 'economy.accounting.profitLoss';
    private const ERROR_MASK = '59????';

    /** @var list<int> */
    private array $createdJournalIds = [];

    private string $yearName;
    private int $yearId;
    /** @var list<int> Id běžných měsíců roku v pořadí date_begin. */
    private array $regularMonthIds;

    protected function setUp(): void
    {
        parent::setUp();

        // Fiskální rok s aspoň 7 běžnými měsíci (interval Q2 + pohyb po něm).
        $years = $this->db->fetchAll(
            'SELECT [id], [name] FROM [economy_codebooks_fiscal_years]'
            . ' WHERE [docState] != 90 ORDER BY [name]',
        );
        foreach ($years as $year) {
            $months = $this->db->fetchAll(
                'SELECT [id] FROM [economy_codebooks_fiscal_months]'
                . ' WHERE [fiscal_year] = %i AND [period_type] = 1 ORDER BY [date_begin], [id]',
                (int) $year['id'],
            );
            if (count($months) >= 7) {
                $this->yearName        = (string) $year['name'];
                $this->yearId          = (int) $year['id'];
                $this->regularMonthIds = array_map(static fn (array $m): int => (int) $m['id'], $months);
                return;
            }
        }
        $this->markTestSkipped('DS has no fiscal year with at least 7 regular months.');
    }

    protected function onTearDown(): void
    {
        foreach ($this->createdJournalIds as $id) {
            $this->db->deleteWhere('economy_accounting_journal', 'id = %i', $id);
        }
    }

    private function seedJournalRow(int $monthOrdinal, string $accountNumber, float $dr, float $cr, bool $isError = false): void
    {
        $monthId   = $this->regularMonthIds[$monthOrdinal - 1];
        $dateBegin = $this->db->fetchSingle(
            'SELECT [date_begin] FROM [economy_codebooks_fiscal_months] WHERE [id] = %i',
            $monthId,
        );

        $this->createdJournalIds[] = $this->db->insertRow('economy_accounting_journal', [
            'source_kind'     => 'doc',
            'doc_head'        => null,
            'doc_number'      => 'RPT-TEST',
            'accounting_date' => $dateBegin,
            'fiscal_year'     => $this->yearId,
            'fiscal_month'    => $monthId,
            'account'         => null,
            'account_number'  => $accountNumber,
            'is_error'        => $isError ? 1 : 0,
            'money_dr'        => $dr,
            'money_cr'        => $cr,
            'text'            => 'ProfitLossBuilderTest seed',
        ]);
    }

    private function runReport(string $detail): ReportResult
    {
        $modulePathResolver = new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        $registry           = ReportDefinitionLoader::load($this->dsConfig, $modulePathResolver, 'cs');
        $runner             = new ReportRunner($registry, $this->db, null, $this->dsConfig->getId(), 'cs');

        return $runner->run(self::REPORT_ID, [
            'fiscalYear' => $this->yearName,
            'monthFrom'  => '4',
            'monthTo'    => '6',
            'detail'     => $detail,
        ]);
    }

    private function findDetailRow(ReportResult $result, string $account): ?ReportRow
    {
        foreach ($result->rows as $row) {
            if ($row->kind === ReportRowKind::Detail && $row->account === $account) {
                return $row;
            }
        }
        return null;
    }

    private function findComputedRow(ReportResult $result): ?ReportRow
    {
        foreach ($result->rows as $row) {
            if ($row->kind === ReportRowKind::Computed) {
                return $row;
            }
        }
        return null;
    }

    /**
     * Nezávislá kontrola výsledku: SUM(cr − dr) tříd 5+6 = výnosy − náklady
     * (stejná identita jako v builderu, ale spočtená přímo nad deníkem).
     *
     * @param list<int> $monthIds
     */
    private function resultBySql(array $monthIds): float
    {
        return (float) $this->db->fetchSingle(
            'SELECT COALESCE(SUM([money_cr] - [money_dr]), 0)'
            . ' FROM [economy_accounting_journal]'
            . ' WHERE [fiscal_month] IN %in AND LEFT([account_number], 1) IN %in',
            $monthIds,
            ['5', '6'],
        );
    }

    /** @return list<int> Otevírací období roku (period_type = 0). */
    private function openingMonthIds(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT [id] FROM [economy_codebooks_fiscal_months]'
            . ' WHERE [fiscal_year] = %i AND [period_type] = 0',
            $this->yearId,
        );
        return array_map(static fn (array $m): int => (int) $m['id'], $rows);
    }

    public function testProfitLossAnalytic(): void
    {
        // Před intervalem (měsíc 2) — vstoupí jen do ytd.
        $this->seedJournalRow(2, '597999', 300.0, 0.0);
        $this->seedJournalRow(2, '697999', 0.0, 500.0);
        // V intervalu 4–6.
        $this->seedJournalRow(4, '597999', 100.0, 20.0);
        $this->seedJournalRow(5, '697999', 0.0, 250.0);
        // Třída 3 do výsledovky nevstupuje.
        $this->seedJournalRow(4, '397997', 40.0, 0.0);
        // Pohyb PO intervalu — účet je nulový v obou sloupcích, neemituje se.
        $this->seedJournalRow(7, '597998', 55.0, 0.0);
        // Chybová maska třídy 5 v intervalu.
        $this->seedJournalRow(5, self::ERROR_MASK, 12.5, 0.0, isError: true);

        $result = $this->runReport('analytic');
        $array  = $result->toArray();

        $this->assertSame(self::REPORT_ID, $array['reportId']);
        $this->assertSame(
            ['fiscalYear' => $this->yearName, 'monthFrom' => 4, 'monthTo' => 6],
            $array['params']['period'],
        );
        $this->assertSame(['period', 'ytd'], array_column($array['columns'], 'id'));

        // Náklad: period jen interval, ytd včetně měsíce 2.
        $row = $this->findDetailRow($result, '597999');
        $this->assertNotNull($row);
        $this->assertSame(4, $row->level);
        $this->assertSame(['md' => 100.0, 'd' => 20.0, 'balance' => 80.0], $row->values['period']);
        $this->assertSame(['md' => 400.0, 'd' => 20.0, 'balance' => 380.0], $row->values['ytd']);

        // Výnos.
        $row = $this->findDetailRow($result, '697999');
        $this->assertNotNull($row);
        $this->assertSame(['md' => 0.0, 'd' => 250.0, 'balance' => -250.0], $row->values['period']);
        $this->assertSame(['md' => 0.0, 'd' => 750.0, 'balance' => -750.0], $row->values['ytd']);

        // Třída 3 a nulový účet se neemitují.
        $this->assertNull($this->findDetailRow($result, '397997'));
        $this->assertNull($this->findDetailRow($result, '597998'));

        // Žádný generický total — místo něj computed výsledek jako poslední řádek.
        foreach ($result->rows as $row) {
            $this->assertNotSame(ReportRowKind::Total, $row->kind);
        }
        $computed = $this->findComputedRow($result);
        $this->assertNotNull($computed);
        $this->assertSame($computed, $result->rows[count($result->rows) - 1]);
        $this->assertSame(0, $computed->level);
        $this->assertNull($computed->account);
        $this->assertSame(0.0, $computed->values['period']['md']);
        $this->assertSame(0.0, $computed->values['period']['d']);

        // Computed = výnosy − náklady, nezávisle přes SQL (sdílený DS může
        // mít v třídách 5/6 cizí pohyby — proto ne konstanta).
        $periodMonthIds = array_slice($this->regularMonthIds, 3, 3);
        $ytdMonthIds    = array_merge($this->openingMonthIds(), array_slice($this->regularMonthIds, 0, 6));
        $this->assertEqualsWithDelta($this->resultBySql($periodMonthIds), $computed->values['period']['balance'], 0.005);
        $this->assertEqualsWithDelta($this->resultBySql($ytdMonthIds), $computed->values['ytd']['balance'], 0.005);

        // Chybová maska: detail řádek + zpráva + status errors.
        $errorRow = $this->findDetailRow($result, self::ERROR_MASK);
        $this->assertNotNull($errorRow);
        $this->assertSame(self::ERROR_MASK, $errorRow->label);
        $this->assertSame('errors', $array['status']);
        $maskMessages = array_values(array_filter(
            $array['messages'],
            fn (array $m): bool => str_contains($m['text'], self::ERROR_MASK),
        ));
        $this->assertCount(1, $maskMessages);
        $this->assertSame('journal.accountNotFound', $maskMessages[0]['code']);
    }

    public function testProfitLossLossDelta(): void
    {
        // Baseline před seedem — na sdíleném DS izoluje cizí data.
        $baseline = $this->findComputedRow($this->runReport('analytic'));
        $this->assertNotNull($baseline);

        // Ztráta: náklady výrazně převyšují výnosy.
        $this->seedJournalRow(4, '597997', 900.0, 0.0);
        $this->seedJournalRow(4, '697997', 0.0, 100.0);

        $computed = $this->findComputedRow($this->runReport('analytic'));
        $this->assertNotNull($computed);

        // Delta = 100 − 900 = −800 v obou sloupcích (seed je v intervalu).
        $this->assertEqualsWithDelta(
            $baseline->values['period']['balance'] - 800.0,
            $computed->values['period']['balance'],
            0.005,
        );
        $this->assertEqualsWithDelta(
            $baseline->values['ytd']['balance'] - 800.0,
            $computed->values['ytd']['balance'],
            0.005,
        );
    }

    public function testProfitLossSynthetic(): void
    {
        $this->seedJournalRow(4, '597999', 100.0, 0.0);
        $this->seedJournalRow(5, '597991', 30.0, 10.0);
        $this->seedJournalRow(5, self::ERROR_MASK, 12.5, 0.0, isError: true);

        $result = $this->runReport('synthetic');

        // Analytiky se slily do syntetiky 597.
        $this->assertNull($this->findDetailRow($result, '597999'));
        $row = $this->findDetailRow($result, '597');
        $this->assertNotNull($row);
        $this->assertSame(3, $row->level);
        $this->assertSame(['md' => 130.0, 'd' => 10.0, 'balance' => 120.0], $row->values['period']);

        // Chybová maska se na prefix neagreguje; computed řádek existuje.
        $this->assertNotNull($this->findDetailRow($result, self::ERROR_MASK));
        $this->assertNotNull($this->findComputedRow($result));
        $this->assertSame('errors', $result->toArray()['status']);
    }

    public function testRowsCarryStableKeysForOverview(): void
    {
        // Přehled firmy (#120 D8) čte výsledek, náklady a výnosy podle klíče, ne popisku.
        $this->seedJournalRow(5, '597901', 100.0, 0.0);
        $this->seedJournalRow(5, '697901', 0.0, 250.0);

        $result = $this->runReport('synthetic');

        $byKey = [];
        foreach ($result->rows as $row) {
            if ($row->key !== null) {
                $byKey[$row->key] = $row;
            }
        }
        $this->assertSame(['costs', 'revenues', 'result'], array_keys($byKey), 'třída 5, třída 6, výsledek');
        $this->assertSame('5', $byKey['costs']->account);
        $this->assertSame(1, $byKey['costs']->level);
        $this->assertSame(ReportRowKind::Subtotal, $byKey['costs']->kind);
        $this->assertSame('6', $byKey['revenues']->account);
        $this->assertSame(ReportRowKind::Computed, $byKey['result']->kind);
        $this->assertSame($this->findComputedRow($result), $byKey['result']);
    }
}

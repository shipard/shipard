<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Accounting;

use Shipard\Api\ReportDefinitionLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Reports\ReportRowKind;
use Shipard\Core\Reports\ReportRunner;
use Shipard\Module\Economy\Accounting\Overview\JournalAccountBalance;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Zůstatek účtu pro Přehled firmy (#120 D5, D19) = sloupec `closing`
 * hlavní knihy za celé období: otevírací + běžné měsíce, uzavírací měsíc
 * mimo. Deník se seeduje přímými INSERTy na účty 219xxx (vzor
 * GeneralLedgerBuilderTest); na sdíleném DS se ověřuje rozdíl proti stavu
 * před seedem a rovnost s hlavní knihou.
 */
class CashBalancesBlockTest extends IntegrationTestCase
{
    private const ACCOUNT_HOME    = '219901';
    private const ACCOUNT_FOREIGN = '219902';

    /** @var list<int> */
    private array $createdJournalIds = [];

    private int $yearId;
    private string $yearName;
    private int $openingMonthId;
    private int $closingMonthId;
    /** @var list<int> */
    private array $regularMonthIds;

    protected function setUp(): void
    {
        parent::setUp();

        $years = $this->db->fetchAll(
            'SELECT [id], [name] FROM [economy_codebooks_fiscal_years] WHERE [docState] != 90 ORDER BY [name]',
        );
        foreach ($years as $year) {
            $months = $this->db->fetchAll(
                'SELECT [id], [period_type] FROM [economy_codebooks_fiscal_months]'
                . ' WHERE [fiscal_year] = %i ORDER BY [date_begin], [id]',
                (int) $year['id'],
            );
            $opening = $closing = null;
            $regular = [];
            foreach ($months as $month) {
                match ((int) $month['period_type']) {
                    0 => $opening = (int) $month['id'],
                    2 => $closing = (int) $month['id'],
                    default => $regular[] = (int) $month['id'],
                };
            }
            if ($opening !== null && $closing !== null && count($regular) >= 3) {
                $this->yearId          = (int) $year['id'];
                $this->yearName        = (string) $year['name'];
                $this->openingMonthId  = $opening;
                $this->closingMonthId  = $closing;
                $this->regularMonthIds = $regular;
                return;
            }
        }
        $this->markTestSkipped('DS has no fiscal year with opening, closing and at least 3 regular months.');
    }

    protected function onTearDown(): void
    {
        foreach ($this->createdJournalIds as $id) {
            $this->db->deleteWhere('economy_accounting_journal', 'id = %i', $id);
        }
    }

    private function seed(int $monthId, string $account, float $dr, float $cr, string $currency = 'czk', ?float $drCur = null, ?float $crCur = null): string
    {
        $dateBegin = $this->db->fetchSingle(
            'SELECT [date_begin] FROM [economy_codebooks_fiscal_months] WHERE [id] = %i',
            $monthId,
        );
        $this->createdJournalIds[] = $this->db->insertRow('economy_accounting_journal', [
            'source_kind'     => 'doc',
            'doc_head'        => null,
            'doc_number'      => 'OVW-TEST',
            'accounting_date' => $dateBegin,
            'fiscal_year'     => $this->yearId,
            'fiscal_month'    => $monthId,
            'account'         => null,
            'account_number'  => $account,
            'is_error'        => 0,
            'money_dr'        => $dr,
            'money_cr'        => $cr,
            'currency'        => $currency,
            'money_dr_cur'    => $drCur ?? $dr,
            'money_cr_cur'    => $crCur ?? $cr,
            'text'            => 'CashBalancesBlockTest seed',
        ]);
        return $dateBegin instanceof \DateTimeInterface ? $dateBegin->format('Y-m-d') : substr((string) $dateBegin, 0, 10);
    }

    public function testBalanceEqualsGeneralLedgerClosingAndExcludesClosingMonth(): void
    {
        $balances = new JournalAccountBalance($this->db);
        $before   = $balances->forAccount(self::ACCOUNT_HOME, $this->yearId);
        $beforeFx = $balances->forAccount(self::ACCOUNT_FOREIGN, $this->yearId);

        $this->seed($this->openingMonthId, self::ACCOUNT_HOME, 1000.0, 0.0);
        $this->seed($this->regularMonthIds[0], self::ACCOUNT_HOME, 250.0, 50.0);
        $lastDate = $this->seed($this->regularMonthIds[2], self::ACCOUNT_HOME, 0.0, 100.0);
        $this->seed($this->closingMonthId, self::ACCOUNT_HOME, 9999.0, 0.0); // uzavírací měsíc — mimo
        $this->seed($this->regularMonthIds[1], self::ACCOUNT_FOREIGN, 2500.0, 0.0, 'eur', 100.0, 0.0);
        $this->seed($this->regularMonthIds[1], self::ACCOUNT_FOREIGN, 0.0, 250.0, 'eur', 0.0, 10.0);

        $after   = $balances->forAccount(self::ACCOUNT_HOME, $this->yearId);
        $afterFx = $balances->forAccount(self::ACCOUNT_FOREIGN, $this->yearId);

        $this->assertEqualsWithDelta(1100.0, $after['balance'] - $before['balance'], 0.001);
        $this->assertSame($lastDate, $after['lastDate']);
        $this->assertSame($before['entries'] + 3, $after['entries']);
        $this->assertEqualsWithDelta(2250.0, $afterFx['balance'] - $beforeFx['balance'], 0.001);
        $this->assertEqualsWithDelta(90.0, $afterFx['balanceCur'] - $beforeFx['balanceCur'], 0.001);

        // Totéž číslo jako hlavní kniha za celé období (otevírací + běžné měsíce).
        $registry = ReportDefinitionLoader::load($this->dsConfig, new ModulePathResolver([dirname(__DIR__, 3) . '/modules']), 'cs');
        $result   = (new ReportRunner($registry, $this->db, null, $this->dsConfig->getId(), 'cs'))->run(
            'economy.accounting.generalLedger',
            ['fiscalYear' => $this->yearName, 'monthFrom' => '1', 'monthTo' => (string) count($this->regularMonthIds), 'detail' => 'analytic'],
        );
        foreach ([self::ACCOUNT_HOME => $after, self::ACCOUNT_FOREIGN => $afterFx] as $account => $balance) {
            $account = (string) $account; // číselný klíč pole je int
            $row     = null;
            foreach ($result->rows as $candidate) {
                if ($candidate->kind === ReportRowKind::Detail && $candidate->account === $account) {
                    $row = $candidate;
                    break;
                }
            }
            $this->assertNotNull($row, "Hlavní kniha nemá řádek účtu {$account}");
            $this->assertEqualsWithDelta($row->values['closing']['balance'], $balance['balance'], 0.001, "Účet {$account}");
        }
    }
}

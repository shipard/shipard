<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Accbal;

use Shipard\Module\Economy\Accbal\CaseQuery;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * CaseQuery nad reálným DS: případ = agregát klíče (skupina, období,
 * partner, VS, SS, měna) z ledgeru (#69 D1/D11). Pohyby se seedují přímo
 * do ledgeru (izolace od enginů) — ověřuje se SQL sémantika agregátu:
 * zůstatek, typ otevřenosti, splatnost jen z předpisů, prázdný SS ≠
 * vyplněný, období odděluje případy, normalizace vstupního klíče.
 */
class CaseQueryTest extends IntegrationTestCase
{
    private const PARTNER  = 990005;
    private const VS       = 'IT-CASE-2026';
    private const ACC_DATE = '2026-06-10';

    private int $fiscalYear = 0;

    /** @var list<int> */
    private array $seededDocs = [];
    /** @var list<int> */
    private array $seededTxs = [];
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
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
        foreach ($this->seededDocs as $id) {
            $dibi->delete('economy_accbal_ledger')->where('doc_head = %i', $id)->execute();
        }
        foreach ($this->seededTxs as $id) {
            $dibi->delete('economy_accbal_ledger')->where('bank_transaction = %i', $id)->execute();
        }
    }

    public function testDebtCaseAggregatesRequestsAndPayments(): void
    {
        $recv = $this->balanceId('receivables');
        $requestId = $this->seedRequest($recv, 1000.00, ['due_date' => '2026-06-30']);
        $this->seedPayment($recv, 400.00);

        $case = $this->query()->caseOf($this->key($recv));

        $this->assertNotNull($case);
        $this->assertSame(1000.0, $case['sum_requests']);
        $this->assertSame(400.0, $case['sum_payments']);
        $this->assertSame(600.0, $case['residual']);
        $this->assertSame(600.0, $case['residual_hc']);
        $this->assertSame(CaseQuery::KIND_DEBT, $case['kind']);
        $this->assertTrue($case['is_open']);
        $this->assertSame(2, $case['moves']);
        $this->assertSame('2026-06-30', $this->dateString($case['due_date']));
        $this->assertSame($requestId, (int) $case['row_id'], 'row_id = nejnižší id pohybu klíče');
        $this->assertSame(self::PARTNER, (int) $case['partner']);
        $this->assertSame($this->fiscalYear, (int) $case['fiscal_year']);
    }

    public function testOverpaymentIsNegativeResidual(): void
    {
        $recv = $this->balanceId('receivables');
        $this->seedRequest($recv, 500.00);
        $this->seedPayment($recv, 800.00);

        $case = $this->query()->caseOf($this->key($recv));

        $this->assertNotNull($case);
        $this->assertSame(-300.0, $case['residual']);
        $this->assertSame(CaseQuery::KIND_OVERPAYMENT, $case['kind']);
        $this->assertTrue($case['is_open']);
    }

    public function testPaymentWithoutRequestIsUnrequested(): void
    {
        $clearing = $this->balanceId('unmatched_payments');
        $this->seedPayment($clearing, 250.00, ['account_number' => '261200']);

        $case = $this->query()->caseOf($this->key($clearing));

        $this->assertNotNull($case);
        $this->assertSame(0.0, $case['sum_requests']);
        $this->assertSame(-250.0, $case['residual']);
        $this->assertSame(CaseQuery::KIND_UNREQUESTED, $case['kind']);
        $this->assertNull($case['due_date'], 'úhrada splatnost nenese');
    }

    public function testClosedCase(): void
    {
        $recv = $this->balanceId('receivables');
        $this->seedRequest($recv, 500.00);
        $this->seedPayment($recv, 500.00);

        $case = $this->query()->caseOf($this->key($recv));

        $this->assertNotNull($case);
        $this->assertSame(0.0, $case['residual']);
        $this->assertSame(CaseQuery::KIND_CLOSED, $case['kind']);
        $this->assertFalse($case['is_open']);
    }

    public function testDueDateIsOldestRequestOfKey(): void
    {
        $recv = $this->balanceId('receivables');
        $this->seedRequest($recv, 300.00, ['due_date' => '2026-06-30']);
        $this->seedRequest($recv, 300.00, ['due_date' => '2026-05-31']);
        // Splatnost na úhradě (import ji může nést) do MIN nevstupuje.
        $this->seedPayment($recv, 100.00, ['due_date' => '2026-01-01']);

        $case = $this->query()->caseOf($this->key($recv));

        $this->assertNotNull($case);
        $this->assertSame('2026-05-31', $this->dateString($case['due_date']));
        $this->assertSame(500.0, $case['residual']);
    }

    public function testEmptySpecificSymbolIsDistinctCase(): void
    {
        $recv = $this->balanceId('receivables');
        $this->seedRequest($recv, 100.00);
        $this->seedRequest($recv, 200.00, ['specific_symbol' => '77']);
        $query = $this->query();

        $plain = $query->caseOf($this->key($recv));
        $withSs = $query->caseOf($this->key($recv, ['specific_symbol' => '77']));

        $this->assertNotNull($plain);
        $this->assertSame(100.0, $plain['residual'], 'prázdný SS = vlastní případ');
        $this->assertNotNull($withSs);
        $this->assertSame(200.0, $withSs['residual']);
        $this->assertNull($query->caseOf($this->key($recv, ['specific_symbol' => '78'])));
    }

    public function testFiscalYearSeparatesCases(): void
    {
        $recv = $this->balanceId('receivables');
        $otherYear = $this->otherFiscalYear();
        $this->seedRequest($recv, 900.00, ['fiscal_year' => $otherYear]);
        $this->seedPayment($recv, 900.00);
        $query = $this->query();

        $current = $query->caseOf($this->key($recv));
        $other = $query->caseOf($this->key($recv, ['fiscal_year' => $otherYear]));

        $this->assertNotNull($current);
        $this->assertSame(CaseQuery::KIND_UNREQUESTED, $current['kind'], 'úhrada bez předpisu ve svém období');
        $this->assertNotNull($other);
        $this->assertSame(CaseQuery::KIND_DEBT, $other['kind'], 'předpis loňského období zůstává otevřený, dokud ho nepřenese otevírací doklad');
    }

    public function testInputKeyIsNormalizedAndKeyOfRowRoundTrips(): void
    {
        $recv = $this->balanceId('receivables');
        $requestId = $this->seedRequest($recv, 100.00);
        $query = $this->query();

        $case = $query->caseOf($this->key($recv, [
            'payment_reference' => ' ' . self::VS . ' ',
            'specific_symbol'   => '  ',
            'currency'          => 'CZK',
        ]));
        $this->assertNotNull($case, 'vstupní klíč se normalizuje jako ledger při zápisu (D10)');
        $this->assertSame(100.0, $case['residual']);

        $this->assertSame($this->key($recv), $query->keyOfRow($requestId), 'z řádku ledgeru zpět na klíč');
        $this->assertNull($query->keyOfRow(-1));
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function query(): CaseQuery
    {
        return new CaseQuery($this->db->getDibiConnection());
    }

    /** @return array<string, int|string|null> */
    private function key(int $balance, array $over = []): array
    {
        return array_merge([
            'balance'           => $balance,
            'fiscal_year'       => $this->fiscalYear,
            'partner'           => self::PARTNER,
            'payment_reference' => self::VS,
            'specific_symbol'   => null,
            'currency'          => 'czk',
        ], $over);
    }

    private function balanceId(string $code): int
    {
        $row = $this->db->fetchRow('SELECT id FROM economy_accbal_balances WHERE code = %s', $code);
        if ($row === null) {
            $this->markTestSkipped("DS nemá naseedované saldokonto '{$code}'");
        }
        return (int) $row['id'];
    }

    private function otherFiscalYear(): int
    {
        $row = $this->db->fetchRow(
            'SELECT id FROM economy_codebooks_fiscal_years WHERE id <> %i ORDER BY id LIMIT 1',
            $this->fiscalYear,
        );
        return $row !== null ? (int) $row['id'] : $this->fiscalYear + 100_000;
    }

    /** @param array<string, mixed> $over  @return int id pohybu */
    private function seedRequest(int $balance, float $amount, array $over = []): int
    {
        $docId = 972_000_000 + (++$this->seq);
        $this->seededDocs[] = $docId;
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('economy_accbal_ledger', array_merge([
            'balance'           => $balance,
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
        ], $over))->execute();
        return (int) $dibi->getInsertId();
    }

    /** @param array<string, mixed> $over  @return int id pohybu */
    private function seedPayment(int $balance, float $amount, array $over = []): int
    {
        $txId = 973_000_000 + (++$this->seq);
        $this->seededTxs[] = $txId;
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('economy_accbal_ledger', array_merge([
            'balance'           => $balance,
            'bal_side'          => 1,
            'source_kind'       => 'bankTransaction',
            'source_id'         => $txId,
            'bank_transaction'  => $txId,
            'account_number'    => '311100',
            'fiscal_year'       => $this->fiscalYear,
            'partner'           => self::PARTNER,
            'payment_reference' => self::VS,
            'currency'          => 'czk',
            'home_currency'     => 'czk',
            'amount'            => $amount,
            'amount_hc'         => $amount,
        ], $over))->execute();
        return (int) $dibi->getInsertId();
    }

    private function dateString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : substr((string) $value, 0, 10);
    }
}

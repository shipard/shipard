<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Accbal;

use Shipard\Module\Economy\Accbal\CaseQuery;
use Shipard\Module\Economy\Accbal\CasesViewer;
use Shipard\Module\Economy\Accbal\CaseTotals;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Součty případů per měna nad reálným DS (#120 D4, D6): obchodní zůstatek
 * (`residual`) per měna, hranice splatnosti `dueWithin` (dnes + N včetně,
 * dnes + N + 1 mimo), a **shoda bloku s patičkou** — `totals()` pro mapu
 * filtrů prokliku dává totéž číslo jako `renderGridFooter()` vieweru
 * případů s filtrem měny (kritérium „Hotovo když").
 */
class CaseTotalsTest extends IntegrationTestCase
{
    private const PARTNER   = 990010;
    private const VS_PREFIX = 'IT-CT-';
    private const RATE_EUR  = 25.0;

    private int $fiscalYear = 0;
    private string $today;
    /** @var list<int> */
    private array $seededDocs = [];
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $fy = $this->db->fetchRow(
            'SELECT id FROM economy_codebooks_fiscal_years WHERE docState != 90 AND date_begin <= %s AND date_end >= %s LIMIT 1',
            $this->today, $this->today,
        );
        if ($fy === null) {
            $this->markTestSkipped('DS nemá fiskální rok pro dnešek');
        }
        $this->fiscalYear = (int) $fy['id'];
    }

    protected function onTearDown(): void
    {
        $dibi = $this->db->getDibiConnection();
        foreach ($this->seededDocs as $id) {
            $dibi->delete('economy_accbal_ledger')->where('doc_head = %i', $id)->execute();
        }
    }

    /** Předpis (dluh) v dané měně se splatností; `amount_hc` = částka × kurz. */
    private function seedDebt(int $balance, string $vs, float $amount, string $currency, string $dueDate): void
    {
        $sourceId = 976_000_000 + (++$this->seq);
        $this->seededDocs[] = $sourceId;
        $this->db->getDibiConnection()->insert('economy_accbal_ledger', [
            'balance'           => $balance,
            'bal_side'          => 0,
            'source_kind'       => 'doc',
            'source_id'         => $sourceId,
            'doc_head'          => $sourceId,
            'account_number'    => '311100',
            'fiscal_year'       => $this->fiscalYear,
            'partner'           => self::PARTNER,
            'payment_reference' => self::VS_PREFIX . $vs,
            'due_date'          => $dueDate,
            'currency'          => $currency,
            'home_currency'     => 'czk',
            'amount'            => $amount,
            'amount_hc'         => $currency === 'czk' ? $amount : $amount * self::RATE_EUR,
            'text'              => 'CaseTotalsTest seed',
        ])->execute();
    }

    private function day(int $offset): string
    {
        return (new \DateTimeImmutable('today'))->modify(($offset >= 0 ? '+' : '') . $offset . ' days')->format('Y-m-d');
    }

    /** @param array<string, string> $extra  @return list<array{id: string, value: string}> */
    private function filters(array $extra = []): array
    {
        return CaseTotals::filtersFromMap(
            ['viewGroup' => 'receivables', 'payment_reference' => self::VS_PREFIX, 'fiscal_year' => (string) $this->fiscalYear] + $extra,
        );
    }

    public function testTotalsPerCurrencyAndDueWithinBoundaries(): void
    {
        $recv = $this->db->fetchRow('SELECT id FROM economy_accbal_balances WHERE code = %s', 'receivables');
        if ($recv === null) {
            $this->markTestSkipped("DS nemá naseedované saldokonto 'receivables'");
        }
        $balance = (int) $recv['id'];
        $this->seedDebt($balance, 'A1', 600.0, 'czk', $this->day(-30));  // po splatnosti
        $this->seedDebt($balance, 'B1', 200.0, 'czk', '2099-12-31');       // v termínu, mimo horizont
        $this->seedDebt($balance, 'E1', 100.0, 'eur', $this->day(3));     // splatné do 7 dní
        $this->seedDebt($balance, 'E3', 25.0, 'eur', $this->day(7));      // hranice: dnes + 7 včetně
        $this->seedDebt($balance, 'E2', 50.0, 'eur', $this->day(8));      // dnes + 8 mimo

        $totals = new CaseTotals($this->db);
        $today  = new \DateTimeImmutable('today');

        $all = $totals->totals($this->filters(['kind' => CaseQuery::KIND_DEBT]), $today);
        $this->assertSame(['czk', 'eur'], array_column($all, 'currency'));
        $this->assertSame([2, 3], array_column($all, 'count'));
        $this->assertSame([800.0, 175.0], array_column($all, 'residual'), 'obchodní zůstatek v měně dokladu');
        $this->assertSame([800.0, 175.0 * self::RATE_EUR], array_column($all, 'residual_hc'));

        $overdue = $totals->totals($this->filters(['kind' => CaseQuery::KIND_DEBT, 'overdue' => '1']), $today);
        $this->assertSame([['czk', 1, 600.0]], array_map(static fn ($r) => [$r['currency'], $r['count'], $r['residual']], $overdue));

        $dueSoon = $totals->totals($this->filters(['kind' => CaseQuery::KIND_DEBT, 'dueWithin' => '7']), $today);
        $this->assertSame([['eur', 2, 125.0]], array_map(static fn ($r) => [$r['currency'], $r['count'], $r['residual']], $dueSoon));

        $dueToday = $totals->totals($this->filters(['kind' => CaseQuery::KIND_DEBT, 'dueWithin' => '2']), $today);
        $this->assertSame([], $dueToday, 'nic nesplatné v ⟨dnes, dnes + 2⟩');

        // Shoda s patičkou vieweru: tytéž filtry + měna → totéž číslo.
        $viewer = new CasesViewer($this->db, 'economy_accbal_ledger');
        $viewer->setLanguage('cs');
        $footer = $viewer->renderGridFooter(null, $this->filters(['kind' => CaseQuery::KIND_DEBT, 'dueWithin' => '7', 'currency' => 'eur']));
        $this->assertSame('125,00 EUR', $footer['residual'][1]['text']);
        $this->assertSame('3 125,00 CZK', $footer['residual_hc'][1]['text']);

        $footerCzk = $viewer->renderGridFooter(null, $this->filters(['kind' => CaseQuery::KIND_DEBT, 'currency' => 'czk']));
        $this->assertSame('800,00 CZK', $footerCzk['residual'][1]['text']);

        $footerAll = $viewer->renderGridFooter(null, $this->filters(['kind' => CaseQuery::KIND_DEBT]));
        $this->assertSame('5 175,00 CZK', $footerAll['residual_hc'][1]['text'], 'bez měny součet v domácí měně');
        $this->assertArrayNotHasKey('residual', $footerAll);
    }
}

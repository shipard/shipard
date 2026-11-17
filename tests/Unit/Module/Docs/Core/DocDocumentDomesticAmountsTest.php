<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use PHPUnit\Framework\TestCase;
use Shipard\Tests\Fixtures\Module\Docs\Core\TestableDocsHeadsDocument;

/**
 * Invarianty domácí měny (accounting Fáze 1, docs/accounting.md sekce 8):
 *
 *   1. Σ rows.vat_base_dom   (per vat_code+pct) == recap.base_dom
 *   2. Σ rows.vat_amount_dom (per vat_code+pct) == recap.tax_dom
 *   3. Σ recap.base_dom (sum_base=1) == total_base_dom
 *   4. Σ recap.tax_dom  (sum_tax=1)  == total_vat_dom
 *   5. total_base_dom + total_vat_dom + total_rounding_dom == total_amount_dom
 *
 * Recap je vstup (autoritativní, base_dom/tax_dom = round(cur × rate) z
 * buildVatRecapitulation); applyDomesticAmounts dorovnává řádky na recap
 * a odvozuje head hodnoty.
 */
class DocDocumentDomesticAmountsTest extends TestCase
{
    private const DELTA = 0.001;

    private function doc(): TestableDocsHeadsDocument
    {
        return new TestableDocsHeadsDocument();
    }

    /** Běžný řádek s cur computed hodnotami (jako po calculateRowVat, vat_mode 1). */
    private function row(string $code, float $pct, float $base, ?int $id = null): array
    {
        $amount = round($base * $pct / 100.0, 2);
        $row = [
            'row_kind'   => 1,
            'vat_code'   => $code,
            'vat_pct'    => $pct,
            'vat_base'   => $base,
            'vat_amount' => $amount,
            'vat_total'  => round($base + $amount, 2),
        ];
        if ($id !== null) {
            $row['id'] = $id;
        }
        return $row;
    }

    /**
     * Recap řádek tak, jak ho staví buildVatRecapitulation: base = Σ cur bases
     * skupiny, tax z group base, _dom = round(cur × rate).
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private function recapLine(array $rows, string $code, float $pct, float $rate): array
    {
        $base = 0.0;
        foreach ($rows as $r) {
            if (($r['vat_code'] ?? null) === $code && (float) ($r['vat_pct'] ?? 0) === $pct) {
                $base += (float) $r['vat_base'];
            }
        }
        $base = round($base, 2);
        $tax  = round($base * $pct / 100.0, 2);
        return [
            'vat_code' => $code, 'vat_pct' => $pct,
            'base' => $base, 'tax' => $tax, 'total' => round($base + $tax, 2),
            'base_dom' => round($base * $rate, 2), 'tax_dom' => round($tax * $rate, 2),
            'sum_base' => 1, 'sum_tax' => 1, 'sum_total' => 1, 'is_reverse_pair' => 0,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, array<string, mixed>> $recap
     */
    private function assertInvariants(array $data, array $rows, array $recap): void
    {
        $recapBaseSum = 0.0;
        $recapTaxSum  = 0.0;
        foreach ($recap as $r) {
            if (!empty($r['sum_base'])) { $recapBaseSum += (float) $r['base_dom']; }
            if (!empty($r['sum_tax']))  { $recapTaxSum  += (float) $r['tax_dom']; }

            if (!empty($r['is_reverse_pair'])) {
                continue;
            }
            $rowBaseSum = 0.0;
            $rowAmountSum = 0.0;
            foreach ($rows as $row) {
                if ((int) ($row['row_kind'] ?? 1) !== 1
                    || ($row['vat_code'] ?? null) !== $r['vat_code']
                    || (float) ($row['vat_pct'] ?? 0) !== (float) $r['vat_pct']
                ) {
                    continue;
                }
                $rowBaseSum   += (float) $row['vat_base_dom'];
                $rowAmountSum += (float) $row['vat_amount_dom'];
            }
            $this->assertEqualsWithDelta((float) $r['base_dom'], $rowBaseSum, self::DELTA,
                "Inv 1: Σ rows.vat_base_dom != recap.base_dom ({$r['vat_code']})");
            $this->assertEqualsWithDelta((float) $r['tax_dom'], $rowAmountSum, self::DELTA,
                "Inv 2: Σ rows.vat_amount_dom != recap.tax_dom ({$r['vat_code']})");
        }

        $this->assertEqualsWithDelta($recapBaseSum, (float) $data['total_base_dom'], self::DELTA,
            'Inv 3: Σ recap.base_dom != total_base_dom');
        $this->assertEqualsWithDelta($recapTaxSum, (float) $data['total_vat_dom'], self::DELTA,
            'Inv 4: Σ recap.tax_dom != total_vat_dom');
        $this->assertEqualsWithDelta(
            (float) $data['total_amount_dom'],
            (float) $data['total_base_dom'] + (float) $data['total_vat_dom'] + (float) $data['total_rounding_dom'],
            self::DELTA,
            'Inv 5: base + vat + rounding != amount (dom)',
        );
    }

    // ── Scénáře ─────────────────────────────────────────────────────────────

    public function testDomesticCurrencyRateOneDomEqualsCur(): void
    {
        $doc = $this->doc();
        $rows = [
            $this->row('cz-101', 21.0, 333.33),
            $this->row('cz-102', 12.0, 50.00),
        ];
        $recap = [
            $this->recapLine($rows, 'cz-101', 21.0, 1.0),
            $this->recapLine($rows, 'cz-102', 12.0, 1.0),
        ];

        $data = ['exchange_rate' => 1.0, 'total_rounding_mode' => 1];
        $doc->sumTotalsPub($data, $recap);
        $doc->applyTotalRoundingPub($data);
        $doc->applyDomesticAmountsPub($data, $rows, $recap);

        // _dom je kopie cur hodnot
        foreach ($rows as $row) {
            $this->assertEqualsWithDelta((float) $row['vat_base'], (float) $row['vat_base_dom'], self::DELTA);
            $this->assertEqualsWithDelta((float) $row['vat_amount'], (float) $row['vat_amount_dom'], self::DELTA);
            $this->assertEqualsWithDelta((float) $row['vat_total'], (float) $row['vat_total_dom'], self::DELTA);
        }
        $this->assertEqualsWithDelta((float) $data['total_base'], (float) $data['total_base_dom'], self::DELTA);
        $this->assertEqualsWithDelta((float) $data['total_vat'], (float) $data['total_vat_dom'], self::DELTA);
        $this->assertEqualsWithDelta((float) $data['total_amount'], (float) $data['total_amount_dom'], self::DELTA);
        $this->assertEqualsWithDelta((float) $data['total_rounding'], (float) $data['total_rounding_dom'], self::DELTA);
        // zaokrouhlení na celé koruny je nenulové — invariant 5 netriviální
        $this->assertNotEquals(0.0, (float) $data['total_rounding']);

        $this->assertInvariants($data, $rows, $recap);
    }

    public function testForeignCurrencyTwoVatRatesInvariantsHold(): void
    {
        $rate = 25.285;
        $doc = $this->doc();
        // 2× base 1.11 @ 21 % vynutí nenulový diff: Σ row dom = 56.14,
        // recap base_dom = round(2.22 × 25.285) = 56.13. U amount je diff
        // ještě větší (recap počítá daň z group base, ne ze součtu řádků).
        $rows = [
            $this->row('cz-101', 21.0, 1.11),
            $this->row('cz-101', 21.0, 1.11),
            $this->row('cz-102', 12.0, 100.10),
            $this->row('cz-102', 12.0, 33.30),
        ];
        $recap = [
            $this->recapLine($rows, 'cz-101', 21.0, $rate),
            $this->recapLine($rows, 'cz-102', 12.0, $rate),
        ];

        $data = ['exchange_rate' => $rate, 'total_rounding_mode' => 0];
        $doc->sumTotalsPub($data, $recap);
        $doc->applyTotalRoundingPub($data);
        $doc->applyDomesticAmountsPub($data, $rows, $recap);

        $this->assertInvariants($data, $rows, $recap);

        // Dorovnání skončilo na posledním řádku skupiny 21 % (index 1):
        // první řádek zůstal na čistém kurzovém přepočtu.
        $this->assertEqualsWithDelta(round(1.11 * $rate, 2), (float) $rows[0]['vat_base_dom'], self::DELTA);
        $this->assertNotEquals(
            round((float) $rows[1]['vat_amount'] * $rate, 2),
            (float) $rows[1]['vat_amount_dom'],
            'diff měl skončit na posledním řádku skupiny',
        );
    }

    public function testReconciliationSkipsZeroBaseRow(): void
    {
        $rate = 25.285;
        $doc = $this->doc();
        $rows = [
            $this->row('cz-101', 21.0, 1.11),
            $this->row('cz-101', 21.0, 1.11),
            $this->row('cz-101', 21.0, 0.0),   // množství 0 — poslední ve skupině
        ];
        $recap = [$this->recapLine($rows, 'cz-101', 21.0, $rate)];

        $data = ['exchange_rate' => $rate, 'total_rounding_mode' => 0];
        $doc->sumTotalsPub($data, $recap);
        $doc->applyTotalRoundingPub($data);
        $doc->applyDomesticAmountsPub($data, $rows, $recap);

        $this->assertInvariants($data, $rows, $recap);
        // nulový řádek nedostal žádný diff
        $this->assertSame(0.0, (float) $rows[2]['vat_base_dom']);
        $this->assertSame(0.0, (float) $rows[2]['vat_amount_dom']);
    }

    public function testForeignCurrencyWithTotalRoundingClosingInvariant(): void
    {
        $rate = 24.5;
        $doc = $this->doc();
        $rows = [$this->row('cz-101', 21.0, 1000.37)];
        $recap = [$this->recapLine($rows, 'cz-101', 21.0, $rate)];

        $data = ['exchange_rate' => $rate, 'total_rounding_mode' => 1];
        $doc->sumTotalsPub($data, $recap);
        $doc->applyTotalRoundingPub($data);
        $doc->applyDomesticAmountsPub($data, $rows, $recap);

        $this->assertNotEquals(0.0, (float) $data['total_rounding']);
        $this->assertInvariants($data, $rows, $recap);
    }

    public function testTextRowsKeepNullDomValues(): void
    {
        $doc = $this->doc();
        $rows = [
            ['row_kind' => 0, 'description' => 'jen text'],
            $this->row('cz-101', 21.0, 100.0),
        ];
        $recap = [$this->recapLine($rows, 'cz-101', 21.0, 1.0)];

        $data = ['exchange_rate' => 1.0];
        $doc->sumTotalsPub($data, $recap);
        $doc->applyTotalRoundingPub($data);
        $doc->applyDomesticAmountsPub($data, $rows, $recap);

        $this->assertNull($rows[0]['vat_base_dom']);
        $this->assertNull($rows[0]['vat_amount_dom']);
        $this->assertNull($rows[0]['vat_total_dom']);
    }

    // ── Persistence computed sloupců ────────────────────────────────────────

    public function testPersistRowComputedColumnsUpdatesOnlyRowsWithId(): void
    {
        $doc = $this->doc();
        $doc->setDb($this->createMock(\Dibi\Connection::class));
        $doc->setComputedRows([
            $this->row('cz-101', 21.0, 100.0, id: 7),
            $this->row('cz-101', 21.0, 50.0),          // nový řádek bez id — přeskočit
        ]);

        $doc->persistRowComputedColumns();

        $this->assertCount(1, $doc->executedSql);
        $this->assertStringContainsString('UPDATE [docs_core_rows]', $doc->executedSql[0]['sql']);
        $set = $doc->executedSql[0]['args'][0];
        // Procento se zapisuje s vypočtenými sloupci (#159 D1) — řádek ho nese.
        $this->assertSame(
            ['vat_base', 'vat_amount', 'vat_total', 'vat_base_dom', 'vat_amount_dom', 'vat_total_dom', 'vat_pct'],
            array_keys($set),
        );
        $this->assertSame(7, $doc->executedSql[0]['args'][1]);
    }

    /**
     * Procento a datum sazby se zapisují jen z klíčů, které řádek nese
     * (#159 U8): řádek z payloadu bez procenta uložené nepřepíše, řádek
     * s datem (i NULL po přepnutí zdroje) ho propíše.
     */
    public function testPersistRowComputedColumnsWritesRateColumnsOnlyWhenPresent(): void
    {
        $doc = $this->doc();
        $doc->setDb($this->createMock(\Dibi\Connection::class));
        $withoutPct = $this->row('cz-101', 21.0, 100.0, id: 7);
        unset($withoutPct['vat_pct']);
        $withDate = $this->row('cz-101', 21.0, 100.0, id: 8) + ['vat_rate_date' => null];
        $doc->setComputedRows([$withoutPct, $withDate]);

        $doc->persistRowComputedColumns();

        $this->assertArrayNotHasKey('vat_pct', $doc->executedSql[0]['args'][0]);
        $this->assertArrayNotHasKey('vat_rate_date', $doc->executedSql[0]['args'][0]);
        $second = $doc->executedSql[1]['args'][0];
        $this->assertSame(21.0, (float) $second['vat_pct']);
        $this->assertArrayHasKey('vat_rate_date', $second);
        $this->assertNull($second['vat_rate_date']);
    }

    public function testPersistRowComputedColumnsNoDbIsNoop(): void
    {
        $doc = $this->doc();
        $doc->setComputedRows([$this->row('cz-101', 21.0, 100.0, id: 7)]);

        $doc->persistRowComputedColumns();

        $this->assertCount(0, $doc->executedSql);
    }

    // ── Child-sync ochrana (regrese) ────────────────────────────────────────

    public function testBeforeSaveWithoutRowsKeyNeverAddsIt(): void
    {
        // Header-only save: přidání klíče 'rows' by spustilo child sync
        // a smazalo řádky v DB.
        $doc = $this->doc();
        $data = ['docState' => 10, 'issue_date' => '2026-06-10'];

        $doc->beforeSavePub($data);

        $this->assertArrayNotHasKey('rows', $data);
        $this->assertSame([], $doc->getComputedRows());
    }

    public function testBeforeSaveWithRowsInPayloadPropagatesComputedValues(): void
    {
        // Full-sync save: computed hodnoty musí do $data['rows'], aby je
        // gateway zapsal i pro nové řádky bez id.
        $doc = $this->doc();
        $data = [
            'docState'   => 10,
            'issue_date' => '2026-06-10',
            'vat_mode'   => 1,
            'exchange_rate' => 25.0,
            'doc_currency' => 'eur',
            'home_currency' => 'czk',
            'rows' => [[
                'row_kind' => 1, 'quantity' => 2, 'unit_price' => 50.0,
                'vat_code' => 'cz-101', 'vat_pct' => 21.0,
            ]],
        ];

        $doc->beforeSavePub($data);

        $this->assertSame(100.0, $data['rows'][0]['vat_base']);
        $this->assertSame(21.0, $data['rows'][0]['vat_amount']);
        $this->assertSame(2500.0, $data['rows'][0]['vat_base_dom']);
        $this->assertSame(525.0, $data['rows'][0]['vat_amount_dom']);
        $this->assertSame(3025.0, $data['rows'][0]['vat_total_dom']);
        $this->assertCount(1, $doc->getComputedRows());
    }
}

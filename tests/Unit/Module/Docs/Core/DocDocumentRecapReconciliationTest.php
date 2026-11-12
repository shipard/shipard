<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\World\Vat\VatRateResolver;
use Shipard\Tests\Fixtures\Module\Docs\Core\TestableDocsHeadsDocument;

/**
 * Dorovnání řádků na rekapitulaci v OBOU měnách (I5, spec
 * `docs/vat-calculation.md` § 7) nad dokladovou metodou výpočtu
 * (`vat_calc_source = 0`), nad reálným `vat-cz.jsonc`.
 *
 * Invarianty per skupina (kód, sazba) i per doklad, v cur i dom:
 *
 *   Σ rows.vat_base   == recap.base       Σ rows.vat_base_dom   == recap.base_dom
 *   Σ rows.vat_amount == recap.tax        Σ rows.vat_amount_dom == recap.tax_dom
 *   Σ recap.base (sum_base) == total_base            (obdobně _dom)
 *   Σ recap.tax  (sum_tax)  == total_vat             (obdobně _dom)
 *   total_base + total_vat + total_rounding == total_amount   (obdobně _dom)
 *
 * Dokladová metoda počítá daň jednou ze součtu cen, takže řádkové hodnoty
 * z ní vycházejí o haléře jinak — a právě ty haléře musí dorovnání vrátit
 * zpět na rekapitulaci, jinak by deník (výnos z řádků, 343 z recapu,
 * 311/321 z hlavičky) nebyl vyrovnaný.
 */
class DocDocumentRecapReconciliationTest extends TestCase
{
    private const VAT_CZ_PATH = __DIR__ . '/../../../../../modules/world/vat/config/vat-cz.jsonc';
    private const DELTA = 0.001;

    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/shpd_reconcile_test_' . uniqid();
        mkdir($this->tmpDir . '/config/configuration', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = "$path/$entry";
            is_dir($full) ? $this->removeDir($full) : unlink($full);
        }
        rmdir($path);
    }

    private function doc(): TestableDocsHeadsDocument
    {
        $data = [
            '_meta' => ['language' => 'cs'],
            'items' => ['world.vat.cz' => JsoncParser::parseFile(self::VAT_CZ_PATH)],
        ];
        file_put_contents(
            $this->tmpDir . '/config/configuration/compiled.cs.json',
            json_encode($data),
        );

        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(new Row(['country' => 'cz']));

        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($db);
        $doc->setConfig(ConfigRuntime::load($this->tmpDir, 'cs'));
        return $doc;
    }

    /**
     * Compute pipeline v pořadí `DocDocument::beforeSave` (kroky 5–7) —
     * bez denormalizací, datumů a čísel, které s DPH nesouvisí.
     *
     * @param array<string, mixed> $data
     * @param array<int, array<string, mixed>> $rows
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function runPipeline(TestableDocsHeadsDocument $doc, array &$data, array $rows): array
    {
        $vatMode = (int) ($data['vat_mode'] ?? 1);
        foreach ($rows as &$row) {
            $doc->calculateRowPricePub($row);
            $doc->calculateRowVatPub($row, $vatMode, $this->vatCodes());
        }
        unset($row);

        $recap = $doc->buildVatRecapitulationPub($data, $rows);
        $doc->sumTotalsPub($data, $recap, $rows);
        $doc->applyTotalRoundingPub($data);
        $doc->reconcileRowsToRecapPub($rows, $recap);
        $doc->applyDomesticAmountsPub($data, $rows, $recap, $this->vatCodes());

        return [$rows, $recap];
    }

    /**
     * Definice DPH kódů země dokladu — stejný dotaz jako
     * `DocDocument::resolveVatCodesForDoc` (ta je private).
     *
     * @return array<string, array<string, mixed>>
     */
    private function vatCodes(): array
    {
        return (new VatRateResolver(ConfigRuntime::load($this->tmpDir, 'cs')))
            ->getVatCodes('cz', direction: null, place: null, includeHidden: true);
    }

    /** Položkový řádek v cenách dle režimu dokladu. */
    private function row(string $code, float $pct, float $price): array
    {
        return [
            'row_kind'        => 1,
            'quantity'        => 1,
            'unit_price'      => $price,
            'price_calc_mode' => 0,
            'vat_code'        => $code,
            'vat_pct'         => $pct,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, array<string, mixed>> $recap
     */
    private function assertInvariants(array $data, array $rows, array $recap): void
    {
        foreach (['' => '', '_dom' => '_dom'] as $suffix) {
            $recapBase = 0.0;
            $recapTax  = 0.0;
            foreach ($recap as $r) {
                if (!empty($r['sum_base'])) { $recapBase += (float) $r['base' . $suffix]; }
                if (!empty($r['sum_tax']))  { $recapTax  += (float) $r['tax' . $suffix]; }

                if (!empty($r['is_reverse_pair'])) {
                    continue;
                }
                $rowBase = 0.0;
                $rowAmount = 0.0;
                foreach ($rows as $row) {
                    if ((int) ($row['row_kind'] ?? 1) !== 1
                        || ($row['vat_code'] ?? null) !== $r['vat_code']
                        || (float) ($row['vat_pct'] ?? 0) !== (float) $r['vat_pct']
                    ) {
                        continue;
                    }
                    $rowBase   += (float) $row['vat_base' . $suffix];
                    $rowAmount += (float) $row['vat_amount' . $suffix];
                }
                $this->assertEqualsWithDelta((float) $r['base' . $suffix], $rowBase, self::DELTA,
                    "Σ rows.vat_base{$suffix} != recap.base{$suffix} ({$r['vat_code']})");
                $this->assertEqualsWithDelta((float) $r['tax' . $suffix], $rowAmount, self::DELTA,
                    "Σ rows.vat_amount{$suffix} != recap.tax{$suffix} ({$r['vat_code']})");
            }

            $totalSuffix = $suffix === '' ? '' : '_dom';
            $this->assertEqualsWithDelta($recapBase, (float) $data['total_base' . $totalSuffix], self::DELTA,
                "Σ recap.base{$suffix} != total_base{$totalSuffix}");
            $this->assertEqualsWithDelta($recapTax, (float) $data['total_vat' . $totalSuffix], self::DELTA,
                "Σ recap.tax{$suffix} != total_vat{$totalSuffix}");
            $this->assertEqualsWithDelta(
                (float) $data['total_amount' . $totalSuffix],
                (float) $data['total_base' . $totalSuffix]
                    + (float) $data['total_vat' . $totalSuffix]
                    + (float) $data['total_rounding' . $totalSuffix],
                self::DELTA,
                "base + vat + rounding != amount ({$totalSuffix})",
            );
        }
    }

    /**
     * Referenční prodejka: 2 × 55,00 v cenách s DPH 21 %. Rekapitulace
     * 90,91 / 19,09 / 110,00; řádkový rozpad se dorovná (druhý řádek nese
     * haléř na základu i na dani), cena řádku zůstává 55,00.
     */
    public function testVatInclusiveTwoRowsReconciledInBothCurrencies(): void
    {
        $doc = $this->doc();
        $data = [
            'vat_mode' => 2,
            'vat_registration' => 1,
            'vat_duzp' => '2026-05-06',
            'exchange_rate' => 1.0,
            'total_rounding_mode' => 0,
        ];
        [$rows, $recap] = $this->runPipeline($doc, $data, [
            $this->row('cz-110', 21.0, 55.00),
            $this->row('cz-110', 21.0, 55.00),
        ]);

        $this->assertSame(90.91, $recap[0]['base']);
        $this->assertSame(19.09, $recap[0]['tax']);
        $this->assertSame(110.00, $recap[0]['total']);

        $this->assertSame(45.45, $rows[0]['vat_base']);
        $this->assertSame(9.55, $rows[0]['vat_amount']);
        $this->assertSame(45.46, $rows[1]['vat_base'], 'haléř základu nese poslední řádek skupiny');
        $this->assertSame(9.54, $rows[1]['vat_amount'], 'haléř daně nese týž řádek, celkem zůstává 55,00');

        foreach ($rows as $row) {
            $this->assertEqualsWithDelta(55.00, (float) $row['vat_total'], self::DELTA);
            // kurz 1 → cur i dom hodnoty musí být shodné
            $this->assertEqualsWithDelta((float) $row['vat_base'], (float) $row['vat_base_dom'], self::DELTA);
            $this->assertEqualsWithDelta((float) $row['vat_amount'], (float) $row['vat_amount_dom'], self::DELTA);
            $this->assertEqualsWithDelta((float) $row['vat_total'], (float) $row['vat_total_dom'], self::DELTA);
        }

        $this->assertSame(110.00, $data['total_amount']);
        $this->assertInvariants($data, $rows, $recap);
    }

    /**
     * Mode 1 (ceny bez DPH), tři řádky se zbytkem: daň se počítá jednou ze
     * součtu (62,99), řádkové daně 3 × 21,00 se dorovnají — poslední řádek
     * nese −0,01 a jeho `vat_total` je 120,98.
     */
    public function testVatExclusiveThreeRowsReconciledInBothCurrencies(): void
    {
        $doc = $this->doc();
        $data = [
            'vat_mode' => 1,
            'vat_registration' => 1,
            'vat_duzp' => '2026-05-06',
            'exchange_rate' => 1.0,
            'total_rounding_mode' => 0,
        ];
        [$rows, $recap] = $this->runPipeline($doc, $data, [
            $this->row('cz-110', 21.0, 99.99),
            $this->row('cz-110', 21.0, 99.99),
            $this->row('cz-110', 21.0, 99.99),
        ]);

        $this->assertSame(299.97, $recap[0]['base']);
        $this->assertSame(62.99, $recap[0]['tax']);

        $this->assertSame(21.00, $rows[0]['vat_amount']);
        $this->assertSame(21.00, $rows[1]['vat_amount']);
        $this->assertSame(20.99, $rows[2]['vat_amount']);
        $this->assertSame(120.98, $rows[2]['vat_total'], 'celkem řádku = dorovnané části');
        // základ se v mode 1 nedorovnává — je to cena řádku
        foreach ($rows as $row) {
            $this->assertSame(99.99, (float) $row['vat_base']);
        }

        $this->assertSame(362.96, $data['total_amount']);
        $this->assertInvariants($data, $rows, $recap);
    }

    /**
     * Cizí měna, dvě sazby, zbytky v obou: invarianty platí v měně dokladu
     * i v domácí, dorovnání obou měn je nezávislé.
     */
    public function testForeignCurrencyTwoRatesInvariantsHoldInBothCurrencies(): void
    {
        $doc = $this->doc();
        $data = [
            'vat_mode' => 2,
            'vat_registration' => 1,
            'vat_duzp' => '2026-05-06',
            'doc_currency' => 'eur',
            'home_currency' => 'czk',
            'exchange_rate' => 25.285,
            'total_rounding_mode' => 0,
        ];
        [$rows, $recap] = $this->runPipeline($doc, $data, [
            $this->row('cz-110', 21.0, 33.33),
            $this->row('cz-110', 21.0, 66.67),
            $this->row('cz-111', 12.0, 11.11),
            $this->row('cz-111', 12.0, 22.22),
        ]);

        $this->assertCount(2, $recap);
        $this->assertInvariants($data, $rows, $recap);
    }

    /**
     * Samovyměření (cz-115 → cz-203) v mode 1: informativní daň řádku se
     * dorovná na daň recapu, ale `vat_total` řádku zůstává cena — daň se
     * dodavateli neplatí (noPayTax), takže do celkem nevstupuje.
     */
    public function testReverseChargeRowKeepsPriceAsTotal(): void
    {
        $doc = $this->doc();
        $data = [
            'vat_mode' => 1,
            'vat_registration' => 1,
            'vat_duzp' => '2026-05-06',
            'exchange_rate' => 1.0,
            'total_rounding_mode' => 0,
        ];
        [$rows, $recap] = $this->runPipeline($doc, $data, [
            $this->row('cz-115', 21.0, 1000.00),
        ]);

        $this->assertCount(2, $recap, 'primární řádek + oddaňovací pár');
        $this->assertSame(1000.0, $recap[0]['base']);
        $this->assertSame(210.0, $recap[0]['tax']);
        $this->assertSame(1000.0, $recap[0]['total']);

        $this->assertSame(1000.0, (float) $rows[0]['vat_base']);
        $this->assertSame(210.0, (float) $rows[0]['vat_amount']);
        $this->assertSame(1000.0, (float) $rows[0]['vat_total']);
        $this->assertSame(1000.0, (float) $rows[0]['vat_total_dom']);

        $this->assertSame(1000.0, $data['total_amount']);
        $this->assertSame(0.0, $data['total_vat'], 'sumTax = 0 → daň se do hlavičky nesčítá');
    }


    /**
     * Dva předměty plnění na jednom kódu a sazbě (#89 D7) = dvě skupiny:
     * každá se dorovnává na svůj řádek rekapitulace, ne na společný součet.
     */
    public function testRowsWithDifferentSubjectsReconcileToTheirOwnGroups(): void
    {
        $doc = $this->doc();
        $data = [
            'vat_mode' => 1, 'vat_registration' => 1, 'vat_duzp' => '2026-05-06',
            'exchange_rate' => 1.0, 'total_rounding_mode' => 0,
        ];
        $rows = [
            $this->row('cz-115', 21.0, 33.33) + ['reverse_charge_code' => '4'],
            $this->row('cz-115', 21.0, 33.33) + ['reverse_charge_code' => '4'],
            $this->row('cz-115', 21.0, 33.33) + ['reverse_charge_code' => '14'],
        ];

        [$rows, $recap] = $this->runPipeline($doc, $data, $rows);

        $primary = array_values(array_filter($recap, static fn (array $r): bool => empty($r['is_reverse_pair'])));
        $this->assertSame(['4', '14'], array_column($primary, 'reverse_charge_code'));
        foreach ($primary as $r) {
            $rowBase = 0.0;
            $rowTax  = 0.0;
            foreach ($rows as $row) {
                if ($row['reverse_charge_code'] === $r['reverse_charge_code']) {
                    $rowBase += (float) $row['vat_base'];
                    $rowTax  += (float) $row['vat_amount'];
                }
            }
            $this->assertEqualsWithDelta((float) $r['base'], $rowBase, self::DELTA);
            $this->assertEqualsWithDelta((float) $r['tax'], $rowTax, self::DELTA);
        }
        $this->assertCount(4, $recap, 'dvě skupiny, každá se svým oddaňovacím párem');
    }
}

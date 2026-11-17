<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Docs\Core\VatRateDates;
use Shipard\Module\World\Vat\VatRateResolver;

/**
 * Datum sazby DPH (#159 D1–D6, D9, D15, U13): pravidla nad daty hlavičky
 * a řádku — automatika, vlastní datum dokladu, vlastní datum na řádcích,
 * mazání dat mimo režim, procento k datu a nálezy.
 */
class VatRateDatesTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/shpd_vat_rate_dates_' . uniqid();
        mkdir($this->tmpDir . '/config/configuration', 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/config/configuration/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->tmpDir . '/config/configuration');
        rmdir($this->tmpDir . '/config');
        rmdir($this->tmpDir);
    }

    private function config(): ConfigRuntime
    {
        $items = [
            'docs.core.docTypes' => [
                'invno' => ['trade_dir' => 1],
                'invni' => ['trade_dir' => 2],
                'invpo' => ['trade_dir' => 1, 'tax_document' => false],
            ],
            'world.vat.cz' => [
                'vatCategories' => ['standard' => ['name' => 'Standard'], 'reduced' => ['name' => 'Reduced']],
                'vatCodes' => [
                    'cz-110' => ['category' => 'standard', 'place' => 'domestic', 'direction' => 'input'],
                    'cz-302' => ['category' => 'reduced', 'place' => 'domestic', 'direction' => 'input'],
                ],
                'vatPercents' => [
                    ['code' => 'cz-110', 'from' => '2010-01-01', 'to' => '2012-12-31', 'value' => 20.0],
                    ['code' => 'cz-110', 'from' => '2013-01-01', 'to' => '0000-00-00', 'value' => 21.0],
                    ['code' => 'cz-302', 'from' => '2015-01-01', 'to' => '2023-12-31', 'value' => 10.0],
                ],
            ],
        ];
        file_put_contents(
            $this->tmpDir . '/config/configuration/compiled.cs.json',
            json_encode(['_meta' => ['language' => 'cs'], 'items' => $items]),
        );
        return ConfigRuntime::load($this->tmpDir, 'cs');
    }

    // ── D3: automatika ───────────────────────────────────────────────────

    public function testAutomaticDateOfCorrectiveDocumentIsOriginalTaxPointDate(): void
    {
        $head = [
            'corrective'         => 1,
            'corrected_doc_date' => '2012-06-01',
            'vat_dppd'           => '2026-05-05',
            'vat_duzp'           => '2026-05-06',
            'issue_date'         => '2026-05-07',
        ];
        $this->assertSame('2012-06-01', VatRateDates::automaticDate($head));

        $head['corrected_doc_date'] = null;
        $this->assertSame('2026-05-05', VatRateDates::automaticDate($head), 'bez data původního plnění DPPD');

        $head['corrective'] = 0;
        $head['corrected_doc_date'] = '2012-06-01';
        $this->assertSame('2026-05-05', VatRateDates::automaticDate($head), 'bez příznaku se datum opravy nečte');
    }

    public function testAutomaticDateIsDppdThenDuzpThenIssueDate(): void
    {
        $this->assertSame('2012-12-31', VatRateDates::automaticDate(
            ['vat_dppd' => '2012-12-31', 'vat_duzp' => '2013-01-04', 'issue_date' => '2013-01-07'],
        ));
        $this->assertSame('2013-01-04', VatRateDates::automaticDate(
            ['vat_dppd' => null, 'vat_duzp' => '2013-01-04', 'issue_date' => '2013-01-07'],
        ));
        $this->assertSame('2013-01-07', VatRateDates::automaticDate(
            ['vat_dppd' => '', 'vat_duzp' => null, 'issue_date' => '2013-01-07'],
        ));
        $this->assertNull(VatRateDates::automaticDate([]));
    }

    public function testDateObjectsAndDateTimesAreNormalized(): void
    {
        $head = ['corrective' => true, 'corrected_doc_date' => new \DateTimeImmutable('2012-06-01 00:00:00')];
        $this->assertSame('2012-06-01', VatRateDates::automaticDate($head));
        $this->assertSame('2026-05-06', VatRateDates::automaticDate(['vat_duzp' => '2026-05-06T00:00:00']));
    }

    // ── D2: zdroj ────────────────────────────────────────────────────────

    public function testSourceIsForcedToAutomaticOutsideTaxDocumentsWithVat(): void
    {
        $config = $this->config();
        $this->assertSame(1, VatRateDates::source(['doc_type' => 'invno', 'vat_rate_source' => 1], $config));
        $this->assertSame(2, VatRateDates::source(['doc_type' => 'invno', 'vat_rate_source' => '2'], $config));
        $this->assertSame(0, VatRateDates::source(['doc_type' => 'invno'], $config), 'bez klíče automatika');
        $this->assertSame(0, VatRateDates::source(['doc_type' => 'invno', 'vat_rate_source' => 7], $config), 'neznámá hodnota');
        $this->assertSame(
            0,
            VatRateDates::source(['doc_type' => 'invpo', 'vat_rate_source' => 2], $config),
            'nedaňový doklad',
        );
        $this->assertSame(
            0,
            VatRateDates::source(['doc_type' => 'invno', 'vat_mode' => 0, 'vat_rate_source' => 1], $config),
            'doklad bez DPH',
        );
        $this->assertSame(1, VatRateDates::source(['doc_type' => 'invpo', 'vat_rate_source' => 1]), 'bez configu daňový');
    }

    // ── D4: vlastní datum dokladu ────────────────────────────────────────

    public function testHeadDateUsesOwnDateOnlyWithDocumentSource(): void
    {
        $head = ['vat_dppd' => '2013-01-04', 'vat_rate_source' => 1, 'vat_rate_date' => '2012-12-31'];
        $this->assertSame('2012-12-31', VatRateDates::headDate($head));

        $head['vat_rate_date'] = null;
        $this->assertSame('2013-01-04', VatRateDates::headDate($head), 'zdroj 1 bez data: automatika');

        $head = ['vat_dppd' => '2013-01-04', 'vat_rate_source' => 0, 'vat_rate_date' => '2012-12-31'];
        $this->assertSame('2013-01-04', VatRateDates::headDate($head), 'zdroj 0 vlastní datum nečte');

        $head['vat_rate_source'] = 2;
        $this->assertSame('2013-01-04', VatRateDates::headDate($head), 'zdroj 2: datum dokladu je automatika');
    }

    // ── D5: vlastní datum na řádcích ─────────────────────────────────────

    public function testLineDateUsesRowDateOnlyWithRowsSource(): void
    {
        $head = ['vat_dppd' => '2014-03-10', 'vat_rate_source' => 2, 'vat_rate_date' => '2011-01-01'];
        $this->assertSame('2012-11-20', VatRateDates::lineDate($head, ['vat_rate_date' => '2012-11-20']));
        $this->assertSame('2014-03-10', VatRateDates::lineDate($head, ['vat_rate_date' => null]), 'prázdné = automatika');
        $this->assertSame('2014-03-10', VatRateDates::lineDate($head, []), 'bez klíče automatika');

        $head['vat_rate_source'] = 1;
        $this->assertSame(
            '2011-01-01',
            VatRateDates::lineDate($head, ['vat_rate_date' => '2012-11-20']),
            'zdroj 1: datum dokladu, datum řádku se nečte',
        );

        $head['vat_rate_source'] = 0;
        $this->assertSame('2014-03-10', VatRateDates::lineDate($head, ['vat_rate_date' => '2012-11-20']));
    }

    // ── D6: mazání dat mimo režim ────────────────────────────────────────

    public function testNormalizeHeadKeepsOwnDateOnlyWithDocumentSource(): void
    {
        $head = ['doc_type' => 'invno', 'vat_rate_source' => 1, 'vat_rate_date' => '2012-12-31 00:00:00'];
        VatRateDates::normalizeHead($head, $this->config());
        $this->assertSame(1, $head['vat_rate_source']);
        $this->assertSame('2012-12-31', $head['vat_rate_date']);

        foreach ([0, 2] as $source) {
            $head = ['doc_type' => 'invno', 'vat_rate_source' => $source, 'vat_rate_date' => '2012-12-31'];
            VatRateDates::normalizeHead($head, $this->config());
            $this->assertSame($source, $head['vat_rate_source']);
            $this->assertNull($head['vat_rate_date'], "zdroj {$source} vlastní datum dokladu maže");
        }
    }

    public function testNormalizeHeadForcesAutomaticSourceOnNonTaxDocument(): void
    {
        $head = ['doc_type' => 'invpo', 'vat_rate_source' => 1, 'vat_rate_date' => '2012-12-31'];
        VatRateDates::normalizeHead($head, $this->config());
        $this->assertSame(0, $head['vat_rate_source']);
        $this->assertNull($head['vat_rate_date']);

        $head = ['doc_type' => 'invno', 'vat_mode' => 0, 'vat_rate_source' => 2];
        VatRateDates::normalizeHead($head, $this->config());
        $this->assertSame(0, $head['vat_rate_source'], 'doklad bez DPH');
    }

    public function testNormalizeLineKeepsRowDateOnlyWithRowsSource(): void
    {
        $line = ['vat_rate_date' => '2012-11-20'];
        VatRateDates::normalizeLine(['vat_rate_source' => 2], $line);
        $this->assertSame('2012-11-20', $line['vat_rate_date']);

        $line = ['vat_rate_date' => ''];
        VatRateDates::normalizeLine(['vat_rate_source' => 2], $line);
        $this->assertNull($line['vat_rate_date'], 'prázdný řetězec je NULL');

        foreach ([0, 1] as $source) {
            $line = ['vat_rate_date' => '2012-11-20'];
            VatRateDates::normalizeLine(['vat_rate_source' => $source], $line);
            $this->assertNull($line['vat_rate_date'], "zdroj {$source} datum řádku maže");
        }
    }

    // ── D1: procento ─────────────────────────────────────────────────────

    public function testPctFollowsCodeAndDate(): void
    {
        $resolver = new VatRateResolver($this->config());
        $this->assertSame(20.0, VatRateDates::pct($resolver, 'cz', 'cz-110', '2012-12-31'));
        $this->assertSame(21.0, VatRateDates::pct($resolver, 'cz', 'cz-110', '2013-01-04'));
        $this->assertSame(10.0, VatRateDates::pct($resolver, 'cz', 'cz-302', '2023-06-01'));
        $this->assertNull(VatRateDates::pct($resolver, 'cz', 'cz-302', '2024-02-01'), 'kód bez sazby k datu');
        $this->assertNull(VatRateDates::pct($resolver, 'cz', 'cz-999', '2024-02-01'), 'kód mimo číselník');
        $this->assertNull(VatRateDates::pct($resolver, 'cz', '', '2024-02-01'));
        $this->assertNull(VatRateDates::pct($resolver, 'cz', 'cz-110', null));
    }

    // ── D9, D15, U11: nálezy ─────────────────────────────────────────────

    public function testMissingRateIsWarningUntilStrict(): void
    {
        $resolver = new VatRateResolver($this->config());
        $head = ['doc_type' => 'invni', 'vat_dppd' => '2024-02-01'];
        $line = ['vat_code' => 'cz-302'];

        $problems = VatRateDates::lineProblems($resolver, 'cz', $head, $line, $this->config(), strict: false);
        $this->assertCount(1, $problems);
        $this->assertSame('vat_code', $problems[0]['column']);
        $this->assertSame('vat_rate_missing', $problems[0]['code']);
        $this->assertTrue($problems[0]['warning']);
        $this->assertStringContainsString('01.02.2024', $problems[0]['message']);

        $problems = VatRateDates::lineProblems($resolver, 'cz', $head, $line, $this->config(), strict: true);
        $this->assertFalse($problems[0]['warning'], 'při potvrzení a importu chyba');

        $this->assertSame([], VatRateDates::lineProblems(
            $resolver, 'cz', $head, ['vat_code' => 'cz-110'], $this->config(), strict: true,
        ));
        $this->assertSame([], VatRateDates::lineProblems(
            $resolver, 'cz', $head, ['vat_code' => ''], $this->config(), strict: true,
        ), 'řádek bez kódu nálezy nemá');
    }

    public function testRowDateResolvesCodeOtherwiseWithoutRate(): void
    {
        $resolver = new VatRateResolver($this->config());
        $head = ['doc_type' => 'invni', 'vat_dppd' => '2024-02-01', 'vat_rate_source' => 2];
        $this->assertSame([], VatRateDates::lineProblems(
            $resolver, 'cz', $head, ['vat_code' => 'cz-302', 'vat_rate_date' => '2023-11-30'], $this->config(), strict: true,
        ));
    }

    public function testLateRowDateIsWarning(): void
    {
        $resolver = new VatRateResolver($this->config());
        $head = ['doc_type' => 'invni', 'vat_dppd' => '2012-12-31', 'vat_rate_source' => 2];
        $problems = VatRateDates::lineProblems(
            $resolver, 'cz', $head, ['vat_code' => 'cz-110', 'vat_rate_date' => '2013-02-01'], $this->config(), strict: true,
        );
        $this->assertCount(1, $problems);
        $this->assertSame('vat_rate_date', $problems[0]['column']);
        $this->assertSame('vat_rate_date_late', $problems[0]['code']);
        $this->assertTrue($problems[0]['warning'], 'pozdější datum neblokuje ani potvrzení');

        $head['vat_rate_source'] = 0;
        $this->assertSame([], VatRateDates::lineProblems(
            $resolver, 'cz', $head, ['vat_code' => 'cz-110', 'vat_rate_date' => '2013-02-01'], $this->config(), strict: true,
        ), 'mimo zdroj 2 se datum řádku nečte');
    }

    public function testHeadProblemsRequiredAndLate(): void
    {
        $config = $this->config();
        $head = ['doc_type' => 'invni', 'vat_dppd' => '2013-01-04', 'vat_rate_source' => 1, 'vat_rate_date' => null];
        $this->assertSame([], VatRateDates::headProblems($head, $config, strict: false), 'do potvrzení automatika');

        $problems = VatRateDates::headProblems($head, $config, strict: true);
        $this->assertCount(1, $problems);
        $this->assertSame('vat_rate_date', $problems[0]['column']);
        $this->assertSame('vat_rate_date_required', $problems[0]['code']);
        $this->assertFalse($problems[0]['warning']);

        $head['vat_rate_date'] = '2013-03-01';
        $problems = VatRateDates::headProblems($head, $config, strict: true);
        $this->assertSame('vat_rate_date_late', $problems[0]['code']);
        $this->assertTrue($problems[0]['warning']);

        $head['vat_rate_date'] = '2012-12-31';
        $this->assertSame([], VatRateDates::headProblems($head, $config, strict: true), 'dřívější datum je v pořádku');

        $head['vat_rate_source'] = 0;
        $head['vat_rate_date'] = '2013-03-01';
        $this->assertSame([], VatRateDates::headProblems($head, $config, strict: true), 'zdroj 0 vlastní datum nečte');
    }

    // ── U13: DPPD následuje DUZP ─────────────────────────────────────────

    public function testObligationDateFollowsChangedTaxPointDate(): void
    {
        $original = ['vat_duzp' => '2024-01-05', 'vat_dppd' => '2024-01-05'];

        $head = ['vat_duzp' => '2023-12-20', 'vat_dppd' => '2024-01-05'];
        VatRateDates::followObligationDate($head, $original);
        $this->assertSame('2023-12-20', $head['vat_dppd'], 'payload nese staré DPPD');

        $head = ['vat_duzp' => '2023-12-20'];
        VatRateDates::followObligationDate($head, $original);
        $this->assertSame('2023-12-20', $head['vat_dppd'], 'payload bez DPPD');
    }

    public function testObligationDateStaysWhenItDidNotFollowOrPayloadSetsIt(): void
    {
        $head = ['vat_duzp' => '2023-12-20', 'vat_dppd' => '2024-01-10'];
        VatRateDates::followObligationDate($head, ['vat_duzp' => '2024-01-05', 'vat_dppd' => '2024-01-10']);
        $this->assertSame('2024-01-10', $head['vat_dppd'], 'uložené DPPD se od DUZP lišilo — je to fakt');

        $head = ['vat_duzp' => '2023-12-20', 'vat_dppd' => '2023-12-28'];
        VatRateDates::followObligationDate($head, ['vat_duzp' => '2024-01-05', 'vat_dppd' => '2024-01-05']);
        $this->assertSame('2023-12-28', $head['vat_dppd'], 'payload DPPD sám mění');

        $head = ['vat_duzp' => '2024-01-05', 'vat_dppd' => '2024-01-05'];
        VatRateDates::followObligationDate($head, ['vat_duzp' => '2024-01-05', 'vat_dppd' => '2024-01-05']);
        $this->assertSame('2024-01-05', $head['vat_dppd'], 'DUZP se nezměnilo');
    }
}

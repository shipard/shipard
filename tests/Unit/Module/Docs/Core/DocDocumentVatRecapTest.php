<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Tests\Fixtures\Module\Docs\Core\TestableDocsHeadsDocument;

class DocDocumentVatRecapTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/shpd_recap_test_' . uniqid();
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

    /**
     * Build a minimal world.vat.cz cfgItem with the codes we test.
     */
    private function buildConfig(): ConfigRuntime
    {
        $items = [
            'world.vat.cz' => [
                'vatCategories' => [
                    'standard' => ['name' => 'Standard'],
                    'reduced'  => ['name' => 'Reduced'],
                    'exempt'   => ['name' => 'Exempt'],
                ],
                'vatCodes' => [
                    'cz-110' => [
                        'category' => 'standard', 'place' => 'domestic', 'direction' => 'input',
                    ],
                    'cz-111' => [
                        'category' => 'reduced', 'place' => 'domestic', 'direction' => 'input',
                    ],
                    'cz-115' => [
                        'category' => 'standard', 'place' => 'domestic', 'direction' => 'input',
                        'noPayTax' => 1, 'sumTax' => 0,
                        'reverseVatCode' => 'cz-203', 'reverseCharge' => 1,
                    ],
                    'cz-203' => [
                        'category' => 'standard', 'place' => 'domestic', 'direction' => 'output',
                        'noPayTax' => 1, 'hidden' => 1, 'sumBase' => 0, 'sumTax' => 0, 'sumTotal' => 0,
                    ],
                    // Osvobozeno (exempt) — valid code with a 0% rate.
                    'cz-123' => [
                        'category' => 'exempt', 'place' => 'domestic', 'direction' => 'output',
                    ],
                ],
                // Reverse kód cz-203 zde ZÁMĚRNĚ nemá vatPercents záznam —
                // pár musí dědit sazbu primární skupiny (cz-115), ne volat
                // rate resolver (ten by na chybějící sazbu vyhodil výjimku).
                'vatPercents' => [
                    ['code' => 'cz-110', 'from' => '0000-00-00', 'to' => '0000-00-00', 'value' => 21],
                    ['code' => 'cz-111', 'from' => '0000-00-00', 'to' => '0000-00-00', 'value' => 12],
                    ['code' => 'cz-115', 'from' => '0000-00-00', 'to' => '0000-00-00', 'value' => 21],
                    ['code' => 'cz-123', 'from' => '0000-00-00', 'to' => '0000-00-00', 'value' => 0],
                ],
                'vatNotes' => [],
            ],
        ];
        $data = ['_meta' => ['language' => 'cs'], 'items' => $items];
        file_put_contents(
            $this->tmpDir . '/config/configuration/compiled.cs.json',
            json_encode($data),
        );
        return ConfigRuntime::load($this->tmpDir, 'cs');
    }

    private function dbWithCountry(string $country): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(new Row(['country' => $country]));
        return $db;
    }

    private function buildDoc(): TestableDocsHeadsDocument
    {
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($this->dbWithCountry('cz'));
        $doc->setConfig($this->buildConfig());
        return $doc;
    }

    public function testEmptyRowsReturnsEmptyRecap(): void
    {
        $doc = $this->buildDoc();
        $data = ['rows' => [], 'vat_registration' => 1, 'vat_duzp' => '2026-05-06'];
        $recap = $doc->buildVatRecapitulationPub($data);

        $this->assertSame([], $recap);
    }

    public function testNoVatRegistrationReturnsEmptyRecap(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $doc->setConfig($this->buildConfig());
        // No DB → resolveCountryFromVatRegistration returns null

        $data = [
            'rows' => [['row_kind' => 1, 'vat_code' => 'cz-110', 'vat_pct' => 21, 'total_price' => 100]],
            'vat_registration' => 1,
            'vat_duzp' => '2026-05-06',
        ];
        $this->assertSame([], $doc->buildVatRecapitulationPub($data));
    }

    public function testSingleStandardCodeOneRow(): void
    {
        $doc = $this->buildDoc();
        $data = [
            'rows' => [
                ['row_kind' => 1, 'vat_code' => 'cz-110', 'vat_pct' => 21, 'total_price' => 200],
            ],
            'vat_registration' => 1,
            'vat_duzp' => '2026-05-06',
            'exchange_rate' => 1.0,
        ];
        $recap = $doc->buildVatRecapitulationPub($data);

        $this->assertCount(1, $recap);
        $this->assertSame('cz-110', $recap[0]['vat_code']);
        $this->assertSame(200.0, $recap[0]['base']);
        $this->assertSame(42.0, $recap[0]['tax']);
        $this->assertSame(242.0, $recap[0]['total']);
        $this->assertSame(1, $recap[0]['sum_base']);
        $this->assertSame(1, $recap[0]['sum_tax']);
        $this->assertSame(1, $recap[0]['sum_total']);
        $this->assertSame(0, $recap[0]['is_reverse_pair']);
    }

    public function testGroupingAcrossRowsSameCode(): void
    {
        $doc = $this->buildDoc();
        $data = [
            'rows' => [
                ['row_kind' => 1, 'vat_code' => 'cz-110', 'vat_pct' => 21, 'total_price' => 100],
                ['row_kind' => 1, 'vat_code' => 'cz-110', 'vat_pct' => 21, 'total_price' => 100],
            ],
            'vat_registration' => 1,
            'vat_duzp' => '2026-05-06',
        ];
        $recap = $doc->buildVatRecapitulationPub($data);

        $this->assertCount(1, $recap);
        $this->assertSame(200.0, $recap[0]['base']);
        $this->assertSame(42.0, $recap[0]['tax']);
    }

    public function testReverseChargeGeneratesPair(): void
    {
        $doc = $this->buildDoc();
        $data = [
            'rows' => [
                ['row_kind' => 1, 'vat_code' => 'cz-115', 'vat_pct' => 21, 'total_price' => 200],
            ],
            'vat_registration' => 1,
            'vat_duzp' => '2026-05-06',
            'exchange_rate' => 1.0,
        ];
        $recap = $doc->buildVatRecapitulationPub($data);

        $this->assertCount(2, $recap);

        // Primary cz-115 — samovyměření: tax=42 (nárok na odpočet),
        // total ale jen základ (noPayTax — daň se dodavateli neplatí)
        $this->assertSame('cz-115', $recap[0]['vat_code']);
        $this->assertSame(200.0, $recap[0]['base']);
        $this->assertSame(42.0, $recap[0]['tax']);
        $this->assertSame(200.0, $recap[0]['total']);
        $this->assertSame(0, $recap[0]['sum_tax']);
        $this->assertSame(0, $recap[0]['is_reverse_pair']);

        // Paired cz-203 — sazbu i daň dědí z primární skupiny (21 %), base=200,
        // tax=42, all sum_*=0, is_reverse_pair=1. cz-203 nemá vlastní
        // vatPercents (viz buildConfig) — důkaz, že se resolver nevolá.
        $this->assertSame('cz-203', $recap[1]['vat_code']);
        $this->assertSame(21.0, $recap[1]['vat_pct']);
        $this->assertSame(200.0, $recap[1]['base']);
        $this->assertSame(42.0, $recap[1]['tax']);
        $this->assertSame(0, $recap[1]['sum_base']);
        $this->assertSame(0, $recap[1]['sum_tax']);
        $this->assertSame(0, $recap[1]['sum_total']);
        $this->assertSame(1, $recap[1]['is_reverse_pair']);
    }

    /**
     * "Z ceny celkem" (vat_mode = 2): the row's total_price is VAT-inclusive
     * and calculateRowVat() has already back-calculated vat_base. The recap
     * must aggregate vat_base, not total_price, so the summary base/tax/total
     * match the per-row figures.
     *
     * Mirrors issued invoice 12610005: total_price=7000 incl. 12% VAT →
     * base=6250, tax=750, total=7000 (not base=7000, tax=840, total=7840).
     */
    public function testVatInclusiveModeAggregatesBaseNotTotal(): void
    {
        $doc = $this->buildDoc();
        $data = [
            'vat_mode' => 2,
            'rows' => [
                [
                    'row_kind'    => 1,
                    'vat_code'    => 'cz-111',
                    'vat_pct'     => 12,
                    'total_price' => 7000,   // VAT-inclusive
                    'vat_base'    => 6250.0, // computed by calculateRowVat (7000 / 1.12)
                    'vat_amount'  => 750.0,
                    'vat_total'   => 7000.0,
                ],
            ],
            'vat_registration' => 1,
            'vat_duzp' => '2026-05-06',
            'exchange_rate' => 1.0,
        ];
        $recap = $doc->buildVatRecapitulationPub($data);

        $this->assertCount(1, $recap);
        $this->assertSame('cz-111', $recap[0]['vat_code']);
        $this->assertSame(6250.0, $recap[0]['base']);
        $this->assertSame(750.0, $recap[0]['tax']);
        $this->assertSame(7000.0, $recap[0]['total']);
    }

    /**
     * "Z ceny celkem" se zbytkem po zpětném rozpočtu (referenční účtenka PHM
     * z docs-vat-mode-derivation): daň v rekapitulaci musí být rozdíl
     * Σ vat_total − Σ vat_base (303,02), ne zdola ze základu skupiny
     * (1442,98 × 21 % = 303,0258 → 303,03) — jinak doklad ujede o haléř
     * proti předloze (1746,01 místo 1746,00).
     */
    public function testVatInclusiveModeRemainderTaxByDifference(): void
    {
        $doc = $this->buildDoc();
        $data = [
            'vat_mode' => 2,
            'rows' => [
                [
                    'row_kind'    => 1,
                    'vat_code'    => 'cz-110',
                    'vat_pct'     => 21,
                    'total_price' => 1746.00, // VAT-inclusive
                    'vat_base'    => 1442.98, // 1746 / 1.21 = 1442.9752…
                    'vat_amount'  => 303.02,
                    'vat_total'   => 1746.00,
                ],
            ],
            'vat_registration' => 1,
            'vat_duzp' => '2026-05-06',
            'exchange_rate' => 1.0,
        ];
        $recap = $doc->buildVatRecapitulationPub($data);

        $this->assertCount(1, $recap);
        $this->assertSame(1442.98, $recap[0]['base']);
        $this->assertSame(303.02, $recap[0]['tax']);
        $this->assertSame(1746.00, $recap[0]['total']);
    }

    /**
     * Víceřádková skupina v mode 2 se zbytky na obou řádcích, metoda
     * `1 z řádků`: rekapitulace == součet per-row hodnot přesně
     * (base 102,02 + 561,07, daň 21,43 + 117,83). Dokladová úroveň dá jiný
     * rozpad téže částky — viz zrcadlový test níže.
     */
    public function testVatInclusiveModeMultiRowGroupFromRowsSumsRowValues(): void
    {
        $doc = $this->buildDoc();
        $data = [
            'vat_mode' => 2,
            'vat_calc_source' => 1,
            'rows' => [
                [
                    'row_kind' => 1, 'vat_code' => 'cz-110', 'vat_pct' => 21,
                    'total_price' => 123.45,
                    'vat_base' => 102.02, 'vat_amount' => 21.43, 'vat_total' => 123.45,
                ],
                [
                    'row_kind' => 1, 'vat_code' => 'cz-110', 'vat_pct' => 21,
                    'total_price' => 678.90,
                    'vat_base' => 561.07, 'vat_amount' => 117.83, 'vat_total' => 678.90,
                ],
            ],
            'vat_registration' => 1,
            'vat_duzp' => '2026-05-06',
            'exchange_rate' => 1.0,
        ];
        $recap = $doc->buildVatRecapitulationPub($data);

        $this->assertCount(1, $recap);
        $this->assertSame(663.09, $recap[0]['base']);
        $this->assertSame(139.26, $recap[0]['tax']);
        $this->assertSame(802.35, $recap[0]['total']);
    }

    /**
     * Zrcadlo předchozího testu na dokladové úrovni (`vat_calc_source = 0`,
     * default): základ se počítá jednou ze součtu cen skupiny —
     * round(802,35 / 1,21) = 663,10, daň rozdílem 139,25. Celková částka je
     * v obou metodách stejná (802,35), liší se jen rozpad.
     */
    public function testVatInclusiveModeMultiRowGroupFromHeaderComputesOnce(): void
    {
        $doc = $this->buildDoc();
        $data = [
            'vat_mode' => 2,
            'rows' => [
                [
                    'row_kind' => 1, 'vat_code' => 'cz-110', 'vat_pct' => 21,
                    'total_price' => 123.45,
                    'vat_base' => 102.02, 'vat_amount' => 21.43, 'vat_total' => 123.45,
                ],
                [
                    'row_kind' => 1, 'vat_code' => 'cz-110', 'vat_pct' => 21,
                    'total_price' => 678.90,
                    'vat_base' => 561.07, 'vat_amount' => 117.83, 'vat_total' => 678.90,
                ],
            ],
            'vat_registration' => 1,
            'vat_duzp' => '2026-05-06',
            'exchange_rate' => 1.0,
        ];
        $recap = $doc->buildVatRecapitulationPub($data);

        $this->assertCount(1, $recap);
        $this->assertSame(663.10, $recap[0]['base']);
        $this->assertSame(139.25, $recap[0]['tax']);
        $this->assertSame(802.35, $recap[0]['total']);
    }

    /**
     * Referenční prodejka z „Hotovo když": 2 × 55,00 s DPH 21 % v cenách
     * s daní. Dokladová úroveň dá 90,91 / 19,09 / 110,00; součet řádkových
     * rozpočtů by dal 90,90 / 19,10 (viz metoda `1 z řádků`).
     */
    public function testVatInclusiveModeTwoEqualRowsFromHeader(): void
    {
        $doc = $this->buildDoc();
        $rows = [
            [
                'row_kind' => 1, 'vat_code' => 'cz-110', 'vat_pct' => 21,
                'total_price' => 55.00,
                'vat_base' => 45.45, 'vat_amount' => 9.55, 'vat_total' => 55.00,
            ],
            [
                'row_kind' => 1, 'vat_code' => 'cz-110', 'vat_pct' => 21,
                'total_price' => 55.00,
                'vat_base' => 45.45, 'vat_amount' => 9.55, 'vat_total' => 55.00,
            ],
        ];
        $data = [
            'vat_mode' => 2,
            'rows' => $rows,
            'vat_registration' => 1,
            'vat_duzp' => '2026-05-06',
            'exchange_rate' => 1.0,
        ];
        $recap = $doc->buildVatRecapitulationPub($data);

        $this->assertCount(1, $recap);
        $this->assertSame(90.91, $recap[0]['base']);
        $this->assertSame(19.09, $recap[0]['tax']);
        $this->assertSame(110.00, $recap[0]['total']);

        // Metoda z řádků nad týmiž řádky = jiný rozpad, stejná částka.
        $dataRows = $data;
        $dataRows['vat_calc_source'] = 1;
        $recapRows = $doc->buildVatRecapitulationPub($dataRows);
        $this->assertSame(90.90, $recapRows[0]['base']);
        $this->assertSame(19.10, $recapRows[0]['tax']);
        $this->assertSame(110.00, $recapRows[0]['total']);
    }

    /**
     * Mode 1 (ceny bez DPH), jeden řádek: obě metody dají totéž — základ je
     * cena a daň z ní se počítá jednou tak i tak.
     */
    public function testVatExclusiveModeSingleRowBothMethodsAgree(): void
    {
        $doc = $this->buildDoc();
        $row = [
            'row_kind' => 1, 'vat_code' => 'cz-110', 'vat_pct' => 21,
            'total_price' => 333.33,
            'vat_base' => 333.33, 'vat_amount' => 70.0, 'vat_total' => 403.33,
        ];
        $base = [
            'vat_mode' => 1,
            'rows' => [$row],
            'vat_registration' => 1,
            'vat_duzp' => '2026-05-06',
            'exchange_rate' => 1.0,
        ];

        $fromHeader = $doc->buildVatRecapitulationPub($base);
        $fromRowsData = ['vat_calc_source' => 1] + $base;
        $fromRows = $doc->buildVatRecapitulationPub($fromRowsData);

        $this->assertSame(333.33, $fromHeader[0]['base']);
        $this->assertSame(70.0, $fromHeader[0]['tax']);
        $this->assertSame($fromHeader[0]['base'], $fromRows[0]['base']);
        $this->assertSame($fromHeader[0]['tax'], $fromRows[0]['tax']);
    }

    /**
     * Mode 1, víc řádků se zbytky: dokladová úroveň počítá daň ze součtu
     * (round(299,97 × 21 %) = 62,99), metoda z řádků sčítá tři samostatně
     * zaokrouhlené daně (3 × 21,00 = 63,00). O ten haléř se metody liší —
     * proto obě existují.
     */
    public function testVatExclusiveModeMultiRowMethodsDiffer(): void
    {
        $doc = $this->buildDoc();
        $rows = [];
        for ($i = 0; $i < 3; $i++) {
            $rows[] = [
                'row_kind' => 1, 'vat_code' => 'cz-110', 'vat_pct' => 21,
                'total_price' => 99.99,
                'vat_base' => 99.99, 'vat_amount' => 21.00, 'vat_total' => 120.99,
            ];
        }
        $base = [
            'vat_mode' => 1,
            'rows' => $rows,
            'vat_registration' => 1,
            'vat_duzp' => '2026-05-06',
            'exchange_rate' => 1.0,
        ];

        $fromHeader = $doc->buildVatRecapitulationPub($base);
        $this->assertSame(299.97, $fromHeader[0]['base']);
        $this->assertSame(62.99, $fromHeader[0]['tax']);
        $this->assertSame(362.96, $fromHeader[0]['total']);

        $fromRowsData = ['vat_calc_source' => 1] + $base;
        $fromRows = $doc->buildVatRecapitulationPub($fromRowsData);
        $this->assertSame(299.97, $fromRows[0]['base']);
        $this->assertSame(63.00, $fromRows[0]['tax']);
        $this->assertSame(362.97, $fromRows[0]['total']);
    }

    /**
     * `vat_rounding_mode` na celé jednotky se v dokladové metodě aplikuje
     * v mode 2 na **základ** (daň je pak rozdíl, aby celková částka zůstala
     * Σ cen), v mode 1 na **daň**.
     */
    public function testVatRoundingModeWholeUnitsAppliesToBaseInModeTwo(): void
    {
        $doc = $this->buildDoc();
        $data = [
            'vat_mode' => 2,
            'vat_rounding_mode' => 1,
            'rows' => [
                ['row_kind' => 1, 'vat_code' => 'cz-110', 'vat_pct' => 21, 'total_price' => 1746.00],
            ],
            'vat_registration' => 1,
            'vat_duzp' => '2026-05-06',
            'exchange_rate' => 1.0,
        ];
        $recap = $doc->buildVatRecapitulationPub($data);

        // 1746 / 1,21 = 1442,9752 → na celé Kč 1443, daň rozdílem 303
        $this->assertSame(1443.0, $recap[0]['base']);
        $this->assertSame(303.0, $recap[0]['tax']);
        $this->assertSame(1746.0, $recap[0]['total']);

        $data['vat_mode'] = 1;
        $recapFromBase = $doc->buildVatRecapitulationPub($data);
        // 1746 × 21 % = 366,66 → na celé Kč 367
        $this->assertSame(1746.0, $recapFromBase[0]['base']);
        $this->assertSame(367.0, $recapFromBase[0]['tax']);
        $this->assertSame(2113.0, $recapFromBase[0]['total']);
    }

    /**
     * noPayTax / samovyměření v mode 2 zůstává beze změny: základ je
     * autoritativní (calculateRowVat drží base = totalPrice i v mode 2),
     * informativní daň se dál počítá zdola — shodná očekávání jako
     * testReverseChargeGeneratesPair v mode 1.
     */
    public function testVatInclusiveModeKeepsNoPayTaxGroupFromBase(): void
    {
        $doc = $this->buildDoc();
        $data = [
            'vat_mode' => 2,
            'rows' => [
                [
                    'row_kind' => 1, 'vat_code' => 'cz-115', 'vat_pct' => 21,
                    'total_price' => 200,
                    'vat_base' => 200.0, 'vat_amount' => 42.0, 'vat_total' => 200.0,
                ],
            ],
            'vat_registration' => 1,
            'vat_duzp' => '2026-05-06',
            'exchange_rate' => 1.0,
        ];
        $recap = $doc->buildVatRecapitulationPub($data);

        $this->assertCount(2, $recap);
        $this->assertSame(200.0, $recap[0]['base']);
        $this->assertSame(42.0, $recap[0]['tax']);
        $this->assertSame(200.0, $recap[0]['total']);
        $this->assertSame('cz-203', $recap[1]['vat_code']);
        $this->assertSame(42.0, $recap[1]['tax']);
        $this->assertSame(242.0, $recap[1]['total']);
    }

    /**
     * Osvobozeno / 0% rows (e.g. cz-123, vat_pct=0) must appear in the recap
     * with their base and tax=0 — they must NOT be dropped just because the
     * percent is zero, or their base vanishes from the document totals.
     */
    public function testZeroPercentExemptRowIncludedInRecap(): void
    {
        $doc = $this->buildDoc();
        $data = [
            'rows' => [
                ['row_kind' => 1, 'vat_code' => 'cz-110', 'vat_pct' => 21, 'total_price' => 1000],
                ['row_kind' => 1, 'vat_code' => 'cz-123', 'vat_pct' => 0,  'total_price' => 500],
            ],
            'vat_registration' => 1,
            'vat_duzp' => '2026-05-06',
            'exchange_rate' => 1.0,
        ];
        $recap = $doc->buildVatRecapitulationPub($data);

        $this->assertCount(2, $recap);
        $byCode = array_column($recap, null, 'vat_code');

        $this->assertArrayHasKey('cz-123', $byCode);
        $this->assertSame(500.0, $byCode['cz-123']['base']);
        $this->assertSame(0.0, $byCode['cz-123']['tax']);
        $this->assertSame(500.0, $byCode['cz-123']['total']);

        $this->assertSame(1000.0, $byCode['cz-110']['base']);
        $this->assertSame(210.0, $byCode['cz-110']['tax']);
    }

    public function testNullVatCodeRowSkipped(): void
    {
        $doc = $this->buildDoc();
        $data = [
            'rows' => [
                ['row_kind' => 1, 'vat_code' => null, 'vat_pct' => null, 'total_price' => 100],
                ['row_kind' => 1, 'vat_code' => 'cz-110', 'vat_pct' => 21, 'total_price' => 200],
            ],
            'vat_registration' => 1,
            'vat_duzp' => '2026-05-06',
        ];
        $recap = $doc->buildVatRecapitulationPub($data);

        $this->assertCount(1, $recap);
        $this->assertSame('cz-110', $recap[0]['vat_code']);
        $this->assertSame(200.0, $recap[0]['base']);
    }

    public function testTextRowSkipped(): void
    {
        $doc = $this->buildDoc();
        $data = [
            'rows' => [
                ['row_kind' => 0, 'vat_code' => 'cz-110', 'vat_pct' => 21, 'total_price' => 100],
                ['row_kind' => 1, 'vat_code' => 'cz-110', 'vat_pct' => 21, 'total_price' => 200],
            ],
            'vat_registration' => 1,
            'vat_duzp' => '2026-05-06',
        ];
        $recap = $doc->buildVatRecapitulationPub($data);

        $this->assertCount(1, $recap);
        $this->assertSame(200.0, $recap[0]['base']);
    }

    public function testExchangeRateAppliedToDom(): void
    {
        $doc = $this->buildDoc();
        $data = [
            'rows' => [
                ['row_kind' => 1, 'vat_code' => 'cz-110', 'vat_pct' => 21, 'total_price' => 100],
            ],
            'vat_registration' => 1,
            'vat_duzp' => '2026-05-06',
            'exchange_rate' => 25.0,
        ];
        $recap = $doc->buildVatRecapitulationPub($data);

        $this->assertSame(2500.0, $recap[0]['base_dom']);
        $this->assertSame(525.0, $recap[0]['tax_dom']);
        $this->assertSame(3025.0, $recap[0]['total_dom']);
    }

    /**
     * Samovyměření v cizí měně (#103 D80): daň v domácí měně ze základu
     * v domácí měně — 432,70 EUR × 24,325 = 10 525,43 Kč, 21 % = 2 210,34 Kč.
     * Přepočet daně v EUR (90,87 × 24,325 = 2 210,41) by dal o 7 haléřů víc.
     */
    public function testSelfAssessedForeignCurrencyTaxDomFromBaseDom(): void
    {
        $doc = $this->buildDoc();
        $data = [
            'rows' => [
                ['row_kind' => 1, 'vat_code' => 'cz-115', 'vat_pct' => 21, 'total_price' => 432.70],
            ],
            'vat_registration' => 1,
            'vat_duzp' => '2026-08-31',
            'exchange_rate' => 24.325,
        ];
        $recap = $doc->buildVatRecapitulationPub($data);

        $this->assertCount(2, $recap);
        $this->assertSame(432.70, $recap[0]['base']);
        $this->assertSame(90.87, $recap[0]['tax']);
        $this->assertSame(10525.43, $recap[0]['base_dom']);
        $this->assertSame(2210.34, $recap[0]['tax_dom']);
        $this->assertSame(10525.43, $recap[0]['total_dom'], 'noPayTax: celkem v domácí měně jen základ');
        $this->assertSame(10525.43, $recap[1]['base_dom']);
        $this->assertSame(2210.34, $recap[1]['tax_dom']);
        $this->assertSame(12735.77, $recap[1]['total_dom']);
    }

    /**
     * Přesná půlka haléře (#103 nález I): 45 EUR × 24,345 = 1 095,525 → 1 095,53;
     * float by dal 1 095,52 (součin je binárně 1 095,5249…). Daň 9,45 × 24,345
     * = 230,06025 → 230,06.
     */
    public function testHalfHalerConversionRoundsUpInDecimalArithmetic(): void
    {
        $doc = $this->buildDoc();
        $data = [
            'rows' => [
                ['row_kind' => 1, 'vat_code' => 'cz-110', 'vat_pct' => 21, 'total_price' => 45],
            ],
            'vat_registration' => 1,
            'vat_duzp' => '2026-09-22',
            'exchange_rate' => 24.345,
        ];
        $recap = $doc->buildVatRecapitulationPub($data);
        $this->assertSame(1095.53, $recap[0]['base_dom']);
        $this->assertSame(230.06, $recap[0]['tax_dom']);
        $this->assertSame(1325.59, $recap[0]['total_dom'], '54,45 × 24,345 = 1 325,58525 → 1 325,59');

        // Samovyměření: daň ze základu v domácí měně také desítkově — 100,50 × 24,345 = 2 446,6725 → 2 446,67;
        // 2 446,67 × 21 % = 513,8007 → 513,80.
        $data['rows'] = [['row_kind' => 1, 'vat_code' => 'cz-115', 'vat_pct' => 21, 'total_price' => 100.50]];
        $recap = $doc->buildVatRecapitulationPub($data);
        $this->assertSame(2446.67, $recap[0]['base_dom']);
        $this->assertSame(513.80, $recap[0]['tax_dom']);
    }

    /** Při kurzu 1 zůstává daň v domácí měně kopií daně dokladu (D80 jen pro cizí měnu). */
    public function testSelfAssessedHomeCurrencyKeepsTaxDomEqualToTax(): void
    {
        $doc = $this->buildDoc();
        $data = [
            'rows' => [
                ['row_kind' => 1, 'vat_code' => 'cz-115', 'vat_pct' => 21, 'total_price' => 200],
            ],
            'vat_registration' => 1,
            'vat_duzp' => '2026-05-06',
            'exchange_rate' => 1.0,
        ];
        $recap = $doc->buildVatRecapitulationPub($data);
        $this->assertSame(42.0, $recap[0]['tax_dom']);
        $this->assertSame(42.0, $recap[1]['tax_dom']);
        $this->assertSame(242.0, $recap[1]['total_dom']);
    }

    public function testUnknownVatCodeThrows(): void
    {
        $doc = $this->buildDoc();
        $data = [
            'rows' => [
                ['row_kind' => 1, 'vat_code' => 'cz-XYZ', 'vat_pct' => 21, 'total_price' => 100],
            ],
            'vat_registration' => 1,
            'vat_duzp' => '2026-05-06',
        ];

        // Neznámý kód je datová chyba — nesmí tiše vypadnout ze součtů.
        $this->expectException(\DomainException::class);
        $doc->buildVatRecapitulationPub($data);
    }

    public function testDifferentCodesProduceMultipleRows(): void
    {
        $doc = $this->buildDoc();
        $data = [
            'rows' => [
                ['row_kind' => 1, 'vat_code' => 'cz-110', 'vat_pct' => 21, 'total_price' => 100],
                ['row_kind' => 1, 'vat_code' => 'cz-111', 'vat_pct' => 12, 'total_price' => 100],
            ],
            'vat_registration' => 1,
            'vat_duzp' => '2026-05-06',
        ];
        $recap = $doc->buildVatRecapitulationPub($data);

        $this->assertCount(2, $recap);
        $codes = array_column($recap, 'vat_code');
        $this->assertContains('cz-110', $codes);
        $this->assertContains('cz-111', $codes);
    }
}

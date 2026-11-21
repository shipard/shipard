<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\World\Vat;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\World\Vat\VatRateResolver;

class VatRateResolverTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/shpd_vat_test_' . uniqid();
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
     * Build a ConfigRuntime backed by a freshly compiled production-like
     * cfgItem. Reads the actual world.vat.cz JSONC so tests run against
     * the real migration data, not a fixture.
     */
    private function realResolver(): VatRateResolver
    {
        $jsoncPath = __DIR__ . '/../../../../../modules/world/vat/config/vat-cz.jsonc';
        $data = JsoncParser::parseFile($jsoncPath);

        $compiled = [
            '_meta' => ['language' => 'cs'],
            'items' => ['world.vat.cz' => $data],
        ];
        file_put_contents(
            $this->tmpDir . '/config/configuration/compiled.cs.json',
            json_encode($compiled),
        );

        $config = ConfigRuntime::load($this->tmpDir, 'cs');
        return new VatRateResolver($config);
    }

    /** Build a resolver from an in-line cfgItem (for fixture-based tests). */
    private function resolverFromArray(array $cfg): VatRateResolver
    {
        $compiled = [
            '_meta' => ['language' => 'cs'],
            'items' => ['world.vat.cz' => $cfg],
        ];
        file_put_contents(
            $this->tmpDir . '/config/configuration/compiled.cs.json',
            json_encode($compiled),
        );

        $config = ConfigRuntime::load($this->tmpDir, 'cs');
        return new VatRateResolver($config);
    }

    // --- resolveVatPct ------------------------------------------------------

    public function testResolveVatPctValidCodeReturnsPercent(): void
    {
        $r = $this->realResolver();
        $this->assertSame(21.0, $r->resolveVatPct('cz', 'cz-110', '2024-06-01'));
    }

    public function testResolveVatPctAcrossYear2013Boundary(): void
    {
        // cz-110: 20 % do 2012-12-31, 21 % od 2013-01-01.
        $r = $this->realResolver();
        $this->assertSame(20.0, $r->resolveVatPct('cz', 'cz-110', '2012-12-31'));
        $this->assertSame(21.0, $r->resolveVatPct('cz', 'cz-110', '2013-01-01'));
    }

    public function testResolveVatPctAcrossYear2024ReducedReform(): void
    {
        // cz-111: 15 % do 2014, gap, 12 % od 2024.
        $r = $this->realResolver();
        $this->assertSame(12.0, $r->resolveVatPct('cz', 'cz-111', '2024-06-01'));
        $this->assertSame(15.0, $r->resolveVatPct('cz', 'cz-111', '2014-06-01'));
    }

    public function testResolveVatPctUnknownCodeThrows(): void
    {
        $r = $this->realResolver();
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("Unknown VAT code 'cz-999'");
        $r->resolveVatPct('cz', 'cz-999', '2024-06-01');
    }

    public function testResolveVatPctMissingPercentThrows(): void
    {
        // Kód existuje ve vatCodes, ale nemá žádný záznam ve vatPercents —
        // resolveVatPct musí selhat. (V reálném vat-cz.jsonc už žádný takový
        // kód není — hlídá VatReverseCodeRatesTest — proto syntetický config.)
        $r = $this->resolverFromArray([
            'vatCodes'    => ['cz-777' => ['category' => 'standard']],
            'vatPercents' => [],
        ]);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("No VAT percentage defined");
        $r->resolveVatPct('cz', 'cz-777', '2024-06-01');
    }

    public function testResolveVatPctOutsideValidityRangeThrows(): void
    {
        // cz-110 starts at 2010-01-01 — earlier dates should miss.
        $r = $this->realResolver();
        $this->expectException(\LogicException::class);
        $r->resolveVatPct('cz', 'cz-110', '2009-12-31');
    }

    public function testResolveVatPctUnknownCountryThrows(): void
    {
        $r = $this->realResolver();
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("VAT configuration for country 'sk' not found");
        $r->resolveVatPct('sk', 'sk-110', '2024-06-01');
    }

    // --- getVatCodes --------------------------------------------------------

    public function testGetVatCodesFiltersByDirectionAndPlace(): void
    {
        $r = $this->realResolver();
        $codes = $r->getVatCodes('cz', direction: 'output', place: 'domestic');
        $keys = array_keys($codes);

        // Expected visible codes for output+domestic:
        // cz-120, cz-121, cz-310, cz-311, cz-122, cz-123, cz-150, cz-151,
        // cz-350. Hidden cz-203 / cz-204 / cz-370 must be excluded.
        $this->assertContains('cz-120', $keys);
        $this->assertContains('cz-150', $keys);
        $this->assertNotContains('cz-110', $keys); // input
        $this->assertNotContains('cz-201', $keys); // intracom
        $this->assertNotContains('cz-203', $keys); // hidden
        // Kódy přenesení DP5 zanikly (#89 D8) — předmět plnění je údaj řádku.
        $this->assertNotContains('cz-152', $keys);
        $this->assertNull($r->getVatCode('cz', 'cz-117'));
    }

    public function testGetVatCodesSkipsHiddenByDefault(): void
    {
        $r = $this->realResolver();
        $codes = $r->getVatCodes('cz', direction: 'output', place: 'domestic');
        $this->assertArrayNotHasKey('cz-203', $codes);
        $this->assertArrayNotHasKey('cz-204', $codes);
        $this->assertArrayNotHasKey('cz-370', $codes);
    }

    public function testGetVatCodesIncludesHiddenWhenAsked(): void
    {
        $r = $this->realResolver();
        $codes = $r->getVatCodes(
            'cz',
            direction: 'output',
            place: 'domestic',
            includeHidden: true,
        );
        $this->assertArrayHasKey('cz-203', $codes);
        $this->assertArrayHasKey('cz-204', $codes);
        $this->assertArrayHasKey('cz-370', $codes);
    }

    public function testGetVatCodesUnfilteredReturnsAll(): void
    {
        $r = $this->realResolver();
        $codes = $r->getVatCodes('cz', includeHidden: true);
        $this->assertCount(67, $codes);
    }

    // --- kód „Mimo DPH“ (#164 D1, U2–U4) -------------------------------------

    public function testOutOfScopeCodeIsOfferedForEveryDirectionAndPlaceAsLast(): void
    {
        $r = $this->realResolver();

        foreach ([null, 'input', 'output'] as $direction) {
            foreach ([null, 'domestic', 'intracom', 'foreign'] as $place) {
                $keys = array_keys($r->getVatCodes('cz', direction: $direction, place: $place));
                $label = ($direction ?? 'null') . ' / ' . ($place ?? 'null');

                $this->assertSame('cz-000', end($keys), "{$label}: „Mimo DPH“ je poslední");
                $this->assertNotSame('cz-000', $keys[0], "{$label}: první kód nabídky není „Mimo DPH“");
                $this->assertSame(1, count(array_keys($keys, 'cz-000', true)), $label);
            }
        }
    }

    public function testOutOfScopeCodeIsLastRegardlessOfConfigOrder(): void
    {
        $r = $this->resolverFromArray([
            'vatCategories' => ['standard' => ['name' => 'X'], 'outOfScope' => ['name' => 'Y']],
            'vatCodes' => [
                'cz-000' => ['category' => 'outOfScope'],
                'cz-110' => ['category' => 'standard', 'direction' => 'input', 'place' => 'domestic'],
                'cz-217' => ['category' => 'standard', 'direction' => 'input', 'place' => 'intracom'],
            ],
            'vatPercents' => [],
            'vatNotes' => [],
        ]);

        $this->assertSame(['cz-110', 'cz-217', 'cz-000'], array_keys($r->getVatCodes('cz')));
        $this->assertSame(['cz-217', 'cz-000'], array_keys($r->getVatCodes('cz', 'input', 'intracom')));
        $this->assertSame(['cz-000'], array_keys($r->getVatCodes('cz', 'output', 'foreign')));
    }

    public function testIsOutOfScopeCodeFollowsCategory(): void
    {
        $r = $this->realResolver();

        $this->assertTrue($r->isOutOfScopeCode('cz', 'cz-000'));
        // Osvobozené a nulové kódy v rekapitulaci jsou — kategorie exempt.
        foreach (['cz-110', 'cz-123', 'cz-124', 'cz-214', 'cz-414', 'cz-201', 'cz-999'] as $code) {
            $this->assertFalse($r->isOutOfScopeCode('cz', $code), $code);
        }
        $this->assertFalse($r->isOutOfScopeCode('cz', null));
        $this->assertFalse($r->isOutOfScopeCode('cz', ''));
    }

    public function testOutOfScopeCodeHasZeroRateWithoutValidityLimit(): void
    {
        $r = $this->realResolver();

        foreach (['1999-01-01', '2026-11-21', '2099-12-31'] as $date) {
            $this->assertSame(0.0, $r->resolveVatPct('cz', 'cz-000', $date), $date);
        }
    }

    /**
     * Nové kódy výkazů a vstupní „Osvobozeno“ mimo tuzemsko (#164 D4, D8):
     * kategorie exempt = v rekapitulaci s nulovou daní, sazba 0 bez omezení.
     * `cz-114` přibude se zrušením `cz-112` — do té doby by 0 % v tuzemsku
     * mělo dva kandidáty derivace.
     */
    public function testNewExemptCodesCarryPlaceDirectionAndZeroRate(): void
    {
        $r = $this->realResolver();
        $expected = [
            'cz-124' => ['domestic', 'output', 26],
            'cz-209' => ['intracom', 'output', 26],
            'cz-402' => ['foreign', 'output', 26],
            'cz-220' => ['intracom', 'input', 30],
            'cz-221' => ['intracom', 'output', 31],
            'cz-214' => ['intracom', 'input', 0],
            'cz-414' => ['foreign', 'input', 0],
        ];

        foreach ($expected as $code => [$place, $direction, $row]) {
            $def = $r->getVatCode('cz', $code);
            $this->assertNotNull($def, $code);
            $this->assertSame('exempt', $def['category'], $code);
            $this->assertSame($place, $def['place'], $code);
            $this->assertSame($direction, $def['direction'], $code);
            $this->assertSame($row, $def['vatReturnRow'], $code);
            $this->assertArrayNotHasKey('reverseVatCode', $def, "{$code}: neodvozuje se");
            $this->assertSame(0.0, $r->resolveVatPct('cz', $code, '2026-11-21'), $code);
        }
        $this->assertSame(2, $r->getVatCode('cz', 'cz-221')['intracomCode']);
        $this->assertArrayNotHasKey('intracomCode', $r->getVatCode('cz', 'cz-209'));
    }

    // --- getVatCode ---------------------------------------------------------

    public function testGetVatCodeReturnsDetail(): void
    {
        $r = $this->realResolver();
        $code = $r->getVatCode('cz', 'cz-115');
        $this->assertNotNull($code);
        $this->assertSame('standard', $code['category']);
        $this->assertSame('cz-203', $code['reverseVatCode']);
        // Kód přenesení nese jen příznak — předmět plnění je údaj řádku (#89 D8).
        $this->assertSame(1, $code['reverseCharge']);
        $this->assertArrayNotHasKey('reverseChargeCode', $code);
    }

    public function testGetVatCodeMissingReturnsNull(): void
    {
        $r = $this->realResolver();
        $this->assertNull($r->getVatCode('cz', 'cz-999'));
    }

    // --- getVatCategories / getVatNotes -------------------------------------

    public function testGetVatCategoriesReturnsKnownKeys(): void
    {
        $r = $this->realResolver();
        $cats = $r->getVatCategories('cz');
        $this->assertSame(
            ['standard', 'reduced', 'reduced1', 'reduced2', 'zero', 'exempt', 'outOfScope'],
            array_keys($cats),
        );
    }

    public function testGetVatNotesReturnsKnownNotes(): void
    {
        $r = $this->realResolver();
        $notes = $r->getVatNotes('cz');
        $this->assertArrayHasKey('pdp', $notes);
        $this->assertArrayNotHasKey('pdp4', $notes);
        $this->assertArrayNotHasKey('pdp5', $notes);
        $this->assertArrayHasKey('eu', $notes);
    }

    // --- reverseChargeSubjects (#89 D6) -------------------------------------

    public function testReverseChargeSubjectsListKeepsStringCodesInConfigOrder(): void
    {
        $subjects = $this->realResolver()->getReverseChargeSubjects('cz');
        $codes = array_column($subjects, 'code');

        $this->assertSame(
            ['1', '1a', '3', '3a', '4', '4a', '5', '6', '7', '11', '12', '13',
                '14', '15', '16', '17', '18', '19', '20', '21'],
            $codes,
        );
        $byCode = array_column($subjects, null, 'code');
        $this->assertSame('§ 92e odst. 2', $byCode['4a']['legal']);
        $this->assertSame('2017-07-01', $byCode['4a']['validFrom']);
        $this->assertNull($byCode['4']['validFrom']);
        $this->assertNull($byCode['14']['validTo']);
    }

    public function testReverseChargeSubjectValidityFollowsValidFrom(): void
    {
        $r = $this->realResolver();

        $this->assertFalse($r->isReverseChargeSubjectValid('cz', '4a', '2017-06-30'));
        $this->assertTrue($r->isReverseChargeSubjectValid('cz', '4a', '2017-07-01'));
        $this->assertTrue($r->isReverseChargeSubjectValid('cz', '4', '2016-01-01'));
        // Bez data se ověřuje jen existence.
        $this->assertTrue($r->isReverseChargeSubjectValid('cz', '4a', null));

        $before = array_column($r->getReverseChargeSubjectsValidAt('cz', '2017-06-30'), 'code');
        $after  = array_column($r->getReverseChargeSubjectsValidAt('cz', '2017-07-01'), 'code');
        $this->assertNotContains('4a', $before);
        $this->assertContains('4', $before);
        $this->assertContains('4a', $after);
    }

    public function testReverseChargeSubjectUnknownOrEmptyIsInvalid(): void
    {
        $r = $this->realResolver();

        $this->assertFalse($r->isReverseChargeSubjectValid('cz', '99', '2026-01-01'));
        $this->assertFalse($r->isReverseChargeSubjectValid('cz', '', '2026-01-01'));
        $this->assertFalse($r->isReverseChargeSubjectValid('cz', null, '2026-01-01'));
    }

    public function testReverseChargeSubjectValidityRespectsValidTo(): void
    {
        $r = $this->resolverFromArray([
            'vatCategories' => [],
            'vatCodes' => [],
            'vatPercents' => [],
            'vatNotes' => [],
            'reverseChargeSubjects' => [
                '11' => ['name' => 'Allowances', 'validTo' => '2026-12-31'],
            ],
        ]);

        $this->assertTrue($r->isReverseChargeSubjectValid('cz', '11', '2026-12-31'));
        $this->assertFalse($r->isReverseChargeSubjectValid('cz', '11', '2027-01-01'));
        $this->assertSame([], $r->getReverseChargeSubjectsValidAt('cz', '2027-01-01'));
    }

    public function testIsReverseChargeCodeFollowsFlagNotReverseVatCode(): void
    {
        $r = $this->realResolver();

        $this->assertTrue($r->isReverseChargeCode('cz', 'cz-115'));
        $this->assertTrue($r->isReverseChargeCode('cz', 'cz-150'));
        // Oddaňovací pár a pořízení z EU mají reverseVatCode, ne příznak.
        $this->assertFalse($r->isReverseChargeCode('cz', 'cz-203'));
        $this->assertFalse($r->isReverseChargeCode('cz', 'cz-205'));
        $this->assertFalse($r->isReverseChargeCode('cz', 'cz-110'));
        $this->assertFalse($r->isReverseChargeCode('cz', 'cz-999'));
        $this->assertFalse($r->isReverseChargeCode('cz', null));
    }

    public function testValidateCountryConfigDetectsBrokenReverseChargeSubject(): void
    {
        $r = $this->resolverFromArray([
            'vatCategories' => [],
            'vatCodes' => [],
            'vatPercents' => [],
            'vatNotes' => [],
            'reverseChargeSubjects' => [
                '4'  => ['name' => 'OK'],
                '4a' => ['name' => 'Bad date', 'validFrom' => '2017-7-1'],
                '5'  => ['name' => ''],
                '6'  => ['name' => 'Swapped', 'validFrom' => '2020-01-01', 'validTo' => '2019-01-01'],
            ],
        ]);

        $errors = $r->validateCountryConfig('cz');
        $this->assertCount(3, $errors);
        $this->assertStringContainsString("['4a']: invalid validFrom '2017-7-1'", $errors[0]);
        $this->assertStringContainsString("['5']: missing name", $errors[1]);
        $this->assertStringContainsString("['6']: validFrom is after validTo", $errors[2]);
    }

    // --- validateCountryConfig ----------------------------------------------

    public function testValidateCountryConfigOnProductionDataIsClean(): void
    {
        $r = $this->realResolver();
        $this->assertSame([], $r->validateCountryConfig('cz'));
    }

    public function testValidateCountryConfigDetectsUnknownPercentCode(): void
    {
        $r = $this->resolverFromArray([
            'vatCategories' => ['standard' => ['name' => 'X']],
            'vatCodes' => [
                'cz-110' => ['category' => 'standard', 'direction' => 'input', 'place' => 'domestic'],
            ],
            'vatPercents' => [
                ['code' => 'cz-110', 'from' => '0000-00-00', 'to' => '0000-00-00', 'value' => 21.0],
                ['code' => 'cz-999', 'from' => '0000-00-00', 'to' => '0000-00-00', 'value' => 21.0],
            ],
            'vatNotes' => [],
        ]);
        $errors = $r->validateCountryConfig('cz');
        $this->assertCount(1, $errors);
        $this->assertStringContainsString("unknown code 'cz-999'", $errors[0]);
    }

    public function testValidateCountryConfigDetectsUnknownReverseVatCode(): void
    {
        $r = $this->resolverFromArray([
            'vatCategories' => ['standard' => ['name' => 'X']],
            'vatCodes' => [
                'cz-115' => [
                    'category' => 'standard',
                    'direction' => 'input',
                    'place' => 'domestic',
                    'reverseVatCode' => 'cz-missing',
                ],
            ],
            'vatPercents' => [],
            'vatNotes' => [],
        ]);
        $errors = $r->validateCountryConfig('cz');
        $this->assertCount(1, $errors);
        $this->assertStringContainsString("reverseVatCode 'cz-missing'", $errors[0]);
    }

    public function testValidateCountryConfigDetectsUnknownCategory(): void
    {
        $r = $this->resolverFromArray([
            'vatCategories' => ['standard' => ['name' => 'X']],
            'vatCodes' => [
                'cz-110' => ['category' => 'wrong', 'direction' => 'input', 'place' => 'domestic'],
            ],
            'vatPercents' => [],
            'vatNotes' => [],
        ]);
        $errors = $r->validateCountryConfig('cz');
        $this->assertCount(1, $errors);
        $this->assertStringContainsString("unknown category 'wrong'", $errors[0]);
    }

    public function testValidateCountryConfigDetectsUnknownNote(): void
    {
        $r = $this->resolverFromArray([
            'vatCategories' => ['standard' => ['name' => 'X']],
            'vatCodes' => [
                'cz-110' => [
                    'category' => 'standard',
                    'direction' => 'input',
                    'place' => 'domestic',
                    'note' => 'unknown_note',
                ],
            ],
            'vatPercents' => [],
            'vatNotes' => ['pdp' => ['text' => 'X']],
        ]);
        $errors = $r->validateCountryConfig('cz');
        $this->assertCount(1, $errors);
        $this->assertStringContainsString("unknown note 'unknown_note'", $errors[0]);
    }

    public function testValidateCountryConfigRejectsPlaceOrReportOnOutOfScopeCode(): void
    {
        // #164 U2: kód „Mimo DPH“ je univerzální — místo, směr ani výkaz nemá.
        $r = $this->resolverFromArray([
            'vatCategories' => ['standard' => ['name' => 'X'], 'outOfScope' => ['name' => 'Y']],
            'vatCodes' => [
                'cz-110' => ['category' => 'standard', 'direction' => 'input', 'place' => 'domestic'],
                'cz-000' => [
                    'category' => 'outOfScope',
                    'place' => 'domestic',
                    'direction' => 'input',
                    'vatReturnRow' => 0,
                    'reverseVatCode' => 'cz-110',
                    'supplyKind' => 'goods',
                ],
            ],
            'vatPercents' => [],
            'vatNotes' => [],
        ]);

        $errors = $r->validateCountryConfig('cz');
        $this->assertCount(5, $errors);
        foreach (['place', 'direction', 'vatReturnRow', 'reverseVatCode', 'supplyKind'] as $i => $property) {
            $this->assertStringContainsString(
                "vatCodes['cz-000']: out-of-scope code must not have '{$property}'",
                $errors[$i],
            );
        }
    }

    public function testValidateCountryConfigRequiresPlaceAndDirectionOnOtherCodes(): void
    {
        // Chybějící místo by filtr getVatCodes() tiše bral jako tuzemsko.
        $r = $this->resolverFromArray([
            'vatCategories' => ['standard' => ['name' => 'X'], 'outOfScope' => ['name' => 'Y']],
            'vatCodes' => [
                'cz-110' => ['category' => 'standard', 'direction' => 'input'],
                'cz-120' => ['category' => 'standard', 'place' => 'domestic'],
                'cz-000' => ['category' => 'outOfScope'],
            ],
            'vatPercents' => [],
            'vatNotes' => [],
        ]);

        $this->assertSame(
            ["vatCodes['cz-110']: missing place", "vatCodes['cz-120']: missing direction"],
            $r->validateCountryConfig('cz'),
        );
    }

    public function testValidateCountryConfigDetectsUnknownSupplyKind(): void
    {
        // tasks/exchange-received-reverse-charge.md: supplyKind řídí derivaci
        // kódu mimo tuzemsko — překlep by kód tiše vyřadil z kandidátů.
        $r = $this->resolverFromArray([
            'vatCategories' => ['standard' => ['name' => 'X']],
            'vatCodes' => [
                'cz-217' => [
                    'category' => 'standard',
                    'direction' => 'input',
                    'place' => 'intracom',
                    'supplyKind' => 'stuff',
                ],
            ],
            'vatPercents' => [],
            'vatNotes' => [],
        ]);
        $errors = $r->validateCountryConfig('cz');
        $this->assertCount(1, $errors);
        $this->assertStringContainsString("unknown supplyKind 'stuff'", $errors[0]);
    }
}

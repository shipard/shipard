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
        // cz-350, cz-152. Hidden cz-203 / cz-204 / cz-370 must be excluded.
        $this->assertContains('cz-120', $keys);
        $this->assertContains('cz-150', $keys);
        $this->assertNotContains('cz-110', $keys); // input
        $this->assertNotContains('cz-201', $keys); // intracom
        $this->assertNotContains('cz-203', $keys); // hidden
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
        $this->assertCount(61, $codes);
    }

    // --- getVatCode ---------------------------------------------------------

    public function testGetVatCodeReturnsDetail(): void
    {
        $r = $this->realResolver();
        $code = $r->getVatCode('cz', 'cz-115');
        $this->assertNotNull($code);
        $this->assertSame('standard', $code['category']);
        $this->assertSame('cz-203', $code['reverseVatCode']);
        $this->assertSame(4, $code['reverseChargeCode']);
    }

    public function testGetVatCodeMissingReturnsNull(): void
    {
        $r = $this->realResolver();
        $this->assertNull($r->getVatCode('cz', 'cz-999'));
    }

    // --- getVatCategories / getVatNotes -------------------------------------

    public function testGetVatCategoriesReturnsSixKnownKeys(): void
    {
        $r = $this->realResolver();
        $cats = $r->getVatCategories('cz');
        $this->assertSame(
            ['standard', 'reduced', 'reduced1', 'reduced2', 'zero', 'exempt'],
            array_keys($cats),
        );
    }

    public function testGetVatNotesReturnsKnownNotes(): void
    {
        $r = $this->realResolver();
        $notes = $r->getVatNotes('cz');
        $this->assertArrayHasKey('pdp4', $notes);
        $this->assertArrayHasKey('pdp5', $notes);
        $this->assertArrayHasKey('eu', $notes);
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
                'cz-110' => ['category' => 'standard', 'direction' => 'input'],
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
                'cz-110' => ['category' => 'wrong', 'direction' => 'input'],
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
                    'note' => 'unknown_note',
                ],
            ],
            'vatPercents' => [],
            'vatNotes' => ['pdp4' => ['text' => 'X']],
        ]);
        $errors = $r->validateCountryConfig('cz');
        $this->assertCount(1, $errors);
        $this->assertStringContainsString("unknown note 'unknown_note'", $errors[0]);
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

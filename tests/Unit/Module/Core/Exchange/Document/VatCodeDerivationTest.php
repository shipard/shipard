<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Document;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Core\Exchange\Document\VatCodeDerivation;
use Shipard\Module\World\Vat\VatRateResolver;

/**
 * Derivace kódu DPH nad **skutečným** `vat-cz.jsonc` (vzor
 * VatReverseCodeRatesTest) — testy hlídají i číselník: kdyby někdo přidal
 * další 21 % vstupní kód bez `reducedDeduction` / `reverseVatCode`,
 * tuzemská derivace přestane být jednoznačná a test to ukáže.
 * tasks/exchange-received-reverse-charge.md D1/D4; dovoz zboží (D3)
 * a veto štítku `special` (D4) z tasks/exchange-received-supply-kind.md.
 */
class VatCodeDerivationTest extends TestCase
{
    private const VAT_CZ = '/modules/world/vat/config/vat-cz.jsonc';
    private const DATE = '2026-04-15';
    private const REDUCED_DEDUCTION = ['cz-118', 'cz-119', 'cz-341', 'cz-342'];

    private static ?array $vatCz = null;

    private function derivation(): VatCodeDerivation
    {
        self::$vatCz ??= JsoncParser::parseFile(dirname(__DIR__, 6) . self::VAT_CZ);
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn (string $id): mixed => $id === 'world.vat.cz' ? self::$vatCz : null,
        );
        return new VatCodeDerivation(new VatRateResolver($config));
    }

    /**
     * @return array<string, array{0: ?string, 1: ?bool, 2: ?float, 3: ?string, 4: string, 5: string, 6?: ?string}>
     */
    public static function derivedCodes(): array
    {
        // place, reverseCharge, pct, supplyKind, expected, date, [tagSupply]
        return [
            'EU služby'                        => ['intracom', true, 0.0, 'services', 'cz-217', self::DATE],
            'EU služby bez příznaku'           => ['intracom', null, 0.0, 'services', 'cz-217', self::DATE],
            'EU služby bez sazby'              => ['intracom', true, null, 'services', 'cz-217', self::DATE],
            'EU zboží'                         => ['intracom', true, 0.0, 'goods', 'cz-215', self::DATE],
            'třetí země služby'                => ['thirdCountry', true, 0.0, 'services', 'cz-417', self::DATE],
            'třetí země služby bez příznaku'   => ['thirdCountry', null, 0.0, 'services', 'cz-417', self::DATE],
            // Štítek bez veta derivaci nemění — druh z AI má přednost (D2).
            'EU zboží se štítkem goods'        => ['intracom', true, 0.0, 'goods', 'cz-215', self::DATE, 'goods'],
            'EU služby se štítkem goods'       => ['intracom', true, 0.0, 'services', 'cz-217', self::DATE, 'goods'],
            // Veto `special` platí jen mimo tuzemsko (D4).
            'tuzemsko 21 se štítkem special'   => ['domestic', null, 21.0, null, 'cz-110', self::DATE, 'special'],
            // Tuzemské přenesení (#89 D11): vždy obecný kód přenesení základní
            // sazby — předmět plnění kód nevybírá, je to samostatný údaj řádku.
            'PDP se štítkem special'           => ['domestic', true, 0.0, null, 'cz-115', self::DATE, 'special'],
            'PDP tuzemsko'                     => ['domestic', true, 0.0, null, 'cz-115', self::DATE],
            'PDP s vyplněným supplyKind'       => ['domestic', true, 0.0, 'services', 'cz-115', self::DATE],
            'PDP bez sazby na řádku'           => ['domestic', true, null, null, 'cz-115', self::DATE],
            'tuzemsko 21'                      => ['domestic', false, 21.0, null, 'cz-110', self::DATE],
            'tuzemsko 21 bez příznaku'         => ['domestic', null, 21.0, null, 'cz-110', self::DATE],
            'tuzemsko bez místa (null) 21'     => [null, null, 21.0, null, 'cz-110', self::DATE],
            'tuzemsko 12 (2026)'               => ['domestic', null, 12.0, null, 'cz-111', self::DATE],
            'tuzemsko 0'                       => ['domestic', null, 0.0, null, 'cz-112', self::DATE],
            'tuzemsko 15 (2020, první snížená)' => ['domestic', null, 15.0, null, 'cz-301', '2020-06-01'],
            'tuzemsko 10 (2020, druhá snížená)' => ['domestic', null, 10.0, null, 'cz-302', '2020-06-01'],
        ];
    }

    #[DataProvider('derivedCodes')]
    public function testDerivesUniqueCode(
        ?string $place,
        ?bool $reverseCharge,
        ?float $pct,
        ?string $supplyKind,
        string $expected,
        string $date,
        ?string $tagSupply = null,
    ): void {
        $result = $this->derivation()->derive('cz', $date, $place, $reverseCharge, $pct, $supplyKind, $tagSupply);
        $this->assertSame(['code' => $expected, 'reason' => null, 'kind' => null], $result);
    }

    public function testCountryIsCaseInsensitive(): void
    {
        $result = $this->derivation()->derive('CZ', self::DATE, 'intracom', true, 0.0, 'services');
        $this->assertSame('cz-217', $result['code']);
    }

    /**
     * @return array<string, array{0: ?string, 1: ?bool, 2: ?float, 3: ?string, 4: string, 5?: ?string}>
     */
    public static function underivable(): array
    {
        // place, reverseCharge, pct, supplyKind, očekávaný fragment důvodu, [tagSupply]
        return [
            'EU bez druhu plnění'              => ['intracom', true, 0.0, null, 'druh plnění'],
            'třetí země bez druhu plnění'      => ['thirdCountry', null, 0.0, null, 'druh plnění'],
            // D3: dovoz zboží — DPH z celního dokladu, cz-415 jen ručně.
            'třetí země zboží (dovoz)'         => ['thirdCountry', true, 0.0, 'goods', 'celního dokladu'],
            'třetí země zboží bez příznaku'    => ['thirdCountry', null, 0.0, 'goods', 'celního dokladu'],
            // D4: veto štítku před druhem z AI i před reverseCharge.
            'EU služby, štítek special'        => ['intracom', true, 0.0, 'services', 'zvláštním pravidlem', 'special'],
            'EU bez druhu, štítek special'     => ['intracom', true, 0.0, null, 'zvláštním pravidlem', 'special'],
            'třetí země služby, štítek special' => ['thirdCountry', null, 0.0, 'services', 'zvláštním pravidlem', 'special'],
            'třetí země, special, RC true'     => ['thirdCountry', true, 0.0, 'services', 'zvláštním pravidlem', 'special'],
            'neznámé místo plnění'             => ['eu', true, 0.0, 'services', 'místo plnění „eu“'],
            'tuzemsko sazba 19 bez kódu'       => ['domestic', null, 19.0, null, 'žádný kód'],
            'tuzemsko 15 v roce 2026'          => ['domestic', null, 15.0, null, 'žádný kód'],
            'tuzemsko bez sazby'               => ['domestic', null, null, null, 'sazbu'],
            'zahraniční DPH z EU (hotel 19 %)' => ['intracom', false, 19.0, 'services', 'zahraniční DPH'],
            'zahraniční DPH z EU bez příznaku' => ['intracom', null, 19.0, 'services', 'zahraniční DPH'],
        ];
    }

    #[DataProvider('underivable')]
    public function testReturnsNullWithReason(
        ?string $place,
        ?bool $reverseCharge,
        ?float $pct,
        ?string $supplyKind,
        string $reasonFragment,
        ?string $tagSupply = null,
    ): void {
        $result = $this->derivation()->derive('cz', self::DATE, $place, $reverseCharge, $pct, $supplyKind, $tagSupply);
        $this->assertNull($result['code']);
        $this->assertIsString($result['reason']);
        $this->assertStringContainsString($reasonFragment, $result['reason']);
        // #89: druh důvodu jen u zahraniční DPH a zvláštního místa plnění —
        // applier podle něj rozlišuje, texty neporovnává.
        $this->assertSame(match ($reasonFragment) {
            'zahraniční DPH'      => VatCodeDerivation::FAIL_FOREIGN_VAT,
            'zvláštním pravidlem' => VatCodeDerivation::FAIL_SPECIAL_PLACE,
            default               => null,
        }, $result['kind']);
    }

    public function testUnknownCountryReturnsReason(): void
    {
        $result = $this->derivation()->derive('xx', self::DATE, 'domestic', null, 21.0, null);
        $this->assertNull($result['code']);
        $this->assertStringContainsString('XX', (string) $result['reason']);
    }

    /** Krácený odpočet (cz-118 …) se nikdy neodvozuje — sazba i místo jsou stejné jako u plného nároku. */
    public function testNeverDerivesReducedDeductionCode(): void
    {
        $d = $this->derivation();
        $cases = [
            [self::DATE, 21.0], [self::DATE, 12.0], [self::DATE, 0.0],
            ['2020-06-01', 15.0], ['2020-06-01', 10.0], ['2020-06-01', 21.0],
        ];
        foreach ($cases as [$date, $pct]) {
            $result = $d->derive('cz', $date, 'domestic', null, $pct, null);
            $this->assertNotNull($result['code'], "sazba {$pct} k {$date} musí být jednoznačná");
            $this->assertNotContains($result['code'], self::REDUCED_DEDUCTION);
        }
    }

    /** Skutečný číselník: každý kód s `supplyKind` je mimo tuzemsko a hodnota je z výčtu. */
    public function testSupplyKindIsPresentOnEveryCrossBorderCode(): void
    {
        $cfg = JsoncParser::parseFile(dirname(__DIR__, 6) . self::VAT_CZ);
        foreach ($cfg['vatCodes'] as $key => $def) {
            $place = $def['place'] ?? 'domestic';
            if ($place === 'domestic') {
                $this->assertArrayNotHasKey('supplyKind', $def, "{$key}: tuzemský kód nemá mít supplyKind");
                continue;
            }
            $this->assertContains($def['supplyKind'] ?? null, VatRateResolver::SUPPLY_KINDS, "{$key}: chybí supplyKind");
        }
    }

    // ── conflict(): soulad existujícího kódu se signály ─────────────────────

    /**
     * @return array<string, array{0: string, 1: ?string, 2: ?bool, 3: ?float, 4: ?string, 5: string}>
     */
    public static function conflicts(): array
    {
        // code, place, reverseCharge, pct, supplyKind, fragment důvodu
        return [
            'tuzemský kód na EU dokladu'        => ['cz-110', 'intracom', null, 0.0, 'services', 'místo plnění'],
            'EU kód na tuzemském dokladu'       => ['cz-217', 'domestic', null, 21.0, null, 'místo plnění'],
            'bez PDP, doklad PDP uvádí'         => ['cz-110', 'domestic', true, 21.0, null, 'přenesení daňové povinnosti'],
            'PDP kód, doklad PDP neuvádí'       => ['cz-115', 'domestic', false, 21.0, null, 'samovyměření'],
            'zboží vs. služby'                  => ['cz-215', 'intracom', true, 0.0, 'services', 'zboží'],
            'sazba nesedí'                      => ['cz-110', 'domestic', null, 12.0, null, 'sazba'],
            'sazba nesedí, kód z historie'      => ['cz-111', null, null, 21.0, null, 'sazba'],
        ];
    }

    #[DataProvider('conflicts')]
    public function testConflictDetected(
        string $code,
        ?string $place,
        ?bool $reverseCharge,
        ?float $pct,
        ?string $supplyKind,
        string $reasonFragment,
    ): void {
        $reason = $this->derivation()->conflict('cz', $code, self::DATE, $place, $reverseCharge, $pct, $supplyKind);
        $this->assertIsString($reason);
        $this->assertStringContainsString($reasonFragment, $reason);
    }

    /**
     * @return array<string, array{0: string, 1: ?string, 2: ?bool, 3: ?float, 4: ?string}>
     */
    public static function consistent(): array
    {
        return [
            'tuzemsko 21'                        => ['cz-110', 'domestic', false, 21.0, null],
            'krácený odpočet z historie'         => ['cz-118', 'domestic', null, 21.0, null],
            'EU služby'                          => ['cz-217', 'intracom', true, 0.0, 'services'],
            'EU služby, sazba dodavatele 0'      => ['cz-217', 'intracom', null, 0.0, null],
            'PDP 4'                              => ['cz-115', 'domestic', true, 0.0, null],
            'bez signálů'                        => ['cz-110', null, null, null, null],
            'neznámý kód řeší resolver'          => ['eu-reverse', 'intracom', true, 0.0, 'services'],
        ];
    }

    #[DataProvider('consistent')]
    public function testNoConflict(string $code, ?string $place, ?bool $reverseCharge, ?float $pct, ?string $supplyKind): void
    {
        $this->assertNull(
            $this->derivation()->conflict('cz', $code, self::DATE, $place, $reverseCharge, $pct, $supplyKind),
        );
    }

    // ── Nabídka kódů pro ruční volbu (tasks/exchange-preview-vat-choices.md D13) ──

    public function testOptionsIntracomHaveOnlyCodesWithRateValidAtDate(): void
    {
        $options = $this->derivation()->options('cz', self::DATE, 'intracom');

        $this->assertSame(['cz-215', 'cz-216', 'cz-217', 'cz-218'], array_column($options, 'code'));
        $services = $options[2];
        $this->assertSame('EU/Vstup/Služby/Základní', $services['label']);
        $this->assertSame(21.0, $services['pct']);
        $this->assertTrue($services['reverseCharge']);
        $this->assertFalse($services['reducedDeduction']);
        $this->assertSame('services', $services['supplyKind']);
    }

    public function testOptionsDomesticIncludeReducedDeductionButNotHiddenOrDateless(): void
    {
        $codes = array_column($this->derivation()->options('cz', self::DATE, 'domestic'), 'code');

        foreach (['cz-118', 'cz-119'] as $reduced) {
            $this->assertContains($reduced, $codes, 'krácený odpočet je ručně legitimní');
        }
        $this->assertContains('cz-110', $codes);
        $this->assertNotContains('cz-203', $codes, 'hidden oddaňovací kód');
        $this->assertNotContains('cz-301', $codes, 'bez sazby k datu');
        $this->assertNotContains('cz-217', $codes, 'jiné místo plnění');
    }

    public function testOptionsThirdCountryIncludeImportAndUnknownPlaceIsEmpty(): void
    {
        $derivation = $this->derivation();

        $foreign = array_column($derivation->options('cz', self::DATE, 'thirdCountry'), 'code');
        $this->assertSame(['cz-415', 'cz-416', 'cz-417', 'cz-418'], $foreign, 'dovoz zboží se neodvozuje, ale nabízí');

        $this->assertSame([], $derivation->options('cz', self::DATE, 'eu'), 'neznámé místo');
        $this->assertSame([], $derivation->options('xx', self::DATE, 'domestic'), 'země bez číselníku');
        // Nabídka je k datu: první snížená sazba (cz-390) platila 2015–2023.
        $this->assertContains('cz-390', array_column($derivation->options('cz', '2016-06-01', 'intracom'), 'code'));
    }


    // ── Předmět plnění tuzemského přenesení (#89 D10) ──────────────────────

    public function testReverseChargeSubjectAppliesOnlyToCodesWithReverseChargeFlag(): void
    {
        $d = $this->derivation();

        $this->assertSame(
            ['applies' => true, 'status' => 'matched', 'value' => '14'],
            $d->reverseChargeSubject('CZ', 'cz-115', self::DATE, ' 14 '),
        );
        $this->assertSame(
            ['applies' => true, 'status' => 'matched', 'value' => '5'],
            $d->reverseChargeSubject('cz', 'cz-150', self::DATE, '5'),
        );
        // Kód bez příznaku (běžný, oddaňovací pár, pořízení z EU) předmět nenese.
        foreach (['cz-110', 'cz-203', 'cz-217', 'cz-999'] as $code) {
            $this->assertSame(
                ['applies' => false, 'status' => null, 'value' => null],
                $d->reverseChargeSubject('cz', $code, self::DATE, '4'),
                $code,
            );
        }
    }

    public function testReverseChargeSubjectIsNeverFilledIn(): void
    {
        $d = $this->derivation();

        $this->assertSame(
            ['applies' => true, 'status' => 'missing', 'value' => null],
            $d->reverseChargeSubject('cz', 'cz-115', self::DATE, null),
        );
        $this->assertSame('missing', $d->reverseChargeSubject('cz', 'cz-115', self::DATE, '  ')['status']);
        $this->assertSame(
            ['applies' => true, 'status' => 'unknown', 'value' => '99'],
            $d->reverseChargeSubject('cz', 'cz-115', self::DATE, '99'),
        );
        // 4a platí od 1. 7. 2017.
        $this->assertSame('unknown', $d->reverseChargeSubject('cz', 'cz-115', '2017-06-30', '4a')['status']);
        $this->assertSame('matched', $d->reverseChargeSubject('cz', 'cz-115', '2017-07-01', '4a')['status']);
    }

    public function testReverseChargeSubjectOptionsAreValidAtDate(): void
    {
        $d = $this->derivation();

        $options = array_column($d->reverseChargeSubjectOptions('cz', self::DATE), 'label', 'code');
        $this->assertArrayHasKey('4a', $options);
        $this->assertStringStartsWith('14 — ', $options['14']);
        $this->assertArrayNotHasKey('4a', array_column($d->reverseChargeSubjectOptions('cz', '2017-06-30'), 'label', 'code'));
        $this->assertSame([], $d->reverseChargeSubjectOptions('xx', self::DATE));
    }
}

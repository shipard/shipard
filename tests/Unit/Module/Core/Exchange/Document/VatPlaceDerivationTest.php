<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Document;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Core\Exchange\Document\VatPlaceDerivation;
use Shipard\Module\World\Trade\TradeUnionResolver;

/**
 * Místo plnění přijatého dokladu z prefixu DIČ dodavatele nad **skutečným**
 * `tradeUnions.jsonc` — tasks/exchange-received-vat-place.md D1/D2.
 * Fiktivní DIČ tvaru `IE1234567X`.
 */
class VatPlaceDerivationTest extends TestCase
{
    private const UNIONS = '/modules/world/trade/config/tradeUnions.jsonc';
    private const DATE = '2026-04-15';

    private static ?array $unions = null;

    private function derivation(mixed $cfgItem = false): VatPlaceDerivation
    {
        self::$unions ??= JsoncParser::parseFile(dirname(__DIR__, 6) . self::UNIONS);
        $data = $cfgItem === false ? self::$unions : $cfgItem;
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn (string $id): mixed => $id === 'world.trade.unions' ? $data : null,
        );
        return new VatPlaceDerivation(new TradeUnionResolver($config));
    }

    /**
     * @return array<string, array{0: ?string, 1: list<string|null>, 2: ?string, 3: ?string, 4: string}>
     */
    public static function derivedPlaces(): array
    {
        // supplierVatId, rowSupplyKinds, expected place, expected prefix, date
        return [
            'IE (sídlo mimo EU — scénář z diagnostiky)' => ['IE1234567X', ['services'], 'intracom', 'IE', self::DATE],
            'CZ → tuzemsko'                              => ['CZ12345678', [null], 'domestic', 'CZ', self::DATE],
            'CZ malými písmeny'                          => ['cz12345678', [null], 'domestic', 'CZ', self::DATE],
            'DE s mezerami'                              => ['DE 123 456 789', ['goods'], 'intracom', 'DE', self::DATE],
            'EL → Řecko'                                 => ['EL123456789', ['services'], 'intracom', 'EL', self::DATE],
            'GB po Brexitu'                              => ['GB123456789', ['services'], 'thirdCountry', 'GB', self::DATE],
            'GB před Brexitem'                           => ['GB123456789', ['services'], 'intracom', 'GB', '2020-06-30'],
            'XI zboží'                                   => ['XI123456789', ['goods', 'goods'], 'intracom', 'XI', self::DATE],
            'XI služby'                                  => ['XI123456789', ['services'], 'thirdCountry', 'XI', self::DATE],
            'XI zboží i služby'                          => ['XI123456789', ['goods', 'services'], 'thirdCountry', 'XI', self::DATE],
            'XI služby + řádek bez druhu'                => ['XI123456789', [null, 'services'], 'thirdCountry', 'XI', self::DATE],
            'XI řádek bez druhu'                         => ['XI123456789', ['goods', null], null, 'XI', self::DATE],
            'XI bez položkových řádků'                   => ['XI123456789', [], null, 'XI', self::DATE],
            'XI před platností'                          => ['XI123456789', ['goods'], 'thirdCountry', 'XI', '2020-06-30'],
            'US — unie nezná'                            => ['US12-3456789', ['services'], null, 'US', self::DATE],
            'CHE — unie nezná'                           => ['CHE-123.456.789 MWST', ['services'], null, 'CH', self::DATE],
            'EU (OSS mimo Unii) — unie nezná'            => ['EU372000000', ['services'], null, 'EU', self::DATE],
            'bez DIČ'                                    => [null, ['services'], null, null, self::DATE],
            'prázdné DIČ'                                => ['  ', ['services'], null, null, self::DATE],
            'DIČ bez písmenného prefixu'                 => ['12345678', ['services'], null, null, self::DATE],
        ];
    }

    /** @param list<string|null> $kinds */
    #[DataProvider('derivedPlaces')]
    public function testDerivesPlaceFromSupplierVatIdPrefix(
        ?string $vatId,
        array $kinds,
        ?string $expectedPlace,
        ?string $expectedPrefix,
        string $date,
    ): void {
        $out = $this->derivation()->derive('cz', $date, $vatId, 'CZ99999999', $kinds);

        $this->assertSame($expectedPlace, $out['place']);
        $this->assertSame($expectedPrefix, $out['prefix']);
        if ($expectedPlace === null) {
            $this->assertIsString($out['reason']);
            $this->assertNotSame('', $out['reason']);
        } else {
            $this->assertNull($out['reason']);
        }
    }

    public function testSwappedPartiesSkipDerivation(): void
    {
        // Model dal naše DIČ k dodavateli — bez pojistky by vyšlo tuzemsko.
        $out = $this->derivation()->derive('cz', self::DATE, 'CZ12345678', 'cz 123 456 78', ['services']);
        $this->assertNull($out['place']);
        $this->assertStringContainsString('odběratele', (string) $out['reason']);

        // Odběratel bez DIČ pojistku nespouští.
        $this->assertSame('domestic', $this->derivation()->derive('cz', self::DATE, 'CZ12345678', null, [])['place']);
    }

    public function testOwnCountryOutsideAnyUnionSkipsDerivation(): void
    {
        $out = $this->derivation()->derive('us', self::DATE, 'IE1234567X', null, ['services']);
        $this->assertNull($out['place']);
        $this->assertStringContainsString('US', (string) $out['reason']);

        // ČR před vstupem do EU — DIČ jiného členského státu nic neodvodí.
        $this->assertNull($this->derivation()->derive('cz', '2003-12-31', 'DE123456789', null, ['goods'])['place']);
    }

    public function testMissingDateSkipsDerivation(): void
    {
        $out = $this->derivation()->derive('cz', null, 'IE1234567X', null, ['services']);
        $this->assertNull($out['place']);
        $this->assertStringContainsString('datum', (string) $out['reason']);
    }

    public function testMissingCfgItemSkipsDerivation(): void
    {
        $out = $this->derivation(null)->derive('cz', self::DATE, 'IE1234567X', null, ['services']);
        $this->assertNull($out['place']);
        $this->assertIsString($out['reason']);
    }

    public function testPrefixKnownByTwoUnionsIsAmbiguous(): void
    {
        // Obecná struktura: kdyby naše země byla ve dvou uniích, které obě
        // prefix znají, derivace nerozhodne.
        $unions = [
            'a' => ['members' => ['cz' => ['joinedAt' => null, 'leftAt' => null]],
                    'taxPrefixes' => ['DE' => ['country' => 'de', 'validFrom' => null, 'validTo' => null]]],
            'b' => ['members' => ['cz' => ['joinedAt' => null, 'leftAt' => null]],
                    'taxPrefixes' => ['DE' => ['country' => 'de', 'validFrom' => null, 'validTo' => null]]],
        ];
        $out = $this->derivation($unions)->derive('cz', self::DATE, 'DE123456789', null, ['goods']);
        $this->assertNull($out['place']);
        $this->assertStringContainsString('víc unií', (string) $out['reason']);

        // Prefix zná jen jedna z nich → jednoznačné.
        unset($unions['b']['taxPrefixes']['DE']);
        $this->assertSame('intracom', $this->derivation($unions)->derive('cz', self::DATE, 'DE123456789', null, ['goods'])['place']);
    }

    public function testNormalize(): void
    {
        $this->assertSame('DE123456789', VatPlaceDerivation::normalize('de 123 456 789'));
        $this->assertSame('CHE123456789MWST', VatPlaceDerivation::normalize('CHE-123.456.789 MWST'));
        $this->assertSame('', VatPlaceDerivation::normalize(null));
        $this->assertSame('', VatPlaceDerivation::normalize(' - '));
    }
}

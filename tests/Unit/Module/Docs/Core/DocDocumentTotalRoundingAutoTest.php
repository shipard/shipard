<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Docs\Core\RoundingModes;
use Shipard\Tests\Fixtures\Module\Docs\Core\TestableDocsHeadsDocument;

/**
 * Automatické zaokrouhlení celkové částky v dokumentu (#73 D4, D7): pravidlo
 * `TotalRoundingAuto` běží v `computeAmounts()` před zaokrouhlením (uložení
 * i náhled applieru), varování při vypnuté automatice ve `validate()`.
 *
 * Řádek 1 × 100,33 při 21 % dává 121,40 — na koruny 121,00 (rozdíl −0,40),
 * na haléře 121,40.
 */
class DocDocumentTotalRoundingAutoTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../../..';

    /** Typ dokladu, který mock DB vrací pro číselnou řadu (validate ho denormalizuje z řady). */
    private string $seriesDocType = 'invno';

    /** @param array<string, mixed> $override klíč cfgItem → hodnota; null = cfgItem chybí */
    private function config(array $override = []): ConfigRuntime
    {
        $items = [
            'docs.core.docTypes'       => JsoncParser::parseFile(self::ROOT . '/modules/docs/core/config/docTypes.jsonc'),
            'docs.core.paymentMethods' => JsoncParser::parseFile(self::ROOT . '/modules/docs/core/config/paymentMethods.jsonc'),
            'docs.core.roundingModes'  => JsoncParser::parseFile(self::ROOT . '/modules/docs/core/config/roundingModes.jsonc'),
            'world.base.cashRounding'  => JsoncParser::parseFile(self::ROOT . '/modules/world/base/config/cashRounding.jsonc'),
            'world.vat.cz'             => JsoncParser::parseFile(self::ROOT . '/modules/world/vat/config/vat-cz.jsonc'),
        ];
        foreach ($override as $id => $value) {
            $items[$id] = $value;
        }
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(static fn (string $id): mixed => $items[$id] ?? null);
        return $config;
    }

    private function dsConfig(string $country): DataSourceConfig
    {
        $ds = $this->createMock(DataSourceConfig::class);
        $ds->method('getCountry')->willReturn($country);
        $ds->method('hasCountry')->willReturn(true);
        return $ds;
    }

    /** Mock DB: země registrace DPH, typ dokladu řady; zápisy chytá fixture přes executeSql. */
    private function db(): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturnCallback(function (string $sql): ?Row {
            if (str_contains($sql, 'economy_codebooks_vat_registrations')) {
                return new Row(['country' => 'cz']);
            }
            if (str_contains($sql, 'docs_core_number_series')) {
                return new Row(['doc_type' => $this->seriesDocType, 'cash_desk' => null, 'warehouse' => null]);
            }
            return new Row(['id' => 1]);
        });
        $db->method('fetchAll')->willReturn([]);
        return $db;
    }

    /**
     * @param array<string, mixed>|null $configOverride null = dokument bez konfigurace
     * @param string|null $country null = bez konfigurace zdroje dat (bez země)
     */
    private function doc(?array $configOverride = [], ?string $country = 'cz'): TestableDocsHeadsDocument
    {
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($this->db());
        if ($configOverride !== null) {
            $doc->setConfig($this->config($configOverride));
        }
        if ($country !== null) {
            $doc->setDsConfig($this->dsConfig($country));
        }
        return $doc;
    }

    /** Faktura vydaná hrazená hotově se zapnutou automatikou a módem 0 v payloadu. */
    private function data(array $extra = []): array
    {
        return array_merge([
            'doc_type'            => 'invno',
            'cash_dir'            => 0,
            'vat_mode'            => 1,
            'vat_recap_source'    => 0,
            'vat_registration'    => 1,
            'issue_date'          => '2026-05-06',
            'vat_duzp'            => '2026-05-06',
            'doc_currency'        => 'czk',
            'home_currency'       => 'czk',
            'exchange_rate'       => 1.0,
            'payment_method'      => 0,
            'total_rounding_mode' => RoundingModes::ON_CENT,
            'total_rounding_auto' => 1,
            'docState'            => 10,
            'rows'                => [[
                'row_kind'        => 1,
                'quantity'        => 1,
                'unit_price'      => 100.33,
                'price_calc_mode' => 0,
                'vat_code'        => 'cz-120',
                'vat_pct'         => 21.0,
            ]],
        ], $extra);
    }

    /** @return array{int, int|null, float, float} mód, příznak (null = klíč chybí), částka, zaokrouhlení */
    private function compute(TestableDocsHeadsDocument $doc, array $data): array
    {
        $doc->computeAmounts($data);
        return [
            (int) $data['total_rounding_mode'],
            array_key_exists('total_rounding_auto', $data) ? (int) $data['total_rounding_auto'] : null,
            (float) $data['total_amount'],
            (float) $data['total_rounding'],
        ];
    }

    // ── computeAmounts() ───────────────────────────────────────────────

    public function testAutoOverridesSentModeForCashPayment(): void
    {
        [$mode, $auto, $amount, $rounding] = $this->compute($this->doc(), $this->data());

        $this->assertSame(RoundingModes::MATH_UNIT, $mode);
        $this->assertSame(1, $auto);
        $this->assertSame(121.0, $amount);
        $this->assertEqualsWithDelta(-0.40, $rounding, 0.001);
    }

    public function testPaymentMethodDrivesModeAndTotals(): void
    {
        [$mode, , $amount, $rounding] = $this->compute($this->doc(), $this->data(['payment_method' => 1]));
        $this->assertSame(RoundingModes::ON_CENT, $mode, 'převodem');
        $this->assertSame(121.40, $amount);
        $this->assertSame(0.0, $rounding);

        [$mode] = $this->compute($this->doc(), $this->data(['payment_method' => 3]));
        $this->assertSame(RoundingModes::MATH_UNIT, $mode, 'dobírkou');

        [$mode] = $this->compute($this->doc(), $this->data(['payment_method' => 2, 'total_rounding_mode' => 1]));
        $this->assertSame(RoundingModes::ON_CENT, $mode, 'kartou přepíše i ručně poslaný mód 1');
    }

    public function testAutoOffKeepsSentMode(): void
    {
        [$mode, $auto, $amount] = $this->compute(
            $this->doc(),
            $this->data(['total_rounding_auto' => 0, 'payment_method' => 1, 'total_rounding_mode' => 1]),
        );
        $this->assertSame(RoundingModes::MATH_UNIT, $mode);
        $this->assertSame(0, $auto);
        $this->assertSame(121.0, $amount);

        [$mode, , $amount] = $this->compute($this->doc(), $this->data(['total_rounding_auto' => false]));
        $this->assertSame(RoundingModes::ON_CENT, $mode, 'hotovost s vypnutou automatikou zůstává na haléřích');
        $this->assertSame(121.40, $amount);
    }

    public function testTypeWithoutAutoClearsFlagAndKeepsMode(): void
    {
        [$mode, $auto, $amount] = $this->compute(
            $this->doc(),
            $this->data(['doc_type' => 'invni', 'total_rounding_mode' => 1]),
        );
        $this->assertSame(0, $auto, 'přijatá faktura automatiku nemá — příznak se vynuluje');
        $this->assertSame(RoundingModes::MATH_UNIT, $mode, 'mód zůstává, jak přišel');
        $this->assertSame(121.0, $amount);
    }

    public function testCashDocumentOnlyForReceipts(): void
    {
        [$mode, $auto] = $this->compute($this->doc(), $this->data(['doc_type' => 'cash', 'cash_dir' => 1]));
        $this->assertSame(RoundingModes::MATH_UNIT, $mode, 'příjmový pokladní doklad hotově → na koruny');
        $this->assertSame(1, $auto);

        [$mode, $auto] = $this->compute($this->doc(), $this->data(['doc_type' => 'cash', 'cash_dir' => 2]));
        $this->assertSame(RoundingModes::ON_CENT, $mode, 'výdajový mód nemění');
        $this->assertSame(0, $auto, 'výdajový pokladní doklad příznak vynuluje');
    }

    public function testMissingFlagKeyIsNotAdded(): void
    {
        $data = $this->data();
        unset($data['total_rounding_auto']);

        [$mode, $auto, $amount] = $this->compute($this->doc(), $data);
        $this->assertNull($auto, 'DocDocument chybějící klíč nedoplňuje (U6)');
        $this->assertSame(RoundingModes::ON_CENT, $mode);
        $this->assertSame(121.40, $amount);
    }

    public function testWithoutConfigurationNothingChanges(): void
    {
        [$mode, $auto, $amount] = $this->compute($this->doc(configOverride: null), $this->data());
        $this->assertSame(RoundingModes::ON_CENT, $mode, 'bez konfigurace se mód nemění — nikdy tiché přepsání');
        $this->assertSame(1, $auto, 'příznak se bez konfigurace nenuluje');
        $this->assertSame(121.40, $amount);

        [$mode, $auto] = $this->compute($this->doc(['world.base.cashRounding' => null]), $this->data());
        $this->assertSame(RoundingModes::ON_CENT, $mode, 'bez pravidel hotovosti (zdroj dat před ds-upgrade)');
        $this->assertSame(1, $auto);
    }

    public function testWithoutCountryModeIsUnchanged(): void
    {
        [$mode, $auto] = $this->compute($this->doc(country: null), $this->data());
        $this->assertSame(RoundingModes::ON_CENT, $mode);
        $this->assertSame(1, $auto);
    }

    public function testCurrencyAndCountryRules(): void
    {
        [$mode] = $this->compute($this->doc(), $this->data(['doc_currency' => 'eur']));
        $this->assertSame(RoundingModes::ON_CENT, $mode, 'EUR hotově na českém zdroji → centy');

        // 1 × 100,36 → 121,4356 → 121,44; na 0,05 → 121,45 (+0,01).
        [$mode, , $amount, $rounding] = $this->compute(
            $this->doc(country: 'sk'),
            $this->data(['doc_currency' => 'eur', 'home_currency' => 'eur', 'rows' => [[
                'row_kind' => 1, 'quantity' => 1, 'unit_price' => 100.36, 'price_calc_mode' => 0,
                'vat_code' => 'cz-120', 'vat_pct' => 21.0,
            ]]]),
        );
        $this->assertSame(RoundingModes::MATH_FIVE_CENT, $mode, 'SK + EUR hotově → 0,05');
        $this->assertSame(121.45, $amount);
        $this->assertEqualsWithDelta(0.01, $rounding, 0.001);

        [$mode] = $this->compute($this->doc(country: 'sk'), $this->data());
        $this->assertSame(RoundingModes::ON_CENT, $mode, 'SK + CZK hotově → haléře');
    }

    public function testPreviewWithoutCurrencyFallsBackToHomeCurrency(): void
    {
        // Náhled applieru volá computeAmounts před applyHomeCurrency.
        $data = $this->data();
        unset($data['doc_currency'], $data['home_currency']);

        [$mode, , $amount] = $this->compute($this->doc(), $data);
        $this->assertSame(RoundingModes::MATH_UNIT, $mode);
        $this->assertSame(121.0, $amount);
    }

    public function testApplyTotalRoundingAutoAlone(): void
    {
        $data = ['doc_type' => 'invno', 'payment_method' => 0, 'doc_currency' => 'czk', 'total_rounding_auto' => 1];
        $this->doc()->applyTotalRoundingAutoPub($data);
        $this->assertSame(RoundingModes::MATH_UNIT, $data['total_rounding_mode']);

        $data = ['doc_type' => 'cmnbkp', 'total_rounding_auto' => 1, 'total_rounding_mode' => 3];
        $this->doc()->applyTotalRoundingAutoPub($data);
        $this->assertSame(0, $data['total_rounding_auto']);
        $this->assertSame(3, $data['total_rounding_mode']);
    }

    // ── validate(): varování při vypnuté automatice ────────────────────

    /** @return list<array<string, mixed>> */
    private function warnings(TestableDocsHeadsDocument $doc, array $data): array
    {
        $result = $doc->validate($data);
        $this->assertSame(
            [],
            array_filter($result->getErrors(), static fn ($e) => $e->column === 'total_rounding_mode'),
            'zaokrouhlení dává jen varování, ne chyby',
        );
        return array_values(array_filter(
            array_map(static fn ($w) => $w->toArray(), $result->getWarnings()),
            static fn (array $w) => $w['column'] === 'total_rounding_mode',
        ));
    }

    /** Doklad k uložení z formuláře: řada 1 → typ z {@see $seriesDocType}, vypnutá automatika. */
    private function validateData(array $extra = []): array
    {
        return $this->data(array_merge([
            'number_series'       => 1,
            'accounting_date'     => '2026-05-06',
            'total_rounding_auto' => 0,
        ], $extra));
    }

    public function testCashWithOtherModeWarnsAndNamesExpectedMode(): void
    {
        $warnings = $this->warnings($this->doc(), $this->validateData());

        $this->assertCount(1, $warnings);
        $this->assertSame('cash_rounding_mismatch', $warnings[0]['code']);
        $this->assertStringContainsString('„Round to 1“', $warnings[0]['message'], 'název módu z číselníku');
    }

    public function testCashWithExpectedModeDoesNotWarn(): void
    {
        $this->assertSame([], $this->warnings($this->doc(), $this->validateData(['total_rounding_mode' => 1])));
        $this->assertSame(
            [],
            $this->warnings($this->doc(), $this->validateData(['payment_method' => 3, 'total_rounding_mode' => 1])),
            'dobírka na koruny',
        );
    }

    public function testNonCashRoundingWarnsOnlyWithVat(): void
    {
        $warnings = $this->warnings($this->doc(), $this->validateData(['payment_method' => 1, 'total_rounding_mode' => 1]));
        $this->assertCount(1, $warnings);
        $this->assertSame('non_cash_rounding', $warnings[0]['code']);
        $this->assertStringContainsString('§ 36 odst. 5', $warnings[0]['message']);

        $this->assertSame(
            [],
            $this->warnings($this->doc(), $this->validateData(['payment_method' => 1, 'total_rounding_mode' => 1, 'vat_mode' => 0])),
            'doklad bez DPH',
        );
        $this->assertSame(
            [],
            $this->warnings($this->doc(), $this->validateData(['payment_method' => 2, 'total_rounding_mode' => 0])),
            'kartou na haléře je v pořádku',
        );
    }

    public function testNoWarningWhenAutoIsOn(): void
    {
        $this->assertSame([], $this->warnings($this->doc(), $this->validateData(['total_rounding_auto' => 1])));
        $this->assertSame(
            [],
            $this->warnings($this->doc(), $this->validateData(['total_rounding_auto' => true, 'payment_method' => 1, 'total_rounding_mode' => 1])),
        );
    }

    public function testNoWarningInImportMode(): void
    {
        $this->assertSame(
            [],
            $this->warnings($this->doc(), $this->validateData(['_importNumber' => ['number' => 'IMP-1']])),
        );
    }

    public function testNoWarningForTypeWithoutAutoOrWithoutConfiguration(): void
    {
        $this->seriesDocType = 'invni';
        $this->assertSame([], $this->warnings($this->doc(), $this->validateData(['doc_type' => 'invni'])));

        $this->seriesDocType = 'invno';
        $this->assertSame([], $this->warnings($this->doc(configOverride: null), $this->validateData()));
        $this->assertSame([], $this->warnings($this->doc(country: null), $this->validateData()));
    }
}

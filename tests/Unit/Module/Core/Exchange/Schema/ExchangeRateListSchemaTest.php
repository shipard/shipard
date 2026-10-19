<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Schema;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;

/**
 * Schéma `shpd.exchangeRates.list.v1` (#121 D2): jeden soubor = jeden lístek,
 * měny velkými, `rate` za `amount` jednotek. Sémantiku (známý typ, domácí
 * měna, duplicitní měna) hlídá importer, tohle je jen tvar.
 */
class ExchangeRateListSchemaTest extends TestCase
{
    private const FORMAT = 'shpd.exchangeRates.list';

    private SchemaValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new SchemaValidator(SchemaLoader::default());
    }

    public function testDailyListIsValid(): void
    {
        $this->assertSame([], $this->validator->validate(self::list(), self::FORMAT, '1'));
    }

    public function testMonthlyListWithValidToAndWithoutListNumberIsValid(): void
    {
        $list = self::list(['listType' => 'cz.cnb.other', 'validTo' => '2026-10-31']);
        unset($list['listNumber']);

        $this->assertSame([], $this->validator->validate($list, self::FORMAT, '1'));
    }

    public function testRatesMustNotBeEmpty(): void
    {
        $this->assertContains('minItems', $this->codes(self::list(['rates' => []])));
    }

    public function testAmountMustBeAPositiveInteger(): void
    {
        $this->assertContains('minimum', $this->codes(self::list(['rates' => [self::rate(['amount' => 0])]])));
        $this->assertContains('type', $this->codes(self::list(['rates' => [self::rate(['amount' => 1.5])]])));
    }

    public function testRateMustBePositive(): void
    {
        $this->assertContains('exclusiveMinimum', $this->codes(self::list(['rates' => [self::rate(['rate' => 0])]])));
    }

    public function testCurrenciesAreUpperCaseIsoCodes(): void
    {
        $this->assertContains('pattern', $this->codes(self::list(['homeCurrency' => 'czk'])));
        $this->assertContains('pattern', $this->codes(self::list(['rates' => [self::rate(['currency' => 'eur'])]])));
    }

    public function testDatesAreIsoAndValidToMayBeNull(): void
    {
        $this->assertContains('pattern', $this->codes(self::list(['validFrom' => '16.10.2026'])));
        $this->assertSame([], $this->validator->validate(self::list(['validTo' => null]), self::FORMAT, '1'));
    }

    public function testUnknownKeysAreRejected(): void
    {
        $this->assertContains('additionalProperties', $this->codes(self::list(['source' => 1])));
        $this->assertContains('additionalProperties', $this->codes(self::list(['rates' => [self::rate(['unit' => 1])]])));
    }

    public function testRequiredKeys(): void
    {
        foreach (['format', 'listType', 'homeCurrency', 'validFrom', 'rates'] as $key) {
            $list = self::list();
            unset($list[$key]);
            $this->assertContains('required', $this->codes($list), "bez {$key}");
        }
    }

    /** @return list<string> */
    private function codes(array $list): array
    {
        return array_column($this->validator->validate($list, self::FORMAT, '1'), 'code');
    }

    /** @param array<string, mixed> $override */
    private static function list(array $override = []): array
    {
        return array_merge([
            'format'       => 'shpd.exchangeRates.list.v1',
            'listType'     => 'cz.cnb.daily',
            'homeCurrency' => 'CZK',
            'validFrom'    => '2026-10-16',
            'validTo'      => null,
            'listNumber'   => '201',
            'rates'        => [self::rate(), self::rate(['currency' => 'JPY', 'amount' => 100, 'rate' => 15.871])],
        ], $override);
    }

    /** @param array<string, mixed> $override */
    private static function rate(array $override = []): array
    {
        return array_merge(['currency' => 'EUR', 'amount' => 1, 'rate' => 24.325], $override);
    }
}

<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Document;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Core\Exchange\Document\RoundingModeNames;
use Shipard\Module\Docs\Core\RoundingModes;

/**
 * Názvy módů zaokrouhlení ve výměnném formátu (#166 D25): každý mód
 * `RoundingModes` má právě jeden název a schéma dokladu nabízí tytéž názvy.
 */
final class RoundingModeNamesTest extends TestCase
{
    public function testEveryTotalRoundingModeHasExactlyOneName(): void
    {
        $codes = array_values(RoundingModeNames::TOTAL);
        sort($codes);
        $this->assertSame(array_keys(RoundingModes::TABLE), $codes);
    }

    public function testEveryVatRoundingModeHasExactlyOneName(): void
    {
        $codes = array_values(RoundingModeNames::VAT);
        sort($codes);
        $this->assertSame(RoundingModes::VAT_MODES, $codes);
    }

    public function testNamesRoundTripThroughCodes(): void
    {
        foreach (array_keys(RoundingModes::TABLE) as $code) {
            $name = RoundingModeNames::totalName($code);
            $this->assertNotNull($name, "mód {$code} nemá název");
            $this->assertSame($code, RoundingModeNames::totalCode($name));
        }
        foreach (RoundingModes::VAT_MODES as $code) {
            $name = RoundingModeNames::vatName($code);
            $this->assertNotNull($name, "mód DPH {$code} nemá název");
            $this->assertSame($code, RoundingModeNames::vatCode($name));
        }
    }

    public function testUnknownValuesGiveNull(): void
    {
        $this->assertNull(RoundingModeNames::totalCode(null));
        $this->assertNull(RoundingModeNames::totalCode('up'));
        $this->assertNull(RoundingModeNames::totalCode(3));
        $this->assertNull(RoundingModeNames::vatCode('unitUp'));
        // Historický kód 2 („matematicky na 0,01“) název nemá — export ho vynechá.
        $this->assertNull(RoundingModeNames::totalName(2));
        $this->assertNull(RoundingModeNames::vatName(RoundingModes::UP_UNIT));
    }

    public function testSchemaOffersTheSameNames(): void
    {
        $schema = JsoncParser::parseFile(
            dirname(__DIR__, 6) . '/modules/core/exchange/schemas/shpd.docs.document.v1.jsonc',
        );
        $this->assertIsArray($schema);

        $this->assertSame(
            [...array_keys(RoundingModeNames::TOTAL), null],
            $schema['properties']['totals']['properties']['totalRoundingMode']['enum'] ?? null,
        );
        $this->assertSame(
            [...array_keys(RoundingModeNames::VAT), null],
            $schema['properties']['vat']['properties']['roundingMode']['enum'] ?? null,
        );
    }
}

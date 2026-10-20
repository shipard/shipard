<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\EInvoice;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\EInvoice\CountryNames;
use Shipard\Module\Core\Exchange\EInvoice\EInvoiceOptions;

/** Názvy zemí pro adresy e-faktury nad `world.base.countries`. */
class CountryNamesTest extends TestCase
{
    private const COUNTRIES_FILE = __DIR__ . '/../../../../../../modules/world/base/config/countries.jsonc';

    public function testFromModuleFile(): void
    {
        $names = CountryNames::fromModuleFile(self::COUNTRIES_FILE);
        $this->assertSame('Česko', $names->nameFor('cz'));
        $this->assertSame('Česko', $names->nameFor('CZ'));
        $this->assertSame('Slovensko', $names->nameFor('sk'));
        $this->assertNull($names->nameFor('xx'));
        $this->assertSame('Czechia', CountryNames::fromModuleFile(self::COUNTRIES_FILE, 'en')->nameFor('cz'));
    }

    public function testFallbackChainAndMissingFile(): void
    {
        $names = new CountryNames(['cz' => ['name' => 'Czechia', 'name:en' => 'Czechia (en)'], 'xy' => []], 'cs');
        $this->assertSame('Czechia (en)', $names->nameFor('cz'), 'cs chybí → en');
        $this->assertNull($names->nameFor('xy'), 'záznam bez názvu');
        $this->assertSame('Czechia', (new CountryNames(['cz' => ['name' => 'Czechia']]))->nameFor('cz'), 'holé pole');

        $this->expectException(\RuntimeException::class);
        CountryNames::fromModuleFile('/nonexistent/countries.jsonc');
    }

    public function testOptionsValidateUuidAndSystem(): void
    {
        $names = new CountryNames([]);
        $options = new EInvoiceOptions('03f8260d-4689-5cee-abab-e361a124160c', $names);
        $this->assertSame('Shipard', $options->issuingSystem);

        try {
            new EInvoiceOptions('abc', $names);
            $this->fail('UUID mimo tvar musí selhat');
        } catch (\InvalidArgumentException) {
        }
        $this->expectException(\InvalidArgumentException::class);
        new EInvoiceOptions('03f8260d-4689-5cee-abab-e361a124160c', $names, str_repeat('x', 81));
    }
}

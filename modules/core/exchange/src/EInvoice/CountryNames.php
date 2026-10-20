<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\EInvoice;

use Shipard\Core\I18n\LocalizedFieldResolver;
use Shipard\Core\Utils\JsoncParser;

/**
 * Názvy zemí pro adresy stran e-faktury nad číselníkem
 * `world.base.countries` (klíč = kód ISO 3166-1 alpha-2 malými písmeny,
 * pole `name` / `name:cs` / `name:en`). Generátor konfiguraci nečte —
 * dostane tuto třídu: tisk (fáze 2) ji postaví z cfgItem zdroje dat,
 * CLI bez zdroje dat přímo z modulového souboru
 * ({@see CountryNames::fromModuleFile()}).
 */
final class CountryNames
{
    /**
     * @param array<string, array<string, mixed>> $countries obsah cfgItem `world.base.countries`
     */
    public function __construct(
        private readonly array $countries,
        private readonly string $language = 'cs',
    ) {
    }

    /** @param string $path cesta k `modules/world/base/config/countries.jsonc` */
    public static function fromModuleFile(string $path, string $language = 'cs'): self
    {
        if (!is_file($path)) {
            throw new \RuntimeException("Číselník zemí '{$path}' neexistuje");
        }
        /** @var array<string, array<string, mixed>> $countries */
        $countries = JsoncParser::parseFile($path);
        return new self($countries, $language);
    }

    /** Název země v jazyce číselníku; neznámý kód = null. */
    public function nameFor(string $alpha2): ?string
    {
        $entry = $this->countries[strtolower(trim($alpha2))] ?? null;
        if (!is_array($entry)) {
            return null;
        }
        $name = LocalizedFieldResolver::resolve($entry, 'name', $this->language);
        return $name !== '' ? $name : null;
    }
}

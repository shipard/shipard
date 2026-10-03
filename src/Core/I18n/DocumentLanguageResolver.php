<?php

declare(strict_types=1);

namespace Shipard\Core\I18n;

use Shipard\Core\Config\ConfigRuntime;

/**
 * Jazyk dokumentu pro partnera (#94 D2) — sdílí ho tisk a odeslání dokladu.
 * Čistá služba bez databáze; výslovně vyžádaný jazyk (REST / CLI) řeší
 * volající dřív, než se sem zeptá.
 *
 * 1. jazyk nastavený na osobě, je-li mezi jazyky dokumentů,
 * 2. hlavní jazyk země strany (první položka `languages` v číselníku zemí);
 *    není-li mezi jazyky dokumentů → `en`,
 * 3. země chybí nebo je neznámá → totéž pro vlastní zemi zdroje dat.
 *
 * Vícejazyčné země se berou jen podle hlavního jazyka (CH → `de`,
 * BE → `en`); jiný jazyk se nastaví výslovně na osobě. `defaultLanguage`
 * zdroje dat se nepoužívá — je to jazyk rozhraní a jeho fallback `en` by
 * tuzemcům tiskl anglicky.
 */
final class DocumentLanguageResolver
{
    /** Jazyk pro země, jejichž hlavní jazyk mezi jazyky dokumentů není. */
    public const FALLBACK = 'en';

    /** @var array<string, true> */
    private readonly array $languages;

    /**
     * @param array<string, mixed> $documentLanguages cfgItem
     *        `world.base.documentLanguages` — rozhodují klíče.
     * @param array<string, mixed> $countries cfgItem `world.base.countries`.
     * @param string $ownCountry Země zdroje dat (`DataSourceConfig::getCountry()`).
     */
    public function __construct(
        array $documentLanguages,
        private readonly array $countries,
        private readonly string $ownCountry,
    ) {
        $this->languages = array_fill_keys(array_map('strval', array_keys($documentLanguages)), true);
    }

    /** Chybějící cfgItem (zdroj dat před `ds-upgrade`) = prázdný seznam → vše `en`. */
    public static function fromConfig(?ConfigRuntime $config, string $ownCountry): self
    {
        $languages = $config?->cfgItem('world.base.documentLanguages');
        $countries = $config?->cfgItem('world.base.countries');

        return new self(
            is_array($languages) ? $languages : [],
            is_array($countries) ? $countries : [],
            $ownCountry,
        );
    }

    /**
     * @param ?string $personLanguage Jazyk osoby; neplatná hodnota (starší
     *        import) se ignoruje, jako by nebyla vyplněná.
     * @param ?string $partyCountry Země strany dokladu, ISO alpha-2
     *        v libovolné velikosti písmen.
     */
    public function resolve(?string $personLanguage, ?string $partyCountry): string
    {
        if ($personLanguage !== null && isset($this->languages[$personLanguage])) {
            return $personLanguage;
        }

        return $this->languageOfCountry($partyCountry)
            ?? $this->languageOfCountry($this->ownCountry)
            ?? self::FALLBACK;
    }

    /** Null = země chybí nebo ji číselník nezná. */
    private function languageOfCountry(?string $country): ?string
    {
        $entry = $this->countries[strtolower(trim((string) $country))] ?? null;
        if (!is_array($entry)) {
            return null;
        }

        $main = $entry['languages'][0] ?? null;
        return is_string($main) && isset($this->languages[$main]) ? $main : self::FALLBACK;
    }
}

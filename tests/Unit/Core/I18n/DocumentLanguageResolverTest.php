<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\I18n;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\I18n\DocumentLanguageResolver;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Tests\Fixtures\Core\Config\ConfigRuntimeFactory;

/**
 * Odvození jazyka dokumentu (#94 D2) nad **skutečnými** číselníky
 * `world.base` — tabulka níže tak hlídá i data: které země mají hlavní
 * jazyk mezi jazyky dokumentů.
 */
class DocumentLanguageResolverTest extends TestCase
{
    private const WORLD = __DIR__ . '/../../../../modules/world/base/config';

    /** @var array<string, mixed>|null */
    private static ?array $languages = null;

    /** @var array<string, mixed>|null */
    private static ?array $countries = null;

    private function resolver(string $ownCountry = 'cz'): DocumentLanguageResolver
    {
        self::$languages ??= JsoncParser::parseFile(self::WORLD . '/documentLanguages.jsonc');
        self::$countries ??= JsoncParser::parseFile(self::WORLD . '/countries.jsonc');

        return new DocumentLanguageResolver(self::$languages, self::$countries, $ownCountry);
    }

    /** @return array<string, array{?string, ?string, string, string}> */
    public static function cases(): array
    {
        // [jazyk osoby, země strany, vlastní země, očekávaný jazyk]
        return [
            'jazyk osoby má přednost před zemí'        => ['en', 'cz', 'cz', 'en'],
            'jazyk osoby bez země'                     => ['de', null, 'cz', 'de'],
            'CZ → cs'                                  => [null, 'cz', 'cz', 'cs'],
            'SK → sk'                                  => [null, 'sk', 'cz', 'sk'],
            'AT → de'                                  => [null, 'at', 'cz', 'de'],
            'CH → de (hlavní jazyk)'                   => [null, 'ch', 'cz', 'de'],
            'BE → en (hlavní nl)'                      => [null, 'be', 'cz', 'en'],
            'LU → en (hlavní lb)'                      => [null, 'lu', 'cz', 'en'],
            'IE → en (hlavní ga)'                      => [null, 'ie', 'cz', 'en'],
            'FR → en'                                  => [null, 'fr', 'cz', 'en'],
            'země velkými písmeny'                     => [null, 'SK', 'cz', 'sk'],
            'země s mezerami'                          => [null, ' at ', 'cz', 'de'],
            'bez země → vlastní země'                  => [null, null, 'cz', 'cs'],
            'prázdná země → vlastní země'              => [null, '', 'sk', 'sk'],
            'neznámá země → vlastní země'              => [null, 'zz', 'cz', 'cs'],
            'vlastní země s jazykem mimo seznam → en'  => [null, null, 'fr', 'en'],
            'neznámá vlastní země → en'                => [null, null, 'zz', 'en'],
            'neplatný jazyk osoby se ignoruje'         => ['fr', 'sk', 'cz', 'sk'],
            'prázdný jazyk osoby se ignoruje'          => ['', 'at', 'cz', 'de'],
        ];
    }

    #[DataProvider('cases')]
    public function testResolve(?string $personLanguage, ?string $partyCountry, string $ownCountry, string $expected): void
    {
        $this->assertSame($expected, $this->resolver($ownCountry)->resolve($personLanguage, $partyCountry));
    }

    public function testFromConfigReadsWorldCfgItems(): void
    {
        $config = ConfigRuntimeFactory::fromItems([
            'world.base.documentLanguages' => ['cs' => ['name' => 'čeština'], 'en' => ['name' => 'angličtina']],
            'world.base.countries'         => ['cz' => ['languages' => ['cs']], 'sk' => ['languages' => ['sk']]],
        ]);
        $resolver = DocumentLanguageResolver::fromConfig($config, 'cz');

        $this->assertSame('cs', $resolver->resolve(null, null));
        $this->assertSame('en', $resolver->resolve(null, 'sk'), 'sk není mezi jazyky dokumentů této konfigurace');
    }

    public function testWithoutConfigEverythingFallsBackToEnglish(): void
    {
        $resolver = DocumentLanguageResolver::fromConfig(null, 'cz');

        $this->assertSame('en', $resolver->resolve('cs', 'cz'));
    }
}

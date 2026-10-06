<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Feed;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Module\ModuleLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Utils\JsoncParser;

/**
 * Úplnost katalogů textů feedu (#101 D16): každý cfgItem `*.feedTexts`
 * aktivních modulů v repozitáři má u každého klíče holé pole `text`
 * i varianty `text:cs` / `text:en`, každý vzor projde ICU parserem
 * a obě jazykové varianty používají stejné parametry. Katalogy se
 * hledají v `config[]` deklaracích modulů — nový katalog se do testu
 * přidá sám.
 *
 * Navíc drží konvenci D15: anglický fallback ve volání `->t(klíč, fallback)`
 * ve zdrojích je znak po znaku holé pole `text` katalogu, a každý klíč
 * katalogu některý zdroj používá.
 */
final class FeedTextsCatalogTest extends TestCase
{
    private const SUFFIX = '.feedTexts';

    private const EXPECTED = ['core.mail.feedTexts', 'core.alerts.feedTexts', 'core.exchange.feedTexts'];

    /** cfgItem id → zdroje feedu, které z katalogu čtou (cesty od kořene repozitáře). */
    private const SOURCES = [
        'core.mail.feedTexts' => [
            'modules/core/mail/src/Feed/MailSuggestionsSource.php',
            'modules/core/mail/src/Feed/MailDigestSource.php',
        ],
        'core.alerts.feedTexts'   => ['modules/core/alerts/src/Feed/AlertsSource.php'],
        'core.exchange.feedTexts' => ['modules/core/exchange/src/Dashboard/ContentTagSuggestionsSource.php'],
    ];

    /** [pole katalogu, locale pro parsování]; holé pole se parsuje jako angličtina */
    private const VARIANTS = [['text', 'en'], ['text:cs', 'cs'], ['text:en', 'en']];

    /** @return array<string, array<string, mixed>> cfgItem id → surový katalog */
    private static function catalogs(): array
    {
        $resolver = new ModulePathResolver([dirname(__DIR__, 4) . '/modules']);
        $out = [];
        foreach (ModuleLoader::loadAllModules($resolver) as $module) {
            foreach ($module->config as $entry) {
                $id = (string) ($entry['id'] ?? '');
                if (!str_ends_with($id, self::SUFFIX)) {
                    continue;
                }
                $raw = JsoncParser::parseFile($resolver->getPath($module->id) . '/' . $entry['file']);
                self::assertIsArray($raw, "{$id}: katalog je objekt");
                self::assertNotEmpty($raw, "{$id}: katalog není prázdný");
                $out[$id] = $raw;
            }
        }
        return $out;
    }

    public function testRepositoryDeclaresFeedTextCatalogs(): void
    {
        $ids = array_keys(self::catalogs());

        foreach (self::EXPECTED as $expected) {
            $this->assertContains($expected, $ids);
        }
    }

    public function testEveryKeyHasBareTextAndBothLanguages(): void
    {
        foreach (self::catalogs() as $id => $catalog) {
            foreach ($catalog as $key => $entry) {
                $this->assertIsArray($entry, "{$id}.{$key}: položka je objekt");
                foreach (self::VARIANTS as [$field]) {
                    $this->assertArrayHasKey($field, $entry, "{$id}.{$key}: chybí {$field}");
                    $this->assertIsString($entry[$field], "{$id}.{$key}.{$field}: text");
                    $this->assertNotSame('', trim($entry[$field]), "{$id}.{$key}.{$field}: prázdný text");
                }
            }
        }
    }

    public function testEveryPatternParsesAsIcuMessage(): void
    {
        foreach (self::catalogs() as $id => $catalog) {
            foreach ($catalog as $key => $entry) {
                foreach (self::VARIANTS as [$field, $locale]) {
                    $pattern = (string) $entry[$field];
                    try {
                        new \MessageFormatter($locale, $pattern);
                    } catch (\IntlException $e) {
                        $this->fail("{$id}.{$key}.{$field}: nevalidní ICU vzor — {$e->getMessage()}: {$pattern}");
                    }
                }
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testLanguageVariantsShareParameters(): void
    {
        foreach (self::catalogs() as $id => $catalog) {
            foreach ($catalog as $key => $entry) {
                $cs = self::parameters((string) $entry['text:cs']);
                $this->assertSame(
                    $cs,
                    self::parameters((string) $entry['text:en']),
                    "{$id}.{$key}: text:cs a text:en mají jiné parametry",
                );
                $this->assertSame(
                    $cs,
                    self::parameters((string) $entry['text']),
                    "{$id}.{$key}: holé pole má jiné parametry než překlady",
                );
            }
        }
    }

    public function testBareTextIsTheEnglishFallback(): void
    {
        // Konvence katalogu: holé pole = anglický fallback, shodné s en
        // variantou i s fallbackem ve zdroji.
        foreach (self::catalogs() as $id => $catalog) {
            foreach ($catalog as $key => $entry) {
                $this->assertSame($entry['text:en'], $entry['text'], "{$id}.{$key}: text ≠ text:en");
            }
        }
    }

    public function testSourceFallbacksMatchCatalogAndEveryKeyIsUsed(): void
    {
        $catalogs = self::catalogs();
        foreach (self::SOURCES as $id => $files) {
            $this->assertArrayHasKey($id, $catalogs);
            $used = [];
            foreach ($files as $file) {
                foreach (self::textCalls(dirname(__DIR__, 4) . '/' . $file) as [$key, $fallback]) {
                    $this->assertArrayHasKey($key, $catalogs[$id], "{$file}: klíč '{$key}' v katalogu {$id} není");
                    $this->assertSame(
                        $catalogs[$id][$key]['text'],
                        $fallback,
                        "{$file}: fallback klíče '{$key}' se liší od holého pole katalogu",
                    );
                    $used[$key] = true;
                }
            }
            $this->assertSame(
                [],
                array_values(array_diff(array_keys($catalogs[$id]), array_keys($used))),
                "{$id}: klíče katalogu bez použití ve zdroji",
            );
        }
    }

    /**
     * Dvojice (klíč, fallback) z volání `->t('klíč', 'fallback'` ve zdroji;
     * oba argumenty jsou jednoduše uvozované literály bez escapů.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function textCalls(string $path): array
    {
        $code = file_get_contents($path);
        self::assertIsString($code, $path);
        preg_match_all("/->t\(\s*'([^']+)',\s*'([^']*)'/", $code, $m, PREG_SET_ORDER);
        self::assertNotEmpty($m, "{$path}: žádné volání ->t()");
        return array_map(static fn(array $call): array => [$call[1], $call[2]], $m);
    }

    /**
     * Názvy argumentů ve vzoru (`{n, plural, …}`, `{name}`), seřazené;
     * `#` uvnitř plurálu argument není.
     *
     * @return list<string>
     */
    private static function parameters(string $pattern): array
    {
        preg_match_all('/\{\s*([A-Za-z_][A-Za-z0-9_]*)/', $pattern, $m);
        $names = array_values(array_unique($m[1]));
        sort($names);
        return $names;
    }
}

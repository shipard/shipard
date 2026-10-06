<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Feed;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Feed\FeedContext;
use Shipard\Core\Feed\FeedTexts;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Tests\Fixtures\Core\Config\ConfigRuntimeFactory;

/**
 * Unit testy pro FeedTexts (#101 D11–D15).
 *
 * Pokrývají:
 *   - vzor z cfgItemu, parametry, plurály cs (1 / 2–4 / 5+ / 0 / 21) a en
 *   - bez compiled configu / bez cfgItemu → fallback bez warningu
 *   - chybějící nebo prázdný klíč → fallback + warning, jednou per klíč
 *   - nevalidní vzor → fallback + warning; nevalidní i fallback → vzor
 *     beze změny, nikdy výjimka
 *   - ICU detaily: apostrof `''`, chybějící parametr zůstane v textu
 *   - forContext bere jazyk a config z FeedContext
 */
final class FeedTextsTest extends TestCase
{
    private const CFG = 'test.feedTexts';

    private string $logPath;

    protected function setUp(): void
    {
        $this->logPath = sys_get_temp_dir() . '/shpd_feedtexts_' . uniqid('', true) . '.log';
        ErrorLogger::resetForTesting();
        ErrorLogger::setLogPath($this->logPath);
    }

    protected function tearDown(): void
    {
        ErrorLogger::resetForTesting();
        @unlink($this->logPath);
    }

    /** @param array<string, array<string, mixed>> $entries klíč → položka katalogu */
    private function texts(?array $entries, string $language = 'cs', string $cfgItemId = self::CFG): FeedTexts
    {
        $config = $entries === null ? null : ConfigRuntimeFactory::fromItems([self::CFG => $entries]);
        return new FeedTexts($config, $cfgItemId, $language);
    }

    /** @return list<array<string, mixed>> warningy zapsané do logu */
    private function warnings(): array
    {
        if (!is_file($this->logPath)) {
            return [];
        }
        $out = [];
        foreach (file($this->logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $entry = json_decode($line, true);
            if (is_array($entry) && ($entry['level'] ?? null) === 'warn') {
                $out[] = $entry;
            }
        }
        return $out;
    }

    // ── vzor z katalogu ─────────────────────────────────────────────────────

    public function testUsesCatalogPatternWithParams(): void
    {
        $texts = $this->texts(['greeting' => ['text' => 'Ahoj {name}']]);

        $this->assertSame('Ahoj Anno', $texts->t('greeting', 'Hello {name}', ['name' => 'Anno']));
        $this->assertSame([], $this->warnings());
    }

    public function testCzechPluralBoundaries(): void
    {
        $texts = $this->texts(['count' => [
            'text' => '{n, plural, one {# zpráva} few {# zprávy} other {# zpráv}}',
        ]]);

        $expected = [
            0 => '0 zpráv',
            1 => '1 zpráva',
            2 => '2 zprávy',
            3 => '3 zprávy',
            4 => '4 zprávy',
            5 => '5 zpráv',
            21 => '21 zpráv',
            22 => '22 zpráv',
            101 => '101 zpráv',
        ];
        foreach ($expected as $n => $text) {
            $this->assertSame($text, $texts->t('count', 'x', ['n' => $n]), "n = {$n}");
        }
    }

    public function testEnglishPlural(): void
    {
        $texts = $this->texts(
            ['count' => ['text' => '{n, plural, one {# item} other {# items}}']],
            'en',
        );

        $this->assertSame('1 item', $texts->t('count', 'x', ['n' => 1]));
        $this->assertSame('2 items', $texts->t('count', 'x', ['n' => 2]));
        $this->assertSame('0 items', $texts->t('count', 'x', ['n' => 0]));
    }

    public function testPluralHashFormatsThousandsInLocale(): void
    {
        // Dokumentovaná odchylka od dřívějšího kódu (nad 999): `#` = číslo
        // formátované v locale, v cs s nezlomitelnou mezerou.
        $texts = $this->texts(['count' => ['text' => '{n, plural, one {# zpráva} other {# zpráv}}']]);

        $this->assertSame("1\u{00A0}234 zpráv", $texts->t('count', 'x', ['n' => 1234]));
    }

    public function testPlainPlaceholderDoesNotFormatNumbers(): void
    {
        $texts = $this->texts(['count' => ['text' => '{n} upozornění']]);

        $this->assertSame('1234 upozornění', $texts->t('count', 'x', ['n' => 1234]));
    }

    public function testApostropheIsEscapedIcuStyle(): void
    {
        $texts = $this->texts(['a' => ['text' => "it''s {x}"]], 'en');

        $this->assertSame("it's fine", $texts->t('a', 'x', ['x' => 'fine']));
    }

    public function testMissingParamStaysInText(): void
    {
        $texts = $this->texts(['c' => ['text' => 'jistota {pct} %']]);

        $this->assertSame('jistota {pct} %', $texts->t('c', 'x'));
        $this->assertSame([], $this->warnings());
    }

    // ── degradace ───────────────────────────────────────────────────────────

    public function testWithoutConfigFallsBackWithoutWarning(): void
    {
        $texts = $this->texts(null);

        $this->assertSame('Hello Anno', $texts->t('greeting', 'Hello {name}', ['name' => 'Anno']));
        $this->assertSame([], $this->warnings());
    }

    public function testWithoutCfgItemFallsBackWithoutWarning(): void
    {
        // DS před ds-upgrade: compiled config existuje, katalog v něm ne.
        $texts = $this->texts(['greeting' => ['text' => 'Ahoj {name}']], 'cs', 'other.feedTexts');

        $this->assertSame('Hello Anno', $texts->t('greeting', 'Hello {name}', ['name' => 'Anno']));
        $this->assertSame([], $this->warnings());
    }

    public function testFallbackIsFormattedInFeedLanguage(): void
    {
        $texts = $this->texts(null);

        $this->assertSame(
            '3 items',
            $texts->t('count', '{n, plural, one {# item} other {# items}}', ['n' => 3]),
        );
    }

    public function testMissingKeyFallsBackAndWarnsOnce(): void
    {
        $texts = $this->texts(['other' => ['text' => 'x']]);

        $this->assertSame('Hello', $texts->t('greeting', 'Hello'));
        $this->assertSame('Hello', $texts->t('greeting', 'Hello'));

        $warnings = $this->warnings();
        $this->assertCount(1, $warnings);
        $this->assertSame('Feed text key missing in catalog', $warnings[0]['msg']);
        $this->assertSame(self::CFG, $warnings[0]['ctx']['cfgItem']);
        $this->assertSame('greeting', $warnings[0]['ctx']['key']);
        $this->assertSame('cs', $warnings[0]['ctx']['language']);
    }

    public function testEmptyTextFallsBackAndWarns(): void
    {
        $texts = $this->texts(['greeting' => ['text' => '']]);

        $this->assertSame('Hello', $texts->t('greeting', 'Hello'));
        $this->assertCount(1, $this->warnings());
    }

    public function testWarningsAreDedupedPerKey(): void
    {
        $texts = $this->texts(['other' => ['text' => 'x']]);

        $texts->t('a', 'A');
        $texts->t('b', 'B');
        $texts->t('a', 'A');

        $this->assertCount(2, $this->warnings());
    }

    public function testInvalidCatalogPatternFallsBackAndWarns(): void
    {
        $texts = $this->texts(['count' => ['text' => '{n, plural, one {#']]);

        $this->assertSame('3 items', $texts->t('count', '{n, plural, one {# item} other {# items}}', ['n' => 3]));

        $warnings = $this->warnings();
        $this->assertCount(1, $warnings);
        $this->assertSame('Feed text pattern is invalid', $warnings[0]['msg']);
        $this->assertSame('{n, plural, one {#', $warnings[0]['ctx']['pattern']);
    }

    public function testInvalidCatalogAndFallbackReturnPatternUnchanged(): void
    {
        $texts = $this->texts(['count' => ['text' => '{n, plural, one {#']]);

        $this->assertSame('{n, plural, one {#', $texts->t('count', '{broken', ['n' => 3]));
    }

    public function testInvalidFallbackWithoutConfigReturnsFallbackUnchanged(): void
    {
        $texts = $this->texts(null);

        $this->assertSame('{broken', $texts->t('count', '{broken', ['n' => 3]));
        $this->assertCount(1, $this->warnings());
    }

    // ── forContext ──────────────────────────────────────────────────────────

    public function testForContextTakesLanguageAndConfigFromContext(): void
    {
        $config = ConfigRuntimeFactory::fromItems([self::CFG => [
            'count' => ['text' => '{n, plural, one {# item} other {# items}}'],
        ]]);
        $ctx = new FeedContext($this->createMock(DataSourceConnection::class), $config, 'en', 30);

        $texts = FeedTexts::forContext($ctx, self::CFG);

        $this->assertSame('2 items', $texts->t('count', 'x', ['n' => 2]));
    }

    public function testForContextWithoutConfigUsesFallback(): void
    {
        $ctx = new FeedContext($this->createMock(DataSourceConnection::class), null, 'cs', 30);

        $this->assertSame('Hello', FeedTexts::forContext($ctx, self::CFG)->t('k', 'Hello'));
    }

    public function testConfigRuntimeMockIsAccepted(): void
    {
        // Testy zdrojů staví config přes createMock(ConfigRuntime) — helper
        // s ním musí pracovat stejně jako se skutečným runtime.
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn(string $id): mixed => $id === self::CFG ? ['k' => ['text' => 'Ahoj']] : null,
        );

        $this->assertSame('Ahoj', (new FeedTexts($config, self::CFG, 'cs'))->t('k', 'Hello'));
    }
}

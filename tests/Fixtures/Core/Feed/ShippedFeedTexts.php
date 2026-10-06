<?php

declare(strict_types=1);

namespace Shipard\Tests\Fixtures\Core\Feed;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\I18n\ConfigLocalizer;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Tests\Fixtures\Core\Config\ConfigRuntimeFactory;

/**
 * Dodávané katalogy textů feedu (`*.feedTexts`) lokalizované jako compiled
 * config — testy zdrojů feedu assertují přesné české texty, které bez
 * katalogu nevzniknou (fallback v PHP je anglický, #101 D15).
 *
 * Dvě cesty podle toho, jak test staví config:
 *   - `config($lang, $items)` — skutečný `ConfigRuntime` s katalogy + dalšími
 *     položkami (`ConfigRuntimeFactory::fromItems`);
 *   - `resolver($lang, $inner)` — callback pro mock `cfgItem()`, který
 *     katalogy vrství nad existující mock configu (ostatní id deleguje).
 */
final class ShippedFeedTexts
{
    /** cfgItem id → cesta dodávaného katalogu relativně k `modules/`. */
    public const array CATALOGS = [
        'core.mail.feedTexts'     => 'core/mail/config/feedTexts.jsonc',
        'core.alerts.feedTexts'   => 'core/alerts/config/feedTexts.jsonc',
        'core.exchange.feedTexts' => 'core/exchange/config/feedTexts.jsonc',
    ];

    /** @return array<string, array<string, mixed>> cfgItem id → lokalizovaný katalog */
    public static function localized(string $language): array
    {
        $root = dirname(__DIR__, 4) . '/modules/';
        $out = [];
        foreach (self::CATALOGS as $id => $file) {
            $raw = JsoncParser::parseFile($root . $file);
            if (!is_array($raw)) {
                throw new \RuntimeException("Feed texts catalog '{$id}' is not an object: {$file}");
            }
            $out[$id] = ConfigLocalizer::localize($raw, $language);
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $items další cfgItemy (id → data), už lokalizované
     */
    public static function config(string $language, array $items = []): ConfigRuntime
    {
        return ConfigRuntimeFactory::fromItems(self::localized($language) + $items);
    }

    /** Callback pro `$mock->method('cfgItem')->willReturnCallback(...)`. */
    public static function resolver(string $language, ?ConfigRuntime $inner = null): \Closure
    {
        $catalogs = self::localized($language);
        return static fn(string $id): mixed => $catalogs[$id] ?? $inner?->cfgItem($id);
    }
}

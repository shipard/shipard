<?php

declare(strict_types=1);

namespace Shipard\Core\Feed;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Logging\ErrorLogger;

/**
 * Texty karet feedu z katalogu modulu (cfgItem `*.feedTexts`, #101 D12–D15).
 *
 * Položka katalogu: klíč → `{text, text:cs, text:en}`; compiled config je
 * per jazyk, pole `text` přijde už lokalizované. Vzor je ICU MessageFormat
 * — plurály a `{param}` se stejnou syntaxí jako frontend
 * (`frontend/src/i18n/cs.js`), locale formátování = jazyk feedu.
 *
 * Omezení PHP intl: netypované `{n}` předá PHP jako řetězec, plurálový
 * selektor jako číslo — tentýž argument v obou rolích skončí
 * `U_ARGUMENT_TYPE_MISMATCH`. Uvnitř plurálu proto vždy `#` (formátované
 * číslo v locale, v `cs` s nezlomitelnou mezerou u tisíců). Apostrof je
 * v ICU escape znak: v anglických textech `''` — platí i pro fallbacky
 * v PHP, i ty jdou přes ICU.
 *
 * Degradace: bez compiled configu nebo bez cfgItemu (DS před `ds-upgrade`)
 * → anglický `$fallback` volajícího, bez logu (běžný stav; dashboard
 * i badge sekcí se pollují). Chybějící klíč v existujícím cfgItemu →
 * fallback + warning, jednou per klíč a instanci (instance = jeden sběr
 * zdroje). Nevalidní vzor → warning + fallback; selže-li i ten, vzor beze
 * změny. Nikdy výjimka — `FeedCollector` by jinak izoloval celý zdroj
 * a feed by přišel o všechny jeho karty.
 */
final class FeedTexts
{
    /** @var array<string, true> klíče (`klíč|důvod`), pro které instance už varovala */
    private array $warned = [];

    public function __construct(
        private readonly ?ConfigRuntime $config,
        private readonly string $cfgItemId,
        private readonly string $language,
    ) {}

    public static function forContext(FeedContext $ctx, string $cfgItemId): self
    {
        return new self($ctx->config, $cfgItemId, $ctx->language);
    }

    /**
     * Text pro klíč katalogu; `$fallback` je anglický ICU vzor volajícího.
     *
     * @param array<string, string|int|float> $params  počty pro plurály jako int
     */
    public function t(string $key, string $fallback, array $params = []): string
    {
        $pattern = $this->pattern($key) ?? $fallback;

        $out = $this->format($pattern, $params);
        if ($out !== null) {
            return $out;
        }
        $this->warnOnce($key, 'invalid', 'Feed text pattern is invalid', ['pattern' => $pattern]);

        if ($pattern !== $fallback) {
            $out = $this->format($fallback, $params);
            if ($out !== null) {
                return $out;
            }
        }
        return $pattern;
    }

    /** Lokalizovaný vzor z katalogu; null = použít fallback. */
    private function pattern(string $key): ?string
    {
        $catalog = $this->config?->cfgItem($this->cfgItemId);
        if (!is_array($catalog)) {
            return null; // bez configu / bez cfgItemu — degradace bez logu
        }
        $text = $catalog[$key]['text'] ?? null;
        if (is_string($text) && $text !== '') {
            return $text;
        }
        $this->warnOnce($key, 'missing', 'Feed text key missing in catalog');
        return null;
    }

    /**
     * ICU formátování; null = nevalidní vzor (statické `formatMessage`
     * vrací `false`, konstruktor by vyhodil `IntlException`).
     *
     * @param array<string, string|int|float> $params
     */
    private function format(string $pattern, array $params): ?string
    {
        try {
            $out = \MessageFormatter::formatMessage($this->language, $pattern, $params);
        } catch (\Throwable) {
            return null;
        }
        return is_string($out) ? $out : null;
    }

    /** @param array<string, mixed> $ctx */
    private function warnOnce(string $key, string $reason, string $message, array $ctx = []): void
    {
        $dedupe = $key . '|' . $reason;
        if (isset($this->warned[$dedupe])) {
            return;
        }
        $this->warned[$dedupe] = true;
        ErrorLogger::warn($message, [
            'cfgItem'  => $this->cfgItemId,
            'key'      => $key,
            'language' => $this->language,
        ] + $ctx);
    }
}

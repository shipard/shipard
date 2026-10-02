<?php

declare(strict_types=1);

namespace Shipard\Core\Prints;

use Shipard\Core\Logging\ErrorLogger;

/**
 * Překlady tisku (#90 D8) — sloučený katalog klíčovaný identifikátorem,
 * ne českým textem. Čte ho builder (titulek, název souboru, hlášení)
 * i šablona (funkce `t()`).
 *
 * Chybějící jazyk → `cs`; chybějící klíč → vrátí klíč a zaloguje warning
 * (tisk kvůli překlepu v katalogu nespadne, ale chyba je vidět).
 */
final class PrintTranslator
{
    private const FALLBACK_LANGUAGE = 'cs';

    /** @param array<string, array<string, string>> $messages klíč → jazyk → text */
    public function __construct(
        private readonly array $messages,
        public readonly string $language,
    ) {}

    public function has(string $key): bool
    {
        return $this->lookup($key) !== null;
    }

    /**
     * @param array<string, scalar|null> $params Hodnoty za `{název}` v textu.
     */
    public function t(string $key, array $params = []): string
    {
        $text = $this->lookup($key);
        if ($text === null) {
            ErrorLogger::warn('prints: missing translation key', ['key' => $key, 'language' => $this->language]);
            return $key;
        }
        if ($params === []) {
            return $text;
        }
        $replace = [];
        foreach ($params as $name => $value) {
            $replace['{' . $name . '}'] = (string) $value;
        }
        return strtr($text, $replace);
    }

    private function lookup(string $key): ?string
    {
        $variants = $this->messages[$key] ?? null;
        if ($variants === null) {
            return null;
        }
        return $variants[$this->language] ?? $variants[self::FALLBACK_LANGUAGE] ?? null;
    }
}

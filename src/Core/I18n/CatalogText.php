<?php

declare(strict_types=1);

namespace Shipard\Core\I18n;

/**
 * Text z katalogu popisků tvaru `{<klíč>: {name: "..."}}` — viewery
 * (`*.viewerLabels`, `*.viewerDetailLabels`) i formuláře (`*.formTexts`,
 * #150 D87). Katalog je cfgItem lokalizovaný kompilací konfigurace
 * (`ConfigLocalizer`): `name` už je v jazyce požadavku, tady se jazyk
 * neřeší.
 *
 * Jediné místo, kde se z definic bere text, nahrazují parametry `{param}`
 * (prostý `str_replace`, žádné ICU — plurály sem nepatří) a vrací anglický
 * fallback: bez katalogu (nezkompilovaná konfigurace) i pro chybějící klíč.
 */
final class CatalogText
{
    /**
     * @param mixed $defs definice `{<klíč>: {name}}`; cokoli jiného než pole = katalog chybí
     * @param array<string, scalar> $params
     */
    public static function resolve(mixed $defs, string $key, string $fallback, array $params = []): string
    {
        $text = is_array($defs) ? (string) ($defs[$key]['name'] ?? $fallback) : $fallback;
        foreach ($params as $name => $value) {
            $text = str_replace('{' . $name . '}', (string) $value, $text);
        }
        return $text;
    }
}

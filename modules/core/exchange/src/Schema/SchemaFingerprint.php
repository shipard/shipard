<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Schema;

/**
 * Otisk JSON schématu (#166 D65, D66): sha256 nad kanonickým zápisem —
 * klíče objektů seřazené, pořadí prvků seznamů zachované, bez bílých znaků.
 * Stejné schéma tak dá stejný otisk ze šablony profilu v repu (JSONC),
 * z řetězce uloženého ve zdroji dat i z dekódovaného objektu; na formátování
 * ani na pořadí klíčů nezáleží. Prázdný objekt a prázdný seznam se
 * nerozlišují (šablona profilu se čte jako PHP pole).
 *
 * Jeden otisk čte strážní test verze promptu (`ProfileSchemaDriftTest`)
 * i záznam běhu evaluace (`effective.schemaHash`), takže jdou porovnat.
 */
final class SchemaFingerprint
{
    private function __construct()
    {
    }

    /**
     * @param array<int|string, mixed>|\stdClass|string|null $schema Schéma
     *        jako pole, objekt nebo text JSON.
     * @return string|null Otisk, nebo null, když vstup není objekt JSON.
     */
    public static function of(array|\stdClass|string|null $schema): ?string
    {
        if ($schema instanceof \stdClass || is_array($schema)) {
            $schema = json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        }
        $decoded = is_string($schema) && $schema !== '' ? json_decode($schema, true) : null;
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            return null;
        }

        return hash('sha256', (string) json_encode(
            self::canonical($decoded),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $value = array_map(self::canonical(...), $value);
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        return $value;
    }
}

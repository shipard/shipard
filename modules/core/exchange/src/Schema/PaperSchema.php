<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Schema;

/**
 * Papírová podoba schématu dokladu (#166 D63, D64) — projekce jediného
 * schématu `shpd.docs.document.v1` bez toho, co papírový doklad nenese:
 * reference na záznamy, pole určená skriptům a generátorům, volby uložení.
 * Proti projekci se validuje **jen výstup modelu** při analýze pošty
 * (`output_schema` profilu, `OutputParser`); uložené návrhy a hotové doklady
 * nesou pole mimo ni (reference z obohacení, `_resolve`) a validují se plným
 * schématem.
 *
 * Projekce je mechanická, co do ní nepatří, říká značka ve schématu:
 *
 *  - `"x-paper": false` u vlastnosti — vlastnost se vynechá;
 *  - `"x-paper": { … }` u vlastnosti — nahradí ji uvedené schéma (jednotka
 *    jen textem, účet řádku holým číslem);
 *  - uzel typu „objekt, nebo reference“ (strana, položka řádku) zůstane jen
 *    objektem — z výčtu typů vypadnou `integer` a `string`;
 *  - definice v `$defs`, na které po projekci nic neodkazuje (`Reference`),
 *    se odstraní; značky samotné ve výsledku nejsou.
 *
 * Změna projekce znamená novou verzi promptu profilu pošty (D65) — hlídá
 * `ProfileSchemaDriftTest`.
 */
final class PaperSchema
{
    public const MARKER = 'x-paper';

    private const DOCUMENT_SCHEMA = 'shpd.docs.document.v1.json';
    private const ID_SUFFIX = '.paper.json';
    private const TITLE_SUFFIX = ' — paper form';

    /** Klíčová slova, jejichž hodnota je jedno podschéma. */
    private const SINGLE_SUBSCHEMA = ['items', 'additionalProperties', 'not', 'if', 'then', 'else', 'contains', 'propertyNames'];
    /** Klíčová slova, jejichž hodnota je seznam podschémat. */
    private const SUBSCHEMA_LIST = ['oneOf', 'anyOf', 'allOf', 'prefixItems'];
    /** Klíčová slova, jejichž hodnota je mapa název → podschéma (bez `properties`). */
    private const SUBSCHEMA_MAP = ['$defs', 'patternProperties', 'dependentSchemas'];

    private function __construct()
    {
    }

    /**
     * Papírová podoba schématu dokladu ze souboru v `schemas/`.
     *
     * @return array<string, mixed>
     */
    public static function ofDocument(?string $schemasDir = null): array
    {
        $path = ($schemasDir ?? dirname(__DIR__, 2) . '/schemas') . '/' . self::DOCUMENT_SCHEMA;
        $contents = is_file($path) ? file_get_contents($path) : false;
        $schema = $contents === false ? null : json_decode($contents, true);
        if (!is_array($schema)) {
            throw new \RuntimeException("Exchange schema not found or invalid: {$path}");
        }
        return self::project($schema);
    }

    /**
     * @param array<string, mixed> $schema Schéma s daty jako PHP pole.
     * @return array<string, mixed>
     */
    public static function project(array $schema): array
    {
        $projected = self::pruneDefinitions(self::node($schema, ''));

        if (is_string($projected['$id'] ?? null)) {
            $projected['$id'] = (string) preg_replace('/\.json$/', '', $projected['$id']) . self::ID_SUFFIX;
        }
        if (is_string($projected['title'] ?? null)) {
            $projected['title'] .= self::TITLE_SUFFIX;
        }
        return $projected;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private static function node(array $node, string $path): array
    {
        if (array_key_exists(self::MARKER, $node)) {
            // Značka patří vlastnosti — zpracuje ji rodič v `properties`.
            throw new \InvalidArgumentException(
                "PaperSchema: '" . self::MARKER . "' at '{$path}' is not on a property of an object",
            );
        }

        if (is_array($node['properties'] ?? null)) {
            $node['properties'] = self::properties($node['properties'], $node['required'] ?? [], $path);
        }
        foreach (self::SINGLE_SUBSCHEMA as $keyword) {
            if (is_array($node[$keyword] ?? null)) {
                $node[$keyword] = self::node($node[$keyword], "{$path}/{$keyword}");
            }
        }
        foreach (self::SUBSCHEMA_LIST as $keyword) {
            if (is_array($node[$keyword] ?? null)) {
                foreach ($node[$keyword] as $i => $sub) {
                    if (is_array($sub)) {
                        $node[$keyword][$i] = self::node($sub, "{$path}/{$keyword}/{$i}");
                    }
                }
            }
        }
        foreach (self::SUBSCHEMA_MAP as $keyword) {
            if (is_array($node[$keyword] ?? null)) {
                foreach ($node[$keyword] as $name => $sub) {
                    if (is_array($sub)) {
                        $node[$keyword][$name] = self::node($sub, "{$path}/{$keyword}/{$name}");
                    }
                }
            }
        }

        if (is_array($node['type'] ?? null) && in_array('object', $node['type'], true)) {
            // „Objekt, nebo reference“ → jen objekt (strana, položka řádku).
            $node['type'] = array_values(array_filter(
                $node['type'],
                static fn (mixed $type): bool => $type !== 'integer' && $type !== 'string',
            ));
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $properties
     * @return array<string, mixed>
     */
    private static function properties(array $properties, mixed $required, string $path): array
    {
        $kept = [];
        foreach ($properties as $name => $property) {
            $propertyPath = "{$path}/properties/{$name}";
            if (!is_array($property)) {
                $kept[$name] = $property;
                continue;
            }
            if (array_key_exists(self::MARKER, $property)) {
                $marker = $property[self::MARKER];
                if ($marker === false) {
                    if (is_array($required) && in_array($name, $required, true)) {
                        throw new \InvalidArgumentException(
                            "PaperSchema: required property '{$propertyPath}' cannot be dropped from the paper form",
                        );
                    }
                    continue;
                }
                if (!is_array($marker) || $marker === [] || array_is_list($marker)) {
                    throw new \InvalidArgumentException(
                        "PaperSchema: '" . self::MARKER . "' at '{$propertyPath}' must be false or a schema object",
                    );
                }
                $property = $marker;
            }
            $kept[$name] = self::node($property, $propertyPath);
        }
        return $kept;
    }

    /**
     * Odstraní definice, na které z kořene (přes zbylé definice) nic neodkazuje.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private static function pruneDefinitions(array $schema): array
    {
        if (!is_array($schema['$defs'] ?? null)) {
            return $schema;
        }
        $definitions = $schema['$defs'];
        $root = $schema;
        unset($root['$defs']);

        $reachable = [];
        $queue = self::references($root);
        while ($queue !== []) {
            $name = array_shift($queue);
            if (isset($reachable[$name]) || !is_array($definitions[$name] ?? null)) {
                continue;
            }
            $reachable[$name] = true;
            array_push($queue, ...self::references($definitions[$name]));
        }

        $schema['$defs'] = array_intersect_key($definitions, $reachable);
        if ($schema['$defs'] === []) {
            unset($schema['$defs']);
        }
        return $schema;
    }

    /**
     * Názvy definic, na které uzel odkazuje (`#/$defs/<název>`).
     *
     * @param array<int|string, mixed> $node
     * @return list<string>
     */
    private static function references(array $node): array
    {
        $names = [];
        array_walk_recursive($node, static function (mixed $value, int|string $key) use (&$names): void {
            if ($key === '$ref' && is_string($value) && str_starts_with($value, '#/$defs/')) {
                $names[] = substr($value, strlen('#/$defs/'));
            }
        });
        return $names;
    }
}

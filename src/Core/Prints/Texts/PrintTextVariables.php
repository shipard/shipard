<?php

declare(strict_types=1);

namespace Shipard\Core\Prints\Texts;

use Shipard\Core\Prints\PrintCatalogLoader;
use Shipard\Core\Prints\PrintDefinition;
use Shipard\Core\Prints\PrintTemplatePaths;
use Shipard\Core\Utils\JsoncParser;

/**
 * Kurátorský seznam proměnných pro texty na tiscích (#90 D51). Text vidí
 * celé `data` a `meta` z `PrintData`; tady je výběr, který formulář textu
 * nabídne — cesta, popisek a ukázka zápisu s doporučeným filtrem.
 *
 * Tisk proměnné deklaruje v `textVariables`: přímo (`{path, filter?}`), nebo
 * odkazem na sdílenou sadu `@<modul>/<adresář>` — soubor
 * `text-variables.jsonc` v adresáři tisku (doklady: `@docs.core/_layout`).
 * Popisky jsou v katalozích tisku pod klíčem `var.<cesta>`.
 */
final class PrintTextVariables
{
    /** Soubor sdílené sady v adresáři tisku. */
    public const SET_FILE = 'text-variables.jsonc';

    /** Prefix klíče katalogu s popiskem proměnné. */
    public const LABEL_PREFIX = 'var.';

    /** Proměnná: `data.…` / `meta.…` (tečkovaná cesta) nebo `language`. */
    private const PATH = '/^(?:language|(?:data|meta)(?:\.[A-Za-z][A-Za-z0-9_]*)+)$/';

    /** Filtr z politiky uživatelských textů, volitelně s argumenty. */
    private const FILTER = '/^(?:money|qty|pct|date|default|upper|lower)(?:\([A-Za-z0-9_.\', ]*\))?$/';

    public function __construct(
        private readonly PrintTemplatePaths $paths,
    ) {}

    /**
     * Položka seznamu z deklarace nebo ze sady: řetězec (cesta) nebo
     * `{path, filter?}`.
     *
     * @return array{path: string, filter: ?string}
     * @throws \InvalidArgumentException Položka nemá očekávaný tvar.
     */
    public static function parse(mixed $entry): array
    {
        $path   = is_array($entry) ? ($entry['path'] ?? null) : $entry;
        $filter = is_array($entry) ? ($entry['filter'] ?? null) : null;

        if (!is_string($path) || !preg_match(self::PATH, $path)) {
            throw new \InvalidArgumentException(
                "text variable must be a path like 'data.document.number', 'meta.title' or 'language'",
            );
        }
        if ($filter !== null && (!is_string($filter) || !preg_match(self::FILTER, $filter))) {
            throw new \InvalidArgumentException(
                "text variable '{$path}': 'filter' must be one of money|qty|pct|date|default|upper|lower",
            );
        }
        return ['path' => $path, 'filter' => $filter];
    }

    /**
     * Proměnné tisku: sdílené sady v pořadí deklarace, pak vlastní. Cesta
     * uvedená víckrát platí první.
     *
     * @return list<array{path: string, filter: ?string}>
     * @throws \RuntimeException Sada neexistuje nebo nemá očekávaný tvar.
     */
    public function forPrint(PrintDefinition $definition): array
    {
        $variables = [];
        foreach ($definition->textVariableSets as $set) {
            foreach ($this->loadSet($definition, $set) as $variable) {
                $variables[$variable['path']] ??= $variable;
            }
        }
        foreach ($definition->textVariables as $variable) {
            $variables[$variable['path']] ??= $variable;
        }
        return array_values($variables);
    }

    /**
     * Proměnné společné všem zadaným tiskům (průnik podle cesty, pořadí
     * a filtr prvního tisku) s popiskem v daném jazyce a ukázkou zápisu.
     *
     * @param list<PrintDefinition> $definitions
     * @return list<array{path: string, label: string, example: string}>
     */
    public function describe(array $definitions, PrintCatalogLoader $catalogs, string $language): array
    {
        if ($definitions === []) {
            return [];
        }

        $first  = array_shift($definitions);
        $common = [];
        foreach ($this->forPrint($first) as $variable) {
            $common[$variable['path']] = $variable;
        }
        foreach ($definitions as $definition) {
            $paths  = array_column($this->forPrint($definition), 'path', 'path');
            $common = array_intersect_key($common, $paths);
        }

        $translator = $catalogs->translator($first, $language);

        $described = [];
        foreach ($common as $variable) {
            $key = self::LABEL_PREFIX . $variable['path'];
            $described[] = [
                'path'    => $variable['path'],
                'label'   => $translator->has($key) ? $translator->t($key) : $variable['path'],
                'example' => self::example($variable['path'], $variable['filter']),
            ];
        }
        return $described;
    }

    /** Zápis proměnné do textu: `{{ cesta }}` nebo `{{ cesta|filtr }}`. */
    public static function example(string $path, ?string $filter): string
    {
        return '{{ ' . $path . ($filter === null ? '' : '|' . $filter) . ' }}';
    }

    /** @return list<array{path: string, filter: ?string}> */
    private function loadSet(PrintDefinition $definition, string $set): array
    {
        $dir  = $this->paths->directory($set);
        $file = $dir === null ? null : $dir . '/' . self::SET_FILE;
        if ($file === null || !is_file($file)) {
            throw new \RuntimeException(
                "Print '{$definition->id}': text variable set '{$set}' has no " . self::SET_FILE,
            );
        }

        $entries = JsoncParser::parseFile($file);
        if (!is_array($entries) || !array_is_list($entries)) {
            throw new \RuntimeException("Text variable set '{$set}' must be an array of variables");
        }

        $variables = [];
        foreach ($entries as $entry) {
            try {
                $variables[] = self::parse($entry);
            } catch (\InvalidArgumentException $e) {
                throw new \RuntimeException("Text variable set '{$set}': " . $e->getMessage(), 0, $e);
            }
        }
        return $variables;
    }
}

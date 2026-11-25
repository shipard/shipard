<?php

declare(strict_types=1);

namespace Shipard\Core\Module;

use Shipard\Core\Utils\JsoncParser;

/**
 * Generovaný rozcestník modulů `modules/README.md` (#170 D7): blok mezi
 * značkami se všemi moduly hlavního stromu po druzích — Instalační moduly,
 * Jádro (po skupinách), Doplňky. Řádek nese ID s odkazem na `README.md`
 * modulu (když existuje, jinak na `module.jsonc`), název a popis česky.
 *
 * Volá ho `scripts/modules-index.php` (zápis a `--check` pro pre-commit
 * hook) a test shody nad skutečným stromem; ruční text nad blokem a pod ním
 * se nemění.
 */
final class ModulesIndex
{
    public const string BEGIN = '<!-- MODULY:BEGIN — generováno scripts/modules-index.php, needituj ručně -->';
    public const string END = '<!-- MODULY:END -->';

    /** Jazyk názvů a popisů v rozcestníku. */
    private const string LANGUAGE = 'cs';

    private function __construct()
    {
    }

    /**
     * Moduly kořene seřazené podle ID.
     *
     * @return list<array{id: string, kind: ModuleKind, name: string, description: string, link: string}>
     *         `link` je cesta relativní ke kořeni modulů
     */
    public static function collect(string $modulesRoot): array
    {
        $resolver = new ModulePathResolver([$modulesRoot]);

        $entries = [];
        foreach ($resolver->allModuleIds() as $id) {
            $path = (string) $resolver->getPath($id);
            $raw = JsoncParser::parseFile($path . '/module.jsonc');
            $definition = ModuleDefinition::fromArray($raw);

            $entries[] = [
                'id' => $id,
                'kind' => $definition->kind,
                'name' => self::localized($raw, 'name'),
                'description' => self::localized($raw, 'description'),
                'link' => str_replace('.', '/', $id) . '/' . (is_file($path . '/README.md') ? 'README.md' : 'module.jsonc'),
            ];
        }
        return $entries;
    }

    /**
     * Blok rozcestníku včetně obou značek.
     *
     * @param list<array{id: string, kind: ModuleKind, name: string, description: string, link: string}> $entries
     */
    public static function block(array $entries): string
    {
        usort($entries, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));

        $byKind = [ModuleKind::Install->value => [], ModuleKind::Core->value => [], ModuleKind::Addon->value => []];
        foreach ($entries as $entry) {
            $byKind[$entry['kind']->value][] = $entry;
        }

        $lines = [self::BEGIN, ''];

        $lines[] = '## Instalační moduly';
        $lines[] = '';
        array_push($lines, ...self::table($byKind[ModuleKind::Install->value], 'Zatím žádný instalační modul.'));

        $lines[] = '## Jádro';
        $lines[] = '';
        $groups = [];
        foreach ($byKind[ModuleKind::Core->value] as $entry) {
            $groups[strstr($entry['id'], '.', true)][] = $entry;
        }
        if ($groups === []) {
            $lines[] = 'Zatím žádný modul jádra.';
            $lines[] = '';
        }
        foreach ($groups as $group => $groupEntries) {
            $lines[] = "### `{$group}`";
            $lines[] = '';
            array_push($lines, ...self::table($groupEntries, ''));
        }

        $lines[] = '## Doplňky';
        $lines[] = '';
        array_push($lines, ...self::table($byKind[ModuleKind::Addon->value], 'Zatím žádný doplněk.'));

        $lines[] = self::END;
        return implode("\n", $lines);
    }

    /**
     * Dokument s blokem mezi značkami nahrazeným novým blokem.
     *
     * @throws \RuntimeException když dokument značky nemá (nebo v obráceném pořadí)
     */
    public static function apply(string $document, string $block): string
    {
        $begin = strpos($document, self::BEGIN);
        $end = strpos($document, self::END);
        if ($begin === false || $end === false || $end < $begin) {
            throw new \RuntimeException('markers ' . self::BEGIN . ' … ' . self::END . ' not found');
        }
        return substr($document, 0, $begin) . $block . substr($document, $end + strlen(self::END));
    }

    /**
     * @param list<array{id: string, name: string, description: string, link: string}> $entries
     * @return list<string> řádky tabulky zakončené prázdným řádkem
     */
    private static function table(array $entries, string $emptyText): array
    {
        if ($entries === []) {
            return [$emptyText, ''];
        }
        $lines = ['| Modul | Název | Popis |', '|-------|-------|-------|'];
        foreach ($entries as $entry) {
            $lines[] = sprintf(
                '| [`%s`](%s) | %s | %s |',
                $entry['id'],
                $entry['link'],
                self::cell($entry['name']),
                self::cell($entry['description']),
            );
        }
        $lines[] = '';
        return $lines;
    }

    /** @param array<string, mixed> $raw */
    private static function localized(array $raw, string $field): string
    {
        $value = $raw[$field . ':' . self::LANGUAGE] ?? $raw[$field] ?? '';
        return is_string($value) ? $value : '';
    }

    /** Text do buňky tabulky: jeden řádek, svislítko escapované. */
    private static function cell(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        return str_replace('|', '\\|', $text);
    }
}

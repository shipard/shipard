<?php

declare(strict_types=1);

namespace Shipard\Core\Prints;

use Shipard\Core\Utils\JsoncParser;

/**
 * Načte a sloučí katalogy překladů tisku: nejdřív katalogy z `catalogs`
 * deklarace (sdílené klíče layoutu), pak `messages.jsonc` z adresáře
 * šablony — pozdější klíč má přednost.
 *
 * Soubor = objekt `{ "klíč": { "cs": "…", "en": "…" } }`. Katalog uvedený
 * v `catalogs` musí existovat; katalog šablony je nepovinný.
 */
final class PrintCatalogLoader
{
    public const FILE_NAME = 'messages.jsonc';

    public function __construct(
        private readonly PrintTemplatePaths $paths,
    ) {}

    public function translator(PrintDefinition $definition, string $language): PrintTranslator
    {
        return new PrintTranslator($this->messages($definition), $language);
    }

    /** @return array<string, array<string, string>> klíč → jazyk → text */
    public function messages(PrintDefinition $definition): array
    {
        $messages = [];
        foreach ($definition->catalogs as $catalog) {
            $messages = array_replace($messages, $this->loadCatalog($definition, $catalog, required: true));
        }
        return array_replace($messages, $this->loadCatalog($definition, $definition->template, required: false));
    }

    /** @return array<string, array<string, string>> */
    private function loadCatalog(PrintDefinition $definition, string $path, bool $required): array
    {
        $dir  = $this->paths->directory($path);
        $file = $dir === null ? null : $dir . '/' . self::FILE_NAME;
        if ($file === null || !is_file($file)) {
            if ($required) {
                throw new \RuntimeException(
                    "Print '{$definition->id}': catalog '{$path}' has no " . self::FILE_NAME,
                );
            }
            return [];
        }

        $raw = JsoncParser::parseFile($file);
        if (!is_array($raw)) {
            throw new \RuntimeException("Print catalog '{$file}' must contain an object");
        }

        $messages = [];
        foreach ($raw as $key => $variants) {
            if (!is_string($key) || !is_array($variants)) {
                throw new \RuntimeException(
                    "Print catalog '{$file}': each key must map languages to texts",
                );
            }
            foreach ($variants as $language => $text) {
                if (!is_string($language) || !is_string($text)) {
                    throw new \RuntimeException(
                        "Print catalog '{$file}': key '{$key}' must map languages to texts",
                    );
                }
            }
            $messages[$key] = $variants;
        }
        return $messages;
    }
}

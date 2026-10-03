<?php

declare(strict_types=1);

namespace Shipard\Core\Config;

use Shipard\Core\I18n\ConfigLocalizer;
use Shipard\Core\Module\ModuleDefinition;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Server\PermissionSpec;
use Shipard\Core\StructuredFields\StructuredSchemaValidator;
use Shipard\Core\Utils\JsoncParser;

class ConfigCompiler
{
    /**
     * Verze formátu kompilovaného configu — nezávislá na verzi aplikace
     * (Shipard\Core\Version). Bumpovat při změně struktury kompilátu.
     */
    private const VERSION = '0.1.0';

    /** cfgItem složený z `journalDimensions` aktivních modulů (JournalDimensionSet). */
    public const JOURNAL_DIMENSIONS_ITEM = 'core.accounting.journalDimensions';

    /** Jazyky rozhraní — kompilují se vždy. */
    public const UI_LANGUAGES = ['cs', 'en'];

    /** cfgItem s jazyky dokumentů (#94 D3); klíč = jazyk. */
    public const DOCUMENT_LANGUAGES_ITEM = 'world.base.documentLanguages';

    /**
     * Jazyky kompilace (#90 D29): jazyky rozhraní a jazyky dokumentů — tisk
     * čte konfiguraci v jazyce dokumentu. Jazyky dokumentů se berou ze
     * surových dat cfgItemu, kompilát ještě neexistuje; bez něj (modul není
     * aktivní) zůstanou jen jazyky rozhraní.
     *
     * @param ModuleDefinition[] $modules
     * @return list<string>
     */
    public static function languages(array $modules, ModulePathResolver $resolver): array
    {
        $languages = self::UI_LANGUAGES;

        foreach ($modules as $module) {
            $modulePath = $resolver->getPath($module->id);
            if ($modulePath === null) continue;

            foreach ($module->config as $entry) {
                if ($entry['id'] !== self::DOCUMENT_LANGUAGES_ITEM) continue;

                $raw = JsoncParser::parseFile($modulePath . '/' . $entry['file']);
                foreach (array_keys(is_array($raw) ? $raw : []) as $language) {
                    $languages[] = (string) $language;
                }
            }
        }

        return array_values(array_unique($languages));
    }

    /**
     * @param ModuleDefinition[]     $modules  Resolved modules in dependency order
     * @param array<string, string>  $structuredSchemas cfgItem => původ
     *        (`tabulka.sloupec`) pro každý cfgItem, na který ukazuje atribut
     *        `schema` sloupce typu `json` (#74). Kompilátor u nich vynutí
     *        existenci i formát — obojí je chyba `ds-upgrade`, ne warning:
     *        sloupec bez validovatelného schématu by se ukládal bez validace.
     * @param array<string, ?string>|null $tableDisplayPatterns tabulka =>
     *        `displayPattern` všech tabulek DS. Dimenze deníku si z něj
     *        převezme vzor popisku cílové tabulky; dimenze mířící na
     *        neznámou tabulku je chyba. Null = volající tabulky nezná
     *        (testy) — dimenze zůstanou bez vzoru a bez kontroly.
     * @throws \RuntimeException když schéma chybí nebo neprojde validací,
     *         dimenze deníku míří na neznámou tabulku, nebo nejde založit
     *         výstupní adresář
     */
    public static function compile(
        array $modules,
        ModulePathResolver $resolver,
        array $languages,
        string $outputPath,
        array $structuredSchemas = [],
        ?array $tableDisplayPatterns = null,
    ): void {
        $rawItems = [];
        $moduleIds = [];

        foreach ($modules as $module) {
            $moduleIds[] = $module->id;
            $modulePath = $resolver->getPath($module->id);
            if ($modulePath === null) continue;

            foreach ($module->config as $entry) {
                $cfgId = $entry['id'];
                $filePath = $modulePath . '/' . $entry['file'];
                $rawItems[$cfgId] = JsoncParser::parseFile($filePath);
            }
        }

        // Dimenze deníku (`journalDimensions` v module.jsonc) aktivních
        // modulů → jeden cfgItem. Čte ho JournalDimensionSet::fromConfig —
        // engine, viewery i formuláře mají konfiguraci, žádná injektáž.
        $dimensions = [];
        foreach ($modules as $module) {
            foreach ($module->journalDimensions as $dimension) {
                $dimensionId = (string) $dimension['id'];
                if (isset($dimensions[$dimensionId])) {
                    throw new \RuntimeException(
                        "Journal dimension '{$dimensionId}' is declared by more than one module"
                        . " (again in '{$module->id}')",
                    );
                }
                if ($tableDisplayPatterns !== null) {
                    $table = (string) $dimension['table'];
                    if (!array_key_exists($table, $tableDisplayPatterns)) {
                        throw new \RuntimeException(
                            "Journal dimension '{$dimensionId}' (module '{$module->id}') references unknown table"
                            . " '{$table}' — is the module that declares it active?",
                        );
                    }
                    $dimension['displayPattern'] = $tableDisplayPatterns[$table];
                }
                $dimensions[$dimensionId] = $dimension;
            }
        }
        if (isset($rawItems[self::JOURNAL_DIMENSIONS_ITEM])) {
            throw new \RuntimeException(
                "cfgItem '" . self::JOURNAL_DIMENSIONS_ITEM . "' is reserved for journalDimensions",
            );
        }
        $rawItems[self::JOURNAL_DIMENSIONS_ITEM] = $dimensions;

        // Strukturovaná schémata — validace nad SUROVÝMI daty, tedy před
        // lokalizací: `name:cs` je vícejazyčná varianta, ne neznámý klíč.
        foreach ($structuredSchemas as $cfgId => $origin) {
            if (!isset($rawItems[$cfgId])) {
                throw new \RuntimeException(
                    "Column '{$origin}' references unknown schema cfgItem '{$cfgId}'"
                    . ' — is the module that declares it active?',
                );
            }
            StructuredSchemaValidator::validate((string) $cfgId, $rawItems[$cfgId]);
        }

        if (!PermissionSpec::ensureDsDir($outputPath)) {
            throw new \RuntimeException("Cannot create configuration directory '{$outputPath}'");
        }

        foreach ($languages as $language) {
            $localizedItems = [];
            foreach ($rawItems as $cfgId => $rawData) {
                $localizedItems[$cfgId] = ConfigLocalizer::localize($rawData, $language);
            }

            $output = [
                '_meta' => [
                    'compiled' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                    'version' => self::VERSION,
                    'language' => $language,
                    'modules' => $moduleIds,
                ],
                'items' => $localizedItems,
            ];

            file_put_contents(
                $outputPath . '/compiled.' . $language . '.json',
                json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            );
        }
    }
}

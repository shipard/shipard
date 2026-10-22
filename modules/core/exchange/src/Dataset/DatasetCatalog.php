<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset;

/**
 * Katalog sad k seedu z checkoutu repa (#131): testovací sady korpusu
 * `tests/datasets/<země>/<sada>/manifest.jsonc` (druh `test`) a demo sady
 * z příběhu firmy `demo/datasets/<sada>/manifest.jsonc` (druh `demo`;
 * kořenové `README.md`, `build.py`, `stories/` a `templates/` manifest
 * nemají, a tak se přeskočí). Jediný zdroj sad pro pole Data formuláře
 * Nový zdroj dat a pro dialog Nahrát datovou sadu v dashboardu i pro
 * seznam sad pojistky `DevActionGuard`. Sekce Testování (evaluace,
 * běhy, očekávání) má vlastní {@see \Shipard\Module\Core\Mail\Eval\Browse\EvalCatalog}
 * jen nad `tests/datasets` — demo sady nemají `expected/` ani běhy.
 *
 * Název sady je klíč: `dataset-seed` ho zapíše do `main.json` zdroje dat
 * a dashboard podle něj pozná drženou sadu, proto musí být napříč oběma
 * adresáři jednoznačný. Při shodě názvu má přednost testovací sada a demo
 * sada se vynechá (nikdy dvě sady téhož jména). Sada se vybírá **jen
 * z výpisu** ({@see dataset()}); z parametru požadavku se nikdy neskládá cesta.
 */
final class DatasetCatalog
{
    public const string TEST_DIR = 'tests/datasets';
    public const string DEMO_DIR = 'demo/datasets';

    public const string KIND_TEST = 'test';
    public const string KIND_DEMO = 'demo';

    /** @var list<DatasetCatalogEntry>|null */
    private ?array $datasets = null;

    public function __construct(private readonly string $repoRoot) {}

    /** Aspoň jeden z adresářů existuje (instalace z balíčku nemá žádný). */
    public function isAvailable(): bool
    {
        return is_dir($this->repoRoot . '/' . self::TEST_DIR) || is_dir($this->repoRoot . '/' . self::DEMO_DIR);
    }

    /**
     * Testovací sady podle názvu, za nimi demo sady podle názvu; nečitelný
     * manifest se přeskočí, demo sada se jménem testovací také.
     *
     * @return list<DatasetCatalogEntry>
     */
    public function datasets(): array
    {
        if ($this->datasets !== null) {
            return $this->datasets;
        }
        $entries = [];
        $patterns = [
            self::KIND_TEST => self::TEST_DIR . '/*/*',
            self::KIND_DEMO => self::DEMO_DIR . '/*',
        ];
        foreach ($patterns as $kind => $pattern) {
            $byName = [];
            foreach (self::scanManifests($this->repoRoot . '/' . $pattern) as [$manifest, $dir]) {
                if (isset($entries[$manifest->name])) {
                    continue;
                }
                $byName[$manifest->name] = new DatasetCatalogEntry(
                    name: $manifest->name,
                    title: $manifest->title,
                    description: $manifest->description,
                    kind: $kind,
                    country: $manifest->country,
                    profile: $manifest->profile,
                    dir: $dir,
                );
            }
            ksort($byName, SORT_STRING);
            $entries += $byName;
        }
        return $this->datasets = array_values($entries);
    }

    /** Sada podle názvu — jen přesná shoda s výpisem. */
    public function dataset(string $name): ?DatasetCatalogEntry
    {
        foreach ($this->datasets() as $dataset) {
            if ($dataset->name === $name) {
                return $dataset;
            }
        }
        return null;
    }

    /** @return list<string> názvy sad obou druhů — seznam pro pojistku spouštěcích akcí (D60) */
    public function datasetNames(): array
    {
        return array_map(static fn (DatasetCatalogEntry $d): string => $d->name, $this->datasets());
    }

    /**
     * Manifesty sad v adresářích podle glob vzoru (bez `/manifest.jsonc`);
     * adresář bez manifestu nebo s nečitelným manifestem se přeskočí.
     * Sdílí ji {@see \Shipard\Module\Core\Mail\Eval\Browse\EvalCatalog}.
     *
     * @return list<array{0: DatasetManifest, 1: string}> manifest a absolutní adresář sady, v pořadí globu
     */
    public static function scanManifests(string $dirPattern): array
    {
        $found = [];
        foreach (glob($dirPattern . '/' . DatasetReader::MANIFEST_FILE) ?: [] as $manifestPath) {
            $dir = dirname($manifestPath);
            try {
                $manifest = DatasetReader::open($dir)->getManifest();
            } catch (\Throwable) {
                continue;
            }
            $found[] = [$manifest, $dir];
        }
        return $found;
    }
}

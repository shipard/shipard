<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Eval\Browse;

use Shipard\Module\Core\Exchange\Dataset\DatasetReader;
use Shipard\Module\Core\Mail\Eval\EvalRunStore;

/**
 * Katalog prohlížeče výsledků evaluací (#103 D52): zdrojem pravdy jsou
 * soubory v checkoutu repa — sady `tests/datasets/<země>/<sada>/manifest.jsonc`
 * a běhy `tests/eval/<název sady>/*.json`. Server bez obou adresářů
 * (instalace z balíčku) sekci nenabídne ({@see isAvailable()}).
 *
 * Bezpečnost cest: sada, běh i zpráva se vybírají **jen z výpisu** — název
 * sady se hledá mezi manifesty, soubor běhu mezi `basename` souborů
 * z `EvalRunStore::runs()`. Z parametru URL se nikdy neskládá cesta.
 */
final class EvalCatalog
{
    public const string DATASETS_DIR = 'tests/datasets';
    public const string EVAL_DIR = 'tests/eval';

    /** @var list<EvalDatasetInfo>|null */
    private ?array $datasets = null;

    public function __construct(private readonly string $repoRoot) {}

    public function isAvailable(): bool
    {
        return is_dir($this->repoRoot . '/' . self::DATASETS_DIR) && is_dir($this->repoRoot . '/' . self::EVAL_DIR);
    }

    /**
     * Sady podle názvu z manifestu; nečitelný manifest se přeskočí.
     *
     * @return list<EvalDatasetInfo>
     */
    public function datasets(): array
    {
        if ($this->datasets !== null) {
            return $this->datasets;
        }
        $byName = [];
        foreach (glob($this->repoRoot . '/' . self::DATASETS_DIR . '/*/*/' . DatasetReader::MANIFEST_FILE) ?: [] as $manifestPath) {
            $dir = dirname($manifestPath);
            try {
                $manifest = DatasetReader::open($dir)->getManifest();
            } catch (\Throwable) {
                continue;
            }
            $byName[$manifest->name] = new EvalDatasetInfo(
                name: $manifest->name,
                title: $manifest->title,
                country: $manifest->country,
                profile: $manifest->profile,
                messages: (int) ($manifest->counts['mail'] ?? 0),
                dir: $dir,
            );
        }
        ksort($byName, SORT_STRING);
        return $this->datasets = array_values($byName);
    }

    public function dataset(string $name): ?EvalDatasetInfo
    {
        foreach ($this->datasets() as $dataset) {
            if ($dataset->name === $name) {
                return $dataset;
            }
        }
        return null;
    }

    public function runStore(EvalDatasetInfo $dataset): EvalRunStore
    {
        return new EvalRunStore(EvalRunStore::defaultDir($this->repoRoot, $dataset->name));
    }

    /**
     * Běhy sady, nejnovější první; každý s `_file` (absolutní) a `_basename`.
     *
     * @return list<array<string, mixed>>
     */
    public function runs(EvalDatasetInfo $dataset): array
    {
        $runs = $this->runStore($dataset)->runs();
        foreach ($runs as &$run) {
            $run['_basename'] = basename((string) $run['_file']);
        }
        unset($run);
        return $runs;
    }

    /** Běh podle názvu souboru — jen přesná shoda s výpisem. @return array<string, mixed>|null */
    public function run(EvalDatasetInfo $dataset, string $file): ?array
    {
        foreach ($this->runs($dataset) as $run) {
            if ($run['_basename'] === $file) {
                return $run;
            }
        }
        return null;
    }

    /** Hash aktuálního obsahu sady (`mail/` + `expected/`) — běhy s jiným hashem jsou nad starší verzí sady. */
    public function currentHash(EvalDatasetInfo $dataset): string
    {
        return EvalRunStore::datasetHash($dataset->reader());
    }
}

<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Eval\Browse;

use Shipard\Module\Core\Exchange\Dataset\DatasetCatalog;
use Shipard\Module\Core\Mail\Eval\EvalRunStore;
use Shipard\Module\Core\Mail\Eval\EvalVariantStore;

/**
 * Katalog prohlížeče výsledků evaluací (#103 D52): zdrojem pravdy jsou
 * soubory v checkoutu repa — sady `tests/datasets/<země>/<sada>/manifest.jsonc`
 * a běhy `tests/eval/<název sady>/*.json`. Server bez obou adresářů
 * (instalace z balíčku) sekci nenabídne ({@see isAvailable()}).
 *
 * Bezpečnost cest: sada, běh i zpráva se vybírají **jen z výpisu** — název
 * sady se hledá mezi manifesty, soubor běhu mezi `basename` souborů
 * z `EvalRunStore::runs()`. Z parametru URL se nikdy neskládá cesta.
 *
 * Sady **k seedu** (formulář Nový zdroj dat, dialog Nahrát datovou sadu)
 * dodává {@see DatasetCatalog} — ten přibírá i demo sady z `demo/datasets`
 * (#131); tenhle katalog zůstává jen nad `tests/datasets`, protože demo
 * sady nemají `expected/` ani běhy.
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
        foreach (DatasetCatalog::scanManifests($this->repoRoot . '/' . self::DATASETS_DIR . '/*/*') as [$manifest, $dir]) {
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

    /** Soubory variant `tests/eval/variants/` (editor variant a spouštění evaluace, F6b). */
    public function variantStore(): EvalVariantStore
    {
        return new EvalVariantStore(EvalVariantStore::defaultDir($this->repoRoot));
    }

    /** @return list<string> názvy sad — seznam pro pojistku spouštěcích akcí (D60) */
    public function datasetNames(): array
    {
        return array_map(static fn (EvalDatasetInfo $d): string => $d->name, $this->datasets());
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

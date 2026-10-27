<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\Round;

use Shipard\Core\Server\DataSourceInventory;
use Shipard\Module\Core\Exchange\Dataset\DatasetCatalog;
use Shipard\Module\Core\Exchange\Dataset\DatasetCatalogEntry;
use Shipard\Module\Core\Exchange\Dataset\Video\VideoDataSources;
use Shipard\Module\Core\Exchange\Dataset\Video\VideoScenarioCatalog;
use Shipard\Module\Core\Mail\Eval\Browse\EvalCatalog;

/**
 * Ze výběru sestaví {@see RoundPlan}: vrstva sad = veřejné testovací sady
 * (`tests/datasets`, {@see EvalCatalog} — mají `expected/`; demo sada tam
 * nepatří), nebo adresáře s `manifest.jsonc` (kopie sady mimo repo pro umělé
 * rozbití); vrstva videí = scénáře `demo/scenarios/` a jejich demo sady.
 * Zdroje dat: kola (`testovací kolo — <sada>`, O1) a videí (`videa —
 * <sada>`, D126), chybějící = založit. Neznámá sada nebo scénář ve filtru
 * = {@see RoundException} (kolo nezačne, exit 2); neplatný scénář
 * z katalogu = krok `failed`, kolo pokračuje.
 */
final class RoundPlanner
{
    /**
     * @param \Closure(): list<string> $e2eToolProblems   chybějící nástroje proklikání
     * @param \Closure(): list<string> $videoToolProblems chybějící nástroje videí (vč. Gotenbergu)
     */
    public function __construct(
        private readonly DataSourceInventory $inventory,
        private readonly EvalCatalog $evalCatalog,
        private readonly DatasetCatalog $datasetCatalog,
        private readonly VideoScenarioCatalog $scenarioCatalog,
        private readonly CodeSteps $codeSteps,
        private readonly \Closure $e2eToolProblems,
        private readonly \Closure $videoToolProblems,
    ) {}

    /** @throws RoundException */
    public function plan(RoundSelection $selection): RoundPlan
    {
        $code = $selection->has(RoundSelection::LAYER_CODE) ? $this->codeSteps->plan() : [];
        $datasets = $selection->has(RoundSelection::LAYER_DATASETS) ? $this->datasets($selection->datasets) : [];
        [$videos, $invalid] = $selection->has(RoundSelection::LAYER_VIDEOS) ? $this->videos($selection->scenarios) : [[], []];
        return new RoundPlan(
            $code,
            $datasets,
            $videos,
            $invalid,
            $datasets !== [] ? ($this->e2eToolProblems)() : [],
            $videos !== [] ? ($this->videoToolProblems)() : [],
        );
    }

    /** @param list<string> $filter @return list<RoundDatasetPlan> */
    private function datasets(array $filter): array
    {
        $plans = [];
        if ($filter === []) {
            foreach ($this->evalCatalog->datasets() as $info) {
                $plans[] = $this->datasetPlan($info->name, $info->title, $info->country, $info->dir);
            }
            return $plans;
        }
        $known = $this->evalCatalog->datasetNames();
        foreach ($filter as $token) {
            $info = $this->evalCatalog->dataset($token);
            if ($info !== null) {
                $plans[] = $this->datasetPlan($info->name, $info->title, $info->country, $info->dir);
                continue;
            }
            if (is_dir($token) && is_file(rtrim($token, '/') . '/manifest.jsonc')) {
                $scanned = DatasetCatalog::scanManifests(rtrim($token, '/'));
                if ($scanned === []) {
                    throw new RoundException("Adresář '{$token}' nemá čitelný manifest.jsonc");
                }
                [$manifest, $dir] = $scanned[0];
                $plans[] = $this->datasetPlan($manifest->name, $manifest->title, $manifest->country, $dir);
                continue;
            }
            throw new RoundException("Sada '{$token}' není veřejná testovací sada (tests/datasets: " . implode(', ', $known) . ') ani adresář s manifest.jsonc');
        }
        return $plans;
    }

    private function datasetPlan(string $name, string $title, ?string $country, string $dir): RoundDatasetPlan
    {
        $ds = RoundDataSources::find($this->inventory, $name);
        return new RoundDatasetPlan($name, $title, $country, $dir, $ds?->id, $ds?->name);
    }

    /**
     * @param list<string> $filter
     * @return array{0: list<RoundVideoPlan>, 1: list<RoundScenarioPlan>}
     */
    private function videos(array $filter): array
    {
        $entries = $this->scenarioCatalog->scenarios();
        if ($filter !== []) {
            $byId = [];
            foreach ($entries as $entry) {
                $byId[$entry['id']] = $entry;
            }
            $selected = [];
            foreach ($filter as $id) {
                if (!isset($byId[$id])) {
                    throw new RoundException("Scénář '{$id}' není v " . VideoScenarioCatalog::DIR . ' (' . implode(', ', array_keys($byId)) . ')');
                }
                $selected[] = $byId[$id];
            }
            $entries = $selected;
        }
        $invalid = [];
        /** @var array<string, list<RoundScenarioPlan>> $byDataset */
        $byDataset = [];
        /** @var array<string, DatasetCatalogEntry> $demo */
        $demo = [];
        foreach ($entries as $entry) {
            $scenario = $entry['scenario'];
            if ($scenario === null) {
                $invalid[] = new RoundScenarioPlan($entry['id'], $entry['path'], null, (string) $entry['problem']);
                continue;
            }
            $dataset = $this->datasetCatalog->dataset($scenario->dataset);
            if (!$dataset instanceof DatasetCatalogEntry || $dataset->kind !== DatasetCatalog::KIND_DEMO) {
                $invalid[] = new RoundScenarioPlan($scenario->id, $scenario->path, $scenario->dataset, "Sada '{$scenario->dataset}' scénáře není demo sada v " . DatasetCatalog::DEMO_DIR);
                continue;
            }
            $demo[$dataset->name] = $dataset;
            $byDataset[$dataset->name][] = new RoundScenarioPlan($scenario->id, $scenario->path, $scenario->dataset);
        }
        ksort($byDataset, SORT_STRING);
        $videos = [];
        foreach ($byDataset as $name => $scenarios) {
            $ds = VideoDataSources::find($this->inventory, $name);
            $videos[] = new RoundVideoPlan($name, $demo[$name]->title, $demo[$name]->country, $ds?->id, $ds?->name, $scenarios);
        }
        return [$videos, $invalid];
    }
}

<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Dataset\Round;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Server\DataSourceInventory;
use Shipard\Module\Core\Exchange\Dataset\DatasetCatalog;
use Shipard\Module\Core\Exchange\Dataset\DatasetReader;
use Shipard\Module\Core\Exchange\Dataset\Round\CodeSteps;
use Shipard\Module\Core\Exchange\Dataset\Round\RoundException;
use Shipard\Module\Core\Exchange\Dataset\Round\RoundPlanner;
use Shipard\Module\Core\Exchange\Dataset\Round\RoundSelection;
use Shipard\Module\Core\Exchange\Dataset\Video\VideoScenarioCatalog;
use Shipard\Module\Core\Mail\Eval\Browse\EvalCatalog;

/**
 * Rozvrh kola: vrstva sad = jen tests/datasets (demo ne), kopie sady podle
 * adresáře, zdroje dat kola a videí nebo založení, scénáře s problémem,
 * neznámý filtr = RoundException.
 */
final class RoundPlannerTest extends TestCase
{
    private string $dir;
    private string $repo;
    private string $dsDir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/shpd_round_plan_' . bin2hex(random_bytes(6));
        $this->repo = $this->dir . '/repo';
        $this->dsDir = $this->dir . '/ds';
        $real = dirname(__DIR__, 7);
        foreach (['tests/datasets/cz/non-payer', 'tests/datasets/cz/vat-payer', 'demo/datasets/it-servis', 'demo/scenarios', 'tests/eval'] as $sub) {
            mkdir($this->repo . '/' . $sub, 0700, true);
        }
        mkdir($this->dsDir, 0700, true);
        copy($real . '/tests/datasets/cz/non-payer/manifest.jsonc', $this->repo . '/tests/datasets/cz/non-payer/manifest.jsonc');
        copy($real . '/tests/datasets/cz/vat-payer/manifest.jsonc', $this->repo . '/tests/datasets/cz/vat-payer/manifest.jsonc');
        copy($real . '/demo/datasets/it-servis/manifest.jsonc', $this->repo . '/demo/datasets/it-servis/manifest.jsonc');
        copy($real . '/demo/scenarios/s1-prijata-faktura.jsonc', $this->repo . '/demo/scenarios/s1-prijata-faktura.jsonc');
        file_put_contents($this->repo . '/demo/scenarios/s9-broken.jsonc', "{ this is not a scenario\n");
    }

    protected function tearDown(): void
    {
        DatasetReader::removeTree($this->dir);
    }

    /** @param array<string, mixed> $config */
    private function ds(string $id, array $config): void
    {
        mkdir($this->dsDir . '/' . $id . '/config', 0700, true);
        file_put_contents($this->dsDir . '/' . $id . '/config/main.json', json_encode(['id' => $id, 'name' => 'DS ' . $id, 'database_name' => 'x', 'country' => 'cz'] + $config));
    }

    /** @param list<string> $e2e @param list<string> $video */
    private function planner(array $e2e = [], array $video = []): RoundPlanner
    {
        return new RoundPlanner(
            new DataSourceInventory($this->dsDir),
            new EvalCatalog($this->repo),
            new DatasetCatalog($this->repo),
            new VideoScenarioCatalog($this->repo),
            new CodeSteps($this->repo, $this->dir . '/nobin', '/usr/bin/php'),
            static fn (): array => $e2e,
            static fn (): array => $video,
        );
    }

    public function testAllLayersWithExistingAndMissingDataSources(): void
    {
        $this->ds('kkkk-0000-0000-0000', ['dataClass' => 'free', 'purpose' => 'testovací kolo — cz-non-payer']);
        $this->ds('eeee-0000-0000-0000', ['dataClass' => 'free', 'purpose' => 'evaluace AI — cz-vat-payer', 'dataset' => ['name' => 'cz-vat-payer', 'hash' => 'h']]);
        $this->ds('vvvv-0000-0000-0000', ['dataClass' => 'free', 'purpose' => 'videa — it-servis']);

        $plan = $this->planner(['Node chybí'], [])->plan(RoundSelection::fromArray([]));

        $this->assertCount(8, $plan->codeSteps, 'vrstva kódu vždy celá; chybějící nástroje jsou problémy kroků');
        $this->assertSame(['cz-non-payer', 'cz-vat-payer'], array_map(static fn ($d) => $d->name, $plan->datasets), 'jen tests/datasets, seřazené, bez demo sady');
        $this->assertSame('kkkk-0000-0000-0000', $plan->datasets[0]->dataSourceId);
        $this->assertFalse($plan->datasets[0]->needsCreate());
        $this->assertNull($plan->datasets[1]->dataSourceId, 'evaluační zdroj dat sady se nepoužije (O1)');
        $this->assertTrue($plan->datasets[1]->needsCreate());
        $this->assertSame(['cz-vat-payer'], array_map(static fn ($d) => $d->name, $plan->datasetsToCreate()));
        $this->assertSame($this->repo . '/tests/datasets/cz/non-payer', $plan->datasets[0]->dir);
        $this->assertSame('cz', $plan->datasets[0]->country);
        $this->assertSame(['Node chybí'], $plan->e2eProblems);

        $this->assertCount(1, $plan->videos);
        $this->assertSame('it-servis', $plan->videos[0]->dataset);
        $this->assertSame('vvvv-0000-0000-0000', $plan->videos[0]->dataSourceId);
        $this->assertSame(['s1-prijata-faktura'], array_map(static fn ($s) => $s->id, $plan->videos[0]->scenarios));
        $this->assertSame('it-servis', $plan->videos[0]->scenarios[0]->dataset);
        $this->assertCount(1, $plan->invalidScenarios);
        $this->assertSame('s9-broken', $plan->invalidScenarios[0]->id);
        $this->assertNotNull($plan->invalidScenarios[0]->problem);
        $this->assertSame([], $plan->videoProblems);
    }

    public function testLayerFiltersAndMissingVideoDataSource(): void
    {
        $plan = $this->planner()->plan(RoundSelection::fromArray(['layers' => ['videos'], 'scenarios' => ['s1-prijata-faktura']]));
        $this->assertSame([], $plan->codeSteps);
        $this->assertSame([], $plan->datasets);
        $this->assertSame([], $plan->e2eProblems, 'nástroje proklikání se bez vrstvy sad neřeší');
        $this->assertSame([], $plan->invalidScenarios, 'filtr vybral jen platný scénář');
        $this->assertTrue($plan->videos[0]->needsCreate());
        $this->assertSame('Demo — IT servis', $plan->videos[0]->title);

        $plan = $this->planner()->plan(RoundSelection::fromArray(['layers' => ['datasets'], 'datasets' => ['cz-vat-payer']]));
        $this->assertSame(['cz-vat-payer'], array_map(static fn ($d) => $d->name, $plan->datasets));
        $this->assertSame([], $plan->videos);
    }

    public function testDatasetDirectoryOutsideTheRepoIsAccepted(): void
    {
        $copy = $this->dir . '/copy-of-non-payer';
        mkdir($copy, 0700, true);
        copy($this->repo . '/tests/datasets/cz/non-payer/manifest.jsonc', $copy . '/manifest.jsonc');
        $this->ds('kkkk-0000-0000-0000', ['dataClass' => 'free', 'purpose' => 'testovací kolo — cz-non-payer']);

        $plan = $this->planner()->plan(RoundSelection::fromArray(['layers' => ['datasets'], 'datasets' => [$copy]]));

        $this->assertSame('cz-non-payer', $plan->datasets[0]->name, 'název z manifestu kopie');
        $this->assertSame($copy, $plan->datasets[0]->dir);
        $this->assertSame('kkkk-0000-0000-0000', $plan->datasets[0]->dataSourceId, 'zdroj dat kola podle názvu sady');
    }

    public function testUnknownDatasetOrScenarioRefusesTheRound(): void
    {
        try {
            $this->planner()->plan(RoundSelection::fromArray(['datasets' => ['it-servis']]));
            $this->fail('demo set is not a test set');
        } catch (RoundException $e) {
            $this->assertStringContainsString("'it-servis'", $e->getMessage());
            $this->assertStringContainsString('cz-non-payer, cz-vat-payer', $e->getMessage());
        }
        $this->expectException(RoundException::class);
        $this->planner()->plan(RoundSelection::fromArray(['scenarios' => ['s0-nope']]));
    }
}

<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Dataset\Video;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\DataClass;
use Shipard\Core\Server\DataSourceInfo;
use Shipard\Module\Core\Exchange\Dataset\DatasetCatalog;
use Shipard\Module\Core\Exchange\Dataset\DatasetManifest;
use Shipard\Module\Core\Exchange\Dataset\DatasetReader;
use Shipard\Module\Core\Exchange\Dataset\DatasetWriter;
use Shipard\Module\Core\Exchange\Dataset\Video\VideoPreflight;
use Shipard\Module\Core\Exchange\Dataset\Video\VideoScenario;

/**
 * Preflight videí (tasks/testing-phase7b.md § 2): každý chybějící nástroj
 * má přesnou větu, co spustit; Chromium se bere z instalace runneru;
 * důvody k odmítnutí jsou fail-closed (režim, třída, demo sada, příběh,
 * Gotenberg).
 */
final class VideoPreflightTest extends TestCase
{
    private string $tmp;
    private string $repo;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/shpd_video_pf_' . bin2hex(random_bytes(6));
        $this->repo = $this->tmp . '/repo';
        self::fullRepo($this->repo, $this->tmp . '/bin', $this->tmp . '/browsers');
    }

    protected function tearDown(): void
    {
        DatasetReader::removeTree($this->tmp);
    }

    /** Checkout se vším, co preflight hledá (sdílí {@see \Shipard\Tests\Unit\Command\DataSource\VideoRunCommandTest}). */
    public static function fullRepo(string $repo, string $bin, string $browsers): void
    {
        mkdir($repo . '/tools/video-runner/bin', 0700, true);
        mkdir($repo . '/tools/video-runner/node_modules/playwright', 0700, true);
        mkdir($repo . '/tools/video-runner/node_modules/playwright-core', 0700, true);
        mkdir($repo . '/demo/scenarios', 0700, true);
        mkdir($repo . '/demo/datasets/stories/demo-set', 0700, true);
        mkdir($repo . '/demo/datasets/templates', 0700, true);
        mkdir($repo . '/tools/invoice-generator/invoicegen', 0700, true);
        mkdir($repo . '/home', 0700, true);
        mkdir($bin, 0700, true);
        mkdir($browsers . '/chromium-4321', 0700, true);
        foreach (['node', 'ffmpeg', 'python3'] as $tool) {
            file_put_contents($bin . '/' . $tool, "#!/bin/sh\nexit 0\n");
            chmod($bin . '/' . $tool, 0755);
        }
        file_put_contents($repo . '/tools/video-runner/bin/video-runner.mjs', '');
        file_put_contents($repo . '/tools/video-runner/node_modules/playwright/package.json', '{"name":"playwright"}');
        file_put_contents($repo . '/tools/video-runner/node_modules/playwright-core/browsers.json', '{"browsers":[{"name":"chromium","revision":"4321"}]}');
        file_put_contents($browsers . '/chromium-4321/INSTALLATION_COMPLETE', '');
        file_put_contents($repo . '/demo/datasets/build.py', 'print(1)');
        file_put_contents($repo . '/demo/datasets/stories/demo-set/story.jsonc', '{"name":"demo-set"}');
        file_put_contents($repo . '/demo/datasets/templates/contract.html', '<html>');
        file_put_contents($repo . '/tools/invoice-generator/invoicegen/render.py', 'x = 1');
        file_put_contents($repo . '/demo/scenarios/s1.jsonc', VideoScenarioTest::scenarioJsonc('s1', [], "// S1 — test.\n"));
        $w = DatasetWriter::create($repo . '/demo/datasets/demo-set');
        $w->writeManifest(new DatasetManifest('demo-set', 'Demo sada', null, 'fixed', '2026-10-20T00:00:00Z', [], country: 'cz', profile: 'vat-payer'));
    }

    private function preflight(): VideoPreflight
    {
        return new VideoPreflight($this->repo, $this->tmp . '/bin', $this->tmp . '/browsers', $this->repo . '/home');
    }

    private function dataSource(DataClass $class = DataClass::Free): DataSourceInfo
    {
        return new DataSourceInfo('abcd-abcd-abcd-abcd', 'Videa', null, 'cz', 'cs', ['install.base'], 'x', $class, true, false, 'videa — demo-set', 'ds-create', null, 'active', false, false, true);
    }

    public function testCompleteMachineHasNoProblems(): void
    {
        $preflight = $this->preflight();
        $this->assertSame([], $preflight->toolProblems());
        $this->assertSame($this->tmp . '/bin/node', $preflight->nodeBinary());
        $this->assertSame($this->tmp . '/bin/ffmpeg', $preflight->ffmpegBinary());
        $this->assertSame($this->tmp . '/bin/python3', $preflight->python3Binary());
        $this->assertSame($this->repo . '/tools/video-runner', $preflight->runnerDir());
        $this->assertSame($this->repo . '/tools/video-runner/bin/video-runner.mjs', $preflight->runnerBin());
        $this->assertSame('4321', $preflight->runnerInstall()->chromiumRevision(), 'revize z instalace runneru');
        $this->assertNull($preflight->chromiumRevision(), 'tests/e2e v tomto checkoutu není — a nevadí');
        $this->assertSame($this->tmp . '/bin', $preflight->toolPath());
        $this->assertSame($this->repo . '/home', $preflight->home());

        $scenario = VideoScenario::load($this->repo . '/demo/scenarios/s1.jsonc');
        $catalog = new DatasetCatalog($this->repo);
        $this->assertSame([], $preflight->videoProblems('development', $this->dataSource(), $scenario, $catalog, 'http://10.0.0.1:3000'));
    }

    public function testEachMissingToolNamesTheRemedy(): void
    {
        $remedy = VideoPreflight::VIDEO_REMEDY;
        $cases = [
            [fn () => unlink($this->tmp . '/bin/node'), 'Node není v PATH', $remedy],
            [fn () => unlink($this->repo . '/tools/video-runner/node_modules/playwright/package.json'), 'Závislosti video-runneru chybí', $remedy],
            [fn () => unlink($this->tmp . '/browsers/chromium-4321/INSTALLATION_COMPLETE'), 'Chromium Playwrightu pro video-runner chybí', 'pod uživatelem, který videa spouští'],
            [fn () => file_put_contents($this->repo . '/tools/video-runner/node_modules/playwright-core/browsers.json', '{"browsers":[]}'), 'Revizi Chromia video-runneru nejde určit', $remedy],
            [fn () => unlink($this->repo . '/tools/video-runner/bin/video-runner.mjs'), 'Video-runner chybí', 'checkout je neúplný'],
            [fn () => unlink($this->tmp . '/bin/ffmpeg'), 'ffmpeg není v PATH', 'INSTALL.md'],
            [fn () => unlink($this->tmp . '/bin/python3'), 'python3 není v PATH', 'build.py'],
            [fn () => unlink($this->repo . '/demo/datasets/build.py'), 'Generátor demo sady chybí', 'checkout je neúplný'],
        ];
        foreach ($cases as [$break, $problem, $hint]) {
            DatasetReader::removeTree($this->tmp);
            self::fullRepo($this->repo, $this->tmp . '/bin', $this->tmp . '/browsers');
            $break();
            $problems = $this->preflight()->toolProblems();
            $this->assertCount(1, $problems, $problem);
            $this->assertStringContainsString($problem, $problems[0]);
            $this->assertStringContainsString($hint, $problems[0]);
        }
    }

    public function testVideoProblemsAreFailClosed(): void
    {
        $preflight = $this->preflight();
        $scenario = VideoScenario::load($this->repo . '/demo/scenarios/s1.jsonc');
        $catalog = new DatasetCatalog($this->repo);

        $problems = $preflight->videoProblems(null, $this->dataSource(), $scenario, $catalog, 'http://g:3000');
        $this->assertCount(1, $problems);
        $this->assertStringContainsString('Režim serveru nejde určit', $problems[0]);

        $problems = $preflight->videoProblems('production', $this->dataSource(), $scenario, $catalog, 'http://g:3000');
        $this->assertStringContainsString("mode is 'production'", $problems[0]);

        $problems = $preflight->videoProblems('development', $this->dataSource(DataClass::RealCopy), $scenario, $catalog, 'http://g:3000');
        $this->assertStringContainsString('not a free data source', $problems[0]);

        $problems = $preflight->videoProblems('development', null, $scenario, $catalog, 'http://g:3000');
        $this->assertStringContainsString('Data source not found', $problems[0]);

        $problems = $preflight->videoProblems('development', $this->dataSource(), $scenario, $catalog, null);
        $this->assertSame(['Gotenberg není nastavený (render.url v /etc/shipard/server.json) — PDF demo sady se bez něj nevyrobí (docs/operations/render-service.md)'], $problems);

        unlink($this->repo . '/demo/datasets/stories/demo-set/story.jsonc');
        $problems = $preflight->videoProblems('development', $this->dataSource(), $scenario, $catalog, 'http://g:3000');
        $this->assertCount(1, $problems);
        $this->assertStringContainsString("Příběh sady 'demo-set' chybí", $problems[0]);

        // Sada mimo katalog (neznámá) a testovací sada stejného jména (není demo).
        file_put_contents($this->repo . '/demo/scenarios/s2.jsonc', VideoScenarioTest::scenarioJsonc('s2', ['dataset' => 'unknown-set']));
        $problems = $preflight->videoProblems('development', $this->dataSource(), VideoScenario::load($this->repo . '/demo/scenarios/s2.jsonc'), $catalog, 'http://g:3000');
        $this->assertStringContainsString("Unknown dataset 'unknown-set'", $problems[0]);

        mkdir($this->repo . '/tests/datasets/cz', 0700, true);
        $w = DatasetWriter::create($this->repo . '/tests/datasets/cz/demo-set');
        $w->writeManifest(new DatasetManifest('demo-set', 'Testovací', null, 'fixed', '2026-10-20T00:00:00Z', [], country: 'cz', profile: 'vat-payer'));
        file_put_contents($this->repo . '/demo/datasets/stories/demo-set/story.jsonc', '{}');
        $problems = $preflight->videoProblems('development', $this->dataSource(), $scenario, new DatasetCatalog($this->repo), 'http://g:3000');
        $this->assertCount(1, $problems);
        $this->assertStringContainsString("není demo sada (druh test)", $problems[0]);
    }
}

<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Dataset\Video;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Dataset\DatasetReader;
use Shipard\Module\Core\Exchange\Dataset\Video\VideoException;
use Shipard\Module\Core\Exchange\Dataset\Video\VideoScenarioCatalog;

/** Scénáře v `demo/scenarios/` (tasks/testing-phase7b.md § 3): výpis i s neplatnými, překlad id / cesty, cesta mimo adresář odmítnuta. */
final class VideoScenarioCatalogTest extends TestCase
{
    private string $repo;

    protected function setUp(): void
    {
        $this->repo = sys_get_temp_dir() . '/shpd_video_cat_' . bin2hex(random_bytes(6));
        mkdir($this->repo . '/demo/scenarios', 0700, true);
        mkdir($this->repo . '/elsewhere', 0700, true);
        file_put_contents($this->repo . '/demo/scenarios/s1.jsonc', VideoScenarioTest::scenarioJsonc('s1', [], "// S1 — první.\n"));
        file_put_contents($this->repo . '/demo/scenarios/broken.jsonc', VideoScenarioTest::scenarioJsonc('broken', ['dataset' => null]));
        file_put_contents($this->repo . '/elsewhere/s1.jsonc', VideoScenarioTest::scenarioJsonc('s1'));
        file_put_contents($this->repo . '/demo/scenarios/README.md', 'ne scénář');
    }

    protected function tearDown(): void
    {
        DatasetReader::removeTree($this->repo);
    }

    public function testListsScenariosByNameWithProblemsForInvalidOnes(): void
    {
        $catalog = new VideoScenarioCatalog($this->repo);
        $this->assertTrue($catalog->isAvailable());
        $list = $catalog->scenarios();
        $this->assertSame(['broken', 's1'], array_column($list, 'id'));
        $this->assertNull($list[0]['scenario']);
        $this->assertStringContainsString('chybí dataset', (string) $list[0]['problem']);
        $this->assertSame('S1 — první.', $list[1]['scenario']?->title);
        $this->assertNull($list[1]['problem']);
        $this->assertFalse((new VideoScenarioCatalog($this->repo . '/nope'))->isAvailable());
    }

    public function testResolvesIdAndPathInsideTheDirectoryOnly(): void
    {
        $catalog = new VideoScenarioCatalog($this->repo);
        $this->assertSame('s1', $catalog->resolve('s1')->id);
        $this->assertSame('s1', $catalog->resolve($this->repo . '/demo/scenarios/s1.jsonc')->id);
        $cwd = getcwd();
        chdir($this->repo);
        try {
            $this->assertSame('s1', $catalog->resolve('demo/scenarios/s1.jsonc')->id, 'relativní cesta');
        } finally {
            chdir((string) $cwd);
        }

        foreach ([
            ['missing', 'neexistuje'],
            ['Bad Id', 'id musí být z malých písmen'],
            [$this->repo . '/elsewhere/s1.jsonc', 'leží mimo demo/scenarios'],
            [$this->repo . '/demo/scenarios/../scenarios/../../elsewhere/s1.jsonc', 'leží mimo demo/scenarios'],
            [$this->repo . '/demo/scenarios/nope.jsonc', 'neexistuje'],
            ['', 'Chybí scénář'],
        ] as [$arg, $message]) {
            try {
                $catalog->resolve($arg);
                $this->fail("expected refusal for {$arg}");
            } catch (VideoException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
    }
}

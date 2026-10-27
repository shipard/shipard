<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Dataset\Video;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Dataset\DatasetReader;
use Shipard\Module\Core\Exchange\Dataset\Video\DatasetBuildCache;
use Shipard\Module\Core\Exchange\Dataset\Video\VideoException;

/**
 * Mezipaměť sestavené demo sady (tasks/testing-phase7b.md D128): otisk
 * zdrojů (bez `build-state.json`), platnost per sada a kotva, značka až
 * po buildu, úklid starších kotev, zámek, bezpečný tvar názvů.
 */
final class DatasetBuildCacheTest extends TestCase
{
    private string $tmp;
    private string $repo;
    private DatasetBuildCache $cache;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/shpd_video_cache_' . bin2hex(random_bytes(6));
        $this->repo = $this->tmp . '/repo';
        mkdir($this->repo . '/demo/datasets/stories/demo-set/setup', 0700, true);
        mkdir($this->repo . '/demo/datasets/templates', 0700, true);
        mkdir($this->repo . '/tools/invoice-generator/invoicegen/tests', 0700, true);
        mkdir($this->repo . '/tools/invoice-generator/invoicegen/__pycache__', 0700, true);
        file_put_contents($this->repo . '/demo/datasets/stories/demo-set/story.jsonc', '{"name":"demo-set"}');
        file_put_contents($this->repo . '/demo/datasets/stories/demo-set/setup/a.jsonc', '{}');
        file_put_contents($this->repo . '/demo/datasets/stories/demo-set/build-state.json', '{"pdf":1}');
        file_put_contents($this->repo . '/demo/datasets/build.py', 'print(1)');
        file_put_contents($this->repo . '/demo/datasets/templates/contract.html', '<html>');
        file_put_contents($this->repo . '/tools/invoice-generator/invoicegen/render.py', 'x = 1');
        file_put_contents($this->repo . '/tools/invoice-generator/invoicegen/tests/test_x.py', 'assert True');
        file_put_contents($this->repo . '/tools/invoice-generator/invoicegen/__pycache__/render.cpython-313.pyc', 'bin');
        file_put_contents($this->repo . '/tools/invoice-generator/invoicegen/README.md', 'not python');
        $this->cache = new DatasetBuildCache($this->tmp . '/run/video-datasets', $this->repo);
    }

    protected function tearDown(): void
    {
        DatasetReader::removeTree($this->tmp);
    }

    public function testSourceHashCoversStoryGeneratorAndTemplatesButNotPdfCacheOrTests(): void
    {
        $hash = $this->cache->sourceHash('demo-set');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);

        file_put_contents($this->repo . '/demo/datasets/stories/demo-set/build-state.json', '{"pdf":2}');
        file_put_contents($this->repo . '/tools/invoice-generator/invoicegen/tests/test_x.py', 'assert False');
        file_put_contents($this->repo . '/tools/invoice-generator/invoicegen/__pycache__/render.cpython-313.pyc', 'other');
        file_put_contents($this->repo . '/tools/invoice-generator/invoicegen/README.md', 'still not python');
        $this->assertSame($hash, $this->cache->sourceHash('demo-set'), 'cache PDF, testy, pyc ani ne-python soubory otisk nemění');

        file_put_contents($this->repo . '/demo/datasets/stories/demo-set/setup/a.jsonc', '{"changed":true}');
        $afterStory = $this->cache->sourceHash('demo-set');
        $this->assertNotSame($hash, $afterStory, 'změna příběhu');
        file_put_contents($this->repo . '/tools/invoice-generator/invoicegen/render.py', 'x = 2');
        $afterGenerator = $this->cache->sourceHash('demo-set');
        $this->assertNotSame($afterStory, $afterGenerator, 'změna generátoru');
        file_put_contents($this->repo . '/demo/datasets/templates/contract.html', '<html><body>');
        $this->assertNotSame($afterGenerator, $this->cache->sourceHash('demo-set'), 'změna šablony');
        file_put_contents($this->repo . '/demo/datasets/build.py', 'print(2)');
        $this->assertNotSame($afterGenerator, $this->cache->sourceHash('demo-set'), 'změna build.py');
    }

    public function testFreshnessNeedsMarkerWithMatchingHashAndAManifest(): void
    {
        $hash = $this->cache->sourceHash('demo-set');
        $this->assertFalse($this->cache->isFresh('demo-set', '2026-10-23', $hash), 'nic nesestaveno');

        $dir = $this->cache->prepare('demo-set', '2026-10-23');
        $this->assertSame($this->tmp . '/run/video-datasets/demo-set/2026-10-23', $dir);
        $this->assertDirectoryExists($dir);
        file_put_contents($dir . '/manifest.jsonc', '{}');
        $this->assertFalse($this->cache->isFresh('demo-set', '2026-10-23', $hash), 'bez značky = rozestavěné');

        $this->cache->markBuilt('demo-set', '2026-10-23', $hash, 'abc123', new \DateTimeImmutable('2026-10-23T08:00:00+02:00'));
        $this->assertTrue($this->cache->isFresh('demo-set', '2026-10-23', $hash));
        $marker = $this->cache->readMarker('demo-set', '2026-10-23');
        $this->assertSame(['dataset' => 'demo-set', 'anchor' => '2026-10-23', 'sourceHash' => $hash, 'builtAt' => '2026-10-23T06:00:00Z', 'commit' => 'abc123'], $marker);
        $this->assertSame('0640', substr(sprintf('%o', fileperms($this->cache->markerPath('demo-set', '2026-10-23'))), -4));

        $this->assertFalse($this->cache->isFresh('demo-set', '2026-10-23', 'other-hash'), 'jiný otisk = přestavět');
        $this->assertFalse($this->cache->isFresh('demo-set', '2026-10-24', $hash), 'jiná kotva = jiný adresář');
        unlink($dir . '/manifest.jsonc');
        $this->assertFalse($this->cache->isFresh('demo-set', '2026-10-23', $hash), 'bez manifestu neplatí');

        $this->cache->prepare('demo-set', '2026-10-23');
        $this->assertFileDoesNotExist($this->cache->markerPath('demo-set', '2026-10-23'), 'příprava značku smaže');
    }

    public function testPruneRemovesOtherAnchorsOfTheSetOnly(): void
    {
        foreach (['2026-10-20', '2026-10-22', '2026-10-23'] as $anchor) {
            $dir = $this->cache->prepare('demo-set', $anchor);
            file_put_contents($dir . '/manifest.jsonc', '{}');
            file_put_contents($dir . '/.build-state.json', '{}');
        }
        $other = $this->cache->prepare('other-set', '2026-10-20');
        $removed = $this->cache->prune('demo-set', '2026-10-23');
        $this->assertSame([
            $this->tmp . '/run/video-datasets/demo-set/2026-10-20',
            $this->tmp . '/run/video-datasets/demo-set/2026-10-22',
        ], $removed);
        $this->assertDirectoryDoesNotExist($removed[0]);
        $this->assertDirectoryExists($this->cache->dir('demo-set', '2026-10-23'));
        $this->assertDirectoryExists($other, 'jiná sada zůstává');
        $this->assertSame([], $this->cache->prune('demo-set', '2026-10-23'));
    }

    public function testBuildArgvAndLock(): void
    {
        $this->assertSame([
            '/usr/bin/python3',
            $this->repo . '/demo/datasets/build.py',
            '--story', 'demo-set',
            '--anchor', '2026-10-23',
            '--out', $this->tmp . '/run/video-datasets/demo-set/2026-10-23',
            '--state', $this->tmp . '/run/video-datasets/demo-set/2026-10-23/.build-state.json',
        ], $this->cache->buildArgv('/usr/bin/python3', 'demo-set', '2026-10-23'));

        $lock = $this->cache->lock('demo-set');
        $this->assertIsResource($lock);
        $this->assertFileExists($this->tmp . '/run/video-datasets/demo-set/.lock');
        $second = fopen($this->tmp . '/run/video-datasets/demo-set/.lock', 'c');
        $this->assertNotFalse($second);
        $this->assertFalse(flock($second, LOCK_EX | LOCK_NB), 'zámek drží první běh');
        $this->cache->unlock($lock);
        $this->assertTrue(flock($second, LOCK_EX | LOCK_NB), 'po uvolnění jde získat');
        flock($second, LOCK_UN);
        fclose($second);
    }

    public function testRefusesUnsafeNamesAndAnchors(): void
    {
        foreach (['../x', 'Demo', 'a b'] as $name) {
            try {
                $this->cache->dir($name, '2026-10-23');
                $this->fail("expected refusal for {$name}");
            } catch (VideoException $e) {
                $this->assertStringContainsString('nemá bezpečný tvar', $e->getMessage());
            }
        }
        foreach (['2026-13-01', '20261023', '2026-10-23/..', 'today'] as $anchor) {
            try {
                $this->cache->dir('demo-set', $anchor);
                $this->fail("expected refusal for {$anchor}");
            } catch (VideoException $e) {
                $this->assertStringContainsString('Kotva musí být datum', $e->getMessage());
            }
        }
        $this->assertSame('/opt/shipard/run/video-datasets', DatasetBuildCache::defaultDir());
    }
}

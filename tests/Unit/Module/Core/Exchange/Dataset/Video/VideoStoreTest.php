<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Dataset\Video;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Dataset\DatasetReader;
use Shipard\Module\Core\Exchange\Dataset\Video\VideoException;
use Shipard\Module\Core\Exchange\Dataset\Video\VideoStore;

/**
 * Úložiště videí (tasks/testing-phase7b.md D127): cesty pro runner,
 * příprava čistého `work/`, povýšení videa až s `meta.json`, endpoint
 * jen přes meta, `check.json`, délka z `timeline.json`.
 */
final class VideoStoreTest extends TestCase
{
    private string $tmp;
    private VideoStore $store;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/shpd_video_store_' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0700, true);
        $this->store = new VideoStore($this->tmp . '/videos');
    }

    protected function tearDown(): void
    {
        DatasetReader::removeTree($this->tmp);
    }

    public function testPathsFollowTheRunnerContract(): void
    {
        $root = $this->tmp . '/videos';
        $this->assertSame($root . '/s1', $this->store->dir('s1'));
        $this->assertSame($root . '/s1/s1.mp4', $this->store->videoPath('s1'));
        $this->assertSame($root . '/s1/work', $this->store->workDir('s1'));
        $this->assertSame($root . '/s1/work/out', $this->store->outDir('s1'));
        $this->assertSame($root . '/s1/work/out/s1.mp4', $this->store->composedPath('s1'));
        $this->assertSame($root . '/s1/work/s1/result.json', $this->store->resultPath('s1'));
        $this->assertSame($root . '/s1/work/s1/timeline.json', $this->store->timelinePath('s1'));
        $this->assertSame($root . '/s1/meta.json', $this->store->metaPath('s1'));
        $this->assertSame($root . '/s1/check.json', $this->store->checkPath('s1'));
        $this->assertSame('/opt/shipard/run/videos', VideoStore::defaultDir());
        $this->expectException(VideoException::class);
        $this->store->dir('../etc');
    }

    public function testPrepareClearsWorkButKeepsTheFinishedVideo(): void
    {
        $this->store->prepare('s1');
        $this->assertDirectoryExists($this->store->outDir('s1'));
        mkdir($this->store->workDir('s1') . '/s1', 0700, true);
        file_put_contents($this->store->resultPath('s1'), '{"status":"failed"}');
        file_put_contents($this->store->videoPath('s1'), 'MP4');
        file_put_contents($this->store->metaPath('s1'), '{"file":"s1.mp4"}');

        $this->store->prepare('s1');
        $this->assertFileDoesNotExist($this->store->resultPath('s1'), 'stale result.json pryč');
        $this->assertDirectoryExists($this->store->outDir('s1'));
        $this->assertFileExists($this->store->videoPath('s1'));
        $this->assertFileExists($this->store->metaPath('s1'));
    }

    public function testPromoteMovesTheVideoAndWritesMetaLast(): void
    {
        $this->store->prepare('s1');
        $this->assertNull($this->store->fileFor('s1'));
        $this->assertSame(['meta' => null, 'check' => null, 'hasVideo' => false], $this->store->read('s1'));
        try {
            $this->store->promote('s1', []);
            $this->fail('bez složeného videa nelze povýšit');
        } catch (VideoException $e) {
            $this->assertStringContainsString('Složené video chybí', $e->getMessage());
        }

        file_put_contents($this->store->composedPath('s1'), str_repeat('x', 2048));
        mkdir($this->store->workDir('s1') . '/s1', 0700, true);
        file_put_contents($this->store->timelinePath('s1'), '{"scenario":"s1","duration":12.345,"fps":30}');
        $this->assertSame(12345, $this->store->readDurationMs('s1'));

        $meta = $this->store->promote('s1', ['dataset' => 'demo-set', 'anchor' => '2026-10-23', 'commit' => 'abc', 'recordedAt' => '2026-10-23T08:00:00Z', 'durationMs' => 12345]);
        $this->assertSame(['scenario', 'file', 'dataset', 'anchor', 'commit', 'recordedAt', 'durationMs', 'bytes'], array_keys($meta));
        $this->assertSame('s1.mp4', $meta['file']);
        $this->assertSame(2048, $meta['bytes']);
        $this->assertFileDoesNotExist($this->store->composedPath('s1'));
        $this->assertSame($this->store->videoPath('s1'), $this->store->fileFor('s1'));
        $this->assertSame('0640', substr(sprintf('%o', fileperms($this->store->videoPath('s1'))), -4));
        $read = $this->store->read('s1');
        $this->assertSame($meta, $read['meta']);
        $this->assertTrue($read['hasVideo']);

        // Endpoint vydá jen soubor, který meta jmenuje.
        file_put_contents($this->store->metaPath('s1'), json_encode(['file' => 'other.mp4']));
        $this->assertNull($this->store->fileFor('s1'));
        file_put_contents($this->store->metaPath('s1'), json_encode(['file' => 's1.mp4']));
        unlink($this->store->videoPath('s1'));
        $this->assertNull($this->store->fileFor('s1'));
    }

    public function testCheckAndResultFiles(): void
    {
        $this->store->writeCheck('s1', ['at' => '2026-10-23T08:00:00Z', 'status' => 'ok']);
        $this->assertSame(['scenario' => 's1', 'at' => '2026-10-23T08:00:00Z', 'status' => 'ok'], $this->store->read('s1')['check']);
        $this->assertNull($this->store->readResult('s1'));
        $this->store->prepare('s1');
        mkdir($this->store->workDir('s1') . '/s1', 0700, true);
        file_put_contents($this->store->resultPath('s1'), '{"status":"failed","error":{"message":"x"}}');
        $this->assertSame('failed', $this->store->readResult('s1')['status']);
        file_put_contents($this->store->resultPath('s1'), 'not json');
        $this->assertNull($this->store->readResult('s1'));
        $this->assertNull($this->store->readDurationMs('s1'));
    }
}

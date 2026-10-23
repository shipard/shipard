<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Dataset\E2e;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Dataset\DatasetReader;
use Shipard\Module\Core\Exchange\Dataset\E2e\E2eException;
use Shipard\Module\Core\Exchange\Dataset\E2e\E2eProgress;

/** Průběh `dataset-e2e` (I10): tvar řádků jako `ai-eval`, bez cesty nic. */
final class E2eProgressTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/shpd_e2e_prog_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        DatasetReader::removeTree($this->dir);
    }

    public function testEventsAreJsonLinesWithEventAndTimestamp(): void
    {
        $file = $this->dir . '/progress.jsonl';
        $progress = E2eProgress::toFile($file);
        $this->assertTrue($progress->enabled());
        $this->assertSame($file, $progress->path());

        $progress->started(['pid' => 42, 'dataset' => 'cz-test', 'dataSource' => 'abcd', 'appUrl' => 'http://127.0.0.1/abcd/app/', 'outputDir' => '/out', 'expected' => null]);
        $progress->step(E2eProgress::STEP_RESEED, E2eProgress::STATUS_STARTED);
        $progress->step(E2eProgress::STEP_RESEED, E2eProgress::STATUS_FINISHED, 0);
        $progress->step(E2eProgress::STEP_WALKTHROUGH, E2eProgress::STATUS_STARTED, null, ['expected' => 3]);
        $progress->step(E2eProgress::STEP_WALKTHROUGH, E2eProgress::STATUS_FAILED, null, ['termSignal' => 9, 'cancelled' => true]);
        $progress->finished('cancelled', 1, 0, 130);
        $progress->error('boom');

        $events = array_map(static fn (string $l): array => json_decode($l, true, 512, JSON_THROW_ON_ERROR), array_values(array_filter(explode("\n", (string) file_get_contents($file)))));
        $this->assertSame(['started', 'step', 'step', 'step', 'step', 'finished', 'error'], array_column($events, 'event'));
        foreach ($events as $event) {
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $event['at']);
        }
        $this->assertSame(42, $events[0]['pid']);
        $this->assertNull($events[0]['expected']);
        $this->assertSame(['event', 'at', 'step', 'status'], array_keys($events[1]));
        $this->assertSame(['step' => 'reseed', 'status' => 'finished', 'exitCode' => 0], array_slice($events[2], 2));
        $this->assertSame(3, $events[3]['expected']);
        $this->assertArrayNotHasKey('exitCode', $events[3]);
        $this->assertSame(['step' => 'walkthrough', 'status' => 'failed', 'exitCode' => null, 'termSignal' => 9, 'cancelled' => true], array_slice($events[4], 2));
        $this->assertSame(['status' => 'cancelled', 'applied' => 1, 'failed' => 0, 'exitCode' => 130], array_slice($events[5], 2));
        $this->assertSame('boom', $events[6]['message']);
    }

    public function testNoneWritesNothingAndMissingDirectoryIsRefused(): void
    {
        $none = E2eProgress::none();
        $this->assertFalse($none->enabled());
        $this->assertNull($none->path());
        $none->started(['pid' => null, 'dataset' => 'x', 'dataSource' => 'y', 'appUrl' => 'u', 'outputDir' => 'o', 'expected' => null]);
        $none->finished('ok', 0, 0, 0);

        $this->expectException(E2eException::class);
        $this->expectExceptionMessage('--progress-file');
        E2eProgress::toFile($this->dir . '/missing/progress.jsonl');
    }
}

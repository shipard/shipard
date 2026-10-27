<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Dataset\Video;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Dataset\Video\VideoReport;
use Symfony\Component\Console\Output\BufferedOutput;

/** Report `video-run` (tasks/testing-phase7b.md § 2): nálezy per krok, krok a selektor z runneru, video jen u `build`, zrušení má přednost. */
final class VideoReportTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function meta(string $verb = 'check'): array
    {
        return [
            'verb' => $verb,
            'scenario' => ['id' => 's1', 'path' => '/repo/demo/scenarios/s1.jsonc', 'title' => 'S1', 'description' => '', 'dataset' => 'demo-set', 'locale' => 'cs-CZ', 'timezone' => 'Europe/Prague', 'clockTime' => '09:30', 'stepCount' => 3],
            'anchor' => '2026-10-23',
            'fixedTime' => '2026-10-23T09:30:00+02:00',
            'dataset' => ['name' => 'demo-set', 'cacheDir' => '/run/video-datasets/demo-set/2026-10-23', 'cached' => true, 'sourceHash' => 'h'],
            'dataSource' => ['id' => 'abcd-abcd-abcd-abcd', 'name' => 'Videa'],
            'appUrl' => 'http://127.0.0.1/abcd-abcd-abcd-abcd/app/',
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function okSteps(string $verb = 'check'): array
    {
        $steps = [
            'build' => ['ran' => true, 'cached' => true, 'exitCode' => 0, 'durationMs' => 0],
            'reseed' => ['ran' => true, 'exitCode' => 0, 'durationMs' => 40000],
        ];
        if ($verb === 'build') {
            $steps['record'] = ['ran' => true, 'exitCode' => 0, 'durationMs' => 30000];
            $steps['compose'] = ['ran' => true, 'exitCode' => 0, 'durationMs' => 12000];
        } else {
            $steps['check'] = ['ran' => true, 'exitCode' => 0, 'durationMs' => 20000];
        }
        return $steps;
    }

    public function testCheckOk(): void
    {
        $report = VideoReport::build(self::meta(), self::okSteps(), ['verb' => 'check', 'scenario' => 's1', 'status' => 'ok', 'durationMs' => 19000], null, false);
        $this->assertSame('ok', $report->status());
        $this->assertSame(0, $report->exitCode());
        $this->assertSame([], $report->problems());
        $array = $report->toArray();
        $this->assertTrue($array['ok']);
        $this->assertSame(['build', 'reseed', 'check'], array_keys($array['steps']));
        $this->assertTrue($array['steps']['build']['cached']);
        $this->assertNull($array['steps']['reseed']['cached']);
        $this->assertNull($array['video']);
        $out = new BufferedOutput();
        $report->writeText($out);
        $text = $out->fetch();
        $this->assertStringContainsString('Scénář s1 (check) → sada demo-set, kotva 2026-10-23', $text);
        $this->assertStringContainsString('sestavení sady … z mezipaměti', $text);
        $this->assertStringContainsString('Výsledek: v pořádku.', $text);
    }

    public function testRunnerFailureNamesTheStepAndSelector(): void
    {
        $steps = self::okSteps();
        $steps['check']['exitCode'] = 1;
        $result = ['verb' => 'check', 'scenario' => 's1', 'status' => 'failed', 'durationMs' => 15000, 'error' => ['message' => 'krok #2 (click @card-action-review): prvek se do 15 s neobjevil.', 'step' => ['index' => 2, 'verb' => 'click', 'value' => '@card-action-review']]];
        $report = VideoReport::build(self::meta(), $steps, $result, null, false);
        $this->assertSame('failed', $report->status());
        $this->assertSame(1, $report->exitCode());
        $this->assertSame(['ověření: krok #2 (click @card-action-review): prvek se do 15 s neobjevil. [krok #2 click @card-action-review]'], $report->problems());
        $this->assertSame(['index' => 2, 'verb' => 'click', 'value' => '@card-action-review'], $report->failedStep());
        $out = new BufferedOutput();
        $report->writeText($out);
        $this->assertStringContainsString('Výsledek: nálezy (1).', $out->fetch());
    }

    public function testMissingResultSignalAndFailedPrerequisites(): void
    {
        $steps = self::okSteps();
        $report = VideoReport::build(self::meta(), $steps, null, null, false);
        $this->assertSame(['ověření: runner nezapsal result.json'], $report->problems());

        $steps['check']['termSignal'] = 9;
        $steps['check']['exitCode'] = null;
        $report = VideoReport::build(self::meta(), $steps, null, null, false);
        $this->assertSame(['ověření: runner skončil signálem 9'], $report->problems(), 'signál vysvětlí chybějící result');

        $report = VideoReport::build(self::meta(), ['build' => ['ran' => true, 'cached' => false, 'exitCode' => 1, 'durationMs' => 500]], null, null, false);
        $this->assertSame(['sestavení demo sady selhalo (build.py exit 1)'], $report->problems());

        $report = VideoReport::build(self::meta(), ['build' => ['ran' => true, 'cached' => true, 'exitCode' => 0], 'reseed' => ['ran' => true, 'exitCode' => 2]], null, null, false);
        $this->assertSame(['přeseedování selhalo (dataset-seed exit 2)'], $report->problems());

        $report = VideoReport::build(self::meta(), [], null, null, false);
        $this->assertSame(['ověření neběželo'], $report->problems());
    }

    public function testBuildNeedsAVideoAndCancellationWins(): void
    {
        $result = ['verb' => 'compose', 'scenario' => 's1', 'status' => 'ok', 'durationMs' => 12000, 'video' => ['path' => '/run/videos/s1/work/out/s1.mp4']];
        $video = ['scenario' => 's1', 'file' => 's1.mp4', 'path' => '/run/videos/s1/s1.mp4', 'durationMs' => 42000, 'bytes' => 3_000_000, 'anchor' => '2026-10-23', 'commit' => 'abc'];
        $report = VideoReport::build(self::meta('build'), self::okSteps('build'), $result, $video, false);
        $this->assertSame('ok', $report->status());
        $this->assertSame(['build', 'reseed', 'record', 'compose'], array_keys($report->toArray()['steps']));
        $this->assertSame($video, $report->video());
        $out = new BufferedOutput();
        $report->writeText($out);
        $this->assertStringContainsString('Video: /run/videos/s1/s1.mp4 (0:42, 2.9 MiB)', $out->fetch());

        $report = VideoReport::build(self::meta('build'), self::okSteps('build'), $result, null, false);
        $this->assertSame(['video nevzniklo — runner nezapsal složené video'], $report->problems());

        $steps = self::okSteps('build');
        unset($steps['compose']);
        $report = VideoReport::build(self::meta('build'), $steps, ['status' => 'ok'], null, false);
        $this->assertSame(['skládání neběželo'], $report->problems());

        $report = VideoReport::build(self::meta('build'), $steps, null, null, true);
        $this->assertSame('cancelled', $report->status());
        $this->assertSame(130, $report->exitCode());
        $this->assertFalse($report->toArray()['ok']);
        $this->assertSame('1:05', VideoReport::durationText(65_400));
        $this->assertSame('512 KiB', VideoReport::bytesText(524_288));
    }
}

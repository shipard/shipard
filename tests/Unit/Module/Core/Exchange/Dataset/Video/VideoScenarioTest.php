<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Dataset\Video;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Dataset\DatasetReader;
use Shipard\Module\Core\Exchange\Dataset\Video\VideoException;
use Shipard\Module\Core\Exchange\Dataset\Video\VideoScenario;

/**
 * Scénář videa z pohledu `video-run` (tasks/testing-phase7b.md § 1–2):
 * titulek a popis z hlavičky, výchozí hodnoty, povinný `dataset`, id =
 * název souboru, `clockTime`, pevný čas v zóně scénáře (D120).
 */
final class VideoScenarioTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/shpd_video_scn_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        DatasetReader::removeTree($this->dir);
    }

    public static function scenarioJsonc(string $id = 's1', array $overrides = [], string $header = ''): string
    {
        $body = ['id' => $id, 'dataset' => 'demo-set', 'steps' => [['goto' => '/app/'], ['waitFor' => '@app-shell']]];
        foreach ($overrides as $key => $value) {
            if ($value === null) {
                unset($body[$key]);
            } else {
                $body[$key] = $value;
            }
        }
        return $header . json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    }

    private function write(string $name, string $content): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, $content);
        return $path;
    }

    public function testLoadsHeaderDefaultsAndStepCount(): void
    {
        $header = "// S1 — Přeposlaná faktura → doklad.\n// Tvrzení: klient přepošle fakturu,\n//   Shipard připraví doklad.\n//\n// Rozsah: tohle už do popisu nepatří.\n";
        $scenario = VideoScenario::load($this->write('s1.jsonc', self::scenarioJsonc(header: $header)));

        $this->assertSame('s1', $scenario->id);
        $this->assertSame('demo-set', $scenario->dataset);
        $this->assertSame('S1 — Přeposlaná faktura → doklad.', $scenario->title);
        $this->assertSame('Tvrzení: klient přepošle fakturu, Shipard připraví doklad.', $scenario->description);
        $this->assertSame('cs-CZ', $scenario->locale);
        $this->assertSame('Europe/Prague', $scenario->timezone);
        $this->assertSame('09:30', $scenario->clockTime);
        $this->assertSame(2, $scenario->stepCount);
        $this->assertSame('s1', $scenario->toArray()['id']);
        $this->assertSame(['id', 'path', 'title', 'description', 'dataset', 'locale', 'timezone', 'clockTime', 'stepCount'], array_keys($scenario->toArray()));
    }

    public function testWithoutHeaderTheTitleIsTheId(): void
    {
        $scenario = VideoScenario::load($this->write('s2.jsonc', self::scenarioJsonc('s2')));
        $this->assertSame('s2', $scenario->title);
        $this->assertSame('', $scenario->description);
    }

    public function testFixedTimeIsAnchorPlusClockTimeInTheScenarioTimezone(): void
    {
        $scenario = VideoScenario::load($this->write('s1.jsonc', self::scenarioJsonc()));
        $this->assertSame('2026-10-23T09:30:00+02:00', $scenario->fixedTime('2026-10-23'), 'letní čas');
        $this->assertSame('2027-01-20T09:30:00+01:00', $scenario->fixedTime('2027-01-20'), 'zimní čas');

        $custom = VideoScenario::load($this->write('s3.jsonc', self::scenarioJsonc('s3', ['clockTime' => '14:05', 'timezone' => 'UTC', 'locale' => 'en-US'])));
        $this->assertSame('2026-10-23T14:05:00+00:00', $custom->fixedTime('2026-10-23'));
        $this->assertSame('en-US', $custom->locale);

        $this->expectException(VideoException::class);
        $this->expectExceptionMessage('Kotva musí být datum');
        $scenario->fixedTime('2026-13-01');
    }

    public function testRejectsMissingDatasetIdMismatchBadClockTimeAndUnknownTimezone(): void
    {
        $cases = [
            ['s1.jsonc', self::scenarioJsonc(overrides: ['dataset' => null]), 'chybí dataset'],
            ['s1.jsonc', self::scenarioJsonc(overrides: ['dataset' => 'Web Demo']), 'chybí dataset'],
            ['other.jsonc', self::scenarioJsonc(), "id 's1' neodpovídá názvu souboru 'other'"],
            ['s1.jsonc', self::scenarioJsonc(overrides: ['clockTime' => '9:30']), 'clockTime musí být HH:MM'],
            ['s1.jsonc', self::scenarioJsonc(overrides: ['timezone' => 'Mars/Olympus']), 'neznámá časová zóna'],
            ['s1.jsonc', self::scenarioJsonc(overrides: ['steps' => []]), 'nemá žádné kroky'],
            ['s1.jsonc', "// jen komentář\n{ nevalidní", 'není platný JSONC'],
            ['S1.jsonc', self::scenarioJsonc('S1'), 'id musí být z malých písmen'],
        ];
        foreach ($cases as [$name, $content, $message]) {
            try {
                VideoScenario::load($this->write($name, $content));
                $this->fail("expected failure: {$message}");
            } catch (VideoException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
        $this->expectException(VideoException::class);
        $this->expectExceptionMessage('nejde přečíst');
        VideoScenario::load($this->dir . '/missing.jsonc');
    }
}

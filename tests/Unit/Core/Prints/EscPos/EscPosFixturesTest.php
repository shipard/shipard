<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Prints\EscPos;

use PHPUnit\Framework\TestCase;
use Shipard\Tests\Fixtures\EscPos\EscPosFixtures;

/**
 * Společné vzory ESC/POS (#177 U33): renderer musí vyrobit uložené bajty
 * bajt po bajtu a jeho sazba uložený náhled. Tytéž soubory čte náhled ve
 * frontendu (`frontend/tests/Unit/native/escposFixtures.test.mjs`).
 *
 * Po vědomé změně: `php tests/Fixtures/EscPos/regenerate.php` a kontrola diffu.
 */
final class EscPosFixturesTest extends TestCase
{
    private const HINT = ' — po vědomé změně: php tests/Fixtures/EscPos/regenerate.php';

    public function testRendererProducesStoredBytesAndPreview(): void
    {
        $dir      = EscPosFixtures::directory();
        $fixtures = EscPosFixtures::build();

        // Zkušební účtenka a čtyři prodejky pro oba profily.
        $this->assertCount(10, $fixtures);

        foreach ($fixtures as $name => $fixture) {
            $this->assertFileExists("{$dir}/{$name}.bin", $name . self::HINT);
            $this->assertSame(
                bin2hex((string) file_get_contents("{$dir}/{$name}.bin")),
                bin2hex($fixture['bytes']),
                "{$name}.bin" . self::HINT,
            );
            $this->assertSame(
                (string) file_get_contents("{$dir}/{$name}.preview.json"),
                EscPosFixtures::previewJson($fixture['preview']),
                "{$name}.preview.json" . self::HINT,
            );
        }
    }

    public function testNoStoredFixtureIsLeftBehind(): void
    {
        $names = array_keys(EscPosFixtures::build());
        $files = [];
        foreach ($names as $name) {
            $files[] = "{$name}.bin";
            $files[] = "{$name}.preview.json";
        }
        sort($files);

        $stored = array_map('basename', glob(EscPosFixtures::directory() . '/*.{bin,json}', GLOB_BRACE) ?: []);
        sort($stored);

        $this->assertSame($files, $stored);
    }

    public function testNoLineOfAnyFixtureIsWiderThanItsProfile(): void
    {
        foreach (EscPosFixtures::build() as $name => $fixture) {
            $profileId = array_search(substr($name, -2), EscPosFixtures::PROFILES, true);
            $profile   = EscPosFixtures::profile((string) $profileId);

            foreach ($fixture['preview']['lines'] as $line) {
                $this->assertLessThanOrEqual(
                    $profile->lineWidth($line['font'] === 'small', $line['size']['w']),
                    mb_strlen($line['text']),
                    "{$name}: {$line['text']}",
                );
            }
        }
    }

    public function testEveryFixtureEndsWithCutAndPrintsNoQuestionMarks(): void
    {
        foreach (EscPosFixtures::build() as $name => $fixture) {
            $marks = $fixture['preview']['marks'];
            $this->assertSame('cut', end($marks)['kind'], $name);
            $this->assertStringEndsWith("\x1DVA\x05", $fixture['bytes'], $name);
            // Otazník = znak, který kódová stránka nemá a nemá ani náhradu.
            foreach ($fixture['preview']['lines'] as $line) {
                $this->assertStringNotContainsString('?', $line['text'], "{$name}: {$line['text']}");
            }
        }
    }
}

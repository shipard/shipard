<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\Video;

use Shipard\Core\Server\CronProvisioner;
use Shipard\Module\Core\Exchange\Dataset\DatasetReader;

/**
 * Úložiště videí na vývojářském serveru (tasks/testing-phase7b.md D127):
 * `<root>/<id scénáře>/` s hotovým `<id>.mp4`, `meta.json` (sada, kotva,
 * commit, čas, délka, velikost), `check.json` (výsledek posledního
 * ověření) a pracovním `work/` (runner v něm dělá `<id>/raw.mp4`,
 * `timeline.json`, `result.json`; složené video jde do `work/out/<id>.mp4`).
 * Jedna verze na scénář: hotové video se **povýší** `rename` z `work/out`
 * a `meta.json` se zapíše až poté — přehrávač dashboardu nikdy nevidí
 * rozepsaný soubor, a dokud nové natočení nedoběhne, hraje předchozí.
 * Endpoint dashboardu vydá jen soubor, který `meta.json` jmenuje
 * ({@see fileFor()}).
 */
final class VideoStore
{
    public const string DIR_NAME = 'videos';
    public const string META_FILE = 'meta.json';
    public const string CHECK_FILE = 'check.json';
    public const string WORK_DIR = 'work';
    public const string OUT_DIR = 'out';
    public const string RESULT_FILE = 'result.json';
    public const string TIMELINE_FILE = 'timeline.json';

    private readonly string $rootDir;

    public function __construct(string $rootDir)
    {
        $this->rootDir = rtrim($rootDir, '/');
    }

    public static function defaultDir(): string
    {
        return CronProvisioner::RUN_DIR . '/' . self::DIR_NAME;
    }

    public function rootDir(): string
    {
        return $this->rootDir;
    }

    /** @throws VideoException id nemá bezpečný tvar */
    public function dir(string $id): string
    {
        if (preg_match(VideoScenario::ID_REGEX, $id) !== 1) {
            throw new VideoException("Id scénáře '{$id}' nemá bezpečný tvar");
        }
        return $this->rootDir . '/' . $id;
    }

    public function videoFile(string $id): string
    {
        return $id . '.mp4';
    }

    public function videoPath(string $id): string
    {
        return $this->dir($id) . '/' . $this->videoFile($id);
    }

    public function metaPath(string $id): string
    {
        return $this->dir($id) . '/' . self::META_FILE;
    }

    public function checkPath(string $id): string
    {
        return $this->dir($id) . '/' . self::CHECK_FILE;
    }

    /** `VIDEO_WORK_DIR` runneru. */
    public function workDir(string $id): string
    {
        return $this->dir($id) . '/' . self::WORK_DIR;
    }

    /** `VIDEO_OUT_DIR` runneru — složené video před povýšením. */
    public function outDir(string $id): string
    {
        return $this->workDir($id) . '/' . self::OUT_DIR;
    }

    public function composedPath(string $id): string
    {
        return $this->outDir($id) . '/' . $this->videoFile($id);
    }

    /** `result.json` runneru (píše ho do `<VIDEO_WORK_DIR>/<id>/`). */
    public function resultPath(string $id): string
    {
        return $this->workDir($id) . '/' . $id . '/' . self::RESULT_FILE;
    }

    public function timelinePath(string $id): string
    {
        return $this->workDir($id) . '/' . $id . '/' . self::TIMELINE_FILE;
    }

    /**
     * Adresář scénáře a čistý `work/` před během (hotové video i meta
     * zůstávají, dokud nové natočení nedoběhne).
     *
     * @throws VideoException adresář nejde vytvořit
     */
    public function prepare(string $id): void
    {
        $dir = $this->dir($id);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
            throw new VideoException("Úložiště videa {$dir} nejde vytvořit");
        }
        DatasetReader::removeTree($this->workDir($id));
        if (!@mkdir($this->outDir($id), 0750, true)) {
            throw new VideoException("Pracovní adresář {$this->workDir($id)} nejde vytvořit");
        }
    }

    /**
     * Povýší složené video z `work/out` na hotové a zapíše `meta.json`
     * (až po přesunu). Vrací meta vč. velikosti a názvu souboru.
     *
     * @param array<string, mixed> $meta sada, kotva, commit, čas, délka
     * @return array<string, mixed>
     * @throws VideoException složené video chybí nebo přesun selhal
     */
    public function promote(string $id, array $meta): array
    {
        $from = $this->composedPath($id);
        if (!is_file($from)) {
            throw new VideoException("Složené video chybí: {$from}");
        }
        $to = $this->videoPath($id);
        if (!@rename($from, $to)) {
            throw new VideoException("Video nejde přesunout na {$to}");
        }
        @chmod($to, 0640);
        $size = filesize($to);
        $meta = ['scenario' => $id, 'file' => $this->videoFile($id)] + $meta;
        $meta['bytes'] = $size === false ? null : $size;
        DatasetBuildCache::writeJsonAtomic($this->metaPath($id), $meta);
        return $meta;
    }

    /**
     * @param array<string, mixed> $check
     * @throws VideoException
     */
    public function writeCheck(string $id, array $check): void
    {
        $dir = $this->dir($id);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
            throw new VideoException("Úložiště videa {$dir} nejde vytvořit");
        }
        DatasetBuildCache::writeJsonAtomic($this->checkPath($id), ['scenario' => $id] + $check);
    }

    /** @return array{meta: ?array<string, mixed>, check: ?array<string, mixed>, hasVideo: bool} */
    public function read(string $id): array
    {
        $meta = self::readJson($this->metaPath($id));
        return [
            'meta' => $meta,
            'check' => self::readJson($this->checkPath($id)),
            'hasVideo' => $this->fileFor($id) !== null,
        ];
    }

    /** Cesta hotového videa — jen když ho `meta.json` jmenuje a existuje; jinak null. */
    public function fileFor(string $id): ?string
    {
        $meta = self::readJson($this->metaPath($id));
        if ($meta === null || ($meta['file'] ?? null) !== $this->videoFile($id)) {
            return null;
        }
        $path = $this->videoPath($id);
        return is_file($path) ? $path : null;
    }

    /** `result.json` runneru po běhu; null = chybí nebo není JSON objekt. */
    public function readResult(string $id): ?array
    {
        return self::readJson($this->resultPath($id));
    }

    /** Délka záznamu z `timeline.json` runneru v ms; null = neznámá. */
    public function readDurationMs(string $id): ?int
    {
        $timeline = self::readJson($this->timelinePath($id));
        $duration = $timeline['duration'] ?? null;
        return is_int($duration) || is_float($duration) ? (int) round($duration * 1000) : null;
    }

    /** @return array<string, mixed>|null */
    public static function readJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }
}

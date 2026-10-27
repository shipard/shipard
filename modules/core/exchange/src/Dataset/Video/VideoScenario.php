<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\Video;

use Shipard\Core\Utils\JsoncParser;

/**
 * Scénář videa (`demo/scenarios/<id>.jsonc`, tasks/testing-phase7b.md § 1,
 * #103 D119–D121) z pohledu `video-run`: `id` (= název souboru), `dataset`
 * (demo sada, jejíž existenci ověřuje preflight), `locale` a `timezone`
 * prohlížeče (výchozí `cs-CZ` / `Europe/Prague`, jako v runneru), `clockTime`
 * (čas dne pevných hodin, výchozí 09:30 — D120) a titulek s popisem
 * z úvodního bloku komentářů `//` (první řádek = titulek, další do prázdného
 * komentáře nebo konce bloku = popis). Kroky validuje runner sám.
 */
final readonly class VideoScenario
{
    public const string ID_REGEX = '/^[a-z0-9][a-z0-9-]*$/';
    public const string DATASET_REGEX = '/^[a-z0-9][a-z0-9-]*$/';
    public const string CLOCK_TIME_REGEX = '/^([01]\d|2[0-3]):[0-5]\d$/';
    public const string DEFAULT_LOCALE = 'cs-CZ';
    public const string DEFAULT_TIMEZONE = 'Europe/Prague';
    public const string DEFAULT_CLOCK_TIME = '09:30';

    public function __construct(
        public string $id,
        public string $path,
        public string $dataset,
        public string $locale,
        public string $timezone,
        public string $clockTime,
        public string $title,
        public string $description,
        public int $stepCount,
    ) {}

    /**
     * @throws VideoException soubor nejde přečíst nebo scénář nesedí
     */
    public static function load(string $path): self
    {
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new VideoException("Scénář {$path} nejde přečíst");
        }
        try {
            $raw = JsoncParser::parse($content);
        } catch (\RuntimeException $e) {
            throw new VideoException("Scénář {$path} není platný JSONC: " . $e->getMessage(), $e);
        }
        if (!is_array($raw)) {
            throw new VideoException("Scénář {$path} musí být objekt");
        }
        $id = $raw['id'] ?? null;
        if (!is_string($id) || preg_match(self::ID_REGEX, $id) !== 1) {
            throw new VideoException("Scénář {$path}: id musí být z malých písmen, číslic a pomlček");
        }
        $expectedId = basename($path, '.jsonc');
        if ($id !== $expectedId) {
            throw new VideoException("Scénář {$path}: id '{$id}' neodpovídá názvu souboru '{$expectedId}' — id určuje adresář videa i úlohy");
        }
        $dataset = $raw['dataset'] ?? null;
        if (!is_string($dataset) || preg_match(self::DATASET_REGEX, $dataset) !== 1) {
            throw new VideoException("Scénář {$id}: chybí dataset (název demo sady v demo/datasets/, malá písmena, číslice a pomlčky)");
        }
        $locale = self::optionalString($raw, 'locale', self::DEFAULT_LOCALE, $id);
        $timezone = self::optionalString($raw, 'timezone', self::DEFAULT_TIMEZONE, $id);
        try {
            new \DateTimeZone($timezone);
        } catch (\Throwable) {
            throw new VideoException("Scénář {$id}: neznámá časová zóna '{$timezone}'");
        }
        $clockTime = self::optionalString($raw, 'clockTime', self::DEFAULT_CLOCK_TIME, $id);
        if (preg_match(self::CLOCK_TIME_REGEX, $clockTime) !== 1) {
            throw new VideoException("Scénář {$id}: clockTime musí být HH:MM, je '{$clockTime}'");
        }
        $steps = $raw['steps'] ?? null;
        if (!is_array($steps) || $steps === []) {
            throw new VideoException("Scénář {$id} nemá žádné kroky");
        }
        [$title, $description] = self::header($content, $id);

        return new self($id, $path, $dataset, $locale, $timezone, $clockTime, $title, $description, count($steps));
    }

    /**
     * Pevný čas prohlížeče (D120): kotva + `clockTime` v časové zóně
     * scénáře, ISO 8601 s posunem (`VIDEO_FIXED_TIME` runneru).
     *
     * @param string $anchor `YYYY-MM-DD`
     * @throws VideoException kotva není datum
     */
    public function fixedTime(string $anchor): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $anchor) !== 1 || !checkdate((int) substr($anchor, 5, 2), (int) substr($anchor, 8, 2), (int) substr($anchor, 0, 4))) {
            throw new VideoException("Kotva musí být datum YYYY-MM-DD, je '{$anchor}'");
        }
        return (new \DateTimeImmutable("{$anchor} {$this->clockTime}:00", new \DateTimeZone($this->timezone)))->format(\DATE_ATOM);
    }

    /** @return array<string, mixed> pro report a dashboard */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'path' => $this->path,
            'title' => $this->title,
            'description' => $this->description,
            'dataset' => $this->dataset,
            'locale' => $this->locale,
            'timezone' => $this->timezone,
            'clockTime' => $this->clockTime,
            'stepCount' => $this->stepCount,
        ];
    }

    /** @param array<string, mixed> $raw */
    private static function optionalString(array $raw, string $key, string $default, string $id): string
    {
        if (!array_key_exists($key, $raw)) {
            return $default;
        }
        $value = $raw[$key];
        if (!is_string($value) || trim($value) === '') {
            throw new VideoException("Scénář {$id}: {$key} musí být neprázdný řetězec");
        }
        return trim($value);
    }

    /**
     * Titulek a popis z úvodních řádků `//`: první komentář = titulek, další
     * do prvního prázdného komentáře (`//` bez textu) nebo nekomentářového
     * řádku = popis. Bez komentáře je titulek id.
     *
     * @return array{0: string, 1: string}
     */
    private static function header(string $content, string $id): array
    {
        $lines = [];
        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            $trimmed = trim($line);
            if (!str_starts_with($trimmed, '//')) {
                break;
            }
            $text = trim(substr($trimmed, 2));
            if ($text === '' && $lines !== []) {
                break;
            }
            if ($text !== '') {
                $lines[] = $text;
            }
        }
        if ($lines === []) {
            return [$id, ''];
        }
        $title = array_shift($lines);
        return [$title, implode(' ', $lines)];
    }
}

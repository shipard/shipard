<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\Video;

use Shipard\Core\Server\CronProvisioner;
use Shipard\Module\Core\Exchange\Dataset\DatasetReader;

/**
 * Mezipaměť demo sady sestavené s kotvou (tasks/testing-phase7b.md D128):
 * `<root>/<sada>/<kotva>/` je výstup `build.py --out`, vedle dat v něm
 * leží `.build-state.json` (cache PDF generátoru) a značka `build.json`
 * `{dataset, anchor, sourceHash, builtAt, commit}` zapsaná až po úspěšném
 * buildu — adresář bez značky je rozestavěný a staví se znovu (zápis
 * generátoru je idempotentní). `sourceHash` je otisk příběhu, generátoru
 * a šablon: změna příběhu týž den sadu přestaví. Starší kotvy téže sady
 * se po úspěchu mažou ({@see prune()}). Běhy nad toutéž sadou se vylučují
 * zámkem `<root>/<sada>/.lock` (`flock`), drženým přes build i seed, aby
 * úklid jednoho běhu nesmazal sadu, kterou druhý právě seeduje.
 *
 * Kořen je pod `/opt/shipard/run` (vedle úloh dashboardu), ne v `/tmp`:
 * příkaz z terminálu a úloha dashboardu (PHP-FPM, případně `PrivateTmp`)
 * musí vidět tutéž mezipaměť.
 */
final class DatasetBuildCache
{
    public const string DIR_NAME = 'video-datasets';
    public const string MARKER_FILE = 'build.json';
    public const string STATE_FILE = '.build-state.json';
    public const string LOCK_FILE = '.lock';
    public const string BUILD_SCRIPT_REL = 'demo/datasets/build.py';
    public const string STORIES_REL = 'demo/datasets/stories';
    public const string TEMPLATES_REL = 'demo/datasets/templates';
    public const string GENERATOR_REL = 'tools/invoice-generator/invoicegen';

    private readonly string $rootDir;
    private readonly string $repoRoot;

    public function __construct(string $rootDir, string $repoRoot)
    {
        $this->rootDir = rtrim($rootDir, '/');
        $this->repoRoot = rtrim($repoRoot, '/');
    }

    public static function defaultDir(): string
    {
        return CronProvisioner::RUN_DIR . '/' . self::DIR_NAME;
    }

    public function rootDir(): string
    {
        return $this->rootDir;
    }

    /** @throws VideoException název sady nebo kotva nemají bezpečný tvar */
    public function dir(string $dataset, string $anchor): string
    {
        self::assertName($dataset);
        self::assertAnchor($anchor);
        return $this->rootDir . '/' . $dataset . '/' . $anchor;
    }

    public function markerPath(string $dataset, string $anchor): string
    {
        return $this->dir($dataset, $anchor) . '/' . self::MARKER_FILE;
    }

    public function statePath(string $dataset, string $anchor): string
    {
        return $this->dir($dataset, $anchor) . '/' . self::STATE_FILE;
    }

    /**
     * Otisk zdrojů sady: sha256 nad seřazenými relativními cestami a obsahy
     * příběhu (`demo/datasets/stories/<sada>/**` bez `build-state.json`),
     * `build.py`, šablon a generátoru (`invoicegen/**\/*.py` bez testů
     * a `__pycache__`).
     */
    public function sourceHash(string $dataset): string
    {
        self::assertName($dataset);
        $files = [];
        $story = $this->repoRoot . '/' . self::STORIES_REL . '/' . $dataset;
        foreach (self::walk($story) as $file) {
            if (basename($file) !== 'build-state.json') {
                $files[] = $file;
            }
        }
        $build = $this->repoRoot . '/' . self::BUILD_SCRIPT_REL;
        if (is_file($build)) {
            $files[] = $build;
        }
        array_push($files, ...self::walk($this->repoRoot . '/' . self::TEMPLATES_REL));
        foreach (self::walk($this->repoRoot . '/' . self::GENERATOR_REL) as $file) {
            $rel = substr($file, strlen($this->repoRoot) + 1);
            if (str_ends_with($file, '.py') && !str_contains($rel, '__pycache__') && !str_contains($rel, '/tests/')) {
                $files[] = $file;
            }
        }
        sort($files, SORT_STRING);
        $ctx = hash_init('sha256');
        foreach ($files as $file) {
            hash_update($ctx, substr($file, strlen($this->repoRoot) + 1) . "\0" . hash_file('sha256', $file) . "\n");
        }
        return hash_final($ctx);
    }

    /** Sada pro kotvu je sestavená a platná (značka se shodným otiskem + manifest). */
    public function isFresh(string $dataset, string $anchor, string $sourceHash): bool
    {
        $marker = $this->readMarker($dataset, $anchor);
        return $marker !== null
            && ($marker['dataset'] ?? null) === $dataset
            && ($marker['anchor'] ?? null) === $anchor
            && ($marker['sourceHash'] ?? null) === $sourceHash
            && is_file($this->dir($dataset, $anchor) . '/' . DatasetReader::MANIFEST_FILE);
    }

    /** @return array<string, mixed>|null */
    public function readMarker(string $dataset, string $anchor): ?array
    {
        $path = $this->markerPath($dataset, $anchor);
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    /**
     * Připraví adresář sady (bez značky) a vrátí ho.
     *
     * @throws VideoException adresář nejde vytvořit
     */
    public function prepare(string $dataset, string $anchor): string
    {
        $dir = $this->dir($dataset, $anchor);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
            throw new VideoException("Mezipaměť sady {$dir} nejde vytvořit");
        }
        @unlink($dir . '/' . self::MARKER_FILE);
        return $dir;
    }

    /**
     * Argv generátoru: `python3 build.py --story <sada> --anchor <kotva>
     * --out <adresář> --state <adresář>/.build-state.json`.
     *
     * @return list<string>
     */
    public function buildArgv(string $python3, string $dataset, string $anchor): array
    {
        return [
            $python3,
            $this->repoRoot . '/' . self::BUILD_SCRIPT_REL,
            '--story', $dataset,
            '--anchor', $anchor,
            '--out', $this->dir($dataset, $anchor),
            '--state', $this->statePath($dataset, $anchor),
        ];
    }

    /**
     * Značka po úspěšném buildu (atomicky).
     *
     * @throws VideoException značka nejde zapsat
     */
    public function markBuilt(string $dataset, string $anchor, string $sourceHash, ?string $commit, \DateTimeImmutable $now): void
    {
        $marker = [
            'dataset' => $dataset,
            'anchor' => $anchor,
            'sourceHash' => $sourceHash,
            'builtAt' => $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'commit' => $commit,
        ];
        self::writeJsonAtomic($this->markerPath($dataset, $anchor), $marker);
    }

    /**
     * Smaže ostatní kotvy sady (D128). Vrací smazané adresáře.
     *
     * @return list<string>
     */
    public function prune(string $dataset, string $keepAnchor): array
    {
        self::assertName($dataset);
        self::assertAnchor($keepAnchor);
        $removed = [];
        foreach (glob($this->rootDir . '/' . $dataset . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (basename($dir) === $keepAnchor) {
                continue;
            }
            DatasetReader::removeTree($dir);
            $removed[] = $dir;
        }
        return $removed;
    }

    /**
     * Výlučný zámek sady (blokující) — držet přes build i seed.
     *
     * @return resource
     * @throws VideoException zámek nejde získat
     */
    public function lock(string $dataset)
    {
        self::assertName($dataset);
        $dir = $this->rootDir . '/' . $dataset;
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
            throw new VideoException("Mezipaměť sady {$dir} nejde vytvořit");
        }
        $handle = @fopen($dir . '/' . self::LOCK_FILE, 'c');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            throw new VideoException("Zámek sady {$dir}/" . self::LOCK_FILE . ' nejde získat');
        }
        return $handle;
    }

    /** @param resource $handle */
    public function unlock($handle): void
    {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @throws VideoException */
    public static function assertName(string $dataset): void
    {
        if (preg_match(VideoScenario::DATASET_REGEX, $dataset) !== 1) {
            throw new VideoException("Název sady '{$dataset}' nemá bezpečný tvar (malá písmena, číslice, pomlčky)");
        }
    }

    /** @throws VideoException */
    public static function assertAnchor(string $anchor): void
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $anchor) !== 1 || !checkdate((int) substr($anchor, 5, 2), (int) substr($anchor, 8, 2), (int) substr($anchor, 0, 4))) {
            throw new VideoException("Kotva musí být datum YYYY-MM-DD, je '{$anchor}'");
        }
    }

    /**
     * @param array<string, mixed> $data
     * @throws VideoException
     */
    public static function writeJsonAtomic(string $path, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION);
        $tmp = @tempnam(dirname($path), '.' . basename($path) . '-');
        if ($json === false || $tmp === false || @file_put_contents($tmp, $json . "\n") === false || !@chmod($tmp, 0640) || !@rename($tmp, $path)) {
            if (is_string($tmp)) {
                @unlink($tmp);
            }
            throw new VideoException("Soubor {$path} nejde zapsat");
        }
    }

    /** @return list<string> soubory pod adresářem (rekurzivně), bez pořadí */
    private static function walk(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $files[] = $file->getPathname();
            }
        }
        return $files;
    }
}

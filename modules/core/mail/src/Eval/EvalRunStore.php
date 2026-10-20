<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Eval;

use Shipard\Module\Core\Exchange\Dataset\DatasetReader;

/**
 * Soubory výsledků evaluace (D36): `tests/eval/<název sady>/<YYYY-MM-DD>-<varianta>[-<n>].json`
 * mimo generovanou sadu, formát `shpd.eval.run.v1`. Hash obsahu sady
 * (`mail/` včetně příloh, `expected/` a `exchangeRates/` — #103 D81) váže
 * běhy na stejná data — jiný hash = jiná sada (porovnání běhů ho odmítne).
 */
final class EvalRunStore
{
    public const string FORMAT = 'shpd.eval.run.v1';
    private const array HASHED_DIRS = ['mail', 'expected', 'exchangeRates'];

    public function __construct(private readonly string $dir) {}

    public static function defaultDir(string $repoRoot, string $setName): string
    {
        return rtrim($repoRoot, '/') . '/tests/eval/' . $setName;
    }

    public function dir(): string
    {
        return $this->dir;
    }

    /** První volná cesta `<datum>-<varianta>[-n].json`. */
    public function nextPath(string $date, string $variant): string
    {
        $base = $this->dir . '/' . $date . '-' . $variant;
        if (!file_exists($base . '.json')) {
            return $base . '.json';
        }
        for ($n = 2; ; $n++) {
            if (!file_exists("{$base}-{$n}.json")) {
                return "{$base}-{$n}.json";
            }
        }
    }

    /**
     * @param array<string, mixed> $run
     * @return string zapsaná cesta
     * @throws EvalException
     */
    public function write(array $run, string $date, string $variant): string
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0755, true) && !is_dir($this->dir)) {
            throw new EvalException("nejde založit složku výsledků {$this->dir}");
        }
        $path = $this->nextPath($date, $variant);
        $json = json_encode($run, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if ($json === false || @file_put_contents($path, $json . "\n") === false) {
            throw new EvalException("nejde zapsat {$path}");
        }
        return $path;
    }

    /**
     * @return array<string, mixed>
     * @throws EvalException
     */
    public static function read(string $path): array
    {
        if (!is_file($path)) {
            throw new EvalException("soubor výsledků {$path} neexistuje");
        }
        $run = json_decode((string) file_get_contents($path), true);
        if (!is_array($run) || ($run['format'] ?? null) !== self::FORMAT) {
            throw new EvalException("{$path} není " . self::FORMAT);
        }
        return $run;
    }

    /**
     * Všechny čitelné běhy ve složce (volitelně jen nad sadou s hashem),
     * nejnovější první, každý s `_file`.
     *
     * @return list<array<string, mixed>>
     */
    public function runs(?string $setHash = null): array
    {
        $runs = [];
        foreach (glob($this->dir . '/*.json') ?: [] as $file) {
            try {
                $run = self::read($file);
            } catch (EvalException) {
                continue;
            }
            if ($setHash !== null && ($run['set']['hash'] ?? null) !== $setHash) {
                continue;
            }
            $run['_file'] = $file;
            $runs[] = $run;
        }
        usort($runs, static fn (array $a, array $b): int => strcmp((string) ($b['startedAt'] ?? ''), (string) ($a['startedAt'] ?? '')));
        return $runs;
    }

    /**
     * Běhy varianty nad touž sadou (podle hashe), nejnovější první.
     *
     * @return list<array<string, mixed>>
     */
    public function runsFor(string $variant, ?string $setHash = null): array
    {
        return array_values(array_filter(
            $this->runs($setHash),
            static fn (array $run): bool => ($run['variant']['name'] ?? null) === $variant,
        ));
    }

    /** sha256 nad relativními cestami a obsahem souborů v `mail/` (i přílohy) a `expected/`. */
    public static function datasetHash(DatasetReader $reader): string
    {
        $root = rtrim($reader->getRootDir(), '/');
        $entries = [];
        foreach (self::HASHED_DIRS as $dir) {
            $base = $root . '/' . $dir;
            if (!is_dir($base)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $entries[substr($file->getPathname(), strlen($root) + 1)] = hash_file('sha256', $file->getPathname());
                }
            }
        }
        ksort($entries, SORT_STRING);
        $ctx = hash_init('sha256');
        foreach ($entries as $rel => $hash) {
            hash_update($ctx, $rel . "\0" . $hash . "\n");
        }
        return hash_final($ctx);
    }
}

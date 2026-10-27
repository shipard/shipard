<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\Video;

/**
 * Scénáře videí v checkoutu (`demo/scenarios/*.jsonc`, tasks/testing-phase7b.md
 * § 3): výpis pro dashboard (i s neplatnými — nesou větu problému místo
 * scénáře) a překlad argumentu `video-run` (id nebo cesta) na scénář.
 * Cesta musí po `realpath` ležet v adresáři scénářů — z parametru se
 * nikdy neskládá cesta jinam; id se bere ze souboru, ne z argumentu.
 */
final class VideoScenarioCatalog
{
    public const string DIR = 'demo/scenarios';

    private readonly string $dir;

    public function __construct(string $repoRoot)
    {
        $this->dir = rtrim($repoRoot, '/') . '/' . self::DIR;
    }

    public function dir(): string
    {
        return $this->dir;
    }

    public function isAvailable(): bool
    {
        return is_dir($this->dir);
    }

    /**
     * Všechny soubory `*.jsonc` podle názvu; neplatný scénář nese `problem`.
     *
     * @return list<array{id: string, path: string, scenario: ?VideoScenario, problem: ?string}>
     */
    public function scenarios(): array
    {
        $files = glob($this->dir . '/*.jsonc') ?: [];
        sort($files, SORT_STRING);
        $list = [];
        foreach ($files as $file) {
            $id = basename($file, '.jsonc');
            try {
                $list[] = ['id' => $id, 'path' => $file, 'scenario' => VideoScenario::load($file), 'problem' => null];
            } catch (VideoException $e) {
                $list[] = ['id' => $id, 'path' => $file, 'scenario' => null, 'problem' => $e->getMessage()];
            }
        }
        return $list;
    }

    /**
     * Scénář podle id (`s1-prijata-faktura`) nebo cesty k souboru v adresáři
     * scénářů (`demo/scenarios/s1-prijata-faktura.jsonc`, i absolutní).
     *
     * @throws VideoException neexistuje, leží mimo adresář scénářů, nebo nesedí
     */
    public function resolve(string $idOrPath): VideoScenario
    {
        $idOrPath = trim($idOrPath);
        if ($idOrPath === '') {
            throw new VideoException('Chybí scénář (id nebo cesta v ' . self::DIR . ')');
        }
        $looksLikePath = str_contains($idOrPath, '/') || str_ends_with($idOrPath, '.jsonc');
        if (!$looksLikePath) {
            if (preg_match(VideoScenario::ID_REGEX, $idOrPath) !== 1) {
                throw new VideoException("Scénář '{$idOrPath}' — id musí být z malých písmen, číslic a pomlček, nebo cesta k souboru v " . self::DIR);
            }
            $path = $this->dir . '/' . $idOrPath . '.jsonc';
            if (!is_file($path)) {
                throw new VideoException("Scénář '{$idOrPath}' neexistuje (" . self::DIR . "/{$idOrPath}.jsonc)");
            }
            return VideoScenario::load($path);
        }
        $real = realpath($idOrPath);
        $dirReal = realpath($this->dir);
        if ($real === false || $dirReal === false || !is_file($real)) {
            throw new VideoException("Scénář {$idOrPath} neexistuje");
        }
        if (dirname($real) !== $dirReal) {
            throw new VideoException("Scénář {$idOrPath} leží mimo " . self::DIR . ' — video-run bere jen scénáře z checkoutu');
        }
        return VideoScenario::load($real);
    }
}

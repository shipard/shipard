<?php

declare(strict_types=1);

namespace Shipard\Core\Prints;

use Shipard\Core\Module\ModulePathResolver;

/**
 * Převod cesty šablony / katalogu z deklarace (`@<modul>/<adresář>`) na
 * adresář na disku: `<adresář modulu>/prints/<adresář>`. Jediné místo,
 * které zná umístění tiskových šablon v modulu — čte ho katalog překladů
 * i Twig loader.
 */
final class PrintTemplatePaths
{
    /** Podadresář modulu s tiskovými šablonami. */
    public const MODULE_DIR = 'prints';

    public function __construct(
        private readonly ModulePathResolver $modules,
    ) {}

    /**
     * @return array{module: string, dir: string}
     * @throws \InvalidArgumentException Cesta nemá tvar `@<modul>/<adresář>`.
     */
    public static function parse(string $path): array
    {
        if (!preg_match('#^@([a-z][a-z0-9]*\.[a-z][a-zA-Z0-9]*)/((?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+)$#', $path, $m)) {
            throw new \InvalidArgumentException("Invalid print template path '{$path}'");
        }
        return ['module' => $m[1], 'dir' => $m[2]];
    }

    /** Adresář šablony / katalogu; null, když modul na serveru není. */
    public function directory(string $path): ?string
    {
        $parsed = self::parse($path);
        $root   = $this->moduleRoot($parsed['module']);
        return $root === null ? null : $root . '/' . $parsed['dir'];
    }

    /** Kořen tiskových šablon modulu (`<modul>/prints`); null, když modul chybí. */
    public function moduleRoot(string $moduleId): ?string
    {
        $modulePath = $this->modules->getPath($moduleId);
        return $modulePath === null ? null : $modulePath . '/' . self::MODULE_DIR;
    }

    /**
     * Kořeny šablon všech modulů, které adresář `prints/` mají — Twig
     * namespace = id modulu.
     *
     * @return array<string, string> id modulu → adresář
     */
    public function moduleRoots(): array
    {
        $roots = [];
        foreach ($this->modules->allModuleIds() as $moduleId) {
            $root = $this->moduleRoot($moduleId);
            if ($root !== null && is_dir($root)) {
                $roots[$moduleId] = $root;
            }
        }
        return $roots;
    }
}

<?php

declare(strict_types=1);

namespace Shipard\Core\Module;

use Shipard\Core\Config\ServerConfig;

/**
 * Resolves module ids to absolute filesystem paths across one or more module
 * roots. The first root is the main (in-repo) `modules/` directory; subsequent
 * roots are additional third-party / customer module trees.
 *
 * Module discovery is purely directory-based: `{root}/{group}/{module}/` is a
 * module iff it contains `module.jsonc`. The id `{group}.{module}` is derived
 * from directory names; this class does not parse `module.jsonc`.
 *
 * A directory that holds a `module.jsonc` but whose group or module name does
 * not match the id format is an error, not a silent skip (#170 D11) — the
 * module would otherwise never load and nothing would say why. Directories
 * without `module.jsonc` are ignored.
 */
final class ModulePathResolver
{
    /** Tvar jména skupiny (adresář pod kořenem) — jedno slovo malými písmeny. */
    public const string GROUP_NAME_FORMAT = '[a-z][a-z0-9]*';

    /** Tvar jména modulu (adresář pod skupinou) — camelCase. */
    public const string MODULE_NAME_FORMAT = '[a-z][a-zA-Z0-9]*';

    private const string GROUP_NAME_RE = '/^' . self::GROUP_NAME_FORMAT . '$/';
    private const string MODULE_NAME_RE = '/^' . self::MODULE_NAME_FORMAT . '$/';

    /** @var list<string> */
    private readonly array $roots;

    /** @var array<string, string>  moduleId => absolute module directory */
    private readonly array $map;

    /** @var array<string, string>  moduleId => root the module was found in */
    private readonly array $moduleRoots;

    /** @var list<string>  module ids sorted alphabetically */
    private readonly array $sortedIds;

    /**
     * @param list<string> $roots Absolute paths to module root directories.
     *                            First entry is the main root; subsequent
     *                            entries are extra roots.
     *
     * @throws \RuntimeException on module-id collision across roots, when
     *         a configured root does not exist / is not a directory, or when
     *         a directory with `module.jsonc` has an invalid group or module
     *         name.
     */
    public function __construct(array $roots)
    {
        foreach ($roots as $root) {
            if (!is_dir($root)) {
                throw new \RuntimeException(
                    "Module root does not exist or is not a directory: '$root'"
                );
            }
        }
        $this->roots = array_values($roots);

        $map = [];
        $moduleRoots = [];
        foreach ($this->roots as $root) {
            foreach ($this->scanRoot($root) as $id => $path) {
                if (isset($map[$id])) {
                    throw new \RuntimeException(
                        "Module '$id' found in multiple roots: "
                      . "'{$map[$id]}' and '$path'"
                    );
                }
                $map[$id] = $path;
                $moduleRoots[$id] = $root;
            }
        }
        $this->map = $map;
        $this->moduleRoots = $moduleRoots;

        $ids = array_keys($map);
        sort($ids, SORT_STRING);
        $this->sortedIds = $ids;
    }

    /**
     * Builds a resolver from a loaded ServerConfig plus the well-known main
     * module root. The main root is always first; extras follow in the order
     * declared in server.json's `extraModulesPath`.
     *
     * @param ServerConfig $serverConfig  Must already be loaded.
     * @param string $mainModulesPath     Absolute path to the in-repo modules/ dir.
     */
    public static function fromServerConfig(
        ServerConfig $serverConfig,
        string $mainModulesPath,
    ): self {
        return new self(array_merge(
            [$mainModulesPath],
            $serverConfig->getExtraModulesPath(),
        ));
    }

    /**
     * Returns the absolute path to the directory containing `module.jsonc` for
     * the given module id, or null if no such module exists in any root.
     */
    public function getPath(string $moduleId): ?string
    {
        return $this->map[$moduleId] ?? null;
    }

    /**
     * Returns the root the module was found in, or null for an unknown module.
     */
    public function rootOf(string $moduleId): ?string
    {
        return $this->moduleRoots[$moduleId] ?? null;
    }

    /**
     * True when the module lives in the main (first) root — the in-repo
     * `modules/` tree; false for a module from an extra root and for an
     * unknown module.
     */
    public function isInMainRoot(string $moduleId): bool
    {
        $root = $this->moduleRoots[$moduleId] ?? null;
        return $root !== null && $root === ($this->roots[0] ?? null);
    }

    /**
     * Returns all discovered module ids across all roots, sorted alphabetically.
     *
     * @return list<string>
     */
    public function allModuleIds(): array
    {
        return $this->sortedIds;
    }

    /**
     * Returns the configured roots in their original order.
     *
     * @return list<string>
     */
    public function getRoots(): array
    {
        return $this->roots;
    }

    /**
     * Walks a single root and yields `{group}.{module} => absolutePath` for
     * every directory of the form `{root}/{group}/{module}/` that contains a
     * `module.jsonc`.
     *
     * @return iterable<string, string>
     * @throws \RuntimeException when a directory with `module.jsonc` has
     *         a group or module name outside the id format
     */
    private function scanRoot(string $root): iterable
    {
        foreach ($this->listSubdirs($root) as $group) {
            $groupDir = $root . '/' . $group;
            $groupValid = preg_match(self::GROUP_NAME_RE, $group) === 1;

            foreach ($this->listSubdirs($groupDir) as $module) {
                $moduleDir = $groupDir . '/' . $module;
                if (!is_file($moduleDir . '/module.jsonc')) continue;

                if (!$groupValid) {
                    throw new \RuntimeException(
                        "Invalid module group name '$group' in '$moduleDir': "
                      . 'a group is one lowercase word (' . self::GROUP_NAME_FORMAT . ')'
                    );
                }
                if (preg_match(self::MODULE_NAME_RE, $module) !== 1) {
                    throw new \RuntimeException(
                        "Invalid module name '$module' in '$moduleDir': "
                      . 'a module name is camelCase starting with a lowercase letter ('
                      . self::MODULE_NAME_FORMAT . ')'
                    );
                }
                yield "$group.$module" => $moduleDir;
            }
        }
    }

    /** @return list<string> */
    private function listSubdirs(string $dir): array
    {
        $entries = @scandir($dir);
        if ($entries === false) return [];
        $out = [];
        foreach ($entries as $e) {
            if ($e === '.' || $e === '..') continue;
            if (is_dir($dir . '/' . $e)) $out[] = $e;
        }
        return $out;
    }
}

<?php

declare(strict_types=1);

namespace Shipard\Core\DevUpdate;

/**
 * Prostředí podprocesů kontroly a aktualizace (#129 D20, D21). PHP-FPM
 * nepředává `PATH` (`clear_env`), proto ho dostávají potomci vždy napevno
 * — stejný v terminálu i v úloze dashboardu, aby pojistka `missingTool`
 * platila pro obojí. Git navíc nikdy nesmí čekat na heslo:
 * `GIT_TERMINAL_PROMPT=0` a `GIT_ASKPASS=/bin/true`. Zbytek prostředí
 * (HOME, LANG…) se dědí.
 */
final class UpdateEnvironment
{
    public const string DEFAULT_PATH = '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';

    /** Nástroje, bez kterých aktualizace nedoběhne (pojistka `missingTool`). */
    public const array TOOLS = ['git', 'composer', 'npm'];

    public const float FETCH_TIMEOUT_SECONDS = 30.0;
    public const float LOCAL_TIMEOUT_SECONDS = 10.0;

    private function __construct()
    {
    }

    /**
     * Prostředí potomka: zděděné + pevný PATH + git bez dotazů + `LC_ALL=C`
     * (anglické hlášky gitu v `error`).
     *
     * @return array<string, string>
     */
    public static function env(string $path = self::DEFAULT_PATH): array
    {
        $env = [];
        foreach (getenv() as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $env[$key] = $value;
            }
        }
        $env['PATH'] = $path;
        $env['GIT_TERMINAL_PROMPT'] = '0';
        $env['GIT_ASKPASS'] = '/bin/true';
        $env['LC_ALL'] = 'C';
        return $env;
    }

    /** Absolutní cesta spustitelného `$name` v `$path`; null = chybí. */
    public static function findTool(string $name, string $path = self::DEFAULT_PATH): ?string
    {
        foreach (explode(':', $path) as $dir) {
            if ($dir === '') {
                continue;
            }
            $candidate = rtrim($dir, '/') . '/' . $name;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Které z {@see TOOLS} v `$path` chybí.
     *
     * @return list<string>
     */
    public static function missingTools(string $path = self::DEFAULT_PATH): array
    {
        $missing = [];
        foreach (self::TOOLS as $tool) {
            if (self::findTool($tool, $path) === null) {
                $missing[] = $tool;
            }
        }
        return $missing;
    }
}

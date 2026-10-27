<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\E2e;

use Shipard\Core\DevUpdate\UpdateEnvironment;

/**
 * Podproces Playwright Test (tasks/testing-phase7a.md I1, I3): `node
 * <cli.js> test --config <config>` v `tests/e2e`. Smyčku, zrušení (SIGINT
 * — Playwright zavře prohlížeč a dopíše reportery — a po grace SIGKILL)
 * i skládání prostředí dědí z {@see CancellableProcess}; tady je jen argv
 * a whitelist proměnných specu (`SHPD_E2E_*`) plus `CI=1`.
 */
class PlaywrightRunner extends CancellableProcess
{
    public const string ENV_PREFIX = 'SHPD_E2E_';

    /**
     * @param \Closure(): bool $shouldCancel
     */
    public function __construct(
        private readonly string $node,
        private readonly string $cli,
        private readonly string $config,
        string $cwd,
        \Closure $shouldCancel,
        float $graceSeconds = 20.0,
        int $pollMicroseconds = 200_000,
    ) {
        parent::__construct($cwd, $shouldCancel, $graceSeconds, $pollMicroseconds, self::SIGINT);
    }

    /** @return list<string> */
    public function argv(): array
    {
        return [$this->node, $this->cli, 'test', '--config', $this->config];
    }

    /**
     * Prostředí potomka: zděděné + pevný PATH, `HOME` (když chybí), proměnné
     * specu (jen `SHPD_E2E_*`) a `CI=1`.
     *
     * @param array<string, string> $e2eVars
     * @return array<string, string>
     */
    public static function environment(array $e2eVars, ?string $home = null, string $path = UpdateEnvironment::DEFAULT_PATH): array
    {
        foreach (array_keys($e2eVars) as $name) {
            if (!is_string($name) || !str_starts_with($name, self::ENV_PREFIX)) {
                throw new \InvalidArgumentException('E2E environment variable must start with ' . self::ENV_PREFIX . ": {$name}");
            }
        }
        $env = self::environmentFor($e2eVars, [self::ENV_PREFIX], $home, $path);
        $env['CI'] = '1';
        return $env;
    }

    protected function startFailureMessage(): string
    {
        return 'Playwright nejde spustit: ' . implode(' ', $this->argv());
    }
}

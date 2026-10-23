<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\E2e;

use Shipard\Core\Ai\ServerAiConnection;
use Shipard\Core\DevJobs\DevActionGuard;
use Shipard\Core\DevUpdate\UpdateEnvironment;
use Shipard\Core\Server\DataSourceInfo;

/**
 * Preflight proklikání datové sady (`shpd-ds dataset-e2e`, tasks/testing-phase7a.md
 * § 3, #103 D91): nástroje v checkoutu a na stroji ({@see toolProblems()}),
 * pojistka spouštěcích akcí ({@see problems()} přes {@see DevActionGuard}:
 * režim serveru přesně `development`, zdroj dat třídy `free`, sada ze seznamu
 * sad) a dosažitelnost aplikace ({@see appReachable()}, I13 — past
 * `server_name` nginx; s připíchnutou adresou z {@see LocalBaseUrl}, D97).
 *
 * Nástroje: Node v **pevném** `PATH` ({@see UpdateEnvironment::DEFAULT_PATH},
 * stejný v terminálu i v úloze dashboardu — nvm v neinteraktivním shellu
 * nepomůže), Playwright Test v `tests/e2e/node_modules` (I1: spouští se
 * `node …/cli.js`, ne `npx`) a Chromium Playwrightu v cache uživatele, pod
 * kterým příkaz běží (`PLAYWRIGHT_BROWSERS_PATH`, jinak
 * `~/.cache/ms-playwright`; revize z `playwright-core/browsers.json`, hotová
 * instalace = soubor `INSTALLATION_COMPLETE`). Každý problém nese přesnou
 * větu, co spustit ({@see REMEDY}) — dashboard ji ukazuje jako nápovědu
 * neaktivní akce Proklikat.
 */
class E2ePreflight
{
    public const string REMEDY = 'cd tests/e2e && npm ci && npx playwright install chromium';

    public const string E2E_DIR = 'tests/e2e';
    public const string CLI_REL = self::E2E_DIR . '/node_modules/@playwright/test/cli.js';
    public const string BROWSERS_JSON_REL = self::E2E_DIR . '/node_modules/playwright-core/browsers.json';
    public const string CONFIG_REL = self::E2E_DIR . '/playwright.config.mjs';
    public const string BROWSER_NAME = 'chromium';
    public const string INSTALLED_MARKER = 'INSTALLATION_COMPLETE';
    public const float APP_TIMEOUT_SECONDS = 5.0;

    private readonly string $repoRoot;
    private readonly string $browsersPath;
    private readonly string $home;

    /**
     * @param string $repoRoot kořen checkoutu (`tests/e2e/` v něm)
     * @param string $path PATH, ve kterém se hledá `node`
     * @param ?string $browsersPath cache prohlížečů Playwrightu; null = `PLAYWRIGHT_BROWSERS_PATH`, jinak `<home>/.cache/ms-playwright`
     * @param ?string $home domovský adresář; null = podle efektivního uživatele (`posix_getpwuid`), jinak `HOME`
     */
    public function __construct(
        string $repoRoot,
        private readonly string $path = UpdateEnvironment::DEFAULT_PATH,
        ?string $browsersPath = null,
        ?string $home = null,
    ) {
        $this->repoRoot = rtrim($repoRoot, '/');
        $this->home = $home ?? self::homeDir();
        $this->browsersPath = rtrim($browsersPath ?? self::defaultBrowsersPath($this->home), '/');
    }

    /** Domovský adresář efektivního uživatele (pod ním leží cache prohlížeče); prázdný = neznámý. */
    public static function homeDir(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $info = @posix_getpwuid(posix_geteuid());
            if (is_array($info) && is_string($info['dir'] ?? null) && $info['dir'] !== '') {
                return $info['dir'];
            }
        }
        $env = getenv('HOME');
        return is_string($env) ? $env : '';
    }

    public static function defaultBrowsersPath(string $home): string
    {
        $env = getenv('PLAYWRIGHT_BROWSERS_PATH');
        if (is_string($env) && trim($env) !== '') {
            return trim($env);
        }
        return rtrim($home, '/') . '/.cache/ms-playwright';
    }

    public function repoRoot(): string
    {
        return $this->repoRoot;
    }

    public function home(): string
    {
        return $this->home;
    }

    public function browsersPath(): string
    {
        return $this->browsersPath;
    }

    /** Absolutní cesta k `node` v pevném PATH; null = chybí. */
    public function nodeBinary(): ?string
    {
        return UpdateEnvironment::findTool('node', $this->path);
    }

    public function cliPath(): string
    {
        return $this->repoRoot . '/' . self::CLI_REL;
    }

    public function configPath(): string
    {
        return $this->repoRoot . '/' . self::CONFIG_REL;
    }

    /** Pracovní adresář Playwrightu (`tests/e2e`). */
    public function e2eDir(): string
    {
        return $this->repoRoot . '/' . self::E2E_DIR;
    }

    /** Revize Chromia podle nainstalovaného `playwright-core`; null = soubor chybí nebo prohlížeč v něm není. */
    public function chromiumRevision(): ?string
    {
        $file = $this->repoRoot . '/' . self::BROWSERS_JSON_REL;
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($file), true);
        foreach (is_array($data['browsers'] ?? null) ? $data['browsers'] : [] as $browser) {
            if (($browser['name'] ?? null) === self::BROWSER_NAME && is_scalar($browser['revision'] ?? null)) {
                $revision = trim((string) $browser['revision']);
                return $revision === '' ? null : $revision;
            }
        }
        return null;
    }

    /** Adresář nainstalovaného Chromia; null bez známé revize. */
    public function browserDir(): ?string
    {
        $revision = $this->chromiumRevision();
        return $revision === null ? null : $this->browsersPath . '/' . self::BROWSER_NAME . '-' . $revision;
    }

    /**
     * Co chybí na stroji: Node, Playwright Test, prohlížeč. Prázdné = vše je.
     *
     * @return list<string>
     */
    public function toolProblems(): array
    {
        $problems = [];
        if ($this->nodeBinary() === null) {
            $problems[] = "Node není v PATH ({$this->path}) — nainstaluj Node 24+ do systémové cesty (nvm v neinteraktivním shellu nestačí); poté " . self::REMEDY;
        }
        if (!is_file($this->cliPath())) {
            $problems[] = 'Závislosti E2E chybí (' . self::CLI_REL . ') — spusť ' . self::REMEDY;
        }
        $browserDir = $this->browserDir();
        if ($browserDir === null) {
            if (is_file($this->cliPath())) {
                $problems[] = 'Revizi Chromia nejde určit (' . self::BROWSERS_JSON_REL . ') — spusť ' . self::REMEDY;
            }
        } elseif (!is_file($browserDir . '/' . self::INSTALLED_MARKER)) {
            $problems[] = "Chromium Playwrightu chybí ({$browserDir}/" . self::INSTALLED_MARKER . ') — spusť ' . self::REMEDY
                . ' pod uživatelem, který proklikání spouští (PHP-FPM pro úlohu dashboardu)';
        }
        if (!is_file($this->configPath())) {
            $problems[] = 'Konfigurace Playwrightu chybí (' . self::CONFIG_REL . ') — checkout je neúplný';
        }
        return $problems;
    }

    /**
     * Všechny důvody k odmítnutí (exit 2): režim serveru (null = nejde určit →
     * fail-closed), třída zdroje dat, sada ze seznamu, nástroje.
     *
     * @param list<string> $knownDatasets
     * @return list<string>
     */
    public function problems(?string $serverMode, ?DataSourceInfo $dataSource, string $datasetName, array $knownDatasets): array
    {
        $problems = [];
        if ($serverMode === null) {
            $problems[] = 'Režim serveru nejde určit (server.json) — dataset-e2e běží jen na vývojovém serveru (mode ' . ServerAiConnection::MODE_DEVELOPMENT . ')';
        } else {
            $guard = DevActionGuard::check($serverMode, $dataSource, true, $datasetName, $knownDatasets);
            if ($guard !== null) {
                $problems[] = $guard['message'];
            }
        }
        return [...$problems, ...$this->toolProblems()];
    }

    /**
     * Dosažitelnost aplikace zdroje dat (I13): HTTP GET, odpověď < 400 = ok
     * (null); jinak věta s důvodem. Přesměrování se nenásleduje — `/app/`
     * má odpovídat přímo, jinak je `--base-url` špatně. S `$pin` (D97) jde
     * dotaz na ověřenou adresu hostu (`CURLOPT_RESOLVE`), ne na to, co by
     * jméno přeložilo teď.
     */
    public function appReachable(string $url, float $timeout = self::APP_TIMEOUT_SECONDS, ?PinnedBaseUrl $pin = null): ?string
    {
        ['status' => $status, 'error' => $error] = $this->httpStatus($url, $timeout, $pin?->curlResolve());
        if ($status === null) {
            return "Aplikace na {$url} není dosažitelná" . ($error !== null ? " ({$error})" : '')
                . ' — zkontroluj --base-url (výchozí http://127.0.0.1; nginx musí mít Shipard jako výchozí server_name)';
        }
        if ($status >= 400) {
            return "Aplikace na {$url} odpověděla HTTP {$status} — zkontroluj --base-url a zdroj dat";
        }
        return null;
    }

    /**
     * Jeden HTTP GET bez přesměrování (curl, jen http/https); `$resolve` =
     * záznam `host:port:ip` pro `CURLOPT_RESOLVE` ({@see PinnedBaseUrl::curlResolve()}).
     * Šev pro testy.
     *
     * @return array{status: ?int, error: ?string}
     */
    protected function httpStatus(string $url, float $timeout, ?string $resolve = null): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['status' => null, 'error' => 'curl_init selhalo'];
        }
        $millis = max(1, (int) ceil($timeout * 1000));
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT_MS => $millis,
            CURLOPT_TIMEOUT_MS => $millis,
            CURLOPT_HTTPHEADER => ['Accept: text/html'],
            CURLOPT_USERAGENT => 'shpd-dataset-e2e',
        ];
        if ($resolve !== null) {
            $options[CURLOPT_RESOLVE] = [$resolve];
        }
        curl_setopt_array($ch, $options);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = $ok === false ? (curl_error($ch) ?: 'neznámá chyba curl') : null;
        if ($status === 0) {
            return ['status' => null, 'error' => $error ?? 'odpověď bez stavového řádku'];
        }
        return ['status' => $status, 'error' => null];
    }
}

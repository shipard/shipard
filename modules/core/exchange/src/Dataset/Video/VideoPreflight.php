<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\Video;

use Shipard\Core\Ai\ServerAiConnection;
use Shipard\Core\DevJobs\DevActionGuard;
use Shipard\Core\DevUpdate\UpdateEnvironment;
use Shipard\Core\Server\DataSourceInfo;
use Shipard\Module\Core\Exchange\Dataset\DatasetCatalog;
use Shipard\Module\Core\Exchange\Dataset\E2e\E2ePreflight;
use Shipard\Module\Core\Exchange\Dataset\E2e\PlaywrightInstall;

/**
 * Preflight `video-run` (tasks/testing-phase7b.md § 2, #103 D125): co musí
 * být na stroji, než se cokoli změní — Node v pevném PATH, závislosti
 * runneru (`tools/video-runner/node_modules`), Chromium Playwrightu
 * **runneru** (vlastní `browsers.json`, ne `tests/e2e`), `ffmpeg`,
 * `python3`, generátor demo sady (`demo/datasets/build.py`) a adresa
 * Gotenbergu (`render.url` v `server.json` — bez ní by `build.py` tiše
 * použil svou zapsanou adresu). Každý problém nese přesnou větu, co spustit
 * (podle `tools/video-runner/INSTALL.md`); dashboard ji ukazuje jako
 * nápovědu neaktivních akcí Ověřit / Natočit.
 *
 * Z {@see E2ePreflight} se dědí jen obecné: domovský adresář efektivního
 * uživatele (prohlížeč i ffmpeg patří tomu, pod kým úloha běží), `node`,
 * dosažitelnost API zdroje dat s připíchnutou adresou (D97) a HTTP sonda.
 */
class VideoPreflight extends E2ePreflight
{
    public const string RUNNER_DIR = 'tools/video-runner';
    public const string RUNNER_BIN_REL = self::RUNNER_DIR . '/bin/video-runner.mjs';
    public const string RUNNER_DEPS_REL = self::RUNNER_DIR . '/node_modules/playwright/package.json';
    public const string BUILD_SCRIPT_REL = 'demo/datasets/build.py';
    public const string STORIES_DIR_REL = 'demo/datasets/stories';
    public const string VIDEO_REMEDY = 'cd tools/video-runner && npm ci && npx playwright install chromium';
    public const float GOTENBERG_TIMEOUT_SECONDS = 3.0;

    private readonly PlaywrightInstall $runnerInstall;
    private readonly string $toolPath;

    /**
     * @param string $repoRoot kořen checkoutu
     * @param string $path PATH, ve kterém se hledají node, ffmpeg a python3
     * @param ?string $browsersPath cache prohlížečů Playwrightu; null = `PLAYWRIGHT_BROWSERS_PATH`, jinak `<home>/.cache/ms-playwright`
     * @param ?string $home domovský adresář; null = podle efektivního uživatele
     */
    public function __construct(string $repoRoot, string $path = UpdateEnvironment::DEFAULT_PATH, ?string $browsersPath = null, ?string $home = null)
    {
        parent::__construct($repoRoot, $path, $browsersPath, $home);
        $this->toolPath = $path;
        $this->runnerInstall = new PlaywrightInstall($this->repoRoot() . '/' . self::RUNNER_DIR . '/node_modules', $this->browsersPath());
    }

    public function toolPath(): string
    {
        return $this->toolPath;
    }

    /** Instalace Playwrightu runneru (vlastní revize Chromia). */
    public function runnerInstall(): PlaywrightInstall
    {
        return $this->runnerInstall;
    }

    public function runnerDir(): string
    {
        return $this->repoRoot() . '/' . self::RUNNER_DIR;
    }

    public function runnerBin(): string
    {
        return $this->repoRoot() . '/' . self::RUNNER_BIN_REL;
    }

    public function buildScript(): string
    {
        return $this->repoRoot() . '/' . self::BUILD_SCRIPT_REL;
    }

    public function storyDir(string $dataset): string
    {
        return $this->repoRoot() . '/' . self::STORIES_DIR_REL . '/' . $dataset;
    }

    public function ffmpegBinary(): ?string
    {
        return UpdateEnvironment::findTool('ffmpeg', $this->toolPath);
    }

    public function python3Binary(): ?string
    {
        return UpdateEnvironment::findTool('python3', $this->toolPath);
    }

    /**
     * Co chybí na stroji pro videa (bez zdroje dat a scénáře — pro nápovědu
     * dashboardu). Prázdné = vše je.
     *
     * @return list<string>
     */
    public function toolProblems(): array
    {
        $problems = [];
        if ($this->nodeBinary() === null) {
            $problems[] = "Node není v PATH ({$this->toolPath}) — nainstaluj Node 24+ do systémové cesty (nvm v neinteraktivním shellu nestačí); poté " . self::VIDEO_REMEDY;
        }
        if (!is_file($this->runnerBin())) {
            $problems[] = 'Video-runner chybí (' . self::RUNNER_BIN_REL . ') — checkout je neúplný';
        }
        if (!is_file($this->repoRoot() . '/' . self::RUNNER_DEPS_REL)) {
            $problems[] = 'Závislosti video-runneru chybí (' . self::RUNNER_DEPS_REL . ') — spusť ' . self::VIDEO_REMEDY;
        } else {
            $browserDir = $this->runnerInstall->browserDir();
            if ($browserDir === null) {
                $problems[] = 'Revizi Chromia video-runneru nejde určit (' . self::RUNNER_DIR . '/node_modules/' . PlaywrightInstall::BROWSERS_JSON_REL . ') — spusť ' . self::VIDEO_REMEDY;
            } elseif (!$this->runnerInstall->isInstalled()) {
                $problems[] = "Chromium Playwrightu pro video-runner chybí ({$browserDir}/" . PlaywrightInstall::INSTALLED_MARKER . ') — spusť ' . self::VIDEO_REMEDY
                    . ' pod uživatelem, který videa spouští (PHP-FPM pro úlohu dashboardu)';
            }
        }
        if ($this->ffmpegBinary() === null) {
            $problems[] = "ffmpeg není v PATH ({$this->toolPath}) — nainstaluj balíček ffmpeg (tools/video-runner/INSTALL.md § 1)";
        }
        if ($this->python3Binary() === null) {
            $problems[] = "python3 není v PATH ({$this->toolPath}) — generátor demo sady (demo/datasets/build.py) ho potřebuje";
        }
        if (!is_file($this->buildScript())) {
            $problems[] = 'Generátor demo sady chybí (' . self::BUILD_SCRIPT_REL . ') — checkout je neúplný';
        }
        return $problems;
    }

    /**
     * Všechny důvody k odmítnutí (exit 2): režim serveru, třída zdroje dat
     * (`free`), sada scénáře ze seznamu sad a druhu `demo` s příběhem
     * v `demo/datasets/stories/`, Gotenberg v `server.json`, nástroje.
     *
     * @return list<string>
     */
    public function videoProblems(?string $serverMode, ?DataSourceInfo $dataSource, VideoScenario $scenario, DatasetCatalog $catalog, ?string $gotenbergUrl): array
    {
        $problems = [];
        if ($serverMode === null) {
            $problems[] = 'Režim serveru nejde určit (server.json) — video-run běží jen na vývojovém serveru (mode ' . ServerAiConnection::MODE_DEVELOPMENT . ')';
        } else {
            $guard = DevActionGuard::check($serverMode, $dataSource, true, $scenario->dataset, $catalog->datasetNames());
            if ($guard !== null) {
                $problems[] = $guard['message'];
            }
        }
        $entry = $catalog->dataset($scenario->dataset);
        if ($entry !== null && $entry->kind !== DatasetCatalog::KIND_DEMO) {
            $problems[] = "Sada '{$scenario->dataset}' scénáře {$scenario->id} není demo sada (druh {$entry->kind}) — videa běží jen nad demo sadami z demo/datasets/";
        }
        if (!is_file($this->storyDir($scenario->dataset) . '/story.jsonc')) {
            $problems[] = "Příběh sady '{$scenario->dataset}' chybí (" . self::STORIES_DIR_REL . "/{$scenario->dataset}/story.jsonc) — sadu s kotvou nejde sestavit";
        }
        if ($gotenbergUrl === null || trim($gotenbergUrl) === '') {
            $problems[] = 'Gotenberg není nastavený (render.url v /etc/shipard/server.json) — PDF demo sady se bez něj nevyrobí (docs/operations/render-service.md)';
        }
        return [...$problems, ...$this->toolProblems()];
    }

    /**
     * Gotenberg odpovídá na `/health` (jen před stavbou sady — mrtvá služba
     * by se jinak ukázala jako traceback po 60 s na každé PDF). Null = ok.
     */
    public function gotenbergReachable(string $url, float $timeout = self::GOTENBERG_TIMEOUT_SECONDS): ?string
    {
        $health = rtrim($url, '/') . '/health';
        ['status' => $status, 'error' => $error] = $this->httpStatus($health, $timeout);
        if ($status === null) {
            return "Gotenberg na {$health} není dosažitelný" . ($error !== null ? " ({$error})" : '') . ' — zkontroluj render.url v server.json a běh služby';
        }
        if ($status >= 400) {
            return "Gotenberg na {$health} odpověděl HTTP {$status}";
        }
        return null;
    }
}

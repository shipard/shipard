<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\E2e;

/**
 * Instalace Playwrightu jednoho Node projektu (tasks/testing-phase7a.md I2,
 * tasks/testing-phase7b.md § 2): revize Chromia z
 * `<node_modules>/playwright-core/browsers.json` a adresář prohlížeče
 * v cache (`<browsersPath>/chromium-<revize>`, hotová instalace = soubor
 * `INSTALLATION_COMPLETE`). Projekty `tests/e2e` (proklikání) a
 * `tools/video-runner` (videa) mají každý vlastní `node_modules`, a tedy
 * i vlastní revizi — dnes shodnou, ale to není zaručené.
 */
final class PlaywrightInstall
{
    public const string BROWSERS_JSON_REL = 'playwright-core/browsers.json';
    public const string BROWSER_NAME = 'chromium';
    public const string INSTALLED_MARKER = 'INSTALLATION_COMPLETE';

    private readonly string $nodeModulesDir;
    private readonly string $browsersPath;

    /**
     * @param string $nodeModulesDir absolutní `node_modules` projektu
     * @param string $browsersPath cache prohlížečů Playwrightu ({@see E2ePreflight::defaultBrowsersPath()})
     */
    public function __construct(string $nodeModulesDir, string $browsersPath)
    {
        $this->nodeModulesDir = rtrim($nodeModulesDir, '/');
        $this->browsersPath = rtrim($browsersPath, '/');
    }

    public function nodeModulesDir(): string
    {
        return $this->nodeModulesDir;
    }

    public function browsersPath(): string
    {
        return $this->browsersPath;
    }

    public function browsersJsonPath(): string
    {
        return $this->nodeModulesDir . '/' . self::BROWSERS_JSON_REL;
    }

    /** Revize Chromia podle nainstalovaného `playwright-core`; null = soubor chybí nebo prohlížeč v něm není. */
    public function chromiumRevision(): ?string
    {
        $file = $this->browsersJsonPath();
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

    /** Chromium je nainstalované (značka hotové instalace v adresáři revize). */
    public function isInstalled(): bool
    {
        $dir = $this->browserDir();
        return $dir !== null && is_file($dir . '/' . self::INSTALLED_MARKER);
    }
}

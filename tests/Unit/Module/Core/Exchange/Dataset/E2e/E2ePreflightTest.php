<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Dataset\E2e;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Server\DataSourceInfo;
use Shipard\Core\Server\DataSourceInventory;
use Shipard\Module\Core\Exchange\Dataset\DatasetReader;
use Shipard\Module\Core\Exchange\Dataset\E2e\E2ePreflight;
use Shipard\Module\Core\Exchange\Dataset\E2e\PinnedBaseUrl;

/** Preflight s podvrženým HTTP stavem (síť se v unit testu nevolá). */
final class StubbedPreflight extends E2ePreflight
{
    /** @var array{status: ?int, error: ?string, code: ?string} */
    public array $http = ['status' => 200, 'error' => null, 'code' => null];
    /** @var list<array{0: string, 1: float, 2: ?string}> */
    public array $requests = [];

    protected function httpStatus(string $url, float $timeout, ?string $resolve = null): array
    {
        $this->requests[] = [$url, $timeout, $resolve];
        return $this->http + ['code' => null];
    }
}

/**
 * Preflight `dataset-e2e` (tasks/testing-phase7a.md § 3, I1, I12, I13): Node
 * v pevném PATH, `cli.js` Playwright Testu, Chromium podle revize
 * z `browsers.json`, režim serveru fail-closed, třída `free`, sada ze
 * seznamu; každý chybějící nástroj nese přesnou větu {@see E2ePreflight::REMEDY}.
 */
final class E2ePreflightTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/shpd_e2e_pre_' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/bin', 0700, true);
        mkdir($this->dir . '/repo/tests/e2e/node_modules/@playwright/test', 0700, true);
        mkdir($this->dir . '/repo/tests/e2e/node_modules/playwright-core', 0700, true);
        mkdir($this->dir . '/home/.cache/ms-playwright/chromium-1234', 0700, true);
        mkdir($this->dir . '/ds', 0700, true);
        self::tool($this->dir . '/bin/node');
        file_put_contents($this->dir . '/repo/tests/e2e/node_modules/@playwright/test/cli.js', "#!/usr/bin/env node\n");
        file_put_contents($this->dir . '/repo/tests/e2e/node_modules/playwright-core/browsers.json', json_encode([
            'browsers' => [
                ['name' => 'chromium', 'revision' => '1234', 'installByDefault' => true],
                ['name' => 'firefox', 'revision' => '1538', 'installByDefault' => true],
            ],
        ]));
        file_put_contents($this->dir . '/repo/tests/e2e/playwright.config.mjs', "export default {};\n");
        file_put_contents($this->dir . '/home/.cache/ms-playwright/chromium-1234/INSTALLATION_COMPLETE', '');
    }

    protected function tearDown(): void
    {
        DatasetReader::removeTree($this->dir);
    }

    private static function tool(string $path): void
    {
        file_put_contents($path, "#!/bin/sh\nexit 0\n");
        chmod($path, 0755);
    }

    private function preflight(): StubbedPreflight
    {
        return new StubbedPreflight($this->dir . '/repo', $this->dir . '/bin', null, $this->dir . '/home');
    }

    private function dataSource(array $config): DataSourceInfo
    {
        $id = 'abcd-abcd-abcd-abcd';
        @mkdir($this->dir . '/ds/' . $id . '/config', 0700, true);
        file_put_contents($this->dir . '/ds/' . $id . '/config/main.json', json_encode(['id' => $id, 'name' => 'DS', 'database_name' => 'x'] + $config));
        $info = (new DataSourceInventory($this->dir . '/ds'))->read($this->dir . '/ds/' . $id);
        $this->assertNotNull($info);
        return $info;
    }

    public function testPathsFollowTheRepoAndTheHomeDirectory(): void
    {
        $p = $this->preflight();
        $this->assertSame($this->dir . '/bin/node', $p->nodeBinary());
        $this->assertSame($this->dir . '/repo/tests/e2e/node_modules/@playwright/test/cli.js', $p->cliPath());
        $this->assertSame($this->dir . '/repo/tests/e2e/playwright.config.mjs', $p->configPath());
        $this->assertSame($this->dir . '/repo/tests/e2e', $p->e2eDir());
        $this->assertSame('1234', $p->chromiumRevision());
        $this->assertSame($this->dir . '/home/.cache/ms-playwright', $p->browsersPath());
        $this->assertSame($this->dir . '/home/.cache/ms-playwright/chromium-1234', $p->browserDir());
        $this->assertSame($this->dir . '/home', $p->home());
        $this->assertSame([], $p->toolProblems());

        $explicit = new StubbedPreflight($this->dir . '/repo/', $this->dir . '/bin', $this->dir . '/elsewhere/', $this->dir . '/home');
        $this->assertSame($this->dir . '/elsewhere', $explicit->browsersPath());
        $this->assertSame($this->dir . '/repo', $explicit->repoRoot());
    }

    public function testBrowsersPathHonoursPlaywrightEnvironmentVariable(): void
    {
        $previous = getenv('PLAYWRIGHT_BROWSERS_PATH');
        putenv('PLAYWRIGHT_BROWSERS_PATH=' . $this->dir . '/custom');
        try {
            $this->assertSame($this->dir . '/custom', E2ePreflight::defaultBrowsersPath($this->dir . '/home'));
            $this->assertSame($this->dir . '/custom', $this->preflight()->browsersPath());
        } finally {
            putenv($previous === false ? 'PLAYWRIGHT_BROWSERS_PATH' : 'PLAYWRIGHT_BROWSERS_PATH=' . $previous);
        }
        $this->assertSame($this->dir . '/home/.cache/ms-playwright', E2ePreflight::defaultBrowsersPath($this->dir . '/home/'));
        $this->assertNotSame('', E2ePreflight::homeDir(), 'domovský adresář efektivního uživatele');
    }

    public function testMissingNodeDependenciesOrBrowserEachNameTheRemedy(): void
    {
        unlink($this->dir . '/bin/node');
        $problems = $this->preflight()->toolProblems();
        $this->assertCount(1, $problems);
        $this->assertStringContainsString('Node není v PATH (' . $this->dir . '/bin)', $problems[0]);
        $this->assertStringContainsString(E2ePreflight::REMEDY, $problems[0]);
        self::tool($this->dir . '/bin/node');

        unlink($this->dir . '/repo/tests/e2e/node_modules/@playwright/test/cli.js');
        $problems = $this->preflight()->toolProblems();
        $this->assertCount(1, $problems, implode("\n", $problems));
        $this->assertStringContainsString('tests/e2e/node_modules/@playwright/test/cli.js', $problems[0]);
        $this->assertStringContainsString('spusť ' . E2ePreflight::REMEDY, $problems[0]);
        file_put_contents($this->dir . '/repo/tests/e2e/node_modules/@playwright/test/cli.js', '');

        unlink($this->dir . '/home/.cache/ms-playwright/chromium-1234/INSTALLATION_COMPLETE');
        $problems = $this->preflight()->toolProblems();
        $this->assertCount(1, $problems);
        $this->assertStringContainsString('Chromium Playwrightu chybí (' . $this->dir . '/home/.cache/ms-playwright/chromium-1234/INSTALLATION_COMPLETE)', $problems[0]);
        $this->assertStringContainsString(E2ePreflight::REMEDY, $problems[0]);
        $this->assertStringContainsString('pod uživatelem', $problems[0]);
        file_put_contents($this->dir . '/home/.cache/ms-playwright/chromium-1234/INSTALLATION_COMPLETE', '');

        unlink($this->dir . '/repo/tests/e2e/node_modules/playwright-core/browsers.json');
        $problems = $this->preflight()->toolProblems();
        $this->assertCount(1, $problems);
        $this->assertStringContainsString('Revizi Chromia nejde určit', $problems[0]);
        $this->assertStringContainsString(E2ePreflight::REMEDY, $problems[0]);
        file_put_contents($this->dir . '/repo/tests/e2e/node_modules/playwright-core/browsers.json', '{"browsers":[]}');
        $this->assertNull($this->preflight()->chromiumRevision());

        // Nic z toho na stroji: tři věty, každá s nápravou.
        unlink($this->dir . '/bin/node');
        unlink($this->dir . '/repo/tests/e2e/node_modules/@playwright/test/cli.js');
        $problems = $this->preflight()->toolProblems();
        $this->assertCount(2, $problems, 'bez cli.js se revize nehlásí — stačí jedna věta o závislostech');
        foreach ($problems as $problem) {
            $this->assertStringContainsString(E2ePreflight::REMEDY, $problem);
        }

        unlink($this->dir . '/repo/tests/e2e/playwright.config.mjs');
        $this->assertStringContainsString('playwright.config.mjs', implode("\n", $this->preflight()->toolProblems()));
    }

    public function testProblemsAreFailClosedOnServerModeDataClassAndDataset(): void
    {
        $free = $this->dataSource(['dataClass' => 'free']);
        $p = $this->preflight();

        $this->assertSame([], $p->problems('development', $free, 'cz-non-payer', ['cz-non-payer', 'cz-vat-payer']));

        $problems = $p->problems(null, $free, 'cz-non-payer', ['cz-non-payer']);
        $this->assertCount(1, $problems);
        $this->assertStringContainsString('Režim serveru nejde určit', $problems[0]);
        $this->assertStringContainsString('development', $problems[0]);

        $problems = $p->problems('production', $free, 'cz-non-payer', ['cz-non-payer']);
        $this->assertCount(1, $problems);
        $this->assertStringContainsString("mode is 'production'", $problems[0]);

        $problems = $p->problems('development', $this->dataSource(['dataClass' => 'realCopy']), 'cz-non-payer', ['cz-non-payer']);
        $this->assertCount(1, $problems);
        $this->assertStringContainsString('not a free data source', $problems[0]);

        $problems = $p->problems('development', $this->dataSource([]), 'cz-non-payer', ['cz-non-payer']);
        $this->assertStringContainsString('not set in main.json', $problems[0], 'bez třídy = realCopy');

        $problems = $p->problems('development', null, 'cz-non-payer', ['cz-non-payer']);
        $this->assertSame(['Data source not found'], $problems);

        $problems = $p->problems('development', $free, 'demo-it', ['cz-non-payer']);
        $this->assertSame(["Unknown dataset 'demo-it'"], $problems);

        // Nástroje se hlásí za pojistkou, ne místo ní.
        unlink($this->dir . '/bin/node');
        $problems = $this->preflight()->problems('production', $free, 'demo-it', ['cz-non-payer']);
        $this->assertCount(2, $problems);
        $this->assertStringContainsString('production', $problems[0]);
        $this->assertStringContainsString('Node není v PATH', $problems[1]);
    }

    public function testAppReachabilityAcceptsAnythingBelow400(): void
    {
        $p = $this->preflight();
        $url = E2ePreflight::probeUrl('http://127.0.0.1/', 'abcd-abcd-abcd-abcd');
        $this->assertSame('http://127.0.0.1/abcd-abcd-abcd-abcd/api/v1/_app/info', $url, 'zkouší se API, ne statická stránka');

        $this->assertNull($p->appReachable($url));
        $this->assertSame([[$url, E2ePreflight::APP_TIMEOUT_SECONDS, null]], $p->requests);

        $p->http = ['status' => 302, 'error' => null, 'code' => null];
        $this->assertNull($p->appReachable($url, 2.5), 'přesměrování je odpověď serveru, ne nedostupnost');
        $this->assertSame(2.5, $p->requests[1][1]);

        $p->http = ['status' => 404, 'error' => null, 'code' => 'UNKNOWN_DATASOURCE'];
        $problem = $p->appReachable($url);
        $this->assertStringContainsString("Aplikace na {$url} odpověděla HTTP 404 (UNKNOWN_DATASOURCE)", (string) $problem);
        $this->assertStringContainsString('--base-url', (string) $problem);

        $p->http = ['status' => 502, 'error' => null, 'code' => null];
        $this->assertStringContainsString('HTTP 502', (string) $p->appReachable($url));

        $p->http = ['status' => null, 'error' => 'Connection refused', 'code' => null];
        $problem = $p->appReachable($url);
        $this->assertStringContainsString("Aplikace na {$url} není dosažitelná (Connection refused)", (string) $problem);
        $this->assertStringContainsString('server_name', (string) $problem);
    }

    public function testAppReachabilityPinsTheVerifiedAddressAndNamesAnUnknownHost(): void
    {
        $p = $this->preflight();
        $named = E2ePreflight::probeUrl('http://dev.example', 'abcd-abcd-abcd-abcd');
        $this->assertNull($p->appReachable($named, 1.0, new PinnedBaseUrl('http://dev.example', 'dev.example', 80, '127.0.1.1', false)));
        $this->assertSame([$named, 1.0, 'dev.example:80:127.0.1.1'], $p->requests[0], 'jméno jde přes CURLOPT_RESOLVE na ověřenou adresu (D97)');

        $this->assertNull($p->appReachable($named, 1.0, new PinnedBaseUrl('https://dev.example:8443', 'dev.example', 8443, '::1', false)));
        $this->assertSame('dev.example:8443:[::1]', $p->requests[1][2]);

        $literal = E2ePreflight::probeUrl('http://127.0.0.1', 'abcd-abcd-abcd-abcd');
        $this->assertNull($p->appReachable($literal, 1.0, new PinnedBaseUrl('http://127.0.0.1', '127.0.0.1', 80, '127.0.0.1', true)));
        $this->assertNull($p->requests[2][2], 'IP literál se nepřipichuje');

        // Jméno mimo domains.json: nginx stránku dá, API odmítne UNKNOWN_HOST — věta říká, co s tím.
        $p->http = ['status' => 404, 'error' => null, 'code' => 'UNKNOWN_HOST'];
        $problem = (string) $p->appReachable($named, 1.0, new PinnedBaseUrl('http://dev.example', 'dev.example', 80, '127.0.1.1', false));
        $this->assertStringContainsString("Aplikace host 'dev.example' nezná (HTTP 404 UNKNOWN_HOST)", $problem);
        $this->assertStringContainsString('domains.json', $problem);
        $this->assertStringContainsString('http://127.0.0.1', $problem);
    }

    public function testRealHttpStatusReportsAClosedPortAsUnreachable(): void
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($socket, "socket: {$errstr}");
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket); // port je teď zavřený — spojení skončí odmítnutím hned
        $url = E2ePreflight::probeUrl("http://{$name}", 'x');

        $preflight = new E2ePreflight($this->dir . '/repo', $this->dir . '/bin', null, $this->dir . '/home');
        $problem = $preflight->appReachable($url, 2.0);
        $this->assertNotNull($problem);
        $this->assertStringContainsString("Aplikace na {$url} není dosažitelná", $problem);

        // Připíchnuté jméno: curl jde na 127.0.0.1 bez překladu — zavřený port odmítne stejně.
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        $pinned = new PinnedBaseUrl("http://localhost:{$port}", 'localhost', $port, '127.0.0.1', false);
        $problem = $preflight->appReachable(E2ePreflight::probeUrl("http://localhost:{$port}", 'x'), 2.0, $pinned);
        $this->assertNotNull($problem);
        $this->assertStringContainsString('není dosažitelná', $problem);
    }

    public function testRealHttpStatusReadsTheErrorCodeFromTheApiBody(): void
    {
        // Jednorázový HTTP server v podprocesu odpoví 404 s JSON chybou jako
        // API — preflight z těla přečte kód a jméno hostu vezme z pinu.
        $server = <<<'PHP'
            $s = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
            if ($s === false) { fwrite(STDERR, $errstr); exit(1); }
            echo substr(stream_socket_get_name($s, false), strrpos(stream_socket_get_name($s, false), ':') + 1), "\n";
            fflush(STDOUT);
            $c = @stream_socket_accept($s, 5);
            if ($c !== false) {
                fread($c, 4096);
                $body = '{"success":false,"error":{"code":"UNKNOWN_HOST","message":"Unknown host"}}';
                fwrite($c, "HTTP/1.1 404 Not Found\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
                fclose($c);
            }
            PHP;
        $proc = proc_open([PHP_BINARY, '-r', $server], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc);
        try {
            $port = (int) trim((string) fgets($pipes[1]));
            $this->assertGreaterThan(0, $port, 'podproces vypíše port');
            $preflight = new E2ePreflight($this->dir . '/repo', $this->dir . '/bin', null, $this->dir . '/home');
            $pinned = new PinnedBaseUrl("http://dev.example:{$port}", 'dev.example', $port, '127.0.0.1', false);
            $problem = (string) $preflight->appReachable(E2ePreflight::probeUrl("http://dev.example:{$port}", 'x'), 3.0, $pinned);
            $this->assertStringContainsString("Aplikace host 'dev.example' nezná (HTTP 404 UNKNOWN_HOST)", $problem);
            $this->assertStringContainsString('domains.json', $problem);
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
        }
    }
}

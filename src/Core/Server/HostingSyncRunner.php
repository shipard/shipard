<?php

declare(strict_types=1);

namespace Shipard\Core\Server;

use Shipard\Core\Auth\OidcProviderConfig;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Module\ModuleLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Module\ModuleResolver;
use Shipard\Core\Utils\IdGenerator;
use Shipard\Core\Version;

/**
 * Jeden běh pull agenta hosting-sync (D3, docs/hosting.md §5.2):
 *
 *   1. Reconcile — POST inventura lokálních DS (id, name, modules) + verze.
 *   2. Queue — GET fronta požadavků; pro každý postupně (chyba jednoho
 *      nezastaví další): ds-create --ds-id --language --country →
 *      ds-upgrade → domain-add →
 *      merge auth.providers do main.json → user-create ownera
 *      s předpropojenou identitou → mail-router-setup --json (D4, jen
 *      s aktivním core.mail; token jde do confirm body jako mail_token)
 *      → ai-backend-set-key --base-url (D5, jen s `ai` sekcí payloadu
 *      a aktivním core.ai) → confirm ok/failed.
 *   3. Stats push (D7) — jen když reconcile response nese
 *      stats_wanted=true (nebo ruční --stats): `hosting-stats --json`
 *      per lokální DS, nasbírané počty jedním POST …/stats. Selhání
 *      jednoho DS krok nezastaví; prázdný sběr se neposílá.
 *
 * Idempotence: existující adresář DS = ds-create se přeskočí, ostatní
 * kroky jsou idempotentní samy (ds-upgrade, domain-add no-op na stejný
 * host→DS, merge providera dle id, user-create --if-not-exists).
 *
 * HTTP jen https (http pro localhost dev — stejné pravidlo jako OIDC RP);
 * subprocesy přes argv pole (žádný shell). `performHttpRequest`
 * a `runProcess` jsou protected seams pro testy.
 */
class HostingSyncRunner
{
    public const PROVIDER_ID = 'shipard-id';

    private const HTTP_TIMEOUT_SECONDS = 10;
    private const HTTP_CONNECT_TIMEOUT_SECONDS = 5;
    private const STEP_TIMEOUT_SECONDS = 600;

    /** Kolik posledních bajtů výstupu kroku se drží pro confirm failed. */
    private const OUTPUT_TAIL_BYTES = 4096;

    private \Closure $log;

    public function __construct(
        private readonly HostingConfig $hosting,
        private readonly string $dataSourcesDir,
        private readonly string $shpdServerPath,
        private readonly string $shpdDsPath,
        ?\Closure $log = null,
    ) {
        $this->log = $log ?? static function (string $message): void {};
    }

    public function run(bool $dryRun = false, bool $forceStats = false): bool
    {
        if (!OidcProviderConfig::isAllowedIssuerUrl($this->hosting->url)) {
            $this->logLine('<error>hosting.url must be https (http only for localhost): ' . $this->hosting->url . '</error>');
            return false;
        }

        // 1. Reconcile — když neprojde, nemá smysl pokračovat (spojení
        // nefunguje) a hosting by neměl aktuální last_seen kontext.
        $inventory = $this->buildInventory();
        $this->logLine(sprintf('Reconcile: %d data source(s), version %s', count($inventory), Version::VERSION));
        $reconcile = $this->callHosting('POST', 'reconcile', [
            'version' => Version::VERSION,
            'dataSources' => $inventory,
        ]);
        if ($reconcile === null) {
            return false;
        }

        // 2. Queue — dry-run přes peek=1: hosting frontu nepřeklápí na
        // creating a payload neobsahuje client_secret.
        $queueAction = $dryRun ? 'queue?peek=1' : 'queue';
        $queue = $this->callHosting('GET', $queueAction, null);
        if ($queue === null) {
            return false;
        }
        $requests = is_array($queue['requests'] ?? null) ? $queue['requests'] : [];
        $this->logLine(sprintf('Queue: %d request(s)', count($requests)));

        if ($dryRun) {
            foreach ($requests as $item) {
                $this->logLine(sprintf(
                    '  [dry-run] #%d %s "%s" (%s)',
                    (int) ($item['request_id'] ?? 0),
                    (string) ($item['ds_id'] ?? '?'),
                    (string) ($item['name'] ?? ''),
                    (string) ($item['install_module'] ?? ''),
                ));
            }
            return true;
        }

        $allOk = true;
        foreach ($requests as $item) {
            if (!is_array($item)) {
                continue;
            }
            $requestId = (int) ($item['request_id'] ?? 0);
            $dsId = (string) ($item['ds_id'] ?? '');
            $this->logLine(sprintf('Provisioning #%d %s "%s"…', $requestId, $dsId, (string) ($item['name'] ?? '')));

            $result = $this->provisionRequest($item);
            $error = $result['error'];

            $confirmBody = ['request_id' => $requestId, 'ds_id' => $dsId, 'status' => $error === null ? 'ok' : 'failed'];
            if ($error !== null) {
                $confirmBody['error'] = mb_substr($error, 0, self::OUTPUT_TAIL_BYTES);
                $this->logLine('<error>  failed: ' . $error . '</error>');
                $allOk = false;
            } else {
                // D4: mail token jde hostingu jen při úspěchu kroku f.;
                // bez core.mail confirm token nenese.
                if ($result['mailToken'] !== null) {
                    $confirmBody['mail_token'] = $result['mailToken'];
                }
                $this->logLine('  done.');
            }
            if ($this->callHosting('POST', 'confirm', $confirmBody) === null) {
                $allOk = false;
            }
        }

        // 3. Stats push (D7) — kadenci řídí hosting přes stats_wanted,
        // agent je stateless; --stats krok vynutí ručně.
        if ($forceStats || (bool) ($reconcile['stats_wanted'] ?? false)) {
            if (!$this->pushStats($inventory)) {
                $allOk = false;
            }
        }

        return $allOk;
    }

    // -------------------------------------------------------------------------
    // Stats push (D7)
    // -------------------------------------------------------------------------

    /**
     * `hosting-stats --json` v adresáři každého lokálního DS z inventury;
     * selhání jednoho DS → skip + log (nesmí shodit běh). Nasbírané počty
     * jedním POST …/stats, prázdný sběr se neposílá.
     *
     * @param list<array{ds_id: string, name: string, modules: list<string>}> $inventory
     */
    private function pushStats(array $inventory): bool
    {
        $this->logLine(sprintf('Stats: collecting from %d data source(s)…', count($inventory)));

        $collected = [];
        foreach ($inventory as $item) {
            $dsId = $item['ds_id'];
            $dsDir = $this->dataSourcesDir . '/' . $dsId;
            $result = $this->runProcess([$this->shpdDsPath, 'hosting-stats', '--json'], $dsDir);
            if ($result['exitCode'] !== 0) {
                $this->logLine(sprintf(
                    '<error>  %s: hosting-stats failed (exit %d) — skipped</error>',
                    $dsId,
                    $result['exitCode'],
                ));
                continue;
            }
            $stats = $this->parseStatsOutput($result['output']);
            if ($stats === null) {
                $this->logLine(sprintf('<error>  %s: hosting-stats returned no parsable JSON — skipped</error>', $dsId));
                continue;
            }
            $collected[] = ['ds_id' => $dsId, 'alerts' => $stats['alerts'], 'mail' => $stats['mail']];
        }

        if ($collected === []) {
            $this->logLine('Stats: nothing collected, push skipped.');
            return true;
        }

        $response = $this->callHosting('POST', 'stats', ['stats' => $collected]);
        if ($response === null) {
            return false;
        }
        $this->logLine(sprintf(
            'Stats: pushed %d snapshot(s), hosting accepted %d.',
            count($collected),
            (int) ($response['accepted'] ?? 0),
        ));
        return true;
    }

    /**
     * Poslední flat JSON objekt s klíči alerts + mail z výstupu subprocesu
     * (stdout/stderr se prokládají — stejný přístup jako parseSetupOutput).
     *
     * @return array{alerts: int|null, mail: int|null}|null
     */
    private function parseStatsOutput(string $output): ?array
    {
        if (!preg_match_all('/\{[^{}]*\}/', $output, $matches)) {
            return null;
        }
        foreach (array_reverse($matches[0]) as $candidate) {
            $decoded = json_decode($candidate, true);
            if (!is_array($decoded)
                || !array_key_exists('alerts', $decoded)
                || !array_key_exists('mail', $decoded)
            ) {
                continue;
            }
            return [
                'alerts' => is_numeric($decoded['alerts']) ? (int) $decoded['alerts'] : null,
                'mail' => is_numeric($decoded['mail']) ? (int) $decoded['mail'] : null,
            ];
        }
        return null;
    }

    // -------------------------------------------------------------------------
    // Provisioning jednoho požadavku
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $item
     * @return array{error: ?string, mailToken: ?string}
     *     error = zpráva pro confirm failed (null = úspěch); mailToken =
     *     shpd_ak_ token z kroku f. pro confirm body (null = DS bez core.mail
     *     nebo chyba)
     */
    private function provisionRequest(array $item): array
    {
        $mailToken = null;
        $error = $this->provisionSteps($item, $mailToken);
        return ['error' => $error, 'mailToken' => $mailToken];
    }

    /**
     * @param array<string, mixed> $item
     * @return string|null chybová zpráva pro confirm failed, null = úspěch
     */
    private function provisionSteps(array $item, ?string &$mailToken): ?string
    {
        $dsId = (string) ($item['ds_id'] ?? '');
        $name = (string) ($item['name'] ?? '');
        $module = (string) ($item['install_module'] ?? '');
        $language = (string) ($item['language'] ?? '');
        $country = (string) ($item['country'] ?? '');
        $host = (string) ($item['host'] ?? '');
        $owner = is_array($item['owner'] ?? null) ? $item['owner'] : [];
        $oidc = is_array($item['oidc'] ?? null) ? $item['oidc'] : [];

        // Hodnoty jdou do cest a argv — validovat dřív, než se čehokoli dotknou.
        if (!preg_match(IdGenerator::ID_PATTERN, $dsId)) {
            return "Invalid ds_id in queue payload: '{$dsId}'";
        }
        if ($name === '' || $module === '') {
            return 'Queue payload is missing name or install_module';
        }
        // Vrstva A (ds-setup D1/D7) — hosting starší verze bez těchto polí
        // musí skončit hlasitě, ne založením DS s odhadnutou zemí.
        if ($language === '' || $country === '') {
            return 'Queue payload is missing language or country';
        }
        if (!preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/i', $host)) {
            return "Invalid host in queue payload: '{$host}'";
        }
        $ownerEmail = (string) ($owner['email'] ?? '');
        $ownerName = (string) ($owner['name'] ?? '');
        $ownerSub = (string) ($owner['sub'] ?? '');
        if ($ownerEmail === '' || $ownerName === '' || $ownerSub === '') {
            return 'Queue payload is missing owner email/name/sub';
        }
        $issuer = rtrim((string) ($oidc['issuer'] ?? ''), '/');
        $clientSecret = (string) ($oidc['client_secret'] ?? '');
        if ($issuer === '' || $clientSecret === '') {
            return 'Queue payload is missing oidc issuer/client_secret';
        }

        $dsDir = $this->dataSourcesDir . '/' . $dsId;

        // a. Založení DS — existující adresář = krok přeskočit (idempotence,
        //    dokončování po pádu agenta).
        if (!is_dir($dsDir)) {
            $error = $this->runStep(
                'ds-create',
                [
                    $this->shpdServerPath, 'ds-create',
                    '--ds-id', $dsId,
                    '--name', $name,
                    '--module', $module,
                    '--language', $language,
                    '--country', $country,
                ],
                null,
            );
            if ($error !== null) {
                return $error;
            }
        } else {
            $this->logLine('  ds-create skipped — directory exists.');
        }

        // b. Schéma + compiled config.
        $error = $this->runStep('ds-upgrade', [$this->shpdDsPath, 'ds-upgrade'], $dsDir);
        if ($error !== null) {
            return $error;
        }

        // c. Doména (idempotentní: stejný host → stejný DS = no-op).
        $error = $this->runStep(
            'domain-add',
            [$this->shpdServerPath, 'domain-add', '--host', $host, '--ds', $dsId],
            null,
        );
        if ($error !== null) {
            return $error;
        }

        // d. auth.providers — OP hostingu (U2: autoLinkEmail false, identita
        //    se předpropojuje krokem e).
        $error = $this->patchAuthProvider($dsDir, [
            'id' => self::PROVIDER_ID,
            'label' => (string) ($oidc['label'] ?? 'Shipard'),
            'issuer' => $issuer,
            'clientId' => (string) ($oidc['client_id'] ?? $dsId),
            'clientSecret' => $clientSecret,
            'autoLinkEmail' => false,
        ]);
        if ($error !== null) {
            return $error;
        }

        // e. Admin účet vlastníka bez hesla + předpropojená identita (U1+U2).
        $error = $this->runStep('user-create', [
            $this->shpdDsPath, 'user-create',
            '--login', $ownerEmail,
            '--email', $ownerEmail,
            '--name', $ownerName,
            '--admin',
            '--if-not-exists',
            '--identity-provider', self::PROVIDER_ID,
            '--identity-issuer', $issuer,
            '--identity-subject', $ownerSub,
        ], $dsDir);
        if ($error !== null) {
            return $error;
        }

        // f. Mail token pro mail-router (D4) — jen s aktivním core.mail.
        $error = $this->mintMailToken($dsDir, $mailToken);
        if ($error !== null) {
            return $error;
        }

        // g. AI gateway backend (D5) — jen s `ai` sekcí payloadu a aktivním
        //    core.ai. (Krok h., token pro externí AI analyzer, zanikl
        //    s registrem analyzerů — #85 D22.)
        return $this->setupAiBackend($dsDir, $item);
    }

    /**
     * Krok f. — `mail-router-setup --json` v adresáři DS; token jde do
     * confirm body. Retry po pádu za tímto krokem: existující klíč shodí
     * běh bez --force, druhý pokus rotuje s --force (neškodné — token na
     * hostingu stejně přepíše tento confirm, DS ještě poštu nepřijímá).
     */
    private function mintMailToken(string $dsDir, ?string &$mailToken): ?string
    {
        if (!$this->isModuleActiveForDs($dsDir, 'core.mail')) {
            $this->logLine('  mail-router-setup skipped — core.mail not active.');
            return null;
        }

        $this->logLine('  mail-router-setup…');
        $result = $this->runProcess([$this->shpdDsPath, 'mail-router-setup', '--json'], $dsDir);
        if ($result['exitCode'] !== 0) {
            $this->logLine('  mail-router-setup retry with --force…');
            $result = $this->runProcess([$this->shpdDsPath, 'mail-router-setup', '--json', '--force'], $dsDir);
        }
        if ($result['exitCode'] !== 0) {
            $tail = trim($result['output']);
            return sprintf(
                'mail-router-setup failed (exit %d)%s',
                $result['exitCode'],
                $tail !== '' ? ': ' . $tail : '',
            );
        }

        $token = $this->parseSetupOutput($result['output']);
        if ($token === null) {
            return 'mail-router-setup returned no parsable api_key JSON';
        }
        $mailToken = $token;
        return null;
    }

    /**
     * Krok g. — `ai-backend-set-key --base-url` v adresáři DS zapíše
     * gateway backend (D5). Idempotentní (set-key je upsert nad default
     * backendem, který zakládá MailAiProvisioner při ds-upgrade).
     * api_key v argv je lokální root kontext (proc_open s argv polem, žádný
     * shell) — do logu ani confirm.error ale nesmí: failure tail se maskuje.
     *
     * @param array<string, mixed> $item
     */
    private function setupAiBackend(string $dsDir, array $item): ?string
    {
        $ai = is_array($item['ai'] ?? null) ? $item['ai'] : null;
        if ($ai === null) {
            return null;
        }
        $apiKey = (string) ($ai['api_key'] ?? '');
        $baseUrl = (string) ($ai['base_url'] ?? '');
        if ($apiKey === '' || $baseUrl === '') {
            return 'Queue payload ai section is missing api_key/base_url';
        }

        if (!$this->isModuleActiveForDs($dsDir, 'core.ai')) {
            $this->logLine('  ai-backend-set-key skipped — core.ai not active.');
            return null;
        }

        $this->logLine('  ai-backend-set-key…');
        $result = $this->runProcess([
            $this->shpdDsPath, 'ai-backend-set-key',
            '--backend', 'default',
            '--api-key', $apiKey,
            '--base-url', $baseUrl,
        ], $dsDir);
        if ($result['exitCode'] !== 0) {
            $tail = trim(str_replace($apiKey, '***', $result['output']));
            return sprintf(
                'ai-backend-set-key failed (exit %d)%s',
                $result['exitCode'],
                $tail !== '' ? ': ' . $tail : '',
            );
        }
        return null;
    }

    /**
     * Výstup setup subprocesu (mail-router-setup)
     * prokládá stdout a stderr — vzít poslední JSON objekt s validním
     * api_key, dekorace okolo ignorovat.
     */
    private function parseSetupOutput(string $output): ?string
    {
        if (!preg_match_all('/\{[^{}]*\}/', $output, $matches)) {
            return null;
        }
        foreach (array_reverse($matches[0]) as $candidate) {
            $decoded = json_decode($candidate, true);
            if (!is_array($decoded)) {
                continue;
            }
            $apiKey = (string) ($decoded['api_key'] ?? '');
            if (preg_match('/^shpd_ak_[0-9a-f]{32}$/', $apiKey)) {
                return $apiKey;
            }
        }
        return null;
    }

    /**
     * Aktivní modul DS vč. tranzitivních závislostí — main.json nese jen
     * přímé moduly (install.*), rezoluce přes ModuleLoader/ModuleResolver
     * (vzor DsUpgradeCommand::isModuleActive). Protected seam pro testy.
     * Fallback bez server.json = jen repo modules (vzor bin/shpd-ds).
     */
    protected function isModuleActiveForDs(string $dsDir, string $moduleId): bool
    {
        $config = json_decode((string) @file_get_contents($dsDir . '/config/main.json'), true);
        $direct = is_array($config['modules'] ?? null)
            ? array_values(array_map(strval(...), $config['modules']))
            : [];
        if ($direct === []) {
            return false;
        }
        if (in_array($moduleId, $direct, true)) {
            return true;
        }

        try {
            $sc = new ServerConfig();
            $sc->load();
            $resolver = ModulePathResolver::fromServerConfig($sc, dirname(__DIR__, 3) . '/modules');
        } catch (\Throwable) {
            $resolver = new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        }

        try {
            $errors = [];
            $resolved = ModuleResolver::resolve(ModuleLoader::loadAllModules($resolver), $direct, $errors);
        } catch (\Throwable $e) {
            $this->logLine('<error>  module resolution failed: ' . $e->getMessage() . '</error>');
            return false;
        }
        foreach ($resolved as $module) {
            if ($module->id === $moduleId) {
                return true;
            }
        }
        return false;
    }

    /**
     * Merge providera do main.json — čistou transformaci dělá
     * MainConfigPatcher, tady jen read-modify-write (atomicky tmp + rename,
     * chmod 0600).
     *
     * @param array<string, mixed> $provider
     */
    private function patchAuthProvider(string $dsDir, array $provider): ?string
    {
        $file = $dsDir . '/config/main.json';
        $content = @file_get_contents($file);
        if ($content === false) {
            return "auth.providers: cannot read {$file}";
        }
        $config = json_decode($content, true);
        if (!is_array($config)) {
            return "auth.providers: invalid JSON in {$file}";
        }

        $config = MainConfigPatcher::mergeAuthProvider($config, $provider);

        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $tmp = $file . '.tmp';
        if (@file_put_contents($tmp, $json) === false) {
            return "auth.providers: cannot write {$tmp}";
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            return "auth.providers: cannot replace {$file}";
        }
        $this->logLine('  auth.providers merged (' . self::PROVIDER_ID . ').');
        return null;
    }

    // -------------------------------------------------------------------------
    // Inventura
    // -------------------------------------------------------------------------

    /**
     * @return list<array{ds_id: string, name: string, modules: list<string>}>
     */
    private function buildInventory(): array
    {
        $dirs = glob($this->dataSourcesDir . '/*', GLOB_ONLYDIR) ?: [];
        sort($dirs);

        $inventory = [];
        foreach ($dirs as $dir) {
            $file = $dir . '/config/main.json';
            if (!is_file($file)) {
                continue;
            }
            $config = json_decode((string) @file_get_contents($file), true);
            if (!is_array($config)) {
                continue;
            }
            $modules = is_array($config['modules'] ?? null) ? $config['modules'] : [];
            $inventory[] = [
                'ds_id' => (string) ($config['id'] ?? basename($dir)),
                'name' => (string) ($config['name'] ?? ''),
                'modules' => array_values(array_map(strval(...), $modules)),
            ];
        }
        return $inventory;
    }

    // -------------------------------------------------------------------------
    // HTTP + subprocesy
    // -------------------------------------------------------------------------

    /**
     * Zavolá hosting endpoint a vrátí `data` ze success envelope; chyba
     * (síť, ne-200, nevalidní JSON) → log + null.
     *
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>|null
     */
    private function callHosting(string $method, string $action, ?array $body): ?array
    {
        $url = $this->hosting->url . '/api/v1/_hosting/server/' . $action;
        $result = $this->performHttpRequest($method, $url, $body);

        if ($result['error'] !== null || $result['statusCode'] !== 200) {
            $this->logLine(sprintf(
                '<error>Hosting call %s failed: HTTP %d%s</error>',
                $action,
                $result['statusCode'],
                $result['error'] !== null ? ' (' . $result['error'] . ')' : '',
            ));
            return null;
        }

        $decoded = json_decode($result['body'], true);
        if (!is_array($decoded) || ($decoded['success'] ?? false) !== true) {
            $this->logLine("<error>Hosting call {$action} returned unexpected payload</error>");
            return null;
        }
        $data = $decoded['data'] ?? [];
        return is_array($data) ? $data : [];
    }

    /**
     * Jeden HTTP request — protected seam pro testy. Bearer = klíč serveru.
     *
     * @param array<string, mixed>|null $body JSON body (POST), null = bez těla
     * @return array{statusCode: int, body: string, error: ?string}
     */
    protected function performHttpRequest(string $method, string $url, ?array $body): array
    {
        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $this->hosting->apiKey,
        ];
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::HTTP_TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::HTTP_CONNECT_TIMEOUT_SECONDS,
        ];
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($body ?? [], JSON_UNESCAPED_UNICODE);
            $headers[] = 'Content-Type: application/json';
        }
        $options[CURLOPT_HTTPHEADER] = $headers;

        $ch = curl_init();
        curl_setopt_array($ch, $options);
        $responseBody = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = $errno !== 0 ? curl_error($ch) : null;
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return [
            'statusCode' => $statusCode,
            'body' => $responseBody === false ? '' : (string) $responseBody,
            'error' => $error,
        ];
    }

    /** Spustí krok a při neúspěchu vrátí zprávu pro confirm failed. */
    private function runStep(string $label, array $argv, ?string $cwd): ?string
    {
        $this->logLine('  ' . $label . '…');
        $result = $this->runProcess($argv, $cwd);
        if ($result['exitCode'] === 0) {
            return null;
        }
        $tail = trim($result['output']);
        return sprintf('%s failed (exit %d)%s', $label, $result['exitCode'], $tail !== '' ? ': ' . $tail : '');
    }

    /**
     * Subprocess přes argv pole (žádný shell) — protected seam pro testy.
     * Timeout: SIGTERM, 5 s grace, pak SIGKILL (vzor CronCommand::runJob).
     *
     * @param list<string> $argv
     * @return array{exitCode: int, output: string}
     */
    protected function runProcess(array $argv, ?string $cwd): array
    {
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open($argv, $descriptors, $pipes, $cwd);
        if (!is_resource($process)) {
            return ['exitCode' => -1, 'output' => 'proc_open failed'];
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $deadline = microtime(true) + self::STEP_TIMEOUT_SECONDS;
        $output = '';
        $exitCode = -1;

        $drain = function () use (&$output, $pipes): void {
            foreach ([1, 2] as $i) {
                $chunk = @stream_get_contents($pipes[$i]);
                if (is_string($chunk) && $chunk !== '') {
                    $output = substr($output . $chunk, -self::OUTPUT_TAIL_BYTES);
                }
            }
        };

        while (true) {
            $drain();
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = $status['exitcode'];
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($process, 15); // SIGTERM
                $grace = microtime(true) + 5.0;
                while (microtime(true) < $grace) {
                    usleep(100_000);
                    $status = proc_get_status($process);
                    if (!$status['running']) {
                        break;
                    }
                }
                if ($status['running']) {
                    proc_terminate($process, 9); // SIGKILL
                }
                $output = substr($output . "\n[timeout]", -self::OUTPUT_TAIL_BYTES);
                break;
            }
            usleep(100_000);
        }

        $drain();
        foreach ($pipes as $pipe) {
            @fclose($pipe);
        }
        proc_close($process);

        return ['exitCode' => $exitCode, 'output' => $output];
    }

    private function logLine(string $message): void
    {
        ($this->log)($message);
    }
}

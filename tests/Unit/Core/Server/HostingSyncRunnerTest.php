<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Server;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Server\HostingConfig;
use Shipard\Core\Server\HostingSyncRunner;
use Shipard\Core\Version;

class StubHostingSyncRunner extends HostingSyncRunner
{
    /** @var list<array{method: string, url: string, body: ?array}> */
    public array $httpCalls = [];
    /** @var list<array{argv: list<string>, cwd: ?string}> */
    public array $processCalls = [];
    /** @var array<string, array{statusCode: int, body: string, error: ?string}> URL substring → odpověď */
    public array $httpResponses = [];
    public ?\Closure $onProcess = null;
    /** null = reálná rezoluce modulů (repo modules); closure = deterministický stub. */
    public ?\Closure $onModuleCheck = null;

    protected function isModuleActiveForDs(string $dsDir, string $moduleId): bool
    {
        if ($this->onModuleCheck !== null) {
            return ($this->onModuleCheck)($dsDir, $moduleId);
        }
        return parent::isModuleActiveForDs($dsDir, $moduleId);
    }

    protected function performHttpRequest(string $method, string $url, ?array $body): array
    {
        $this->httpCalls[] = ['method' => $method, 'url' => $url, 'body' => $body];
        foreach ($this->httpResponses as $needle => $response) {
            if (str_contains($url, $needle)) {
                return $response;
            }
        }
        return [
            'statusCode' => 200,
            'body' => (string) json_encode(['success' => true, 'data' => ['ok' => true]]),
            'error' => null,
        ];
    }

    protected function runProcess(array $argv, ?string $cwd): array
    {
        $this->processCalls[] = ['argv' => $argv, 'cwd' => $cwd];
        return $this->onProcess !== null
            ? ($this->onProcess)($argv, $cwd)
            : ['exitCode' => 0, 'output' => ''];
    }
}

class HostingSyncRunnerTest extends TestCase
{
    private const EXISTING_DS = 'aaaa-aaaa-aaaa-aaaa';
    private const NEW_DS = 'bbbb-bbbb-bbbb-bbbb';
    private const ISSUER = 'http://127.0.0.1/gggg-gggg-gggg-gggg/api/v1/_hosting/oidc';
    private const MAIL_TOKEN = 'shpd_ak_00112233445566778899aabbccddeeff';
    private const MAIL_SETUP_JSON = '{"api_key":"' . self::MAIL_TOKEN . '","user_id":3}';

    private string $dataSourcesDir;

    protected function setUp(): void
    {
        $this->dataSourcesDir = sys_get_temp_dir() . '/shpd_hostingsync_test_' . uniqid();
        mkdir($this->dataSourcesDir, 0755, true);
        $this->createDs(self::EXISTING_DS, 'Existing DS', ['install.base', 'core.mail']);
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->dataSourcesDir);
    }

    private function createDs(string $dsId, string $name, array $modules): void
    {
        mkdir($this->dataSourcesDir . '/' . $dsId . '/config', 0755, true);
        file_put_contents(
            $this->dataSourcesDir . '/' . $dsId . '/config/main.json',
            (string) json_encode([
                'id' => $dsId,
                'name' => $name,
                'modules' => $modules,
                'database_name' => 'db',
                'database_user' => 'user',
                'database_password' => 'pw',
            ], JSON_PRETTY_PRINT),
        );
    }

    private function makeRunner(string $url = 'http://127.0.0.1/gggg-gggg-gggg-gggg'): StubHostingSyncRunner
    {
        return new StubHostingSyncRunner(
            new HostingConfig($url, 1, 'shpd_hk_' . str_repeat('a', 43)),
            $this->dataSourcesDir,
            '/fake/bin/shpd-server',
            '/fake/bin/shpd-ds',
        );
    }

    /** @return array<string, mixed> */
    private function queueItem(array $overrides = []): array
    {
        return array_merge([
            'request_id' => 12,
            'ds_id' => self::NEW_DS,
            'name' => 'Nová firma',
            'install_module' => 'install.base',
            'web_id' => 'nova',
            'language' => 'cs',
            'country' => 'cz',
            'host' => 'nova.shpd.dev',
            'owner' => ['email' => 'owner@example.com', 'name' => 'Owner User', 'sub' => '7'],
            'oidc' => [
                'issuer' => self::ISSUER,
                'client_id' => self::NEW_DS,
                'client_secret' => 'plain-secret',
                'label' => 'Shipard',
            ],
        ], $overrides);
    }

    private function queueResponse(array $items): array
    {
        return [
            'statusCode' => 200,
            'body' => (string) json_encode(['success' => true, 'data' => ['requests' => $items]]),
            'error' => null,
        ];
    }

    public function testHappyPathProvisionsAndConfirmsOk(): void
    {
        $runner = $this->makeRunner();
        $runner->httpResponses['queue'] = $this->queueResponse([$this->queueItem()]);
        $runner->onModuleCheck = static fn(): bool => true; // core.mail + core.ai aktivní
        // ds-create simulace: založí adresář s main.json (jako reálný příkaz).
        $runner->onProcess = function (array $argv): array {
            if ($argv[1] === 'ds-create') {
                $this->createDs(self::NEW_DS, 'Nová firma', ['install.base']);
            }
            if ($argv[1] === 'mail-router-setup') {
                return ['exitCode' => 0, 'output' => self::MAIL_SETUP_JSON];
            }
            return ['exitCode' => 0, 'output' => ''];
        };

        $this->assertTrue($runner->run());

        // Reconcile: inventura existujících DS + verze.
        $reconcile = $runner->httpCalls[0];
        $this->assertSame('POST', $reconcile['method']);
        $this->assertStringContainsString('/api/v1/_hosting/server/reconcile', $reconcile['url']);
        $this->assertSame(Version::VERSION, $reconcile['body']['version']);
        $this->assertSame(self::EXISTING_DS, $reconcile['body']['dataSources'][0]['ds_id']);
        $this->assertSame(['install.base', 'core.mail'], $reconcile['body']['dataSources'][0]['modules']);

        // Kroky v pořadí: ds-create → … → mail-router-setup (bez `ai` sekce
        // payloadu krok g. neběží; krok h. externího analyzeru zanikl, #85 D22).
        $labels = array_map(static fn(array $c): string => $c['argv'][1], $runner->processCalls);
        $this->assertSame(
            ['ds-create', 'ds-upgrade', 'domain-add', 'user-create', 'mail-router-setup'],
            $labels,
        );

        [$dsCreate, $dsUpgrade, $domainAdd, $userCreate, $mailSetup] = $runner->processCalls;
        $this->assertSame('/fake/bin/shpd-server', $dsCreate['argv'][0]);
        $this->assertContains('--ds-id', $dsCreate['argv']);
        $this->assertContains(self::NEW_DS, $dsCreate['argv']);
        $this->assertContains('--language', $dsCreate['argv']);
        $this->assertContains('cs', $dsCreate['argv']);
        $this->assertContains('--country', $dsCreate['argv']);
        $this->assertContains('cz', $dsCreate['argv']);
        $this->assertSame($this->dataSourcesDir . '/' . self::NEW_DS, $dsUpgrade['cwd']);
        $this->assertContains('nova.shpd.dev', $domainAdd['argv']);
        $this->assertContains('--if-not-exists', $userCreate['argv']);
        $this->assertContains('--identity-subject', $userCreate['argv']);
        $this->assertContains('7', $userCreate['argv']);
        $this->assertContains(HostingSyncRunner::PROVIDER_ID, $userCreate['argv']);
        $this->assertContains('--json', $mailSetup['argv']);
        $this->assertNotContains('--force', $mailSetup['argv']);
        $this->assertSame($this->dataSourcesDir . '/' . self::NEW_DS, $mailSetup['cwd']);

        // auth.providers v main.json nového DS, mode 0600, ostatní klíče netknuté.
        $mainFile = $this->dataSourcesDir . '/' . self::NEW_DS . '/config/main.json';
        $config = json_decode((string) file_get_contents($mainFile), true);
        $provider = $config['auth']['providers'][0];
        $this->assertSame('shipard-id', $provider['id']);
        $this->assertSame(self::ISSUER, $provider['issuer']);
        $this->assertSame(self::NEW_DS, $provider['clientId']);
        $this->assertSame('plain-secret', $provider['clientSecret']);
        $this->assertFalse($provider['autoLinkEmail']);
        $this->assertSame('db', $config['database_name']);
        $this->assertSame(0600, fileperms($mainFile) & 0777);

        // Confirm ok + mail_token z kroku f. (D4); token externího analyzeru
        // už confirm nenese (#85 D22).
        $confirm = $runner->httpCalls[2];
        $this->assertStringContainsString('/confirm', $confirm['url']);
        $this->assertSame(
            [
                'request_id' => 12,
                'ds_id' => self::NEW_DS,
                'status' => 'ok',
                'mail_token' => self::MAIL_TOKEN,
            ],
            $confirm['body'],
        );
    }

    public function testExistingDsDirectorySkipsDsCreate(): void
    {
        $this->createDs(self::NEW_DS, 'Nová firma', ['install.base']);
        $runner = $this->makeRunner();
        $runner->httpResponses['queue'] = $this->queueResponse([$this->queueItem()]);
        $runner->onModuleCheck = static fn(): bool => false; // bez core.mail

        $this->assertTrue($runner->run());

        $labels = array_map(static fn(array $c): string => $c['argv'][1], $runner->processCalls);
        $this->assertSame(['ds-upgrade', 'domain-add', 'user-create'], $labels);
        $this->assertSame('ok', $runner->httpCalls[2]['body']['status']);
        // Bez core.mail confirm token nenese.
        $this->assertArrayNotHasKey('mail_token', $runner->httpCalls[2]['body']);
    }

    public function testStepFailureConfirmsFailedAndContinuesWithNextRequest(): void
    {
        $this->createDs(self::NEW_DS, 'Nová firma', ['install.base']);
        $this->createDs('cccc-cccc-cccc-cccc', 'Druhá firma', ['install.base']);
        $runner = $this->makeRunner();
        $runner->httpResponses['queue'] = $this->queueResponse([
            $this->queueItem(),
            $this->queueItem([
                'request_id' => 13,
                'ds_id' => 'cccc-cccc-cccc-cccc',
                'host' => 'druha.shpd.dev',
            ]),
        ]);
        $runner->onModuleCheck = static fn(): bool => false;
        $runner->onProcess = static function (array $argv, ?string $cwd): array {
            if ($argv[1] === 'domain-add' && in_array('nova.shpd.dev', $argv, true)) {
                return ['exitCode' => 1, 'output' => "Host 'nova.shpd.dev' is already mapped to data source 'zzzz-zzzz-zzzz-zzzz'"];
            }
            return ['exitCode' => 0, 'output' => ''];
        };

        $this->assertFalse($runner->run());

        // První požadavek: failed s výstižnou zprávou, user-create už neběžel.
        $confirms = array_values(array_filter(
            $runner->httpCalls,
            static fn(array $c): bool => str_contains($c['url'], '/confirm'),
        ));
        $this->assertCount(2, $confirms);
        $this->assertSame('failed', $confirms[0]['body']['status']);
        $this->assertStringContainsString('domain-add failed', $confirms[0]['body']['error']);
        $this->assertStringContainsString('already mapped', $confirms[0]['body']['error']);

        // Druhý požadavek doběhl ok.
        $this->assertSame('ok', $confirms[1]['body']['status']);
        $this->assertSame(13, $confirms[1]['body']['request_id']);
    }

    public function testDryRunUsesPeekAndDoesNothing(): void
    {
        $runner = $this->makeRunner();
        $runner->httpResponses['queue'] = $this->queueResponse([
            ['request_id' => 12, 'ds_id' => self::NEW_DS, 'name' => 'Nová firma', 'install_module' => 'install.base'],
        ]);

        $this->assertTrue($runner->run(dryRun: true));

        $this->assertStringContainsString('queue?peek=1', $runner->httpCalls[1]['url']);
        $this->assertSame([], $runner->processCalls);
        $this->assertCount(2, $runner->httpCalls); // jen reconcile + queue, žádný confirm
    }

    public function testReconcileFailureAbortsRun(): void
    {
        $runner = $this->makeRunner();
        $runner->httpResponses['reconcile'] = ['statusCode' => 401, 'body' => '', 'error' => null];

        $this->assertFalse($runner->run());
        $this->assertCount(1, $runner->httpCalls);
        $this->assertSame([], $runner->processCalls);
    }

    public function testInvalidDsIdInPayloadConfirmsFailedWithoutTouchingDisk(): void
    {
        $runner = $this->makeRunner();
        $runner->httpResponses['queue'] = $this->queueResponse([
            $this->queueItem(['ds_id' => '../../etc/passwd']),
        ]);

        $this->assertFalse($runner->run());

        $this->assertSame([], $runner->processCalls);
        $confirm = $runner->httpCalls[2];
        $this->assertSame('failed', $confirm['body']['status']);
        $this->assertStringContainsString('Invalid ds_id', $confirm['body']['error']);
    }

    public function testMissingCountryInPayloadConfirmsFailedWithoutTouchingDisk(): void
    {
        // Hosting starší verze než DS server (bez vrstvy A v payloadu) musí
        // skončit hlasitě — žádný ds-create s odhadnutou zemí.
        $runner = $this->makeRunner();
        $runner->httpResponses['queue'] = $this->queueResponse([
            $this->queueItem(['country' => '']),
        ]);

        $this->assertFalse($runner->run());

        $this->assertSame([], $runner->processCalls);
        $confirm = $runner->httpCalls[2];
        $this->assertSame('failed', $confirm['body']['status']);
        $this->assertStringContainsString('missing language or country', $confirm['body']['error']);
    }

    public function testNonLocalhostHttpUrlIsRejected(): void
    {
        $runner = $this->makeRunner('http://portal.example.com');

        $this->assertFalse($runner->run());
        $this->assertSame([], $runner->httpCalls);
    }

    // -------------------------------------------------------------------------
    // Krok f. — mail-router-setup (D4)
    // -------------------------------------------------------------------------

    public function testMailSetupRetriesWithForceOnExistingKey(): void
    {
        $this->createDs(self::NEW_DS, 'Nová firma', ['install.base']);
        $runner = $this->makeRunner();
        $runner->httpResponses['queue'] = $this->queueResponse([$this->queueItem()]);
        $runner->onModuleCheck = static fn(): bool => true;
        // Retry po pádu za krokem f.: bez --force selže na existující klíč,
        // s --force projde. Výstup má okolo JSON i dekorace (stderr mix).
        $runner->onProcess = static function (array $argv): array {
            if ($argv[1] !== 'mail-router-setup') {
                return ['exitCode' => 0, 'output' => ''];
            }
            if (!in_array('--force', $argv, true)) {
                return ['exitCode' => 1, 'output' => 'Error: An active mail-router API key already exists. Use --force to rotate it.'];
            }
            return ['exitCode' => 0, 'output' => "some stderr noise\n" . self::MAIL_SETUP_JSON . "\n"];
        };

        $this->assertTrue($runner->run());

        $mailCalls = array_values(array_filter(
            $runner->processCalls,
            static fn(array $c): bool => $c['argv'][1] === 'mail-router-setup',
        ));
        $this->assertCount(2, $mailCalls);
        $this->assertNotContains('--force', $mailCalls[0]['argv']);
        $this->assertContains('--force', $mailCalls[1]['argv']);

        $confirm = $runner->httpCalls[2];
        $this->assertSame('ok', $confirm['body']['status']);
        $this->assertSame(self::MAIL_TOKEN, $confirm['body']['mail_token']);
    }

    public function testMailSetupFailureConfirmsFailed(): void
    {
        $this->createDs(self::NEW_DS, 'Nová firma', ['install.base']);
        $runner = $this->makeRunner();
        $runner->httpResponses['queue'] = $this->queueResponse([$this->queueItem()]);
        $runner->onModuleCheck = static fn(): bool => true;
        $runner->onProcess = static fn(array $argv): array => $argv[1] === 'mail-router-setup'
            ? ['exitCode' => 1, 'output' => 'boom']
            : ['exitCode' => 0, 'output' => ''];

        $this->assertFalse($runner->run());

        $confirm = $runner->httpCalls[2];
        $this->assertSame('failed', $confirm['body']['status']);
        $this->assertStringContainsString('mail-router-setup failed', $confirm['body']['error']);
        $this->assertArrayNotHasKey('mail_token', $confirm['body']);
    }

    public function testMailSetupUnparsableOutputConfirmsFailed(): void
    {
        $this->createDs(self::NEW_DS, 'Nová firma', ['install.base']);
        $runner = $this->makeRunner();
        $runner->httpResponses['queue'] = $this->queueResponse([$this->queueItem()]);
        $runner->onModuleCheck = static fn(): bool => true;
        $runner->onProcess = static fn(array $argv): array => $argv[1] === 'mail-router-setup'
            ? ['exitCode' => 0, 'output' => 'not json at all']
            : ['exitCode' => 0, 'output' => ''];

        $this->assertFalse($runner->run());
        $this->assertStringContainsString('no parsable api_key', $runner->httpCalls[2]['body']['error']);
    }

    // -------------------------------------------------------------------------
    // Krok g. — ai-backend-set-key (D5)
    // -------------------------------------------------------------------------

    private const GW_TOKEN = 'shpd_gw_' . 'abcdefghijkl' . 'mnopqrstuvwxyz0123456789ABCDEFG';
    private const GW_BASE_URL = 'http://127.0.0.1/gggg-gggg-gggg-gggg/api/v1/_hosting/ai-gw';

    /** @return array<string, mixed> */
    private function queueItemWithAi(): array
    {
        return $this->queueItem([
            'ai' => ['base_url' => self::GW_BASE_URL, 'api_key' => self::GW_TOKEN],
        ]);
    }

    public function testAiSetupRunsSetKeyWithBaseUrl(): void
    {
        $this->createDs(self::NEW_DS, 'Nová firma', ['install.base']);
        $runner = $this->makeRunner();
        $runner->httpResponses['queue'] = $this->queueResponse([$this->queueItemWithAi()]);
        $runner->onModuleCheck = static fn(): bool => true;
        $runner->onProcess = static fn(array $argv): array => match ($argv[1]) {
            'mail-router-setup' => ['exitCode' => 0, 'output' => self::MAIL_SETUP_JSON],
            default => ['exitCode' => 0, 'output' => ''],
        };

        $this->assertTrue($runner->run());

        $aiCalls = array_values(array_filter(
            $runner->processCalls,
            static fn(array $c): bool => $c['argv'][1] === 'ai-backend-set-key',
        ));
        $this->assertCount(1, $aiCalls);
        $argv = $aiCalls[0]['argv'];
        $this->assertSame('/fake/bin/shpd-ds', $argv[0]);
        $this->assertSame(
            ['--backend', 'default', '--api-key', self::GW_TOKEN, '--base-url', self::GW_BASE_URL],
            array_slice($argv, 2),
        );
        $this->assertSame($this->dataSourcesDir . '/' . self::NEW_DS, $aiCalls[0]['cwd']);

        $this->assertSame('ok', $runner->httpCalls[2]['body']['status']);
    }

    public function testAiSetupSkippedWithoutCoreAi(): void
    {
        $this->createDs(self::NEW_DS, 'Nová firma', ['install.base']);
        $runner = $this->makeRunner();
        $runner->httpResponses['queue'] = $this->queueResponse([$this->queueItemWithAi()]);
        $runner->onModuleCheck = static fn(string $dsDir, string $moduleId): bool => $moduleId !== 'core.ai';
        $runner->onProcess = static fn(array $argv): array => $argv[1] === 'mail-router-setup'
            ? ['exitCode' => 0, 'output' => self::MAIL_SETUP_JSON]
            : ['exitCode' => 0, 'output' => ''];

        $this->assertTrue($runner->run());

        $labels = array_map(static fn(array $c): string => $c['argv'][1], $runner->processCalls);
        $this->assertNotContains('ai-backend-set-key', $labels);
        $this->assertSame('ok', $runner->httpCalls[2]['body']['status']);
    }

    public function testAiSetupSkippedWithoutAiSection(): void
    {
        $this->createDs(self::NEW_DS, 'Nová firma', ['install.base']);
        $runner = $this->makeRunner();
        $runner->httpResponses['queue'] = $this->queueResponse([$this->queueItem()]);
        $runner->onModuleCheck = static fn(): bool => false;

        $this->assertTrue($runner->run());

        $labels = array_map(static fn(array $c): string => $c['argv'][1], $runner->processCalls);
        $this->assertNotContains('ai-backend-set-key', $labels);
    }

    public function testAiSetupFailureMasksKeyInConfirmError(): void
    {
        $this->createDs(self::NEW_DS, 'Nová firma', ['install.base']);
        $logLines = [];
        $runner = new StubHostingSyncRunner(
            new HostingConfig('http://127.0.0.1/gggg-gggg-gggg-gggg', 1, 'shpd_hk_' . str_repeat('a', 43)),
            $this->dataSourcesDir,
            '/fake/bin/shpd-server',
            '/fake/bin/shpd-ds',
            static function (string $line) use (&$logLines): void {
                $logLines[] = $line;
            },
        );
        $runner->httpResponses['queue'] = $this->queueResponse([$this->queueItemWithAi()]);
        $runner->onModuleCheck = static fn(): bool => true;
        // Selhání s api_key ve výstupu (např. echo argv v error hlášce).
        $runner->onProcess = static fn(array $argv): array => $argv[1] === 'ai-backend-set-key'
            ? ['exitCode' => 1, 'output' => 'Error: cannot store key ' . self::GW_TOKEN . ' (db gone)']
            : ($argv[1] === 'mail-router-setup'
                ? ['exitCode' => 0, 'output' => self::MAIL_SETUP_JSON]
                : ['exitCode' => 0, 'output' => '']);

        $this->assertFalse($runner->run());

        $confirm = $runner->httpCalls[2];
        $this->assertSame('failed', $confirm['body']['status']);
        $this->assertStringContainsString('ai-backend-set-key failed', $confirm['body']['error']);
        // Token nesmí uniknout do confirm.error ani do logu — maskuje se.
        $this->assertStringNotContainsString(self::GW_TOKEN, $confirm['body']['error']);
        $this->assertStringContainsString('***', $confirm['body']['error']);
        $this->assertStringNotContainsString(self::GW_TOKEN, implode("\n", $logLines));
    }

    public function testAiSectionWithMissingFieldsConfirmsFailed(): void
    {
        $this->createDs(self::NEW_DS, 'Nová firma', ['install.base']);
        $runner = $this->makeRunner();
        $runner->httpResponses['queue'] = $this->queueResponse([
            $this->queueItem(['ai' => ['base_url' => self::GW_BASE_URL]]),
        ]);
        $runner->onModuleCheck = static fn(): bool => false;

        $this->assertFalse($runner->run());
        $this->assertStringContainsString('ai section is missing', $runner->httpCalls[2]['body']['error']);
    }

    public function testCoreMailGatingResolvesTransitiveDependencies(): void
    {
        // Reálná rezoluce přes repo modules: install.base → … → core.mail;
        // samotný core.system core.mail nemá.
        $runner = new class(
            new HostingConfig('http://127.0.0.1/x', 1, 'shpd_hk_' . str_repeat('a', 43)),
            $this->dataSourcesDir,
            '/fake/bin/shpd-server',
            '/fake/bin/shpd-ds',
        ) extends HostingSyncRunner {
            public function checkModule(string $dsDir, string $moduleId): bool
            {
                return $this->isModuleActiveForDs($dsDir, $moduleId);
            }
        };

        $this->createDs('dddd-dddd-dddd-dddd', 'S mailem', ['install.base']);
        $this->createDs('ffff-ffff-ffff-ffff', 'Bez mailu', ['core.system']);

        $this->assertTrue($runner->checkModule($this->dataSourcesDir . '/dddd-dddd-dddd-dddd', 'core.mail'));
        $this->assertFalse($runner->checkModule($this->dataSourcesDir . '/ffff-ffff-ffff-ffff', 'core.mail'));
    }

    // -------------------------------------------------------------------------
    // Stats push (D7)
    // -------------------------------------------------------------------------

    /** Reconcile response se stats_wanted. */
    private function reconcileResponse(bool $statsWanted): array
    {
        return [
            'statusCode' => 200,
            'body' => (string) json_encode(['success' => true, 'data' => ['ok' => true, 'stats_wanted' => $statsWanted]]),
            'error' => null,
        ];
    }

    /** @return list<array{argv: list<string>, cwd: ?string}> */
    private function statsProcessCalls(StubHostingSyncRunner $runner): array
    {
        return array_values(array_filter(
            $runner->processCalls,
            static fn(array $c): bool => $c['argv'][1] === 'hosting-stats',
        ));
    }

    /** @return list<array{method: string, url: string, body: ?array}> */
    private function statsHttpCalls(StubHostingSyncRunner $runner): array
    {
        return array_values(array_filter(
            $runner->httpCalls,
            static fn(array $c): bool => str_contains($c['url'], '/stats'),
        ));
    }

    public function testStatsStepSkippedWithoutStatsWanted(): void
    {
        // Default reconcile response stats_wanted nenese → krok 3 neběží.
        $runner = $this->makeRunner();

        $this->assertTrue($runner->run());
        $this->assertSame([], $this->statsProcessCalls($runner));
        $this->assertSame([], $this->statsHttpCalls($runner));
    }

    public function testStatsWantedCollectsPerDsAndPushes(): void
    {
        $this->createDs('cccc-cccc-cccc-cccc', 'Bez mailu', ['core.system']);
        $runner = $this->makeRunner();
        $runner->httpResponses['reconcile'] = $this->reconcileResponse(true);
        $runner->onProcess = static function (array $argv, ?string $cwd): array {
            $output = str_contains((string) $cwd, self::EXISTING_DS)
                ? '{"alerts":2,"mail":3}'
                : '{"alerts":1,"mail":null}';
            return ['exitCode' => 0, 'output' => $output];
        };

        $this->assertTrue($runner->run());

        $calls = $this->statsProcessCalls($runner);
        $this->assertCount(2, $calls);
        $this->assertSame(['/fake/bin/shpd-ds', 'hosting-stats', '--json'], $calls[0]['argv']);
        $this->assertSame($this->dataSourcesDir . '/' . self::EXISTING_DS, $calls[0]['cwd']);
        $this->assertSame($this->dataSourcesDir . '/cccc-cccc-cccc-cccc', $calls[1]['cwd']);

        $push = $this->statsHttpCalls($runner);
        $this->assertCount(1, $push);
        $this->assertSame('POST', $push[0]['method']);
        $this->assertSame(
            ['stats' => [
                ['ds_id' => self::EXISTING_DS, 'alerts' => 2, 'mail' => 3],
                ['ds_id' => 'cccc-cccc-cccc-cccc', 'alerts' => 1, 'mail' => null],
            ]],
            $push[0]['body'],
        );
    }

    public function testForceStatsRunsStepWithoutStatsWanted(): void
    {
        $runner = $this->makeRunner();
        $runner->onProcess = static fn(): array => ['exitCode' => 0, 'output' => '{"alerts":0,"mail":0}'];

        $this->assertTrue($runner->run(forceStats: true));

        $this->assertCount(1, $this->statsProcessCalls($runner));
        $this->assertCount(1, $this->statsHttpCalls($runner));
    }

    public function testStatsFailureOfOneDsDoesNotStopOthers(): void
    {
        $this->createDs('cccc-cccc-cccc-cccc', 'Zdravý DS', ['core.system']);
        $runner = $this->makeRunner();
        $runner->httpResponses['reconcile'] = $this->reconcileResponse(true);
        $runner->onProcess = static function (array $argv, ?string $cwd): array {
            if (str_contains((string) $cwd, self::EXISTING_DS)) {
                return ['exitCode' => 1, 'output' => 'boom'];
            }
            return ['exitCode' => 0, 'output' => '{"alerts":1,"mail":2}'];
        };

        // Selhání sběru jednoho DS běh neshodí — push jde s tím, co se sebralo.
        $this->assertTrue($runner->run());

        $push = $this->statsHttpCalls($runner);
        $this->assertCount(1, $push);
        $this->assertSame(
            [['ds_id' => 'cccc-cccc-cccc-cccc', 'alerts' => 1, 'mail' => 2]],
            $push[0]['body']['stats'],
        );
    }

    public function testStatsEmptyCollectionSkipsPost(): void
    {
        $runner = $this->makeRunner();
        $runner->httpResponses['reconcile'] = $this->reconcileResponse(true);
        $runner->onProcess = static fn(): array => ['exitCode' => 1, 'output' => 'boom'];

        $this->assertTrue($runner->run());
        $this->assertSame([], $this->statsHttpCalls($runner));
    }

    public function testStatsUnparsableOutputSkipsDs(): void
    {
        $runner = $this->makeRunner();
        $runner->httpResponses['reconcile'] = $this->reconcileResponse(true);
        $runner->onProcess = static fn(): array => ['exitCode' => 0, 'output' => 'no json here'];

        $this->assertTrue($runner->run());
        $this->assertCount(1, $this->statsProcessCalls($runner));
        $this->assertSame([], $this->statsHttpCalls($runner));
    }

    private function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmdirRecursive($path) : unlink($path);
        }
        rmdir($dir);
    }
}

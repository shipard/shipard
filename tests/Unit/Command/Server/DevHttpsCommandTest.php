<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\Server;

use PHPUnit\Framework\TestCase;
use Shipard\Command\Server\DevHttpsCommand;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Server\DevAccess;
use Shipard\Core\Server\DevCertificate;
use Shipard\Core\Server\DevHttpsPlanner;
use Shipard\Tests\Unit\Core\Server\DevCertificateFixture;
use Shipard\Tests\Unit\Core\Server\DevHttpsEnvironment;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

class TestableDevHttpsCommand extends DevHttpsCommand
{
    public int $euid = 0;
    public \DateTimeImmutable $now;
    /** @var list<string> */
    public array $addresses = DevHttpsEnvironment::ADDRESSES;
    public int $nginxTestExit = 0;
    public int $nginxReloadExit = 0;
    public int $opensslExit = 0;
    /** @var list<string> `openssl` | `nginx -t` | `reload` v pořadí volání */
    public array $calls = [];
    /** @var list<array{path: string, owner: string, group: string, mode: int}> */
    public array $ownership = [];
    /** @var list<list<string>> */
    public array $opensslArgv = [];
    public ?string $opensslConfig = null;

    public function __construct(
        private readonly DevHttpsEnvironment $env,
        private readonly ?ServerConfig $config,
    ) {
        parent::__construct($config);
        $this->now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    protected function loadServerConfig(): ?ServerConfig
    {
        return $this->config;
    }

    protected function getEuid(): int
    {
        return $this->euid;
    }

    protected function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    protected function getHostname(): string
    {
        return 'dev-box';
    }

    protected function createAccess(ServerConfig $config): DevAccess
    {
        return DevHttpsEnvironment::access($config->getDevAccess(), $config->getMode(), $this->addresses);
    }

    protected function createPlanner(): DevHttpsPlanner
    {
        return $this->env->planner();
    }

    protected function detectShipardUser(string $mode): string
    {
        return 'dev';
    }

    protected function applyOwnership(string $path, string $owner, string $group, int $mode): void
    {
        $this->ownership[] = ['path' => $path, 'owner' => $owner, 'group' => $group, 'mode' => $mode];
        @chmod($path, $mode);
    }

    /** Místo `openssl` vyrobí pár z předpisu v `-config` (adresy z oddílu [san]). */
    protected function runProcess(array $argv): array
    {
        $this->calls[] = 'openssl';
        $this->opensslArgv[] = $argv;
        if ($this->opensslExit !== 0) {
            return ['exitCode' => $this->opensslExit, 'output' => 'openssl: simulated failure'];
        }
        $arg = static fn (string $name): string => $argv[array_search($name, $argv, true) + 1];
        $this->opensslConfig = (string) file_get_contents($arg('-config'));
        preg_match_all('/^IP\.\d+ = (\S+)$/m', $this->opensslConfig, $m);
        [$cert, $key] = DevCertificateFixture::pem($m[1], [], (int) $arg('-days'));
        file_put_contents($arg('-out'), $cert);
        file_put_contents($arg('-keyout'), $key);
        return ['exitCode' => 0, 'output' => ''];
    }

    protected function nginxTest(): array
    {
        $this->calls[] = 'nginx -t';
        return ['exitCode' => $this->nginxTestExit, 'output' => $this->nginxTestExit === 0 ? 'ok' : 'nginx: [emerg] simulated failure'];
    }

    protected function nginxReload(): array
    {
        $this->calls[] = 'reload';
        return ['exitCode' => $this->nginxReloadExit, 'output' => $this->nginxReloadExit === 0 ? '' : 'reload failed'];
    }
}

/** `shpd-server dev-https` (#183 U3, U21). */
class DevHttpsCommandTest extends TestCase
{
    private DevHttpsEnvironment $env;

    protected function setUp(): void
    {
        $this->env = new DevHttpsEnvironment();
    }

    protected function tearDown(): void
    {
        $this->env->remove();
    }

    /** @param array<string, mixed>|null $devAccess */
    private function command(?array $devAccess, string $mode = 'development'): TestableDevHttpsCommand
    {
        $data = ['host' => '127.0.0.1', 'port' => 3306, 'admin_user' => 'root', 'admin_password' => 'x', 'mode' => $mode];
        if ($devAccess !== null) {
            $data['devAccess'] = $devAccess;
        }
        $file = $this->env->root . '/server.json';
        file_put_contents($file, json_encode($data));
        $config = new ServerConfig($file);
        $config->load();
        return new TestableDevHttpsCommand($this->env, $config);
    }

    /** @param array<string, mixed> $input */
    private function run_(TestableDevHttpsCommand $command, array $input = [], int $verbosity = OutputInterface::VERBOSITY_NORMAL): array
    {
        $tester = new CommandTester($command);
        $exit = $tester->execute($input, ['verbosity' => $verbosity]);
        return [$exit, $tester->getDisplay()];
    }

    private function ipCert(): string
    {
        return $this->env->tlsDir . '/dev-ip/fullchain.pem';
    }

    private function ipKey(): string
    {
        return $this->env->tlsDir . '/dev-ip/privkey.pem';
    }

    // ── odmítnutí ───────────────────────────────────────────────────────────

    public function testRefusesOutsideDevelopment(): void
    {
        $command = $this->command(['httpsIp' => true], 'production');
        [$exit, $out] = $this->run_($command);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('development servers only', $out);
        $this->assertSame([], $command->calls);
        $this->assertDirectoryDoesNotExist($this->env->tlsDir);
    }

    public function testRefusesWithoutServerConfig(): void
    {
        $command = new TestableDevHttpsCommand($this->env, null);
        [$exit, $out] = $this->run_($command);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('server-init', $out);
        $this->assertSame(0, $this->run_($command, ['--check' => true])[0], '--check bez server.json nic nehlásí');
    }

    public function testRefusesWithoutRootAndChangesNothing(): void
    {
        $command = $this->command(['httpsIp' => true]);
        $command->euid = 1000;
        [$exit, $out] = $this->run_($command);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('must run as root', $out);
        $this->assertSame([], $command->calls);
        $this->assertFileDoesNotExist($this->ipCert());
        $this->assertFileDoesNotExist($this->env->siteFile);
    }

    // ── běh ─────────────────────────────────────────────────────────────────

    public function testCreatesCertificateSiteAndReloadsNginx(): void
    {
        $command = $this->command(['httpsIp' => true]);
        [$exit, $out] = $this->run_($command);

        $this->assertSame(0, $exit, $out);
        $this->assertSame(['openssl', 'nginx -t', 'reload'], $command->calls);

        // Certifikát pro IP: všechny adresy serveru, klíč jen pro root.
        $certificate = DevCertificate::read($this->ipCert());
        $this->assertSame(DevHttpsEnvironment::ADDRESSES, $certificate->ips);
        $this->assertTrue($certificate->matchesKeyFile($this->ipKey()));
        $this->assertSame(0600, fileperms($this->ipKey()) & 0777);
        $this->assertSame(0644, fileperms($this->ipCert()) & 0777);
        $this->assertStringContainsString('CN = Shipard dev dev-box', (string) $command->opensslConfig);
        $this->assertSame('397', $command->opensslArgv[0][array_search('-days', $command->opensslArgv[0], true) + 1]);
        $this->assertSame([], glob($this->env->tlsDir . '/dev-ip/.*.tmp') ?: [], 'dočasné soubory jsou pryč');

        // Práva podle kontraktu (U4).
        $byPath = array_column($command->ownership, null, 'path');
        $this->assertSame(['root', 'dev', 0750], array_values(array_slice($byPath[$this->env->tlsDir], 1)));
        $this->assertSame(['root', 'dev', 0750], array_values(array_slice($byPath[$this->env->tlsDir . '/dev-ip'], 1)));
        $this->assertSame(['root', 'dev', 0750], array_values(array_slice($byPath[$this->env->tlsDir . '/dev-host'], 1)));
        $this->assertSame(['root', 'root', 0600], array_values(array_slice($byPath[$this->env->tlsDir . '/dev-ip/.privkey.pem.tmp'], 1)));
        $this->assertSame(['root', 'dev', 0644], array_values(array_slice($byPath[$this->env->tlsDir . '/dev-ip/.fullchain.pem.tmp'], 1)));

        // Site soubor a odkaz.
        $this->assertFileExists($this->env->siteFile);
        $this->assertTrue(is_link($this->env->siteLink));
        $this->assertSame($this->env->siteFile, readlink($this->env->siteLink));
        $site = (string) file_get_contents($this->env->siteFile);
        $this->assertStringContainsString('listen 443 ssl default_server;', $site);
        $this->assertStringContainsString('fastcgi_pass unix:' . DevHttpsEnvironment::SOCKET . ';', $site);
        $this->assertStringNotContainsString('dev-host', $site);

        $this->assertStringContainsString('https-ip   https://10.0.0.7', $out);
        $this->assertStringContainsString('new warning in the browser', $out);
    }

    public function testSecondRunIsIdempotent(): void
    {
        $this->run_($this->command(['httpsIp' => true]));
        $certBefore = file_get_contents($this->ipCert());

        $command = $this->command(['httpsIp' => true]);
        [$exit, $out] = $this->run_($command);

        $this->assertSame(0, $exit);
        $this->assertSame([], $command->calls, 'bez změny žádný openssl, nginx -t ani reload');
        $this->assertSame($certBefore, file_get_contents($this->ipCert()));
        $this->assertStringContainsString('up to date', $out);
        $this->assertMatchesRegularExpression('/certificate for the IP entry is valid \(39[67] days left\)/', $out);
    }

    public function testRenewsCertificateBeforeExpiryAndOnNewAddress(): void
    {
        $this->run_($this->command(['httpsIp' => true]));

        // Zbývá méně než 30 dní → nový certifikát, site beze změny, reload.
        $command = $this->command(['httpsIp' => true]);
        $command->now = $command->now->modify('+380 days');
        [$exit, $out] = $this->run_($command);
        $this->assertSame(0, $exit, $out);
        $this->assertSame(['openssl', 'nginx -t', 'reload'], $command->calls);
        $this->assertStringContainsString('expires in', $out);

        // Nová adresa serveru → nový certifikát s ní.
        $command = $this->command(['httpsIp' => true]);
        $command->addresses = ['10.0.0.7', '2001:db8::7', '192.168.5.1'];
        [$exit, $out] = $this->run_($command);
        $this->assertSame(0, $exit, $out);
        $this->assertStringContainsString('does not cover 192.168.5.1', $out);
        $this->assertSame(['10.0.0.7', '192.168.5.1', '2001:db8::7'], DevCertificate::read($this->ipCert())->ips);

        // Adresa zanikla → certifikát ji nese navíc, bez obnovy (U20).
        $command = $this->command(['httpsIp' => true]);
        $command->addresses = ['10.0.0.7'];
        $this->run_($command);
        $this->assertSame([], $command->calls);
    }

    public function testFailedOpensslLeavesEverythingAsItWas(): void
    {
        $command = $this->command(['httpsIp' => true]);
        $command->opensslExit = 1;
        [$exit, $out] = $this->run_($command);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('openssl failed (exit 1)', $out);
        $this->assertSame(['openssl'], $command->calls);
        $this->assertFileDoesNotExist($this->ipCert());
        $this->assertFileDoesNotExist($this->env->siteFile);
        $this->assertSame([], glob($this->env->tlsDir . '/dev-ip/.*.tmp') ?: []);
    }

    public function testFailedNginxTestRestoresTheFirstInstall(): void
    {
        $command = $this->command(['httpsIp' => true]);
        $command->nginxTestExit = 1;
        [$exit, $out] = $this->run_($command);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('nginx -t failed', $out);
        $this->assertStringContainsString('simulated failure', $out);
        $this->assertSame(['openssl', 'nginx -t'], $command->calls, 'bez reloadu');
        $this->assertFileDoesNotExist($this->env->siteFile);
        $this->assertFalse(is_link($this->env->siteLink));
    }

    public function testFailedNginxTestRestoresThePreviousSite(): void
    {
        $this->run_($this->command(['httpsIp' => true]));
        $previous = (string) file_get_contents($this->env->siteFile);
        $this->env->writeHostCertificate(['shpd.example.com']);

        $command = $this->command(['httpsIp' => true, 'host' => 'shpd.example.com']);
        $command->nginxTestExit = 1;
        [$exit, $out] = $this->run_($command);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('was restored', $out);
        $this->assertSame($previous, file_get_contents($this->env->siteFile));
        $this->assertTrue(is_link($this->env->siteLink));
        $this->assertNotContains('reload', $command->calls);
    }

    public function testFailedReloadIsAnError(): void
    {
        $command = $this->command(['httpsIp' => true]);
        $command->nginxReloadExit = 1;
        [$exit, $out] = $this->run_($command);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('nginx reload failed', $out);
    }

    public function testDisablingAllEntriesRemovesTheSite(): void
    {
        $this->run_($this->command(['httpsIp' => true]));
        $this->assertFileExists($this->env->siteFile);

        $command = $this->command(['httpsIp' => false]);
        [$exit, $out] = $this->run_($command);

        $this->assertSame(0, $exit, $out);
        $this->assertFileDoesNotExist($this->env->siteFile);
        $this->assertFalse(is_link($this->env->siteLink));
        $this->assertSame(['nginx -t', 'reload'], $command->calls);
        $this->assertStringContainsString('removed (no https entry enabled)', $out);

        // A další běh už nic nedělá.
        $command = $this->command(null);
        [$exit, $out] = $this->run_($command);
        $this->assertSame(0, $exit);
        $this->assertSame([], $command->calls);
        $this->assertStringContainsString('nothing to do', $out);
    }

    public function testHostEntryWithTheDevelopersCertificate(): void
    {
        $this->env->writeHostCertificate(['shpd.example.com']);
        $command = $this->command(['host' => 'shpd.example.com']);
        [$exit, $out] = $this->run_($command);

        $this->assertSame(0, $exit, $out);
        $this->assertSame(['nginx -t', 'reload'], $command->calls, 'certifikát pro jméno se nevyrábí');
        $site = (string) file_get_contents($this->env->siteFile);
        $this->assertStringContainsString('server_name shpd.example.com;', $site);
        $this->assertStringNotContainsString('dev-ip', $site);
        $this->assertFileDoesNotExist($this->ipCert());
        $this->assertStringContainsString('https-host https://shpd.example.com', $out);
    }

    public function testMissingHostCertificateFailsButKeepsTheIpEntry(): void
    {
        $command = $this->command(['httpsIp' => true, 'host' => 'shpd.example.com']);
        [$exit, $out] = $this->run_($command);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('certificate for shpd.example.com not found', $out);
        $this->assertStringContainsString($this->env->tlsDir . '/dev-host/fullchain.pem', $out);
        $this->assertStringContainsString('(not active)', $out);
        // Vstup přes IP vznikl a nginx běží dál.
        $this->assertSame(['openssl', 'nginx -t', 'reload'], $command->calls);
        $site = (string) file_get_contents($this->env->siteFile);
        $this->assertStringContainsString('default_server', $site);
        $this->assertStringNotContainsString('shpd.example.com', $site);
        $this->assertDirectoryExists($this->env->tlsDir . '/dev-host', 'adresář pro certifikát je připravený');
    }

    public function testForeignDefaultServerWarnsAndSucceeds(): void
    {
        // U21
        $this->env->writeForeignSite('intranet', "server { listen 443 ssl default_server; }\n");
        $command = $this->command(['httpsIp' => true]);
        [$exit, $out] = $this->run_($command);

        $this->assertSame(0, $exit, $out);
        $this->assertStringContainsString('default server on port 443', $out);
        $this->assertSame([], $command->calls);
        $this->assertFileDoesNotExist($this->env->siteFile);
        $this->assertFileDoesNotExist($this->ipCert());
        $this->assertSame(0, $this->run_($this->command(['httpsIp' => true]), ['--check' => true])[0]);
    }

    public function testInvalidConfigValuesAreReported(): void
    {
        $command = $this->command(['httpsIp' => true, 'host' => 'shpd example']);
        [$exit, $out] = $this->run_($command);

        $this->assertSame(0, $exit, $out);
        $this->assertStringContainsString("server.json: 'devAccess.host'", $out);
        $this->assertStringNotContainsString('shpd example', (string) file_get_contents($this->env->siteFile));
    }

    // ── --check ─────────────────────────────────────────────────────────────

    public function testCheckIsSilentNeedsNoRootAndChangesNothing(): void
    {
        $command = $this->command(['httpsIp' => true]);
        $command->euid = 1000;
        [$exit, $out] = $this->run_($command, ['--check' => true]);

        $this->assertSame(1, $exit, 'certifikát a site chybí → běh by něco udělal');
        $this->assertSame('', $out);
        $this->assertSame([], $command->calls);
        $this->assertDirectoryDoesNotExist($this->env->tlsDir);
        $this->assertFileDoesNotExist($this->env->siteFile);

        [, $verbose] = $this->run_($command, ['--check' => true], OutputInterface::VERBOSITY_VERBOSE);
        $this->assertStringContainsString('certificate for the IP entry: missing', $verbose);
        $this->assertStringContainsString('shipard-https.conf is missing', $verbose);
    }

    public function testCheckPassesAfterARunAndFailsWhenARunIsDueAgain(): void
    {
        $this->run_($this->command(['httpsIp' => true]));

        $check = fn (TestableDevHttpsCommand $c): int => $this->run_($c, ['--check' => true])[0];

        $this->assertSame(0, $check($this->command(['httpsIp' => true])));
        $this->assertSame(0, $check($this->command(null, 'production')), 'mimo vývoj vždy 0');

        // Expirující certifikát.
        $command = $this->command(['httpsIp' => true]);
        $command->now = $command->now->modify('+380 days');
        $this->assertSame(1, $check($command));

        // Změna adres.
        $command = $this->command(['httpsIp' => true]);
        $command->addresses = ['10.0.0.9'];
        $this->assertSame(1, $check($command));

        // Zastaralý site soubor.
        file_put_contents($this->env->siteFile, "# edited\n");
        $this->assertSame(1, $check($this->command(['httpsIp' => true])));

        // Vypnuté vstupy, ale site zůstal.
        $this->assertSame(1, $check($this->command(null)));
    }

    public function testCheckWithoutAnyEntryPasses(): void
    {
        $this->assertSame(0, $this->run_($this->command(null), ['--check' => true])[0]);
    }
}

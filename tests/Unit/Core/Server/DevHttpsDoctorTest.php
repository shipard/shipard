<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Server;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Net\LocalAddresses;
use Shipard\Core\Server\DevHttpsDoctor;

/** Sekce Development HTTPS příkazu `doctor` (#183 U1, U11). */
class DevHttpsDoctorTest extends TestCase
{
    private const string HOST = 'shpd.example.com';

    private DevHttpsEnvironment $env;
    private \DateTimeImmutable $now;
    /** @var array<string, list<string>> */
    private array $dns = [self::HOST => ['10.0.0.7']];

    protected function setUp(): void
    {
        $this->env = new DevHttpsEnvironment();
        $this->now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    protected function tearDown(): void
    {
        $this->env->remove();
    }

    /**
     * @param array<string, mixed>|null $section
     * @return list<array{level: string, message: string, hints: list<string>}>
     */
    private function check(?array $section, string $mode = 'development', ?\DateTimeImmutable $now = null, bool $privileged = true, array $addresses = DevHttpsEnvironment::ADDRESSES): array
    {
        $dns = $this->dns;
        $local = new LocalAddresses(
            static fn (string $host): array => $dns[$host] ?? [],
            static fn (): array => ['127.0.0.1', ...$addresses],
        );
        return (new DevHttpsDoctor($this->env->planner(privileged: $privileged), $local))
            ->check(DevHttpsEnvironment::access($section, $mode, $addresses), $now ?? $this->now);
    }

    /** Nainstaluje site soubor, který pro konfiguraci vyrobí `dev-https`. */
    private function installExpectedSite(array $section): void
    {
        $plan = $this->env->planner()->plan(DevHttpsEnvironment::access($section), $this->now);
        $this->env->installSite((string) $plan->expectedSite);
    }

    /**
     * @param list<array{level: string, message: string, hints: list<string>}> $findings
     * @return list<string> `level: message`
     */
    private static function lines(array $findings): array
    {
        return array_map(static fn (array $f): string => $f['level'] . ': ' . $f['message'], $findings);
    }

    /** @param list<array{level: string, message: string, hints: list<string>}> $findings */
    private function assertFinding(string $level, string $needle, array $findings): array
    {
        foreach ($findings as $finding) {
            if ($finding['level'] === $level && str_contains($finding['message'], $needle)) {
                $this->addToAssertionCount(1);
                return $finding;
            }
        }
        $this->fail("Chybí nález {$level} s „{$needle}“ v:\n" . implode("\n", self::lines($findings)));
    }

    /** @param list<array{level: string, message: string, hints: list<string>}> $findings */
    private static function levels(array $findings): array
    {
        return array_values(array_unique(array_column($findings, 'level')));
    }

    // ── režim a konfigurace ─────────────────────────────────────────────────

    public function testProductionWithoutSectionIsSkipped(): void
    {
        $this->assertSame(['info: (development servers only — skipped)'], self::lines($this->check(null, 'production')));
    }

    public function testDevAccessInProductionIsAWarning(): void
    {
        $findings = $this->check(['httpsIp' => true], 'production');

        $this->assertCount(1, $findings);
        $this->assertFinding('warning', 'production mode — the section is ignored', $findings);
    }

    public function testNoHttpsEntry(): void
    {
        $this->assertSame(['info: (no https entry enabled — http on the IP address only)'], self::lines($this->check(null)));

        // Vypnuté vstupy, ale site soubor zůstal.
        $this->env->installSite("# old\n");
        $findings = $this->check(['httpsIp' => false]);
        $finding = $this->assertFinding('warning', 'shipard-https.conf is still installed', $findings);
        $this->assertStringContainsString('sudo shpd-server dev-https', $finding['hints'][0]);
    }

    public function testInvalidConfigValueIsAWarning(): void
    {
        $findings = $this->check(['httpsIp' => 'yes']);

        $this->assertFinding('warning', "server.json: 'devAccess.httpsIp'", $findings);
        $this->assertNotContains('error', self::levels($findings));
    }

    // ── vstup přes IP ───────────────────────────────────────────────────────

    public function testHealthyIpEntry(): void
    {
        $this->env->writeIpCertificate();
        $this->installExpectedSite(['httpsIp' => true]);

        $findings = $this->check(['httpsIp' => true]);

        $this->assertSame(['ok'], self::levels($findings));
        $this->assertFinding('ok', 'https on the IP address: certificate valid for', $findings);
        $this->assertFinding('ok', 'covers 10.0.0.7, 2001:db8::7', $findings);
        $this->assertFinding('ok', 'shipard-https.conf is enabled and up to date', $findings);
    }

    public function testMissingIpCertificateIsAnErrorWithTheCommand(): void
    {
        $findings = $this->check(['httpsIp' => true]);

        $finding = $this->assertFinding('error', 'https on the IP address: certificate is missing', $findings);
        $this->assertSame(['Run: sudo shpd-server dev-https'], $finding['hints']);
        $this->assertFinding('warning', 'shipard-https.conf is missing', $findings);
    }

    public function testExpiringIpCertificateIsAWarningFourteenDaysAhead(): void
    {
        $this->env->writeIpCertificate(days: 100);
        $this->installExpectedSite(['httpsIp' => true]);

        // 30–14 dní: obnova je na řadě, ale ještě ne varování.
        $findings = $this->check(['httpsIp' => true], now: $this->now->modify('+80 days'));
        $this->assertFinding('info', 'renewal is due', $findings);
        $this->assertNotContains('warning', self::levels($findings));
        $this->assertNotContains('error', self::levels($findings));

        // Méně než 14 dní: varování.
        $findings = $this->check(['httpsIp' => true], now: $this->now->modify('+90 days'));
        $this->assertFinding('warning', 'https on the IP address: certificate expires in', $findings);
        $this->assertNotContains('error', self::levels($findings));
    }

    public function testExpiredIpCertificateIsAnError(): void
    {
        $this->env->writeIpCertificate(days: 100);
        $this->installExpectedSite(['httpsIp' => true]);

        $findings = $this->check(['httpsIp' => true], now: $this->now->modify('+101 days'));

        $this->assertFinding('error', 'https on the IP address: certificate expired', $findings);
    }

    public function testIpCertificateThatDoesNotCoverAnAddressIsAnError(): void
    {
        $this->env->writeIpCertificate(['10.0.0.7']);
        $this->installExpectedSite(['httpsIp' => true]);

        $findings = $this->check(['httpsIp' => true]);
        $this->assertFinding('error', 'certificate does not cover 2001:db8::7', $findings);

        // U20: adresa v certifikátu navíc chybou není.
        $this->env->writeIpCertificate(['10.0.0.7', '2001:db8::7', '2001:db8::dead']);
        $this->assertNotContains('error', self::levels($this->check(['httpsIp' => true])));
    }

    public function testIpKeyMismatchIsAnErrorWhereTheKeyIsReadable(): void
    {
        $files = $this->env->writeIpCertificate();
        [, $otherKey] = DevCertificateFixture::pem(['10.0.0.7']);
        file_put_contents($files['key'], $otherKey);

        $this->assertFinding('error', 'private key does not match the certificate', $this->check(['httpsIp' => true]));

        // Bez roota klíč číst nejde — shoda se neposuzuje (hlídá ji dev-https a nginx -t).
        chmod($files['key'], 0000);
        try {
            if (!is_readable($files['key'])) {
                $this->installExpectedSite(['httpsIp' => true]);
                $this->assertNotContains('error', self::levels($this->check(['httpsIp' => true])));
            }
        } finally {
            chmod($files['key'], 0600);
        }
    }

    public function testForeignDefaultServerIsAWarning(): void
    {
        // U21
        $this->env->writeForeignSite('intranet', "server { listen 443 ssl default_server; }\n");

        $findings = $this->check(['httpsIp' => true]);

        $this->assertFinding('warning', 'another nginx site is the default server on port 443', $findings);
        $this->assertNotContains('error', self::levels($findings));
    }

    public function testServerWithoutAddressIsAnError(): void
    {
        $findings = $this->check(['httpsIp' => true], addresses: []);

        $this->assertFinding('error', 'no address of this server found', $findings);
        $this->assertCount(1, array_filter($findings, static fn (array $f): bool => $f['level'] === 'error'));
    }

    // ── vstup přes jméno ────────────────────────────────────────────────────

    public function testHealthyHostEntry(): void
    {
        $this->env->writeHostCertificate([self::HOST]);
        $this->installExpectedSite(['host' => self::HOST]);

        $findings = $this->check(['host' => self::HOST]);

        $this->assertSame(['ok'], self::levels($findings));
        $this->assertFinding('ok', 'https on shpd.example.com: certificate valid for', $findings);
        $this->assertFinding('ok', 'resolves to this server (10.0.0.7)', $findings);
    }

    public function testMissingHostCertificateIsAnError(): void
    {
        $finding = $this->assertFinding('error', 'certificate for shpd.example.com not found', $this->check(['host' => self::HOST]));

        $this->assertStringContainsString($this->env->tlsDir . '/dev-host/', $finding['hints'][0]);
    }

    public function testHostCertificateForAnotherNameIsAnError(): void
    {
        $this->env->writeHostCertificate(['other.example.com']);

        $this->assertFinding('error', 'does not cover shpd.example.com', $this->check(['host' => self::HOST]));
    }

    public function testHostCertificateExpiry(): void
    {
        $this->env->writeHostCertificate([self::HOST], days: 90);
        $this->installExpectedSite(['host' => self::HOST]);

        $this->assertSame(['ok'], self::levels($this->check(['host' => self::HOST], now: $this->now->modify('+70 days'))));

        $findings = $this->check(['host' => self::HOST], now: $this->now->modify('+80 days'));
        $this->assertFinding('warning', 'https on shpd.example.com: certificate expires in', $findings);
        $this->assertNotContains('error', self::levels($findings));

        $findings = $this->check(['host' => self::HOST], now: $this->now->modify('+91 days'));
        $this->assertFinding('error', 'https on shpd.example.com: certificate expired on', $findings);
    }

    public function testUnreadableHostCertificateIsAWarningWithoutRoot(): void
    {
        // U4: symlink do adresáře jen pro root — rada spustit doctor pod rootem.
        $this->env->writeHostCertificate([self::HOST]);
        $this->installExpectedSite(['host' => self::HOST]);
        unlink($this->env->tlsDir . '/dev-host/fullchain.pem');
        symlink($this->env->root . '/root-only/fullchain.pem', $this->env->tlsDir . '/dev-host/fullchain.pem');

        $findings = $this->check(['host' => self::HOST], privileged: false);

        $finding = $this->assertFinding('warning', 'certificate is not readable for this user', $findings);
        $this->assertSame(['Run: sudo shpd-server doctor'], $finding['hints']);
        $this->assertNotContains('error', self::levels($findings));
        $this->assertFinding('ok', 'shipard-https.conf is enabled and up to date', $findings);
    }

    public function testHostMustResolveToThisServer(): void
    {
        $this->env->writeHostCertificate([self::HOST]);
        $this->installExpectedSite(['host' => self::HOST]);

        $this->dns = [];
        $finding = $this->assertFinding('error', 'the name does not resolve on this server', $this->check(['host' => self::HOST]));
        $this->assertStringContainsString('/etc/hosts', $finding['hints'][0]);

        $this->dns = [self::HOST => ['10.0.0.7', '203.0.113.9']];
        $this->assertFinding('error', 'resolves to 203.0.113.9, which is not an address of this server', $this->check(['host' => self::HOST]));

        // Loopback (záznam v /etc/hosts) míří sem.
        $this->dns = [self::HOST => ['127.0.1.1']];
        $this->assertFinding('ok', 'resolves to this server (127.0.1.1)', $this->check(['host' => self::HOST]));
    }

    // ── site soubor ─────────────────────────────────────────────────────────

    public function testOutdatedSiteIsAWarningWithTheCommand(): void
    {
        $this->env->writeIpCertificate();
        $this->env->installSite("# edited by hand\n");

        $findings = $this->check(['httpsIp' => true]);

        $finding = $this->assertFinding('warning', 'shipard-https.conf is outdated', $findings);
        $this->assertSame(['Run: sudo shpd-server dev-https'], $finding['hints']);
        $this->assertNotContains('error', self::levels($findings));
    }

    public function testBothEntriesReportEachOnItsOwn(): void
    {
        $this->env->writeIpCertificate();
        $findings = $this->check(['httpsIp' => true, 'host' => self::HOST]);

        $this->assertFinding('ok', 'https on the IP address: certificate valid', $findings);
        $this->assertFinding('error', 'https on shpd.example.com: certificate for shpd.example.com not found', $findings);
        $this->assertFinding('ok', 'https on shpd.example.com: resolves to this server', $findings);
    }
}

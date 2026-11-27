<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Server;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Server\DevHttpsPlan;
use Shipard\Core\Server\DevHttpsSite;

/** Rozhodnutí `dev-https` bez vedlejších účinků (#183 U3–U6, U20, U21). */
class DevHttpsPlannerTest extends TestCase
{
    private DevHttpsEnvironment $env;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->env = new DevHttpsEnvironment();
        $this->now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    protected function tearDown(): void
    {
        $this->env->remove();
    }

    /** @param array<string, mixed>|null $section */
    private function plan(?array $section, ?\DateTimeImmutable $now = null, array $addresses = DevHttpsEnvironment::ADDRESSES): DevHttpsPlan
    {
        return $this->env->planner()->plan(DevHttpsEnvironment::access($section, 'development', $addresses), $now ?? $this->now);
    }

    public function testNothingEnabledAndNothingInstalledNeedsNoRun(): void
    {
        $plan = $this->plan(null);

        $this->assertFalse($plan->ipBlock);
        $this->assertFalse($plan->hostBlock);
        $this->assertNull($plan->expectedSite);
        $this->assertTrue($plan->siteUpToDate());
        $this->assertFalse($plan->needsRun());
        $this->assertSame([], $plan->pendingActions());
        $this->assertSame([], $plan->blockers);
    }

    public function testNothingEnabledButSiteInstalledWouldRemoveIt(): void
    {
        $this->env->installSite("# old\n");
        $plan = $this->plan(['httpsIp' => false]);

        $this->assertNull($plan->expectedSite);
        $this->assertFalse($plan->siteUpToDate());
        $this->assertTrue($plan->needsRun());
        $this->assertStringContainsString('would be removed', $plan->pendingActions()[0]);
    }

    public function testIpEntryWithoutCertificateNeedsCertificateAndSite(): void
    {
        $plan = $this->plan(['httpsIp' => true]);

        $this->assertTrue($plan->ipBlock);
        $this->assertSame('missing', $plan->ipCertRenewal);
        $this->assertNotNull($plan->expectedSite);
        $this->assertStringContainsString('default_server', (string) $plan->expectedSite);
        $this->assertTrue($plan->needsRun());
        $this->assertCount(2, $plan->pendingActions());
    }

    public function testIpEntryInstalledAndValidNeedsNoRun(): void
    {
        $this->env->writeIpCertificate();
        $this->env->installSite((string) $this->plan(['httpsIp' => true])->expectedSite);

        $plan = $this->plan(['httpsIp' => true]);

        $this->assertNull($plan->ipCertRenewal);
        $this->assertTrue($plan->siteUpToDate());
        $this->assertFalse($plan->needsRun());
    }

    public function testCertificateIsRenewedThirtyDaysBeforeExpiry(): void
    {
        $this->env->writeIpCertificate(days: 100);

        $this->assertNull($this->plan(['httpsIp' => true], $this->now->modify('+69 days'))->ipCertRenewal);
        $this->assertMatchesRegularExpression('/^expires in \d+ days$/', (string) $this->plan(['httpsIp' => true], $this->now->modify('+71 days'))->ipCertRenewal);
        $this->assertSame('expired', $this->plan(['httpsIp' => true], $this->now->modify('+101 days'))->ipCertRenewal);
    }

    public function testCertificateIsRenewedOnlyWhenAnAddressIsMissing(): void
    {
        // U20: certifikát nese adresu navíc (zaniklá dočasná IPv6) → bez obnovy.
        $this->env->writeIpCertificate(['10.0.0.7', '2001:db8::7', '2001:db8::dead']);
        $this->assertNull($this->plan(['httpsIp' => true])->ipCertRenewal);
        $this->assertNull($this->plan(['httpsIp' => true], null, ['10.0.0.7'])->ipCertRenewal);

        // Server dostal novou adresu → obnova.
        $plan = $this->plan(['httpsIp' => true], null, ['10.0.0.7', '2001:db8::7', '192.168.5.1']);
        $this->assertSame('does not cover 192.168.5.1', $plan->ipCertRenewal);

        // Přepis `ip` mimo rozhraní patří do certifikátu také.
        $plan = $this->plan(['httpsIp' => true, 'ip' => '203.0.113.9']);
        $this->assertSame('does not cover 203.0.113.9', $plan->ipCertRenewal);
    }

    public function testBrokenIpCertificateOrKeyIsRenewed(): void
    {
        $files = $this->env->writeIpCertificate();

        unlink($files['key']);
        $this->assertSame('private key is missing', $this->plan(['httpsIp' => true])->ipCertRenewal);

        [, $otherKey] = DevCertificateFixture::pem(['10.0.0.7']);
        file_put_contents($files['key'], $otherKey);
        $this->assertSame('private key does not match the certificate', $this->plan(['httpsIp' => true])->ipCertRenewal);

        file_put_contents($files['cert'], "garbage\n");
        $this->assertSame('file is not a readable certificate', $this->plan(['httpsIp' => true])->ipCertRenewal);
    }

    public function testForeignDefaultServerDropsTheIpBlockWithAWarning(): void
    {
        // U21: blok pro IP nevznikne, běh není potřeba, jen varování.
        $foreign = $this->env->writeForeignSite('intranet', "server {\n    listen 443 ssl http2 default_server;\n    server_name _;\n}\n");
        $plan = $this->plan(['httpsIp' => true]);

        $this->assertFalse($plan->ipBlock);
        $this->assertSame($foreign, $plan->ipConflict);
        $this->assertNull($plan->ipCertRenewal);
        $this->assertNull($plan->expectedSite);
        $this->assertFalse($plan->needsRun());
        $this->assertCount(1, $plan->warnings);
        $this->assertStringContainsString('default server on port 443', $plan->warnings[0]);
        $this->assertSame([], $plan->blockers);
    }

    public function testForeignSitesThatAreNotDefaultOn443DoNotConflict(): void
    {
        $this->env->writeForeignSite('a', "server { listen 80 default_server; }\n");
        $this->env->writeForeignSite('b', "server { listen 443 ssl; server_name intranet.example; }\n");
        $this->env->writeForeignSite('c', "server {\n    # listen 443 ssl default_server;\n    listen 8443 ssl default_server;\n}\n");
        $this->env->writeForeignSite('d', "server { listen 4430 ssl default_server; }\n");

        $this->assertNull($this->plan(['httpsIp' => true])->ipConflict);

        $this->env->writeForeignSite('e', "server { listen [::]:443 ssl default_server; }\n");
        $this->assertNotNull($this->plan(['httpsIp' => true])->ipConflict);
    }

    public function testOwnSiteIsNotAForeignDefaultServer(): void
    {
        $this->env->writeIpCertificate();
        $this->env->installSite((string) $this->plan(['httpsIp' => true])->expectedSite);

        $plan = $this->plan(['httpsIp' => true]);

        $this->assertNull($plan->ipConflict);
        $this->assertTrue($plan->ipBlock);
    }

    public function testDefaultServerInConfDIsFound(): void
    {
        mkdir($this->env->root . '/conf.d');
        file_put_contents($this->env->root . '/conf.d/other.conf', "server { listen 0.0.0.0:443 ssl default_server; }\n");

        $this->assertSame($this->env->root . '/conf.d/other.conf', $this->plan(['httpsIp' => true])->ipConflict);
    }

    public function testHostEntryNeedsTheDevelopersCertificate(): void
    {
        $plan = $this->plan(['host' => 'shpd.example.com']);

        $this->assertFalse($plan->hostBlock);
        $this->assertStringContainsString('certificate for shpd.example.com not found', (string) $plan->hostProblem);
        $this->assertNull($plan->expectedSite);
        // Běh certifikát pro jméno nevyrobí — `--check` ho nepočítá.
        $this->assertFalse($plan->needsRun());
    }

    public function testHostEntryWithMatchingCertificate(): void
    {
        $this->env->writeHostCertificate(['shpd.example.com']);
        $plan = $this->plan(['host' => 'shpd.example.com']);

        $this->assertTrue($plan->hostBlock);
        $this->assertNull($plan->hostProblem);
        $this->assertStringContainsString('server_name shpd.example.com;', (string) $plan->expectedSite);
        $this->assertStringNotContainsString('default_server', preg_replace('/#[^\n]*/', '', (string) $plan->expectedSite) ?? '');
        $this->assertTrue($plan->needsRun());
    }

    public function testHostCertificateProblems(): void
    {
        $files = $this->env->writeHostCertificate(['other.example.com']);
        $this->assertStringContainsString('does not cover shpd.example.com (SAN: other.example.com)', (string) $this->plan(['host' => 'shpd.example.com'])->hostProblem);

        $files = $this->env->writeHostCertificate(['shpd.example.com']);
        [, $otherKey] = DevCertificateFixture::pem([], ['shpd.example.com']);
        file_put_contents($files['key'], $otherKey);
        $this->assertStringContainsString('does not match the certificate', (string) $this->plan(['host' => 'shpd.example.com'])->hostProblem);

        unlink($files['key']);
        $this->assertStringContainsString('private key for shpd.example.com not found', (string) $this->plan(['host' => 'shpd.example.com'])->hostProblem);

        file_put_contents($files['cert'], "garbage\n");
        $this->assertStringContainsString('is not a certificate', (string) $this->plan(['host' => 'shpd.example.com'])->hostProblem);
    }

    public function testHostCertificateMayBeASymlink(): void
    {
        // U4: certbot apod. — oba soubory smí být symlink.
        [$cert, $key] = DevCertificateFixture::pem([], ['shpd.example.com']);
        mkdir($this->env->root . '/live', 0750, true);
        mkdir($this->env->tlsDir . '/dev-host', 0750, true);
        file_put_contents($this->env->root . '/live/fullchain.pem', $cert);
        file_put_contents($this->env->root . '/live/privkey.pem', $key);
        symlink($this->env->root . '/live/fullchain.pem', $this->env->tlsDir . '/dev-host/fullchain.pem');
        symlink($this->env->root . '/live/privkey.pem', $this->env->tlsDir . '/dev-host/privkey.pem');

        $this->assertTrue($this->plan(['host' => 'shpd.example.com'])->hostBlock);
    }

    public function testBothEntriesAndHostProblemKeepsTheIpBlock(): void
    {
        $this->env->writeIpCertificate();
        $plan = $this->plan(['httpsIp' => true, 'host' => 'shpd.example.com']);

        $this->assertTrue($plan->ipBlock);
        $this->assertFalse($plan->hostBlock);
        $this->assertSame(1, substr_count((string) $plan->expectedSite, "\nserver {"));
    }

    public function testOutdatedSiteAndMissingLink(): void
    {
        $this->env->writeIpCertificate();
        $expected = (string) $this->plan(['httpsIp' => true])->expectedSite;

        $this->env->installSite($expected . "# edited by hand\n");
        $plan = $this->plan(['httpsIp' => true]);
        $this->assertTrue($plan->needsRun());
        $this->assertSame(['nginx site ' . DevHttpsSite::FILE_NAME . ' is outdated'], $plan->pendingActions());

        $this->env->installSite($expected);
        unlink($this->env->siteLink);
        $plan = $this->plan(['httpsIp' => true]);
        $this->assertTrue($plan->needsRun());
        $this->assertSame(['nginx site ' . DevHttpsSite::FILE_NAME . ' is not enabled'], $plan->pendingActions());
    }

    public function testIpv6ListenFollowsTheServer(): void
    {
        $this->env->writeIpCertificate();
        $access = DevHttpsEnvironment::access(['httpsIp' => true]);

        $this->assertStringContainsString('[::]:443', (string) $this->env->planner(ipv6: true)->plan($access, $this->now)->expectedSite);
        $this->assertStringNotContainsString('[::]', (string) $this->env->planner(ipv6: false)->plan($access, $this->now)->expectedSite);
    }

    public function testBlockers(): void
    {
        $access = DevHttpsEnvironment::access(['httpsIp' => true]);

        $plan = $this->env->planner(socket: null)->plan($access, $this->now);
        $this->assertStringContainsString('PHP-FPM pool "shipard" not found', $plan->blockers[0]);
        $this->assertFalse($plan->needsRun(), 'běh, který nejde provést, se jako potřebný nehlásí');

        $plan = $this->env->planner(templateFile: $this->env->root . '/missing.conf')->plan($access, $this->now);
        $this->assertStringContainsString('nginx template not found', $plan->blockers[0]);

        // Server bez dosažitelné adresy: certifikát pro IP není z čeho vyrobit.
        $plan = $this->env->planner()->plan(DevHttpsEnvironment::access(['httpsIp' => true], 'development', []), $this->now);
        $this->assertStringContainsString('no address of this server', $plan->blockers[0]);
        $this->assertFalse($plan->ipBlock);
    }

    public function testProductionPlansNothing(): void
    {
        $plan = $this->env->planner()->plan(
            DevHttpsEnvironment::access(['httpsIp' => true, 'host' => 'shpd.example.com'], 'production'),
            $this->now,
        );

        $this->assertFalse($plan->ipBlock);
        $this->assertFalse($plan->hostBlock);
        $this->assertNull($plan->expectedSite);
        $this->assertFalse($plan->needsRun());
    }
}

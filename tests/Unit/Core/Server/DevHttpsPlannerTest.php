<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Server;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Server\DevHttpsPlan;
use Shipard\Core\Server\DevHttpsSite;

/** Rozhodnutí `dev-https` bez vedlejších účinků (#183 U3–U6, U20, U21). */
class DevHttpsPlannerTest extends TestCase
{
    private const string HOST = 'shpd.example.com';

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

    public function testUnreadableHostCertificateKeepsTheInstalledBlockWithoutRoot(): void
    {
        // Site vznikl pod rootem; certifikát je symlink tam, kam uživatel nedosáhne.
        $this->env->writeHostCertificate(['shpd.example.com']);
        $access = DevHttpsEnvironment::access(['host' => 'shpd.example.com']);
        $this->env->installSite((string) $this->env->planner()->plan($access, $this->now)->expectedSite);
        unlink($this->env->tlsDir . '/dev-host/fullchain.pem');
        symlink($this->env->root . '/root-only/fullchain.pem', $this->env->tlsDir . '/dev-host/fullchain.pem');

        // Bez roota: nejde posoudit → blok platí tak, jak je na disku, běh není potřeba.
        $plan = $this->env->planner(privileged: false)->plan($access, $this->now);
        $this->assertTrue($plan->hostBlock);
        $this->assertTrue($plan->siteUpToDate());
        $this->assertFalse($plan->needsRun());
        $this->assertStringContainsString('not readable', (string) $plan->hostProblem);

        // Bez site souboru se blok nedomýšlí.
        unlink($this->env->siteLink);
        unlink($this->env->siteFile);
        $plan = $this->env->planner(privileged: false)->plan($access, $this->now);
        $this->assertFalse($plan->hostBlock);
        $this->assertFalse($plan->needsRun());
    }

    public function testUnreadableHostCertificateIsAProblemUnderRoot(): void
    {
        // Root dosáhne všude — nečitelný soubor je rozbitý odkaz.
        mkdir($this->env->tlsDir . '/dev-host', 0750, true);
        symlink($this->env->root . '/gone/fullchain.pem', $this->env->tlsDir . '/dev-host/fullchain.pem');

        $plan = $this->env->planner(privileged: true)->plan(DevHttpsEnvironment::access(['host' => 'shpd.example.com']), $this->now);

        $this->assertFalse($plan->hostBlock);
        $this->assertStringContainsString('not readable', (string) $plan->hostProblem);
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

    // ── IPv6 jen na vyžádání (#183 D8, U25–U27) ─────────────────────────────

    public function testSiteHasNoIpv6ListenWithoutTheKeyEvenWithIpv6InTheKernel(): void
    {
        $this->env->writeIpCertificate();
        $this->env->writeHostCertificate([self::HOST]);

        foreach ([['httpsIp' => true, 'host' => self::HOST], ['httpsIp' => true, 'host' => self::HOST, 'ipv6' => false]] as $section) {
            $plan = $this->env->planner(ipv6: true)->plan(DevHttpsEnvironment::access($section), $this->now);

            $this->assertStringContainsString('listen 443 ssl default_server;', (string) $plan->expectedSite);
            $this->assertStringContainsString('server_name ' . self::HOST . ';', (string) $plan->expectedSite);
            $this->assertStringNotContainsString('[::]', (string) $plan->expectedSite);
            $this->assertFalse($plan->ipv6Unavailable);
            $this->assertSame([], $plan->warnings);
        }
    }

    public function testSiteListensOnIpv6WithTheKeyAndIpv6InTheKernel(): void
    {
        $this->env->writeIpCertificate();
        $this->env->writeHostCertificate([self::HOST]);

        $plan = $this->env->planner(ipv6: true)
            ->plan(DevHttpsEnvironment::access(['httpsIp' => true, 'host' => self::HOST, 'ipv6' => true]), $this->now);

        $this->assertStringContainsString('listen [::]:443 ssl default_server;', (string) $plan->expectedSite);
        $this->assertStringContainsString("listen [::]:443 ssl;\n", (string) $plan->expectedSite);
        $this->assertFalse($plan->ipv6Unavailable);
        $this->assertSame([], $plan->warnings);
    }

    public function testIpv6KeyWithoutIpv6InTheKernelIsAWarningAndBlocksStayOnIpv4(): void
    {
        $this->env->writeIpCertificate();

        $plan = $this->env->planner(ipv6: false)->plan(DevHttpsEnvironment::access(['httpsIp' => true, 'ipv6' => true]), $this->now);

        $this->assertStringNotContainsString('[::]', (string) $plan->expectedSite);
        $this->assertTrue($plan->ipv6Unavailable);
        $this->assertCount(1, $plan->warnings);
        $this->assertStringContainsString('devAccess.ipv6 is on, but IPv6 is not enabled on this server', $plan->warnings[0]);
        $this->assertSame([], $plan->blockers);

        // Bez https vstupu klíč nic neřídí — bez varování.
        $plan = $this->env->planner(ipv6: false)->plan(DevHttpsEnvironment::access(['ipv6' => true]), $this->now);
        $this->assertFalse($plan->ipv6Unavailable);
        $this->assertSame([], $plan->warnings);
    }

    public function testIpCertificateCarriesIpv6OnlyOnRequest(): void
    {
        // U25: server má 10.0.0.7 a 2001:db8::7, certifikát jen IPv4.
        $this->env->writeIpCertificate(['10.0.0.7']);
        $this->assertNull($this->plan(['httpsIp' => true])->ipCertRenewal);
        $this->assertSame('does not cover 2001:db8::7', $this->plan(['httpsIp' => true, 'ipv6' => true])->ipCertRenewal);

        // Certifikát z fáze 1 s IPv6 navíc se po vypnutí neobnovuje (U20).
        $this->env->writeIpCertificate(['10.0.0.7', '2001:db8::7']);
        $this->assertNull($this->plan(['httpsIp' => true])->ipCertRenewal);
    }

    public function testSiteFromPhaseOneWithIpv6ListenIsOutdated(): void
    {
        // U26: po nasazení má server starý site s `[::]` — běh ho přegeneruje.
        $this->env->writeIpCertificate();
        $this->env->installSite($this->env->siteWithIpv6Listen());

        $plan = $this->env->planner(ipv6: true)->plan(DevHttpsEnvironment::access(['httpsIp' => true]), $this->now);

        $this->assertFalse($plan->siteUpToDate());
        $this->assertTrue($plan->needsRun());
        $this->assertSame(['nginx site ' . DevHttpsSite::FILE_NAME . ' is outdated'], $plan->pendingActions());
        $this->assertSame([DevHttpsSite::FILE_NAME], $plan->ipv6ListenSites);

        // Se zapnutým `ipv6` je tentýž soubor aktuální.
        $this->env->writeIpCertificate(DevHttpsEnvironment::ADDRESSES);
        $plan = $this->env->planner(ipv6: true)->plan(DevHttpsEnvironment::access(['httpsIp' => true, 'ipv6' => true]), $this->now);
        $this->assertTrue($plan->siteUpToDate());
        $this->assertFalse($plan->needsRun());
    }

    public function testIpv6ListenSitesAreReadFromEnabledShipardSites(): void
    {
        // Nic nainstalováno, cizí web s IPv6 se nepočítá.
        $this->env->writeForeignSite('other.conf', "server { listen [::]:8443 ssl; }\n");
        $this->assertSame([], $this->plan(null)->ipv6ListenSites);

        // Ručně upravený http site: `[::]` i konkrétní adresa.
        $this->env->writeForeignSite('shipard.conf', "server {\n    listen 80;\n    listen [::]:80;\n}\n");
        $this->assertSame(['shipard.conf'], $this->plan(null)->ipv6ListenSites);
        $this->env->writeForeignSite('shipard.conf', "server {\n    listen 80;\n    listen   [2001:db8::7]:80 default_server;\n}\n");
        $this->assertSame(['shipard.conf'], $this->plan(null)->ipv6ListenSites);

        // Zakomentovaný řádek ani http jen na IPv4 se nepočítá.
        $this->env->writeForeignSite('shipard.conf', "server {\n    listen 80;\n    # listen [::]:80;\n}\n");
        $this->assertSame([], $this->plan(null)->ipv6ListenSites);

        // Oba soubory.
        $this->env->writeForeignSite('shipard.conf', "server { listen [::]:80; }\n");
        $this->env->installSite($this->env->siteWithIpv6Listen());
        $this->assertSame(['shipard.conf', DevHttpsSite::FILE_NAME], $this->plan(null)->ipv6ListenSites);

        // Site bez `[::]` (to, co běh zapíše bez `ipv6`).
        @unlink($this->env->sitesEnabled . '/shipard.conf');
        $this->env->writeIpCertificate();
        $this->env->installSite((string) $this->plan(['httpsIp' => true])->expectedSite);
        $this->assertSame([], $this->plan(['httpsIp' => true])->ipv6ListenSites);
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

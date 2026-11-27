<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Server;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Net\LocalAddresses;
use Shipard\Core\Server\DevAccess;

/** Výklad sekce `devAccess` (#183 D3, U2). */
class DevAccessTest extends TestCase
{
    /** @param list<string> $interfaces */
    private function local(array $interfaces = ['127.0.0.1', '10.0.0.7', 'fe80::1%eth0', '2001:db8::7']): LocalAddresses
    {
        return new LocalAddresses(static fn (): array => [], static fn (): array => $interfaces);
    }

    private function access(mixed $section, string $mode = 'development', ?LocalAddresses $local = null): DevAccess
    {
        return DevAccess::fromSection($section, $mode, $local ?? $this->local());
    }

    public function testMissingSectionMeansHttpOnTheIpOnly(): void
    {
        $access = $this->access(null);

        $this->assertFalse($access->configured);
        $this->assertFalse($access->wantsHttpsIp());
        $this->assertNull($access->httpsHost());
        $this->assertFalse($access->hasHttpsEntry());
        $this->assertSame([DevAccess::HTTP_IP], $access->enabledKinds());
        $this->assertSame(DevAccess::HTTP_IP, $access->preferredKind());
        $this->assertSame('http://10.0.0.7', $access->preferredOrigin());
        $this->assertSame([], $access->problems);
    }

    public function testEmptySectionIsConfiguredWithDefaults(): void
    {
        $access = $this->access([]);

        $this->assertTrue($access->configured);
        $this->assertFalse($access->wantsHttpsIp());
        $this->assertSame([], $access->problems);
    }

    public function testAllEntries(): void
    {
        $access = $this->access(['httpsIp' => true, 'host' => 'Shpd.Example.com']);

        $this->assertSame([DevAccess::HTTP_IP, DevAccess::HTTPS_IP, DevAccess::HTTPS_HOST], $access->enabledKinds());
        $this->assertSame('http://10.0.0.7', $access->origin(DevAccess::HTTP_IP));
        $this->assertSame('https://10.0.0.7', $access->origin(DevAccess::HTTPS_IP));
        $this->assertSame('https://shpd.example.com', $access->origin(DevAccess::HTTPS_HOST));
        $this->assertSame('shpd.example.com', $access->httpsHost());
        $this->assertTrue($access->hasHttpsEntry());
        // Výchozí preferovaný vstup je nejvyšší zapnutý.
        $this->assertSame(DevAccess::HTTPS_HOST, $access->preferredKind());
        $this->assertSame('https://shpd.example.com', $access->preferredOrigin());
    }

    public function testDefaultPreferredFallsThroughEnabledEntries(): void
    {
        $this->assertSame(DevAccess::HTTPS_IP, $this->access(['httpsIp' => true])->preferredKind());
        $this->assertSame(DevAccess::HTTPS_HOST, $this->access(['host' => 'shpd.example.com'])->preferredKind());
        $this->assertSame(DevAccess::HTTP_IP, $this->access(['httpsIp' => false])->preferredKind());
    }

    public function testConfiguredPreferredWins(): void
    {
        $access = $this->access(['httpsIp' => true, 'host' => 'shpd.example.com', 'preferred' => 'https-ip']);

        $this->assertSame(DevAccess::HTTPS_IP, $access->preferredKind());
        $this->assertSame('https://10.0.0.7', $access->preferredOrigin());
        $this->assertSame([], $access->problems);
    }

    public function testPreferredThatIsNotEnabledIsAProblem(): void
    {
        $access = $this->access(['httpsIp' => true, 'preferred' => 'https-host']);

        $this->assertSame(DevAccess::HTTPS_IP, $access->preferredKind());
        $this->assertCount(1, $access->problems);
        $this->assertStringContainsString("'devAccess.preferred' is 'https-host'", $access->problems[0]);
    }

    public function testAddressesAreIpv4OnlyByDefault(): void
    {
        // #183 D8, U25: server má i globální IPv6, bez `ipv6` se nepoužije.
        $access = $this->access(['httpsIp' => true]);

        $this->assertFalse($access->ipv6);
        $this->assertFalse($access->wantsIpv6());
        $this->assertSame(['10.0.0.7'], $access->addresses());
        $this->assertSame('10.0.0.7', $access->linkAddress());
        $this->assertSame([], $access->problems);

        $this->assertFalse($this->access(['httpsIp' => true, 'ipv6' => false])->wantsIpv6());
    }

    public function testIpv6AddressesComeOnRequestIpv4First(): void
    {
        $access = $this->access(['httpsIp' => true, 'ipv6' => true]);

        $this->assertTrue($access->wantsIpv6());
        $this->assertSame(['10.0.0.7', '2001:db8::7'], $access->addresses());
        $this->assertSame('10.0.0.7', $access->linkAddress());
        $this->assertSame([], $access->problems);
    }

    public function testIpOverrideIsUsedForLinksAndKeptInAddresses(): void
    {
        // Přepis mezi adresami rozhraní: jen mění odkazy.
        $access = $this->access(['httpsIp' => true, 'ipv6' => true, 'ip' => '2001:DB8::7']);
        $this->assertSame('2001:db8::7', $access->linkAddress());
        $this->assertSame(['10.0.0.7', '2001:db8::7'], $access->addresses());
        $this->assertSame('https://[2001:db8::7]', $access->origin(DevAccess::HTTPS_IP));
        $this->assertSame('http://[2001:db8::7]', $access->origin(DevAccess::HTTP_IP));

        // Přepis mimo rozhraní (NAT): patří i do certifikátu.
        $access = $this->access(['httpsIp' => true, 'ip' => '203.0.113.9']);
        $this->assertSame('203.0.113.9', $access->linkAddress());
        $this->assertSame(['203.0.113.9', '10.0.0.7'], $access->addresses());

        $access = $this->access(['httpsIp' => true, 'ipv6' => true, 'ip' => '203.0.113.9']);
        $this->assertSame(['203.0.113.9', '10.0.0.7', '2001:db8::7'], $access->addresses());
    }

    public function testIpv6OverrideWithoutIpv6IsAProblemAndIsNotUsed(): void
    {
        // U25: bez `ipv6` Shipard na IPv6 neposlouchá — odkaz by nikam nevedl.
        $access = $this->access(['httpsIp' => true, 'ip' => '2001:db8::7']);

        $this->assertCount(1, $access->problems);
        $this->assertStringContainsString("'devAccess.ip' is an IPv6 address, but 'devAccess.ipv6' is not true", $access->problems[0]);
        $this->assertNull($access->ipOverride);
        $this->assertSame('10.0.0.7', $access->linkAddress());
        $this->assertSame(['10.0.0.7'], $access->addresses());
        $this->assertSame('https://10.0.0.7', $access->origin(DevAccess::HTTPS_IP));

        // Totéž s adresou mimo rozhraní a s `ipv6: false`.
        $access = $this->access(['ip' => '2001:db8::99', 'ipv6' => false]);
        $this->assertCount(1, $access->problems);
        $this->assertSame(['10.0.0.7'], $access->addresses());
    }

    public function testServerWithIpv6AddressesOnlyNeedsIpv6(): void
    {
        $local = $this->local(['127.0.0.1', 'fe80::1%eth0', '2001:db8::7']);

        $access = $this->access(['httpsIp' => true], 'development', $local);
        $this->assertSame([], $access->addresses());
        $this->assertNull($access->linkAddress());
        $this->assertSame([], $access->enabledKinds());

        $access = $this->access(['httpsIp' => true, 'ipv6' => true], 'development', $local);
        $this->assertSame(['2001:db8::7'], $access->addresses());
        $this->assertSame('https://[2001:db8::7]', $access->origin(DevAccess::HTTPS_IP));
    }

    public function testServerWithoutReachableAddressHasNoIpEntries(): void
    {
        $access = $this->access(['httpsIp' => true, 'host' => 'shpd.example.com'], 'development', $this->local(['127.0.0.1']));

        $this->assertNull($access->linkAddress());
        $this->assertSame([DevAccess::HTTPS_HOST], $access->enabledKinds());
        $this->assertNull($access->origin(DevAccess::HTTPS_IP));
        $this->assertTrue($access->wantsHttpsIp(), 'přání trvá — dev-https ohlásí chybějící adresu');
    }

    public function testProductionIgnoresTheSection(): void
    {
        $access = $this->access(['httpsIp' => true, 'host' => 'shpd.example.com'], 'production');

        $this->assertTrue($access->isIgnoredInProduction());
        $this->assertFalse($access->wantsHttpsIp());
        $this->assertFalse($this->access(['httpsIp' => true, 'ipv6' => true], 'production')->wantsIpv6());
        $this->assertNull($access->httpsHost());
        $this->assertFalse($access->hasHttpsEntry());
        $this->assertSame([], $access->enabledKinds());
        $this->assertSame([], $access->addresses());
        $this->assertNull($access->preferredKind());
        $this->assertNull($access->preferredOrigin());
        $this->assertSame([], $access->publicEntries('https://shpd.example.com'));

        $this->assertFalse($this->access(null, 'production')->isIgnoredInProduction());
        $this->assertFalse($this->access(['httpsIp' => true])->isIgnoredInProduction());
    }

    /** @return array<string, array{mixed, string}> */
    public static function invalidSections(): array
    {
        return [
            'sekce není objekt' => ['yes', "'devAccess' must be an object"],
            'sekce je seznam' => [['a', 'b'], "'devAccess' must be an object"],
            'httpsIp text' => [['httpsIp' => 'true'], "'devAccess.httpsIp'"],
            'ipv6 text' => [['ipv6' => 'true'], "'devAccess.ipv6' must be true or false"],
            'ipv6 číslo' => [['ipv6' => 1], "'devAccess.ipv6' must be true or false"],
            'host s mezerou' => [['host' => 'shpd example.com'], "'devAccess.host'"],
            'host se středníkem' => [['host' => 'shpd.example.com; include /etc/passwd'], "'devAccess.host'"],
            'host zástupný znak' => [['host' => '*.example.com'], "'devAccess.host'"],
            'host je IP' => [['host' => '10.0.0.7'], "'devAccess.host'"],
            'host číslo' => [['host' => 42], "'devAccess.host'"],
            'ip není adresa' => [['ip' => 'ten.zero.zero.seven'], "'devAccess.ip'"],
            'preferred neznámý' => [['preferred' => 'https'], "'devAccess.preferred'"],
        ];
    }

    #[DataProvider('invalidSections')]
    public function testInvalidValueIsNotUsedAndNeverThrows(mixed $section, string $expectedProblem): void
    {
        $access = $this->access($section);

        $this->assertCount(1, $access->problems);
        $this->assertStringContainsString($expectedProblem, $access->problems[0]);
        // Chybná hodnota = výchozí chování, žádný https vstup navíc.
        $this->assertFalse($access->wantsHttpsIp());
        $this->assertFalse($access->wantsIpv6());
        $this->assertSame(['10.0.0.7'], $access->addresses());
        $this->assertNull($access->httpsHost());
        $this->assertNull($access->ipOverride);
        $this->assertSame(DevAccess::HTTP_IP, $access->preferredKind());
    }

    public function testEmptyHostMeansNoHostEntry(): void
    {
        $access = $this->access(['host' => '']);

        $this->assertNull($access->httpsHost());
        $this->assertSame([], $access->problems);
    }

    public function testPublicEntriesAreAnAllowlistWithCurrentAndPreferred(): void
    {
        $access = $this->access(['httpsIp' => true, 'host' => 'shpd.example.com', 'ip' => '10.0.0.7', 'admin_password' => 'secret']);

        $this->assertSame([
            ['kind' => 'http-ip', 'origin' => 'http://10.0.0.7', 'preferred' => false, 'current' => false],
            ['kind' => 'https-ip', 'origin' => 'https://10.0.0.7', 'preferred' => false, 'current' => true],
            ['kind' => 'https-host', 'origin' => 'https://shpd.example.com', 'preferred' => true, 'current' => false],
        ], $access->publicEntries('HTTPS://10.0.0.7/'));

        $none = $access->publicEntries('http://localhost');
        $this->assertSame([false, false, false], array_column($none, 'current'));
        $this->assertSame([false, false, false], array_column($access->publicEntries(), 'current'));
        $this->assertStringNotContainsString('secret', json_encode($access->publicEntries()) ?: '');
    }

    public function testIsHostName(): void
    {
        foreach (['shpd.example.com', 'dev', 'a-b.c1', 'xn--hkyrky-ptac70bc.example'] as $ok) {
            $this->assertTrue(DevAccess::isHostName($ok), $ok);
        }
        foreach (['', '-a.example', 'a-.example', 'a..b', 'a_b.example', 'A.example', '10.0.0.7', '1.2.3', '::1', 'a b', "a\nb", str_repeat('a', 64) . '.example'] as $bad) {
            $this->assertFalse(DevAccess::isHostName($bad), $bad);
        }
    }
}

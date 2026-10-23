<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Dataset\E2e;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Dataset\E2e\E2eException;
use Shipard\Module\Core\Exchange\Dataset\E2e\LocalBaseUrl;

/**
 * Pravidlo lokální adresy běhu proklikání (tasks/testing-phase7a-fixes.md
 * § 1, #103 D97): IP literál loopback nebo vlastního rozhraní projde bez
 * pinu; jméno projde jen s výhradně lokálními adresami a dostane pin pro
 * curl i Chromium; cizí literál, jméno s jednou cizí adresou, nepřeložitelné
 * jméno a neplatná adresa neprojdou. Resolver i rozhraní jsou falešné.
 */
final class LocalBaseUrlTest extends TestCase
{
    /** @var array<string, list<string>> */
    private const DNS = [
        'dev.example' => ['127.0.1.1'],
        'both.example' => ['::1', '127.0.0.1'],
        'v6.example' => ['::1'],
        'iface.example' => ['10.0.0.7', '2001:db8::7'],
        'mixed.example' => ['127.0.0.1', '203.0.113.5'],
        'scoped.example' => ['fe80::1%eth0'],
    ];

    /** @var list<string> adresy rozhraní včetně rozsahu IPv6 (vrací ho net_get_interfaces) */
    private const LOCAL = ['127.0.0.1', '::1', '10.0.0.7', '2001:db8::7', 'fe80::1%eth0'];

    /** @param array<string, list<string>> $dns @param list<string> $local */
    private function rule(array $dns = self::DNS, array $local = self::LOCAL): LocalBaseUrl
    {
        return new LocalBaseUrl(
            static fn (string $host): array => $dns[$host] ?? [],
            static fn (): array => $local,
        );
    }

    public function testLoopbackLiteralPassesWithoutPin(): void
    {
        $pinned = $this->rule()->verify('http://127.0.0.1');
        $this->assertSame('http://127.0.0.1', $pinned->origin);
        $this->assertSame('127.0.0.1', $pinned->host);
        $this->assertSame(80, $pinned->port);
        $this->assertSame('127.0.0.1', $pinned->ip);
        $this->assertTrue($pinned->isLiteral);
        $this->assertNull($pinned->curlResolve(), 'literál není co připíchnout');
        $this->assertNull($pinned->chromiumRule());

        $this->assertSame('http://127.0.0.1', $this->rule()->verify(' http://127.0.0.1/ ')->origin, 'lomítko na konci a mezery se ořežou');
        $this->assertSame('127.5.6.7', $this->rule()->verify('http://127.5.6.7')->ip, 'celý 127.0.0.0/8');

        $v6 = $this->rule()->verify('https://[::1]:8443');
        $this->assertSame('https://[::1]:8443', $v6->origin);
        $this->assertSame('::1', $v6->host);
        $this->assertSame(8443, $v6->port);
        $this->assertSame('::1', $v6->ip);
        $this->assertTrue($v6->isLiteral);

        $this->assertSame(443, $this->rule()->verify('HTTPS://127.0.0.1')->port, 'výchozí port podle schématu');
        $this->assertSame('https://127.0.0.1', $this->rule()->verify('HTTPS://127.0.0.1')->origin, 'schéma malými písmeny');
    }

    public function testInterfaceLiteralPassesAndMappedOrScopedAddressesNormalize(): void
    {
        $this->assertSame('10.0.0.7', $this->rule()->verify('http://10.0.0.7')->ip);
        $this->assertSame('2001:db8::7', $this->rule()->verify('http://[2001:db8:0:0::7]')->ip, 'IPv6 zkrácená');
        $this->assertSame('fe80::1', $this->rule()->verify('http://[fe80::1]')->ip, 'adresa rozhraní bez %scope');
        $this->assertSame('127.0.0.1', $this->rule()->verify('http://[::ffff:127.0.0.1]')->ip, 'IPv4 mapovaná do IPv6 je loopback');

        $this->assertSame('fe80::1', LocalBaseUrl::normalizeIp('fe80::1%eth0'));
        $this->assertSame('127.0.0.1', LocalBaseUrl::normalizeIp('::ffff:127.0.0.1'));
        $this->assertSame('::1', LocalBaseUrl::normalizeIp('[0:0:0:0:0:0:0:1]'));
        $this->assertNull(LocalBaseUrl::normalizeIp('dev.example'));
        $this->assertNull(LocalBaseUrl::normalizeIp(''));
        $this->assertTrue(LocalBaseUrl::isLoopback('127.255.0.1'));
        $this->assertTrue(LocalBaseUrl::isLoopback('::1'));
        $this->assertFalse(LocalBaseUrl::isLoopback('10.0.0.7'));
    }

    public function testForeignLiteralIsRefused(): void
    {
        try {
            $this->rule()->verify('http://203.0.113.5');
            $this->fail('cizí adresa musí být odmítnuta');
        } catch (E2eException $e) {
            $this->assertStringContainsString('203.0.113.5', $e->getMessage());
            $this->assertStringContainsString('není loopback ani adresa rozhraní', $e->getMessage());
        }

        $this->expectException(E2eException::class);
        $this->rule()->verify('http://[2001:db8::99]');
    }

    public function testNameWithOnlyLocalAddressesIsPinned(): void
    {
        $pinned = $this->rule()->verify('http://dev.example');
        $this->assertSame('http://dev.example', $pinned->origin);
        $this->assertSame('dev.example', $pinned->host);
        $this->assertSame('127.0.1.1', $pinned->ip);
        $this->assertFalse($pinned->isLiteral);
        $this->assertSame('dev.example:80:127.0.1.1', $pinned->curlResolve());
        $this->assertSame('MAP dev.example 127.0.1.1', $pinned->chromiumRule());

        $port = $this->rule()->verify('https://Dev.Example:8443/');
        $this->assertSame('https://dev.example:8443', $port->origin, 'host malými písmeny jako v prohlížeči');
        $this->assertSame('dev.example:8443:127.0.1.1', $port->curlResolve());

        $this->assertSame('127.0.0.1', $this->rule()->verify('http://both.example')->ip, 'IPv4 má přednost před IPv6');

        $v6 = $this->rule()->verify('http://v6.example');
        $this->assertSame('::1', $v6->ip);
        $this->assertSame('v6.example:80:[::1]', $v6->curlResolve(), 'IPv6 v hranatých závorkách');
        $this->assertSame('MAP v6.example [::1]', $v6->chromiumRule());

        $this->assertSame('10.0.0.7', $this->rule()->verify('http://iface.example')->ip, 'adresy rozhraní jsou lokální');
        $this->assertSame('fe80::1', $this->rule()->verify('http://scoped.example')->ip, 'rozsah z resolveru i z rozhraní se ořeže');
    }

    public function testNameWithOneForeignAddressAmongLocalIsRefused(): void
    {
        try {
            $this->rule()->verify('http://mixed.example');
            $this->fail('jedna cizí adresa stačí k odmítnutí');
        } catch (E2eException $e) {
            $this->assertStringContainsString('203.0.113.5', $e->getMessage());
            $this->assertStringContainsString("host 'mixed.example'", $e->getMessage());
        }

        // Bez vlastních rozhraní projde jen loopback.
        $this->assertSame('127.0.1.1', $this->rule(local: [])->verify('http://dev.example')->ip);
        $this->expectException(E2eException::class);
        $this->rule(local: [])->verify('http://iface.example');
    }

    public function testUnresolvableNameIsRefused(): void
    {
        try {
            $this->rule()->verify('http://nowhere.invalid');
            $this->fail('nepřeložitelné jméno musí být odmítnuto');
        } catch (E2eException $e) {
            $this->assertStringContainsString("Jméno 'nowhere.invalid'", $e->getMessage());
            $this->assertStringContainsString('nejde přeložit', $e->getMessage());
        }

        // Resolver vrátil jen nesmysly → totéž.
        $this->expectException(E2eException::class);
        $this->rule(dns: ['junk.example' => ['', 'not-an-ip']])->verify('http://junk.example');
    }

    public function testMalformedBaseUrlIsRefused(): void
    {
        foreach (['', 'ftp://127.0.0.1', '127.0.0.1', 'http://', 'http://127.0.0.1/app', 'http://127.0.0.1?x=1', 'http://127.0.0.1#f', 'http://user@127.0.0.1', 'http://user:pw@127.0.0.1'] as $bad) {
            try {
                $this->rule()->verify($bad);
                $this->fail("'{$bad}' musí být odmítnuto");
            } catch (E2eException $e) {
                $this->assertStringContainsString('--base-url', $e->getMessage(), $bad);
            }
        }
    }

    public function testDefaultResolverAndInterfacesSeeThisMachine(): void
    {
        $rule = new LocalBaseUrl();
        $this->assertSame('127.0.0.1', $rule->verify('http://127.0.0.1')->ip);

        $localhost = $rule->verify('http://localhost');
        $this->assertTrue(LocalBaseUrl::isLoopback($localhost->ip), 'localhost ze systémového resolveru je loopback');
        $this->assertSame('MAP localhost ' . (str_contains($localhost->ip, ':') ? "[{$localhost->ip}]" : $localhost->ip), $localhost->chromiumRule());

        $this->assertContains('127.0.0.1', array_map(LocalBaseUrl::normalizeIp(...), LocalBaseUrl::interfaceAddresses()), 'lo je mezi rozhraními');
        $this->assertSame([], LocalBaseUrl::systemResolver('nowhere.invalid'));
    }
}

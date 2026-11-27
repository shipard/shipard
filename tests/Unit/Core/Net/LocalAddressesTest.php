<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Net;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Net\LocalAddresses;

/** Adresy serveru a pravidlo „adresa míří na tento server“ (#103 D97, #183 U15). */
class LocalAddressesTest extends TestCase
{
    /**
     * @param list<string> $interfaces
     * @param array<string, list<string>> $dns
     */
    private function local(array $interfaces, array $dns = []): LocalAddresses
    {
        return new LocalAddresses(
            static fn (string $host): array => $dns[$host] ?? [],
            static fn (): array => $interfaces,
        );
    }

    public function testAllNormalizesAndDeduplicates(): void
    {
        $local = $this->local(['127.0.0.1', '10.0.0.7', 'fe80::1%eth0', '::ffff:10.0.0.7', '2001:DB8:0:0:0:0:0:7', 'garbage']);

        $this->assertSame(['127.0.0.1', '10.0.0.7', 'fe80::1', '2001:db8::7'], $local->all());
    }

    public function testReachableSkipsLoopbackAndLinkLocalAndPutsIpv4First(): void
    {
        $local = $this->local([
            '127.0.0.1', '::1',
            'fe80::1%eth0', '169.254.10.20',
            '2001:db8::7', '10.0.0.7', 'fd00::5', '192.168.5.1',
        ]);

        $this->assertSame(['10.0.0.7', '192.168.5.1', '2001:db8::7', 'fd00::5'], $local->reachable());
    }

    public function testReachableIsEmptyWithLoopbackOnly(): void
    {
        $this->assertSame([], $this->local(['127.0.0.1', '::1'])->reachable());
    }

    public function testAddressesOfLiteralAndName(): void
    {
        $local = $this->local(['10.0.0.7'], ['dev.example' => ['10.0.0.7', '10.0.0.7', '2001:DB8::7'], 'empty.example' => []]);

        $this->assertSame(['10.0.0.7'], $local->addressesOf('10.0.0.7'));
        $this->assertSame(['2001:db8::7'], $local->addressesOf('[2001:db8::7]'));
        $this->assertSame(['10.0.0.7', '2001:db8::7'], $local->addressesOf('dev.example'));
        $this->assertSame([], $local->addressesOf('empty.example'));
        $this->assertSame([], $local->addressesOf('unknown.example'));
    }

    public function testForeignKeepsOnlyAddressesOutsideThisServer(): void
    {
        $local = $this->local(['127.0.0.1', '10.0.0.7', '2001:db8::7']);

        $this->assertSame([], $local->foreign(['10.0.0.7', '127.0.1.1', '::1', '2001:db8::7']));
        $this->assertSame(['203.0.113.9'], $local->foreign(['10.0.0.7', '203.0.113.9']));
        $this->assertSame([], $local->foreign([]));
    }

    public function testClassification(): void
    {
        $this->assertTrue(LocalAddresses::isLoopback('127.255.0.1'));
        $this->assertTrue(LocalAddresses::isLoopback('::1'));
        $this->assertFalse(LocalAddresses::isLoopback('10.0.0.7'));

        $this->assertTrue(LocalAddresses::isLinkLocal('169.254.0.1'));
        $this->assertTrue(LocalAddresses::isLinkLocal('fe80::1'));
        $this->assertTrue(LocalAddresses::isLinkLocal('febf::1'));
        $this->assertFalse(LocalAddresses::isLinkLocal('fec0::1'));
        $this->assertFalse(LocalAddresses::isLinkLocal('fd00::1'));
        $this->assertFalse(LocalAddresses::isLinkLocal('10.0.0.7'));

        $this->assertTrue(LocalAddresses::isIpv4('10.0.0.7'));
        $this->assertFalse(LocalAddresses::isIpv4('2001:db8::7'));
    }

    public function testNormalizeIp(): void
    {
        $this->assertSame('fe80::1', LocalAddresses::normalizeIp('fe80::1%eth0'));
        $this->assertSame('127.0.0.1', LocalAddresses::normalizeIp('::ffff:127.0.0.1'));
        $this->assertSame('::1', LocalAddresses::normalizeIp('[0:0:0:0:0:0:0:1]'));
        $this->assertSame('2001:db8::7', LocalAddresses::normalizeIp('2001:DB8:0:0:0:0:0:7'));
        $this->assertNull(LocalAddresses::normalizeIp('dev.example'));
        $this->assertNull(LocalAddresses::normalizeIp(''));
    }

    public function testOriginHostBracketsIpv6(): void
    {
        $this->assertSame('10.0.0.7', LocalAddresses::originHost('10.0.0.7'));
        $this->assertSame('[2001:db8::7]', LocalAddresses::originHost('2001:db8::7'));
    }

    public function testSystemSourcesWork(): void
    {
        $local = new LocalAddresses();

        $this->assertContains('127.0.0.1', $local->all(), 'lo je mezi rozhraními');
        $this->assertNotContains('127.0.0.1', $local->reachable());
        $this->assertSame([], $local->addressesOf('nowhere.invalid'));
        $this->assertSame([], $local->foreign($local->addressesOf('localhost')), 'localhost míří sem');
    }
}

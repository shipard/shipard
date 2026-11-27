<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Server;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Server\DevCertificate;

/** Čtení certifikátu https vstupu a předpis certifikátu pro IP (#183 U4, U5, U20). */
class DevCertificateTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/shpd-devcert-' . uniqid();
        mkdir($this->dir, 0750, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file) || is_link($file)) {
                @unlink($file);
            }
        }
        @rmdir($this->dir);
    }

    public function testReadsValidityAndSan(): void
    {
        $files = DevCertificateFixture::write($this->dir, ['10.0.0.7', '2001:db8::7'], ['Shpd.Example.com'], 397);
        $certificate = DevCertificate::read($files['cert']);
        $now = new \DateTimeImmutable('now');

        $this->assertTrue($certificate->isOk());
        $this->assertSame(DevCertificate::OK, $certificate->status);
        // openssl vypisuje IPv6 rozepsanou a velkými písmeny — čte se kanonicky.
        $this->assertSame(['10.0.0.7', '2001:db8::7'], $certificate->ips);
        $this->assertSame(['shpd.example.com'], $certificate->names);
        $this->assertContains($certificate->daysLeft($now), [396, 397]);
        $this->assertFalse($certificate->isExpired($now));
    }

    public function testDaysLeftAndExpiryFollowNow(): void
    {
        $certificate = DevCertificate::read(DevCertificateFixture::write($this->dir, ['10.0.0.7'], [], 100)['cert']);
        $issued = new \DateTimeImmutable('now');

        $this->assertContains($certificate->daysLeft($issued->modify('+90 days')), [9, 10]);
        $this->assertFalse($certificate->isExpired($issued->modify('+90 days')));
        $this->assertLessThan(0, $certificate->daysLeft($issued->modify('+101 days')));
        $this->assertTrue($certificate->isExpired($issued->modify('+101 days')));
    }

    public function testMissingIpsIgnoresExtraAddressesInTheCertificate(): void
    {
        // U20: obnova jen když adresa chybí; adresa navíc nevadí.
        $certificate = DevCertificate::read(DevCertificateFixture::write($this->dir, ['10.0.0.7', '2001:db8::7'])['cert']);

        $this->assertSame([], $certificate->missingIps(['10.0.0.7']));
        $this->assertSame([], $certificate->missingIps(['2001:db8::7', '10.0.0.7']));
        $this->assertSame(['192.168.5.1'], $certificate->missingIps(['10.0.0.7', '192.168.5.1']));
        $this->assertSame([], $certificate->missingIps([]));
    }

    public function testCoversNameExactlyOrByOneLabelWildcard(): void
    {
        $certificate = DevCertificate::read(DevCertificateFixture::write($this->dir, [], ['shpd.example.com', '*.dev.example.com'])['cert']);

        $this->assertTrue($certificate->coversName('shpd.example.com'));
        $this->assertTrue($certificate->coversName('SHPD.example.com'));
        $this->assertTrue($certificate->coversName('box.dev.example.com'));
        $this->assertFalse($certificate->coversName('a.box.dev.example.com'), 'zástupný znak kryje jeden štítek');
        $this->assertFalse($certificate->coversName('dev.example.com'));
        $this->assertFalse($certificate->coversName('other.example.com'));
    }

    public function testMatchesKeyFile(): void
    {
        $files = DevCertificateFixture::write($this->dir, ['10.0.0.7']);
        $certificate = DevCertificate::read($files['cert']);

        $this->assertTrue($certificate->matchesKeyFile($files['key']));

        [, $otherKey] = DevCertificateFixture::pem(['10.0.0.7']);
        file_put_contents($this->dir . '/other.pem', $otherKey);
        $this->assertFalse($certificate->matchesKeyFile($this->dir . '/other.pem'));

        $this->assertNull($certificate->matchesKeyFile($this->dir . '/missing.pem'), 'nečitelný klíč = nejde rozhodnout');
    }

    public function testMissingUnreadableAndInvalidFiles(): void
    {
        $this->assertSame(DevCertificate::MISSING, DevCertificate::read($this->dir . '/fullchain.pem')->status);

        file_put_contents($this->dir . '/garbage.pem', "not a certificate\n");
        $invalid = DevCertificate::read($this->dir . '/garbage.pem');
        $this->assertSame(DevCertificate::INVALID, $invalid->status);
        $this->assertFalse($invalid->isOk());
        $this->assertNull($invalid->daysLeft(new \DateTimeImmutable('now')));
        $this->assertNull($invalid->matchesKeyFile($this->dir . '/garbage.pem'));

        // Symlink na cíl, na který proces nedosáhne (certbot: adresář jen pro root).
        symlink($this->dir . '/nowhere/fullchain.pem', $this->dir . '/link.pem');
        $this->assertSame(DevCertificate::UNREADABLE, DevCertificate::read($this->dir . '/link.pem')->status);
    }

    public function testReadFollowsSymlink(): void
    {
        $files = DevCertificateFixture::write($this->dir, ['10.0.0.7']);
        symlink($files['cert'], $this->dir . '/link.pem');

        $this->assertSame(['10.0.0.7'], DevCertificate::read($this->dir . '/link.pem')->ips);
    }

    public function testOpensslConfigDescribesTheIpCertificate(): void
    {
        $config = DevCertificate::opensslConfig('dev box;$(x)', ['10.0.0.7', '2001:db8::7']);

        $this->assertStringContainsString("CN = Shipard dev dev-box---x-\n", $config);
        $this->assertStringContainsString("basicConstraints = critical, CA:FALSE\n", $config);
        $this->assertStringContainsString("extendedKeyUsage = serverAuth\n", $config);
        $this->assertStringContainsString("subjectAltName = @san\n", $config);
        $this->assertStringContainsString("IP.1 = 10.0.0.7\nIP.2 = 2001:db8::7\n", $config);
        $this->assertStringContainsString("prompt = no\n", $config);

        // CN nejvýš 64 znaků (mez X.509).
        preg_match('/^CN = (.*)$/m', DevCertificate::opensslConfig(str_repeat('h', 200), ['10.0.0.7']), $m);
        $this->assertSame(64, strlen($m[1]));
        $this->assertStringContainsString('CN = Shipard dev server', DevCertificate::opensslConfig('', ['10.0.0.7']));
    }

    public function testOpensslConfigProducesTheCertificateItDescribes(): void
    {
        // Předpis projde skutečným OpenSSL (ext-openssl čte stejný formát jako CLI).
        $configFile = $this->dir . '/req.cnf';
        file_put_contents($configFile, DevCertificate::opensslConfig('dev-box', ['10.0.0.7', '2001:db8::7']));
        $options = ['config' => $configFile, 'digest_alg' => 'sha256', 'x509_extensions' => 'ext'];
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'config' => $configFile]);
        $csr = openssl_csr_new(['commonName' => 'Shipard dev dev-box'], $key, $options);
        openssl_x509_export(openssl_csr_sign($csr, null, $key, DevCertificate::VALIDITY_DAYS, $options), $pem);

        $certificate = DevCertificate::fromPem((string) $pem);
        $this->assertSame(['10.0.0.7', '2001:db8::7'], $certificate->ips);

        $parsed = openssl_x509_parse((string) $pem);
        $this->assertSame('CA:FALSE', $parsed['extensions']['basicConstraints']);
        $this->assertSame('TLS Web Server Authentication', $parsed['extensions']['extendedKeyUsage']);
    }

    public function testOpensslArgs(): void
    {
        $this->assertSame([
            'openssl', 'req', '-x509',
            '-newkey', 'ec', '-pkeyopt', 'ec_paramgen_curve:prime256v1',
            '-nodes', '-sha256',
            '-days', '397',
            '-config', '/tmp/req.cnf',
            '-keyout', '/tmp/key.pem',
            '-out', '/tmp/cert.pem',
        ], DevCertificate::opensslArgs('/tmp/req.cnf', '/tmp/key.pem', '/tmp/cert.pem'));
    }
}

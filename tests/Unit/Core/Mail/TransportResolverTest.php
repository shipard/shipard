<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Mail;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Mail\Exception\MailTransportConfigException;
use Shipard\Core\Mail\MailRelayConfig;
use Shipard\Core\Mail\TransportResolver;
use Shipard\Core\Security\DsSecretCipher;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;

class TransportResolverTest extends TestCase
{
    private string $tmpDir;
    private DataSourceConfig $config;
    private DsSecretCipher $cipher;

    protected function setUp(): void
    {
        DsSecretCipher::resetCache();
        $this->tmpDir = sys_get_temp_dir() . '/shpd-transport-' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir . '/config', 0700, true);
        file_put_contents($this->tmpDir . '/config/main.json', json_encode([
            'id'            => 'test-test-test-test',
            'name'          => 'Transport test',
            'database_name' => 'test_db',
            'database_user' => 'test',
            'created'       => date('c'),
        ]));
        DsSecretCipher::generateKey($this->tmpDir);
        $this->config = new DataSourceConfig($this->tmpDir);
        $this->cipher = DsSecretCipher::forConfig($this->config);
    }

    protected function tearDown(): void
    {
        DsSecretCipher::resetCache();
        exec('rm -rf ' . escapeshellarg($this->tmpDir));
    }

    /** @param ?array $senderRow řádek, který mock DB vrátí pro lookup */
    private function makeDb(?array $senderRow, ?string &$capturedSql = null): DataSourceConnection
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(
            function (string $sql, ...$args) use ($senderRow, &$capturedSql) {
                $capturedSql = $sql;
                return $senderRow;
            },
        );
        return $db;
    }

    private function captureFactory(array &$captured): \Closure
    {
        $transport = $this->createMock(TransportInterface::class);
        return function (...$args) use (&$captured, $transport) {
            $captured = $args;
            return $transport;
        };
    }

    public function testSenderHitDecryptsPassword(): void
    {
        $db = $this->makeDb([
            'id'            => 5,
            'email_from'    => 'invoices@firma.cz',
            'smtp_host'     => 'smtp.gmail.com',
            'smtp_port'     => 587,
            'smtp_security' => 'starttls',
            'smtp_username' => 'invoices@firma.cz',
            'password_enc'  => $this->cipher->encrypt('app-password'),
            'is_active'     => 1,
        ], $sql);

        $captured = [];
        $resolver = new TransportResolver($db, $this->config, null, null, $this->captureFactory($captured));

        $resolved = $resolver->resolve('Invoices@Firma.cz');

        $this->assertSame('sender:5', $resolved->label);
        $this->assertSame(['smtp.gmail.com', 587, 'starttls', 'invoices@firma.cz', 'app-password'], $captured);
        // case-insensitivita je v SQL — nesmí zmizet
        $this->assertStringContainsString('LOWER(email_from)', $sql);
        $this->assertStringContainsString('is_active = 1', $sql);
    }

    public function testSenderWithoutPasswordPassesNull(): void
    {
        $db = $this->makeDb([
            'id'            => 2,
            'email_from'    => 'noreply@firma.cz',
            'smtp_host'     => 'localhost',
            'smtp_port'     => 25,
            'smtp_security' => 'none',
            'smtp_username' => null,
            'password_enc'  => null,
            'is_active'     => 1,
        ]);

        $captured = [];
        $resolver = new TransportResolver($db, $this->config, null, null, $this->captureFactory($captured));

        $resolved = $resolver->resolve('noreply@firma.cz');

        $this->assertSame('sender:2', $resolved->label);
        $this->assertSame(['localhost', 25, 'none', null, null], $captured);
    }

    public function testMissFallsBackToRelay(): void
    {
        $relay = new MailRelayConfig('relay.example.com', 587, 'starttls', 'shipard', 'relay-pw');
        $captured = [];
        $resolver = new TransportResolver(
            $this->makeDb(null),
            $this->config,
            $relay,
            null,
            $this->captureFactory($captured),
        );

        $resolved = $resolver->resolve('kdokoliv@firma.cz');

        $this->assertSame('relay.example.com:587', $resolved->label);
        $this->assertSame(['relay.example.com', 587, 'starttls', 'shipard', 'relay-pw'], $captured);
    }

    public function testMissWithoutRelayThrows(): void
    {
        $resolver = new TransportResolver($this->makeDb(null), $this->config, null);

        $this->expectException(MailTransportConfigException::class);
        $this->expectExceptionMessageMatches('/no mail relay is configured/');

        $resolver->resolve('kdokoliv@firma.cz');
    }

    public function testDefaultFactoryBuildsEsmtpTransport(): void
    {
        $relay = new MailRelayConfig('relay.example.com', 587, 'starttls', 'shipard', 'relay-pw');
        $resolver = new TransportResolver($this->makeDb(null), $this->config, $relay);

        $resolved = $resolver->resolve('kdokoliv@firma.cz');

        $this->assertInstanceOf(EsmtpTransport::class, $resolved->transport);
        $this->assertSame('shipard', $resolved->transport->getUsername());
        $this->assertSame('relay-pw', $resolved->transport->getPassword());
    }
}

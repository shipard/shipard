<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Controller\MailController;
use Shipard\Api\Request;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Security\DsSecretCipher;

/**
 * `POST /_mail/senders/{id}/password` — jediná cesta k nastavení SMTP
 * hesla senderu (sloupec password_enc je sensitive). Admin session only.
 */
class MailControllerSenderPasswordTest extends TestCase
{
    private string $tmpDir;
    private DataSourceConfig $config;

    protected function setUp(): void
    {
        DsSecretCipher::resetCache();
        $this->tmpDir = sys_get_temp_dir() . '/shpd-sender-pw-' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir . '/config', 0700, true);
        file_put_contents($this->tmpDir . '/config/main.json', json_encode([
            'id'            => 'test-test-test-test',
            'name'          => 'Sender pw test',
            'database_name' => 'test_db',
            'database_user' => 'test',
            'created'       => date('c'),
        ]));
        DsSecretCipher::generateKey($this->tmpDir);
        $this->config = new DataSourceConfig($this->tmpDir);
    }

    protected function tearDown(): void
    {
        DsSecretCipher::resetCache();
        exec('rm -rf ' . escapeshellarg($this->tmpDir));
    }

    private function controller(DataSourceConnection $db): MailController
    {
        return new MailController($db, $this->tmpDir, [], new DocumentRegistry(), null, $this->config);
    }

    private function request(string $password = 's3cret'): Request
    {
        return Request::fromArray(
            'POST',
            '/_mail/senders/3/password',
            [],
            json_encode(['password' => $password]),
            ['CONTENT_TYPE' => 'application/json'],
        );
    }

    private function statusOf(\Shipard\Api\Response $response): int
    {
        $ref = new \ReflectionClass($response);
        $prop = $ref->getProperty('status');
        return (int) $prop->getValue($response);
    }

    public function testNonAdminIsRejected(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->expects($this->never())->method('updateWhere');

        $auth = new AuthContext(true, 1, 'session', 'shpd_st_x', isAdmin: false);
        $response = $this->controller($db)->setSenderPassword($auth, $this->request(), 3);

        $this->assertSame(403, $this->statusOf($response));
    }

    public function testApiKeyIsRejected(): void
    {
        // API klíče nikdy nemají isAdmin — belt and braces test kontraktu
        $db = $this->createMock(DataSourceConnection::class);

        $auth = new AuthContext(true, 1, 'api_key', 'shpd_ak_x', isAdmin: false);
        $response = $this->controller($db)->setSenderPassword($auth, $this->request(), 3);

        $this->assertSame(403, $this->statusOf($response));
    }

    public function testEmptyPasswordIsRejected(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->expects($this->never())->method('updateWhere');

        $auth = new AuthContext(true, 1, 'session', 'shpd_st_x', isAdmin: true);
        $response = $this->controller($db)->setSenderPassword($auth, $this->request(''), 3);

        $this->assertSame(400, $this->statusOf($response));
    }

    public function testUnknownSenderIs404(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null);

        $auth = new AuthContext(true, 1, 'session', 'shpd_st_x', isAdmin: true);
        $response = $this->controller($db)->setSenderPassword($auth, $this->request(), 99);

        $this->assertSame(404, $this->statusOf($response));
    }

    public function testPasswordIsEncryptedAndStored(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(['id' => 3]);

        $captured = null;
        $db->expects($this->once())->method('updateWhere')->willReturnCallback(
            function (string $table, array $data, string $where, ...$args) use (&$captured) {
                $captured = [$table, $data, $where, $args];
            },
        );

        $auth = new AuthContext(true, 1, 'session', 'shpd_st_x', isAdmin: true);
        $response = $this->controller($db)->setSenderPassword($auth, $this->request('super-tajne'), 3);

        $this->assertSame(200, $this->statusOf($response));

        [$table, $data, $where, $args] = $captured;
        $this->assertSame('core_mail_senders', $table);
        $this->assertSame([3], $args);
        // v DB nesmí být plaintext — musí to být dešifrovatelný ciphertext
        $this->assertStringStartsWith('v1:', $data['password_enc']);
        $this->assertSame(
            'super-tajne',
            DsSecretCipher::forConfig($this->config)->decrypt($data['password_enc']),
        );
    }
}

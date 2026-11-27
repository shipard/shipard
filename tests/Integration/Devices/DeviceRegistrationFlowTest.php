<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Devices;

use Shipard\Api\AuthContext;
use Shipard\Api\Controller\DevicesController;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Devices\ChallengeService;
use Shipard\Module\Core\Devices\DeviceDocument;
use Shipard\Module\Core\Devices\DeviceKey;
use Shipard\Module\Core\Devices\DevicePurpose;
use Shipard\Module\Core\Devices\DeviceRegistrationService;
use Shipard\Module\Core\Devices\PeripheralDocument;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Registrace zařízení a podepsaná volání nad skutečnou databází (#177
 * task 3): registrace → self → hlášení periferií → druhé hlášení bez
 * tiskárny → zneplatnění → `DEVICE_REVOKED`; odmítnutí staré, cizí a použité
 * výzvy; souběžná spotřeba výzvy dvěma spojeními.
 *
 * Test si zakládá zařízení s náhodným `deviceId` a po sobě uklízí — bez
 * obalující transakce, protože souběh potřebuje řádky vidět i z druhého
 * spojení.
 */
class DeviceRegistrationFlowTest extends IntegrationTestCase
{
    private const int ADMIN = 1;

    private string $deviceId;
    private \OpenSSLAsymmetricKey $private;
    private string $publicKey;
    private ?DataSourceConnection $second = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (!isset($this->tables[DeviceDocument::TABLE])) {
            $this->markTestSkipped('Zdroj dat nemá modul core.devices — spusť ds-upgrade.');
        }

        $this->deviceId = self::uuid();
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $this->assertNotFalse($key);
        $this->private = $key;
        $this->publicKey = (string) preg_replace('/-----[A-Z ]+-----|\s+/', '', (string) openssl_pkey_get_details($key)['key']);
    }

    protected function onTearDown(): void
    {
        $this->second?->disconnect();
        if (!isset($this->deviceId)) {
            return;
        }
        $row = $this->db->fetchRow('SELECT id FROM core_devices_devices WHERE device_id = %s', $this->deviceId);
        if ($row !== null) {
            $this->db->deleteWhere(PeripheralDocument::TABLE, 'device = %i', (int) $row['id']);
            $this->db->deleteWhere(DeviceDocument::TABLE, 'id = %i', (int) $row['id']);
        }
        $this->db->deleteWhere(ChallengeService::TABLE, 'device_id = %s', $this->deviceId);
    }

    private static function uuid(): string
    {
        $hex = bin2hex(random_bytes(16));
        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }

    private function controller(): DevicesController
    {
        return new DevicesController($this->dsConfig);
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string> $headers
     */
    private function request(string $method, string $path, ?array $body = null, array $headers = []): Request
    {
        $server = ['HTTP_HOST' => 'firma.example.com'];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }
        return Request::fromArray($method, '/api/v1/_devices/' . $path, [], $body === null ? '' : (string) json_encode($body), $server);
    }

    private static function admin(): AuthContext
    {
        return new AuthContext(true, self::ADMIN, 'session', 'shpd_st_test', true);
    }

    private function sign(string $purpose, string $challenge): string
    {
        openssl_sign(DeviceKey::signingString($purpose, $this->deviceId, $challenge), $signature, $this->private, OPENSSL_ALGO_SHA256);
        return base64_encode((string) $signature);
    }

    private function issue(DevicePurpose $purpose, ?AuthContext $auth = null): Response
    {
        return $this->controller()->challenge(
            $this->request('POST', 'challenge', ['deviceId' => $this->deviceId, 'purpose' => $purpose->value]),
            $auth ?? AuthContext::anonymous(),
            $this->db,
            $this->tables,
        );
    }

    /** @return array<string, string> hlavičky podepsaného volání s čerstvou výzvou */
    private function signedHeaders(DevicePurpose $purpose): array
    {
        $response = $this->issue($purpose);
        $this->assertSame(200, $response->getStatus(), json_encode($response->getPayload()));
        $challenge = $response->getPayload()['data']['challenge'];
        return [
            'X-Shipard-Device'    => $this->deviceId,
            'X-Shipard-Challenge' => $challenge,
            'X-Shipard-Signature' => $this->sign($purpose->value, $challenge),
        ];
    }

    private function register(string $mode = 'shared'): Response
    {
        $challenge = $this->issue(DevicePurpose::Register, self::admin())->getPayload()['data']['challenge'];
        return $this->controller()->register(
            $this->request('POST', 'register', [
                'deviceId'   => $this->deviceId,
                'algorithm'  => 'ES256',
                'publicKey'  => $this->publicKey,
                'challenge'  => $challenge,
                'signature'  => $this->sign('register', $challenge),
                'name'       => 'Integrační pokladna',
                'mode'       => $mode,
                'platform'   => 'mock',
                'appVersion' => '1.0.0',
                'keyStorage' => 'mock',
            ]),
            self::admin(),
            $this->db,
            $this->tables,
        );
    }

    private function self(): Response
    {
        return $this->controller()->selfState(
            $this->request('GET', 'self', headers: $this->signedHeaders(DevicePurpose::Self)),
            $this->db,
            $this->tables,
        );
    }

    /** @param list<array<string, mixed>> $peripherals */
    private function report(array $peripherals): Response
    {
        return $this->controller()->peripherals(
            $this->request('PUT', 'self/peripherals', ['peripherals' => $peripherals], $this->signedHeaders(DevicePurpose::Peripherals)),
            $this->db,
            $this->tables,
        );
    }

    /** @return array<string, mixed> */
    private function deviceRow(): array
    {
        $row = $this->db->fetchRow('SELECT * FROM core_devices_devices WHERE device_id = %s', $this->deviceId);
        $this->assertNotNull($row);
        return $row;
    }

    /** @return array<string, array<string, mixed>> `peripheral_id` → řádek */
    private function peripherals(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM core_devices_peripherals WHERE device = %i ORDER BY peripheral_id',
            (int) $this->deviceRow()['id'],
        );
        return array_column($rows, null, 'peripheral_id');
    }

    private function assertError(string $code, int $status, Response $response): void
    {
        $this->assertSame($status, $response->getStatus(), json_encode($response->getPayload()));
        $this->assertSame($code, $response->getPayload()['error']['code']);
    }

    public function testRegistrationSelfPeripheralsAndRevocation(): void
    {
        $printer = ['id' => 'mock-receipt', 'kind' => 'printer', 'name' => 'Mock receipt printer', 'transport' => 'tcp', 'status' => 'ready', 'profileId' => 'escpos-80-cp852'];
        $scanner = ['id' => 'mock-scanner', 'kind' => 'scanner', 'name' => 'Mock scanner', 'transport' => 'usb', 'status' => 'ready', 'reads' => ['barcode', 'rfid']];

        // Neregistrované zařízení výzvu k podepsanému volání nedostane.
        $this->assertError('DEVICE_UNKNOWN', 401, $this->issue(DevicePurpose::Self));

        // Registrace.
        $registered = $this->register();
        $this->assertSame(201, $registered->getStatus(), json_encode($registered->getPayload()));
        $this->assertSame(
            ['deviceId' => $this->deviceId, 'name' => 'Integrační pokladna', 'mode' => 'shared', 'state' => 'active'],
            $registered->getPayload()['data'],
        );
        $row = $this->deviceRow();
        $this->assertSame(DeviceDocument::STATE_ACTIVE, (int) $row['docState']);
        $this->assertSame(self::ADMIN, (int) $row['registered_by']);
        $this->assertSame('mock', $row['key_storage']);
        $this->assertNull($row['last_seen_at']);
        $this->assertError('DEVICE_EXISTS', 409, $this->register());

        // Self.
        $self = $this->self();
        $this->assertSame(200, $self->getStatus(), json_encode($self->getPayload()));
        $data = $self->getPayload()['data'];
        $this->assertSame('shared', $data['mode']);
        $this->assertSame('Integrační pokladna', $data['name']);
        $this->assertSame('active', $data['state']);
        $this->assertSame([], $data['users']);
        $this->assertSame(['enabled', 'minLength', 'maxLength', 'maxAttempts'], array_keys($data['pinPolicy']));
        $this->assertNull($data['autoLockMinutes']);
        $this->assertNotNull($this->deviceRow()['last_seen_at']);

        // Hlášení periferií.
        $first = $this->report([$printer, $scanner]);
        $this->assertSame(200, $first->getStatus(), json_encode($first->getPayload()));
        $this->assertSame(['count' => 2], $first->getPayload()['data']);
        $stored = $this->peripherals();
        $this->assertSame(['mock-receipt', 'mock-scanner'], array_keys($stored));
        $this->assertSame(1, (int) $stored['mock-receipt']['present']);
        $this->assertSame(['barcode', 'rfid'], json_decode((string) $stored['mock-scanner']['reads'], true));

        // Administrátor přiřadí tiskárně roli.
        $this->db->updateWhere(PeripheralDocument::TABLE, ['role' => 'receipt'], 'id = %i', (int) $stored['mock-receipt']['id']);

        // Druhé hlášení bez tiskárny: zůstává jako nepřítomná, role jí zůstane.
        $this->assertSame(['count' => 1], $this->report([$scanner])->getPayload()['data']);
        $stored = $this->peripherals();
        $this->assertCount(2, $stored);
        $this->assertSame(0, (int) $stored['mock-receipt']['present']);
        $this->assertSame('receipt', $stored['mock-receipt']['role']);
        $this->assertSame(1, (int) $stored['mock-scanner']['present']);

        // Zneplatnění je konečné.
        $pending = $this->signedHeaders(DevicePurpose::Self);
        $service = new DeviceRegistrationService($this->db);
        $this->assertTrue($service->revoke((int) $row['id'], null));
        $this->assertFalse($service->revoke((int) $row['id'], null));

        $revoked = $this->deviceRow();
        $this->assertSame(DeviceDocument::STATE_REVOKED, (int) $revoked['docState']);
        $this->assertSame(DeviceDocument::STATE_MAIN_REVOKED, (int) $revoked['docStateMain']);
        $this->assertNotNull($revoked['revoked_at']);
        $this->assertNull($revoked['revoked_by']);

        $this->assertError('DEVICE_REVOKED', 403, $this->issue(DevicePurpose::Self));
        $this->assertError('DEVICE_REVOKED', 403, $this->controller()->selfState(
            $this->request('GET', 'self', headers: $pending),
            $this->db,
            $this->tables,
        ));
        // Stejné deviceId už zaregistrovat nejde.
        $this->assertError('DEVICE_REVOKED', 403, $this->register());
    }

    public function testOldForeignAndUsedChallengesAreRejected(): void
    {
        $this->assertSame(201, $this->register('personal')->getStatus());
        $ctrl = $this->controller();

        // Použitá výzva.
        $headers = $this->signedHeaders(DevicePurpose::Self);
        $this->assertSame(200, $ctrl->selfState($this->request('GET', 'self', headers: $headers), $this->db, $this->tables)->getStatus());
        $this->assertError('CHALLENGE_INVALID', 401, $ctrl->selfState($this->request('GET', 'self', headers: $headers), $this->db, $this->tables));

        // Stará výzva — vypršela před minutou.
        $headers = $this->signedHeaders(DevicePurpose::Self);
        $this->db->execute(
            'UPDATE core_devices_challenges SET expires_at = %dt WHERE challenge = %s',
            new \DateTimeImmutable('-1 second'),
            $headers['X-Shipard-Challenge'],
        );
        $this->assertError('CHALLENGE_INVALID', 401, $ctrl->selfState($this->request('GET', 'self', headers: $headers), $this->db, $this->tables));

        // Výzva jiného účelu.
        $headers = $this->signedHeaders(DevicePurpose::Peripherals);
        $this->assertError('CHALLENGE_INVALID', 401, $ctrl->selfState($this->request('GET', 'self', headers: $headers), $this->db, $this->tables));

        // Cizí výzva: jiné zařízení, tatáž hodnota jen s jinou velikostí písmen neprojde také.
        $own = $this->signedHeaders(DevicePurpose::Self);
        $swapped = strtolower($own['X-Shipard-Challenge']) !== $own['X-Shipard-Challenge']
            ? strtolower($own['X-Shipard-Challenge'])
            : strtoupper($own['X-Shipard-Challenge']);
        $this->assertError('CHALLENGE_INVALID', 401, $ctrl->selfState(
            $this->request('GET', 'self', headers: [
                'X-Shipard-Challenge' => $swapped,
                'X-Shipard-Signature' => $this->sign('self', $swapped),
            ] + $own),
            $this->db,
            $this->tables,
        ));
        // ID zařízení se porovnává přesně — velká písmena jsou jiné zařízení.
        $this->assertError('DEVICE_UNKNOWN', 401, $ctrl->selfState(
            $this->request('GET', 'self', headers: ['X-Shipard-Device' => strtoupper($this->deviceId)] + $own),
            $this->db,
            $this->tables,
        ));
        // Původní výzva zůstala nedotčená a projde.
        $this->assertSame(200, $ctrl->selfState($this->request('GET', 'self', headers: $own), $this->db, $this->tables)->getStatus());
    }

    public function testOfTwoConcurrentConsumersExactlyOneWins(): void
    {
        $this->second = new DataSourceConnection($this->dsConfig);
        $first = new ChallengeService($this->db);
        $second = new ChallengeService($this->second);

        for ($round = 0; $round < 5; $round++) {
            $challenge = $first->issue($this->deviceId, DevicePurpose::Self);

            // První spojení výzvu spotřebuje v otevřené transakci; druhé na
            // zámku řádku počká a po commitu už výzvu jako použitou neuvidí.
            $this->db->begin();
            $wonFirst = $first->consume($challenge, $this->deviceId, DevicePurpose::Self);
            $this->second->execute('SET SESSION innodb_lock_wait_timeout = 1');
            $blocked = false;
            try {
                $second->consume($challenge, $this->deviceId, DevicePurpose::Self);
            } catch (\Throwable) {
                $blocked = true;
            }
            $this->db->commit();
            $wonSecond = $second->consume($challenge, $this->deviceId, DevicePurpose::Self);

            $this->assertTrue($wonFirst, "kolo {$round}");
            $this->assertTrue($blocked, "kolo {$round}: druhé spojení musí čekat na zámek řádku");
            $this->assertFalse($wonSecond, "kolo {$round}: použitou výzvu nesmí spotřebovat druhé spojení");
        }
    }

    public function testSixthUnusedChallengeIsRateLimited(): void
    {
        $this->assertSame(201, $this->register()->getStatus());

        for ($i = 0; $i < ChallengeService::MAX_OPEN; $i++) {
            $this->assertSame(200, $this->issue(DevicePurpose::Self)->getStatus());
        }
        $this->assertError('RATE_LIMITED', 429, $this->issue(DevicePurpose::Self));

        // Prošlé výzvy místo uvolní a úklid je smaže.
        $this->db->execute('UPDATE core_devices_challenges SET expires_at = %dt WHERE device_id = %s', new \DateTimeImmutable('-1 second'), $this->deviceId);
        $this->assertGreaterThanOrEqual(ChallengeService::MAX_OPEN, (new ChallengeService($this->db))->prune());
        $this->assertSame(200, $this->issue(DevicePurpose::Self)->getStatus());
    }
}

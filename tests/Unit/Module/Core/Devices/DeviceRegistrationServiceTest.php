<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Devices;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Devices\ChallengeService;
use Shipard\Module\Core\Devices\DeviceDocument;
use Shipard\Module\Core\Devices\DeviceException;
use Shipard\Module\Core\Devices\DevicePurpose;
use Shipard\Module\Core\Devices\DeviceRegistrationService;
use Shipard\Tests\Fixtures\Module\Devices\InMemoryDevicesDb;

/**
 * Registrace a zneplatnění zařízení (#177 D5, D27, U18): podpis výzvy nad
 * dodaným klíčem, jedno `deviceId` jedna registrace, zneplatnění je konečné.
 */
class DeviceRegistrationServiceTest extends TestCase
{
    use DeviceKeys;
    use DevicesConfig;

    private const string DEVICE = '0b9f6c1e-52a4-4f0e-9a43-6f2d1c7e8a10';
    private const int ADMIN = 3;

    private InMemoryDevicesDb $db;
    private \DateTimeImmutable $now;
    /** @var array{private: \OpenSSLAsymmetricKey, public: string} */
    private array $pair;

    protected function setUp(): void
    {
        $this->db = InMemoryDevicesDb::create();
        $this->now = new \DateTimeImmutable('2026-11-27 10:00:00');
        $this->pair = self::generateEcKey();
    }

    private function clock(): \Closure
    {
        return fn (): \DateTimeImmutable => $this->now;
    }

    private function service(): DeviceRegistrationService
    {
        return new DeviceRegistrationService($this->db, $this->devicesConfig(), clock: $this->clock());
    }

    private function challenge(DevicePurpose $purpose = DevicePurpose::Register): string
    {
        return (new ChallengeService($this->db, $this->clock()))->issue(self::DEVICE, $purpose);
    }

    /**
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function body(?string $challenge = null, array $override = []): array
    {
        $challenge ??= $this->challenge();
        return $override + [
            'deviceId'   => self::DEVICE,
            'algorithm'  => 'ES256',
            'publicKey'  => $this->pair['public'],
            'challenge'  => $challenge,
            'signature'  => self::sign($this->pair['private'], 'register', self::DEVICE, $challenge),
            'name'       => 'Pokladna 2',
            'mode'       => 'shared',
            'platform'   => 'ipados',
            'appVersion' => '1.0.0',
            'keyStorage' => 'secureEnclave',
        ];
    }

    /** @param array<string, mixed> $body */
    private function assertRejected(string $code, int $status, array $body): DeviceException
    {
        $devices = count($this->db->rows(DeviceDocument::TABLE));
        try {
            $this->service()->register($body, self::ADMIN);
            $this->fail("očekávána chyba {$code}");
        } catch (DeviceException $e) {
            $this->assertSame($code, $e->errorCode);
            $this->assertSame($status, $e->httpStatus);
            $this->assertCount($devices, $this->db->rows(DeviceDocument::TABLE), 'odmítnutá registrace nesmí založit zařízení');
            return $e;
        }
    }

    public function testRegistrationCreatesAnActiveDevice(): void
    {
        $result = $this->service()->register($this->body(), self::ADMIN);

        $this->assertSame(
            ['deviceId' => self::DEVICE, 'name' => 'Pokladna 2', 'mode' => 'shared', 'state' => 'active'],
            $result,
        );

        $row = $this->db->rows(DeviceDocument::TABLE)[0];
        $this->assertSame(self::DEVICE, $row['device_id']);
        $this->assertSame($this->pair['public'], $row['public_key']);
        $this->assertSame('ES256', $row['key_algorithm']);
        $this->assertSame('secureEnclave', $row['key_storage']);
        $this->assertSame('ipados', $row['platform']);
        $this->assertSame('1.0.0', $row['app_version']);
        $this->assertSame(self::ADMIN, $row['registered_by']);
        $this->assertEquals($this->now, $row['registered_at']);
        $this->assertSame(DeviceDocument::STATE_ACTIVE, $row['docState']);
        $this->assertSame(DeviceDocument::STATE_MAIN_ACTIVE, $row['docStateMain']);
    }

    public function testOptionalFieldsMayBeMissingAndNameIsTrimmed(): void
    {
        $body = $this->body(override: ['name' => '  Můj Mac  ', 'mode' => 'personal', 'platform' => 'mock']);
        unset($body['appVersion'], $body['keyStorage']);

        $this->service()->register($body, self::ADMIN);

        $row = $this->db->rows(DeviceDocument::TABLE)[0];
        $this->assertSame('Můj Mac', $row['name']);
        $this->assertNull($row['app_version']);
        $this->assertNull($row['key_storage']);
    }

    public function testBodyMustMatchTheContract(): void
    {
        $e = $this->assertRejected(DeviceException::VALIDATION_ERROR, 422, [
            'deviceId'   => 'neni-uuid',
            'algorithm'  => 'RS256',
            'name'       => '   ',
            'mode'       => 'unregistered',
            'platform'   => 'windows',
            'appVersion' => str_repeat('1', 41),
            'keyStorage' => 'disk',
        ]);

        $this->assertSame(
            ['deviceId', 'algorithm', 'publicKey', 'challenge', 'signature', 'name', 'mode', 'platform', 'appVersion', 'keyStorage'],
            array_column($e->details, 'field'),
        );
        // Tvar se kontroluje dřív, než se sáhne na výzvu.
        $this->assertSame([], $this->db->writes);
    }

    public function testKeyMustBeP256(): void
    {
        $other = self::generateEcKey('secp384r1');
        $challenge = $this->challenge();

        $this->assertRejected(DeviceException::SIGNATURE_INVALID, 401, $this->body($challenge, [
            'publicKey' => $other['public'],
            'signature' => self::sign($other['private'], 'register', self::DEVICE, $challenge),
        ]));
        // Výzva zůstala — klíč se odmítl dřív.
        $this->assertNull($this->db->rows(ChallengeService::TABLE)[0]['used_at'] ?? null);
    }

    public function testChallengeMustBeARegisterChallengeOfThisDevice(): void
    {
        // Výzva jiného účelu.
        $this->assertRejected(DeviceException::CHALLENGE_INVALID, 401, $this->body($this->challenge(DevicePurpose::Self)));
        // Neexistující výzva.
        $this->assertRejected(DeviceException::CHALLENGE_INVALID, 401, $this->body(str_repeat('A', 43)));
        // Prošlá.
        $stale = $this->challenge();
        $this->now = $this->now->modify('+61 seconds');
        $this->assertRejected(DeviceException::CHALLENGE_INVALID, 401, $this->body($stale));
    }

    public function testSignatureMustComeFromTheSuppliedKey(): void
    {
        $challenge = $this->challenge();
        $other = self::generateEcKey();

        $this->assertRejected(DeviceException::SIGNATURE_INVALID, 401, $this->body($challenge, [
            'signature' => self::sign($other['private'], 'register', self::DEVICE, $challenge),
        ]));
        // Jedna výzva = jeden pokus.
        $this->assertRejected(DeviceException::CHALLENGE_INVALID, 401, $this->body($challenge));
    }

    public function testSignatureForAnotherPurposeIsRejected(): void
    {
        $challenge = $this->challenge();

        $this->assertRejected(DeviceException::SIGNATURE_INVALID, 401, $this->body($challenge, [
            'signature' => self::sign($this->pair['private'], 'self', self::DEVICE, $challenge),
        ]));
    }

    public function testActiveDeviceCannotBeRegisteredAgain(): void
    {
        $this->service()->register($this->body(), self::ADMIN);

        $this->assertRejected(DeviceException::DEVICE_EXISTS, 409, $this->body());
    }

    public function testRevokedDeviceIdIsNeverRegisteredAgain(): void
    {
        $this->service()->register($this->body(), self::ADMIN);
        $id = (int) $this->db->rows(DeviceDocument::TABLE)[0]['id'];
        $this->assertTrue($this->service()->revoke($id, self::ADMIN));

        // Nová registrace téhož zařízení = nové deviceId (kontrakt §7.3).
        $this->assertRejected(DeviceException::DEVICE_REVOKED, 403, $this->body());
    }

    public function testConcurrentRegistrationLosesOnTheUniqueIndex(): void
    {
        // Souběh: zařízení vzniklo mezi kontrolou a vložením — fixture hlídá
        // unikátní index stejně jako databáze.
        $this->service()->register($this->body(), self::ADMIN);
        $this->db->hideExistingDevice = true;

        $this->assertRejected(DeviceException::DEVICE_EXISTS, 409, $this->body());
    }

    public function testRevokeIsFinalAndDropsChallenges(): void
    {
        $service = $this->service();
        $service->register($this->body(), self::ADMIN);
        $id = (int) $this->db->rows(DeviceDocument::TABLE)[0]['id'];
        $pending = (new ChallengeService($this->db, $this->clock()))->issue(self::DEVICE, DevicePurpose::Self);

        $this->assertTrue($service->revoke($id, 7));

        $row = $this->db->rows(DeviceDocument::TABLE)[0];
        $this->assertSame(DeviceDocument::STATE_REVOKED, $row['docState']);
        $this->assertSame(DeviceDocument::STATE_MAIN_REVOKED, $row['docStateMain']);
        $this->assertEquals($this->now, $row['revoked_at']);
        $this->assertSame(7, $row['revoked_by']);
        $this->assertFalse(
            (new ChallengeService($this->db, $this->clock()))->consume($pending, self::DEVICE, DevicePurpose::Self),
            'výzvy zneplatněného zařízení nesmí zůstat použitelné',
        );

        // Podruhé už není co zneplatnit; neexistující zařízení také ne.
        $this->assertFalse($service->revoke($id, 7));
        $this->assertFalse($service->revoke(999, null));
    }
}

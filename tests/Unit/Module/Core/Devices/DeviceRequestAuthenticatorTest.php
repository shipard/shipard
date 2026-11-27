<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Devices;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Devices\ChallengeService;
use Shipard\Module\Core\Devices\DeviceDocument;
use Shipard\Module\Core\Devices\DeviceException;
use Shipard\Module\Core\Devices\DevicePurpose;
use Shipard\Module\Core\Devices\DeviceRequestAuthenticator;
use Shipard\Tests\Fixtures\Module\Devices\InMemoryDevicesDb;

/**
 * Podepsané volání zařízení (#177 U4): neznámé a zneplatněné zařízení,
 * chybějící hlavičky, výzva a podpis, zápis posledního kontaktu.
 */
class DeviceRequestAuthenticatorTest extends TestCase
{
    use DeviceKeys;

    private const string DEVICE = '0b9f6c1e-52a4-4f0e-9a43-6f2d1c7e8a10';

    private InMemoryDevicesDb $db;
    private \DateTimeImmutable $now;
    private \OpenSSLAsymmetricKey $private;
    private int $rowId;

    protected function setUp(): void
    {
        $this->db = InMemoryDevicesDb::create();
        $this->now = new \DateTimeImmutable('2026-11-27 10:00:00');

        $pair = self::generateEcKey();
        $this->private = $pair['private'];
        $this->rowId = $this->db->seed(DeviceDocument::TABLE, [
            'device_id'         => self::DEVICE,
            'name'              => 'Pokladna 2',
            'mode'              => 'shared',
            'auto_lock_minutes' => 10,
            'platform'          => 'ipados',
            'app_version'       => '1.0.0',
            'public_key'        => $pair['public'],
            'key_algorithm'     => 'ES256',
            'last_seen_at'      => null,
            'docState'          => DeviceDocument::STATE_ACTIVE,
        ]);
    }

    private function clock(): \Closure
    {
        return fn (): \DateTimeImmutable => $this->now;
    }

    private function authenticator(): DeviceRequestAuthenticator
    {
        return new DeviceRequestAuthenticator($this->db, clock: $this->clock());
    }

    private function challenge(DevicePurpose $purpose = DevicePurpose::Self, string $deviceId = self::DEVICE): string
    {
        return (new ChallengeService($this->db, $this->clock()))->issue($deviceId, $purpose);
    }

    private function signature(string $challenge, string $purpose = 'self', string $deviceId = self::DEVICE): string
    {
        return self::sign($this->private, $purpose, $deviceId, $challenge);
    }

    /** @return array<string, mixed> */
    private function device(): array
    {
        return $this->db->tables[DeviceDocument::TABLE][$this->rowId];
    }

    private function assertRejected(string $code, int $status, callable $call): void
    {
        try {
            $call();
            $this->fail("očekávána chyba {$code}");
        } catch (DeviceException $e) {
            $this->assertSame($code, $e->errorCode);
            $this->assertSame($status, $e->httpStatus);
        }
        $this->assertNull($this->device()['last_seen_at'], 'odmítnuté volání nesmí zapsat poslední kontakt');
    }

    public function testSignedCallIsAcceptedAndRecordsLastContact(): void
    {
        $challenge = $this->challenge();

        $context = $this->authenticator()->authenticate(DevicePurpose::Self, self::DEVICE, $challenge, $this->signature($challenge));

        $this->assertSame($this->rowId, $context->id);
        $this->assertSame(self::DEVICE, $context->deviceId);
        $this->assertSame('Pokladna 2', $context->name);
        $this->assertSame('shared', $context->mode);
        $this->assertSame(10, $context->autoLockMinutes);
        $this->assertEquals($this->now, $this->device()['last_seen_at']);
        $this->assertSame('1.0.0', $this->device()['app_version'], 'bez hlavičky se verze nemění');
    }

    public function testAppVersionHeaderUpdatesTheDevice(): void
    {
        $challenge = $this->challenge();
        $this->authenticator()->authenticate(DevicePurpose::Self, self::DEVICE, $challenge, $this->signature($challenge), '1.2.0-beta+17');
        $this->assertSame('1.2.0-beta+17', $this->device()['app_version']);

        // Hodnota mimo tvar verze se ignoruje, volání projde.
        foreach (['', str_repeat('1', 41), "1.0\n", '<script>'] as $bad) {
            $challenge = $this->challenge();
            $this->authenticator()->authenticate(DevicePurpose::Self, self::DEVICE, $challenge, $this->signature($challenge), $bad);
            $this->assertSame('1.2.0-beta+17', $this->device()['app_version'], json_encode($bad));
        }
    }

    public function testUnknownDeviceIsRejected(): void
    {
        $unknown = '5d0e7c34-8b1a-4c59-a2f7-3e9b6d4c1f02';
        $challenge = $this->challenge();

        foreach ([null, '', 'neni-uuid', $unknown, strtoupper(self::DEVICE)] as $deviceId) {
            $this->assertRejected(
                DeviceException::DEVICE_UNKNOWN,
                401,
                fn () => $this->authenticator()->authenticate(DevicePurpose::Self, $deviceId, $challenge, $this->signature($challenge)),
            );
        }
        // Výzva zůstala nespotřebovaná — neznámé zařízení na ni nesáhne.
        $this->assertNull($this->db->rows(ChallengeService::TABLE)[0]['used_at'] ?? null);
    }

    public function testRevokedDeviceIsRejected(): void
    {
        $challenge = $this->challenge();
        $this->db->tables[DeviceDocument::TABLE][$this->rowId]['docState'] = DeviceDocument::STATE_REVOKED;

        $this->assertRejected(
            DeviceException::DEVICE_REVOKED,
            403,
            fn () => $this->authenticator()->authenticate(DevicePurpose::Self, self::DEVICE, $challenge, $this->signature($challenge)),
        );
    }

    public function testMissingChallengeAndSignatureHeaders(): void
    {
        $challenge = $this->challenge();

        foreach ([null, ''] as $missing) {
            $this->assertRejected(
                DeviceException::CHALLENGE_INVALID,
                401,
                fn () => $this->authenticator()->authenticate(DevicePurpose::Self, self::DEVICE, $missing, $this->signature($challenge)),
            );
        }
        $this->assertRejected(
            DeviceException::SIGNATURE_INVALID,
            401,
            fn () => $this->authenticator()->authenticate(DevicePurpose::Self, self::DEVICE, $challenge, null),
        );
    }

    public function testUsedExpiredAndForeignChallengeIsRejected(): void
    {
        $auth = $this->authenticator();

        // Použitá.
        $used = $this->challenge();
        $auth->authenticate(DevicePurpose::Self, self::DEVICE, $used, $this->signature($used));
        $this->db->tables[DeviceDocument::TABLE][$this->rowId]['last_seen_at'] = null;
        $this->assertRejected(
            DeviceException::CHALLENGE_INVALID,
            401,
            fn () => $auth->authenticate(DevicePurpose::Self, self::DEVICE, $used, $this->signature($used)),
        );

        // Jiného účelu — účel určuje routa, ne klient.
        $peripherals = $this->challenge(DevicePurpose::Peripherals);
        $this->assertRejected(
            DeviceException::CHALLENGE_INVALID,
            401,
            fn () => $auth->authenticate(DevicePurpose::Self, self::DEVICE, $peripherals, $this->signature($peripherals)),
        );

        // Cizího zařízení.
        $foreign = $this->challenge(deviceId: '5d0e7c34-8b1a-4c59-a2f7-3e9b6d4c1f02');
        $this->assertRejected(
            DeviceException::CHALLENGE_INVALID,
            401,
            fn () => $auth->authenticate(DevicePurpose::Self, self::DEVICE, $foreign, $this->signature($foreign)),
        );

        // Prošlá.
        $stale = $this->challenge();
        $this->now = $this->now->modify('+61 seconds');
        $this->assertRejected(
            DeviceException::CHALLENGE_INVALID,
            401,
            fn () => $auth->authenticate(DevicePurpose::Self, self::DEVICE, $stale, $this->signature($stale)),
        );
    }

    public function testWrongSignatureIsRejectedAndBurnsTheChallenge(): void
    {
        $auth = $this->authenticator();
        $challenge = $this->challenge();
        $other = self::generateEcKey();

        $this->assertRejected(
            DeviceException::SIGNATURE_INVALID,
            401,
            fn () => $auth->authenticate(
                DevicePurpose::Self,
                self::DEVICE,
                $challenge,
                self::sign($other['private'], 'self', self::DEVICE, $challenge),
            ),
        );
        // Jedna výzva = jeden pokus: ani správný podpis už neprojde.
        $this->assertRejected(
            DeviceException::CHALLENGE_INVALID,
            401,
            fn () => $auth->authenticate(DevicePurpose::Self, self::DEVICE, $challenge, $this->signature($challenge)),
        );
    }

    public function testSignatureForAnotherPurposeIsRejected(): void
    {
        $challenge = $this->challenge();

        $this->assertRejected(
            DeviceException::SIGNATURE_INVALID,
            401,
            fn () => $this->authenticator()->authenticate(
                DevicePurpose::Self,
                self::DEVICE,
                $challenge,
                $this->signature($challenge, 'peripherals'),
            ),
        );
    }

    public function testStoredKeyThatIsNotP256IsRejected(): void
    {
        $this->db->tables[DeviceDocument::TABLE][$this->rowId]['public_key'] = self::generateEcKey('secp384r1')['public'];
        $challenge = $this->challenge();

        $this->assertRejected(
            DeviceException::SIGNATURE_INVALID,
            401,
            fn () => $this->authenticator()->authenticate(DevicePurpose::Self, self::DEVICE, $challenge, $this->signature($challenge)),
        );
    }

    public function testErrorMessagesDoNotHelpGuessing(): void
    {
        $messages = [
            DeviceException::unknownDevice()->getMessage(),
            DeviceException::challengeInvalid()->getMessage(),
            DeviceException::signatureInvalid()->getMessage(),
        ];
        foreach ($messages as $message) {
            $this->assertDoesNotMatchRegularExpression('/expired|used|purpose|curve|key|base64/i', $message);
        }
    }
}

<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Devices;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Devices\ChallengeService;
use Shipard\Module\Core\Devices\DeviceException;
use Shipard\Module\Core\Devices\DevicePurpose;
use Shipard\Tests\Fixtures\Module\Devices\InMemoryDevicesDb;

/**
 * Výzvy zařízení (#177 U20): jednorázové, platí 60 s, vázané na účel
 * a zařízení, nejvýš 5 nevyužitých na zařízení, úklid použitých a prošlých.
 * Souběžnou spotřebu nad skutečnou databází hlídá integrační test.
 */
class ChallengeServiceTest extends TestCase
{
    private const string DEVICE = '0b9f6c1e-52a4-4f0e-9a43-6f2d1c7e8a10';
    private const string OTHER = '5d0e7c34-8b1a-4c59-a2f7-3e9b6d4c1f02';

    private InMemoryDevicesDb $db;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->db = InMemoryDevicesDb::create();
        $this->now = new \DateTimeImmutable('2026-11-27 10:00:00');
    }

    private function service(): ChallengeService
    {
        return new ChallengeService($this->db, fn (): \DateTimeImmutable => $this->now);
    }

    private function later(int $seconds): void
    {
        $this->now = $this->now->modify("+{$seconds} seconds");
    }

    public function testChallengeIs32RandomBytesInBase64Url(): void
    {
        $first = $this->service()->issue(self::DEVICE, DevicePurpose::Self);
        $second = $this->service()->issue(self::DEVICE, DevicePurpose::Self);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $first);
        $this->assertSame(32, strlen((string) base64_decode(strtr($first, '-_', '+/'), true)));
        $this->assertNotSame($first, $second);

        $row = $this->db->rows(ChallengeService::TABLE)[0];
        $this->assertSame(self::DEVICE, $row['device_id']);
        $this->assertSame('self', $row['purpose']);
        $this->assertEquals($this->now->modify('+60 seconds'), $row['expires_at']);
    }

    public function testChallengeIsSingleUse(): void
    {
        $service = $this->service();
        $challenge = $service->issue(self::DEVICE, DevicePurpose::Self);

        $this->assertTrue($service->consume($challenge, self::DEVICE, DevicePurpose::Self));
        $this->assertFalse($service->consume($challenge, self::DEVICE, DevicePurpose::Self));
    }

    public function testChallengeExpiresAfterSixtySeconds(): void
    {
        $service = $this->service();
        $fresh = $service->issue(self::DEVICE, DevicePurpose::Self);
        $stale = $service->issue(self::DEVICE, DevicePurpose::Self);

        $this->later(59);
        $this->assertTrue($service->consume($fresh, self::DEVICE, DevicePurpose::Self));

        $this->later(1);
        $this->assertFalse($service->consume($stale, self::DEVICE, DevicePurpose::Self));
    }

    public function testChallengeIsBoundToPurposeAndDevice(): void
    {
        $service = $this->service();
        $challenge = $service->issue(self::DEVICE, DevicePurpose::Self);

        $this->assertFalse($service->consume($challenge, self::DEVICE, DevicePurpose::Peripherals), 'jiný účel');
        $this->assertFalse($service->consume($challenge, self::OTHER, DevicePurpose::Self), 'jiné zařízení');
        // Neúspěšný pokus výzvu nespotřebuje.
        $this->assertTrue($service->consume($challenge, self::DEVICE, DevicePurpose::Self));
    }

    public function testUnknownAndMalformedChallengeIsRejected(): void
    {
        $service = $this->service();
        $service->issue(self::DEVICE, DevicePurpose::Self);
        $writes = count($this->db->writes);

        $this->assertFalse($service->consume(str_repeat('A', 43), self::DEVICE, DevicePurpose::Self));
        foreach (['', 'krátká', str_repeat('A', 44), str_repeat('A', 42) . '+', str_repeat('A', 43) . "\n"] as $malformed) {
            $this->assertFalse($service->consume($malformed, self::DEVICE, DevicePurpose::Self), json_encode($malformed));
        }
        // Na výzvu ve špatném tvaru se databáze vůbec neptá.
        $this->assertSame($writes + 1, count($this->db->writes));
    }

    public function testDeviceMayHoldAtMostFiveUnusedChallenges(): void
    {
        $service = $this->service();
        $issued = [];
        for ($i = 0; $i < ChallengeService::MAX_OPEN; $i++) {
            $issued[] = $service->issue(self::DEVICE, DevicePurpose::Self);
        }

        try {
            $service->issue(self::DEVICE, DevicePurpose::Peripherals);
            $this->fail('šestá nevyužitá výzva musí skončit RATE_LIMITED');
        } catch (DeviceException $e) {
            $this->assertSame(DeviceException::RATE_LIMITED, $e->errorCode);
            $this->assertSame(429, $e->httpStatus);
        }
        $this->assertCount(5, $this->db->rows(ChallengeService::TABLE));

        // Limit je na zařízení — jiné zařízení výzvu dostane.
        $service->issue(self::OTHER, DevicePurpose::Self);

        // Použitá výzva místo uvolní …
        $service->consume($issued[0], self::DEVICE, DevicePurpose::Self);
        $service->issue(self::DEVICE, DevicePurpose::Self);

        // … a prošlé také.
        $this->later(61);
        $service->issue(self::DEVICE, DevicePurpose::Self);
        $this->addToAssertionCount(1);
    }

    public function testPruneRemovesUsedAndExpiredChallenges(): void
    {
        $service = $this->service();
        $used = $service->issue(self::DEVICE, DevicePurpose::Self);
        $service->issue(self::DEVICE, DevicePurpose::Self);
        $service->consume($used, self::DEVICE, DevicePurpose::Self);

        $this->later(61);
        $live = $service->issue(self::DEVICE, DevicePurpose::Peripherals);

        $this->assertSame(2, $service->prune());
        $rows = $this->db->rows(ChallengeService::TABLE);
        $this->assertCount(1, $rows);
        $this->assertSame($live, $rows[0]['challenge']);
        $this->assertSame(0, $service->prune());
    }

    public function testForgetDeviceRemovesAllItsChallenges(): void
    {
        $service = $this->service();
        $challenge = $service->issue(self::DEVICE, DevicePurpose::Self);
        $service->issue(self::DEVICE, DevicePurpose::Peripherals);
        $kept = $service->issue(self::OTHER, DevicePurpose::Self);

        $service->forgetDevice(self::DEVICE);

        $this->assertFalse($service->consume($challenge, self::DEVICE, DevicePurpose::Self));
        $this->assertTrue($service->consume($kept, self::OTHER, DevicePurpose::Self));
    }
}

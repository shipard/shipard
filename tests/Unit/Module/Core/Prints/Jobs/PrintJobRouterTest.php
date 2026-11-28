<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Prints\Jobs;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Prints\EscPos\PrinterProfiles;
use Shipard\Module\Core\Devices\DeviceDocument;
use Shipard\Module\Core\Prints\Jobs\PrintJobException;
use Shipard\Module\Core\Prints\Jobs\PrintJobRouter;
use Shipard\Tests\Fixtures\Module\Prints\InMemoryPrintJobsDb;

/**
 * Směrování tiskové úlohy (#177 U37): zařízení + role → periferie a profil.
 */
final class PrintJobRouterTest extends TestCase
{
    use PrintJobsFixture;

    protected function setUp(): void
    {
        $this->db = InMemoryPrintJobsDb::create();
    }

    /** @param array<string, mixed> $config */
    private function router(array $config = []): PrintJobRouter
    {
        return new PrintJobRouter($this->db, $this->jobsConfig($config));
    }

    private function assertFails(string $code, int $status, callable $call): PrintJobException
    {
        try {
            $call();
        } catch (PrintJobException $e) {
            $this->assertSame($code, $e->errorCode);
            $this->assertSame($status, $e->httpStatus);
            $this->assertSame([], $this->db->writes, 'Směrování nic nezapisuje');
            return $e;
        }
        $this->fail("Čekal jsem {$code}");
    }

    public function testRoutesToThePresentPrinterWithRole(): void
    {
        $device = $this->seedDevice();
        $this->seedPeripheral($device, ['peripheral_id' => 'scanner-1', 'kind' => 'scanner', 'role' => null]);
        $printer = $this->seedPeripheral($device);
        $this->seedPeripheral($device, ['peripheral_id' => 'printer-2', 'role' => null]);

        $route = $this->router()->route(self::DEVICE_ID, 'receipt');

        $this->assertSame($device, $route->deviceRowId);
        $this->assertSame($printer, $route->peripheralRowId);
        $this->assertSame('printer-1', $route->peripheralId);
        $this->assertSame('receipt', $route->role);
        $this->assertSame('escpos-80-cp852', $route->profile->id);
        $this->assertSame([], $route->warnings);
    }

    public function testAbsentPrinterWithRoleDoesNotBlockThePresentOne(): void
    {
        $device = $this->seedDevice();
        $this->seedPeripheral($device, ['peripheral_id' => 'old', 'present' => 0]);
        $this->seedPeripheral($device, ['peripheral_id' => 'new', 'profile_id' => 'escpos-58-cp852']);

        $route = $this->router()->route(self::DEVICE_ID, 'receipt');

        $this->assertSame('new', $route->peripheralId);
        $this->assertSame('escpos-58-cp852', $route->profile->id);
    }

    public function testDeviceWithoutPrinterWithRole(): void
    {
        $device = $this->seedDevice();
        $this->seedPeripheral($device, ['role' => null]);

        $this->assertFails('NO_PERIPHERAL_FOR_ROLE', 409, fn () => $this->router()->route(self::DEVICE_ID, 'receipt'));
    }

    public function testScannerWithRoleIsNotAPrinter(): void
    {
        $device = $this->seedDevice();
        $this->seedPeripheral($device, ['kind' => 'scanner']);

        $this->assertFails('NO_PERIPHERAL_FOR_ROLE', 409, fn () => $this->router()->route(self::DEVICE_ID, 'receipt'));
    }

    public function testOnlyAbsentPrinterWithRole(): void
    {
        $device = $this->seedDevice();
        $this->seedPeripheral($device, ['present' => 0]);

        $this->assertFails('PERIPHERAL_NOT_PRESENT', 409, fn () => $this->router()->route(self::DEVICE_ID, 'receipt'));
    }

    public function testTwoPresentPrintersWithRoleAreAmbiguous(): void
    {
        $device = $this->seedDevice();
        $this->seedPeripheral($device);
        $this->seedPeripheral($device, ['peripheral_id' => 'printer-2']);

        $this->assertFails('ROLE_AMBIGUOUS', 409, fn () => $this->router()->route(self::DEVICE_ID, 'receipt'));
    }

    public function testPrinterOfAnotherDeviceDoesNotCount(): void
    {
        $this->seedDevice();
        $other = $this->seedDevice(['device_id' => 'aaaaaaaa-2222-4333-8444-555555555555']);
        $this->seedPeripheral($other);

        $this->assertFails('NO_PERIPHERAL_FOR_ROLE', 409, fn () => $this->router()->route(self::DEVICE_ID, 'receipt'));
    }

    public function testRevokedDevice(): void
    {
        $device = $this->seedDevice(['docState' => DeviceDocument::STATE_REVOKED]);
        $this->seedPeripheral($device);

        $this->assertFails('DEVICE_REVOKED', 403, fn () => $this->router()->route(self::DEVICE_ID, 'receipt'));
    }

    public function testDeviceRegisteredElsewhereIsUnknownHere(): void
    {
        // Registrace je pro jeden zdroj dat (D33): `deviceId` z jiného zdroje
        // tu žádný řádek nemá. 409, ne 401 — session je v pořádku.
        $this->assertFails('DEVICE_UNKNOWN', 409, fn () => $this->router()->route(self::DEVICE_ID, 'receipt'));
    }

    public function testDeviceIdOfWrongShapeIsUnknown(): void
    {
        $this->seedDevice();

        foreach ([null, '', 'not-a-uuid', 42, ['x']] as $deviceId) {
            $this->assertFails('DEVICE_UNKNOWN', 409, fn () => $this->router()->route($deviceId, 'receipt'));
        }
    }

    public function testUnknownRoleIsAValidationError(): void
    {
        $device = $this->seedDevice();
        $this->seedPeripheral($device);

        foreach (['a4', ''] as $role) {
            $e = $this->assertFails('VALIDATION_ERROR', 422, fn () => $this->router()->route(self::DEVICE_ID, $role));
            $this->assertSame('role', $e->details[0]['field']);
        }
    }

    public function testUnknownProfileFallsBackWithWarning(): void
    {
        $device = $this->seedDevice();
        $this->seedPeripheral($device, ['profile_id' => 'escpos-112-unknown']);

        $route = $this->router()->route(self::DEVICE_ID, 'receipt');

        $this->assertSame(PrinterProfiles::FALLBACK_ID, $route->profile->id);
        $this->assertCount(1, $route->warnings);
        $this->assertSame(PrinterProfiles::WARNING_UNKNOWN_PROFILE, $route->warnings[0]->code);
    }

    public function testPeripheralWithoutProfileFallsBackWithWarning(): void
    {
        $device = $this->seedDevice();
        $this->seedPeripheral($device, ['profile_id' => null]);

        $route = $this->router()->route(self::DEVICE_ID, 'receipt');

        $this->assertSame(PrinterProfiles::FALLBACK_ID, $route->profile->id);
        $this->assertSame(PrinterProfiles::WARNING_NO_PROFILE, $route->warnings[0]->code);
    }

    public function testDrawerNeedsProfileWithDrawer(): void
    {
        $device = $this->seedDevice();
        $this->seedPeripheral($device);

        $profiles = self::printsJsonc('printerProfiles.jsonc');
        $profiles['escpos-80-cp852']['features'] = ['cut'];
        $router = $this->router([PrinterProfiles::CFG_ITEM => $profiles]);

        $this->assertFails('PROFILE_NO_DRAWER', 409, static fn () => $router->route(self::DEVICE_ID, 'receipt', true));
        // Tisk na tutéž tiskárnu šuplík nepotřebuje.
        $this->assertSame('escpos-80-cp852', $router->route(self::DEVICE_ID, 'receipt')->profile->id);
    }

    public function testDrawerWithProfileThatHasIt(): void
    {
        $device = $this->seedDevice();
        $this->seedPeripheral($device);

        $this->assertSame('printer-1', $this->router()->route(self::DEVICE_ID, 'receipt', true)->peripheralId);
    }
}

<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Prints\Jobs;

use PHPUnit\Framework\MockObject\Stub;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Prints\EscPos\EscPosDrivers;
use Shipard\Core\Prints\EscPos\PrinterProfiles;
use Shipard\Core\Prints\EscPos\PrinterTestReceipt;
use Shipard\Module\Core\Devices\DeviceDocument;
use Shipard\Module\Core\Devices\PeripheralRoles;
use Shipard\Tests\Fixtures\Module\Prints\InMemoryPrintJobsDb;
use Shipard\Tests\Unit\Core\Prints\EscPos\EscPosConfig;
use Shipard\Tests\Unit\Module\Core\Devices\DevicesConfig;

/**
 * Společný základ testů tiskových úloh: zařízení s periferiemi v in-memory
 * databázi a konfigurace ze zdrojových JSONC modulů core.devices a core.prints.
 */
trait PrintJobsFixture
{
    use DevicesConfig;
    use EscPosConfig;

    protected const string DEVICE_ID = '11111111-2222-4333-8444-555555555555';

    protected InMemoryPrintJobsDb $db;

    /** @param array<string, mixed> $override cfgItem id → hodnota */
    protected function jobsConfig(array $override = []): ConfigRuntime&Stub
    {
        $items = $override + [
            PeripheralRoles::CFG_ITEM    => self::repoJsonc('modules/core/devices/config/peripheralRoles.jsonc'),
            PrinterProfiles::CFG_ITEM    => self::printsJsonc('printerProfiles.jsonc'),
            EscPosDrivers::CFG_ITEM      => self::printsJsonc('printerDrivers.jsonc'),
            PrinterTestReceipt::CFG_ITEM => self::printsJsonc('printerTestTexts.jsonc'),
        ];
        $config = $this->createStub(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(static fn (string $id): mixed => $items[$id] ?? null);
        return $config;
    }

    /** @param array<string, mixed> $override */
    protected function seedDevice(array $override = []): int
    {
        return $this->db->seed(DeviceDocument::TABLE, $override + [
            'device_id' => self::DEVICE_ID,
            'name'      => 'Pokladna 1',
            'mode'      => 'shared',
            'docState'  => DeviceDocument::STATE_ACTIVE,
        ]);
    }

    /** @param array<string, mixed> $override */
    protected function seedPeripheral(int $device, array $override = []): int
    {
        return $this->db->seed('core_devices_peripherals', $override + [
            'device'        => $device,
            'peripheral_id' => 'printer-1',
            'kind'          => 'printer',
            'name'          => 'Účtenky',
            'profile_id'    => 'escpos-80-cp852',
            'present'       => 1,
            'role'          => 'receipt',
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Devices;

/**
 * Ověřené zařízení podepsaného volání — výsledek
 * `DeviceRequestAuthenticator`. Nese jen to, co endpointy potřebují; klíč
 * zařízení dál neputuje.
 */
final readonly class DeviceContext
{
    public function __construct(
        /** ID řádku v `core_devices_devices`. */
        public int $id,
        public string $deviceId,
        public string $name,
        public string $mode,
        public ?int $autoLockMinutes,
    ) {
    }

    /** @param array<string, mixed> $row řádek `core_devices_devices` */
    public static function fromRow(array $row): self
    {
        $autoLock = $row['auto_lock_minutes'] ?? null;
        return new self(
            id: (int) $row['id'],
            deviceId: (string) $row['device_id'],
            name: (string) $row['name'],
            mode: (string) $row['mode'],
            autoLockMinutes: $autoLock === null ? null : (int) $autoLock,
        );
    }
}

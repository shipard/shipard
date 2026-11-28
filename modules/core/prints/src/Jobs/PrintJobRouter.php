<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Prints\Jobs;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Prints\EscPos\EscPosConfigException;
use Shipard\Core\Prints\EscPos\PrinterProfile;
use Shipard\Core\Prints\EscPos\PrinterProfiles;
use Shipard\Core\Prints\PrintRenderException;
use Shipard\Core\Render\RenderErrorKind;
use Shipard\Module\Core\Devices\DeviceException;
use Shipard\Module\Core\Devices\DeviceRequestAuthenticator;
use Shipard\Module\Core\Devices\PeripheralRoles;

/**
 * Směrování tiskové úlohy (#177 U37): zařízení + role → periferie a profil.
 *
 * Zařízení se hledá podle `deviceId` jen v tomto zdroji dat (D33) a musí
 * být aktivní. Periferii vybírá role: druh tiskárna a `present`. Jedinečnost
 * role hlídá jen formulář (`PeripheralDocument`) — generické CRUD ji obejde,
 * proto víc přítomných tiskáren s rolí není chyba dat, ale odpověď
 * `ROLE_AMBIGUOUS`. Router nikdy nevybírá první nalezenou.
 */
final class PrintJobRouter
{
    private const string KIND_PRINTER = 'printer';

    private readonly PeripheralRoles $roles;

    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly ?ConfigRuntime $config,
    ) {
        $this->roles = new PeripheralRoles($config);
    }

    /**
     * @param bool $needsDrawer úloha otevírá šuplík — profil ho musí umět
     *
     * @throws PrintJobException `DEVICE_UNKNOWN`, `DEVICE_REVOKED`, `VALIDATION_ERROR` (role),
     *         `NO_PERIPHERAL_FOR_ROLE`, `PERIPHERAL_NOT_PRESENT`, `ROLE_AMBIGUOUS`, `PROFILE_NO_DRAWER`
     * @throws PrintRenderException Katalog profilů nemá ani záložní profil.
     */
    public function route(mixed $deviceId, string $role, bool $needsDrawer = false): PrintJobRoute
    {
        if ($role === '' || $this->roles->isUnknown($role) || !$this->roles->allowsKind($role, self::KIND_PRINTER)) {
            throw PrintJobException::validation([['field' => 'role', 'message' => 'Unknown printer role']]);
        }

        $device = $this->activeDevice($deviceId);
        $peripheral = $this->peripheralWithRole((int) $device['id'], $role);

        $profileId = $peripheral['profile_id'] ?? null;
        try {
            $resolved = PrinterProfiles::fromConfig($this->config)
                ->get(is_string($profileId) ? $profileId : null);
        } catch (EscPosConfigException $e) {
            throw new PrintRenderException(RenderErrorKind::Unconfigured, $e->getMessage());
        }

        /** @var PrinterProfile $profile */
        $profile = $resolved['profile'];
        if ($needsDrawer && !$profile->hasFeature(PrinterProfile::FEATURE_DRAWER)) {
            throw PrintJobException::profileWithoutDrawer($profile->id);
        }

        return new PrintJobRoute(
            deviceRowId: (int) $device['id'],
            peripheralRowId: (int) $peripheral['id'],
            peripheralId: (string) $peripheral['peripheral_id'],
            role: $role,
            profile: $profile,
            warnings: $resolved['warning'] !== null ? [$resolved['warning']] : [],
        );
    }

    /**
     * Aktivní zařízení tohoto zdroje dat — pravidlo je v modulu zařízení,
     * tady se jen přemapují stavy (viz `PrintJobException`).
     *
     * @return array<string, mixed>
     */
    private function activeDevice(mixed $deviceId): array
    {
        try {
            return (new DeviceRequestAuthenticator($this->db))
                ->activeDevice(is_string($deviceId) ? $deviceId : null);
        } catch (DeviceException $e) {
            throw $e->errorCode === DeviceException::DEVICE_REVOKED
                ? PrintJobException::revokedDevice()
                : PrintJobException::unknownDevice();
        }
    }

    /**
     * Jediná přítomná tiskárna zařízení s rolí.
     *
     * @return array<string, mixed> řádek `core_devices_peripherals`
     */
    private function peripheralWithRole(int $deviceRowId, string $role): array
    {
        $withRole = array_values(array_filter(
            $this->db->fetchAll(
                'SELECT id, peripheral_id, kind, profile_id, present, role
                 FROM core_devices_peripherals WHERE device = %i ORDER BY id',
                $deviceRowId,
            ),
            static fn (array $row): bool => (string) ($row['role'] ?? '') === $role
                && (string) ($row['kind'] ?? '') === self::KIND_PRINTER,
        ));
        if ($withRole === []) {
            throw PrintJobException::noPeripheralForRole($role);
        }

        $present = array_values(array_filter(
            $withRole,
            static fn (array $row): bool => (int) ($row['present'] ?? 0) === 1,
        ));
        if ($present === []) {
            throw PrintJobException::peripheralNotPresent($role);
        }
        if (count($present) > 1) {
            throw PrintJobException::roleAmbiguous($role);
        }

        return $present[0];
    }
}

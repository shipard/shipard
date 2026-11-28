<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Devices;

/**
 * Účel výzvy (#177 U20, kontrakt docs/native-bridge.md §7.2). Účel je
 * součástí podepisovaného řetězce a výzva je na něj vázaná — každý podepsaný
 * endpoint má svůj. Další účely (`enroll`, `pin-login`, `lock`) přidá task 6.
 */
enum DevicePurpose: string
{
    case Register = 'register';
    case Self = 'self';
    case Peripherals = 'peripherals';
    case PrinterProfiles = 'printer-profiles';

    /**
     * Výzvu s tímto účelem vydá server jen se session administrátora —
     * zařízení ještě neexistuje, takže ho nejde ověřit jinak.
     */
    public function requiresAdminSession(): bool
    {
        return $this === self::Register;
    }
}

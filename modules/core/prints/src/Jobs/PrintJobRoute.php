<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Prints\Jobs;

use Shipard\Core\Prints\EscPos\PrinterProfile;
use Shipard\Core\Prints\PrintMessage;

/**
 * Kam tisková úloha jde: periferie zařízení s danou rolí a profil, podle
 * kterého se pro ni data složí (výsledek `PrintJobRouter`).
 */
final class PrintJobRoute
{
    /** @param list<PrintMessage> $warnings varování směrování (záložní profil) */
    public function __construct(
        public readonly int $deviceRowId,
        public readonly int $peripheralRowId,
        /** ID periferie v aplikaci — jde do `print.submit`. */
        public readonly string $peripheralId,
        public readonly string $role,
        public readonly PrinterProfile $profile,
        public readonly array $warnings = [],
    ) {
    }
}

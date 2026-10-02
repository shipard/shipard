<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core\Prints\PaymentQr;

/**
 * Dopočet IBAN z českého čísla účtu `[předčíslí-]číslo/kód banky`.
 * BBAN = kód banky (4) + předčíslí (6) + číslo (10), kontrolní číslice
 * podle ISO 13616 (mod 97).
 */
final class CzIbanCalculator
{
    public static function fromAccountNumber(string $accountNumber): ?string
    {
        $compact = preg_replace('/\s+/', '', $accountNumber);
        if (!preg_match('#^(?:(\d{1,6})-)?(\d{2,10})/(\d{4})$#', (string) $compact, $m)) {
            return null;
        }

        $bban = $m[3]
            . str_pad($m[1], 6, '0', STR_PAD_LEFT)
            . str_pad($m[2], 10, '0', STR_PAD_LEFT);

        // 'CZ00' přesunuté na konec, písmena jako čísla: C = 12, Z = 35.
        $check = 98 - self::mod97($bban . '123500');

        return 'CZ' . str_pad((string) $check, 2, '0', STR_PAD_LEFT) . $bban;
    }

    /** Zbytek po dělení 97 pro číslo delší než int — po číslicích. */
    private static function mod97(string $digits): int
    {
        $remainder = 0;
        foreach (str_split($digits) as $digit) {
            $remainder = ($remainder * 10 + (int) $digit) % 97;
        }
        return $remainder;
    }
}

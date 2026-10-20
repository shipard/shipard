<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\EInvoice;

/**
 * Deterministické UUID e-faktury (#119 D7): UUIDv5 (RFC 9562 § 5.5,
 * SHA-1) nad pevným jmenným prostorem Shipardu. Stejné jméno dává vždy
 * stejné UUID — tisk ho tvoří ze zdroje dat a dokladu (bez sloupce
 * v databázi), korpus z cesty buňky, aby se commitované soubory mezi
 * buildy neměnily.
 */
final class EInvoiceUuid
{
    /** Jmenný prostor Shipardu (vygenerován jednou, neměnit). */
    public const NAMESPACE = '082dfa0f-d3a6-4b11-9c1b-3a6737a1a957';

    public static function fromName(string $name, string $namespace = self::NAMESPACE): string
    {
        $bytes = @hex2bin(str_replace('-', '', $namespace));
        if ($bytes === false || strlen($bytes) !== 16) {
            throw new \InvalidArgumentException("Jmenný prostor '{$namespace}' není UUID");
        }
        $hash = substr(sha1($bytes . $name, true), 0, 16);
        $hash[6] = chr((ord($hash[6]) & 0x0f) | 0x50); // verze 5
        $hash[8] = chr((ord($hash[8]) & 0x3f) | 0x80); // varianta RFC
        $hex = bin2hex($hash);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}

<?php

declare(strict_types=1);

namespace Shipard\Core\Mail;

/**
 * Seznam e-mailových adres jako jeden text — tvar, ve kterém ho drží
 * sloupce `email_to` / `email_cc` (adresy oddělené čárkou). Jediné místo,
 * které seznam skládá a rozebírá: fronta, composer i Odeslaná pošta.
 */
final class AddressList
{
    public const SEPARATOR = ', ';

    /**
     * Adresy bez prázdných položek a bez duplicit (bez ohledu na velikost
     * písmen, vyhrává první výskyt). Syntaxi nekontroluje — viz `invalid()`.
     *
     * @param string|array<mixed>|null $value Text s čárkami, nebo seznam.
     * @return list<string>
     */
    public static function parse(string|array|null $value): array
    {
        $items = is_array($value) ? $value : explode(',', (string) $value);

        $out  = [];
        $seen = [];
        foreach ($items as $item) {
            if (!is_string($item)) {
                continue;
            }
            foreach (explode(',', $item) as $address) {
                $address = trim($address);
                $key     = mb_strtolower($address);
                if ($address === '' || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $out[]      = $address;
            }
        }
        return $out;
    }

    /** @param list<string> $addresses */
    public static function format(array $addresses): string
    {
        return implode(self::SEPARATOR, $addresses);
    }

    public static function isValid(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * První syntakticky neplatná adresa seznamu; null = všechny platné.
     *
     * @param list<string> $addresses
     */
    public static function invalid(array $addresses): ?string
    {
        foreach ($addresses as $address) {
            if (!self::isValid($address)) {
                return $address;
            }
        }
        return null;
    }
}

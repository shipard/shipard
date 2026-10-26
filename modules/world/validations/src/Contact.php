<?php

declare(strict_types=1);

namespace Shipard\Module\World\Validations;

/** E-mail a web: tvar bez nálezů (D12). */
final class Contact
{
    public static function email(string $email): string
    {
        return strtolower(trim($email));
    }

    /** Malými, bez schématu a bez koncového lomítka. */
    public static function web(string $web): string
    {
        $s = strtolower(trim($web));
        $s = (string) preg_replace('~^[a-z][a-z0-9+.-]*://~', '', $s);
        return rtrim($s, '/');
    }
}

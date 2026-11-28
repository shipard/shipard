<?php

declare(strict_types=1);

namespace Shipard\Core\Prints\EscPos;

/**
 * Kódové stránky, které renderer umí (#177 U27, U32). Ve v1 jen CP852;
 * další stránka = třída s tabulkou a řádek tady (a příkaz v ovladači).
 */
final class CodePages
{
    public static function has(string $name): bool
    {
        return $name === Cp852::NAME;
    }

    public static function get(string $name): CodePage
    {
        return match ($name) {
            Cp852::NAME => new Cp852(),
            default     => throw new EscPosConfigException("Code page '{$name}' is not supported"),
        };
    }
}

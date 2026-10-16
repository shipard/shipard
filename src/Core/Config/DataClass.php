<?php

declare(strict_types=1);

namespace Shipard\Core\Config;

/**
 * Třída dat zdroje dat (#126 D6): `free` = ukázková, seedovaná nebo
 * testovací data — Claude i periodické úlohy s nimi smějí pracovat volně;
 * `realCopy` = kopie skutečných dat (import ze starého Shipardu, obnova
 * zálohy) — mutace jen po schválení, bez serverového AI připojení (D9).
 *
 * Žije v `config/main.json` (`dataClass`), ne ve `state.json`: stav se čte
 * fail-closed a chybná hodnota by zdroj dat zavřela. Tady platí fail-safe —
 * cokoli jiného než přesně řetězec `'free'` se bere jako `realCopy` a zdroj
 * dat dál běží; neplatnou hodnotu ukáže `ds-class` a `ds-list` (U6).
 */
enum DataClass: string
{
    case Free = 'free';
    case RealCopy = 'realCopy';

    /** Třída z hodnoty v `main.json`: jen přesně `'free'` je Free, vše ostatní RealCopy. */
    public static function fromConfig(mixed $raw): self
    {
        return $raw === self::Free->value ? self::Free : self::RealCopy;
    }

    /** Hodnota je jedna z platných tříd (přesně `'free'` nebo `'realCopy'`). */
    public static function isValidConfigValue(mixed $raw): bool
    {
        return is_string($raw) && self::tryFrom($raw) !== null;
    }
}

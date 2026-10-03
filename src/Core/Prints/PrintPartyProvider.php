<?php

declare(strict_types=1);

namespace Shipard\Core\Prints;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;

/**
 * Volitelné rozhraní builderu: umí říct, komu je tisk určený (#94 D2).
 * `PrintRunner` se ptá **před** `build()` — jazyk tisku musí znát dřív, než
 * sestaví překladač a konfiguraci. Builder bez tohoto rozhraní tiskne
 * v hlavním jazyce vlastní země zdroje dat.
 */
interface PrintPartyProvider
{
    /**
     * @param array<string, mixed> $record Řádek tabulky deklarace.
     * @param ?ConfigRuntime $config Konfigurace v libovolném jazyce — smí
     *        se z ní číst jen to, co na jazyce nezávisí (klíče cfgItemů).
     * @return ?PrintParty Null = záznam partnera nemá.
     */
    public function printParty(array $record, DataSourceConnection $db, ?ConfigRuntime $config): ?PrintParty;
}

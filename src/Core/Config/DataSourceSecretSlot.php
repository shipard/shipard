<?php

declare(strict_types=1);

namespace Shipard\Core\Config;

/**
 * Jedno místo konfigurace, které potřebuje tajemství (#126 B1, U6): co
 * `main.json` očekává a kde to je — bez hodnoty. Jediný zdroj pro
 * `shpd-ds ds-secret list` i sekci `doctor` Data source secrets; oba tak
 * čtou jen přítomnost (`DataSourceSecrets::has()`), nikdy obsah.
 */
final readonly class DataSourceSecretSlot
{
    public function __construct(
        /** Název souboru v `secrets/` ({@see DataSourceSecrets}). */
        public string $name,
        /** Kdo tajemství používá — `database`, `mail.relay`, `auth.providers[<id>]`. */
        public string $usedBy,
        /** Bez tajemství konzument nefunguje (relay bez `username` ho nepotřebuje). */
        public bool $required,
        /** Hodnota zůstala v `main.json` — ignoruje se, `doctor` ji hlásí, `ds-upgrade` přesune (B2). */
        public bool $inMainConfig,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace Shipard\Core\Module;

/**
 * Provisioner modulu (`provisioners` v `module.jsonc`, #174 D28) — výchozí
 * data, bez kterých modul ve zdroji dat nejde používat (seedové druhy
 * číselníku). `ds-upgrade` volá provisionery aktivních modulů po
 * synchronizaci schématu, **vždy** — i pod `skipProvisioning` a po
 * `ds-reset`. Proto platí:
 *
 *  - idempotence: druhý běh nic nezaloží a nic nepřepíše (existující záznam
 *    se pozná podle stabilního klíče, typicky `system_code`, v jakémkoli
 *    stavu — archivovaný ani smazaný se nevrací);
 *  - žádná migrovaná data: co se u převáděného zdroje dat přenáší ze starého
 *    systému, sem nepatří;
 *  - třída jde vytvořit bez argumentů konstruktoru, vše dostane v kontextu.
 *
 * Výjimka z `provision()` ukončí `ds-upgrade` chybou — tiché přeskočení by
 * nechalo modul bez dat, která potřebuje.
 */
interface ModuleProvisioner
{
    /**
     * @return array<string, array{created: int, existing: int}> výsledek po
     *     skupinách záznamů; klíč je anglický popisek do výpisu `ds-upgrade`
     *     (`contract kinds`)
     */
    public function provision(ModuleProvisionContext $context): array;
}

<?php

declare(strict_types=1);

namespace Shipard\Core\Server;

use Shipard\Core\Config\DataClass;

/**
 * Jeden zdroj dat v přehledu {@see DataSourceInventory} (#126 D10) —
 * **allowlist** polí z `config/main.json` + stav ze `state.json`. Nic
 * jiného z `main.json` sem nevstupuje: heslo k databázi, `auth`, `mail`
 * ani budoucí klíče. Výpis (`shpd-server ds-list --json`, vývojářský
 * dashboard) je tak bez tajemství nezávisle na tom, zda tajemství ještě
 * leží v `main.json` (D5, task B).
 *
 * `readable = false` = `main.json` nejde přečíst nebo není JSON: název
 * `?`, ostatní pole prázdná, třída realCopy; stav ze `state.json` se čte
 * nezávisle.
 */
final readonly class DataSourceInfo
{
    /**
     * @param list<string> $modules přímé moduly z `main.json` (bez tranzitivních závislostí)
     * @param string $state efektivní stav, nebo `corrupted` u nečitelného `state.json`
     * @param array{name: string, hash: string, seededAt: ?string}|null $dataset sada ve zdroji dat (`dataset-seed`, #103 F6b D59); null = nezaznamenaná
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $created,
        public ?string $country,
        public ?string $defaultLanguage,
        public array $modules,
        public ?string $databaseName,
        public DataClass $dataClass,
        public bool $dataClassExplicit,
        public bool $dataClassInvalid,
        public ?string $purpose,
        public ?string $origin,
        public ?string $dataClassChanged,
        public string $state,
        public bool $cronEnabled,
        public bool $legacyServerAiFlag,
        public bool $readable,
        public ?array $dataset = null,
    ) {}

    /**
     * Efektivní serverové AI připojení (D9): server ho nabízí (režim
     * development + klíč, `ServerAiConnection::detect() !== null` — volající
     * zjistí jednou) a zdroj dat je `free`.
     */
    public function usesServerAi(bool $serverOffersAi): bool
    {
        return $serverOffersAi && $this->dataClass === DataClass::Free;
    }

    /**
     * Kontrakt `shpd-server ds-list --json` (čte ho Claude a skripty) —
     * pořadí a názvy klíčů jsou stabilní. `dataset` = `{name, hash, seededAt}`
     * nebo null (#103 F6b D59).
     *
     * @return array<string, mixed>
     */
    public function toArray(bool $serverOffersAi): array
    {
        return [
            'id'                => $this->id,
            'name'              => $this->name,
            'created'           => $this->created,
            'dataClass'         => $this->dataClass->value,
            'dataClassExplicit' => $this->dataClassExplicit,
            'dataClassInvalid'  => $this->dataClassInvalid,
            'purpose'           => $this->purpose,
            'origin'            => $this->origin,
            'dataClassChanged'  => $this->dataClassChanged,
            'dataset'           => $this->dataset,
            'country'           => $this->country,
            'defaultLanguage'   => $this->defaultLanguage,
            'modules'           => $this->modules,
            'databaseName'      => $this->databaseName,
            'state'             => $this->state,
            'cron'              => $this->cronEnabled,
            'serverAi'          => $this->usesServerAi($serverOffersAi),
            'readable'          => $this->readable,
        ];
    }
}

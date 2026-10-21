<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset;

/**
 * `manifest.jsonc` datové sady (`shpd.dataset.v1`).
 *
 * Datová třída bez business logiky — validace vstupu v konstruktoru,
 * `fromArray()` z rozparsovaného JSONC, `toArray()` v deterministickém
 * pořadí klíčů pro zápis.
 *
 * Fáze 1 podporuje jen `dateMode: fixed`; jiná hodnota je platná syntaxe,
 * ale konstruktor ji odmítne jako „not implemented" (rozhodnutí D4 v #40).
 *
 * Volitelná pole z #103 F2 (tasks/testing-phase2.md D22/D24): `country`
 * (země vlastní firmy — seed odmítne DS jiné země), `profile` (informativní
 * klíč profilu korpusu) a `provision` (jednorázové akce seedu:
 * `accountingItems` = `"all"` | seznam kódů nabídky osnovy). Dřívější
 * `provision.serverAi` zaniklo (#126 D9) — serverové AI připojení plyne
 * z třídy dat, kterou seed po resetu nastaví na `free`.
 */
final class DatasetManifest
{
    public const FORMAT = 'shpd.dataset.v1';

    public const DATE_MODE_FIXED = 'fixed';

    /**
     * Sekce sady v pořadí, v jakém se seedují (`bank` až za `docs` — předpisy
     * musí existovat, #131 fáze 2a; `assets` před `docs` — řádky pořízení
     * odkazují kartu inventárním číslem, #131 fáze 2c).
     */
    public const SECTIONS = ['setup', 'exchangeRates', 'persons', 'items', 'workOrders', 'assets', 'docs', 'bank', 'registry', 'mail'];

    private const NAME_PATTERN = '/^[a-z0-9][a-z0-9._-]{0,63}$/';

    /** ISO 3166-1 alpha-2 malými — stejný tvar jako `DataSourceConfig::getCountry()`. */
    private const COUNTRY_PATTERN = '/^[a-z]{2}$/';

    /** Známé klíče objektu `provision`; cokoli jiného je chyba manifestu. */
    public const PROVISION_KEYS = ['accountingItems'];

    /** `provision.accountingItems = "all"` = všechny položky nabídky podle osnovy DS. */
    public const PROVISION_ALL = 'all';

    /**
     * @param array<string, int> $counts informativní počty záznamů per sekce
     * @param array<string, mixed> $provision jen klíče z PROVISION_KEYS
     */
    public function __construct(
        public readonly string $name,
        public readonly string $title,
        public readonly ?string $description,
        public readonly string $dateMode,
        public readonly string $created,
        public readonly array $counts = [],
        public readonly ?string $country = null,
        public readonly ?string $profile = null,
        public readonly array $provision = [],
    ) {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw DatasetException::invalidManifest(
                "name '{$name}' must match [a-z0-9][a-z0-9._-]* (max 64 chars)",
            );
        }
        if (trim($title) === '') {
            throw DatasetException::invalidManifest('title must be a non-empty string');
        }
        if ($dateMode !== self::DATE_MODE_FIXED) {
            throw DatasetException::notImplemented(
                "dateMode '{$dateMode}' (phase 1 supports only '" . self::DATE_MODE_FIXED . "')",
            );
        }
        if (trim($created) === '') {
            throw DatasetException::invalidManifest('created must be a non-empty ISO 8601 timestamp');
        }
        try {
            new \DateTimeImmutable($created);
        } catch (\Exception) {
            throw DatasetException::invalidManifest("created '{$created}' is not a valid timestamp");
        }
        foreach ($counts as $section => $count) {
            if (!is_string($section) || $section === '') {
                throw DatasetException::invalidManifest('counts keys must be non-empty section names');
            }
            if (!is_int($count) || $count < 0) {
                throw DatasetException::invalidManifest("counts.{$section} must be a non-negative integer");
            }
        }
        if ($country !== null && preg_match(self::COUNTRY_PATTERN, $country) !== 1) {
            throw DatasetException::invalidManifest(
                "country '{$country}' must be a two-letter lower-case ISO 3166-1 code",
            );
        }
        if ($profile !== null && trim($profile) === '') {
            throw DatasetException::invalidManifest('profile must be a non-empty string');
        }
        foreach ($provision as $key => $value) {
            if (!is_string($key) || !in_array($key, self::PROVISION_KEYS, true)) {
                throw DatasetException::invalidManifest(
                    "provision." . (is_string($key) ? $key : (string) $key)
                    . ' is not a known provisioning action (known: ' . implode(', ', self::PROVISION_KEYS) . ')',
                );
            }
        }
        if (array_key_exists('accountingItems', $provision)) {
            $items = $provision['accountingItems'];
            $isList = is_array($items) && $items !== [] && array_is_list($items)
                && $items === array_filter($items, static fn(mixed $c): bool => is_string($c) && trim($c) !== '');
            if ($items !== self::PROVISION_ALL && !$isList) {
                throw DatasetException::invalidManifest(
                    'provision.accountingItems must be "all" or a non-empty list of offer codes',
                );
            }
        }
    }

    /**
     * Požadavek na účetní položky z nabídky osnovy: `"all"`, seznam kódů,
     * nebo null = sada o ně nežádá.
     *
     * @return list<string>|'all'|null
     */
    public function accountingItemsRequest(): array|string|null
    {
        $items = $this->provision['accountingItems'] ?? null;
        if ($items === self::PROVISION_ALL) {
            return self::PROVISION_ALL;
        }
        return is_array($items) ? array_values($items) : null;
    }

    /**
     * @param array<string, mixed> $data rozparsovaný manifest.jsonc
     */
    public static function fromArray(array $data): self
    {
        $format = $data['format'] ?? null;
        if ($format !== self::FORMAT) {
            $shown = is_scalar($format) ? (string) $format : gettype($format);
            throw DatasetException::invalidManifest(
                "format must be '" . self::FORMAT . "', got '{$shown}'",
            );
        }

        foreach (['name', 'title', 'dateMode', 'created'] as $key) {
            if (!isset($data[$key]) || !is_string($data[$key])) {
                throw DatasetException::invalidManifest("{$key} is required and must be a string");
            }
        }
        if (array_key_exists('description', $data) && $data['description'] !== null && !is_string($data['description'])) {
            throw DatasetException::invalidManifest('description must be a string or null');
        }
        $counts = $data['counts'] ?? [];
        if (!is_array($counts)) {
            throw DatasetException::invalidManifest('counts must be an object');
        }
        foreach (['country', 'profile'] as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null && !is_string($data[$key])) {
                throw DatasetException::invalidManifest("{$key} must be a string or null");
            }
        }
        // `{}` z JsoncParseru (assoc) je `[]` — žádná akce, ne chyba.
        $provision = $data['provision'] ?? [];
        if (!is_array($provision)) {
            throw DatasetException::invalidManifest('provision must be an object');
        }

        /** @var array<string, int> $counts */
        return new self(
            name: $data['name'],
            title: $data['title'],
            description: $data['description'] ?? null,
            dateMode: $data['dateMode'],
            created: $data['created'],
            counts: $counts,
            country: $data['country'] ?? null,
            profile: $data['profile'] ?? null,
            provision: $provision,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [
            'format'   => self::FORMAT,
            'name'     => $this->name,
            'title'    => $this->title,
        ];
        if ($this->description !== null) {
            $out['description'] = $this->description;
        }
        $out['dateMode'] = $this->dateMode;
        $out['created'] = $this->created;
        if ($this->country !== null) {
            $out['country'] = $this->country;
        }
        if ($this->profile !== null) {
            $out['profile'] = $this->profile;
        }
        if ($this->provision !== []) {
            $out['provision'] = $this->provision;
        }
        $out['counts'] = $this->counts === [] ? new \stdClass() : $this->counts;

        return $out;
    }

    /**
     * Kopie manifestu s jinými počty (dump zná počty až po exportu).
     *
     * @param array<string, int> $counts
     */
    public function withCounts(array $counts): self
    {
        return new self(
            name: $this->name,
            title: $this->title,
            description: $this->description,
            dateMode: $this->dateMode,
            created: $this->created,
            counts: $counts,
            country: $this->country,
            profile: $this->profile,
            provision: $this->provision,
        );
    }
}

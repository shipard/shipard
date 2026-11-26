<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration;

use Shipard\Core\Config\ConfigRuntime;

/**
 * Registr typů evidence tržeb zdroje dat (#171 D2, D17) — jediné čtení
 * cfgItemů `economy.salesRegistration.engines` (typ → třída engine, deklaruje
 * doplněk) a `world.<země>.salesRegistrationTypes` (data typu, deklaruje
 * modul země).
 *
 * Skládá se od mapy enginů: země je první část id typu (`cz.eet2` →
 * `world.cz.…`), takže není potřeba výčet aktivních modulů zemí ani cfgItemů.
 * Typ bez enginu se tím nenabízí sám od sebe, záznam mapy bez dat typu
 * (modul země není ve zdroji dat aktivní) taky ne. Vadná položka se
 * přeskočí — registrace takového typu pak neprojde validací, nic nespadne.
 */
final class SalesRegistrationTypes
{
    public const string ENGINES_CFG = 'economy.salesRegistration.engines';

    /** Tvar id typu: země (dvě písmena), tečka, zbytek. */
    private const string ID_PATTERN = '/^([a-z]{2})\.[a-z0-9]+(\.[a-z0-9]+)*$/D';

    /** @param list<SalesRegistrationType> $types */
    public function __construct(private readonly array $types)
    {
    }

    public static function typesCfgItemId(string $country): string
    {
        return 'world.' . strtolower($country) . '.salesRegistrationTypes';
    }

    public static function fromConfig(?ConfigRuntime $config): self
    {
        $engines = $config?->cfgItem(self::ENGINES_CFG);
        $types = [];
        if (is_array($engines)) {
            foreach ($engines as $id => $engineClass) {
                if (!is_string($id) || !is_string($engineClass) || $engineClass === ''
                    || preg_match(self::ID_PATTERN, $id, $m) !== 1) {
                    continue;
                }
                $countryTypes = $config->cfgItem(self::typesCfgItemId($m[1]));
                $entry = is_array($countryTypes) ? ($countryTypes[$id] ?? null) : null;
                $type = is_array($entry) ? self::parse($id, $m[1], $entry, $engineClass) : null;
                if ($type !== null) {
                    $types[] = $type;
                }
            }
        }
        return new self($types);
    }

    /** @param array<string, mixed> $entry */
    private static function parse(string $id, string $country, array $entry, string $engineClass): ?SalesRegistrationType
    {
        $environments = [];
        foreach (is_array($entry['environments'] ?? null) ? $entry['environments'] : [] as $name => $env) {
            $url = is_array($env) ? ($env['url'] ?? null) : null;
            if (is_string($name) && is_string($url) && str_starts_with($url, 'https://')) {
                $environments[$name] = $url;
            }
        }
        $taxpayerPattern = self::pattern($entry['taxpayerId'] ?? null);
        $unitPattern = self::pattern($entry['unitId'] ?? null);
        $currency = strtolower(trim((string) ($entry['currency'] ?? '')));
        if ($environments === [] || $taxpayerPattern === null || $unitPattern === null || $currency === '') {
            return null;
        }

        // Pravidla, kdy doklad do evidence spadá (D8a, D23). Typ bez pravidel
        // nic z dokladů neeviduje; vadné pravidlo vyřadí celý typ — tiše
        // vynechané pravidlo by znamenalo neodeslané tržby.
        $rules = [];
        foreach (is_array($entry['rules'] ?? null) ? $entry['rules'] : [] as $rawRule) {
            $rule = SaleRule::fromArray($rawRule);
            if ($rule === null) {
                return null;
            }
            $rules[] = $rule;
        }

        $validFrom = $entry['validFrom'] ?? null;
        $label = is_array($entry['taxpayerId'] ?? null) ? ($entry['taxpayerId']['label'] ?? null) : null;
        $timeZone = $entry['timeZone'] ?? null;
        $registerHint = is_array($entry['cashRegisterId'] ?? null) ? ($entry['cashRegisterId']['hint'] ?? null) : null;

        return new SalesRegistrationType(
            id: $id,
            name: is_string($entry['name'] ?? null) && $entry['name'] !== '' ? $entry['name'] : $id,
            country: $country,
            validFrom: is_string($validFrom) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $validFrom) === 1 ? $validFrom : null,
            currency: $currency,
            environments: $environments,
            taxpayerIdLabel: is_string($label) && $label !== '' ? $label : 'Taxpayer ID',
            taxpayerIdPattern: $taxpayerPattern,
            unitIdPattern: $unitPattern,
            cashRegisterIdPattern: self::pattern($entry['cashRegisterId'] ?? null),
            serialNumberPattern: self::pattern($entry['serialNumber'] ?? null),
            engineClass: $engineClass,
            rules: $rules,
            timeZone: is_string($timeZone) && $timeZone !== '' ? $timeZone : null,
            amountPattern: self::pattern($entry['amount'] ?? null),
            cashRegisterIdHint: is_string($registerHint) && $registerHint !== '' ? $registerHint : null,
        );
    }

    private static function pattern(mixed $raw): ?string
    {
        $pattern = is_array($raw) ? ($raw['pattern'] ?? null) : null;
        return is_string($pattern) && SalesRegistrationType::isUsablePattern($pattern) ? $pattern : null;
    }

    /** @return list<SalesRegistrationType> */
    public function all(): array
    {
        return $this->types;
    }

    public function isEmpty(): bool
    {
        return $this->types === [];
    }

    public function get(string $id): ?SalesRegistrationType
    {
        foreach ($this->types as $type) {
            if ($type->id === $id) {
                return $type;
            }
        }
        return null;
    }

    /** Lokalizovaný název typu, u neznámého typu jeho id. */
    public function labelOf(string $id): string
    {
        return $this->get($id)?->name ?? $id;
    }
}

<?php

declare(strict_types=1);

namespace Shipard\Module\Facility\Buildings;

use Shipard\Core\Config\ConfigRuntime;

/**
 * Jediné čtení cfgItem `facility.buildings.contractTypes` (#174 D4, D17).
 * Kód se nikdy neptá na klíč typu (`=== 'office'`), jen na vlastnosti odsud:
 * ikona, místa na smlouvě (povinná / volitelná / žádná), povolené typy míst
 * a kód výchozího druhu.
 *
 * Bez zkompilované konfigurace (testy, CLI bez configu) vrací lenivé
 * defaulty: místa jsou volitelná, libovolného typu, a nic není neznámé —
 * degradace, ne crash.
 */
final class ContractTypes
{
    public const CFG_ITEM = 'facility.buildings.contractTypes';

    /** Místa jsou povinná při potvrzení smlouvy. */
    public const PLACES_REQUIRED = 'required';
    public const PLACES_OPTIONAL = 'optional';
    /** Smlouva místa nemá — pole je skryté a místa jsou chyba. */
    public const PLACES_NONE = 'none';

    /** @var array<string, array<string, mixed>>|null */
    private ?array $all = null;

    public function __construct(private readonly ?ConfigRuntime $config)
    {
    }

    /** @return array<string, array<string, mixed>> klíč typu → definice, podle `order` */
    public function all(): array
    {
        if ($this->all !== null) {
            return $this->all;
        }
        $cfg = $this->config?->cfgItem(self::CFG_ITEM);
        $all = [];
        foreach (is_array($cfg) ? $cfg : [] as $key => $entry) {
            if (is_array($entry)) {
                $all[(string) $key] = $entry;
            }
        }
        uasort(
            $all,
            static fn (array $a, array $b): int => (int) ($a['order'] ?? 1000) <=> (int) ($b['order'] ?? 1000),
        );
        return $this->all = $all;
    }

    /** True, když je konfigurace k dispozici a klíč v ní není. */
    public function isUnknown(string $key): bool
    {
        $all = $this->all();
        return $all !== [] && !isset($all[$key]);
    }

    public function label(string $key): string
    {
        return (string) ($this->all()[$key]['name'] ?? $key);
    }

    /** Klíč ikony typu (frontend `iconMap`), null bez konfigurace. */
    public function icon(string $key): ?string
    {
        $icon = $this->all()[$key]['icon'] ?? null;
        return is_string($icon) && $icon !== '' ? $icon : null;
    }

    /**
     * Místa na smlouvě typu: {@see self::PLACES_REQUIRED},
     * {@see self::PLACES_OPTIONAL} nebo {@see self::PLACES_NONE}. Chybějící
     * i neznámá hodnota = volitelná.
     */
    public function places(string $key): string
    {
        $places = $this->all()[$key]['places'] ?? null;
        return in_array($places, [self::PLACES_REQUIRED, self::PLACES_NONE], true)
            ? $places
            : self::PLACES_OPTIONAL;
    }

    /**
     * Povolené typy míst (`base.places.types`); null = libovolný typ.
     *
     * @return list<string>|null
     */
    public function placeTypes(string $key): ?array
    {
        $placeTypes = $this->all()[$key]['placeTypes'] ?? null;
        if (!is_array($placeTypes)) {
            return null;
        }
        $list = [];
        foreach ($placeTypes as $placeType) {
            if (is_string($placeType) && $placeType !== '') {
                $list[] = $placeType;
            }
        }
        return $list;
    }

    /** Smí být místo typu `$placeType` na smlouvě typu `$key`? */
    public function allowsPlaceType(string $key, string $placeType): bool
    {
        $allowed = $this->placeTypes($key);
        return $allowed === null || in_array($placeType, $allowed, true);
    }

    /** Kód výchozího druhu (`%C`); null = typ výchozí druh nemá (D19). */
    public function seedCode(string $key): ?string
    {
        $code = $this->all()[$key]['seedCode'] ?? null;
        return is_string($code) && trim($code) !== '' ? trim($code) : null;
    }

    /** @return list<array{value: string, label: string}> volby selectu podle `order` */
    public function options(): array
    {
        $options = [];
        foreach (array_keys($this->all()) as $key) {
            $options[] = ['value' => $key, 'label' => $this->label($key)];
        }
        return $options;
    }
}

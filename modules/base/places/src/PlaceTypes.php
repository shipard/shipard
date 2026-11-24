<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Places;

use Shipard\Core\Config\ConfigRuntime;

/**
 * Jediné čtení cfgItem `base.places.types` (#169 D2, D3; docs/places.md).
 * Kód se nikdy neptá na klíč typu (`=== 'room'`), jen na vlastnosti odsud:
 * úroveň v hierarchii, ikona, příznak adresy.
 *
 * Bez zkompilované konfigurace (testy, CLI bez configu) vrací lenivé
 * defaulty: typ nemá úroveň ani adresu a nic není neznámé — pravidlo úrovní
 * pak neplatí, což je degradace, ne crash.
 */
final class PlaceTypes
{
    public const CFG_ITEM = 'base.places.types';

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

    /** Úroveň typu v hierarchii; null = typ bez pravidla úrovní. */
    public function level(string $key): ?int
    {
        $level = $this->all()[$key]['level'] ?? null;
        return is_int($level) || (is_string($level) && is_numeric($level)) ? (int) $level : null;
    }

    /** Klíč ikony typu (frontend `iconMap`), null bez konfigurace. */
    public function icon(string $key): ?string
    {
        $icon = $this->all()[$key]['icon'] ?? null;
        return is_string($icon) && $icon !== '' ? $icon : null;
    }

    /** Místo tohoto typu nese adresu (D13). */
    public function hasAddress(string $key): bool
    {
        return (bool) ($this->all()[$key]['hasAddress'] ?? false);
    }

    /**
     * Smí být místo typu `$parentType` nadřazené místu typu `$childType`
     * (D3)? Nadřazené musí mít nižší úroveň, přeskakování je povolené;
     * typ bez úrovně na kterékoli straně pravidlo vypíná. Cyklus tohle
     * neřeší — ten hlídá PlaceDocument vždy.
     */
    public function canBeParent(string $parentType, string $childType): bool
    {
        $parentLevel = $this->level($parentType);
        $childLevel = $this->level($childType);
        if ($parentLevel === null || $childLevel === null) {
            return true;
        }
        return $parentLevel < $childLevel;
    }

    /** @return list<array{value: string, label: string}> nabídka pro select */
    public function options(): array
    {
        $options = [];
        foreach ($this->all() as $key => $entry) {
            if (isset($entry['name'])) {
                $options[] = ['value' => (string) $key, 'label' => (string) $entry['name']];
            }
        }
        return $options;
    }
}

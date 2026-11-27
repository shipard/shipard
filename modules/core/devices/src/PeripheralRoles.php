<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Devices;

use Shipard\Core\Config\ConfigRuntime;

/**
 * Jediné čtení cfgItem `core.devices.peripheralRoles` (#177 U19, U23).
 * Kód se neptá na klíč role (`=== 'receipt'`), jen na její vlastnosti:
 * kterému druhu periferie jde přiřadit a jestli smí být na zařízení jen
 * jednou. Nová role (`a4`, štítky) je tak jen řádek konfigurace.
 *
 * Bez zkompilované konfigurace (testy, CLI bez configu) není žádná role
 * známá a pravidla neplatí — degradace, ne pád.
 */
final class PeripheralRoles
{
    public const CFG_ITEM = 'core.devices.peripheralRoles';

    /** @var array<string, array<string, mixed>>|null */
    private ?array $all = null;

    public function __construct(private readonly ?ConfigRuntime $config)
    {
    }

    /** @return array<string, array<string, mixed>> klíč role → definice */
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
        return $this->all = $all;
    }

    /** True, když je konfigurace k dispozici a role v ní není. */
    public function isUnknown(string $role): bool
    {
        $all = $this->all();
        return $all !== [] && !isset($all[$role]);
    }

    public function label(string $role): string
    {
        return (string) ($this->all()[$role]['name'] ?? $role);
    }

    /** Jde roli přiřadit periferii tohoto druhu? Role bez `kinds` platí pro každý druh. */
    public function allowsKind(string $role, string $kind): bool
    {
        $kinds = $this->all()[$role]['kinds'] ?? null;
        if (!is_array($kinds) || $kinds === []) {
            return true;
        }
        return in_array($kind, $kinds, true);
    }

    /** Smí roli na jednom zařízení nést nejvýš jedna periferie? */
    public function isSingle(string $role): bool
    {
        return ($this->all()[$role]['single'] ?? false) === true;
    }
}

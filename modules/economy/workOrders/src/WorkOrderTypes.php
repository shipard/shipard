<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\WorkOrders;

use Shipard\Core\Config\ConfigRuntime;

/**
 * Jediné čtení cfgItem `economy.workOrders.types` (docs/work-orders.md D14,
 * §5.1). Kód se nikdy neptá na klíč typu (`=== 'periodic'`), jen na příznaky
 * odsud: `external` (zákazník, měna, VS), `oneOff` (smí mít nadřazenou
 * zakázku), `invoicing` (periodická fakturace, fáze 2).
 *
 * Bez zkompilované konfigurace (testy, CLI bez configu) vrací lenivé
 * defaulty: typ není externí ani jednorázový, bez fakturace — validace
 * závislé na typu pak neběží, což je degradace, ne crash.
 */
final class WorkOrderTypes
{
    public const CFG_ITEM = 'economy.workOrders.types';
    public const INVOICING_PERIODIC = 'periodic';

    public function __construct(private readonly ?ConfigRuntime $config)
    {
    }

    /** @return array<string, array<string, mixed>> klíč typu → definice */
    public function all(): array
    {
        $cfg = $this->config?->cfgItem(self::CFG_ITEM);
        return is_array($cfg) ? $cfg : [];
    }

    /** True, když je konfigurace k dispozici a klíč v ní není. */
    public function isUnknown(string $key): bool
    {
        $all = $this->all();
        return $all !== [] && !isset($all[$key]);
    }

    /** Externí typ: zakázka má zákazníka (povinný při potvrzení), měnu a pevný VS. */
    public function isExternal(string $key): bool
    {
        return (bool) ($this->all()[$key]['external'] ?? false);
    }

    /** Jednorázový typ: zakázka smí mít nadřazenou zakázku (D15). */
    public function isOneOff(string $key): bool
    {
        return (bool) ($this->all()[$key]['oneOff'] ?? false);
    }

    /** Způsob fakturace typu (`periodic`), null = zakázka nic nevystavuje. */
    public function invoicing(string $key): ?string
    {
        $value = $this->all()[$key]['invoicing'] ?? null;
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Smí být zakázka tohoto typu nadřazenou (P4)? Libovolný typ kromě
     * fakturujícího — pod nájemní smlouvou se náklady podzakázek nesčítají.
     */
    public function canBeParent(string $key): bool
    {
        return $this->invoicing($key) === null;
    }

    public function label(string $key): string
    {
        return (string) ($this->all()[$key]['name'] ?? $key);
    }

    /** @return list<array{value: string, label: string}> nabídka pro select */
    public function options(): array
    {
        $options = [];
        foreach ($this->all() as $key => $entry) {
            if (is_array($entry) && isset($entry['name'])) {
                $options[] = ['value' => (string) $key, 'label' => (string) $entry['name']];
            }
        }
        return $options;
    }
}

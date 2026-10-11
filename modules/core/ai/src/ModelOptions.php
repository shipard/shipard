<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Ai;

use Shipard\Core\Ai\ModelCatalog;
use Shipard\Core\Ai\ModelSpec;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Form\EnumOptionsHelper;

/**
 * Nabídky pro pole model / ladění ve formulářích AI funkce a AI profilu
 * (tasks/ai-functions.md D43, D44): modely katalogu s úrovní, hodnota
 * mimo katalog (vlastní ID) jako vlastní položka, aby ji select
 * s `allowCustom` zobrazil; thinking / effort z cfgItemů vč. `default`.
 */
final class ModelOptions
{
    /**
     * Modely katalogu, které nabízíme (s úrovní), seřazené podle `order`.
     * Vyřazené modely nesou datum konce; `$current` mimo nabídku se přidá
     * jako další položka, aby formulář uloženou hodnotu neztratil — ID
     * platformy, které katalog zná (`eu.anthropic.…`), s názvem modelu,
     * neznámé ID jako „vlastní ID“.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function catalogModels(ModelCatalog $catalog, ?string $current = null): array
    {
        $options = [];
        $seen = [];
        foreach ($catalog->all() as $spec) {
            if ($spec->tier === null) {
                continue;
            }
            $options[] = ['value' => $spec->id, 'label' => self::modelLabel($spec)];
            $seen[$spec->id] = true;
        }
        $current = $current !== null ? trim($current) : '';
        if ($current !== '' && !isset($seen[$current])) {
            $spec = $catalog->find($current);
            $options[] = ['value' => $current, 'label' => $spec !== null ? "{$current} ({$spec->name})" : "{$current} (vlastní ID)"];
        }
        return $options;
    }

    public static function modelLabel(ModelSpec $spec): string
    {
        $label = "{$spec->name} ({$spec->id})";
        if ($spec->isRetiring() && $spec->retiresAt !== null) {
            $label .= " — končí {$spec->retiresAt}";
        }
        return $label;
    }

    /**
     * Nabídka cfgItemu ladění (`core.ai.thinkingModes` / `effortLevels`)
     * vč. `default` a `auto`; bez configu jen tyto dvě hodnoty.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function tuning(?ConfigRuntime $config, string $cfgItemId): array
    {
        $cfg = $config?->cfgItem($cfgItemId);
        if (is_array($cfg) && $cfg !== []) {
            return EnumOptionsHelper::fromCfgData($cfg, 'enumString', $cfgItemId);
        }
        return [
            ['value' => 'default', 'label' => 'By function (default)'],
            ['value' => 'auto', 'label' => 'Model default (not sent)'],
        ];
    }
}

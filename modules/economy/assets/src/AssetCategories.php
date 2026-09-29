<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets;

use Shipard\Core\Config\ConfigRuntime;

/**
 * Jediné čtení cfgItem `economy.assets.categories` (D19). Kód se nikdy
 * neptá na klíč druhu (`=== 'tangible'`), jen na příznaky odsud.
 *
 * Bez zkompilované konfigurace (testy, CLI bez configu) vrací lenivé
 * defaulty: druh není dlouhodobý, prefix DEFAULT_PREFIX — validace
 * závislé na druhu pak neběží, což je degradace, ne crash.
 */
final class AssetCategories
{
    public const CFG_ITEM = 'economy.assets.categories';
    public const DEFAULT_PREFIX = 'MA';

    /** Settings klíč per druh: `economy.assets.numberPrefix.<klíč druhu>`. */
    public const PREFIX_SETTING = 'economy.assets.numberPrefix.';

    public function __construct(private readonly ?ConfigRuntime $config)
    {
    }

    /** @return array<string, array<string, mixed>> klíč druhu → definice */
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

    public function isLongTerm(string $key): bool
    {
        return (bool) ($this->all()[$key]['longTerm'] ?? false);
    }

    public function isDepreciable(string $key): bool
    {
        return (bool) ($this->all()[$key]['depreciable'] ?? false);
    }

    public function label(string $key): string
    {
        return (string) ($this->all()[$key]['name'] ?? $key);
    }

    /** Výchozí prefix inventárního čísla z cfgItem (fallback DEFAULT_PREFIX). */
    public function defaultNumberPrefix(string $key): string
    {
        $prefix = trim((string) ($this->all()[$key]['numberPrefix'] ?? ''));
        return $prefix !== '' ? $prefix : self::DEFAULT_PREFIX;
    }
}

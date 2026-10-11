<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Ai;

use Shipard\Core\Ai\ModelCatalog;
use Shipard\Core\Viewer\TableViewer;

/**
 * Společný základ viewerů AI v Nastavení (Modely, AI funkce): popisky
 * z cfgItem `core.ai.viewerLabels` (lokalizované kompilací konfigurace),
 * katalog modelů, názvy úrovní a hodnot ladění z cfgItemů, formát data.
 * Vzor `WorkOrdersViewerBase`.
 */
abstract class AiViewerBase extends TableViewer
{
    public const string LABELS_CFG_ITEM = 'core.ai.viewerLabels';

    private ?ModelCatalog $catalog = null;

    /**
     * Popisek z cfgItem `core.ai.viewerLabels`; bez konfigurace anglický
     * fallback. `{param}` v textu se nahradí z `$params`.
     *
     * @param array<string, scalar> $params
     */
    protected function text(string $key, string $englishFallback, array $params = []): string
    {
        $defs = $this->config?->cfgItem(self::LABELS_CFG_ITEM);
        $text = is_array($defs) ? (string) ($defs[$key]['name'] ?? $englishFallback) : $englishFallback;
        foreach ($params as $name => $value) {
            $text = str_replace('{' . $name . '}', (string) $value, $text);
        }
        return $text;
    }

    protected function catalog(): ModelCatalog
    {
        return $this->catalog ??= ModelCatalog::fromConfig($this->config);
    }

    /** Název úrovně z mapy úrovní (`core.ai.tiers`); bez mapy surový kód. */
    protected function tierLabel(string $tier): string
    {
        return $this->catalog()->tiers()[$tier]['name'] ?? $tier;
    }

    /**
     * Popisek hodnoty ladění z cfgItemu (`core.ai.thinkingModes` /
     * `core.ai.effortLevels`, `name` po lokalizaci); bez konfigurace
     * surová hodnota, prázdná hodnota null.
     */
    protected function tuningLabel(string $cfgItemId, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = (string) $value;
        $cfg = $this->config?->cfgItem($cfgItemId);
        $entry = is_array($cfg) ? ($cfg[$value] ?? null) : null;
        return is_array($entry) && isset($entry['name']) ? (string) $entry['name'] : $value;
    }

    /** Název platformy z cfgItemu `core.ai.platforms`; bez konfigurace klíč. */
    protected function platformLabel(string $platform): string
    {
        $cfg = $this->config?->cfgItem('core.ai.platforms');
        $entry = is_array($cfg) ? ($cfg[$platform] ?? null) : null;
        return is_array($entry) && isset($entry['name']) ? (string) $entry['name'] : $platform;
    }

    /** Datum `YYYY-MM-DD` pro uživatele: česky `30. 11. 2026`, jinak ISO. */
    protected function formatDate(?string $date): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }
        if ($this->language !== 'cs') {
            return $date;
        }
        try {
            return (new \DateTimeImmutable($date))->format('j. n. Y');
        } catch (\Exception) {
            return $date;
        }
    }

    /** @param array<int, array{label: string, value: string}> $items */
    protected function addItem(array &$items, string $label, mixed $value): void
    {
        if ($value !== null && $value !== '') {
            $items[] = ['label' => $label, 'value' => (string) $value];
        }
    }
}

<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Places;

use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Viewer\TableViewer;

/**
 * Společný základ viewerů modulu (místa, druhy míst): sada stavů s archivem,
 * filtr viewGroup, popisky z katalogu `base.places.viewerLabels` a ikona
 * podle druhu. Vzor WorkOrdersViewerBase.
 */
abstract class PlacesViewerBase extends TableViewer
{
    public const LABELS_CFG_ITEM = 'base.places.viewerLabels';

    protected ?string $docStatesCfgItem = 'core.system.docStatesArchive';

    private ?PlaceTypes $types = null;

    /**
     * Popisek z cfgItem `base.places.viewerLabels` — viz `TableViewer::labelText()`.
     *
     * @param array<string, scalar> $params
     */
    protected function text(string $key, string $englishFallback, array $params = []): string
    {
        return $this->labelText(self::LABELS_CFG_ITEM, $key, $englishFallback, $params);
    }

    /** Typy míst, jednou za požadavek — strom se na ně ptá u každého řádku. */
    protected function types(): PlaceTypes
    {
        return $this->types ??= new PlaceTypes($this->config);
    }

    /** Ikona místa nebo druhu: ikona druhu, bez ní ikona typu (D14); null = ikona vieweru. */
    protected function kindIcon(mixed $kindIcon, mixed $type): ?string
    {
        $icon = trim((string) ($kindIcon ?? ''));
        if ($icon !== '') {
            return $icon;
        }
        $type = (string) ($type ?? '');
        return $type !== '' ? $this->types()->icon($type) : null;
    }

    /**
     * @param list<array{id: string, value: mixed}> $filters
     * @return array{0: list<string>, 1: list<mixed>} [conditions, params]
     */
    protected function viewGroupCondition(array $filters, string $alias): array
    {
        $viewGroup = 'active';
        foreach ($filters as $filter) {
            if (($filter['id'] ?? null) === 'viewGroup') {
                $viewGroup = (string) $filter['value'];
            }
        }
        if ($viewGroup === 'all' || $this->docStatesCfgItem === null) {
            return [[], []];
        }
        [$sql, $params] = $this->buildViewGroupFilter($this->docStatesCfgItem, $viewGroup);
        return $sql === '' ? [[], []] : [[$alias . '.' . $sql], $params];
    }

    protected function stateStyleOf(int $docState): string
    {
        if ($this->config === null || $this->docStatesCfgItem === null) {
            return 'concept';
        }
        $cfg = DocStateConfig::fromCfgItem($this->config->cfgItem($this->docStatesCfgItem));
        return (string) ($cfg->getState($docState)['stateStyle'] ?? 'concept');
    }

    /** @param list<array{label: string, value: string}> $items */
    protected function addItem(array &$items, string $label, mixed $value): void
    {
        if ($value !== null && $value !== '') {
            $items[] = ['label' => $label, 'value' => (string) $value];
        }
    }

    /** Název hodnoty cfgItem tvaru `{klíč: {name}}`, bez konfigurace klíč. */
    protected function cfgLabel(string $cfgItemId, string $key): string
    {
        if ($key === '') {
            return '';
        }
        $cfg = $this->config?->cfgItem($cfgItemId);
        return is_array($cfg) ? (string) ($cfg[$key]['name'] ?? $key) : $key;
    }
}

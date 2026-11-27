<?php

declare(strict_types=1);

namespace Shipard\Module\Facility\Buildings;

use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Viewer\TableViewer;

/**
 * Společný základ viewerů doplňku (druhy smluv, smlouvy): texty z katalogu
 * `facility.buildings.viewerLabels`, typy smluv, filtr viewGroup a styl
 * stavu řádku. Vzor WorkOrdersViewerBase.
 */
abstract class BuildingsViewerBase extends TableViewer
{
    public const LABELS_CFG_ITEM = 'facility.buildings.viewerLabels';

    protected ?string $docStatesCfgItem = 'core.system.docStatesArchive';

    /**
     * Popisek z cfgItem `facility.buildings.viewerLabels` — viz `TableViewer::labelText()`.
     *
     * @param array<string, scalar> $params
     */
    protected function text(string $key, string $englishFallback, array $params = []): string
    {
        return $this->labelText(self::LABELS_CFG_ITEM, $key, $englishFallback, $params);
    }

    protected function types(): ContractTypes
    {
        return new ContractTypes($this->config);
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

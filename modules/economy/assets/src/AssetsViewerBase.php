<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets;

use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Settings\SettingsStore;
use Shipard\Core\Viewer\TableViewer;

/**
 * Společný základ viewerů modulu (karta + tři číselníky): archivní sada
 * stavů, filtr viewGroup, badge stavu a formátování hodnot v řádku
 * a detailu. Vzor CashDesksViewer, jen bez opakování per viewer.
 */
abstract class AssetsViewerBase extends TableViewer
{
    public const LABELS_CFG_ITEM = 'economy.assets.viewerLabels';

    protected ?string $docStatesCfgItem = 'core.system.docStatesArchive';

    private ?AssetPlanService $planService = null;

    protected const STATE_SPAN_CLASS = [
        'concept'   => 'warning',
        'confirmed' => 'primary',
        'done'      => 'success',
        'edit'      => 'warning',
        'archive'   => 'muted',
        'trash'     => 'muted',
        'cancelled' => 'danger',
    ];

    protected function cs(): bool
    {
        return $this->language !== 'en';
    }

    /**
     * Popisek z cfgItem `economy.assets.viewerLabels` (lokalizovaný
     * kompilací); bez konfigurace anglický fallback. `{param}` v textu se
     * nahradí z `$params`.
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

    /** Most k enginu odpisů — stát z konfigurace zdroje dat, četnost z nastavení. */
    protected function planService(): AssetPlanService
    {
        return $this->planService ??= new AssetPlanService(
            $this->db->getDibiConnection(),
            $this->config,
            $this->dsConfig?->getCountry() ?? 'cz',
            new SettingsStore($this->db),
        );
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

    /** @return array{text: string, class: string}|null badge stavu mimo Koncept */
    protected function stateBadge(int $docState): ?array
    {
        if ($docState === 10 || $this->config === null || $this->docStatesCfgItem === null) {
            return null;
        }
        $cfg   = DocStateConfig::fromCfgItem($this->config->cfgItem($this->docStatesCfgItem));
        $state = $cfg->getState($docState);
        $style = (string) ($state['stateStyle'] ?? 'concept');
        return [
            'text'  => (string) ($state['stateName'] ?? ''),
            'class' => static::STATE_SPAN_CLASS[$style] ?? 'muted',
        ];
    }

    protected function formatDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('d.m.Y');
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', substr((string) $value, 0, 10));
        return $dt instanceof \DateTimeImmutable ? $dt->format('d.m.Y') : (string) $value;
    }

    protected function formatAmount(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return number_format((float) $value, 2, ',', ' ');
    }

    /** @param list<array{label: string, value: string}> $items */
    protected function addItem(array &$items, string $label, mixed $value): void
    {
        if ($value !== null && $value !== '') {
            $items[] = ['label' => $label, 'value' => (string) $value];
        }
    }

    /**
     * Detail s jediným tabem Přehled nad skupinami vlastností.
     *
     * @param list<array{title: string, items: list<array{label: string, value: string}>}> $groups
     * @return array{tabs: list<array<string, mixed>>}
     */
    protected function overviewDetail(array $groups): array
    {
        $groups = array_values(array_filter($groups, static fn(array $g): bool => $g['items'] !== []));
        return [
            'tabs' => [[
                'id'      => 'overview',
                'label'   => $this->defaultOverviewLabel(),
                'content' => ['type' => 'properties', 'groups' => $groups],
            ]],
        ];
    }

    protected function yesNo(mixed $value): string
    {
        return !empty($value) ? ($this->cs() ? 'Ano' : 'Yes') : ($this->cs() ? 'Ne' : 'No');
    }
}

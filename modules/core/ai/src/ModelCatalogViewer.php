<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Ai;

use Shipard\Core\Ai\ModelSpec;
use Shipard\Core\Viewer\Detail\DetailHeader;

/**
 * Přehled katalogu modelů jen pro čtení (tasks/ai-settings-section.md
 * D50): viewer **bez tabulky** — řádky jsou modely s úrovní z cfgItemu
 * `core.ai.models`, položky jen s ceníkem (`tier: null`) se neukazují.
 * Řazení podle pořadí úrovně (mapa `core.ai.tiers`) a pak `order`
 * modelu; **id řádku = pozice v tomto seznamu od 1** (detail route bere
 * jen kladné int), takže `loadRecord()` vrací syntetický záznam bez
 * databáze; hlavičku detailu (název, dodavatel · id) dodává viewer, bez
 * tabulky není displayPattern. Bez formuláře a bez akcí.
 */
class ModelCatalogViewer extends AiViewerBase
{
    /** @var list<array{id: int, spec: ModelSpec, tiers: list<string>, platformTiers: array<string, list<string>>}>|null */
    private ?array $rows = null;

    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        $rows = $this->rows();
        $needle = $search !== null ? trim($search) : '';
        if ($needle !== '') {
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => mb_stripos($row['spec']->name, $needle) !== false
                    || mb_stripos($row['spec']->id, $needle) !== false,
            ));
        }
        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        return array_slice($rows, $offset, $limit);
    }

    public function renderRow(array $rowData): array
    {
        /** @var ModelSpec $spec */
        $spec = $rowData['spec'];
        $badges = [];
        if ($spec->tier !== null) {
            $badges[] = ['text' => $this->tierLabel($spec->tier), 'class' => 'muted'];
        }
        if ($spec->status === ModelSpec::STATUS_RETIRED) {
            $badges[] = ['text' => $this->text('badge.retired', 'Retired'), 'class' => 'danger'];
        } elseif ($spec->isRetiring()) {
            $badges[] = [
                'text' => $this->text('badge.retiring', 'Retires {date}', ['date' => $this->formatDate($spec->retiresAt) ?? '?']),
                'class' => 'warning',
            ];
        }
        if ($rowData['tiers'] !== []) {
            $badges[] = [
                'text' => $this->text('badge.defaultFor', 'Default for {tiers}', ['tiers' => $this->tierNames($rowData['tiers'])]),
                'class' => 'primary',
            ];
        }
        // Model úrovně jen pro některou platformu (tasks/ai-bedrock-eu.md D58).
        foreach ($rowData['platformTiers'] ?? [] as $platform => $tiers) {
            $badges[] = [
                'text' => $this->text('badge.defaultForOn', 'Default for {tiers} on {platform}', [
                    'tiers' => $this->tierNames($tiers), 'platform' => $this->platformLabel((string) $platform),
                ]),
                'class' => 'primary',
            ];
        }

        $prices = $spec->hasPrices()
            ? $this->text('row.prices', 'in {input} · out {output} / 1M tokens', [
                'input' => self::usd((float) $spec->priceInput),
                'output' => self::usd((float) $spec->priceOutput),
            ])
            : null;

        return [
            'id' => (int) $rowData['id'],
            't1' => $spec->name,
            'i1' => $badges,
            't2' => $prices !== null ? ['text' => $prices, 'class' => 'muted'] : null,
            't3' => $spec->id,
        ];
    }

    /** Syntetický záznam: pozice v seřazeném seznamu od 1; mimo rozsah null (404). */
    public function loadRecord(int $recordId): ?array
    {
        return $this->rows()[$recordId - 1] ?? null;
    }

    protected function detailHeader(array $record): DetailHeader
    {
        /** @var ModelSpec $spec */
        $spec = $record['spec'];
        $subtitle = implode(' · ', array_filter([$spec->vendor, $spec->id], static fn (string $v): bool => $v !== ''));

        return new DetailHeader($spec->name, $subtitle !== '' ? $subtitle : null);
    }

    protected function detailTabs(array $record): array
    {
        /** @var ModelSpec $spec */
        $spec = $record['spec'];

        $model = [];
        $this->addItem($model, $this->text('label.name', 'Name'), $spec->name);
        $this->addItem($model, $this->text('label.id', 'ID'), $spec->id);
        $this->addItem($model, $this->text('label.vendor', 'Vendor'), $spec->vendor);
        $this->addItem($model, $this->text('label.tier', 'Tier'), $spec->tier !== null ? $this->tierLabel($spec->tier) : null);
        $this->addItem($model, $this->text('label.status', 'Status'), $this->statusLabel($spec));
        $this->addItem($model, $this->text('label.replacedBy', 'Replacement'), $spec->replacedBy);
        $this->addItem($model, $this->text('label.retiresNotBefore', 'Available at least until'), $this->formatDate($spec->retiresNotBefore));

        $tiers = [];
        $this->addItem(
            $tiers,
            $this->text('label.defaultFor', 'Default model for tiers'),
            $record['tiers'] !== [] ? $this->tierNames($record['tiers']) : $this->text('value.none', 'None'),
        );
        foreach ($record['platformTiers'] ?? [] as $platform => $platformTiers) {
            $this->addItem(
                $tiers,
                $this->text('label.defaultForOn', 'Default model for tiers on {platform}', ['platform' => $this->platformLabel((string) $platform)]),
                $this->tierNames($platformTiers),
            );
        }

        $prices = [];
        if ($spec->hasPrices()) {
            $this->addItem($prices, $this->text('label.priceInput', 'Input'), $this->perMillion((float) $spec->priceInput));
            $this->addItem($prices, $this->text('label.priceOutput', 'Output'), $this->perMillion((float) $spec->priceOutput));
            if ($spec->longPrompt !== null) {
                $this->addItem($prices, $this->text('label.longPrompt', 'Long prompt'), $this->text(
                    'value.longPrompt',
                    'over {over} input tokens: in {input} · out {output} / 1M tokens',
                    [
                        'over' => self::number($spec->longPrompt['over']),
                        'input' => self::usd($spec->longPrompt['input']),
                        'output' => self::usd($spec->longPrompt['output']),
                    ],
                ));
            }
        }

        $caps = [];
        $this->addItem($caps, $this->text('label.temperature', 'Accepts temperature'), $this->yesNo($spec->temperature));
        $this->addItem($caps, $this->text('label.thinkingModes', 'Thinking modes'), $this->tuningList('core.ai.thinkingModes', $spec->thinkingModes, false));
        $this->addItem($caps, $this->text('label.thinkingDefault', 'Thinking without the parameter'), $this->thinkingDefaultLabel($spec->thinkingDefault));
        $this->addItem($caps, $this->text('label.effortLevels', 'Effort levels'), $this->tuningList('core.ai.effortLevels', $spec->effortLevels, true));
        $this->addItem(
            $caps,
            $this->text('label.thinkingOffMaxEffort', 'Max. effort with thinking off'),
            $spec->thinkingOffMaxEffort !== null ? $this->tuningLabel('core.ai.effortLevels', $spec->thinkingOffMaxEffort) : null,
        );
        $this->addItem(
            $caps,
            $this->text('label.maxOutputTokens', 'Output limit'),
            $spec->maxOutputTokens !== null
                ? $this->text('value.tokens', '{n} tokens', ['n' => self::number($spec->maxOutputTokens)])
                : $this->text('value.unknown', 'Unknown — sent unchanged'),
        );

        $platforms = [];
        foreach ($spec->platformIds as $platform => $platformId) {
            $this->addItem($platforms, $this->platformLabel((string) $platform), $platformId);
        }

        $groups = [
            ['title' => $this->text('group.model', 'Model'), 'items' => $model],
            ['title' => $this->text('group.tiers', 'Tiers'), 'items' => $tiers],
        ];
        if ($prices !== []) {
            $groups[] = ['title' => $this->text('group.prices', 'Prices'), 'items' => $prices];
        }
        $groups[] = ['title' => $this->text('group.capabilities', 'Capabilities'), 'items' => $caps];
        if ($platforms !== []) {
            $groups[] = ['title' => $this->text('group.platforms', 'IDs on platforms'), 'items' => $platforms];
        }

        return [[
            'id' => 'overview',
            'label' => $this->defaultOverviewLabel(),
            'content' => ['type' => 'properties', 'groups' => $groups],
        ]];
    }

    /** Jen čtení — bez Přidat i Otevřít. */
    public function getToolbarActions(?array $selectedRow): array
    {
        return [];
    }

    /**
     * Modely s úrovní seřazené podle pořadí úrovně a `order`; id = pozice
     * od 1. `tiers` = úrovně, které na model dnes ukazují obecně,
     * `platformTiers` = platforma → úrovně, které na něj ukazují jen na
     * této platformě (`platformModels`, D58; shoda s obecným modelem se
     * neopakuje).
     *
     * @return list<array{id: int, spec: ModelSpec, tiers: list<string>, platformTiers: array<string, list<string>>}>
     */
    private function rows(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }
        $catalog = $this->catalog();
        $tierMap = $catalog->tiers();
        $specs = array_values(array_filter($catalog->all(), static fn (ModelSpec $s): bool => $s->tier !== null));
        $tierOrder = static function (ModelSpec $s) use ($tierMap): int {
            if (isset($tierMap[$s->tier]['order'])) {
                return $tierMap[$s->tier]['order'];
            }
            $index = array_search($s->tier, ModelSpec::TIERS, true);
            return 1000 + ($index === false ? 99 : $index);
        };
        usort($specs, static fn (ModelSpec $a, ModelSpec $b): int => [$tierOrder($a), $a->order, $a->id] <=> [$tierOrder($b), $b->order, $b->id]);

        $pointing = [];
        $platformPointing = [];
        foreach ($tierMap as $tier => $entry) {
            $pointing[$entry['model']][] = (string) $tier;
            foreach ($entry['platformModels'] as $platform => $model) {
                if ($model !== $entry['model']) {
                    $platformPointing[$model][$platform][] = (string) $tier;
                }
            }
        }

        $rows = [];
        foreach ($specs as $i => $spec) {
            $rows[] = [
                'id' => $i + 1,
                'spec' => $spec,
                'tiers' => $pointing[$spec->id] ?? [],
                'platformTiers' => $platformPointing[$spec->id] ?? [],
            ];
        }
        return $this->rows = $rows;
    }

    /** @param list<string> $tiers */
    private function tierNames(array $tiers): string
    {
        return implode(', ', array_map(fn (string $tier): string => $this->tierLabel($tier), $tiers));
    }

    private function statusLabel(ModelSpec $spec): string
    {
        if ($spec->status === ModelSpec::STATUS_RETIRED) {
            return $spec->retiresAt !== null
                ? $this->text('value.retired', 'Retired {date}', ['date' => $this->formatDate($spec->retiresAt) ?? ''])
                : $this->text('value.retiredNoDate', 'Retired');
        }
        if ($spec->isRetiring()) {
            return $this->text('value.retiring', 'Retires {date}', ['date' => $this->formatDate($spec->retiresAt) ?? '?']);
        }
        return $this->text('value.active', 'Active');
    }

    private function thinkingDefaultLabel(?string $default): string
    {
        return match ($default) {
            ModelSpec::THINKING_DEFAULT_OFF => $this->text('value.thinkingOff', 'Off'),
            ModelSpec::THINKING_DEFAULT_ADAPTIVE => $this->text('value.thinkingAdaptive', 'Adaptive (thinks on its own)'),
            default => $this->text('value.unknown', 'Unknown — sent unchanged'),
        };
    }

    /**
     * Seznam přijímaných hodnot ladění s popisky z cfgItemu; null = neznámo,
     * `[]` = parametr nepodporuje (jen effort).
     *
     * @param list<string>|null $values
     */
    private function tuningList(string $cfgItemId, ?array $values, bool $emptyMeansUnsupported): string
    {
        if ($values === null) {
            return $this->text('value.unknown', 'Unknown — sent unchanged');
        }
        if ($values === []) {
            return $emptyMeansUnsupported
                ? $this->text('value.noEffort', 'Not supported')
                : $this->text('value.none', 'None');
        }
        return implode(', ', array_map(fn (string $v): string => $this->tuningLabel($cfgItemId, $v) ?? $v, $values));
    }

    private function yesNo(?bool $value): string
    {
        return match ($value) {
            true => $this->text('value.yes', 'Yes'),
            false => $this->text('value.no', 'No'),
            null => $this->text('value.unknown', 'Unknown — sent unchanged'),
        };
    }

    private function perMillion(float $price): string
    {
        return $this->text('value.perMillion', '{price} / 1M tokens', ['price' => self::usd($price)]);
    }

    /** `$3`, `$0.10`, `$0.25` — USD bez zbytečných nul za celými dolary. */
    private static function usd(float $price): string
    {
        $text = number_format($price, 2, '.', '');
        if (str_ends_with($text, '.00')) {
            $text = substr($text, 0, -3);
        }
        return '$' . $text;
    }

    private static function number(int $n): string
    {
        return number_format($n, 0, '', ' ');
    }
}

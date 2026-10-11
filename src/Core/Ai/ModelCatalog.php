<?php

declare(strict_types=1);

namespace Shipard\Core\Ai;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Logging\ErrorLogger;

/**
 * Katalog modelů (tasks/ai-model-catalog.md, #85 D30, D33, D34): jediné
 * místo, kde Shipard ví, jak s modelem mluvit. Zdroj = cfgItem
 * `core.ai.models` (`modules/core/ai/config/models.jsonc`) z compiled
 * configu; bez configu je katalog prázdný — nic se netvaruje, ceny jsou 0
 * s varováním, výchozí model žádný.
 *
 * Výchozí model úrovně (`fast` / `balanced` / `strong`) určuje mapa úrovní
 * — cfgItem `core.ai.tiers` (tasks/ai-functions.md D37), načtená spolu
 * s katalogem; {@see defaultModel()} ji čte, položky katalogu samy příznak
 * výchozího modelu nenesou.
 *
 * {@see find()} přijme ID z kterékoli rodiny platformových ID i s datovou
 * příponou (`claude-sonnet-4-5-20250929`, `anthropic.claude-sonnet-5-5`)
 * a ID Bedrocku s geografickým profilem (`eu.anthropic.claude-sonnet-5-5`,
 * tasks/ai-bedrock-eu.md D57): shoda je přesné ID nebo ID + `-YYYYMMDD`,
 * vyhrává nejdelší kandidát. Prefix sám nestačí — `claude-opus-4` nechytí
 * `claude-opus-4-8` ani `claude-sonnet-4-5` model `claude-sonnet-4-50`.
 * {@see platformOf()} vrací klíč `platformIds` (rodinu ID, ne platformu
 * připojení — tu určuje {@see AiPlatform::$modelIds}), {@see geoPrefixOf()}
 * geografický prefix (`''` bez prefixu).
 */
final class ModelCatalog
{
    public const string CFG_ITEM = 'core.ai.models';

    /** Mapa úrovní: úroveň → {name, order, model} (#85 D37). */
    public const string TIERS_CFG_ITEM = 'core.ai.tiers';

    public const string PLATFORM_ANTHROPIC = 'anthropic';
    public const string PLATFORM_BEDROCK   = 'bedrock';

    /**
     * Geografické profily Bedrocku (cross-region inference) před ID rodiny
     * `bedrock`: `eu.anthropic.claude-sonnet-5-5`. Jen pro rozpoznání ID —
     * kterou platformu připojení prefix znamená, říká `core.ai.platforms`.
     */
    public const array GEO_PREFIXES = ['global.', 'us.', 'eu.', 'apac.', 'au.', 'jp.', 'us-gov.'];

    /**
     * @param array<string, ModelSpec>                                      $models id → spec
     * @param array<string, array{model: string, name: string, order: int}> $tiers  úroveň → výchozí model
     */
    private function __construct(
        private readonly array $models,
        private readonly array $tiers,
    ) {}

    /**
     * Katalog z compiled configu; `null` config nebo chybějící cfgItem =
     * prázdný katalog. Mapa úrovní se načte, je-li v configu; bez ní
     * katalog žádný výchozí model nemá.
     */
    public static function fromConfig(?ConfigRuntime $config): self
    {
        $raw = $config?->cfgItem(self::CFG_ITEM);
        if (!is_array($raw)) {
            return self::empty();
        }
        $tiers = $config?->cfgItem(self::TIERS_CFG_ITEM);
        return self::fromArray($raw, is_array($tiers) ? $tiers : []);
    }

    /**
     * Katalog ze surových dat cfgItemů (testy, `ds-upgrade` před kompilací).
     *
     * @param array<string, array<string, mixed>> $items id → položka
     * @param array<string, array<string, mixed>> $tiers úroveň → {model, name?, order?}
     * @throws \InvalidArgumentException při nevalidní položce nebo úrovni
     */
    public static function fromArray(array $items, array $tiers = []): self
    {
        $models = [];
        foreach ($items as $id => $data) {
            if (!is_array($data)) {
                throw new \InvalidArgumentException("ModelCatalog: entry '{$id}' must be an object");
            }
            $models[(string) $id] = ModelSpec::fromArray((string) $id, $data);
        }
        $tierMap = [];
        foreach ($tiers as $tier => $data) {
            $tier = (string) $tier;
            if (!in_array($tier, ModelSpec::TIERS, true)) {
                throw new \InvalidArgumentException("ModelCatalog: unknown tier '{$tier}' in " . self::TIERS_CFG_ITEM);
            }
            if (!is_array($data) || !is_string($data['model'] ?? null) || trim($data['model']) === '') {
                throw new \InvalidArgumentException("ModelCatalog: tier '{$tier}' must declare a model");
            }
            $tierMap[$tier] = [
                'model' => trim($data['model']),
                'name' => (string) ($data['name'] ?? $tier),
                'order' => is_numeric($data['order'] ?? null) ? (int) $data['order'] : 1000,
            ];
        }
        uasort($tierMap, static fn (array $a, array $b): int => [$a['order'], $a['model']] <=> [$b['order'], $b['model']]);
        return new self($models, $tierMap);
    }

    public static function empty(): self
    {
        return new self([], []);
    }

    public function isEmpty(): bool
    {
        return $this->models === [];
    }

    /** @return list<ModelSpec> seřazené podle `order`, pak id */
    public function all(): array
    {
        $list = array_values($this->models);
        usort($list, static fn (ModelSpec $a, ModelSpec $b): int => [$a->order, $a->id] <=> [$b->order, $b->id]);
        return $list;
    }

    /** Položka podle ID z kterékoli platformy, i s datovou příponou; null = mimo katalog. */
    public function find(string $modelId): ?ModelSpec
    {
        return $this->resolve($modelId)['spec'] ?? null;
    }

    /**
     * Rodina platformových ID, do které `$modelId` patří (klíč `platformIds`:
     * `anthropic` / `bedrock`, i pod geografickým prefixem); null = mimo katalog.
     */
    public function platformOf(string $modelId): ?string
    {
        return $this->resolve($modelId)['platform'] ?? null;
    }

    /**
     * Geografický prefix ID Bedrocku (`eu.` v `eu.anthropic.claude-sonnet-5-5`),
     * `''` bez prefixu; null = mimo katalog.
     */
    public function geoPrefixOf(string $modelId): ?string
    {
        return $this->resolve($modelId)['prefix'] ?? null;
    }

    /**
     * ID výchozího modelu úrovně podle mapy úrovní `core.ai.tiers` (D37),
     * null pro neznámou úroveň nebo katalog bez mapy. Vrací se, co mapa
     * deklaruje — že model v katalogu existuje a je aktivní, hlídá test
     * souboru v repu (ModelCatalogFileTest), ne běh.
     */
    public function defaultModel(string $tier): ?string
    {
        return $this->tiers[$tier]['model'] ?? null;
    }

    /**
     * Mapa úrovní seřazená podle `order`: úroveň → {model, name, order}.
     *
     * @return array<string, array{model: string, name: string, order: int}>
     */
    public function tiers(): array
    {
        return $this->tiers;
    }

    /**
     * Náhrada vyřazeného modelu (`lifecycle.replacedBy` u statusu
     * deprecated / retired), null = model se nemění. Shoda jen přes ID
     * přímého API (přesné nebo s datovou příponou) — ID s prefixem
     * platformy (`anthropic.…` na Bedrocku) se nepřepisují, partnerské
     * platformy mají vlastní termíny (F0-D8).
     */
    public function retiredReplacement(string $modelId): ?string
    {
        $match = $this->resolve($modelId);
        if ($match === null || $match['platform'] !== self::PLATFORM_ANTHROPIC) {
            return null;
        }
        $spec = $match['spec'];
        return $spec->isRetiring() ? $spec->replacedBy : null;
    }

    /**
     * Cena jednoho volání v USD na 6 desetinných míst podle ceníku katalogu
     * (D33). Model mimo katalog nebo bez cen = 0.0 a varování: cena je
     * reportovací pole, ne důvod shodit analýzu. Ceny platí pro přímé API;
     * ID z Bedrocku se oceňuje stejnými sazbami (partnerský ceník fáze 3).
     */
    public function costUsd(string $modelId, int $tokensInput, int $tokensOutput): float
    {
        $rates = $this->rates($modelId, $tokensInput);
        if ($rates === null) {
            ErrorLogger::warn('ModelCatalog: model missing in the catalog or without prices — cost reported as 0', [
                'model' => $modelId,
                'catalogEmpty' => $this->isEmpty(),
            ]);
            return 0.0;
        }
        [$inputPerMtok, $outputPerMtok] = $rates;

        return round(($tokensInput * $inputPerMtok + $tokensOutput * $outputPerMtok) / 1_000_000, 6);
    }

    /**
     * Sazby `[vstup, výstup]` v USD za milion tokenů, null = mimo katalog
     * nebo bez cen; `$tokensInput` vybírá pásmo u modelů s dražším dlouhým
     * promptem (Haiku 5.5).
     *
     * @return array{0: float, 1: float}|null
     */
    public function rates(string $modelId, int $tokensInput = 0): ?array
    {
        return $this->find($modelId)?->ratesFor($tokensInput);
    }

    /**
     * Nejdelší kandidát (ID v kterékoli rodině), který na `$modelId` sedí
     * přesně nebo s datovou příponou `-YYYYMMDD`; ID rodiny `bedrock` i pod
     * geografickým prefixem ({@see GEO_PREFIXES}).
     *
     * @return array{spec: ModelSpec, platform: string, prefix: string}|null
     */
    private function resolve(string $modelId): ?array
    {
        $modelId = trim($modelId);
        if ($modelId === '') {
            return null;
        }
        [$prefix, $bare] = self::splitGeoPrefix($modelId);
        $best = null;
        $bestLength = -1;
        foreach ($this->models as $spec) {
            foreach ($spec->platformIds as $platform => $candidate) {
                if (strlen($candidate) <= $bestLength) {
                    continue;
                }
                if (self::matches($modelId, $candidate)) {
                    $best = ['spec' => $spec, 'platform' => (string) $platform, 'prefix' => ''];
                    $bestLength = strlen($candidate);
                } elseif ($prefix !== '' && $platform === self::PLATFORM_BEDROCK && self::matches($bare, $candidate)) {
                    $best = ['spec' => $spec, 'platform' => (string) $platform, 'prefix' => $prefix];
                    $bestLength = strlen($candidate);
                }
            }
        }
        return $best;
    }

    /**
     * `eu.anthropic.claude-sonnet-5-5` → `['eu.', 'anthropic.claude-sonnet-5-5']`;
     * bez známého prefixu `['', $modelId]`.
     *
     * @return array{0: string, 1: string}
     */
    private static function splitGeoPrefix(string $modelId): array
    {
        foreach (self::GEO_PREFIXES as $prefix) {
            if (str_starts_with($modelId, $prefix)) {
                return [$prefix, substr($modelId, strlen($prefix))];
            }
        }
        return ['', $modelId];
    }

    private static function matches(string $modelId, string $candidate): bool
    {
        return $modelId === $candidate
            || preg_match('/^' . preg_quote($candidate, '/') . '-\d{8}$/', $modelId) === 1;
    }
}

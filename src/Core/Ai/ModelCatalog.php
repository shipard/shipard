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
 * {@see find()} přijme ID z kterékoli platformy i s datovou příponou
 * (`claude-sonnet-4-5-20250929`, `anthropic.claude-sonnet-5-5`): shoda je
 * přesné ID nebo ID + `-YYYYMMDD`, vyhrává nejdelší kandidát. Prefix sám
 * nestačí — `claude-opus-4` nechytí `claude-opus-4-8` ani `claude-sonnet-4-5`
 * model `claude-sonnet-4-50`.
 */
final class ModelCatalog
{
    public const string CFG_ITEM = 'core.ai.models';

    public const string PLATFORM_ANTHROPIC = 'anthropic';
    public const string PLATFORM_BEDROCK   = 'bedrock';

    /** @param array<string, ModelSpec> $models id → spec */
    private function __construct(private readonly array $models) {}

    /** Katalog z compiled configu; `null` config nebo chybějící cfgItem = prázdný katalog. */
    public static function fromConfig(?ConfigRuntime $config): self
    {
        $raw = $config?->cfgItem(self::CFG_ITEM);
        return is_array($raw) ? self::fromArray($raw) : self::empty();
    }

    /**
     * Katalog ze surových dat cfgItemu (testy, `ds-upgrade` před kompilací).
     *
     * @param array<string, array<string, mixed>> $items id → položka
     * @throws \InvalidArgumentException při nevalidní položce
     */
    public static function fromArray(array $items): self
    {
        $models = [];
        foreach ($items as $id => $data) {
            if (!is_array($data)) {
                throw new \InvalidArgumentException("ModelCatalog: entry '{$id}' must be an object");
            }
            $models[(string) $id] = ModelSpec::fromArray((string) $id, $data);
        }
        return new self($models);
    }

    public static function empty(): self
    {
        return new self([]);
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

    /** ID výchozího modelu úrovně (`tierDefault`), null bez výchozího (D34). */
    public function defaultModel(string $tier): ?string
    {
        foreach ($this->all() as $spec) {
            if ($spec->tier === $tier && $spec->tierDefault) {
                return $spec->id;
            }
        }
        return null;
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
     * Nejdelší kandidát (ID na kterékoli platformě), který na `$modelId`
     * sedí přesně nebo s datovou příponou `-YYYYMMDD`.
     *
     * @return array{spec: ModelSpec, platform: string}|null
     */
    private function resolve(string $modelId): ?array
    {
        $modelId = trim($modelId);
        if ($modelId === '') {
            return null;
        }
        $best = null;
        $bestLength = -1;
        foreach ($this->models as $spec) {
            foreach ($spec->platformIds as $platform => $candidate) {
                if (strlen($candidate) > $bestLength && self::matches($modelId, $candidate)) {
                    $best = ['spec' => $spec, 'platform' => (string) $platform];
                    $bestLength = strlen($candidate);
                }
            }
        }
        return $best;
    }

    private static function matches(string $modelId, string $candidate): bool
    {
        return $modelId === $candidate
            || preg_match('/^' . preg_quote($candidate, '/') . '-\d{8}$/', $modelId) === 1;
    }
}

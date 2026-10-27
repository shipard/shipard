<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\Round;

/**
 * Co kolo projde (D130): vrstvy `code` / `datasets` / `videos` (výchozí
 * všechny), filtr sad a scénářů (jen své vrstvy — ortogonální k `--only`),
 * volitelná evaluace ({@see RoundEvalOptions}, D131) a lokální adresa
 * aplikace pro proklikání a videa (`--base-url`, origin požadavku). Žije
 * v `job.json` → `params`, orchestrátor ji čte odtud; CLI i dashboard ji
 * skládají týmž tvarem.
 */
final readonly class RoundSelection
{
    public const string LAYER_CODE = 'code';
    public const string LAYER_DATASETS = 'datasets';
    public const string LAYER_VIDEOS = 'videos';
    public const array LAYERS = [self::LAYER_CODE, self::LAYER_DATASETS, self::LAYER_VIDEOS];
    public const string DEFAULT_BASE_URL = 'http://127.0.0.1';

    /**
     * @param list<string> $layers    v pořadí {@see LAYERS}
     * @param list<string> $datasets  názvy sad (nebo adresáře s `manifest.jsonc`); prázdné = všechny veřejné
     * @param list<string> $scenarios id scénářů; prázdné = všechny
     */
    public function __construct(
        public array $layers,
        public array $datasets,
        public array $scenarios,
        public ?RoundEvalOptions $eval,
        public string $baseUrl,
    ) {
        if ($layers === []) {
            throw new \InvalidArgumentException('At least one layer is required (' . implode(', ', self::LAYERS) . ')');
        }
        foreach ($layers as $layer) {
            if (!in_array($layer, self::LAYERS, true)) {
                throw new \InvalidArgumentException("Unknown layer '{$layer}' (" . implode(', ', self::LAYERS) . ')');
            }
        }
        if (array_values(array_unique($layers)) !== $layers) {
            throw new \InvalidArgumentException('Layers must not repeat');
        }
        foreach ([$datasets, $scenarios] as $list) {
            foreach ($list as $item) {
                if (!is_string($item) || trim($item) === '') {
                    throw new \InvalidArgumentException('Dataset and scenario filters must be non-empty strings');
                }
            }
        }
        if (trim($baseUrl) === '') {
            throw new \InvalidArgumentException('baseUrl is required');
        }
    }

    public function has(string $layer): bool
    {
        return in_array($layer, $this->layers, true);
    }

    /** `code,datasets` → vrstvy v kanonickém pořadí. @return list<string> @throws \InvalidArgumentException */
    public static function parseLayers(string $csv): array
    {
        $wanted = [];
        foreach (explode(',', $csv) as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }
            if (!in_array($token, self::LAYERS, true)) {
                throw new \InvalidArgumentException("Unknown layer '{$token}' (" . implode(', ', self::LAYERS) . ')');
            }
            $wanted[$token] = true;
        }
        return array_values(array_filter(self::LAYERS, static fn (string $l): bool => isset($wanted[$l])));
    }

    /** @param array<string, mixed> $raw @throws \InvalidArgumentException */
    public static function fromArray(array $raw): self
    {
        $layers = $raw['layers'] ?? self::LAYERS;
        $eval = $raw['eval'] ?? null;
        return new self(
            is_array($layers) ? array_values(array_map('strval', $layers)) : [],
            is_array($raw['datasets'] ?? null) ? array_values(array_map('strval', $raw['datasets'])) : [],
            is_array($raw['scenarios'] ?? null) ? array_values(array_map('strval', $raw['scenarios'])) : [],
            is_array($eval) ? RoundEvalOptions::fromArray($eval) : null,
            is_string($raw['baseUrl'] ?? null) ? $raw['baseUrl'] : self::DEFAULT_BASE_URL,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'layers' => $this->layers,
            'datasets' => $this->datasets,
            'scenarios' => $this->scenarios,
            'eval' => $this->eval?->toArray(),
            'baseUrl' => $this->baseUrl,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Shipard\Core\Ai;

/**
 * Hotové parametry volání AI funkce (tasks/ai-functions.md D40): platforma
 * (id, dialekt, násobitel ceny — tasks/ai-bedrock-eu.md D57, D60)
 * a adresa, dešifrovaný klíč, ID modelu pro platformu, `shapeAs` (model
 * z katalogu, podle kterého se požadavek tvaruje — u vlastního ID jiný než
 * `model`), ladění, připojení a původ každé hodnoty (výpis v Nastavení).
 *
 * Klíč žije jen v paměti volajícího — {@see toArray()} ho nevrací.
 * {@see chatParams()} složí {@see LlmChatParams} z rozhodnutí a obsahu
 * volání; volající už žádný parametr modelu nedoplňuje.
 */
final readonly class ResolvedAiCall
{
    public const string ORIGIN_OVERRIDE = 'override';
    public const string ORIGIN_FUNCTION = 'function';
    public const string ORIGIN_DECLARATION = 'declaration';
    public const string ORIGIN_TIER = 'tier';
    public const string ORIGIN_DEFAULT = 'default';
    /** Výchozí připojení spadlo na serverové připojení pro vývoj (tasks/testing-phase4.md D40). */
    public const string ORIGIN_SERVER = 'server';

    /**
     * @param array<string, string> $origins klíč (connection, model, thinking, effort, maxTokens) → ORIGIN_*
     */
    public function __construct(
        public string $functionId,
        public string $platform,
        public string $baseUrl,
        public string $apiKey,
        public string $model,
        public ?string $shapeAs,
        public ?string $catalogModel,
        public int $maxTokens,
        public ?float $temperature,
        public ?string $thinking,
        public ?string $effort,
        public int $connectionId,
        public string $connectionCode,
        public array $origins = [],
        public string $dialect = AiPlatform::DIALECT_ANTHROPIC_MESSAGES,
        public float $priceMultiplier = 1.0,
    ) {}

    /** Plán ({@see AiFunctionResolver::plan()}) + dešifrovaný klíč = hotové volání. */
    public static function fromPlan(AiCallPlan $plan, string $apiKey): self
    {
        return new self(
            functionId: $plan->functionId,
            platform: $plan->platform,
            baseUrl: $plan->baseUrl,
            apiKey: $apiKey,
            model: $plan->model,
            shapeAs: $plan->shapeAs,
            catalogModel: $plan->catalogModel,
            maxTokens: $plan->maxTokens,
            temperature: $plan->temperature,
            thinking: $plan->thinking,
            effort: $plan->effort,
            connectionId: $plan->connectionId,
            connectionCode: $plan->connectionCode,
            origins: $plan->origins,
            dialect: $plan->dialect,
            priceMultiplier: $plan->priceMultiplier,
        );
    }

    /** Vlastní ID modelu mimo katalog (D43): tvaruje se podle `shapeAs`, cena 0. */
    public function isCustomModel(): bool
    {
        return $this->catalogModel === null || $this->catalogModel !== $this->shapeAs;
    }

    /**
     * Cena volání v USD (tasks/ai-bedrock-eu.md D60) — **jediné místo**
     * výpočtu pro běh analýzy i `ai-test`: ceník katalogu pro model
     * rozhodnutí (`catalogModel`, ne ID z odpovědi — Bedrock může vrátit
     * základní ID místo profilu) × násobitel platformy, na 6 míst. Vlastní
     * ID mimo katalog nemá cenu (D43) → null.
     */
    public function costUsd(ModelCatalog $catalog, int $tokensInput, int $tokensOutput): ?float
    {
        if ($this->catalogModel === null) {
            return null;
        }
        return round($catalog->costUsd($this->catalogModel, $tokensInput, $tokensOutput) * $this->priceMultiplier, 6);
    }

    /**
     * @param array<int, array{role: string, content: mixed}>                                $messages
     * @param array<int, array{name: string, description: string, input_schema: array}>|null $tools
     */
    public function chatParams(
        ?string $system,
        array $messages,
        ?array $tools = null,
        ?int $stallTimeoutSeconds = null,
        ?int $timeoutSeconds = null,
    ): LlmChatParams {
        return new LlmChatParams(
            provider: $this->platform,
            model: $this->model,
            apiKey: $this->apiKey,
            baseUrl: $this->baseUrl,
            system: $system,
            messages: $messages,
            maxTokens: $this->maxTokens,
            temperature: $this->temperature,
            tools: $tools,
            stallTimeoutSeconds: $stallTimeoutSeconds,
            timeoutSeconds: $timeoutSeconds,
            thinking: $this->thinking,
            effort: $this->effort,
            shapeAs: $this->shapeAs,
            dialect: $this->dialect,
        );
    }

    /**
     * Rozhodnutí bez klíče — pro log a přehled v Nastavení.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'functionId' => $this->functionId,
            'platform' => $this->platform,
            'dialect' => $this->dialect,
            'priceMultiplier' => $this->priceMultiplier,
            'baseUrl' => $this->baseUrl,
            'model' => $this->model,
            'shapeAs' => $this->shapeAs,
            'catalogModel' => $this->catalogModel,
            'maxTokens' => $this->maxTokens,
            'temperature' => $this->temperature,
            'thinking' => $this->thinking,
            'effort' => $this->effort,
            'connectionId' => $this->connectionId,
            'connectionCode' => $this->connectionCode,
            'origins' => $this->origins,
        ];
    }
}

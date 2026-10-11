<?php

declare(strict_types=1);

namespace Shipard\Core\Ai;

/**
 * Hotové parametry volání AI funkce (tasks/ai-functions.md D40): platforma
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
        );
    }

    /** Vlastní ID modelu mimo katalog (D43): tvaruje se podle `shapeAs`, cena 0. */
    public function isCustomModel(): bool
    {
        return $this->catalogModel === null || $this->catalogModel !== $this->shapeAs;
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

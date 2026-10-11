<?php

declare(strict_types=1);

namespace Shipard\Core\Ai;

/**
 * Rozhodnutí resolveru **bez klíče** (tasks/ai-settings-section.md D51):
 * všechno, co {@see ResolvedAiCall} nese, kromě dešifrovaného API klíče.
 * Vrací ho {@see AiFunctionResolver::plan()} — klíč se při plánu
 * neotevírá, ověřuje se jen, že je uložený. Detail AI funkce v Nastavení
 * a `ai-test` z plánu čtou efektivní hodnoty s původem; skutečné volání
 * vzniká až `resolve()` = plán + klíč ({@see ResolvedAiCall::fromPlan()}).
 */
final readonly class AiCallPlan
{
    /**
     * @param array<string, string> $origins klíč (connection, model, thinking, effort, maxTokens) → ResolvedAiCall::ORIGIN_*
     */
    public function __construct(
        public string $functionId,
        public string $platform,
        public string $baseUrl,
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

    /** Vlastní ID modelu mimo katalog (D43): tvaruje se podle `shapeAs`, cena 0. */
    public function isCustomModel(): bool
    {
        return $this->catalogModel === null || $this->catalogModel !== $this->shapeAs;
    }

    /**
     * Parametry pro náhled tvarování ({@see RequestShaper}): bez klíče
     * a bez obsahu — shaper čte jen teplotu, thinking, effort a strop.
     */
    public function previewParams(): LlmChatParams
    {
        return new LlmChatParams(
            provider: $this->platform,
            model: $this->model,
            apiKey: null,
            baseUrl: $this->baseUrl,
            system: null,
            messages: [],
            maxTokens: $this->maxTokens,
            temperature: $this->temperature,
            thinking: $this->thinking,
            effort: $this->effort,
            shapeAs: $this->shapeAs,
        );
    }

    /** @return array<string, mixed> */
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

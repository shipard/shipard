<?php

declare(strict_types=1);

namespace Shipard\Core\Ai;

/**
 * Jedna úprava požadavku z {@see RequestShaper} (tasks/ai-settings-section.md
 * D51): kód + parametry, aby detail AI funkce i `ai-test` mohly úpravu
 * popsat česky z katalogu `core.ai.texts` ({@see AiTexts::change()}).
 * {@see __toString()} dává anglickou větu pro log klienta — stejnou, jakou
 * shaper vracel před zavedením kódů.
 */
final readonly class RequestChange implements \Stringable
{
    /** Teplota se neposílá — model nepřijímá jinou než výchozí. Parametry: temperature. */
    public const string TEMPERATURE_OMITTED = 'temperatureOmitted';
    /** `disabled` ↔ `between_tools`. Parametry: from, to, accepted. */
    public const string THINKING_REPLACED = 'thinkingReplaced';
    /** Režim mimo `thinkingModes` bez náhrady. Parametry: thinking, accepted. */
    public const string THINKING_OMITTED = 'thinkingOmitted';
    /** Model parametr thinking nezná (`thinkingModes: []`). Parametry: thinking. */
    public const string THINKING_UNSUPPORTED = 'thinkingUnsupported';
    /** Model parametr effort nezná (`effortLevels: []`). Parametry: effort. */
    public const string EFFORT_OMITTED = 'effortOmitted';
    /** Úroveň mimo `effortLevels` → nejbližší nižší. Parametry: from, to, accepted. */
    public const string EFFORT_LOWERED = 'effortLowered';
    /** Vypnutý thinking → effort nejvýš `thinkingOffMaxEffort`. Parametry: from, to, thinking. */
    public const string EFFORT_CAPPED = 'effortCapped';
    /** `max_tokens` nad `maxOutputTokens`. Parametry: from, to. */
    public const string MAX_TOKENS_CAPPED = 'maxTokensCapped';

    public const array CODES = [
        self::TEMPERATURE_OMITTED,
        self::THINKING_REPLACED,
        self::THINKING_OMITTED,
        self::THINKING_UNSUPPORTED,
        self::EFFORT_OMITTED,
        self::EFFORT_LOWERED,
        self::EFFORT_CAPPED,
        self::MAX_TOKENS_CAPPED,
    ];

    /** @param array<string, scalar> $params */
    public function __construct(
        public string $code,
        public array $params = [],
    ) {
        if (!in_array($code, self::CODES, true)) {
            throw new \InvalidArgumentException("RequestChange: unknown code '{$code}'");
        }
    }

    /** Anglická věta pro log (`AnthropicLlmClient`) a fallback bez katalogu textů. */
    public function __toString(): string
    {
        $p = $this->params;
        return match ($this->code) {
            self::TEMPERATURE_OMITTED => "temperature {$p['temperature']} omitted (model rejects a non-default temperature)",
            self::THINKING_REPLACED => "thinking {$p['from']} → {$p['to']} (model accepts {$p['accepted']})",
            self::THINKING_OMITTED => "thinking {$p['thinking']} omitted (model accepts {$p['accepted']})",
            self::THINKING_UNSUPPORTED => "thinking {$p['thinking']} omitted (model accepts no thinking parameter)",
            self::EFFORT_OMITTED => "effort {$p['effort']} omitted (model has no effort parameter)",
            self::EFFORT_LOWERED => "effort {$p['from']} → {$p['to']} (model accepts {$p['accepted']})",
            self::EFFORT_CAPPED => "effort {$p['from']} → {$p['to']} (thinking {$p['thinking']} allows at most {$p['to']})",
            self::MAX_TOKENS_CAPPED => "max_tokens {$p['from']} → {$p['to']} (model limit)",
        };
    }
}

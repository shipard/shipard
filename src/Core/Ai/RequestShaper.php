<?php

declare(strict_types=1);

namespace Shipard\Core\Ai;

/**
 * Tvarování požadavku podle schopností modelu (tasks/ai-model-catalog.md
 * D32): z ladicích parametrů volajícího a {@see ModelSpec} vrátí parametry,
 * které model přijme, a seznam provedených úprav. Co model nepřijímá, se
 * nepošle nebo nahradí nejbližší platnou hodnotou — kombinace, kterou by
 * API vrátilo jako HTTP 400, tak projde.
 *
 * Pravidla (každé platí jen při známé schopnosti; `null` = nevíme →
 * hodnota odejde beze změny):
 *  1. `temperature`: model ji nepřijímá → vynechat.
 *  2. `thinking`: hodnota není v `thinkingModes` → náhrada `disabled` ↔
 *     `between_tools` (podle toho, kterou model má); jinak vynechat.
 *  3. `effort`: úroveň není v `effortLevels` → nejbližší nižší přijímaná
 *     (pod nejnižší přijímanou → nejnižší přijímaná); model effort nezná
 *     (`[]`) → vynechat. Vypnutý thinking (`disabled` / `between_tools`)
 *     s `thinkingOffMaxEffort` → effort nejvýš tato mez.
 *  4. `maxTokens` nad `maxOutputTokens` → snížit na mez.
 *
 * Čistá statická třída bez logování — úpravy loguje volající
 * ({@see AnthropicLlmClient}), aby byly vidět jednou za volání.
 */
final class RequestShaper
{
    /** Úrovně effortu od nejnižší (core.ai.effortLevels bez `auto`). */
    public const array EFFORT_ORDER = ['low', 'medium', 'high', 'xhigh', 'max'];

    public const string THINKING_DISABLED      = 'disabled';
    public const string THINKING_BETWEEN_TOOLS = 'between_tools';

    /**
     * @return array{0: LlmChatParams, 1: list<string>} upravené parametry + popis úprav (prázdný = beze změny)
     */
    public static function shape(LlmChatParams $params, ModelSpec $spec): array
    {
        $changes = [];
        $temperature = $params->temperature;
        $thinking = $params->thinking !== '' ? $params->thinking : null;
        $effort = $params->effort !== '' ? $params->effort : null;
        $maxTokens = $params->maxTokens;

        // 1. teplota
        if ($temperature !== null && $spec->temperature === false) {
            $changes[] = "temperature {$temperature} omitted (model rejects a non-default temperature)";
            $temperature = null;
        }

        // 2. thinking
        if ($thinking !== null && $spec->thinkingModes !== null && !in_array($thinking, $spec->thinkingModes, true)) {
            $replacement = self::thinkingReplacement($thinking, $spec->thinkingModes);
            if ($replacement !== null) {
                $changes[] = "thinking {$thinking} → {$replacement} (model accepts " . implode(', ', $spec->thinkingModes) . ')';
            } else {
                $changes[] = "thinking {$thinking} omitted (model accepts "
                    . ($spec->thinkingModes === [] ? 'no thinking parameter' : implode(', ', $spec->thinkingModes)) . ')';
            }
            $thinking = $replacement;
        }

        // 3. effort
        if ($effort !== null && $spec->effortLevels !== null && !in_array($effort, $spec->effortLevels, true)) {
            if ($spec->effortLevels === []) {
                $changes[] = "effort {$effort} omitted (model has no effort parameter)";
                $effort = null;
            } else {
                $nearest = self::nearestLowerEffort($effort, $spec->effortLevels);
                $changes[] = "effort {$effort} → {$nearest} (model accepts " . implode(', ', $spec->effortLevels) . ')';
                $effort = $nearest;
            }
        }
        if ($effort !== null
            && $spec->thinkingOffMaxEffort !== null
            && in_array($thinking, [self::THINKING_DISABLED, self::THINKING_BETWEEN_TOOLS], true)
            && self::effortIndex($effort) > self::effortIndex($spec->thinkingOffMaxEffort)
        ) {
            $changes[] = "effort {$effort} → {$spec->thinkingOffMaxEffort} (thinking {$thinking} allows at most {$spec->thinkingOffMaxEffort})";
            $effort = $spec->thinkingOffMaxEffort;
        }

        // 4. max_tokens
        if ($spec->maxOutputTokens !== null && $maxTokens > $spec->maxOutputTokens) {
            $changes[] = "max_tokens {$maxTokens} → {$spec->maxOutputTokens} (model limit)";
            $maxTokens = $spec->maxOutputTokens;
        }

        if ($changes === []) {
            return [$params, []];
        }

        return [
            new LlmChatParams(
                provider: $params->provider,
                model: $params->model,
                apiKey: $params->apiKey,
                baseUrl: $params->baseUrl,
                system: $params->system,
                messages: $params->messages,
                maxTokens: $maxTokens,
                temperature: $temperature,
                tools: $params->tools,
                stallTimeoutSeconds: $params->stallTimeoutSeconds,
                timeoutSeconds: $params->timeoutSeconds,
                thinking: $thinking,
                effort: $effort,
                shapeAs: $params->shapeAs,
            ),
            $changes,
        ];
    }

    /**
     * `disabled` ↔ `between_tools` jsou oba „vypnuté“ režimy — model má
     * vždy nejvýš jeden z nich. Jiná nepřijímaná hodnota nemá náhradu.
     *
     * @param list<string> $accepted
     */
    private static function thinkingReplacement(string $thinking, array $accepted): ?string
    {
        $pair = [self::THINKING_DISABLED => self::THINKING_BETWEEN_TOOLS, self::THINKING_BETWEEN_TOOLS => self::THINKING_DISABLED];
        $other = $pair[$thinking] ?? null;
        return $other !== null && in_array($other, $accepted, true) ? $other : null;
    }

    /**
     * Nejbližší nižší přijímaná úroveň; pod nejnižší přijímanou (nebo
     * neznámá úroveň) → nejnižší přijímaná.
     *
     * @param list<string> $accepted neprázdný
     */
    private static function nearestLowerEffort(string $effort, array $accepted): string
    {
        $ordered = array_values(array_filter(self::EFFORT_ORDER, static fn (string $l): bool => in_array($l, $accepted, true)));
        if ($ordered === []) {
            // Přijímané úrovně mimo známé pořadí — vezmi první deklarovanou.
            return $accepted[0];
        }
        $wanted = self::effortIndex($effort);
        $best = $ordered[0];
        foreach ($ordered as $level) {
            if (self::effortIndex($level) <= $wanted) {
                $best = $level;
            }
        }
        return $best;
    }

    private static function effortIndex(string $effort): int
    {
        $index = array_search($effort, self::EFFORT_ORDER, true);
        return $index === false ? -1 : $index;
    }
}

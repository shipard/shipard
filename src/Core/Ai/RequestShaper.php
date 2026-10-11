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
 * Čistá statická třída bez logování — úpravy vrací jako {@see RequestChange}
 * (kód + parametry); loguje je volající ({@see AnthropicLlmClient}) jednou
 * za volání, detail AI funkce a `ai-test` je popisují z katalogu textů.
 */
final class RequestShaper
{
    /** Úrovně effortu od nejnižší (core.ai.effortLevels bez `auto`). */
    public const array EFFORT_ORDER = ['low', 'medium', 'high', 'xhigh', 'max'];

    public const string THINKING_DISABLED      = 'disabled';
    public const string THINKING_BETWEEN_TOOLS = 'between_tools';

    /**
     * @return array{0: LlmChatParams, 1: list<RequestChange>} upravené parametry + úpravy (prázdné = beze změny)
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
            $changes[] = new RequestChange(RequestChange::TEMPERATURE_OMITTED, ['temperature' => $temperature]);
            $temperature = null;
        }

        // 2. thinking
        if ($thinking !== null && $spec->thinkingModes !== null && !in_array($thinking, $spec->thinkingModes, true)) {
            $replacement = self::thinkingReplacement($thinking, $spec->thinkingModes);
            if ($replacement !== null) {
                $changes[] = new RequestChange(RequestChange::THINKING_REPLACED, [
                    'from' => $thinking, 'to' => $replacement, 'accepted' => implode(', ', $spec->thinkingModes),
                ]);
            } elseif ($spec->thinkingModes === []) {
                $changes[] = new RequestChange(RequestChange::THINKING_UNSUPPORTED, ['thinking' => $thinking]);
            } else {
                $changes[] = new RequestChange(RequestChange::THINKING_OMITTED, [
                    'thinking' => $thinking, 'accepted' => implode(', ', $spec->thinkingModes),
                ]);
            }
            $thinking = $replacement;
        }

        // 3. effort
        if ($effort !== null && $spec->effortLevels !== null && !in_array($effort, $spec->effortLevels, true)) {
            if ($spec->effortLevels === []) {
                $changes[] = new RequestChange(RequestChange::EFFORT_OMITTED, ['effort' => $effort]);
                $effort = null;
            } else {
                $nearest = self::nearestLowerEffort($effort, $spec->effortLevels);
                $changes[] = new RequestChange(RequestChange::EFFORT_LOWERED, [
                    'from' => $effort, 'to' => $nearest, 'accepted' => implode(', ', $spec->effortLevels),
                ]);
                $effort = $nearest;
            }
        }
        if ($effort !== null
            && $spec->thinkingOffMaxEffort !== null
            && in_array($thinking, [self::THINKING_DISABLED, self::THINKING_BETWEEN_TOOLS], true)
            && self::effortIndex($effort) > self::effortIndex($spec->thinkingOffMaxEffort)
        ) {
            $changes[] = new RequestChange(RequestChange::EFFORT_CAPPED, [
                'from' => $effort, 'to' => $spec->thinkingOffMaxEffort, 'thinking' => $thinking,
            ]);
            $effort = $spec->thinkingOffMaxEffort;
        }

        // 4. max_tokens
        if ($spec->maxOutputTokens !== null && $maxTokens > $spec->maxOutputTokens) {
            $changes[] = new RequestChange(RequestChange::MAX_TOKENS_CAPPED, ['from' => $maxTokens, 'to' => $spec->maxOutputTokens]);
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

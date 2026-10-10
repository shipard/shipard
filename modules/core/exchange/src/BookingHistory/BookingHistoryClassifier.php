<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\BookingHistory;

use Shipard\Core\Ai\AiBackendResolver;
use Shipard\Core\Ai\LlmChatParams;
use Shipard\Core\Ai\LlmClient;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Module\Core\Exchange\Enrich\ContentTagPrompt;

/**
 * LLM klasifikace distinct textů účetní historie do taxonomie
 * `core.exchange.contentTags` (D33).
 *
 * Liší se od {@see \Shipard\Module\Core\Exchange\Enrich\ContentTagClassifier}
 * úlohou: ten klasifikuje **jeden doklad** s kontextem (dodavatel, řádky,
 * částky), tenhle **dávku krátkých textů** bez kontextu. Sdílený je výpis
 * enumu ({@see ContentTagPrompt}); zbytek promptu je jiný, protože jiná
 * úloha dává jiné odpovědi.
 *
 * Vlastnosti, na kterých záleží:
 *  - **dávkování** (~50 textů / volání) — jinak je report nad tisíci texty
 *    neúnosně drahý a pomalý,
 *  - **cache** ({@see TagCache}) — druhý běh nad týmž souborem nevolá LLM,
 *  - **selhání dávky neshodí běh** ani neotráví cache: texty zůstanou
 *    neklasifikované a report to přizná,
 *  - **enum je autorita** — štítek mimo taxonomii se zahodí.
 *
 * Backend: `$backendNdx` (z `--backend` nebo settings
 * `exchange.contentTag.backend`), jinak default backend DS — stejná
 * kaskáda jako D18.
 */
class BookingHistoryClassifier
{
    /** Verze promptu — změna promptu zneplatní cache. */
    public const PROMPT_VERSION = 'bh-tag-v1.0.0';

    public const DEFAULT_BATCH_SIZE = 50;

    /**
     * Dávka 50 textů × ~20 tokenů odpovědi + režie JSON. Strop, ne cena:
     * na modelech s thinking se přemýšlení počítá do `max_tokens`, proto
     * řádově víc než délka odpovědi (tasks/ai-models-phase0.md F0-D5).
     */
    private const MAX_TOKENS = 16000;

    private const SYSTEM_PROMPT = <<<'PROMPT'
        You classify short accounting line-item texts into a fixed semantic
        taxonomy of content tags. Each input text is one numbered line from
        an accounting document; classify each independently by what was
        bought or paid for.

        Rules:
        - Use only tags from the provided list. Never invent a tag.
        - If no tag fits a text, return null for it — that is a legitimate
          and expected answer for vague or administrative texts.
        - Texts may be in Czech or English, often abbreviated.

        Respond with JSON only, no prose, no code fences: a JSON array with
        one entry per input text, in any order:
        [{"i": 0, "tag": "it.internet"}, {"i": 1, "tag": null}]
        PROMPT;

    public function __construct(
        private readonly LlmClient $llm,
        private readonly AiBackendResolver $backends,
        private readonly ?ConfigRuntime $config = null,
        private readonly ?int $backendNdx = null,
        private readonly ?TagCache $cache = null,
        private readonly int $batchSize = self::DEFAULT_BATCH_SIZE,
    ) {}

    /**
     * Dávkové volání nad clustery. Vrací mapu `rowTextNorm → štítek|null`
     * jen pro texty, které se **podařilo** vyhodnotit (z cache nebo z LLM);
     * texty z padlých dávek v mapě nejsou — volající je pozná podle
     * `llmClassified` v analýze.
     *
     * `$progress` dostane po každé dávce (hotovo, celkem) — CLI z toho
     * kreslí postup, aby dlouhý běh nevypadal jako zamrznutí.
     *
     * @param list<TextCluster> $clusters
     * @param (callable(int, int): void)|null $progress
     * @return array{tags: array<string, string|null>, cached: int, classified: int, calls: int, failedBatches: int, unavailable: bool}
     */
    public function classify(array $clusters, ?callable $progress = null): array
    {
        $result = [
            'tags' => [], 'cached' => 0, 'classified' => 0,
            'calls' => 0, 'failedBatches' => 0, 'unavailable' => false,
        ];
        if ($clusters === []) {
            return $result;
        }

        $cached = $this->cache?->load(self::PROMPT_VERSION) ?? [];
        $todo = [];
        foreach ($clusters as $cluster) {
            if (array_key_exists($cluster->norm, $cached)) {
                $result['tags'][$cluster->norm] = $cached[$cluster->norm];
                $result['cached']++;
                continue;
            }
            $todo[] = $cluster;
        }

        $total = count($todo);
        if ($total === 0) {
            $progress?->__invoke(0, 0);
            return $result;
        }

        $taxonomy = $this->taxonomy();
        $params = $taxonomy !== [] ? $this->baseParams() : null;
        if ($params === null) {
            // Chybějící taxonomie / backend / klíč — ne chyba běhu, jen
            // nedostupná klasifikace (report pojede z reverzu).
            $result['unavailable'] = true;
            return $result;
        }

        $done = 0;
        foreach (array_chunk($todo, max(1, $this->batchSize)) as $batch) {
            $result['calls']++;
            $tags = $this->classifyBatch($batch, $taxonomy, $params);
            $done += count($batch);
            if ($tags === null) {
                $result['failedBatches']++;
            } else {
                $this->cache?->append($tags, self::PROMPT_VERSION);
                foreach ($tags as $norm => $tag) {
                    $result['tags'][$norm] = $tag;
                    $result['classified']++;
                }
            }
            $progress?->__invoke($done, $total);
        }

        return $result;
    }

    /**
     * @param list<TextCluster> $batch
     * @param array<string, mixed> $taxonomy
     * @return array<string, string|null>|null null = dávka selhala
     */
    private function classifyBatch(array $batch, array $taxonomy, LlmChatParams $params): ?array
    {
        try {
            $prompt = $this->userPrompt($batch, $taxonomy);
            $result = $this->llm->streamChat(
                new LlmChatParams(
                    provider: $params->provider,
                    model: $params->model,
                    apiKey: $params->apiKey,
                    baseUrl: $params->baseUrl,
                    system: self::SYSTEM_PROMPT,
                    messages: [['role' => 'user', 'content' => $prompt]],
                    maxTokens: self::MAX_TOKENS,
                    temperature: null,
                    tools: null,
                    thinking: $params->thinking,
                    effort: $params->effort,
                ),
                static function (string $delta): void {},
            );
            if (!$result->isComplete()) {
                // Useknutá nebo odmítnutá odpověď (F0-D4) = selhaná dávka,
                // texty zůstanou neklasifikované a cache se neotráví.
                ErrorLogger::warn('BookingHistoryClassifier: incomplete model output — batch failed', [
                    'stopReason' => $result->stopReason,
                    'model' => $result->model ?? $params->model,
                    'maxTokens' => self::MAX_TOKENS,
                ]);
                return null;
            }
            return $this->parseOutput($result->text, $batch, $taxonomy);
        } catch (\Throwable $e) {
            ErrorLogger::logException($e, 'BookingHistoryClassifier: batch failed');
            return null;
        }
    }

    /**
     * @param list<TextCluster> $batch
     * @param array<string, mixed> $taxonomy
     */
    private function userPrompt(array $batch, array $taxonomy): string
    {
        $lines = [ContentTagPrompt::taxonomyBlock($taxonomy), '', 'Texts:'];
        foreach ($batch as $index => $cluster) {
            // Jednořádkový text — model dostane obsah, ne formátování.
            $text = (string) preg_replace('/\s+/u', ' ', $cluster->text);
            $lines[] = "  [{$index}] {$text}";
        }
        return implode("\n", $lines);
    }

    /**
     * Neznámý štítek → null (enum je autorita). Index mimo dávku → zahodit.
     * Nevalidní JSON → celá dávka selhala.
     *
     * @param list<TextCluster> $batch
     * @param array<string, mixed> $taxonomy
     * @return array<string, string|null>|null
     */
    private function parseOutput(string $text, array $batch, array $taxonomy): ?array
    {
        $text = trim($text);
        if (str_starts_with($text, '```')) {
            $text = trim((string) preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $text));
        }
        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            ErrorLogger::warn('BookingHistoryClassifier: non-JSON output discarded');
            return null;
        }
        // Tolerance k obalení do objektu ({"results": [...]}).
        if (!array_is_list($decoded)) {
            foreach ($decoded as $value) {
                if (is_array($value) && array_is_list($value)) {
                    $decoded = $value;
                    break;
                }
            }
        }
        if (!array_is_list($decoded)) {
            ErrorLogger::warn('BookingHistoryClassifier: output is not a list');
            return null;
        }

        // Texty, na které model neodpověděl, zůstávají neklasifikované —
        // radši je nabídnout znovu než je zamrazit jako „bez štítku".
        $tags = [];
        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $index = $entry['i'] ?? null;
            if (!is_int($index) && !(is_string($index) && preg_match('/^\d+$/', $index) === 1)) {
                continue;
            }
            $cluster = $batch[(int) $index] ?? null;
            if ($cluster === null) {
                continue;
            }
            $tag = $entry['tag'] ?? null;
            if ($tag !== null && (!is_string($tag) || !array_key_exists($tag, $taxonomy))) {
                ErrorLogger::warn('BookingHistoryClassifier: unknown tag discarded', [
                    'tag' => is_string($tag) ? $tag : gettype($tag),
                ]);
                $tag = null;
            }
            $tags[$cluster->norm] = $tag;
        }
        return $tags;
    }

    /** @return array<string, mixed> */
    private function taxonomy(): array
    {
        $taxonomy = $this->config?->cfgItem('core.exchange.contentTags');
        return is_array($taxonomy) ? $taxonomy : [];
    }

    /**
     * Backend + klíč jednou pro celý běh (ne per dávku) — jinak by se
     * dešifrování klíče opakovalo u každého volání. Teplota se neposílá
     * nikdy (F0-D1); thinking / effort z backendu (F0-D7).
     */
    private function baseParams(): ?LlmChatParams
    {
        try {
            $backend = $this->backendNdx !== null
                ? $this->backends->backendByNdx($this->backendNdx)
                : $this->backends->defaultBackend();
            if ($backend === null) {
                return null;
            }
            $apiKey = $this->backends->apiKey($backend);
            if ($apiKey === null) {
                return null;
            }
            $tuning = AiBackendResolver::tuning($backend);
            return new LlmChatParams(
                provider: (string) ($backend['provider'] ?? 'anthropic'),
                model: (string) ($backend['model'] ?? ''),
                apiKey: $apiKey,
                baseUrl: isset($backend['base_url']) && $backend['base_url'] !== null
                    ? (string) $backend['base_url']
                    : '',
                system: self::SYSTEM_PROMPT,
                messages: [],
                maxTokens: self::MAX_TOKENS,
                temperature: null,
                tools: null,
                thinking: $tuning['thinking'],
                effort: $tuning['effort'],
            );
        } catch (\Throwable $e) {
            ErrorLogger::logException($e, 'BookingHistoryClassifier: backend unavailable');
            return null;
        }
    }
}

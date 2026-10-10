<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\BookingHistory;

use Shipard\Core\Ai\AiCallOverride;
use Shipard\Core\Ai\AiFunctionResolver;
use Shipard\Core\Ai\Exception\AiFunctionException;
use Shipard\Core\Ai\ResolvedAiCall;
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
 * Model, připojení a ladění určuje AI funkce `exchange.bookingHistory`
 * (tasks/ai-functions.md D36, D40): deklarace (strop 16000 — dávka 50
 * textů × ~20 tokenů odpovědi, na modelech s thinking i přemýšlení)
 * → řádek funkce v Nastavení → mapa úrovní; `$override` z voleb příkazu
 * (`--backend`, `--model`, `--model-like`) má přednost.
 */
class BookingHistoryClassifier
{
    /** Verze promptu — změna promptu zneplatní cache. */
    public const PROMPT_VERSION = 'bh-tag-v1.0.0';

    public const DEFAULT_BATCH_SIZE = 50;

    public const string AI_FUNCTION = 'exchange.bookingHistory';

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
        private readonly AiFunctionResolver $functions,
        private readonly ?ConfigRuntime $config = null,
        private readonly ?AiCallOverride $override = null,
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
        $call = $taxonomy !== [] ? $this->resolveCall() : null;
        if ($call === null) {
            // Chybějící taxonomie / připojení / klíč — ne chyba běhu, jen
            // nedostupná klasifikace (report pojede z reverzu).
            $result['unavailable'] = true;
            return $result;
        }

        $done = 0;
        foreach (array_chunk($todo, max(1, $this->batchSize)) as $batch) {
            $result['calls']++;
            $tags = $this->classifyBatch($batch, $taxonomy, $call);
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
    private function classifyBatch(array $batch, array $taxonomy, ResolvedAiCall $call): ?array
    {
        try {
            $prompt = $this->userPrompt($batch, $taxonomy);
            $params = $call->chatParams(
                system: self::SYSTEM_PROMPT,
                messages: [['role' => 'user', 'content' => $prompt]],
            );
            $result = $this->llm->streamChat($params, static function (string $delta): void {});
            if (!$result->isComplete()) {
                // Useknutá nebo odmítnutá odpověď (F0-D4) = selhaná dávka,
                // texty zůstanou neklasifikované a cache se neotráví.
                ErrorLogger::warn('BookingHistoryClassifier: incomplete model output — batch failed', [
                    'stopReason' => $result->stopReason,
                    'model' => $result->model ?? $params->model,
                    'maxTokens' => $params->maxTokens,
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
     * Rozhodnutí o volání jednou pro celý běh (ne per dávku) — jinak by se
     * dešifrování klíče opakovalo u každého volání.
     */
    private function resolveCall(): ?ResolvedAiCall
    {
        try {
            return $this->functions->resolve(self::AI_FUNCTION, $this->override);
        } catch (AiFunctionException $e) {
            ErrorLogger::warn('BookingHistoryClassifier: AI function unavailable — classification skipped', [
                'code' => $e->errorCode,
                'error' => $e->getMessage(),
            ]);
            return null;
        } catch (\Throwable $e) {
            ErrorLogger::logException($e, 'BookingHistoryClassifier: AI function resolution failed');
            return null;
        }
    }
}

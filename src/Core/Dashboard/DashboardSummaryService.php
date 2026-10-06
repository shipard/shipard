<?php

declare(strict_types=1);

namespace Shipard\Core\Dashboard;

use Shipard\Core\Ai\AiBackendResolver;
use Shipard\Core\Ai\LlmChatParams;
use Shipard\Core\Ai\LlmChatResult;
use Shipard\Core\Ai\LlmClient;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Logging\ErrorLogger;

/**
 * Generated AI summary of the dashboard feed (phase 2b).
 *
 * Builds a compact digest from the feed cards (counts by kind + top cards +
 * today's date + language), hashes it, and serves the summary from the
 * `core_ai_dashboard_summary` cache when the hash matches — one LLM call per
 * feed change per language, and at least one per day (the date is part of the
 * hash, D12). On a miss it streams one plain `LlmClient::streamChat` call
 * (no tools) and upserts the cache.
 *
 * Degrades to `text = null` (frontend keeps the static count text) when the
 * feed has no actionable cards, no default backend exists, or the backend has
 * no usable API key. LLM/transport errors propagate to the caller — the SSE
 * endpoint maps them to an `error` event and nothing is cached.
 *
 * See docs/dashboard.md §AI shrnutí.
 */
final readonly class DashboardSummaryService
{
    private const string CACHE_TABLE = 'core_ai_dashboard_summary';

    /** Karty vstupující do digestu/promptu (nejvýše, feed je už seřazený). */
    private const int TOP_CARDS = 6;

    private const int MAX_TOKENS = 300;

    public function __construct(
        private DataSourceConnection $db,
        private LlmClient $llm,
        private AiBackendResolver $backends,
    ) {}

    /**
     * Returns the summary for the given feed, streaming text deltas through
     * $onDelta on a cache miss. `text = null` means "no summary" (empty feed
     * or degradation) — never an error state.
     *
     * @param list<array<string, mixed>> $cards    sorted feed cards (DashboardController::collectCards)
     * @param callable(string): void     $onDelta
     * @return array{text: ?string, cached: bool}
     */
    public function stream(array $cards, string $language, callable $onDelta): array
    {
        $digest = $this->buildDigest($cards, $language, date('Y-m-d'));
        if ($this->isEmpty($digest)) {
            return ['text' => null, 'cached' => false];
        }
        $hash = hash('sha256', json_encode($digest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        $cached = $this->readCache($language);
        if ($cached !== null && $cached['input_hash'] === $hash && $cached['text'] !== null && $cached['text'] !== '') {
            return ['text' => (string) $cached['text'], 'cached' => true];
        }

        $backend = $this->backends->defaultBackend();
        if ($backend === null) {
            return ['text' => null, 'cached' => false];
        }
        try {
            $apiKey = $this->backends->apiKey($backend);
        } catch (\Throwable $e) {
            ErrorLogger::warn('DashboardSummaryService: backend key decrypt failed', ['error' => $e->getMessage()]);
            return ['text' => null, 'cached' => false];
        }
        if ($apiKey === null) {
            return ['text' => null, 'cached' => false];
        }

        $params = new LlmChatParams(
            provider: (string) ($backend['provider'] ?? 'anthropic'),
            model: (string) ($backend['model'] ?? ''),
            apiKey: $apiKey,
            baseUrl: $backend['base_url'] !== null ? (string) $backend['base_url'] : '',
            system: $this->systemPrompt($language),
            messages: [['role' => 'user', 'content' => $this->userPrompt($digest)]],
            maxTokens: self::MAX_TOKENS,
            temperature: null, // Opus 4.7/4.8 reject `temperature` (HTTP 400)
            tools: null,
        );

        $result = $this->llm->streamChat($params, $onDelta);
        $text   = trim($result->text);
        if ($text === '') {
            return ['text' => null, 'cached' => false];
        }

        $this->upsertCache($language, $hash, $text, $result);
        return ['text' => $text, 'cached' => false];
    }

    /**
     * Canonical digest — the single input for both the hash and the prompt
     * (D13). Contains today's date so the summary regenerates at least once
     * a day (D12). Only actionable cards (urgent/review/ready) participate;
     * info cards (other mail, digest) carry no signal. Caller passes all
     * cards (FeedResult::$allCards, #101) — counts are truthful above the
     * per-section cap and top cards follow the section order.
     *
     * @param list<array<string, mixed>> $cards
     * @return array{date: string, language: string, counts: array{urgent: int, review: int, ready: int}, topCards: list<array{kind: string, id: string, title: string, subtitle: string}>}
     * @internal Public pro účely testů (stabilita hashe) — čistá transformace.
     */
    public function buildDigest(array $cards, string $language, string $today): array
    {
        $counts   = ['urgent' => 0, 'review' => 0, 'ready' => 0];
        $topCards = [];
        foreach ($cards as $card) {
            $kind = (string) ($card['kind'] ?? '');
            if (!isset($counts[$kind])) {
                continue;
            }
            $counts[$kind]++;
            if (count($topCards) < self::TOP_CARDS) {
                $topCards[] = [
                    'kind'     => $kind,
                    'id'       => (string) ($card['id'] ?? ''),
                    'title'    => (string) ($card['title'] ?? ''),
                    'subtitle' => (string) ($card['subtitle'] ?? ''),
                ];
            }
        }

        return [
            'date'     => $today,
            'language' => $language,
            'counts'   => $counts,
            'topCards' => $topCards,
        ];
    }

    /**
     * @param array<string, mixed> $digest
     */
    private function isEmpty(array $digest): bool
    {
        return array_sum($digest['counts']) === 0;
    }

    private function systemPrompt(string $language): string
    {
        $languageLine = $language === 'cs'
            ? 'Odpovídej česky.'
            : 'Respond in English.';

        return 'Jsi asistent účetního systému Shipard. Shrň uživateli dnešní stav '
            . 'domovského feedu: co je nejnaléhavější, co čeká na kontrolu a co je '
            . 'připravené k použití. Piš 2 až 4 věty souvislé prózy — žádné odrážky, '
            . 'žádné nadpisy, žádný úvod typu „Zde je shrnutí“. Nejnaléhavější věci '
            . 'zmiň první. Vycházej výhradně z dodaných dat; nic si nedomýšlej ani '
            . 'nepřidávej rady, které z dat neplynou. ' . $languageLine;
    }

    /**
     * Serializes the digest for the user message — counts and the top
     * cards with title/subtitle. Deliberately compact; never the full
     * extracted_json (D13).
     *
     * @param array<string, mixed> $digest
     */
    private function userPrompt(array $digest): string
    {
        $counts = $digest['counts'];
        $lines  = [
            'Datum: ' . $digest['date'],
            "Počty karet: naléhavé={$counts['urgent']}, ke kontrole={$counts['review']}, připravené={$counts['ready']}",
        ];

        if ($digest['topCards'] !== []) {
            $lines[] = 'Nejdůležitější karty:';
            foreach ($digest['topCards'] as $card) {
                $line = "- [{$card['kind']}] {$card['title']}";
                if ($card['subtitle'] !== '') {
                    $line .= " — {$card['subtitle']}";
                }
                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readCache(string $language): ?array
    {
        return $this->db->fetchRow(
            'SELECT * FROM `' . self::CACHE_TABLE . '` WHERE `language` = %s LIMIT 1',
            $language,
        );
    }

    private function upsertCache(string $language, string $hash, string $text, LlmChatResult $result): void
    {
        $row = [
            'input_hash'    => $hash,
            'text'          => $text,
            'input_tokens'  => $result->inputTokens,
            'output_tokens' => $result->outputTokens,
            'generated_at'  => date('Y-m-d H:i:s'),
        ];

        $existing = $this->readCache($language);
        if ($existing !== null) {
            $this->db->updateWhere(self::CACHE_TABLE, $row, '`language` = %s', $language);
            return;
        }
        $this->db->insertRow(self::CACHE_TABLE, $row + ['language' => $language]);
    }
}

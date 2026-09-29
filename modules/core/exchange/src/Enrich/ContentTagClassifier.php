<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Enrich;

use Shipard\Core\Ai\AiBackendResolver;
use Shipard\Core\Ai\LlmChatParams;
use Shipard\Core\Ai\LlmClient;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Module\Core\Exchange\Document\CanonicalRowText;

/**
 * LLM část obsahové eskalace (tasks/content-tag-enrichment.md, D17/D18):
 * klasifikace dokladu do fixní taxonomie core.exchange.contentTags.
 *
 * Volá se právě jednou za běh analýzy (při /result, D16) a jen když po
 * Vrstvě 0 zbývají nepokryté item řádky a nezasáhlo pravidlo IČO (D12).
 * Jakékoli selhání (chybějící backend/klíč, síť, nevalidní výstup) →
 * null, zalogováno, nikdy nepropadne výš (D17) — /result nesmí spadnout.
 *
 * Backend: override ze settings `exchange.contentTag.backend` (ndx), jinak
 * default backend DS. Doporučení: levný model (Haiku) přes dedikovaný
 * backend — klasifikace je triviální úloha.
 */
class ContentTagClassifier
{
    public const TAG_PROMPT_VERSION = 'tag-v1.0.0';

    private const MAX_TOKENS = 500;

    /** Ořez digestu — víc řádků model pro dominantní štítek nepotřebuje. */
    private const MAX_ROWS = 50;

    private const SYSTEM_PROMPT = <<<'PROMPT'
        You classify accounting documents (invoices, receipts) into a fixed
        semantic taxonomy of content tags. Pick the single tag that best
        describes what the document as a whole is about (primaryTag). When
        individual line items clearly belong to a different tag than the
        document's primary tag, list them in rowExceptions. If no tag fits,
        return null for primaryTag — that is a legitimate answer; never
        invent tags outside the provided list.

        Respond with JSON only, no prose, no code fences:
        {"primaryTag": string|null, "confidence": number between 0 and 1,
         "rowExceptions": [{"rowIndex": int, "tag": string}]}
        PROMPT;

    public function __construct(
        private readonly LlmClient $llm,
        private readonly AiBackendResolver $backends,
        private readonly ?ConfigRuntime $config = null,
        private readonly ?int $backendNdx = null,
    ) {}

    /**
     * Klasifikace canonicalu. null = klasifikace nedostupná nebo selhala
     * (chybějící taxonomie/backend/klíč, výjimka klienta, nevalidní JSON).
     *
     * @param array<string, mixed> $canonical
     * @return array{primaryTag: ?string, confidence: float, rowExceptions: list<array{rowIndex: int, tag: string}>}|null
     */
    public function classify(array $canonical): ?array
    {
        try {
            return $this->classifyInner($canonical);
        } catch (\Throwable $e) {
            ErrorLogger::logException($e, 'ContentTagClassifier: classification failed');
            return null;
        }
    }

    /**
     * @param array<string, mixed> $canonical
     * @return array{primaryTag: ?string, confidence: float, rowExceptions: list<array{rowIndex: int, tag: string}>}|null
     */
    private function classifyInner(array $canonical): ?array
    {
        $taxonomy = $this->config?->cfgItem('core.exchange.contentTags');
        if (!is_array($taxonomy) || $taxonomy === []) {
            return null;
        }

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

        $params = new LlmChatParams(
            provider: (string) ($backend['provider'] ?? 'anthropic'),
            model: (string) ($backend['model'] ?? ''),
            apiKey: $apiKey,
            baseUrl: isset($backend['base_url']) && $backend['base_url'] !== null
                ? (string) $backend['base_url']
                : '',
            system: self::SYSTEM_PROMPT,
            messages: [['role' => 'user', 'content' => $this->userPrompt($canonical, $taxonomy)]],
            maxTokens: self::MAX_TOKENS,
            temperature: null,
            tools: null,
        );

        $result = $this->llm->streamChat($params, static function (string $delta): void {});
        return $this->parseOutput($result->text, $taxonomy);
    }

    /**
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $taxonomy
     */
    private function userPrompt(array $canonical, array $taxonomy): string
    {
        $lines = [ContentTagPrompt::taxonomyBlock($taxonomy)];

        $lines[] = '';
        $lines[] = 'Document:';

        $counterKey = ($canonical['selfParty'] ?? null) === 'supplier' ? 'customer' : 'supplier';
        $party = is_array($canonical[$counterKey] ?? null) ? $canonical[$counterKey] : [];
        $supplierName = trim((string) ($party['name'] ?? ''));
        $companyId    = trim((string) ($party['companyId'] ?? ''));
        if ($supplierName !== '' || $companyId !== '') {
            $lines[] = '  supplier: ' . trim($supplierName . ' ' . ($companyId !== '' ? "(companyId {$companyId})" : ''));
        }
        $docNumber = trim((string) ($canonical['docNumber'] ?? ''));
        if ($docNumber !== '') {
            $lines[] = "  docNumber: {$docNumber}";
        }
        $docType = trim((string) ($canonical['docType'] ?? ''));
        if ($docType !== '') {
            $lines[] = "  docType: {$docType}";
        }

        // Všechny řádky (s ořezem) — model potřebuje celek pro dominantní
        // štítek, ne jen nepokryté řádky.
        $rows = is_array($canonical['rows'] ?? null) ? $canonical['rows'] : [];
        $lines[] = '  rows:';
        $count = 0;
        foreach ($rows as $idx => $row) {
            if (!is_array($row)) {
                continue;
            }
            if (++$count > self::MAX_ROWS) {
                $lines[] = '    … (' . (count($rows) - self::MAX_ROWS) . ' more rows omitted)';
                break;
            }
            // Text řádku stejný, jaký skončí na dokladu (CanonicalRowText, #84).
            $text = CanonicalRowText::compose($row) ?? '';
            $total = $row['totalPrice'] ?? null;
            $lines[] = "    [{$idx}] {$text}" . (is_numeric($total) ? " (total {$total})" : '');
        }

        $totals = is_array($canonical['totals'] ?? null) ? $canonical['totals'] : [];
        $grand = $totals['totalAmount'] ?? null;
        if (is_numeric($grand)) {
            $currency = trim((string) ($canonical['currency'] ?? ''));
            $lines[] = '  total: ' . $grand . ($currency !== '' ? " {$currency}" : '');
        }

        return implode("\n", $lines);
    }

    /**
     * Parsování výstupu — instrukce říká „pouze JSON", ale ochranně se
     * loupou code fences. Neznámý klíč štítku → zahodit (lekce enum:
     * model si štítky vymýšlí, enum je tady jediná autorita). null
     * primaryTag je legitimní výstup.
     *
     * @param array<string, mixed> $taxonomy
     * @return array{primaryTag: ?string, confidence: float, rowExceptions: list<array{rowIndex: int, tag: string}>}|null
     */
    private function parseOutput(string $text, array $taxonomy): ?array
    {
        $text = trim($text);
        if (str_starts_with($text, '```')) {
            $text = trim((string) preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $text));
        }
        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            ErrorLogger::warn('ContentTagClassifier: non-JSON output discarded');
            return null;
        }

        $primaryTag = $decoded['primaryTag'] ?? null;
        if ($primaryTag !== null && (!is_string($primaryTag) || !array_key_exists($primaryTag, $taxonomy))) {
            ErrorLogger::warn('ContentTagClassifier: unknown primaryTag discarded', [
                'tag' => is_string($primaryTag) ? $primaryTag : gettype($primaryTag),
            ]);
            $primaryTag = null;
        }

        $exceptions = [];
        foreach ((array) ($decoded['rowExceptions'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $rowIndex = $entry['rowIndex'] ?? null;
            $tag = $entry['tag'] ?? null;
            if (is_int($rowIndex) && $rowIndex >= 0
                && is_string($tag) && array_key_exists($tag, $taxonomy)
            ) {
                $exceptions[] = ['rowIndex' => $rowIndex, 'tag' => $tag];
            }
        }

        $confidence = $decoded['confidence'] ?? null;
        return [
            'primaryTag'    => $primaryTag,
            'confidence'    => is_numeric($confidence) ? max(0.0, min(1.0, (float) $confidence)) : 0.0,
            'rowExceptions' => $exceptions,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Enrich;

use Shipard\Core\Ai\AiCallOverride;
use Shipard\Core\Ai\AiFunctionResolver;
use Shipard\Core\Ai\Exception\AiFunctionException;
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
 * Model, připojení a ladění určuje AI funkce `exchange.contentTags`
 * (tasks/ai-functions.md D36, D40): deklarace úrovně fast se stropem 8000
 * → řádek funkce v Nastavení (levný model přes vlastní připojení) → mapa
 * úrovní. Nenastavená funkce = klasifikace přeskočena (null).
 */
class ContentTagClassifier
{
    public const TAG_PROMPT_VERSION = 'tag-v1.0.0';

    public const string AI_FUNCTION = 'exchange.contentTags';

    /** Ořez digestu — víc řádků model pro dominantní štítek nepotřebuje. */
    private const MAX_ROWS = 50;

    /**
     * Spotřeba posledního volání modelu (tokeny, model, cena podle rozhodnutí
     * resolveru) — i u useknuté odpovědi, útrata nastala. Čte a nuluje
     * {@see takeLastUsage()} (evaluace `ai-eval`, #103 F6b D56).
     *
     * @var array{tokensIn: int, tokensOut: int, model: ?string, costUsd: ?float}|null
     */
    private ?array $lastUsage = null;

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

    /**
     * @param AiCallOverride|null $override Přepsání volání funkce
     *        `exchange.contentTags` (varianta evaluace `ai-eval`,
     *        tasks/testing-phase4.md D35); null = nastavení zdroje dat.
     */
    public function __construct(
        private readonly LlmClient $llm,
        private readonly AiFunctionResolver $functions,
        private readonly ?ConfigRuntime $config = null,
        private readonly ?AiCallOverride $override = null,
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
        $this->lastUsage = null;
        try {
            return $this->classifyInner($canonical);
        } catch (\Throwable $e) {
            ErrorLogger::logException($e, 'ContentTagClassifier: classification failed');
            return null;
        }
    }

    /**
     * Spotřeba posledního volání modelu a jeho vynulování; null = LLM se
     * nevolalo (chybí taxonomie, nenastavená funkce, výjimka před voláním).
     *
     * @return array{tokensIn: int, tokensOut: int, model: ?string, costUsd: ?float}|null
     */
    public function takeLastUsage(): ?array
    {
        $usage = $this->lastUsage;
        $this->lastUsage = null;
        return $usage;
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

        try {
            $call = $this->functions->resolve(self::AI_FUNCTION, $this->override);
        } catch (AiFunctionException $e) {
            // Nenastavená AI (bez připojení / klíče, špatný model) — žádná
            // klasifikace, žádná chyba běhu (D17).
            ErrorLogger::info('ContentTagClassifier: AI function unavailable — classification skipped', [
                'code' => $e->errorCode,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
        $params = $call->chatParams(
            system: self::SYSTEM_PROMPT,
            messages: [['role' => 'user', 'content' => $this->userPrompt($canonical, $taxonomy)]],
        );

        $result = $this->llm->streamChat($params, static function (string $delta): void {});
        $tokensIn = $result->inputTokens ?? 0;
        $tokensOut = $result->outputTokens ?? 0;
        try {
            $cost = $call->costUsd($this->functions->catalog(), $tokensIn, $tokensOut);
        } catch (\Throwable) {
            $cost = null; // bez katalogu (testy s mockem resolveru) jen tokeny
        }
        $this->lastUsage = ['tokensIn' => $tokensIn, 'tokensOut' => $tokensOut, 'model' => $result->model ?? $params->model, 'costUsd' => $cost];
        if (!$result->isComplete()) {
            // Useknutá nebo odmítnutá odpověď (F0-D4) = totéž co selhání.
            ErrorLogger::warn('ContentTagClassifier: incomplete model output discarded', [
                'stopReason' => $result->stopReason,
                'model' => $result->model ?? $params->model,
                'maxTokens' => $params->maxTokens,
            ]);
            return null;
        }
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

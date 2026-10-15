<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Eval;

use Shipard\Core\Ai\ResolvedAiCall;
use Shipard\Module\Core\Exchange\Compare\CanonicalComparator;
use Shipard\Module\Core\Exchange\Compare\FieldOutcome;
use Shipard\Module\Core\Exchange\Compare\FieldState;
use Shipard\Module\Core\Mail\Analysis\AnalysisComputing;
use Shipard\Module\Core\Mail\Analysis\AnalysisOutcome;
use Shipard\Module\Core\Mail\Analysis\AnalysisRequest;

/**
 * Běh evaluace (D34, D41): zprávy postupně, každá `repeat`krát —
 * {@see AnalysisComputing} nanečisto (nic se nezapisuje), výstup
 * porovnaný s očekáváním sady ({@see CanonicalComparator::evaluate()})
 * plus virtuální list klasifikace zprávy. Selhání volání (síť, parser,
 * nevalidní canonical) je výsledek zprávy, ne pád běhu; neočekávaná
 * výjimka výpočtu = `internal`. Selhané volání nese každý neprázdný list
 * očekávání jako `missing` (D44, {@see failed()}). Syrový text modelu jde
 * volitelně do složky mimo repo (`--save-raw`).
 */
final class EvalRunner
{
    /**
     * @param array<string, mixed>                        $profile  řádek profilu pošty
     * @param \Closure(EvalCallResult, EvalMessage): void|null $onResult průběžný výpis
     */
    public function __construct(
        private readonly AnalysisComputing $computation,
        private readonly CanonicalComparator $comparator,
        private readonly EvalDataSource $dataSource,
        private readonly array $profile,
        private readonly ResolvedAiCall $call,
        private readonly ?string $promptTemplate = null,
        private readonly int $repeat = 1,
        private readonly ?string $rawDir = null,
        private readonly ?\Closure $onResult = null,
    ) {}

    /**
     * @param list<EvalMessage>  $messages
     * @param array<string, int> $ndxByCode `message_id` → id ve zdroji dat
     * @return list<EvalCallResult>
     * @throws EvalException zpráva sady ve zdroji dat chybí
     */
    public function run(array $messages, array $ndxByCode): array
    {
        $results = [];
        foreach ($messages as $message) {
            $ndx = $ndxByCode[$message->messageId] ?? null;
            if ($ndx === null) {
                throw new EvalException("zpráva {$message->messageId} ve zdroji dat není — přeseeduj sadu");
            }
            $row = $this->dataSource->messageRow($ndx);
            for ($i = 1; $i <= max(1, $this->repeat); $i++) {
                $result = $this->evaluateOnce($message, $ndx, $row, $i);
                $results[] = $result;
                if ($this->onResult !== null) {
                    ($this->onResult)($result, $message);
                }
            }
        }
        return $results;
    }

    /** @param array<string, mixed> $row */
    private function evaluateOnce(EvalMessage $message, int $ndx, array $row, int $repeat): EvalCallResult
    {
        $request = new AnalysisRequest(
            messageId: $ndx,
            message: $row,
            profile: $this->profile,
            call: $this->call,
            promptTemplate: $this->promptTemplate,
        );
        try {
            $outcome = $this->computation->compute($request);
        } catch (\Throwable $e) {
            return $this->failed($message, $repeat, 'internal', get_class($e) . ': ' . $e->getMessage(), null, null);
        }
        $this->saveRaw($message, $repeat, $outcome->rawText);

        if (!$outcome->ok) {
            return $this->failed($message, $repeat, $outcome->errorType, $outcome->note, $outcome, null);
        }

        $classification = $outcome->body['message_classification'] ?? null;
        $primaryType = is_array($classification) && is_string($classification['primary_type'] ?? null) ? $classification['primary_type'] : null;
        $canonical = $outcome->validated?->canonical();
        if ($canonical === null) {
            $note = $outcome->validated === null
                ? 'result without a document (document is null)'
                : 'canonical failed schema validation: ' . self::validationIssues($outcome->validated->json);
            return $this->failed($message, $repeat, 'invalid_canonical', $note, $outcome, $primaryType);
        }

        $outcomes = $this->comparator->evaluate($message->expectedCanonical, $canonical);
        $outcomes[] = self::classificationOutcome($message->expectedPrimaryType, $primaryType);
        $tag = $canonical['_resolve']['contentTag']['tag'] ?? null;
        return new EvalCallResult(
            $message->messageId, $repeat, EvalCallResult::STATUS_OK, null, null,
            $outcome->costUsd, $outcome->tokensIn, $outcome->tokensOut, $outcome->durationMs, $outcome->modelName,
            $primaryType, is_string($tag) ? $tag : null, $outcomes,
        );
    }

    /**
     * Selhané volání (D44): bez výstupu k porovnání nese každý neprázdný list
     * očekávání jako `missing` ({@see CanonicalComparator::evaluateAgainstNothing()})
     * plus virtuální list klasifikace — metriky polí selhání započítají,
     * místo aby ho vynechaly. Cena a tokeny podle výsledku výpočtu, u pádu
     * výpočtu (`internal`) nic.
     */
    private function failed(EvalMessage $message, int $repeat, ?string $errorType, ?string $note, ?AnalysisOutcome $outcome, ?string $primaryType): EvalCallResult
    {
        $outcomes = $this->comparator->evaluateAgainstNothing($message->expectedCanonical);
        $outcomes[] = self::classificationOutcome($message->expectedPrimaryType, $primaryType);
        return new EvalCallResult(
            $message->messageId, $repeat, EvalCallResult::STATUS_FAILED, $errorType, $note,
            $outcome?->costUsd, $outcome?->tokensIn, $outcome?->tokensOut, $outcome?->durationMs, $outcome?->modelName,
            $primaryType, null, $outcomes,
        );
    }

    /** Virtuální list klasifikace zprávy (`primary_type`) mimo canonical. */
    public static function classificationOutcome(?string $expected, ?string $actual): FieldOutcome
    {
        $state = match (true) {
            $expected === $actual => FieldState::Match,
            $expected === null => FieldState::Extra,
            $actual === null => FieldState::Missing,
            default => FieldState::Wrong,
        };
        return new FieldOutcome(EvalFieldGroups::CLASSIFICATION_PATH, $state, $expected, $actual);
    }

    private static function validationIssues(?string $json): string
    {
        $wrapped = is_string($json) ? json_decode($json, true) : null;
        $issues = is_array($wrapped) ? ($wrapped['_validationIssues'] ?? []) : [];
        $parts = [];
        foreach (array_slice((array) $issues, 0, 3) as $issue) {
            if (!is_array($issue)) {
                continue;
            }
            $parts[] = trim(((string) ($issue['path'] ?? '')) . ' ' . ((string) ($issue['code'] ?? '')) . ' — ' . ((string) ($issue['message'] ?? '')));
        }
        $more = count((array) $issues) > 3 ? ' (+' . (count((array) $issues) - 3) . ')' : '';
        return ($parts === [] ? 'no issue list' : implode('; ', $parts)) . $more;
    }

    private function saveRaw(EvalMessage $message, int $repeat, ?string $text): void
    {
        if ($this->rawDir === null || $text === null) {
            return;
        }
        @file_put_contents($this->rawDir . '/' . $message->messageId . '-' . $repeat . '.txt', $text);
    }
}

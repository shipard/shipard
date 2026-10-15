<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Analysis;

/**
 * Výsledek výpočtu analýzy ({@see AnalysisComputation::compute()}) — bez
 * zápisu kamkoli. Úspěch nese tělo výsledku (kontrakt v4), výstup modelu
 * a validovaný canonical; selhání typ chyby runneru (`config_error` /
 * `schema_error` / `ai_error`), poznámku a příznak `transient`
 * (přechodná chyba API — o opakování rozhoduje volající stropem selhání).
 * Cena, tokeny a čas jsou tam, kde model odpověděl.
 */
final readonly class AnalysisOutcome
{
    /**
     * @param array<string, mixed>|null $body
     * @param array<string, mixed>|null $parsed
     */
    private function __construct(
        public bool $ok,
        public ?array $body,
        public ?array $parsed,
        public ?ValidatedCanonical $validated,
        public ?string $errorType,
        public ?string $note,
        public bool $transient,
        public ?int $tokensIn,
        public ?int $tokensOut,
        public ?int $durationMs,
        public ?float $costUsd,
        public ?string $rawText,
        public ?string $modelName,
        public ?string $stopReason,
        public ?\Throwable $error,
    ) {}

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $parsed
     */
    public static function success(
        array $body,
        array $parsed,
        ?ValidatedCanonical $validated,
        int $tokensIn,
        int $tokensOut,
        int $durationMs,
        float $costUsd,
        string $rawText,
        string $modelName,
        ?string $stopReason,
    ): self {
        return new self(true, $body, $parsed, $validated, null, null, false, $tokensIn, $tokensOut, $durationMs, $costUsd, $rawText, $modelName, $stopReason, null);
    }

    public static function failure(
        string $errorType,
        string $note,
        bool $transient,
        ?int $tokensIn,
        ?float $costUsd,
        ?\Throwable $error = null,
        ?string $rawText = null,
        ?string $modelName = null,
        ?string $stopReason = null,
        ?int $tokensOut = null,
        ?int $durationMs = null,
    ): self {
        return new self(false, null, null, null, $errorType, $note, $transient, $tokensIn, $tokensOut, $durationMs, $costUsd, $rawText, $modelName, $stopReason, $error);
    }

    public function hasDocument(): bool
    {
        return is_array($this->parsed['document'] ?? null);
    }
}

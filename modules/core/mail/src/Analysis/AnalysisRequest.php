<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Analysis;

use Shipard\Core\Ai\ResolvedAiCall;

/**
 * Vstup jednoho výpočtu analýzy ({@see AnalysisComputation::compute()}):
 * řádek zprávy, AI profil, hotové rozhodnutí o volání a volitelně šablona
 * promptu ze souboru (varianta evaluace, tasks/testing-phase4.md D35 —
 * nahrazuje `prompt_template` profilu jen v paměti běhu). `beforeAttempt`
 * volá retry před každým pokusem (ostrý runner v něm prodlužuje lease;
 * výjimka hooku propadá volajícímu beze změny).
 */
final readonly class AnalysisRequest
{
    /**
     * @param array<string, mixed>     $message řádek zprávy: subject, sender_email, sender_name,
     *                                          body_plain, body_html, received_at, raw_source_attachment
     * @param array<string, mixed>     $profile řádek `core_mail_ai_profiles` (SELECT *)
     * @param \Closure(int): void|null $beforeAttempt
     * @param list<int>                $retryDelays pauzy mezi pokusy při přechodné chybě
     */
    public function __construct(
        public int $messageId,
        public array $message,
        public array $profile,
        public ResolvedAiCall $call,
        public ?string $promptTemplate = null,
        public ?\Closure $beforeAttempt = null,
        public array $retryDelays = AnalysisComputation::RETRY_DELAYS,
        public int $stallTimeoutSeconds = AnalysisComputation::STALL_TIMEOUT_SECONDS,
        public int $timeoutSeconds = AnalysisComputation::CALL_TIMEOUT_SECONDS,
    ) {}

    public function promptTemplate(): string
    {
        return $this->promptTemplate ?? (string) ($this->profile['prompt_template'] ?? '');
    }

    public function promptVersion(): string
    {
        return (string) ($this->profile['prompt_version'] ?? '');
    }

    public function profileNdx(): int
    {
        return (int) ($this->profile['id'] ?? 0);
    }
}

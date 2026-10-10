<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Analysis;

use Shipard\Core\Ai\ResolvedAiCall;

/**
 * Výsledek úspěšného claimu zprávy: řádek rezervace, zvolený AI profil
 * a hotové rozhodnutí o volání modelu (`ResolvedAiCall` funkce
 * `mail.extraction` s přepsáním z profilu, tasks/ai-functions.md D40)
 * včetně **dešifrovaného** klíče připojení. Klíč žije jen v paměti
 * volajícího — nikdy do logu, zprávy výjimky ani argumentů procesu.
 */
final readonly class AnalysisClaim
{
    /**
     * @param array<string, mixed> $profile Řádek `core_mail_ai_profiles` (SELECT *).
     */
    public function __construct(
        public int $claimId,
        public string $claimToken,
        public string $expiresAt,
        public array $profile,
        public ResolvedAiCall $call,
    ) {}

    public function profileNdx(): int
    {
        return (int) $this->profile['id'];
    }

    /** Id připojení (`core_ai_backends`), kterým běh jde — audit `backend_ndx`. */
    public function backendNdx(): int
    {
        return $this->call->connectionId;
    }

    /** ID modelu pro platformu (u vlastního ID tak, jak je zapsané). */
    public function model(): string
    {
        return $this->call->model;
    }
}

<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Analysis;

/**
 * Výsledek úspěšného claimu zprávy: řádek rezervace, zvolený AI profil
 * a backend a **dešifrovaný** klíč backendu. Klíč žije jen v paměti
 * volajícího — nikdy do logu, zprávy výjimky ani argumentů procesu.
 */
final readonly class AnalysisClaim
{
    /**
     * @param array<string, mixed> $profile Řádek `core_mail_ai_profiles` (SELECT *).
     * @param array<string, mixed> $backend Řádek `core_ai_backends` (SELECT *, `api_key` šifrovaný).
     */
    public function __construct(
        public int $claimId,
        public string $claimToken,
        public string $expiresAt,
        public array $profile,
        public array $backend,
        public string $apiKey,
    ) {}

    public function profileNdx(): int
    {
        return (int) $this->profile['id'];
    }

    public function backendNdx(): int
    {
        return (int) $this->backend['id'];
    }
}

<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Analysis;

use Shipard\Core\Database\DataSourceConnection;

/**
 * „Má analýza pošty kam jít?“ — jediné místo s kritériem použitelného
 * backendu: aktivní výchozí AI profil → aktivní backend s nastaveným
 * (šifrovaným) klíčem. Klíč se nedešifruje — rozhoduje jen přítomnost.
 *
 * Sdílí ho sweep runneru (`AnalysisRunner::sweep()` bez backendu frontu
 * nechá být) a upozornění „Analýza pošty stojí“
 * ({@see AnalysisStalledAlertCheck}: fronta bez klíče není porucha).
 */
final class AnalysisBackendProbe
{
    private const PROFILES_TABLE = 'core_mail_ai_profiles';
    private const BACKENDS_TABLE = 'core_ai_backends';

    public function __construct(
        private readonly DataSourceConnection $db,
    ) {}

    public function hasUsableBackend(): bool
    {
        $row = $this->db->fetchRow(
            'SELECT b.api_key FROM %n p JOIN %n b ON b.id = p.backend
              WHERE p.is_default = %i AND p.is_active = %i AND b.is_active = %i LIMIT 1',
            self::PROFILES_TABLE,
            self::BACKENDS_TABLE,
            1,
            1,
            1,
        );
        return $row !== null && (string) ($row['api_key'] ?? '') !== '';
    }
}

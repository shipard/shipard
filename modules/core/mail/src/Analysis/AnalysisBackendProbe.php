<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Analysis;

use Shipard\Core\Database\DataSourceConnection;

/**
 * „Má analýza pošty kam jít?“ — jediné místo s kritériem použitelného
 * připojení: aktivní výchozí AI profil → aktivní připojení s nastaveným
 * (šifrovaným) klíčem. Připojení se bere stejně jako v resolveru
 * (tasks/ai-functions.md D40): přepsání v profilu → řádek funkce
 * `mail.extraction` → výchozí připojení zdroje dat. Klíč se nedešifruje
 * — rozhoduje jen přítomnost.
 *
 * Sdílí ho sweep runneru (`AnalysisRunner::sweep()` bez připojení frontu
 * nechá být) a upozornění „Analýza pošty stojí“
 * ({@see AnalysisStalledAlertCheck}: fronta bez klíče není porucha).
 */
final class AnalysisBackendProbe
{
    private const PROFILES_TABLE = 'core_mail_ai_profiles';
    private const BACKENDS_TABLE = 'core_ai_backends';
    private const FUNCTIONS_TABLE = 'core_ai_function_settings';
    private const AI_FUNCTION = 'mail.extraction';

    public function __construct(
        private readonly DataSourceConnection $db,
    ) {}

    public function hasUsableBackend(): bool
    {
        $row = $this->db->fetchRow(
            'SELECT b.api_key FROM %n p
              LEFT JOIN %n f ON f.function_id = %s
              JOIN %n b ON b.id = COALESCE(p.backend, f.connection,
                  (SELECT d.id FROM %n d WHERE d.is_default = %i AND d.is_active = %i LIMIT 1))
              WHERE p.is_default = %i AND p.is_active = %i AND b.is_active = %i LIMIT 1',
            self::PROFILES_TABLE,
            self::FUNCTIONS_TABLE,
            self::AI_FUNCTION,
            self::BACKENDS_TABLE,
            self::BACKENDS_TABLE,
            1,
            1,
            1,
            1,
            1,
        );
        return $row !== null && (string) ($row['api_key'] ?? '') !== '';
    }
}

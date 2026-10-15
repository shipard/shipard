<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Analysis;

use Shipard\Core\Ai\AiBackendResolver;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;

/**
 * „Má analýza pošty kam jít?“ — jediné místo s kritériem použitelného
 * připojení: aktivní výchozí AI profil → aktivní připojení s nastaveným
 * (šifrovaným) klíčem. Připojení se bere stejně jako v resolveru
 * (tasks/ai-functions.md D40): přepsání v profilu → řádek funkce
 * `mail.extraction` → výchozí připojení zdroje dat. Klíč se nedešifruje
 * — rozhoduje jen přítomnost.
 *
 * Bez vlastního připojení s klíčem (a bez výslovného odkazu profilu nebo
 * řádku funkce na jiné připojení) platí serverové připojení pro vývoj
 * ({@see AiBackendResolver::serverBackend()}, tasks/testing-phase4.md D40)
 * — jen s konfigurací zdroje dat; bez ní sonda serverové připojení nevidí.
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

    private ?AiBackendResolver $backends;

    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly ?DataSourceConfig $dsConfig = null,
        ?AiBackendResolver $backends = null,
    ) {
        $this->backends = $backends;
    }

    public function hasUsableBackend(): bool
    {
        $row = $this->db->fetchRow(
            'SELECT b.api_key, p.backend AS profile_backend, f.connection AS function_connection FROM %n p
              LEFT JOIN %n f ON f.function_id = %s
              LEFT JOIN %n b ON b.id = COALESCE(p.backend, f.connection,
                  (SELECT d.id FROM %n d WHERE d.is_default = %i AND d.is_active = %i LIMIT 1))
                  AND b.is_active = %i
              WHERE p.is_default = %i AND p.is_active = %i LIMIT 1',
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
        if ($row === null) {
            return false; // bez aktivního výchozího profilu
        }
        if ((string) ($row['api_key'] ?? '') !== '') {
            return true;
        }
        if (($row['profile_backend'] ?? null) !== null || ($row['function_connection'] ?? null) !== null) {
            return false; // výslovně zvolené připojení bez klíče / neaktivní — resolver by selhal stejně
        }
        return $this->serverBackendAvailable();
    }

    private function serverBackendAvailable(): bool
    {
        if ($this->dsConfig === null && $this->backends === null) {
            return false;
        }
        $this->backends ??= new AiBackendResolver($this->db, $this->dsConfig);
        return $this->backends->serverBackend() !== null;
    }
}

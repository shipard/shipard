<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

use Shipard\Core\Auth\CurrentUser;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Settings\KeyValueStore;

/**
 * Výchozí autor nového dokladu — `docs_core_heads.author`, „Vystavil“ na
 * tisku (#93 D1, D11). Jediné místo pravdy pro pořadí:
 *
 *   1. klíč `author` v datech je (i `null`) → beze změny — rozhodl formulář,
 *      applier nebo import;
 *   2. doklad zakládá člověk (přihlášený ne-systémový uživatel) → on;
 *   3. strojový kontext → autor automaticky vystavených dokladů z číselné
 *      řady (`auto_author`) → z nastavení (`docs.autoAuthor`) → NULL.
 *
 * Strojový kontext = bez uživatele (CLI, cron) **nebo** systémový uživatel
 * (`is_system`, typicky API klíč integrace): jméno integrace na faktuře jako
 * „Vystavil“ nikdo nechce. Neaktivní uživatel z řady ani z nastavení se
 * nepoužije (warning do logu) a pokračuje se dál v pořadí.
 *
 * Volá `DocDocument::beforeSave` jen při insertu; update autora nemění,
 * pokud ho nepošle formulář.
 */
final class DocAuthorResolver
{
    public const SETTING = 'docs.autoAuthor';

    private const USERS_TABLE  = 'core_system_users';
    private const SERIES_TABLE = 'docs_core_number_series';

    public function __construct(
        private readonly \Dibi\Connection $db,
        private readonly ?KeyValueStore $settings = null,
    ) {}

    /**
     * Doplní `author` do dat nového dokladu, pokud ho volající neurčil.
     *
     * @param array<string, mixed> $data
     */
    public function apply(array &$data): void
    {
        if (array_key_exists('author', $data)) {
            return;
        }
        $author = $this->interactiveUser()
            ?? $this->automaticAuthor($data['number_series'] ?? null);
        if ($author !== null) {
            $data['author'] = $author;
        }
    }

    /**
     * Přihlášený uživatel, pokud je to člověk; null ve strojovém kontextu
     * (nikdo přihlášený, systémový uživatel, uživatel bez řádku).
     */
    public function interactiveUser(): ?int
    {
        $userId = CurrentUser::id();
        if ($userId === null) {
            return null;
        }
        $row = $this->db->fetch(
            'SELECT [is_system] FROM [' . self::USERS_TABLE . '] WHERE [id] = %i',
            $userId,
        );
        if ($row === null || $row === false || !empty($row['is_system'])) {
            return null;
        }
        return $userId;
    }

    /** Autor automaticky vystavených dokladů: řada → nastavení → null. */
    public function automaticAuthor(mixed $seriesId): ?int
    {
        if (is_numeric($seriesId) && (int) $seriesId > 0) {
            $row = $this->db->fetch(
                'SELECT [auto_author] FROM [' . self::SERIES_TABLE . '] WHERE [id] = %i',
                (int) $seriesId,
            );
            $fromSeries = $row !== null && $row !== false ? ($row['auto_author'] ?? null) : null;
            $author = $this->activeUser($fromSeries, 'number series ' . (int) $seriesId);
            if ($author !== null) {
                return $author;
            }
        }

        return $this->activeUser($this->settings?->get(self::SETTING), 'setting ' . self::SETTING);
    }

    /** Id uživatele, je-li to existující aktivní účet; jinak null + warning. */
    private function activeUser(mixed $userId, string $source): ?int
    {
        if (!is_numeric($userId) || (int) $userId <= 0) {
            return null;
        }
        $row = $this->db->fetch(
            'SELECT [id] FROM [' . self::USERS_TABLE . '] WHERE [id] = %i AND [is_active] = 1',
            (int) $userId,
        );
        if ($row === null || $row === false) {
            ErrorLogger::warn('DocAuthorResolver: configured author is not an active user, skipped', [
                'source' => $source,
                'user'   => (int) $userId,
            ]);
            return null;
        }
        return (int) $userId;
    }
}

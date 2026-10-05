<?php

declare(strict_types=1);

namespace Shipard\Module\Core\System;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Settings\SettingsOptionsProvider;

/**
 * Nabídka uživatelů pro pole typu `select` (#93 D12): aktivní ne-systémoví
 * uživatelé podle jména. Jedna implementace pro stránku nastavení
 * (`optionsProvider`, hodnoty jako řetězce) i pro formuláře
 * (`forForm`, hodnoty jako int).
 *
 * Select místo lookupu záměrně: lookup nad `core_system_users` hlídá
 * `TableAccessGuard` (systémové tabulky jen pro admina) a uživatelů je ve
 * zdroji dat jednotky až desítky — nabídku proto skládá server ve formuláři.
 */
final class ActiveUsersOptions implements SettingsOptionsProvider
{
    public const TABLE = 'core_system_users';

    public function options(DataSourceConnection $db, string $language): array
    {
        $options = [];
        foreach (self::forForm($db, null, $language) as $option) {
            $options[] = ['value' => (string) $option['value'], 'label' => $option['label']];
        }
        return $options;
    }

    /**
     * Nabídka pro formulář: aktivní uživatelé + **aktuální hodnota**, i když
     * je neaktivní nebo systémová — jinak by select uloženého uživatele
     * tiše vyprázdnil. Uživatel, který už neexistuje, v nabídce není.
     *
     * @return list<array{value: int, label: string}>
     */
    public static function forForm(DataSourceConnection $db, mixed $current, string $language = 'cs'): array
    {
        $currentId = is_numeric($current) && (int) $current > 0 ? (int) $current : 0;

        $rows = $db->fetchAll(
            'SELECT `id`, `full_name`, `login`, `is_active` FROM `' . self::TABLE . '`'
            . ' WHERE (`is_active` = 1 AND `is_system` = 0) OR `id` = %i'
            . ' ORDER BY `full_name` ASC, `id` ASC',
            $currentId,
        );

        $inactive = $language === 'cs' ? 'neaktivní' : 'inactive';
        $options = [];
        foreach ($rows as $row) {
            $label = trim((string) ($row['full_name'] ?? ''));
            if ($label === '') {
                $label = (string) ($row['login'] ?? ('#' . $row['id']));
            }
            if (empty($row['is_active'])) {
                $label .= " ({$inactive})";
            }
            $options[] = ['value' => (int) $row['id'], 'label' => $label];
        }
        return $options;
    }
}

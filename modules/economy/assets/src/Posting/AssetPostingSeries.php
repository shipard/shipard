<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets\Posting;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Settings\KeyValueStore;
use Shipard\Core\Settings\SettingsOptionsProvider;

/**
 * Řada účetních dokladů majetku (docs/assets.md D54): nastavení
 * `economy.assets.accountingSeries` = id aktivní řady `cmnbkp`.
 *
 * Bez platné řady se zaúčtování odmítne — žádný tichý výběr první řady
 * (stejná zásada jako u pokladen a přiznání DPH). Třída je zároveň
 * provider nabídky pole na stránce Nastavení → Majetek → Odpisy.
 */
final class AssetPostingSeries implements SettingsOptionsProvider
{
    public const SETTING = 'economy.assets.accountingSeries';
    public const DOC_TYPE = 'cmnbkp';
    public const TABLE = 'docs_core_number_series';

    /** Stavy, ve kterých jde na řadu vystavit doklad. */
    public const ACTIVE_STATES = [10, 40, 80];

    public function options(DataSourceConnection $db, string $language): array
    {
        $rows = $db->fetchAll(
            'SELECT `id`, `name`, `doc_number_code` FROM `' . self::TABLE . '`'
            . ' WHERE `doc_type` = %s AND `docState` IN %in ORDER BY `name`, `id`',
            self::DOC_TYPE,
            self::ACTIVE_STATES,
        );
        $options = [];
        foreach ($rows as $row) {
            $code = trim((string) ($row['doc_number_code'] ?? ''));
            $options[] = [
                'value' => (string) (int) $row['id'],
                'label' => (string) $row['name'] . ($code !== '' ? " ({$code})" : ''),
            ];
        }
        return $options;
    }

    /**
     * Nastavená řada, je-li to aktivní řada účetních dokladů.
     *
     * @return array{id: int, name: string}|null
     */
    public static function resolve(\Dibi\Connection $db, KeyValueStore $settings): ?array
    {
        $configured = $settings->get(self::SETTING);
        if ($configured === null || $configured === '' || (int) $configured <= 0) {
            return null;
        }
        $row = $db->fetch(
            'SELECT [id], [name] FROM [' . self::TABLE . '] WHERE [id] = %i AND [doc_type] = %s AND [docState] IN %in',
            (int) $configured,
            self::DOC_TYPE,
            self::ACTIVE_STATES,
        );

        return $row === null || $row === false ? null : ['id' => (int) $row['id'], 'name' => (string) $row['name']];
    }
}

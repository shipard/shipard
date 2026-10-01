<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets\Posting;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Settings\KeyValueStore;

/**
 * Řada účetních dokladů „Majetek“ pro nový zdroj dat (docs/assets.md D54):
 * když nastavení `economy.assets.accountingSeries` řadu nemá, najde řadu
 * `cmnbkp` s kódem {@see self::CODE} (nebo ji založí) a nastaví ji.
 *
 * Idempotentní; vyplněné nastavení nikdy nepřepisuje — ani když míří na
 * neplatnou řadu (to je chyba k opravě v Nastavení, ne k tichému přepsání).
 * Běží jen bez `skipProvisioning`: migrovaný zdroj převezme řadu importem.
 */
final class AssetPostingSeriesProvisioner
{
    public const CODE = 'MA';
    public const NAME = 'Majetek';
    /** Typ + rok + kód řady + pořadí — číslo se od výchozí řady liší kódem. */
    public const PATTERN = '%D%y%C%4';

    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly KeyValueStore $settings,
    ) {
    }

    /**
     * @return array{created: int, existing: int, seriesId: int|null}
     */
    public function provision(): array
    {
        $configured = $this->settings->get(AssetPostingSeries::SETTING);
        if ($configured !== null && $configured !== '') {
            return ['created' => 0, 'existing' => 1, 'seriesId' => (int) $configured];
        }

        $row = $this->db->fetchRow(
            'SELECT `id` FROM `' . AssetPostingSeries::TABLE . '`'
            . ' WHERE `doc_type` = %s AND `doc_number_code` = %s AND `docState` IN %in ORDER BY `id` LIMIT 1',
            AssetPostingSeries::DOC_TYPE,
            self::CODE,
            AssetPostingSeries::ACTIVE_STATES,
        );
        $created = 0;
        if ($row !== null) {
            $seriesId = (int) $row['id'];
        } else {
            $seriesId = $this->db->insertRow(AssetPostingSeries::TABLE, [
                'doc_type'           => AssetPostingSeries::DOC_TYPE,
                'name'               => self::NAME,
                'doc_number_code'    => self::CODE,
                'doc_number_pattern' => self::PATTERN,
                'reset_scope'        => 'fiscal_year',
                'docState'           => 40,
                'docStateMain'       => 3,
            ]);
            $created = 1;
        }
        $this->settings->set(AssetPostingSeries::SETTING, (string) $seriesId);

        return ['created' => $created, 'existing' => 1 - $created, 'seriesId' => $seriesId];
    }
}

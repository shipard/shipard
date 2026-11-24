<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Places;

use Shipard\Core\Database\DataSourceConnection;

/**
 * Idempotentní seed druhů míst (#169 D14): jeden druh na typ, se stejným
 * názvem jako typ a bez vlastní ikony (platí ikona typu).
 *
 * Druh se pozná podle `system_code` (= id typu). Existuje-li řádek se
 * stejným kódem v jakémkoli stavu — i v archivu nebo v koši — nic se
 * nedělá: uživatel si druh mohl přejmenovat nebo vyřadit a seed mu ho
 * nevrací ani nepřepisuje. Jinak INSERT rovnou ve stavu V pořádku.
 *
 * Volá se z DsUpgradeCommand; typy dostává už lokalizované v jazyce zdroje
 * dat (zkompilovaný cfgItem `base.places.types`). Vzor: UnitsProvisioner.
 */
class PlaceKindsProvisioner
{
    public const TABLE = 'base_places_kinds';

    /**
     * @param array<string, array<string, mixed>> $types id typu → definice (`name` v jazyce zdroje dat)
     */
    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly array $types,
    ) {
    }

    /** @return array{kinds: array{created: int, existing: int}} */
    public function provision(): array
    {
        $created = 0;
        $existing = 0;

        foreach ($this->types as $typeId => $type) {
            $code = (string) $typeId;
            if ($code === '' || !is_array($type)) {
                continue;
            }

            $row = $this->db->fetchRow(
                'SELECT id FROM ' . self::TABLE . ' WHERE system_code = %s',
                $code,
            );
            if ($row !== null) {
                $existing++;
                continue;
            }

            $this->db->insertRow(self::TABLE, [
                'name'         => (string) ($type['name'] ?? $code),
                'type'         => $code,
                'icon'         => null,
                'system_code'  => $code,
                'docState'     => 40,
                'docStateMain' => 3,
            ]);
            $created++;
        }

        return ['kinds' => ['created' => $created, 'existing' => $existing]];
    }
}

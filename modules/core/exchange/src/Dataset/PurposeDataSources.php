<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset;

use Shipard\Core\Config\DataClass;
use Shipard\Core\Config\DataSourceState;
use Shipard\Core\Server\DataSourceInfo;
use Shipard\Core\Server\DataSourceInventory;

/**
 * Vyhrazený zdroj dat podle **přesné shody účelu** v `main.json` (vzor D126
 * pro videa, D132 / O1 pro testovací kolo): čitelný, třída **free**, aktivní.
 * Účel přežije přeseedování (`dataset-seed` existující účel nemění). Žádný
 * jiný zdroj dat (evaluační, korpus, pokus, reálná kopie) se takto nikdy
 * nevybere — výběr podle účelu je pojistka proti přeseedování cizí práce.
 */
final class PurposeDataSources
{
    private function __construct()
    {
    }

    /** První zdroj dat s daným účelem podle id; null = žádný. */
    public static function find(DataSourceInventory $inventory, string $purpose): ?DataSourceInfo
    {
        foreach ($inventory->scan() as $info) {
            if (self::matches($info, $purpose)) {
                return $info;
            }
        }
        return null;
    }

    public static function matches(DataSourceInfo $info, string $purpose): bool
    {
        return $info->readable
            && $info->dataClass === DataClass::Free
            && $info->state === DataSourceState::ACTIVE
            && $info->purpose === $purpose;
    }
}

<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\Video;

use Shipard\Core\Server\DataSourceInfo;
use Shipard\Core\Server\DataSourceInventory;
use Shipard\Core\Server\MainConfigPatcher;
use Shipard\Module\Core\Exchange\Dataset\PurposeDataSources;

/**
 * Vyhrazený zdroj dat pro videa per sada (tasks/testing-phase7b.md D126):
 * účel `videa — <sada>` v `main.json`, třída **free**, aktivní. Účel přežije
 * přeseedování (`dataset-seed` existující účel nemění), dashboard ho tak
 * najde i po prvním běhu. Žádný jiný zdroj dat (evaluační, korpus, reálná
 * kopie) se pro videa nikdy nepoužije — výběr je jen přesnou shodou účelu.
 */
final class VideoDataSources
{
    public const string PURPOSE_PREFIX = 'videa — ';

    private function __construct()
    {
    }

    public static function purposeFor(string $dataset): string
    {
        return MainConfigPatcher::normalizePurpose(self::PURPOSE_PREFIX . $dataset);
    }

    /** Zdroj dat sady podle účelu (první podle id, {@see PurposeDataSources}); null = žádný. */
    public static function find(DataSourceInventory $inventory, string $dataset): ?DataSourceInfo
    {
        return PurposeDataSources::find($inventory, self::purposeFor($dataset));
    }

    public static function isVideoDataSource(DataSourceInfo $info): bool
    {
        return $info->readable && is_string($info->purpose) && str_starts_with($info->purpose, self::PURPOSE_PREFIX);
    }
}

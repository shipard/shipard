<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\Round;

use Shipard\Core\Server\DataSourceInfo;
use Shipard\Core\Server\DataSourceInventory;
use Shipard\Core\Server\MainConfigPatcher;
use Shipard\Module\Core\Exchange\Dataset\PurposeDataSources;

/**
 * Vyhrazený zdroj dat testovacího kola per sada (tasks/testing-phase8a.md
 * D132, rozhodnutí O1): účel přesně `testovací kolo — <sada>`, volný,
 * aktivní ({@see PurposeDataSources}). Kolo ho přeseedovává; ostatní zdroje
 * dat téže sady (pokus, korpus, evaluace) nikdy. Chybí-li, kolo ho založí
 * dílčí úlohou `create` (`ds-create --no-cron` + `dataset-seed`). Zdroj pro
 * videa zůstává `videa — <sada>` ({@see \Shipard\Module\Core\Exchange\Dataset\Video\VideoDataSources}, D126).
 */
final class RoundDataSources
{
    public const string PURPOSE_PREFIX = 'testovací kolo — ';
    public const string NAME_PREFIX = 'Testovací kolo — ';

    private function __construct()
    {
    }

    public static function purposeFor(string $dataset): string
    {
        return MainConfigPatcher::normalizePurpose(self::PURPOSE_PREFIX . $dataset);
    }

    /** Název zdroje dat (`main.json` → `name`) z titulku sady. */
    public static function nameFor(string $datasetTitle): string
    {
        return self::NAME_PREFIX . $datasetTitle;
    }

    public static function find(DataSourceInventory $inventory, string $dataset): ?DataSourceInfo
    {
        return PurposeDataSources::find($inventory, self::purposeFor($dataset));
    }
}

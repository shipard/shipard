<?php

declare(strict_types=1);

namespace Shipard\Core\Module;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;

/**
 * Kontext poskytovatele rozšíření UI (`detailExtensions`, `formTabs`,
 * `rowDecorators`, #172 D19) — jeden objekt pro všechny tři druhy.
 * Poskytovatel vzniká bez argumentů konstruktoru a všechno, co k práci
 * potřebuje, dostane tady: tabulku, do které přispívá (jméno a definici
 * včetně sloupců z rozšíření), připojení zdroje dat, zkompilovanou
 * konfiguraci, konfiguraci zdroje dat a jazyk požadavku.
 *
 * `$tableDef` je `null` jen tam, kde definice tabulek nejsou po ruce
 * (viewer vytvořený bez `ViewerRegistry::setTables()`).
 *
 * Akce záznamu (`recordActions`, #172 D12) dostávají potomka
 * `RecordAction\RecordActionContext` — totéž a navíc přihlášený uživatel
 * a `ServerConfig`.
 */
class ModuleExtensionContext
{
    public function __construct(
        public readonly string $table,
        public readonly DataSourceConnection $db,
        public readonly ?ConfigRuntime $config = null,
        public readonly ?DataSourceConfig $dsConfig = null,
        public readonly string $language = 'en',
        public readonly ?TableDefinition $tableDef = null,
    ) {
    }
}

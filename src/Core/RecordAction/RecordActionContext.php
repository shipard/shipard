<?php

declare(strict_types=1);

namespace Shipard\Core\RecordAction;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Document\DocumentEventDispatcher;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Module\ModuleExtensionContext;

/**
 * Kontext akce záznamu (#172 D12, D15): to, co nese kontext rozšíření UI
 * (tabulka a její definice, připojení, konfigurace, jazyk), a navíc
 * přihlášený uživatel a `ServerConfig` — sekci `server.json` modulu čte
 * akce přes `ServerConfig::getSection()` a svou třídu `ServerConfigSection`.
 *
 * Akce, která zakládá nebo mění doklady, potřebuje složit ukládací služby
 * (`DocumentApplier`): proto kontext nepovinně nese `DocumentRegistry`,
 * definice tabulek a `DocumentEventDispatcher` (#175 U3). Vyplňuje je
 * endpoint akce při provedení; při vykreslení detailu (`available()` /
 * `describe()`) jsou prázdné — dostupnost a popis akce na nich nesmí
 * záviset.
 */
final class RecordActionContext extends ModuleExtensionContext
{
    public function __construct(
        string $table,
        DataSourceConnection $db,
        ?ConfigRuntime $config = null,
        ?DataSourceConfig $dsConfig = null,
        string $language = 'en',
        ?TableDefinition $tableDef = null,
        public readonly ?int $userId = null,
        public readonly bool $isAdmin = false,
        public readonly ?ServerConfig $serverConfig = null,
        public readonly ?DocumentRegistry $documents = null,
        /** @var array<string, TableDefinition> definice tabulek zdroje dat */
        public readonly array $tables = [],
        public readonly ?DocumentEventDispatcher $dispatcher = null,
    ) {
        parent::__construct($table, $db, $config, $dsConfig, $language, $tableDef);
    }
}

<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\Verify;

use Dibi\Connection;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Document\DocumentEventDispatcher;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Module\ModulePathResolver;

/**
 * Závislosti zdroje dat pro `dataset-verify` (paralela `SeedContext`):
 * připojení, konfigurace, definice tabulek, registr Document tříd
 * a dispatcher událostí (stav 40 účtuje jen přes něj). Staví je příkaz,
 * testy je dostanou s prázdnými částmi.
 */
final class VerifyWiring
{
    /** @param array<string, TableDefinition> $tables */
    public function __construct(
        public readonly Connection $db,
        public readonly DataSourceConnection $dsConnection,
        public readonly DataSourceConfig $dsConfig,
        public readonly string $dsDir,
        public readonly ?ConfigRuntime $config = null,
        public readonly array $tables = [],
        public readonly ?DocumentRegistry $registry = null,
        public readonly ?DocumentEventDispatcher $dispatcher = null,
        public readonly ?ModulePathResolver $resolver = null,
    ) {}

    public function hasTable(string $table): bool
    {
        return isset($this->tables[$table]);
    }
}

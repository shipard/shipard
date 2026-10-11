<?php

declare(strict_types=1);

namespace Shipard\Core\Viewer;

/**
 * Registrace vieweru z `module.jsonc` `viewers[]`. `table` smí chybět
 * (null): viewer bez tabulky čte data z konfigurace (přehled modelů
 * `core.ai.modelCatalog`), přepisuje `TableViewer::loadRecord()`, nemá
 * formulář, zámky ani tisky a do hlavní navigace nejde — patří do
 * `settingsItems`.
 */
class ViewerDefinition
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $table,
        public readonly ?string $class,
        public readonly string $moduleId,
        public readonly ?string $icon = null,
    ) {}
}

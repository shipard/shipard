<?php

declare(strict_types=1);

namespace Shipard\Core\Module;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;

/**
 * Co dostane provisioner modulu ({@see ModuleProvisioner}, #174 D28):
 * spojení zdroje dat, jeho konfiguraci a právě zkompilovanou konfiguraci
 * modulů v jazyce zdroje dat (názvy seedových záznamů). `config` je `null`,
 * když kompilát nejde načíst — provisioner pak nic nezakládá odhadem.
 */
final readonly class ModuleProvisionContext
{
    public function __construct(
        public DataSourceConnection $db,
        public DataSourceConfig $dsConfig,
        public ?ConfigRuntime $config,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace Shipard\Core\Alerts;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;

/**
 * Abstraktní báze každé konkrétní alert kontroly.
 *
 * Implementer:
 *  - dědí třídu, jejíž FQCN je v `alertChecks[].class` v `module.jsonc`
 *  - implementuje `run()` — vrací seznam `AlertFinding[]`
 *  - lokalizaci textů (`title`, `message`, `actions[].label`) si dělá sám
 *    nad `$this->language` (jazyk DS, který reconciler předal)
 *
 * Reconciler instancuje třídu s těmito parametry — žádný servisní
 * lokátor, žádný DI container. Pokud check potřebuje další služby, vrátit se
 * pro ně přes `$this->db` (raw queries) nebo `$this->config` (compiled
 * cfgItems / doc states). `$dsConfig` = konfigurace zdroje dat (země pro
 * cfgItemy `world.<země>.*`, #121 task B); v testech smí chybět.
 */
abstract class AlertCheck
{
    public function __construct(
        protected readonly DataSourceConnection $db,
        protected readonly ConfigRuntime $config,
        protected readonly string $language,
        protected readonly ?DataSourceConfig $dsConfig = null,
    ) {}

    /** @return AlertFinding[] */
    abstract public function run(): array;
}

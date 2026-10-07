<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets\Depreciation\Method;

use Shipard\Module\Economy\Assets\Depreciation\PlanMessage;

/**
 * Spočtený odpis období. `commit` je stav metody po započtení období —
 * výpočet sám nic nemění, stav se převezme až v `applied()`. `messages`
 * jsou hlášení k výpočtu; `CircuitWalker` je dává jen plánovaným řádkům
 * (u potvrzeného odpisu rozhoduje jeho částka, ne výpočet).
 *
 * @internal
 */
final readonly class Computed
{
    /** @param list<PlanMessage> $messages */
    public function __construct(
        public float $amount,
        public string $formula,
        public mixed $commit = null,
        public array $messages = [],
    ) {
    }
}

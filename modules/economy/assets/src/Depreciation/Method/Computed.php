<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets\Depreciation\Method;

/**
 * Spočtený odpis období. `commit` je stav metody po započtení období —
 * výpočet sám nic nemění, stav se převezme až v `applied()`.
 *
 * @internal
 */
final readonly class Computed
{
    public function __construct(
        public float $amount,
        public string $formula,
        public mixed $commit = null,
    ) {
    }
}

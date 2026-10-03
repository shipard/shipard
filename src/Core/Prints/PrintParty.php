<?php

declare(strict_types=1);

namespace Shipard\Core\Prints;

/**
 * Partner záznamu pro volbu jazyka tisku (#94 D4): jazyk se čte **živě**
 * z osoby (partner si řekne o jiný jazyk a doklad se vytiskne znovu bez
 * zásahu do dokladu), země ze snapshotu strany — adresa dokladu nemusí být
 * dnešní adresa osoby.
 */
final readonly class PrintParty
{
    public function __construct(
        public ?string $personLanguage,
        public ?string $country,
    ) {}
}

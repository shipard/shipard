<?php

declare(strict_types=1);

namespace Shipard\Module\World\Assets;

/**
 * Výsledek výpočtu daňového odpisu za období.
 *
 * - `amount`  — odpis zaokrouhlený dle pravidel země a omezený zůstatkem,
 * - `formula` — lidsky čitelný výpočet (`100 000,00 × 22,25 %`), zobrazuje
 *   ho plán odpisů na kartě,
 * - `exact`   — hodnota před zaokrouhlením a omezením; engine ji sčítá, když
 *   se období skládá z více úseků a zaokrouhluje se jednou za období.
 */
final readonly class TaxAmount
{
    public function __construct(
        public float $amount,
        public string $formula,
        public float $exact,
    ) {
    }
}

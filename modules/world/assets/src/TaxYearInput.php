<?php

declare(strict_types=1);

namespace Shipard\Module\World\Assets;

/**
 * Vstup ročního odpisu (metody druhu `annual`) za jedno zdaňovací období.
 *
 * Počitadla let drží engine: `yearsApplied` = roky, za které už byl odpis
 * uplatněn (přerušený rok se nepočítá), `yearsSinceIncrease` = roky
 * odpisované ze zvýšené ceny, v roce technického zhodnocení 0.
 */
final readonly class TaxYearInput
{
    public function __construct(
        public string $method,
        public string $ruleCode,
        /** Datum prvního zařazení — podle něj se volí sazby (D43). */
        public string $acquiredDate,
        /** Vstupní cena včetně technických zhodnocení a snížení. */
        public float $entryPrice,
        /** Zůstatková cena před odpisem tohoto období. */
        public float $residual,
        public int $yearsApplied,
        public bool $increased = false,
        public int $yearsSinceIncrease = 0,
        /** Polovina ročního odpisu (rok vyřazení, D35). */
        public bool $halfYear = false,
        /**
         * Zdaňovací období kratší než 12 měsíců (D46) — také polovina; spolu
         * s `halfYear` je to pořád jedna polovina, ne čtvrtina.
         */
        public bool $shortPeriod = false,
    ) {
        if ($yearsApplied < 0 || $yearsSinceIncrease < 0) {
            throw new \InvalidArgumentException('Počitadla let nesmí být záporná');
        }
    }
}

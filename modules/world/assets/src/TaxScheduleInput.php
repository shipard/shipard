<?php

declare(strict_types=1);

namespace Shipard\Module\World\Assets;

/**
 * Vstup měsíčních metod (časové a mimořádné odpisy) za souvislý úsek měsíců.
 *
 * Kalendář drží engine: `monthsDone` = měsíce rozpisu uplynulé před úsekem,
 * `months` = měsíce úseku. Po technickém zhodnocení běží nový rozpis
 * (`increased`): základem je zvýšená zůstatková cena a `remainingAtIncrease`
 * nese měsíce, které v okamžiku zhodnocení zbývaly z původní doby.
 */
final readonly class TaxScheduleInput
{
    public function __construct(
        public string $method,
        public string $ruleCode,
        /** Datum prvního zařazení — podle něj se ověřuje platnost pravidla. */
        public string $acquiredDate,
        /** Základ rozpisu: vstupní cena, po zhodnocení zvýšená zůstatková cena. */
        public float $base,
        /** Zůstatková cena před úsekem. */
        public float $residual,
        public int $monthsDone,
        public int $months,
        public bool $increased = false,
        public int $remainingAtIncrease = 0,
    ) {
        if ($monthsDone < 0 || $months < 0) {
            throw new \InvalidArgumentException('Počty měsíců nesmí být záporné');
        }
    }
}

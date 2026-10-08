<?php

declare(strict_types=1);

namespace Shipard\Core\Numbering;

/**
 * Hodnoty pro vyhodnocení vzorce čísla ({@see NumberPattern::resolve}).
 *
 * Popisek roku smí být closure — vyhodnotí se líně, jen když vzorec rok
 * obsahuje, a nejvýš jednou (u dokladů je to dotaz do fiskálních let, který
 * musí přijít až po přidělení pořadí z čítače). Doménové placeholdery jsou
 * mapa znak → hodnota nebo closure; doklady dodávají `%D` (kód typu dokladu).
 */
final readonly class NumberContext
{
    /**
     * @param string|\Closure(): string $yearLabel
     * @param array<string, string|\Closure(): string> $domain
     */
    public function __construct(
        public int $sequence,
        public string $seriesCode,
        public string|\Closure $yearLabel,
        public array $domain = [],
    ) {
        foreach (array_keys($domain) as $key) {
            if (!preg_match('/^[A-Za-z0-9]$/', (string) $key)) {
                throw new \InvalidArgumentException("Doménový placeholder musí být jeden alfanumerický znak, dostal „{$key}“");
            }
        }
    }
}

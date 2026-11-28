<?php

declare(strict_types=1);

namespace Shipard\Core\Prints\EscPos;

/**
 * Vysázený řádek účtenky: normalizovaný text (jen znaky kódové stránky,
 * nezlomitelná mezera zůstává) a jeden styl na celý řádek — víc náhled
 * ve frontendu neunese (#177 U13).
 */
final class ReceiptLine
{
    public function __construct(
        public readonly string $text,
        public readonly string $align = 'left',
        public readonly bool $bold = false,
        public readonly int $width = 1,
        public readonly int $height = 1,
        public readonly bool $small = false,
    ) {
    }
}

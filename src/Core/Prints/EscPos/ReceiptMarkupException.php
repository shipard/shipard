<?php

declare(strict_types=1);

namespace Shipard\Core\Prints\EscPos;

/**
 * Výstup šablony účtenky není platný (#177 U31): XML se nepodařilo přečíst,
 * nebo obsahuje neznámou značku, atribut či hodnotu. `markupLine` je řádek
 * výstupu šablony (`print-run --format=markup` ho ukáže).
 */
final class ReceiptMarkupException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $markupLine)
    {
        parent::__construct("Receipt markup line {$markupLine}: {$message}");
    }
}

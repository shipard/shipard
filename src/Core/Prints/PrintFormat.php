<?php

declare(strict_types=1);

namespace Shipard\Core\Prints;

/**
 * Výstup běhu tisku: hotové PDF, jen `PrintData` (ladění, testy), nebo
 * HTML přesně tak, jak jde do render služby (vývoj šablon, #90 D28 — jen
 * CLI, REST ho nenabízí).
 */
enum PrintFormat: string
{
    case Pdf = 'pdf';
    case Json = 'json';
    case Html = 'html';
}

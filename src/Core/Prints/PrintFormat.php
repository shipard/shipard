<?php

declare(strict_types=1);

namespace Shipard\Core\Prints;

/** Výstup běhu tisku: hotové PDF, nebo jen `PrintData` (ladění, testy). */
enum PrintFormat: string
{
    case Pdf = 'pdf';
    case Json = 'json';
}

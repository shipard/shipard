<?php

declare(strict_types=1);

namespace Shipard\Core\Prints;

/**
 * Tisk pro záznam není dostupný — záznam nesplní `filter` (jiný typ) nebo
 * `docStates` (koncept se netiskne, #90 D5). Controller mapuje na HTTP 409.
 */
final class PrintNotAvailableException extends \RuntimeException
{
}

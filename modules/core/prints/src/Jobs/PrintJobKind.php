<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Prints\Jobs;

/**
 * Druh tiskové úlohy (#177 U36): tisk záznamu, zkušební účtenka profilu
 * tiskárny a otevření šuplíku (úloha bez papíru).
 */
enum PrintJobKind: string
{
    case Print  = 'print';
    case Test   = 'test';
    case Drawer = 'drawer';
}

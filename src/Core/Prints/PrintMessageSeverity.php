<?php

declare(strict_types=1);

namespace Shipard\Core\Prints;

enum PrintMessageSeverity: string
{
    case Warning = 'warning';
    case Info = 'info';
}

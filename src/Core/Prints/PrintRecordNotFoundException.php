<?php

declare(strict_types=1);

namespace Shipard\Core\Prints;

/** Záznam v tabulce deklarace neexistuje — controller mapuje na HTTP 404. */
final class PrintRecordNotFoundException extends \RuntimeException
{
}

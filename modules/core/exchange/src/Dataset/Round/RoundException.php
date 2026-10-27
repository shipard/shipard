<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\Round;

/** Kolo nejde sestavit nebo spustit (preflight, neznámá sada / scénář, limit ceny) — exit 2, nic se nespustilo. */
final class RoundException extends \RuntimeException
{
}

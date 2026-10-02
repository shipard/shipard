<?php

declare(strict_types=1);

namespace Shipard\Core\Prints;

/**
 * Builder nemůže tisk sestavit, protože záznamu chybí data, která nesmí
 * nahradit jiným zdrojem (doklad bez snapshotu stran, neznámý typ dokladu).
 * Vada dat, ne requestu — controller mapuje na HTTP 409.
 */
final class PrintBuildException extends \RuntimeException
{
}

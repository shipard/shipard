<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Exchange\RoundTrip;

/**
 * Zdroj dat nemá, co případ matice potřebuje (řadu typu, pokladnu,
 * registraci DPH, dimenzi) — test případ přeskočí, neselže.
 */
final class FixtureUnavailable extends \RuntimeException
{
}

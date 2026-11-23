<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Exchange\Shadow;

/** Vrací savepoint stínového běhu — zápisy práce nemají zůstat. */
final class ShadowRollback extends \RuntimeException
{
}

<?php

declare(strict_types=1);

namespace Shipard\Module\Test\UiExtensions;

use Shipard\Core\Module\ModuleExtensionContext;
use Shipard\Core\Viewer\RowDecorator;

/** Fixture (#172 D18, D19): dekorátor, který spadne — seznam se vykreslí bez něj. */
class ThrowingRowDecorator implements RowDecorator
{
    public function decorate(string $table, array $rawRows, ModuleExtensionContext $context): array
    {
        throw new \RuntimeException('row decorator broke');
    }
}

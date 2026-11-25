<?php

declare(strict_types=1);

namespace Shipard\Module\Test\UiExtensions;

use Shipard\Core\Module\ModuleExtensionContext;
use Shipard\Core\Viewer\RowDecorator;

/** Fixture (#172 D18, D19): druhý dekorátor téhož modulu — pořadí spanů; štítek jako pilulka pro každý řádek. */
class SecondRowDecorator implements RowDecorator
{
    public function decorate(string $table, array $rawRows, ModuleExtensionContext $context): array
    {
        $spans = [];
        foreach ($rawRows as $row) {
            $spans[(int) $row['id']] = [['text' => 'Second tag', 'badge' => 'info']];
        }
        return $spans;
    }
}

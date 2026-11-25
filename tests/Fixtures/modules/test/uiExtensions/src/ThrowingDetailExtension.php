<?php

declare(strict_types=1);

namespace Shipard\Module\Test\UiExtensions;

use Shipard\Core\Module\ModuleExtensionContext;
use Shipard\Core\Viewer\DetailExtension;

/** Fixture (#172 D16, D19): poskytovatel, který spadne — detail se vykreslí bez něj. */
class ThrowingDetailExtension implements DetailExtension
{
    public function tabs(string $table, array $record, ModuleExtensionContext $context): array
    {
        throw new \RuntimeException('detail provider broke');
    }

    public function badges(string $table, array $record, ModuleExtensionContext $context): array
    {
        return [['label' => 'Never shown', 'style' => 'danger']];
    }
}

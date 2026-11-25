<?php

declare(strict_types=1);

namespace Shipard\Module\Test\UiExtensions;

use Shipard\Core\Module\ModuleExtensionContext;
use Shipard\Core\Viewer\DetailExtension;

/** Fixture (#172 D16, D19): druhý poskytovatel téhož modulu — pořadí tabů a štítků. */
class SecondDetailExtension implements DetailExtension
{
    public function tabs(string $table, array $record, ModuleExtensionContext $context): array
    {
        return [['id' => 'second', 'label' => 'Second', 'content' => ['type' => 'text', 'text' => 'second']]];
    }

    public function badges(string $table, array $record, ModuleExtensionContext $context): array
    {
        return [['label' => 'Second badge', 'style' => 'warning']];
    }
}

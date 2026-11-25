<?php

declare(strict_types=1);

namespace Shipard\Module\Test\UiExtensions;

use Shipard\Core\Module\ModuleExtensionContext;
use Shipard\Core\Viewer\RowDecorator;

/** Fixture (#172 D18, D19): span bez textu — mimo kontrakt řádku. */
class BadSpanRowDecorator implements RowDecorator
{
    public function decorate(string $table, array $rawRows, ModuleExtensionContext $context): array
    {
        return [(int) ($rawRows[0]['id'] ?? 0) => [['class' => 'danger']]];
    }
}

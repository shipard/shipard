<?php

declare(strict_types=1);

namespace Shipard\Module\Test\UiExtensions;

use Shipard\Core\Module\ModuleExtensionContext;
use Shipard\Core\Viewer\DetailExtension;

/** Fixture (#172 D16, D19): tab s obsahem typu html — mimo kontrakt detailu. */
class HtmlDetailExtension implements DetailExtension
{
    public function tabs(string $table, array $record, ModuleExtensionContext $context): array
    {
        return [['id' => 'raw', 'label' => 'Raw', 'content' => ['type' => 'html', 'html' => '<b>x</b>']]];
    }

    public function badges(string $table, array $record, ModuleExtensionContext $context): array
    {
        return [['label' => 'Never shown', 'style' => 'danger']];
    }
}

<?php

declare(strict_types=1);

namespace Shipard\Module\Test\UiExtensions;

use Shipard\Core\Module\ModuleExtensionContext;
use Shipard\Core\Viewer\DetailExtension;

/** Fixture (#172 D16, D19): štítek bez stylu — mimo kontrakt; platný tab se zahodí s ním. */
class BadBadgeDetailExtension implements DetailExtension
{
    public function tabs(string $table, array $record, ModuleExtensionContext $context): array
    {
        return [['id' => 'fine', 'label' => 'Fine', 'content' => ['type' => 'text', 'text' => 'fine']]];
    }

    public function badges(string $table, array $record, ModuleExtensionContext $context): array
    {
        return [['label' => 'No style']];
    }
}

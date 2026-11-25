<?php

declare(strict_types=1);

namespace Shipard\Module\Test\UiExtensions;

use Shipard\Core\Module\ModuleExtensionContext;
use Shipard\Core\Viewer\DetailExtension;

/** Fixture (#172 D16, D19): typ obsahu vázaný na viewer, vnořený v composite — mimo sdílené typy. */
class ViewerContentDetailExtension implements DetailExtension
{
    public function tabs(string $table, array $record, ModuleExtensionContext $context): array
    {
        return [['id' => 'files', 'label' => 'Files', 'content' => ['type' => 'composite', 'blocks' => [
            ['type' => 'text', 'text' => 'ok'],
            ['type' => 'attachments'],
        ]]]];
    }

    public function badges(string $table, array $record, ModuleExtensionContext $context): array
    {
        return [];
    }
}

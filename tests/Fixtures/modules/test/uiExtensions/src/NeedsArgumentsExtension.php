<?php

declare(strict_types=1);

namespace Shipard\Module\Test\UiExtensions;

use Shipard\Core\Module\ModuleExtensionContext;
use Shipard\Core\Viewer\DetailExtension;

/** Fixture: poskytovatel, který nejde vytvořit bez argumentů konstruktoru (#172 D19). */
class NeedsArgumentsExtension implements DetailExtension
{
    public function __construct(private readonly string $required)
    {
    }

    public function tabs(string $table, array $record, ModuleExtensionContext $context): array
    {
        return [];
    }

    public function badges(string $table, array $record, ModuleExtensionContext $context): array
    {
        return [['label' => $this->required, 'style' => 'info']];
    }
}

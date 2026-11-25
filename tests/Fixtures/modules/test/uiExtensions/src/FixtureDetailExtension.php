<?php

declare(strict_types=1);

namespace Shipard\Module\Test\UiExtensions;

use Shipard\Core\Module\ModuleExtensionContext;
use Shipard\Core\Viewer\DetailExtension;

/**
 * Fixture (#172 D16): tab a štítek detailu cizí tabulky. Záznam druhu
 * `skip` se rozšíření netýká — vrací prázdný výsledek.
 */
class FixtureDetailExtension implements DetailExtension
{
    public function tabs(string $table, array $record, ModuleExtensionContext $context): array
    {
        if (($record['kind'] ?? null) === 'skip') {
            return [];
        }
        return [[
            'id' => 'sales',
            'label' => 'Fixture sales',
            'content' => [
                'type' => 'properties',
                'groups' => [[
                    'title' => 'Fixture',
                    'items' => [
                        ['label' => 'Table', 'value' => $table],
                        ['label' => 'Language', 'value' => $context->language],
                    ],
                ]],
            ],
        ]];
    }

    public function badges(string $table, array $record, ModuleExtensionContext $context): array
    {
        if (($record['kind'] ?? null) === 'skip') {
            return [];
        }
        return [['label' => 'Fixture badge', 'style' => 'info']];
    }
}

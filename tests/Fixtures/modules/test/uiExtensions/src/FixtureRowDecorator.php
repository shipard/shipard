<?php

declare(strict_types=1);

namespace Shipard\Module\Test\UiExtensions;

use Shipard\Core\Module\ModuleExtensionContext;
use Shipard\Core\Viewer\RowDecorator;

/**
 * Fixture (#172 D18): štítek řádku pro záznamy s `contact_payment`.
 * `$calls` počítá volání — test ověřuje jedno volání na stránku řádků.
 */
class FixtureRowDecorator implements RowDecorator
{
    public static int $calls = 0;

    public function decorate(string $table, array $rawRows, ModuleExtensionContext $context): array
    {
        self::$calls++;

        $spans = [];
        foreach ($rawRows as $row) {
            if (!empty($row['contact_payment'])) {
                $spans[(int) $row['id']] = [['text' => 'Fixture tag', 'class' => 'success']];
            }
        }
        return $spans;
    }
}

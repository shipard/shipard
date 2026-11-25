<?php

declare(strict_types=1);

namespace Shipard\Module\Test\UiExtensions;

use Shipard\Core\Form\FormTab;
use Shipard\Core\Form\ModuleFormTab;
use Shipard\Core\Module\ModuleExtensionContext;

/** Fixture (#172 D17, D19): poskytovatel, který spadne — formulář se vykreslí bez něj. */
class ThrowingFormTab extends ModuleFormTab
{
    public function build(array $data, bool $isNew, ModuleExtensionContext $context): ?FormTab
    {
        throw new \RuntimeException('form tab provider broke');
    }
}

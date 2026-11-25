<?php

declare(strict_types=1);

namespace Shipard\Module\Test\UiExtensions;

use Shipard\Core\Form\FormTab;
use Shipard\Core\Form\ModuleFormTab;
use Shipard\Core\Module\ModuleExtensionContext;

/**
 * Fixture (#172 D17): záložka formuláře cizí tabulky se sloupcem
 * z rozšíření. Záznam druhu `skip` záložku nemá.
 */
class FixtureFormTab extends ModuleFormTab
{
    public function build(array $data, bool $isNew, ModuleExtensionContext $context): ?FormTab
    {
        if (($data['kind'] ?? null) === 'skip') {
            return null;
        }
        return $this->tab($context, 'sales', 'Fixture sales')
            ->section()
            ->col()
            ->checkbox('contact_payment')
            ->separator('Fixture separator')
            ->build();
    }
}

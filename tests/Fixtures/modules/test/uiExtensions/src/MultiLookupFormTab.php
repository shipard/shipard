<?php

declare(strict_types=1);

namespace Shipard\Module\Test\UiExtensions;

use Shipard\Core\Form\FormTab;
use Shipard\Core\Form\ModuleFormTab;
use Shipard\Core\Module\ModuleExtensionContext;

/** Fixture (#176): záložka s prvkem `multilookup` — záložka z modulu ukládá jen sloupce tabulky. */
class MultiLookupFormTab extends ModuleFormTab
{
    public function build(array $data, bool $isNew, ModuleExtensionContext $context): ?FormTab
    {
        return $this->tab($context, 'multilookup', 'Multilookup')
            ->section()
            ->col()
            ->checkbox('contact_payment')
            ->multiLookup('links', 'test_uihost_records')
            ->build();
    }
}

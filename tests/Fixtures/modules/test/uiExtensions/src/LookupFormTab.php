<?php

declare(strict_types=1);

namespace Shipard\Module\Test\UiExtensions;

use Shipard\Core\Form\FormTab;
use Shipard\Core\Form\ModuleFormTab;
use Shipard\Core\Module\ModuleExtensionContext;

/** Fixture (#172 D17, D19): záložka s lookupem — v 1. fázi nepovolený prvek. */
class LookupFormTab extends ModuleFormTab
{
    public function build(array $data, bool $isNew, ModuleExtensionContext $context): ?FormTab
    {
        return $this->tab($context, 'lookup', 'Lookup')
            ->section()
            ->col()
            ->checkbox('contact_payment')
            ->lookup('kind', 'test_uihost_records')
            ->build();
    }
}

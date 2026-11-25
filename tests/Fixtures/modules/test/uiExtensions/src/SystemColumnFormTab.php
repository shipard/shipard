<?php

declare(strict_types=1);

namespace Shipard\Module\Test\UiExtensions;

use Shipard\Core\Form\FormTab;
use Shipard\Core\Form\ModuleFormTab;
use Shipard\Core\Module\ModuleExtensionContext;

/** Fixture (#172 D17, D19): záložka se systémovým sloupcem — uložení formuláře by ho nepřijalo. */
class SystemColumnFormTab extends ModuleFormTab
{
    public function build(array $data, bool $isNew, ModuleExtensionContext $context): ?FormTab
    {
        return $this->tab($context, 'state', 'State')
            ->section()
            ->col()
            ->inline()
            ->input('name')
            ->input('docState')
            ->endInline()
            ->build();
    }
}

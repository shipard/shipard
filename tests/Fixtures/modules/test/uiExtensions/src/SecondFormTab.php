<?php

declare(strict_types=1);

namespace Shipard\Module\Test\UiExtensions;

use Shipard\Core\Form\FormTab;
use Shipard\Core\Form\ModuleFormTab;
use Shipard\Core\Module\ModuleExtensionContext;

/** Fixture (#172 D17, D19): druhá záložka téhož modulu — pořadí záložek; jen prvky bez hodnoty. */
class SecondFormTab extends ModuleFormTab
{
    public function build(array $data, bool $isNew, ModuleExtensionContext $context): ?FormTab
    {
        return $this->tab($context, 'second', 'Second')
            ->section()
            ->col()
            ->content([['type' => 'hint', 'text' => 'Second tab']])
            ->build();
    }
}

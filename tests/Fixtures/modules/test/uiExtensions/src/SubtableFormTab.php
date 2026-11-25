<?php

declare(strict_types=1);

namespace Shipard\Module\Test\UiExtensions;

use Shipard\Core\Form\FormTab;
use Shipard\Core\Form\ModuleFormTab;
use Shipard\Core\Module\ModuleExtensionContext;

/** Fixture (#172 D17, D19): záložka typu subtable — smí být jen fields. */
class SubtableFormTab extends ModuleFormTab
{
    public function build(array $data, bool $isNew, ModuleExtensionContext $context): ?FormTab
    {
        return new FormTab('rows', 'Rows', [], 'subtable', ['table' => 'test_uihost_records', 'foreignKey' => 'kind', 'formId' => null]);
    }
}

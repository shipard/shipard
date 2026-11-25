<?php

declare(strict_types=1);

namespace Shipard\Module\Test\UiHost;

use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\TableForm;

/**
 * Fixture (#172 D17): PHP formulář hostitelské tabulky se dvěma záložkami —
 * záložka z modulu se má přidat za ně. V `module.jsonc` registrovaný není
 * (test ho registruje sám), aby šla testovat i cesta JSONC a automatická.
 */
class RecordsForm extends TableForm
{
    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        return new FormDefinition(
            table: $this->table,
            title: 'Record',
            titleNew: 'New record',
            tabs: [
                $this->tab('basic', 'Basic')->section()->col()->input('name')->build(),
                $this->tab('settings', 'Settings')->section()->col()->input('kind')->build(),
            ],
        );
    }
}

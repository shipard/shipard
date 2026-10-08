<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\WorkOrders;

use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\TableForm;

/**
 * Formulář druhu zakázky (D14): název, typ a poznámka. Typ se po založení
 * druhu nenabízí k editaci (jen ke čtení) — hlídá ho i KindDocument.
 */
class KindsForm extends TableForm
{
    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        $types = new WorkOrderTypes($this->config);

        $tab = $this->tab('basic', $this->defaultGeneralTabLabel())
            ->section()
                ->col()
                    ->input('name', required: true)
                    ->select(
                        'type',
                        options: $types->options(),
                        required: true,
                        readOnly: !$isNew,
                        hint: $isNew
                            ? 'Typ určuje, co zakázky druhu mají (zákazník, nadřazená zakázka, fakturace). Po potvrzení druhu ho nejde změnit.'
                            : 'Typ se po založení druhu nemění.',
                    )
                    ->separator('Poznámka')
                    ->textarea('notice')
            ->build();

        return new FormDefinition(
            table: $this->table,
            title: 'Druh zakázky',
            titleNew: 'Nový druh zakázky',
            tabs: [$tab],
        );
    }
}

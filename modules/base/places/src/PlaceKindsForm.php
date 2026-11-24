<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Places;

use Shipard\Core\Form\EnumOptionsHelper;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\TableForm;

/**
 * Formulář druhu místa (#169 D2, D14, D15): název, typ a ikona.
 *
 * Typ je po prvním potvrzení jen ke čtení — stejně jako ho hlídá
 * PlaceKindDocument: dokud je uložený druh v Konceptu, typ měnit jde.
 * Ikona je volitelná; prázdná volba = ikona typu.
 */
class PlaceKindsForm extends TableForm
{
    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        $types = new PlaceTypes($this->config);
        $typeLocked = !$isNew && (int) ($data['docState'] ?? PlaceKindDocument::STATE_DRAFT) !== PlaceKindDocument::STATE_DRAFT;

        $tab = $this->tab('basic', $this->defaultGeneralTabLabel())
            ->section()
                ->col()
                    ->input('name', required: true)
                    ->select(
                        'type',
                        options: $types->options(),
                        required: true,
                        readOnly: $typeLocked,
                        hint: $typeLocked
                            ? $this->formText('base.places.formTexts', 'kind.typeLockedHint', 'The type of a confirmed kind cannot be changed.')
                            : $this->formText('base.places.formTexts', 'kind.typeHint', 'The type decides where places of this kind fit in the hierarchy and whether they have an address. It cannot be changed once the kind is confirmed.'),
                    )
                    ->select(
                        'icon',
                        options: $this->iconOptions(),
                        required: false,
                        placeholder: $this->formText('base.places.formTexts', 'kind.iconByType', 'By type'),
                        hint: $this->formText('base.places.formTexts', 'kind.iconHint', 'Empty = the icon of the type.'),
                    )
            ->build();

        return new FormDefinition(
            table: $this->table,
            title: 'Druh místa',
            titleNew: 'Nový druh místa',
            tabs: [$tab],
        );
    }

    /** @return list<array{value: string, label: string}> */
    private function iconOptions(): array
    {
        $cfg = $this->config?->cfgItem(PlaceKindDocument::ICONS_CFG_ITEM);
        return is_array($cfg) ? EnumOptionsHelper::fromCfgData($cfg, 'enumString', PlaceKindDocument::ICONS_CFG_ITEM) : [];
    }
}

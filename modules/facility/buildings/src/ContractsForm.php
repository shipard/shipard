<?php

declare(strict_types=1);

namespace Shipard\Module\Facility\Buildings;

use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\RecalculateResult;
use Shipard\Core\Form\TableForm;
use Shipard\Module\Base\Places\PlaceDocument;
use Shipard\Module\Base\Places\PlacesLookup;
use Shipard\Module\Base\Places\PlaceTypes;

/**
 * Formulář smlouvy (#174 D24): druh, číslo, název, nájemce, zahájení,
 * ukončení, místa a interní poznámka; tab Přílohy.
 *
 * Druh jde volit jen v Konceptu a při změně se formulář postaví znovu
 * (`triggers: 'reload'`) — podle vlastností typu druhu ({@see ContractTypes})
 * se řídí pole **Místa**: u typu bez míst je skryté, u typu s povinnými
 * místy má hvězdičku a nabídka hledání se omezí na povolené typy míst
 * (filtr `types` lookupu míst). Místa, která novému typu nevyhovují,
 * přepočet nemaže — ohlásí je validace při uložení.
 *
 * Místa jsou prvek `multilookup` nad child tabulkou
 * `facility_buildings_contract_places` (klíč `places`).
 */
class ContractsForm extends TableForm
{
    public function applyNewRecordDefaults(array &$data): void
    {
        $kindId = (int) ($data['kind'] ?? 0);
        if ($kindId > 0) {
            $data['type'] = $this->kindType($kindId);
        }
    }

    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        $types = new ContractTypes($this->config);
        $isDraft = $isNew || (int) ($data['docState'] ?? ContractDocument::STATE_DRAFT) === ContractDocument::STATE_DRAFT;

        // V Konceptu rozhoduje zvolený druh, potom uložený typ smlouvy.
        $kindId = (int) ($data['kind'] ?? 0);
        $type = $isDraft && $kindId > 0 ? (string) ($this->kindType($kindId) ?? '') : '';
        if ($type === '') {
            $type = (string) ($data['type'] ?? '');
        }

        $placesMode = $type !== '' ? $types->places($type) : ContractTypes::PLACES_OPTIONAL;
        $placeTypes = $type !== '' ? $types->placeTypes($type) : null;
        $typesFilter = PlacesLookup::typesFilter($placeTypes);
        $placesHint = $placeTypes !== null && $placeTypes !== []
            ? $this->formText('facility.buildings.formTexts', 'contract.placesTypesHint', 'Allowed place types: {types}.', [
                'types' => $this->placeTypeLabels($placeTypes),
            ])
            : $this->formText('facility.buildings.formTexts', 'contract.placesAnyHint', 'Places of any type.');

        $tab = $this->tab('basic', 'Smlouva')
            ->section()
                ->col()
                    ->select(
                        'kind',
                        options: $this->kindOptions($data['kind'] ?? null),
                        required: true,
                        readOnly: !$isDraft,
                        triggers: 'reload',
                        hint: $isDraft
                            ? $this->formText('facility.buildings.formTexts', 'contract.kindHint', 'The kind decides the contract type and number; it cannot be changed after confirmation.')
                            : null,
                    )
                    ->input(
                        'number',
                        readOnly: true,
                        placeholder: $isDraft
                            ? $this->formText('facility.buildings.formTexts', 'contract.numberPlaceholder', 'Assigned on confirmation')
                            : null,
                    )
                    ->input('title', required: true)
                    ->lookup(
                        'customer',
                        table: 'base_persons_persons',
                        placeholder: $this->formText('facility.buildings.formTexts', 'contract.customerPlaceholder', 'Search for a person…'),
                        hint: $this->formText('facility.buildings.formTexts', 'contract.customerHint', 'Required to confirm the contract.'),
                        editForm: true,
                        createForm: true,
                    )
                    ->date(
                        'date_start',
                        hint: $this->formText('facility.buildings.formTexts', 'contract.startHint', 'Required to confirm the contract; its year decides the contract number.'),
                    )
                    ->date(
                        'date_end',
                        hint: $this->formText('facility.buildings.formTexts', 'contract.endHint', 'Empty = open-ended.'),
                    )
                    ->multiLookup(
                        ContractDocument::PLACES_KEY,
                        table: PlaceDocument::TABLE,
                        filter: $typesFilter !== null ? [PlacesLookup::FILTER_TYPES => $typesFilter] : null,
                        label: $this->formText('facility.buildings.formTexts', 'contract.places', 'Places'),
                        placeholder: $this->formText('facility.buildings.formTexts', 'contract.placesPlaceholder', 'Search for a place…'),
                        required: $placesMode === ContractTypes::PLACES_REQUIRED,
                        hidden: $placesMode === ContractTypes::PLACES_NONE,
                        hint: $placesHint,
                    )
                    ->textarea('internal_note')
            ->build();

        return new FormDefinition(
            table: $this->table,
            title: 'Smlouva',
            titleNew: 'Nová smlouva',
            tabs: [$tab, $this->attachmentsTab()],
        );
    }

    public function recalculate(string $changedColumn, array $data): RecalculateResult
    {
        if ($changedColumn === 'kind') {
            $kindId = (int) ($data['kind'] ?? 0);
            $data['type'] = $kindId > 0 ? $this->kindType($kindId) : null;
        }
        return new RecalculateResult($this->buildFormDefinition($data, empty($data['id'])), $data);
    }

    /**
     * Druhy, které jde na smlouvě zvolit (ne archiv, ne koš); uložený druh,
     * který už platný není, v nabídce zůstává.
     *
     * @return list<array{value: int, label: string}>
     */
    private function kindOptions(mixed $current): array
    {
        $currentId = (int) ($current ?? 0);
        $options = [];
        $found = $currentId <= 0;
        foreach ($this->loadKinds($currentId) as $row) {
            $id = (int) $row['id'];
            $options[] = ['value' => $id, 'label' => (string) $row['name']];
            $found = $found || $id === $currentId;
        }
        if (!$found) {
            $options[] = ['value' => $currentId, 'label' => '#' . $currentId];
        }
        return $options;
    }

    /**
     * Živé druhy a k nim uložený druh smlouvy (přepsatelné v testech).
     *
     * @return list<array<string, mixed>> {id, name, type}
     */
    protected function loadKinds(int $currentId): array
    {
        if ($this->db === null) {
            return [];
        }
        return $this->db->fetchAll(
            'SELECT `id`, `name`, `type` FROM `' . ContractKindDocument::TABLE . '`'
            . ' WHERE `docState` IN %in OR `id` = %i ORDER BY `name` ASC, `id` ASC',
            ContractKindDocument::LIVE_STATES,
            $currentId,
        );
    }

    /** Typ smlouvy druhu, null = druh neexistuje. */
    private function kindType(int $kindId): ?string
    {
        foreach ($this->loadKinds($kindId) as $row) {
            if ((int) $row['id'] === $kindId) {
                $type = (string) ($row['type'] ?? '');
                return $type !== '' ? $type : null;
            }
        }
        return null;
    }

    /** @param list<string> $placeTypes */
    private function placeTypeLabels(array $placeTypes): string
    {
        $places = new PlaceTypes($this->config);
        return implode(', ', array_map(static fn (string $type): string => $places->label($type), $placeTypes));
    }
}

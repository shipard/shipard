<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Places;

use Shipard\Core\Form\EnumOptionsHelper;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\TableForm;

/**
 * Formulář místa (#169 D4, D12, D13).
 *
 * Dvě věci se řídí zvoleným druhem a přepočítají se při jeho změně
 * (`triggers: 'reload'` → výchozí `recalculate()` formulář postaví znovu):
 *
 *  - sekce **Adresa** je vidět jen u druhu, jehož typ má `hasAddress`
 *  - lookup **Nadřazené místo** dostává filtr `childType` (jen místa, pod
 *    která typ smí) a `exclude` (bez záznamu a jeho podstromu). Filtr musí
 *    být dynamický — druh se mění ve formuláři, statický by nestačil
 *
 * Nový záznam s předvyplněným `parent` (a případně `kind`) funguje bez
 * dalšího kódu — hodnoty přijdou v datech a filtr i adresa se z nich
 * spočítají stejně. Pořadí formulář jen ukazuje; nové a přesunuté místo
 * řadí na konec PlaceDocument.
 */
class PlacesForm extends TableForm
{
    private const COUNTRIES_CFG_ITEM = 'world.base.countries';

    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        $types = new PlaceTypes($this->config);
        $kindId = (int) ($data['kind'] ?? 0);
        $kinds = $this->loadKinds($kindId);
        $type = $kindId > 0 ? self::kindType($kinds, $kindId) : null;
        $hasAddress = $type !== null && $types->hasAddress($type);

        $parentFilter = [];
        if ($type !== null) {
            $parentFilter[PlacesLookup::FILTER_CHILD_TYPE] = $type;
        }
        if (!empty($data['id'])) {
            $parentFilter[PlacesLookup::FILTER_EXCLUDE] = (int) $data['id'];
        }

        $tab = $this->tab('basic', $this->defaultGeneralTabLabel())
            ->section()
                ->col()
                    ->input('name', required: true)
                    ->inline()
                        ->input(
                            'short_name',
                            hint: $this->formText('base.places.formTexts', 'place.shortNameHint', 'Shown in the path and the tree instead of the name.'),
                        )
                        ->input(
                            'code',
                            hint: $this->formText('base.places.formTexts', 'place.codeHint', 'Unique within the parent place.'),
                        )
                    ->endInline()
                    ->inline()
                        ->select(
                            'kind',
                            options: $this->kindOptions($kinds, $types),
                            triggers: 'reload',
                            required: true,
                        )
                        ->number(
                            'order_pos',
                            hint: $this->formText('base.places.formTexts', 'place.orderHint', 'Order within the parent place. 0 = placed last on save.'),
                        )
                    ->endInline()
                    ->lookup(
                        'parent',
                        table: PlaceDocument::TABLE,
                        filter: $parentFilter !== [] ? $parentFilter : null,
                        placeholder: $this->formText('base.places.formTexts', 'place.parentPlaceholder', 'Search for a place…'),
                        hint: $type !== null
                            ? $this->formText('base.places.formTexts', 'place.parentHint', 'Only places this kind may sit under are offered. Empty = top level.')
                            : $this->formText('base.places.formTexts', 'place.parentHintNoKind', 'Choose the kind first — the offer depends on it. Empty = top level.'),
                    )
                    ->textarea('note')

            ->section(title: 'Adresa', hidden: !$hasAddress)
                ->col()
                    ->input('street')
                    ->inline()
                        ->input('house_number')
                        ->input('orientation_number')
                    ->endInline()
                    ->inline()
                        ->input('latitude')
                        ->input('longitude')
                    ->endInline()
                ->col()
                    ->input('city')
                    ->inline()
                        ->input('zip')
                        ->select('country', options: $this->countryOptions(), required: false)
                    ->endInline()
            ->build();

        return new FormDefinition(
            table: $this->table,
            title: 'Místo',
            titleNew: 'Nové místo',
            tabs: [$tab, $this->attachmentsTab()],
        );
    }

    /**
     * Nabídka druhů `Název (Typ)`: živé druhy a k nim uložený druh, který
     * už živý není — jinak by ho select tiše vyprázdnil.
     *
     * @param list<array<string, mixed>> $kinds
     * @return list<array{value: int, label: string}>
     */
    private function kindOptions(array $kinds, PlaceTypes $types): array
    {
        $options = [];
        foreach ($kinds as $kind) {
            $label = (string) $kind['name'] . ' (' . $types->label((string) $kind['type']) . ')';
            if (!in_array((int) ($kind['docState'] ?? 10), PlaceKindDocument::LIVE_STATES, true)) {
                $label = $this->formText('base.places.formTexts', 'place.retiredKind', '{kind} — retired kind', ['kind' => $label]);
            }
            $options[] = ['value' => (int) $kind['id'], 'label' => $label];
        }
        return $options;
    }

    /** @param list<array<string, mixed>> $kinds */
    private static function kindType(array $kinds, int $kindId): ?string
    {
        foreach ($kinds as $kind) {
            if ((int) $kind['id'] === $kindId) {
                $type = (string) ($kind['type'] ?? '');
                return $type !== '' ? $type : null;
            }
        }
        return null;
    }

    /** @return list<array{value: string, label: string}> */
    private function countryOptions(): array
    {
        $cfg = $this->config?->cfgItem(self::COUNTRIES_CFG_ITEM);
        return is_array($cfg) ? EnumOptionsHelper::fromCfgData($cfg, 'enumString', self::COUNTRIES_CFG_ITEM) : [];
    }

    /**
     * Živé druhy míst a k nim druh `$currentId` v jakémkoli stavu, podle
     * názvu (přepsatelné v testech).
     *
     * @return list<array<string, mixed>> `{id, name, type, docState}`
     */
    protected function loadKinds(int $currentId): array
    {
        if ($this->db === null) {
            return [];
        }
        return $this->db->fetchAll(
            'SELECT `id`, `name`, `type`, `docState` FROM `' . PlaceKindDocument::TABLE . '`'
            . ' WHERE `docState` IN %in OR `id` = %i'
            . ' ORDER BY `name` ASC, `id` ASC',
            PlaceKindDocument::LIVE_STATES,
            $currentId,
        );
    }
}

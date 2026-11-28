<?php

declare(strict_types=1);

namespace Shipard\Module\Facility\Buildings;

use Shipard\Core\Form\EnumOptionsHelper;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormTab;
use Shipard\Core\Form\RecalculateResult;
use Shipard\Core\Form\TableForm;
use Shipard\Module\Base\Places\PlaceDocument;
use Shipard\Module\Base\Places\PlacesLookup;
use Shipard\Module\Base\Places\PlaceTypes;
use Shipard\Module\Economy\PeriodicInvoicing\InvoicingFormOptions;
use Shipard\Module\Economy\PeriodicInvoicing\InvoicingSettings;
use Shipard\Module\Economy\PeriodicInvoicing\InvoicingSettingsResolver;
use Shipard\Module\Economy\PeriodicInvoicing\Rows\PrescriptionRowsSubtable;

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
 *
 * Fakturace (#174 D29, D30, D37): sekce Fakturace (periodicita, fakturovat
 * od, text dokladu, měna, pevný VS, středisko) a přepisy výchozích hodnot
 * druhu — prázdné pole ukazuje hodnotu druhu jako zástupný text „Z druhu: …“
 * (InvoicingSettingsResolver; nabídky sdílí s formulářem zakázky,
 * InvoicingFormOptions). Tab Řádky = sub-tabulka řádků předpisu
 * (ContractRowsForm) mezi hlavní záložkou a Přílohami: je to jiný
 * mechanismus než Místa — uložení smlouvy na řádky nesahá, ukládá je dialog
 * řádku. Řídí se stavem smlouvy (server hlídá ContractRowLockProvider).
 */
class ContractsForm extends TableForm
{
    use InvoicingFormOptions;

    public const CURRENCIES_CFG_ITEM = 'world.base.currencies';
    public const PERIODICITIES_CFG_ITEM = 'economy.periodicInvoicing.periodicities';
    public const ROWS_TAB = 'rows';
    public const ROWS_FORM_ID = 'facility.buildings.contractRows';

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

        // Předpis: hodnoty druhu jako zástupný text prázdných přepisů.
        $kind = $kindId > 0 ? $this->loadKindRow($kindId) : null;
        $kindValues = InvoicingSettingsResolver::kindValues($kind);
        $effectiveDocType = InvoicingSettingsResolver::resolve($data, $kind)->docType;
        $fromKind = fn (string $column, array $options): ?string => isset($kindValues[$column])
            ? $this->formText('facility.buildings.formTexts', 'contract.fromKind', 'From the kind: {value}', [
                'value' => $this->optionLabel($options, $kindValues[$column]),
            ])
            : null;
        $docTypeOptions = $this->invoiceDocTypeOptions();
        $seriesOptions = $this->invoiceSeriesOptions($effectiveDocType, $data['inv_number_series'] ?? null);
        $timingOptions = $this->invoiceTimingOptions();
        $vatModeOptions = $this->invoiceVatModeOptions();
        $paymentOptions = $this->invoicePaymentMethodOptions();
        $bankOptions = $this->invoiceBankAccountOptions();

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
                    ->separator('Fakturace')
                    ->select(
                        'inv_periodicity',
                        options: $this->periodicityOptions(),
                        required: false,
                        hint: $this->formText('facility.buildings.formTexts', 'contract.periodicityHint', 'Calendar period; empty = the contract is not invoiced.'),
                    )
                    ->date(
                        'inv_from',
                        hint: $this->formText('facility.buildings.formTexts', 'contract.invFromHint', 'The first invoiced period is the one containing this date; empty = the start date, it may not be earlier.'),
                    )
                    ->input(
                        'inv_doc_text',
                        placeholder: $this->formText('facility.buildings.formTexts', 'contract.docTextPlaceholder', 'Empty = contract title and period'),
                        hint: $this->formText('facility.buildings.formTexts', 'contract.docTextHint', 'The {období} variable is replaced with the period in the language of the tenant, e.g. October 2026.'),
                    )
                    ->select(
                        'currency',
                        options: $this->currencyOptions(),
                        required: false,
                        hint: $this->formText('facility.buildings.formTexts', 'contract.currencyHint', 'Empty = the home currency.'),
                    )
                    ->input(
                        'payment_reference',
                        hint: $this->formText('facility.buildings.formTexts', 'contract.paymentReferenceHint', 'Fixed payment reference — every invoice of the contract carries it.'),
                    )
                    ->lookup(
                        'cost_center',
                        table: 'economy_codebooks_cost_centers',
                        placeholder: $this->formText('facility.buildings.formTexts', 'contract.costCenterPlaceholder', 'Search for a cost center…'),
                        hint: $this->formText('facility.buildings.formTexts', 'contract.costCenterHint', 'Cost center of the documents of this contract.'),
                    )
                    ->separator('Přepisy výchozích hodnot druhu')
                    ->select(
                        'inv_doc_type',
                        options: $docTypeOptions,
                        required: false,
                        triggers: 'reload',
                        placeholder: $fromKind('inv_doc_type', $docTypeOptions),
                    )
                    ->select(
                        'inv_number_series',
                        options: $seriesOptions,
                        required: false,
                        placeholder: $fromKind('inv_number_series', $seriesOptions),
                        hint: $effectiveDocType === null
                            ? $this->formText('facility.buildings.formTexts', 'contract.seriesNoTypeHint', 'Choose the document type first — here or on the kind.')
                            : null,
                    )
                    ->number(
                        'inv_due_days',
                        hint: isset($kindValues['inv_due_days'])
                            ? $this->formText('facility.buildings.formTexts', 'contract.dueDaysKindHint', 'From the kind: {days} days', ['days' => (int) $kindValues['inv_due_days']])
                            : $this->formText('facility.buildings.formTexts', 'contract.dueDaysDefaultHint', 'Empty = {days} days.', ['days' => InvoicingSettings::DEFAULT_DUE_DAYS]),
                    )
                    ->select(
                        'inv_timing',
                        options: $timingOptions,
                        required: false,
                        placeholder: $fromKind('inv_timing', $timingOptions),
                    )
                    ->select(
                        'inv_vat_mode',
                        options: $vatModeOptions,
                        required: false,
                        placeholder: $fromKind('inv_vat_mode', $vatModeOptions),
                    )
                    ->select(
                        'inv_payment_method',
                        options: $paymentOptions,
                        required: false,
                        placeholder: $fromKind('inv_payment_method', $paymentOptions),
                    )
                    ->select(
                        'inv_bank_account',
                        options: $bankOptions,
                        required: false,
                        placeholder: $fromKind('inv_bank_account', $bankOptions),
                        hint: $this->formText('facility.buildings.formTexts', 'contract.bankAccountHint', 'Empty on both the contract and the kind = the default account of the document.'),
                    )
                    ->separator('Poznámka')
                    ->textarea('internal_note')
            ->build();

        $rows = $this->subtableTab(
            self::ROWS_TAB,
            'Řádky',
            ContractDocument::ROWS_TABLE,
            'contract',
            formId: self::ROWS_FORM_ID,
            orderColumn: PrescriptionRowsSubtable::ORDER_COLUMN,
        );

        return new FormDefinition(
            table: $this->table,
            title: 'Smlouva',
            titleNew: 'Nová smlouva',
            tabs: [$tab, $rows, $this->attachmentsTab()],
        );
    }

    public function recalculate(string $changedColumn, array $data): RecalculateResult
    {
        if ($changedColumn === 'kind') {
            $kindId = (int) ($data['kind'] ?? 0);
            $data['type'] = $kindId > 0 ? $this->kindType($kindId) : null;
        }
        if ($changedColumn === 'inv_doc_type') {
            // Řada patří typu dokladu — po změně typu se vybírá znovu.
            $data['inv_number_series'] = null;
        }
        return new RecalculateResult($this->buildFormDefinition($data, empty($data['id'])), $data);
    }

    /**
     * Sub-tabulka řádků předpisu: popis, množství, jednotka, cena, DPH,
     * platnost (společný PrescriptionRowsSubtable). Ostatní taby default.
     */
    public function renderSubtable(FormTab $tab, array $rows, array $parentData): array
    {
        if ($tab->id !== self::ROWS_TAB) {
            return parent::renderSubtable($tab, $rows, $parentData);
        }
        $table = ContractDocument::ROWS_TABLE;

        return PrescriptionRowsSubtable::render(
            $rows,
            fn (string $column, string $fallback): string => $this->subtableLabel($table, $column, $fallback),
            PrescriptionRowsSubtable::unitShortcuts($this->db),
            $this->language,
        );
    }

    /**
     * Druh se sloupci fakturačního předpisu (`inv_*`), null = neexistuje
     * (přepsatelné v testech).
     *
     * @return array<string, mixed>|null
     */
    protected function loadKindRow(int $kindId): ?array
    {
        if ($this->db === null) {
            return null;
        }
        return $this->db->fetchRow(
            'SELECT * FROM `' . ContractKindDocument::TABLE . '` WHERE `id` = %i',
            $kindId,
        );
    }

    /** @return list<array{value: string, label: string}> */
    private function currencyOptions(): array
    {
        $cfg = $this->config?->cfgItem(self::CURRENCIES_CFG_ITEM);
        return is_array($cfg) ? EnumOptionsHelper::fromCfgData($cfg, 'enumString', self::CURRENCIES_CFG_ITEM) : [];
    }

    /** @return list<array{value: string, label: string}> */
    private function periodicityOptions(): array
    {
        $cfg = $this->config?->cfgItem(self::PERIODICITIES_CFG_ITEM);
        return is_array($cfg) ? EnumOptionsHelper::fromCfgData($cfg, 'enumString') : [];
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

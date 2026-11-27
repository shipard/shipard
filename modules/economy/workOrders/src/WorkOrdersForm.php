<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\WorkOrders;

use Shipard\Core\Form\EnumOptionsHelper;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormTab;
use Shipard\Core\Form\RecalculateResult;
use Shipard\Core\Form\TableForm;
use Shipard\Core\Settings\SettingsStore;
use Shipard\Module\Economy\PeriodicInvoicing\InvoicingFormOptions;
use Shipard\Module\Economy\PeriodicInvoicing\InvoicingSettings;
use Shipard\Module\Economy\PeriodicInvoicing\InvoicingSettingsResolver;
use Shipard\Module\Economy\PeriodicInvoicing\Rows\PrescriptionRowsSubtable;

/**
 * Formulář zakázky (tasks/work-orders-phase1.md §3, §5; fáze 2 §1).
 *
 * Číselná řada určuje druh a typ (denormalizuje je dokument; formulář je
 * dosadí do dat při založení z tabu řady a při změně řady), typ určuje,
 * která pole se nabízí (WorkOrderTypes): zákazník, měna a VS jen externím,
 * nadřazená zakázka jen jednorázovým, fakturační předpis jen periodickým.
 * Řada jde měnit jen u konceptu. Skrytá pole se při změně řady vyprázdní,
 * aby validace nehlásila chybu na poli, které uživatel nevidí.
 *
 * Fakturační předpis (D3): sekce Fakturace (periodicita, fakturovat od,
 * text dokladu) a přepisy výchozích hodnot druhu — prázdné pole ukazuje
 * hodnotu druhu jako placeholder „Z druhu: …“ (InvoicingSettingsResolver).
 * Tab Řádky = sub-tabulka řádků předpisu (WorkOrderRowsForm); řídí se
 * stavem zakázky jako řádky dokladu — Přidat / Smazat / přesun v Konceptu
 * a V opravě, u V pořádku jen ke čtení (server hlídá
 * WorkOrderRowLockProvider, tasks/work-orders-rows-readonly.md). Tab
 * Přílohy (P6).
 */
class WorkOrdersForm extends TableForm
{
    use InvoicingFormOptions;

    public const CURRENCIES_CFG_ITEM = 'world.base.currencies';
    public const ROWS_TAB = 'rows';
    public const ROWS_FORM_ID = 'economy.workOrders.rows';

    private ?string $homeCurrency = null;

    public function applyNewRecordDefaults(array &$data): void
    {
        $seriesId = (int) ($data['number_series'] ?? 0);
        if ($seriesId <= 0) {
            return;
        }
        $this->applySeries($data, $seriesId);
    }

    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        $types = new WorkOrderTypes($this->config);
        $isDraft = $isNew || (int) ($data['docState'] ?? WorkOrderDocument::STATE_DRAFT) === WorkOrderDocument::STATE_DRAFT;
        $type = (string) ($data['type'] ?? '');
        if ($type === '' && !empty($data['number_series'])) {
            $type = (string) ($this->seriesInfo((int) $data['number_series'])['type'] ?? '');
        }
        $external = $type !== '' && $types->isExternal($type);
        $oneOff = $type !== '' && $types->isOneOff($type);
        $periodic = $type !== '' && $types->invoicing($type) === WorkOrderTypes::INVOICING_PERIODIC;

        $kindId = (int) ($data['kind'] ?? 0);
        $kind = $periodic && $kindId > 0 ? $this->loadKind($kindId) : null;
        $kindValues = InvoicingSettingsResolver::kindValues($kind);
        $effectiveDocType = $periodic ? InvoicingSettingsResolver::resolve($data, $kind)->docType : null;
        $fromKind = fn(string $column, array $options): ?string => isset($kindValues[$column])
            ? 'Z druhu: ' . $this->optionLabel($options, $kindValues[$column])
            : null;
        $docTypeOptions = $this->invoiceDocTypeOptions();
        $seriesOptions = $this->invoiceSeriesOptions($effectiveDocType, $data['inv_number_series'] ?? null);
        $timingOptions = $this->invoiceTimingOptions();
        $vatModeOptions = $this->invoiceVatModeOptions();
        $paymentOptions = $this->invoicePaymentMethodOptions();
        $bankOptions = $this->invoiceBankAccountOptions();

        $tab = $this->tab('basic', 'Zakázka')
            ->section()
                ->col()
                    ->separator('Identifikace')
                    ->select(
                        'number_series',
                        options: $this->seriesOptions($data['number_series'] ?? null),
                        required: true,
                        readOnly: !$isDraft,
                        triggers: 'reload',
                        hint: $isDraft ? 'Řada určuje druh a typ zakázky; po potvrzení se nemění.' : null,
                    )
                    ->select(
                        'type',
                        options: $types->options(),
                        required: false,
                        readOnly: true,
                        hidden: $type === '',
                    )
                    ->input(
                        'number',
                        readOnly: true,
                        placeholder: $isDraft ? 'Přidělí se při potvrzení' : null,
                    )
                    ->input('title', required: true)
                    ->separator('Zákazník', hidden: !$external)
                    ->lookup(
                        'customer',
                        table: 'base_persons_persons',
                        placeholder: 'Hledat osobu…',
                        hidden: !$external,
                        hint: $external ? 'Povinný při potvrzení zakázky.' : null,
                        editForm: true,
                        createForm: true,
                    )
                    ->select('currency', options: $this->currencyOptions(), required: false, hidden: !$external)
                    ->input(
                        'payment_reference',
                        hidden: !$external,
                        hint: 'Pevný variabilní symbol — ponesou ho všechny faktury zakázky.',
                    )
                    ->separator('Zařazení')
                    ->lookup(
                        'parent',
                        table: WorkOrderDocument::TABLE,
                        filter: [WorkOrdersLookup::FILTER_ROLE => WorkOrdersLookup::ROLE_PARENT],
                        placeholder: 'Hledat zakázku…',
                        hidden: !$oneOff,
                        hint: 'Nadřazená smí být libovolná zakázka kromě periodické.',
                    )
                    ->lookup(
                        'cost_center',
                        table: 'economy_codebooks_cost_centers',
                        placeholder: 'Hledat středisko…',
                        hint: 'Výchozí středisko dokladů zakázky.',
                    )
                    ->separator('Platnost')
                    ->date('date_start', hint: 'Povinné při potvrzení; u řady s ročním restartem určuje rok čísla.')
                    ->date(
                        'date_end',
                        hint: $periodic
                            ? 'Do kdy zakázka platí — smí být v budoucnu. Období začínající po něm se už nevystaví.'
                            : 'Skutečné ukončení — doplní se při přechodu do Ukončeno nebo Zrušeno.',
                    )
                    ->separator('Fakturace', hidden: !$periodic)
                    ->select(
                        'inv_periodicity',
                        options: $this->periodicityOptions(),
                        required: false,
                        hidden: !$periodic,
                        hint: 'Kalendářní období; povinná při potvrzení.',
                    )
                    ->date(
                        'inv_from',
                        hidden: !$periodic,
                        hint: 'První fakturované období je to, které obsahuje toto datum; prázdné = zahájení.',
                    )
                    ->input(
                        'inv_doc_text',
                        hidden: !$periodic,
                        placeholder: 'Prázdné = název zakázky a období',
                        hint: 'Proměnná {období} se nahradí obdobím v jazyce zákazníka, např. „říjen 2026“.',
                    )
                    ->separator('Přepisy výchozích hodnot druhu', hidden: !$periodic)
                    ->select(
                        'inv_doc_type',
                        options: $docTypeOptions,
                        required: false,
                        hidden: !$periodic,
                        triggers: 'reload',
                        placeholder: $fromKind('inv_doc_type', $docTypeOptions),
                    )
                    ->select(
                        'inv_number_series',
                        options: $seriesOptions,
                        required: false,
                        hidden: !$periodic,
                        placeholder: $fromKind('inv_number_series', $seriesOptions),
                        hint: $effectiveDocType === null ? 'Nejdřív vyber typ dokladu — tady nebo na druhu.' : null,
                    )
                    ->number(
                        'inv_due_days',
                        hidden: !$periodic,
                        hint: isset($kindValues['inv_due_days'])
                            ? 'Z druhu: ' . (int) $kindValues['inv_due_days'] . ' dní'
                            : 'Prázdné = ' . InvoicingSettings::DEFAULT_DUE_DAYS . ' dní.',
                    )
                    ->select(
                        'inv_timing',
                        options: $timingOptions,
                        required: false,
                        hidden: !$periodic,
                        placeholder: $fromKind('inv_timing', $timingOptions),
                    )
                    ->select(
                        'inv_vat_mode',
                        options: $vatModeOptions,
                        required: false,
                        hidden: !$periodic,
                        placeholder: $fromKind('inv_vat_mode', $vatModeOptions),
                    )
                    ->select(
                        'inv_payment_method',
                        options: $paymentOptions,
                        required: false,
                        hidden: !$periodic,
                        placeholder: $fromKind('inv_payment_method', $paymentOptions),
                    )
                    ->select(
                        'inv_bank_account',
                        options: $bankOptions,
                        required: false,
                        hidden: !$periodic,
                        placeholder: $fromKind('inv_bank_account', $bankOptions),
                        hint: 'Prázdné na zakázce i druhu = výchozí účet dokladu.',
                    )
                    ->separator('Poznámka')
                    ->textarea('internal_note')
            ->build();

        $tabs = [$tab];
        if ($periodic) {
            $tabs[] = $this->subtableTab(
                self::ROWS_TAB,
                'Řádky',
                WorkOrderDocument::ROWS_TABLE,
                'work_order',
                formId: self::ROWS_FORM_ID,
                orderColumn: 'order_pos',
            );
        }
        $tabs[] = $this->attachmentsTab();

        return new FormDefinition(
            table: $this->table,
            title: 'Zakázka',
            titleNew: 'Nová zakázka',
            tabs: $tabs,
        );
    }

    public function recalculate(string $changedColumn, array $data): RecalculateResult
    {
        if ($changedColumn === 'number_series') {
            $seriesId = (int) ($data['number_series'] ?? 0);
            if ($seriesId > 0) {
                $this->applySeries($data, $seriesId);
            } else {
                $data['kind'] = null;
                $data['type'] = null;
            }
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
        $table = WorkOrderDocument::ROWS_TABLE;

        return PrescriptionRowsSubtable::render(
            $rows,
            fn(string $column, string $fallback): string => $this->subtableLabel($table, $column, $fallback),
            $this->unitShortcuts(),
            $this->language,
        );
    }

    /**
     * Druh a typ z řady do dat; pole, která typ nemá, se vyprázdní;
     * externí typ bez měny dostane domácí měnu.
     *
     * @param array<string, mixed> $data
     */
    private function applySeries(array &$data, int $seriesId): void
    {
        $info = $this->seriesInfo($seriesId);
        if ($info === null) {
            return;
        }
        $types = new WorkOrderTypes($this->config);
        $type = (string) ($info['type'] ?? '');
        $data['kind'] = (int) $info['kind'];
        $data['type'] = $type !== '' ? $type : null;

        if ($type === '' || !$types->isExternal($type)) {
            $data['customer'] = null;
            $data['currency'] = null;
            $data['payment_reference'] = null;
        } elseif (empty($data['currency'])) {
            $data['currency'] = $this->homeCurrency();
        }
        if ($type === '' || !$types->isOneOff($type)) {
            $data['parent'] = null;
        }
        if ($type === '' || $types->invoicing($type) !== WorkOrderTypes::INVOICING_PERIODIC) {
            foreach ([...InvoicingSettings::COLUMNS, ...InvoicingSettings::RECORD_COLUMNS] as $col) {
                $data[$col] = null;
            }
        }
    }

    /**
     * Řada s druhem a typem: `{kind, type, kind_name}`, null = neexistuje
     * (přepsatelné v testech).
     *
     * @return array<string, mixed>|null
     */
    protected function seriesInfo(int $seriesId): ?array
    {
        if ($this->db === null) {
            return null;
        }
        return $this->db->fetchRow(
            'SELECT s.`id`, s.`kind`, k.`type`, k.`name` AS `kind_name`'
            . ' FROM `' . WorkOrderDocument::SERIES_TABLE . '` s'
            . ' LEFT JOIN `' . KindDocument::TABLE . '` k ON k.`id` = s.`kind`'
            . ' WHERE s.`id` = %i',
            $seriesId,
        );
    }

    /**
     * Druh zakázky se sloupci fakturačního předpisu (inv_*), null =
     * neexistuje (přepsatelné v testech).
     *
     * @return array<string, mixed>|null
     */
    protected function loadKind(int $kindId): ?array
    {
        if ($this->db === null) {
            return null;
        }
        return $this->db->fetchRow(
            'SELECT * FROM `' . KindDocument::TABLE . '` WHERE `id` = %i',
            $kindId,
        );
    }

    /**
     * Platné řady (ne archiv, ne koš) jako `řada — druh`; uložená řada,
     * která už platná není, v nabídce zůstává.
     *
     * @return list<array{value: int, label: string}>
     */
    private function seriesOptions(mixed $current): array
    {
        if ($this->db === null) {
            return [];
        }
        $currentId = (int) ($current ?? 0);
        $options = [];
        $found = $currentId <= 0;
        foreach ($this->db->fetchAll(
            'SELECT s.`id`, s.`name`, s.`docState`, k.`name` AS `kind_name`'
            . ' FROM `' . WorkOrderDocument::SERIES_TABLE . '` s'
            . ' LEFT JOIN `' . KindDocument::TABLE . '` k ON k.`id` = s.`kind`'
            . ' WHERE s.`docState` IN (10, 40, 80) OR s.`id` = %i'
            . ' ORDER BY k.`name` ASC, s.`name` ASC',
            $currentId,
        ) as $row) {
            $id = (int) $row['id'];
            $label = (string) $row['name'];
            if (!empty($row['kind_name'])) {
                $label .= ' — ' . (string) $row['kind_name'];
            }
            $options[] = ['value' => $id, 'label' => $label];
            $found = $found || $id === $currentId;
        }
        if (!$found) {
            $options[] = ['value' => $currentId, 'label' => '#' . $currentId];
        }
        return $options;
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
        $cfg = $this->config?->cfgItem('economy.periodicInvoicing.periodicities');
        return is_array($cfg) ? EnumOptionsHelper::fromCfgData($cfg, 'enumString') : [];
    }

    /**
     * Zkratky jednotek pro sub-tabulku řádků (id → zkratka nebo název).
     *
     * @return array<int, string>
     */
    protected function unitShortcuts(): array
    {
        return PrescriptionRowsSubtable::unitShortcuts($this->db);
    }

    /** Domácí měna DS ze settings `economy.homeCurrency` (vzor DocsHeadsFormBase). */
    protected function homeCurrency(): string
    {
        if ($this->homeCurrency === null) {
            $value = $this->db !== null
                ? (new SettingsStore($this->db))->get(WorkOrderDocument::HOME_CURRENCY_SETTING)
                : null;
            $this->homeCurrency = is_string($value) && $value !== '' ? $value : WorkOrderDocument::DEFAULT_HOME_CURRENCY;
        }
        return $this->homeCurrency;
    }
}

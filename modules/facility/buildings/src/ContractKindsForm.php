<?php

declare(strict_types=1);

namespace Shipard\Module\Facility\Buildings;

use Shipard\Core\Form\EnumOptionsHelper;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormTab;
use Shipard\Core\Form\RecalculateResult;
use Shipard\Core\Form\TableForm;
use Shipard\Module\Economy\PeriodicInvoicing\InvoicingFormOptions;
use Shipard\Module\Economy\PeriodicInvoicing\InvoicingSettings;
use Shipard\Module\Economy\PeriodicInvoicing\Rows\PrescriptionRowsSubtable;

/**
 * Formulář druhu smlouvy (Nastavení → Budovy, #174 D18): název, typ,
 * poznámka a číslování — kód druhu, vzorec čísla a restart čítače.
 *
 * Typ jde zvolit jen u konceptu, který ještě nepoužívá žádná smlouva
 * (ContractKindDocument to hlídá i na serveru); potvrzený druh ho má jen ke
 * čtení. Nový druh dostane výchozí vzorec a roční restart.
 *
 * Fakturace (#174 D29, D32): sekce Výchozí hodnoty fakturace — typ dokladu,
 * řada, splatnost, okamžik fakturace, režim DPH, způsob platby a účet, které
 * smlouva druhu smí přepsat (nabídky sdílí s druhem zakázky,
 * InvoicingFormOptions). Tab Šablona řádků = sub-tabulka řádků předpisu bez
 * platnosti (ContractKindRowsForm); nová smlouva druhu ji dostane jako své
 * řádky. Šablona se řídí stavem druhu — u potvrzeného druhu přes V opravě
 * (ContractKindRowLockProvider).
 */
class ContractKindsForm extends TableForm
{
    use InvoicingFormOptions;

    public const TEMPLATE_TAB = 'template';
    public const TEMPLATE_FORM_ID = 'facility.buildings.contractKindRows';

    public function applyNewRecordDefaults(array &$data): void
    {
        $data['number_pattern'] ??= ContractKindsProvisioner::DEFAULT_PATTERN;
        $data['reset_scope'] ??= ContractKindDocument::RESET_YEARLY;
    }

    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        $types = new ContractTypes($this->config);
        $confirmed = !$isNew && (int) ($data['docState'] ?? ContractKindDocument::STATE_DRAFT) !== ContractKindDocument::STATE_DRAFT;
        $used = !$isNew && !$confirmed && $this->isKindUsed((int) ($data['id'] ?? 0));
        $typeHint = match (true) {
            $confirmed => $this->formText('facility.buildings.formTexts', 'kind.typeLockedHint', 'The type of a confirmed kind cannot be changed.'),
            $used => $this->formText('facility.buildings.formTexts', 'kind.typeUsedHint', 'The type cannot be changed while contracts use this kind.'),
            default => $this->formText('facility.buildings.formTexts', 'kind.typeHint', 'The type decides which places a contract of this kind may have. It cannot be changed once the kind is confirmed or used by a contract.'),
        };
        $docType = (string) ($data['inv_doc_type'] ?? '');

        $tab = $this->tab('basic', $this->defaultGeneralTabLabel())
            ->section()
                ->col()
                    ->input('name', required: true)
                    ->select(
                        'type',
                        options: $types->options(),
                        required: true,
                        readOnly: $confirmed || $used,
                        hint: $typeHint,
                    )
                    ->textarea('notice')
                    ->separator('Číslování')
                    ->input(
                        'number_code',
                        hint: $this->formText('facility.buildings.formTexts', 'kind.codeHint', 'Short code used as %C in the number pattern, unique among kinds.'),
                    )
                    ->input(
                        'number_pattern',
                        required: true,
                        hint: $this->formText('facility.buildings.formTexts', 'kind.patternHint', '%C kind code, %y / %Y year the contract starts (2 / 4 digits), %3 to %6 sequence padded with zeros. The sequence is required.'),
                    )
                    ->select(
                        'reset_scope',
                        options: $this->resetScopeOptions(),
                        required: true,
                        hint: $this->formText('facility.buildings.formTexts', 'kind.resetHint', 'Yearly = the sequence starts again every calendar year a contract starts in; the pattern must then contain the year.'),
                    )
                    ->separator('Výchozí hodnoty fakturace')
                    ->select(
                        'inv_doc_type',
                        options: $this->invoiceDocTypeOptions(),
                        required: false,
                        triggers: 'reload',
                        hint: $this->formText('facility.buildings.formTexts', 'kind.invDocTypeHint', 'Invoice or advance invoice; a contract may override it.'),
                    )
                    ->select(
                        'inv_number_series',
                        options: $this->invoiceSeriesOptions($docType !== '' ? $docType : null, $data['inv_number_series'] ?? null),
                        required: false,
                        hint: $docType === ''
                            ? $this->formText('facility.buildings.formTexts', 'kind.invSeriesNoTypeHint', 'Choose the document type first.')
                            : $this->formText('facility.buildings.formTexts', 'kind.invSeriesHint', 'A number series of the document type.'),
                    )
                    ->number(
                        'inv_due_days',
                        hint: $this->formText('facility.buildings.formTexts', 'kind.invDueDaysHint', 'Empty = {days} days.', ['days' => InvoicingSettings::DEFAULT_DUE_DAYS]),
                    )
                    ->select(
                        'inv_timing',
                        options: $this->invoiceTimingOptions(),
                        required: false,
                        hint: $this->formText('facility.buildings.formTexts', 'kind.invTimingHint', 'Issue date and tax point date of the document; empty = at the start of the period.'),
                    )
                    ->select(
                        'inv_vat_mode',
                        options: $this->invoiceVatModeOptions(),
                        required: false,
                        hint: $this->formText('facility.buildings.formTexts', 'kind.invVatModeHint', 'Prices on prescription rows without VAT (from the base) or with VAT (from the total); empty = from the base.'),
                    )
                    ->select(
                        'inv_payment_method',
                        options: $this->invoicePaymentMethodOptions(),
                        required: false,
                        hint: $this->formText('facility.buildings.formTexts', 'kind.invPaymentMethodHint', 'Empty = bank transfer.'),
                    )
                    ->select(
                        'inv_bank_account',
                        options: $this->invoiceBankAccountOptions(),
                        required: false,
                        hint: $this->formText('facility.buildings.formTexts', 'kind.invBankAccountHint', 'Empty = the default account of the document.'),
                    )
            ->build();

        $template = $this->subtableTab(
            self::TEMPLATE_TAB,
            'Šablona řádků',
            ContractDocument::KIND_ROWS_TABLE,
            'kind',
            formId: self::TEMPLATE_FORM_ID,
            orderColumn: PrescriptionRowsSubtable::ORDER_COLUMN,
        );

        return new FormDefinition(
            table: $this->table,
            title: 'Druh smlouvy',
            titleNew: 'Nový druh smlouvy',
            tabs: [$tab, $template],
        );
    }

    public function recalculate(string $changedColumn, array $data): RecalculateResult
    {
        if ($changedColumn === 'inv_doc_type') {
            // Řada patří typu dokladu — po změně typu se vybírá znovu.
            $data['inv_number_series'] = null;
        }
        return new RecalculateResult($this->buildFormDefinition($data, empty($data['id'])), $data);
    }

    /** Sub-tabulka šablony řádků — sada sloupců řádku předpisu bez platnosti. */
    public function renderSubtable(FormTab $tab, array $rows, array $parentData): array
    {
        if ($tab->id !== self::TEMPLATE_TAB) {
            return parent::renderSubtable($tab, $rows, $parentData);
        }
        $table = ContractDocument::KIND_ROWS_TABLE;

        return PrescriptionRowsSubtable::render(
            $rows,
            fn (string $column, string $fallback): string => $this->subtableLabel($table, $column, $fallback),
            PrescriptionRowsSubtable::unitShortcuts($this->db),
            $this->language,
            withValidity: false,
        );
    }

    /** @return list<array{value: int|string, label: string}> */
    private function resetScopeOptions(): array
    {
        $cfg = $this->config?->cfgItem(ContractKindDocument::RESET_SCOPES_CFG_ITEM);
        return EnumOptionsHelper::fromCfgData($cfg, 'enumString', ContractKindDocument::RESET_SCOPES_CFG_ITEM);
    }

    /** Používá druh nějaká smlouva mimo koš? (přepsatelné v testech) */
    protected function isKindUsed(int $kindId): bool
    {
        if ($this->db === null || $kindId <= 0) {
            return false;
        }
        return (int) $this->db->fetchSingle(
            'SELECT COUNT(*) FROM `' . ContractDocument::TABLE . '` WHERE `kind` = %i AND `docState` <> %i',
            $kindId,
            ContractDocument::STATE_DELETED,
        ) > 0;
    }
}

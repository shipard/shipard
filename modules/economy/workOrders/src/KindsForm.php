<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\WorkOrders;

use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\RecalculateResult;
use Shipard\Core\Form\TableForm;
use Shipard\Module\Economy\WorkOrders\Invoicing\InvoicingFormOptions;
use Shipard\Module\Economy\WorkOrders\Invoicing\InvoicingSettings;

/**
 * Formulář druhu zakázky (D14): název, typ a poznámka. Typ se po založení
 * druhu nenabízí k editaci (jen ke čtení) — hlídá ho i KindDocument.
 *
 * Druh periodického typu má sekci Výchozí hodnoty fakturace (D3): typ
 * dokladu, řada dokladů podle typu, splatnost, fakturace na počátku /
 * konci období, režim DPH, způsob platby, vlastní účet. Zakázka je smí
 * přepsat; prázdné na zakázce = odsud.
 */
class KindsForm extends TableForm
{
    use InvoicingFormOptions;

    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        $types = new WorkOrderTypes($this->config);
        $type = (string) ($data['type'] ?? '');
        $periodic = $type !== '' && $types->invoicing($type) === WorkOrderTypes::INVOICING_PERIODIC;
        $docType = (string) ($data['inv_doc_type'] ?? '');

        $tab = $this->tab('basic', $this->defaultGeneralTabLabel())
            ->section()
                ->col()
                    ->input('name', required: true)
                    ->select(
                        'type',
                        options: $types->options(),
                        required: true,
                        readOnly: !$isNew,
                        triggers: $isNew ? 'reload' : null,
                        hint: $isNew
                            ? 'Typ určuje, co zakázky druhu mají (zákazník, nadřazená zakázka, fakturace). Po potvrzení druhu ho nejde změnit.'
                            : 'Typ se po založení druhu nemění.',
                    )
                    ->separator('Výchozí hodnoty fakturace', hidden: !$periodic)
                    ->select(
                        'inv_doc_type',
                        options: $this->invoiceDocTypeOptions(),
                        required: false,
                        hidden: !$periodic,
                        triggers: 'reload',
                        hint: 'Faktura, nebo zálohová faktura; zakázka smí přepsat. Povinné pro V pořádku.',
                    )
                    ->select(
                        'inv_number_series',
                        options: $this->invoiceSeriesOptions($docType !== '' ? $docType : null, $data['inv_number_series'] ?? null),
                        required: false,
                        hidden: !$periodic,
                        hint: $docType === '' ? 'Nejdřív vyber typ dokladu.' : 'Řada typu dokladu. Povinná pro V pořádku.',
                    )
                    ->number(
                        'inv_due_days',
                        hidden: !$periodic,
                        hint: 'Prázdné = ' . InvoicingSettings::DEFAULT_DUE_DAYS . ' dní.',
                    )
                    ->select(
                        'inv_timing',
                        options: $this->invoiceTimingOptions(),
                        required: false,
                        hidden: !$periodic,
                        hint: 'Datum vystavení a DUZP dokladu; prázdné = na počátku období.',
                    )
                    ->select(
                        'inv_vat_mode',
                        options: $this->invoiceVatModeOptions(),
                        required: false,
                        hidden: !$periodic,
                        hint: 'Ceny na řádcích předpisu bez DPH (ze základu), nebo s DPH (z ceny celkem); prázdné = ze základu.',
                    )
                    ->select(
                        'inv_payment_method',
                        options: $this->invoicePaymentMethodOptions(),
                        required: false,
                        hidden: !$periodic,
                        hint: 'Prázdné = převodem.',
                    )
                    ->select(
                        'inv_bank_account',
                        options: $this->invoiceBankAccountOptions(),
                        required: false,
                        hidden: !$periodic,
                        hint: 'Prázdné = výchozí účet dokladu.',
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

    public function recalculate(string $changedColumn, array $data): RecalculateResult
    {
        if ($changedColumn === 'inv_doc_type') {
            $data['inv_number_series'] = null;
        }
        if ($changedColumn === 'type') {
            $types = new WorkOrderTypes($this->config);
            $type = (string) ($data['type'] ?? '');
            if ($type === '' || $types->invoicing($type) !== WorkOrderTypes::INVOICING_PERIODIC) {
                foreach (InvoicingSettings::COLUMNS as $col) {
                    $data[$col] = null;
                }
            }
        }
        return new RecalculateResult($this->buildFormDefinition($data, empty($data['id'])), $data);
    }
}

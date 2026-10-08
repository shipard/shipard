<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\WorkOrders\Invoicing;

use Shipard\Core\Form\EnumOptionsHelper;

/**
 * Nabídky polí fakturačního předpisu sdílené formulářem druhu a zakázky
 * (KindsForm, WorkOrdersForm): typ dokladu, řada dokladů podle typu, režim
 * DPH, způsob platby, fakturace na počátku / konci, vlastní bankovní účet.
 * Trait pro TableForm — používá `$this->db` a `$this->config`.
 */
trait InvoicingFormOptions
{
    /** @return list<array{value: string, label: string}> */
    protected function invoiceDocTypeOptions(): array
    {
        $cfg = $this->config?->cfgItem('docs.core.docTypes');
        $options = [];
        foreach (InvoicingSettings::DOC_TYPES as $docType) {
            $entry = is_array($cfg) ? ($cfg[$docType] ?? null) : null;
            $options[] = [
                'value' => $docType,
                'label' => is_array($entry) && isset($entry['name']) ? (string) $entry['name'] : $docType,
            ];
        }
        return $options;
    }

    /**
     * Platné řady dokladů daného typu; bez typu prázdná nabídka (řadu nejde
     * vybrat dřív než typ). Uložená řada mimo výběr v nabídce zůstává.
     *
     * @return list<array{value: int, label: string}>
     */
    protected function invoiceSeriesOptions(?string $docType, mixed $current = null): array
    {
        if ($this->db === null || $docType === null || $docType === '') {
            return [];
        }
        $currentId = (int) ($current ?? 0);
        $options = [];
        $found = $currentId <= 0;
        foreach ($this->db->fetchAll(
            'SELECT `id`, `name` FROM `docs_core_number_series`'
            . ' WHERE (`doc_type` = %s AND `docState` IN (10, 40, 80)) OR `id` = %i'
            . ' ORDER BY `name` ASC',
            $docType,
            $currentId,
        ) as $row) {
            $id = (int) $row['id'];
            $options[] = ['value' => $id, 'label' => (string) $row['name']];
            $found = $found || $id === $currentId;
        }
        if (!$found) {
            $options[] = ['value' => $currentId, 'label' => '#' . $currentId];
        }
        return $options;
    }

    /** @return list<array{value: int, label: string}> */
    protected function invoiceVatModeOptions(): array
    {
        return $this->enumIntOptions('docs.core.vatModes');
    }

    /** @return list<array{value: int, label: string}> */
    protected function invoicePaymentMethodOptions(): array
    {
        return $this->enumIntOptions('docs.core.paymentMethods');
    }

    /** @return list<array{value: string, label: string}> */
    protected function invoiceTimingOptions(): array
    {
        $cfg = $this->config?->cfgItem('economy.workOrders.invoiceTimings');
        return is_array($cfg) ? EnumOptionsHelper::fromCfgData($cfg, 'enumString') : [];
    }

    /**
     * Vlastní bankovní účty (vzor DocsHeadsFormBase::resolveBankAccountOptions
     * bez měnového filtru — měna zakázky se může lišit od měny účtu).
     *
     * @return list<array{value: int, label: string}>
     */
    protected function invoiceBankAccountOptions(): array
    {
        if ($this->db === null) {
            return [];
        }
        $options = [];
        foreach ($this->db->fetchAll(
            'SELECT `id`, `code`, `name`, `currency` FROM `economy_codebooks_bank_accounts`'
            . ' WHERE `docState` IN (10, 40, 80)'
            . ' ORDER BY `code` ASC, `name` ASC',
        ) as $row) {
            $label = trim((string) ($row['code'] ?? '') . ' — ' . (string) ($row['name'] ?? ''), ' —');
            $currency = strtoupper((string) ($row['currency'] ?? ''));
            if ($currency !== '') {
                $label .= ' (' . $currency . ')';
            }
            $options[] = ['value' => (int) $row['id'], 'label' => $label];
        }
        return $options;
    }

    /**
     * Popisek hodnoty pro placeholder „Z druhu: …“ — label z nabídky,
     * jinak surová hodnota.
     *
     * @param list<array{value: int|string, label: string}> $options
     */
    protected function optionLabel(array $options, mixed $value): string
    {
        foreach ($options as $option) {
            if ((string) $option['value'] === (string) $value) {
                return $option['label'];
            }
        }
        return (string) $value;
    }

    /** @return list<array{value: int, label: string}> */
    private function enumIntOptions(string $cfgItemId): array
    {
        $cfg = $this->config?->cfgItem($cfgItemId);
        return is_array($cfg) ? EnumOptionsHelper::fromCfgData($cfg, 'enumInt', $cfgItemId) : [];
    }
}

<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

use Shipard\Core\Form\FormTab;

/**
 * Společná báze formulářů přijatých faktur — Faktura přijatá (FPB, `invni`)
 * a Zálohová faktura přijatá (FPZ, `invpi`, #106 D1). Zrcadlo
 * `IssuedInvoiceFormBase` pro vydané doklady.
 *
 * Oproti generickému DocsHeadsFormBase:
 *   - partner = dodavatel → `supplier_snapshot` (default base),
 *   - `buildHeaderTab()` — 2-sloupcový layout bez separátorů, jen pole
 *     potřebná pro každodenní práci s došlým dokladem, řazená podle toku
 *     práce (partner → platba → bankovní spojení dodavatele → symboly →
 *     datumy → číslo dokladu dodavatele); vpravo režim a výpočet DPH, místo
 *     plnění, měna a kurz, zaokrouhlení, období,
 *   - `buildExtraTabs()` — tab „Nastavení" za Přílohami s poli, která se
 *     nastavují zřídka: sekce DPH (`vat_registration`, `vat_rounding_mode`,
 *     `cs_mode`), Bankovní spojení (`bank_account`), Měna (`home_currency`
 *     readOnly), Ostatní (`constant_symbol`) + Vystavil.
 *
 * Nedaňový typ (`docTypes[].tax_document: false`, `isTaxDocument()`): DUZP,
 * DPPD a ruční zařazení do KH (`cs_mode`) se nezobrazují — doklad do tvrzení
 * DPH nepatří; sazby, rekapitulace a součty s DPH zůstávají.
 *
 * Subclassy přepisují jen titulky modalu a header-info hooky
 * (`getDocTypeLabel`, `getHeaderIcon`); FPB zůstává vykreslená beze změny
 * proti stavu před zavedením báze.
 */
abstract class ReceivedInvoiceFormBase extends DocsHeadsFormBase
{
    // getPartnerSnapshotKey() — default 'supplier_snapshot' je správně
    // pro přijaté doklady, override není potřeba.

    /** @param array<string, mixed> $data */
    protected function buildHeaderTab(array $data, bool $isNew): FormTab
    {
        $vatMode = (int) ($data['vat_mode'] ?? 1);
        $hasVat = $vatMode !== 0;
        $taxDocument = $this->isTaxDocument($data);
        $docCurrency = strtolower((string) ($data['doc_currency'] ?? 'czk'));
        $homeCurrency = strtolower((string) ($data['home_currency'] ?? 'czk'));
        $hasForeignCurrency = $docCurrency !== '' && $homeCurrency !== ''
            && $docCurrency !== $homeCurrency;
        $partnerId = (int) ($data['partner'] ?? 0);
        // #62: bankovní účet a IBAN partnera jen při platbě převodem
        // (hodnoty zůstávají, viz DocsHeadsFormBase::isBankTransferPayment).
        $isBankTransfer = $this->isBankTransferPayment($data);

        $tab = $this->tab('basic', 'Hlavička')
            ->section()
            ->col()
            ->select(
                'number_series',
                options: $this->resolveNumberSeriesOptions(
                    !empty($data['doc_type']) ? (string) $data['doc_type'] : null,
                ),
                required: true,
                readOnly: !$isNew,
                hidden: true,
            )
            ->input('doc_number', readOnly: true, hidden: true)

            ->lookup(
                'partner',
                table: 'base_persons_persons',
                placeholder: 'Hledat partnera…',
                triggers: 'reload',
                editForm: true,
                createForm: true,
            )
            ->lookup(
                'partner_address',
                table: 'base_persons_addresses',
                filter: $partnerId !== 0 ? ['person' => $partnerId] : null,
                placeholder: $partnerId !== 0 ? 'Vyberte adresu…' : 'Nejdřív vyberte partnera',
                readOnly: $partnerId === 0,
            )
            ->select(
                'payment_method',
                options: $this->resolveCfgItemOptions('docs.core.paymentMethods'),
                triggers: 'reload',
            )
            ->lookup(
                'cash_desk',
                table: 'economy_codebooks_cash_desks',
                placeholder: 'Hledat pokladnu…',
                hidden: !$this->isCashPayment($data),
                hint: $this->cashDeskHint($data, $docCurrency),
            )
            ->lookup(
                'partner_bank',
                table: 'base_persons_bank_accounts',
                filter: $partnerId !== 0 ? ['person' => $partnerId] : null,
                placeholder: $partnerId !== 0 ? 'Vyberte bankovní účet…' : 'Nejdřív vyberte partnera',
                readOnly: $partnerId === 0,
                hidden: !$isBankTransfer,
            )
            ->input('partner_bank_iban', label: 'IBAN', hidden: !$isBankTransfer);
        // FP: jen ruční Plátce (terminál / doprava jsou prodejní směr, #72 D2).
        $this->addPaymentIntermediaryElements($tab, $data);
        $tab
            ->input('payment_reference')
            ->input('specific_symbol')

            ->date('issue_date', required: true, triggers: 'reload')
            ->date('due_date')
            ->date('accounting_date', required: true)
            // Nedaňový doklad DUZP ani DPPD nemá (DocDocument je nuluje) —
            // pole skrytá.
            ->date('vat_duzp', hidden: !$hasVat || !$taxDocument)
            ->date('vat_dppd', hidden: !$hasVat || !$taxDocument)
            ->input('partner_doc_number');
        // Dimenze deníku (majetek) — výchozí hodnota pro řádky bez vlastní.
        $this->addDimensionElements($tab, $data);
        return $tab

            ->col()
            ->select(
                'vat_mode',
                options: $this->resolveCfgItemOptions('docs.core.vatModes'),
                triggers: 'reload',
            )
            ->select(
                'vat_calc_source',
                options: $this->resolveCfgItemOptions('docs.core.vatCalcSources'),
                hidden: !$hasVat,
                hint: self::VAT_CALC_SOURCE_HINT,
            )
            ->select(
                'vat_recap_source',
                options: $this->resolveCfgItemOptions('docs.core.vatRecapSources'),
                hidden: !$hasVat,
                hint: self::VAT_RECAP_SOURCE_HINT,
            )
            ->select(
                'vat_place',
                options: $this->resolveCfgItemOptions('docs.core.vatPlaces'),
                triggers: 'reload',
                hidden: !$hasVat,
            )

            ->select(
                'doc_currency',
                options: $this->resolveCurrencyOptions(),
                triggers: 'reload',
            )
            ->number('exchange_rate', hidden: !$hasForeignCurrency)

            ->select(
                'total_rounding_mode',
                options: $this->resolveCfgItemOptions('docs.core.roundingModes'),
            )

            ->date('period_from', hint: 'Volitelné, např. pronájem za období')
            ->date('period_to')

            ->section()
            ->col()
            ->input('doc_text')
            ->build();
    }

    /**
     * Přidává tab „Nastavení" na úplný konec formuláře (za Přílohami).
     * Obsahuje pole, která se u přijatých dokladů nastavují zřídka —
     * uživatel je většinou nepotřebuje při běžném pořizování.
     *
     * @param array<string, mixed> $data
     * @return list<FormTab>
     */
    protected function buildExtraTabs(array $data, bool $isNew): array
    {
        return [$this->buildSettingsTab($data)];
    }

    /** @param array<string, mixed> $data */
    protected function buildSettingsTab(array $data): FormTab
    {
        $vatMode = (int) ($data['vat_mode'] ?? 1);
        $hasVat = $vatMode !== 0;
        $taxDocument = $this->isTaxDocument($data);
        $docCurrency = strtolower((string) ($data['doc_currency'] ?? 'czk'));

        $tab = $this->tab('settings', 'Nastavení')
            ->section(title: 'DPH', hidden: !$hasVat)
            ->col()
            ->select(
                'vat_registration',
                options: $this->resolveVatRegistrationOptions(),
                triggers: 'reload',
                required: $hasVat,
            )
            ->select(
                'vat_rounding_mode',
                options: $this->resolveCfgItemOptions('docs.core.vatRoundingModes'),
                hidden: !$hasVat,
            )
            // Ruční zařazení do KH (#77) — u přijaté faktury B2/B3.
            // Nedaňový doklad do KH nepatří — pole skryté.
            ->select(
                'cs_mode',
                options: $this->resolveCfgItemOptions('economy.vat.controlStatementModes'),
                hidden: !$hasVat || !$taxDocument,
                hint: self::CS_MODE_HINT,
            )

            ->section(title: 'Bankovní spojení')
            ->col()
            ->select(
                'bank_account',
                options: $this->resolveBankAccountOptions($docCurrency),
            )

            ->section(title: 'Měna')
            ->col()
            ->input('home_currency', readOnly: true)

            ->section(title: 'Ostatní')
            ->col()
            ->input('constant_symbol');

        return $this->addAuthorElement($tab, $data)->build();
    }
}

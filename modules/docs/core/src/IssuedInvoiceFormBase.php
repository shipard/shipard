<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

use Shipard\Core\Form\FormTab;

/**
 * Společná báze formulářů vydaných faktur — Faktura vydaná (FVB, `invno`)
 * a Zálohová faktura vydaná (FVZ, `invpo`, #79 D1). Protějšek
 * `CashDeskFormBase` pro doklady vázané na pokladnu.
 *
 * Oproti generickému DocsHeadsFormBase:
 *   - partner = odběratel → `customer_snapshot` (`getPartnerSnapshotKey`),
 *   - `buildHeaderTab()` — 2-sloupcový layout bez separátorů, jen pole
 *     potřebná pro každodenní vystavování: vlevo odběratel → adresa →
 *     způsob platby (+ pokladna při hotovosti, terminál / brána / doprava)
 *     → datumy; vpravo režim DPH, místo plnění, měna a kurz, variabilní
 *     a specifický symbol, období. Bankovní účet odběratele a DPPD se
 *     nezadávají (DPPD odvozuje `DocDocument` z DUZP),
 *   - `buildExtraTabs()` — tab „Nastavení" za Přílohami: sekce Oprava
 *     (opravný daňový doklad, #92 — jen typ s `docTypes[].corrective`,
 *     tedy faktura vydaná: zaškrtávátko `corrective`, po zapnutí lookup
 *     původního dokladu, jeho číslo a datum plnění — při vyplněném odkazu
 *     jen ke čtení — a důvod opravy), Měna (`home_currency` readOnly)
 *     a Ostatní (`bank_account`, `vat_registration`, `vat_calc_source`,
 *     `vat_recap_source`, `total_rounding_auto` + `total_rounding_mode`
 *     (při zapnuté automatice jen ke čtení, #73), `vat_rounding_mode`,
 *     `cs_mode`, `constant_symbol`).
 *
 * Nedaňový typ (`docTypes[].tax_document: false`, `isTaxDocument()`): DUZP
 * a ruční zařazení do KH (`cs_mode`) se nezobrazují — doklad do tvrzení DPH
 * nepatří; sazby, rekapitulace a součty s DPH zůstávají.
 *
 * Subclassy přepisují jen titulky modalu a header-info hooky
 * (`getDocTypeLabel`, `getHeaderIcon`).
 */
abstract class IssuedInvoiceFormBase extends DocsHeadsFormBase
{
    protected function getPartnerSnapshotKey(): string
    {
        // Vydaný doklad: partner = odběratel → snímá se do customer_snapshot.
        return 'customer_snapshot';
    }

    /** Vydané doklady se odesílají odběrateli — přílohy lze označit k odeslání. */
    protected function attachmentsSendable(): bool
    {
        return true;
    }

    /** @param array<string, mixed> $data */
    protected function buildHeaderTab(array $data, bool $isNew): FormTab
    {
        $vatMode = (int) ($data['vat_mode'] ?? 1);
        $hasVat = $vatMode !== 0;
        $taxDocument = $this->isTaxDocument($data);
        $docCurrency = strtolower((string) ($data['doc_currency'] ?? 'czk'));
        $homeCurrency = strtolower((string) ($data['home_currency'] ?? 'czk'));
        $hasForeignCurrency = $this->hasForeignCurrency($data);
        $partnerId = (int) ($data['partner'] ?? 0);

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
            );
        // Terminál / brána, doprava, Plátce (#72) — sdílený helper base.
        $this->addPaymentIntermediaryElements($tab, $data);
        $tab
            ->date('issue_date', required: true, triggers: 'reload')
            ->date('due_date')
            ->date('accounting_date', required: true, triggers: 'reload')
            // Nedaňový doklad DUZP nemá (DocDocument ho nuluje) — pole skryté.
            ->date('vat_duzp', hidden: !$hasVat || !$taxDocument);
        // Dimenze deníku (majetek) — jen u typu, který ji deklaruje (FV ano,
        // proforma ne).
        $this->addDimensionElements($tab, $data);
        return $tab

            ->col()
            ->select(
                'vat_mode',
                options: $this->resolveCfgItemOptions('docs.core.vatModes'),
                triggers: 'reload',
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
            ->number('exchange_rate', hidden: !$hasForeignCurrency, hint: $this->exchangeRateHint($data))

            ->input('payment_reference')
            ->input('specific_symbol')

            ->date('period_from', hint: 'Volitelné, např. pronájem za období')
            ->date('period_to')

            ->section()
            ->col()
            ->input('doc_text')
            ->build();
    }

    /**
     * Přidává tab „Nastavení" na úplný konec formuláře (za Přílohami).
     * Obsahuje pole, která mají u vydaných dokladů v praxi jednu hodnotu
     * a při běžném vystavování se nemění: domácí měnu (readOnly), náš
     * bankovní účet, registraci DPH, způsob výpočtu DPH, zaokrouhlení
     * a konstantní symbol. `bank_account` je přesto při Potvrdit povinný
     * (per-typ Document třída) a `vat_registration` při vat_mode != 0
     * (DocDocument) — validační chyba se zobrazí na poli v tomto tabu.
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
        $docCurrency = strtolower((string) ($data['doc_currency'] ?? 'czk'));
        $vatMode = (int) ($data['vat_mode'] ?? 1);
        $hasVat = $vatMode !== 0;
        $taxDocument = $this->isTaxDocument($data);
        $corrective = $this->isCorrective($data);
        $hasCorrectedDoc = (int) ($data['corrected_doc'] ?? 0) > 0;
        $tab = $this->tab('settings', 'Nastavení')
            // Opravný daňový doklad (#92 D1–D3, U8): sekce jen u typu
            // s příznakem; po zapnutí odkaz na původní doklad (lookup
            // dokladů téhož typu, partnera je-li vyplněný), jeho číslo
            // a datum plnění — s odkazem jen ke čtení, dokument je při
            // uložení přepíše — a důvod opravy (při potvrzení povinný).
            ->section(title: 'Oprava', hidden: !$this->isCorrectiveType($data))
            ->col()
            ->checkbox('corrective', triggers: 'reload')
            ->lookup(
                'corrected_doc',
                table: 'docs_core_heads',
                filter: $this->correctedDocLookupFilter($data),
                placeholder: 'Hledat podle čísla, variabilního symbolu nebo partnera…',
                hidden: !$corrective,
                triggers: 'reload',
                hint: 'Nepovinné — souhrnný opravný doklad původní doklady vymezí v důvodu opravy.',
            )
            ->input('corrected_doc_number', readOnly: $hasCorrectedDoc, hidden: !$corrective)
            ->date(
                'corrected_doc_date',
                readOnly: $hasCorrectedDoc,
                hidden: !$corrective,
                hint: 'Sazby DPH opravného dokladu se určují k tomuto datu.',
            )
            ->textarea(
                'correction_reason',
                hidden: !$corrective,
                hint: 'Při potvrzení povinný. U souhrnné opravy sem patří i vymezení původních dokladů.',
            )

            ->section(title: 'Měna')
            ->col()
            ->input('home_currency', readOnly: true)

            ->section(title: 'Ostatní')
            ->col()
            ->select(
                'bank_account',
                options: $this->resolveBankAccountOptions($docCurrency),
            )
            ->select(
                'vat_registration',
                options: $this->resolveVatRegistrationOptions(),
                triggers: 'reload',
                required: $hasVat,
                hidden: !$hasVat,
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

            // Automatické zaokrouhlení podle způsobu úhrady (#73 D4): při
            // zapnutém mód určuje doklad, select je jen ke čtení.
            ->checkbox('total_rounding_auto', triggers: 'reload', hidden: !$this->appliesTotalRoundingAuto($data))
            ->select(
                'total_rounding_mode',
                options: $this->resolveCfgItemOptions('docs.core.roundingModes'),
                readOnly: $this->isTotalRoundingAuto($data),
            )
            ->select(
                'vat_rounding_mode',
                options: $this->resolveCfgItemOptions('docs.core.vatRoundingModes'),
                hidden: !$hasVat,
            )
            // Ruční zařazení do KH (#77) — u vydané faktury A4/A5; vždy
            // jednotlivě bez CZ DIČ odběratele hlásí kontrolní hlášení chybu.
            // Nedaňový doklad do KH nepatří — pole skryté.
            ->select(
                'cs_mode',
                options: $this->resolveCfgItemOptions('economy.vat.controlStatementModes'),
                hidden: !$hasVat || !$taxDocument,
                hint: self::CS_MODE_HINT,
            )
            ->input('constant_symbol');
        return $this->addAuthorElement($tab, $data)->build();
    }
}

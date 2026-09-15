<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\CashRegister;

use Shipard\Core\Form\FormTab;
use Shipard\Module\Docs\Core\CashDeskFormBase;

/**
 * Editační formulář prodejky — `doc_type = 'cashreg'`.
 *
 * Minimalistická hlavička: způsob úhrady (Hotovost / Převodem / Kartou),
 * nepovinný partner, datum vystavení (+ účetní datum a DUZP, které se z něj
 * doplní), měna jen pro čtení (= měna pokladny), readOnly sekce „Pokladna",
 * text dokladu. Řádky, rekapitulace, poznámky a přílohy z base.
 *
 * Tab „Nastavení" za Přílohami (`buildExtraTabs`, vzor pokladního dokladu
 * z #67) — Issue #68: režim DPH, místo plnění, registrace DPH a obě
 * zaokrouhlení. Na rozdíl od pokladního dokladu tu bydlí i samotný režim
 * DPH, proto se sekce „DPH" při `vat_mode = 0` neskrývá — skryjí se jen
 * podřízená pole.
 */
class CashRegisterForm extends CashDeskFormBase
{
    protected function getFormTitle(): string
    {
        return 'Prodejka';
    }

    protected function getNewFormTitle(): string
    {
        return 'Nová prodejka';
    }

    protected function getDocTypeLabel(): string
    {
        return 'Prodejka';
    }

    protected function getHeaderIcon(): ?string
    {
        return 'cash-register';
    }

    /** Prodejka: hotově, převodem (pohledávka, partner povinný) nebo kartou. */
    protected function allowedPaymentMethods(): array
    {
        return CashRegisterDocument::PAYMENT_METHODS_ALLOWED;
    }

    protected function getPartnerSnapshotKey(): string
    {
        // Prodejka = výstup, partner je odběratel.
        return 'customer_snapshot';
    }

    /** @param array<string, mixed> $data */
    protected function buildHeaderTab(array $data, bool $isNew): FormTab
    {
        $vatMode = (int) ($data['vat_mode'] ?? 1);
        $hasVat = $vatMode !== 0;

        $tab = $this->tab('basic', 'Hlavička')
            ->section()
                ->col();
        $this->addSeriesSelect($tab, $data, $isNew)
                    ->input('doc_number', readOnly: true, hidden: true)

                    ->select(
                        'payment_method',
                        options: $this->paymentMethodOptions(),
                        triggers: 'reload',
                    )
                    ->lookup(
                        'partner',
                        table: 'base_persons_persons',
                        placeholder: 'Hledat partnera… (nepovinné)',
                        triggers: 'reload',
                        editForm: true,
                        createForm: true,
                    );
        // Terminál / brána, doprava, Plátce (#72) — karta a brána bez
        // protistrany prodejku nepustí (partner_balance_required).
        $this->addPaymentIntermediaryElements($tab, $data)
                    ->date('issue_date', required: true, triggers: 'reload')
                    ->date('accounting_date', required: true)
                    ->date('vat_duzp', hidden: !$hasVat)

                ->col()
                    ->input('doc_currency', readOnly: true, hint: 'Měna pokladny');

        $this->addCashDeskSection($tab, $data);

        return $tab
            ->section()
                ->col()
                    ->input('doc_text')
            ->build();
    }

    /**
     * Tab „Nastavení" na konci formuláře (za Přílohami) — Issue #68.
     *
     * @param array<string, mixed> $data
     * @return list<FormTab>
     */
    protected function buildExtraTabs(array $data, bool $isNew): array
    {
        return [$this->buildSettingsTab($data)];
    }

    /**
     * Režim DPH, místo plnění, registrace DPH a zaokrouhlení.
     * `vat_registration` je při vat_mode != 0 povinná (DocDocument) —
     * validační chyba se zobrazí na poli v tomto tabu, FormEditor na něj
     * přepne. `vat_mode` a `vat_place` mají `reload` (řídí viditelnost DUZP
     * v hlavičce a polí tady); frontend při reloadu drží aktivní tab.
     *
     * @param array<string, mixed> $data
     */
    protected function buildSettingsTab(array $data): FormTab
    {
        $vatMode = (int) ($data['vat_mode'] ?? 1);
        $hasVat = $vatMode !== 0;

        return $this->tab('settings', 'Nastavení')
            ->section(title: 'DPH')
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
                        'vat_registration',
                        options: $this->resolveVatRegistrationOptions(),
                        triggers: 'reload',
                        required: $hasVat,
                        hidden: !$hasVat,
                    )

            ->section(title: 'Zaokrouhlení')
                ->col()
                    ->select(
                        'total_rounding_mode',
                        options: $this->resolveCfgItemOptions('docs.core.roundingModes'),
                    )
                    ->select(
                        'vat_rounding_mode',
                        options: $this->resolveCfgItemOptions('docs.core.vatRoundingModes'),
                        hidden: !$hasVat,
                    )
            ->build();
    }
}

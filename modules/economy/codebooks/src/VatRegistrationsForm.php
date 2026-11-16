<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Codebooks;

use Shipard\Core\Form\EnumOptionsHelper;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\TableForm;

class VatRegistrationsForm extends TableForm
{
    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        if ($isNew) {
            if (empty($data['region'])) {
                $data['region'] = 'eu';
            }
            if (empty($data['country'])) {
                $data['country'] = 'cz';
            }
            if (!isset($data['taxpayer_kind']) || $data['taxpayer_kind'] === '' || $data['taxpayer_kind'] === null) {
                $data['taxpayer_kind'] = 0;
            }
            if (!isset($data['tax_period_kind']) || $data['tax_period_kind'] === '' || $data['tax_period_kind'] === null) {
                $data['tax_period_kind'] = 1;
            }
            if (!isset($data['cs_period_kind']) || $data['cs_period_kind'] === '' || $data['cs_period_kind'] === null) {
                $data['cs_period_kind'] = 1;
            }
            if (!isset($data['rs_period_kind']) || $data['rs_period_kind'] === '' || $data['rs_period_kind'] === null) {
                $data['rs_period_kind'] = 1;
            }
        }

        $regionOptions = $this->resolveStringOptions('world.trade.unions');
        $countryOptions = $this->resolveStringOptions('world.base.countries', sortByLabel: true);
        $taxpayerKindOptions = $this->resolveIntOptions('economy.codebooks.vatTaxpayerKinds');
        $periodKindOptions = $this->resolveIntOptions('economy.codebooks.vatPeriodKinds');

        $tab = $this->tab('basic', $this->defaultGeneralTabLabel())
            ->section()
                ->col()
                    ->input('name', required: true)
                    ->input('vat_id')
                    ->select('region', options: $regionOptions, required: true)
                    ->select('country', options: $countryOptions, required: true)
                    ->select('taxpayer_kind', options: $taxpayerKindOptions, required: true)
                    ->separator('Správce daně')
                    ->lookup('tax_office_person', 'base_persons_persons',
                        placeholder: 'Hledat osobu…',
                        hint: 'Finanční úřad jako osoba v adresáři — partner saldo řádku účetního dokladu '
                            . 'přiznání DPH. Lze doplnit i u potvrzené registrace.')
                    ->separator('Periodicita')
                    ->select('tax_period_kind', options: $periodKindOptions, required: true)
                    ->select('cs_period_kind', options: $periodKindOptions, required: true)
                    ->select('rs_period_kind', options: $periodKindOptions, required: true)
                    ->separator('Platnost')
                    ->date('valid_from', required: true)
                    ->date('valid_to');

        // Koeficient odpočtu (#59 D13) žije v economy.vat; codebooks na něm
        // nezávisí — jen odkaz, a jen když je modul aktivní (jeho cfgItem
        // existuje v kompilované konfiguraci).
        if (!$isNew && is_array($this->config?->cfgItem('economy.vat.reportTypes'))) {
            $tab->separator('Krácený nárok na odpočet')
                ->content([['type' => 'hint', 'text' => $this->formText(
                    'economy.codebooks.formTexts',
                    'vatRegistration.deductionHint',
                    'Deduction coefficients (advance and settlement, per calendar year) are managed in Settings → Accounting → VAT deduction coefficients. Without a record the full deduction (1.00) applies.',
                )]]);
        }
        $basic = $tab->build();

        $tabs = [$basic];

        // Podací údaje = strukturované pole `filing_profile` (#74), které na
        // registraci přináší extension modulu economy.vat. Bez toho modulu
        // sloupec neexistuje a záložka se nekreslí; codebooks na economy.vat
        // nezávisí, ví o něm jen přes definici tabulky.
        if (!$isNew && $this->hasStructuredColumn('filing_profile')) {
            $tabs[] = $this->tab('filing', 'Podací údaje')
                ->section()
                    ->col()
                        ->content([['type' => 'hint', 'text' => $this->formText(
                            'economy.codebooks.formTexts',
                            'vatRegistration.filingHint',
                            'Details for filing returns and statements on the tax portal — who files, to whom and who prepared the output. They are filled into the filing header.',
                        )]])
                        ->addElements($this->structuredFieldElements('filing_profile', $data))
                ->build();
        }

        // Instance daňových tvrzení (přiznání / KH / SH) žijí ve vieweru
        // „Daňová tvrzení" modulu economy.vat — codebooks na něm nezávisí.
        return new FormDefinition(
            table: $this->table,
            title: 'Registrace DPH',
            titleNew: 'Nová registrace DPH',
            tabs: $tabs,
        );
    }

    /**
     * Správce daně smí přibýt i k registraci ve stavu V pořádku (#55 D30) —
     * jediná změna, kterou potvrzená registrace připouští bez „Opravit".
     * Document zvládá částečné uložení (merge s uloženým řádkem).
     */
    public function getReadOnlyEditableColumns(): array
    {
        return ['tax_office_person'];
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function resolveStringOptions(string $cfgItemId, bool $sortByLabel = false): array
    {
        if ($this->config === null) {
            return [];
        }

        $cfgData = $this->config->cfgItem($cfgItemId);
        if (!is_array($cfgData)) {
            return [];
        }

        $options = EnumOptionsHelper::fromCfgData($cfgData, 'enumString', $cfgItemId);

        if ($sortByLabel) {
            usort($options, static fn(array $a, array $b): int => strcmp($a['label'], $b['label']));
        }

        return $options;
    }

    /**
     * @return array<int, array{value: int, label: string}>
     */
    private function resolveIntOptions(string $cfgItemId): array
    {
        if ($this->config === null) {
            return [];
        }

        $cfgData = $this->config->cfgItem($cfgItemId);
        if (!is_array($cfgData)) {
            return [];
        }

        return EnumOptionsHelper::fromCfgData($cfgData, 'enumInt', $cfgItemId);
    }
}

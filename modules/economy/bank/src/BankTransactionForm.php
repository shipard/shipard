<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Bank;

use Shipard\Core\Form\EnumOptionsHelper;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\TableForm;
use Shipard\Core\Settings\SettingsStore;

/**
 * Editační formulář bankovní transakce. Transakce vzniká importem /
 * migrací, ne ručně (viewer nemá Přidat), takže formulář je vždy edit:
 * měnitelné jsou jen operation / partner / message a u transakce v cizí
 * měně exchange_rate (#121 D7 — ruční oprava kurzu, uložení přepočítá
 * částku v domácí měně, Zaúčtovat ji znovu zaúčtuje); ostatní pole jsou
 * read-only kontext. Stav Zaúčtováno je jen ke čtení (txStates), formulář
 * zamyká FormController. Plnou prezentaci (vč. účtu, výpisu) nese detail
 * vieweru.
 */
class BankTransactionForm extends TableForm
{
    public const string TABLE = 'economy_bank_transactions';

    private ?string $homeCurrency = null;

    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        $currency = strtolower(trim((string) ($data['currency'] ?? '')));
        $foreign = $currency !== '' && $currency !== $this->homeCurrency();

        $tab = $this->tab('basic', 'Transakce')
            ->section()
                ->col()
                    ->input('date_transaction', readOnly: true)
                    ->input('date_value', readOnly: true)
                    ->select('direction', options: $this->options('economy.bank.txDirections', 'enumInt'), readOnly: true)
                    ->input('amount', readOnly: true)
                    ->select('currency', options: $this->options('world.base.currencies', 'enumString'), readOnly: true)
                    ->input('amount_dom', readOnly: true)
                    ->number(
                        'exchange_rate',
                        hidden: !$foreign,
                        hint: 'Kurz za jednotku měny z kurzovního lístku; po změně se přepočítá částka v domácí měně.',
                    )

                    ->separator('Protistrana')
                    ->input('counterparty_account', readOnly: true)
                    ->input('counterparty_name', readOnly: true)
                    ->input('payment_reference', readOnly: true)
                    ->input('specific_symbol', readOnly: true)
                    ->input('constant_symbol', readOnly: true)

                    ->separator('Zaúčtování')
                    ->select('operation', options: $this->options('economy.bank.txOperations', 'enumString'))
                    ->lookup('partner', 'base_persons_persons', placeholder: 'Vyhledat partnera…')
                    ->input('message')
            ->build();

        return new FormDefinition(
            table: $this->table,
            title: 'Bankovní transakce',
            titleNew: 'Bankovní transakce',
            tabs: [$tab],
        );
    }

    /** @return list<array{value: mixed, label: string}>|null */
    private function options(string $cfgItem, string $type): ?array
    {
        $cfgData = $this->config?->cfgItem($cfgItem);
        return is_array($cfgData) ? EnumOptionsHelper::fromCfgData($cfgData, $type, $cfgItem) : null;
    }

    /** Domácí měna zdroje dat (economy.homeCurrency); nerozhodnuto = czk. */
    private function homeCurrency(): string
    {
        if ($this->homeCurrency === null) {
            $value = $this->db !== null ? (new SettingsStore($this->db))->get('economy.homeCurrency') : null;
            $this->homeCurrency = is_string($value) && $value !== '' ? strtolower($value) : 'czk';
        }
        return $this->homeCurrency;
    }
}

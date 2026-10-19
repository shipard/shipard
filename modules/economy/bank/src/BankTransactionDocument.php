<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Bank;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;

/**
 * Document class bankovní transakce (economy_bank_transactions).
 *
 * Transakce je prvotřídní záznam — vzniká importem výpisu (Fáze 2) nebo
 * migrací (Fáze 4), ne ručním zakládáním. Ve Fázi 1 ji lze vložit přímo
 * (integrační test) a editovat jí `operation` / `partner` / `message`.
 *
 * Účtuje ji bankovní mikroengine (Fáze 3) při přechodu do stavu 40; ten zde
 * není. Fingerprint NEPOČÍTÁME — to je úkol ingestion vrstvy (Fáze 2).
 */
class BankTransactionDocument extends Document
{
    public function validate(array &$data): ValidationResult
    {
        $r = new ValidationResult();

        $direction = isset($data['direction']) ? (int) $data['direction'] : 0;
        if ($direction !== 1 && $direction !== 2) {
            $r->addError('direction', 'Směr musí být Příjem nebo Výdaj.', 'invalid');
        }

        if (empty($data['bank_account'])) {
            $r->addError('bank_account', 'Bankovní účet je povinný.', 'required');
        }

        if (empty($data['currency'])) {
            $r->addError('currency', 'Měna je povinná.', 'required');
        } elseif (strtolower(trim((string) $data['currency'])) !== $this->homeCurrency()) {
            // Cizí měna bez kurzu — nikdy tiché 1 (#121 D7).
            $rate = $data['exchange_rate'] ?? null;
            if ($rate === null || $rate === '' || (float) $rate <= 0) {
                $r->addError('exchange_rate', 'Kurz je povinný pro cizí měnu.', 'required');
            }
        }

        if (empty($data['date_transaction'])) {
            $r->addError('date_transaction', 'Datum transakce je povinné.', 'required');
        }

        $amount = isset($data['amount']) ? (float) $data['amount'] : 0.0;
        if ($amount <= 0.0) {
            $r->addError('amount', 'Částka musí být kladná; směr drží pole Směr.', 'invalid');
        }

        // amount_dom je derivované (beforeSave ho dopočítá z amount × kurz),
        // takže zde odmítáme jen explicitně zápornou hodnotu.
        if (isset($data['amount_dom']) && $data['amount_dom'] !== '' && (float) $data['amount_dom'] < 0.0) {
            $r->addError('amount_dom', 'Částka v domácí měně nesmí být záporná.', 'invalid');
        }

        $this->validateOperationDirection($data, $direction, $r);

        return $r;
    }

    /**
     * Pohyb (economy.bank.txOperations) musí směrem odpovídat transakci —
     * formulář nabízí všechny pohyby bez filtru, takže výdaj s `transfer.in`
     * by účtoval 221 DAL / 261100 DAL-protistrana obráceně (#59 Task D).
     * Bez configu nebo u neznámého pohybu se kontrola degradovaně přeskočí
     * (enum hodnotu hlídá schéma sloupce, vzor DocRowOperationRules).
     */
    private function validateOperationDirection(array $data, int $direction, ValidationResult $r): void
    {
        $operation = trim((string) ($data['operation'] ?? ''));
        if ($operation === '' || $this->config === null || ($direction !== 1 && $direction !== 2)) {
            return;
        }
        $ops = $this->config->cfgItem('economy.bank.txOperations');
        $entry = is_array($ops) ? ($ops[$operation] ?? null) : null;
        if (!is_array($entry) || !isset($entry['direction'])) {
            return;
        }
        if ((int) $entry['direction'] !== $direction) {
            $r->addError(
                'operation',
                'Pohyb neodpovídá směru transakce (příjmový pohyb na výdaji nebo naopak).',
                'operation_direction_mismatch',
            );
        }
    }

    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        foreach (['counterparty_account', 'counterparty_name', 'payment_reference', 'specific_symbol', 'constant_symbol', 'message', 'external_id'] as $col) {
            if (isset($data[$col]) && $data[$col] !== null) {
                $data[$col] = trim((string) $data[$col]);
            }
        }
        if (isset($data['currency'])) {
            $data['currency'] = strtolower(trim((string) $data['currency']));
        }

        // Domácí měna má vždy kurz 1 (#121 D7).
        $currency = (string) ($data['currency'] ?? '');
        if ($currency !== '' && $currency === $this->homeCurrency()) {
            $data['exchange_rate'] = 1;
        }

        // amount_dom = amount × exchange_rate. Dopočítá se, když chybí
        // (ingestion / UI ho může dodat přímo) NEBO když se kurz proti
        // uloženému řádku změnil (ruční oprava kurzu ve formuláři, #121 D7).
        // Jinak zůstává — migrované řádky nesou částku ze starého Shipardu
        // a přepočet by ji mohl posunout o haléř.
        $hasRate = isset($data['exchange_rate']) && $data['exchange_rate'] !== '' && $data['exchange_rate'] !== null;
        $rate = $hasRate ? (float) $data['exchange_rate'] : 1.0;
        $hasDom = isset($data['amount_dom']) && $data['amount_dom'] !== '' && $data['amount_dom'] !== null;
        $rateChanged = $hasRate && $originalData !== null
            && round((float) ($originalData['exchange_rate'] ?? 1.0), 6) !== round($rate, 6);
        if ((!$hasDom || $rateChanged) && isset($data['amount']) && $data['amount'] !== '') {
            $data['amount_dom'] = round((float) $data['amount'] * $rate, 2);
        }

        $this->trackStateChange($data, $originalData);
    }

    /** Domácí měna zdroje dat z nastavení (injektuje TableGateway); nerozhodnuto = czk. */
    private function homeCurrency(): string
    {
        $value = $this->settings?->get('economy.homeCurrency');
        return is_string($value) && $value !== '' ? strtolower($value) : 'czk';
    }
}

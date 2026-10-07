<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

use Shipard\Core\Document\ValidationResult;

/**
 * Společná báze Document tříd přijatých faktur — Faktura přijatá (FPB,
 * `invni`, docs.invoicesIn) a Zálohová faktura přijatá (FPZ, `invpi`,
 * docs.proformasIn, #106 D1).
 *
 * Jediné sdílené per-typ pravidlo: u přijatého dokladu nevyžadujeme náš
 * `bank_account` (účet dává dodavatel), ale při Potvrzení **doporučujeme**
 * bankovní spojení dodavatele — warning `partner_bank_recommended`, uložení
 * projde. Výzva k platbě (FPZ) je hlavně podklad k platbě, proto pro ni
 * platí stejně jako pro FPB. Tvrdý požadavek patří budoucímu platebnímu
 * toku (příkaz k úhradě z dokladu) — až ten modul bude, převezme ho odsud.
 *
 * Nedaňový charakter FPZ (DUZP/DPPD/období DPH null) řídí `DocDocument`
 * a economy.vat podle `docTypes[].tax_document`, ne tahle třída.
 */
abstract class ReceivedInvoiceDocumentBase extends DocsHeadsDocument
{
    public function validate(array &$data): ValidationResult
    {
        $result = parent::validate($data);

        $newState = (int) ($data['docState'] ?? 10);
        $paymentMethod = (int) ($data['payment_method'] ?? 1);

        // Potvrzeno a dál: aspoň jedno z partner_bank, partner_bank_account,
        // partner_bank_iban — musíme vědět, kam platit. Jen při platbě
        // převodem (payment_method === 1); hotovost / karta / dobírka /
        // zápočet bankovní spojení nepotřebují. Historické (uhrazené)
        // doklady ho legitimně nemají, proto nesmí blokovat uložení.
        if (in_array($newState, [40, 80], true) && $paymentMethod === 1) {
            $hasBank = !empty($data['partner_bank'])
                || !empty($data['partner_bank_account'])
                || !empty($data['partner_bank_iban']);
            if (!$hasBank) {
                // Vázáno na sloupec `partner_bank` (lookup na hlavičce) —
                // kontrola pokrývá partner_bank / partner_bank_account /
                // partner_bank_iban, ale lookup je primární vstup
                // „vyberte jeho účet“.
                $result->addWarning(
                    'partner_bank',
                    'Bankovní spojení dodavatele je povinné — vyberte jeho účet '
                    . 'nebo vyplňte ručně číslo účtu / IBAN.',
                    'partner_bank_recommended',
                );
            }
        }

        return $result;
    }
}

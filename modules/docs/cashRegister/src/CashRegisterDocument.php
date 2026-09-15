<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\CashRegister;

use Shipard\Core\Document\ValidationResult;
use Shipard\Module\Docs\Core\CashDeskDocumentBase;

/**
 * Prodejka (PRO) — `doc_type = 'cashreg'`.
 *
 * Logika žije v CashDeskDocumentBase (docs.core). Typ má pevný směr výstup
 * (`docTypes.cashreg.trade_dir = 1`), takže `cash_dir` musí zůstat 0 — hlídá
 * DocDocument::validateBindingAndDirection. Vratka = záporné řádky, žádná
 * validace je neodmítá (stejně jako u dobropisů).
 *
 * Nad bázi přidává způsob úhrady **Převodem** (1): prodejka „na převod" je
 * pohledávka — účtuje se na 311 místo pokladny (předpis `cashreg`,
 * `query: {payment_method: 1}`), a proto vyžaduje partnera hlavičky
 * (anonymní pohledávka nedává smysl). Rozhodnutí #59, reimport 2026-09-08:
 * starý Shipard takové prodejky účtoval na 311, msi jich má tři.
 *
 * Dobírka (3) a platební brána (5, #72 D4) jsou také pohledávky — za
 * dopravcem resp. bránou (`partner_balance`, viz CashDeskDocumentBase).
 */
class CashRegisterDocument extends CashDeskDocumentBase
{
    /** 0 Hotovost, 1 Převodem, 2 Kartou, 3 Dobírkou, 5 Platební bránou. */
    public const PAYMENT_METHODS_ALLOWED = [0, 1, 2, 3, 5];

    protected function paymentMethodsAllowedLabel(): string
    {
        return 'hotově, převodem, kartou, dobírkou nebo platební bránou';
    }

    public function validate(array &$data): ValidationResult
    {
        $result = parent::validate($data);

        if ((int) ($data['payment_method'] ?? 0) === 1 && empty($data['partner'])) {
            $result->addError(
                'partner',
                'Prodejka hrazená převodem je pohledávka — zadejte odběratele',
                'partner_required_for_transfer',
            );
        }

        return $result;
    }
}

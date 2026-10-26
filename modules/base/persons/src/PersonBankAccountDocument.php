<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Persons;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;
use Shipard\Module\Base\Persons\Values\PersonContext;
use Shipard\Module\Base\Persons\Values\PersonValues;

/**
 * Bankovní účet osoby (`base_persons_bank_accounts`, #118 D18, D20): při
 * uložení z formuláře číslo účtu, IBAN a BIC v kanonickém tvaru a IBAN
 * zapsaný do čísla účtu přesunutý do sloupce `iban` (u cz / sk s
 * dopočteným tuzemským číslem). Podřízené záznamy applieru a `bankCreate`
 * z dokladu normalizuje táž funkce ({@see PersonValues::normalizeBankAccount}).
 * Vlastní účty v číselníku řeší `economy.codebooks` `BankAccountDocument`.
 * Číslo účtu, IBAN, BIC a jejich soulad kontroluje (D19): u účtu vlastní
 * firmy chyba, u partnera varování; tuzemský tvar se čeká podle země osoby.
 */
class PersonBankAccountDocument extends Document
{
    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();
        $context = $this->personContext();
        $person = (int) ($data['person'] ?? 0);
        $country = $person > 0 ? $context->country($person) : $context->dsCountry();
        PersonDocument::report($result, PersonValues::checkBankAccount($data, $country), $context->isOwn($person));
        return $result;
    }

    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        $data = PersonValues::normalizeBankAccount($data);
    }

    protected function personContext(): PersonContext
    {
        return new PersonContext($this->db, $this->dsConfig?->getCountry() ?? 'cz');
    }
}

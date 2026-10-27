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
 * firmy chyba, u partnera varování; číslo bez mod 11 je podezření
 * (`account_number_checksum`, D30) — varování i u vlastní firmy; tuzemský
 * tvar se čeká podle země osoby.
 * Účet musí nést číslo účtu nebo IBAN (D28) — zahraniční účet má jen IBAN,
 * bez obou údajů se neuloží ani partnerovi.
 */
class PersonBankAccountDocument extends Document
{
    public const REQUIRED_ONE_OF_MESSAGE = 'Musí být vyplněn alespoň jeden z údajů: Číslo účtu nebo IBAN.';

    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();
        $context = $this->personContext();
        $person = (int) ($data['person'] ?? 0);
        $country = $person > 0 ? $context->country($person) : $context->dsCountry();

        // Nad tvarem, v jakém se účet uloží (přesun IBAN ze sloupce čísla
        // účtu, D20). Částečný update bez obou sloupců se nehodnotí — mění
        // jiné sloupce a uložený účet nevidí.
        if (self::carriesAccount($data)) {
            $shaped = PersonValues::normalizeBankAccount($data);
            if (PersonValues::text($shaped['account_number'] ?? null) === null && PersonValues::text($shaped['iban'] ?? null) === null) {
                $result->addError('account_number', self::REQUIRED_ONE_OF_MESSAGE, 'required_one_of');
            }
        }
        PersonDocument::report($result, PersonValues::checkBankAccount($data, $country), $context->isOwn($person));
        return $result;
    }

    /** Nový záznam vždy; update jen když payload nese číslo účtu i IBAN (formulář). */
    private static function carriesAccount(array $data): bool
    {
        return empty($data['id'])
            || (array_key_exists('account_number', $data) && array_key_exists('iban', $data));
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

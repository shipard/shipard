<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\ContactPayment;

use Shipard\Module\Economy\SalesRegistration\Evaluation\DocFactsLoader;
use Shipard\Module\Economy\SalesRegistration\Evaluation\RegistrationFacts;

/**
 * Kde se Kontaktní platba nabízí (#171 D8b, U14) — jediné místo, které čtou
 * kontroly při uložení, záložka formuláře, akce záznamu i detail dokladu.
 *
 * Odpověď dávají **data typu evidence** (`contactPayment`) registrací ve
 * stavu V pořádku: faktura vydaná Převodem, zálohová faktura vydaná Převodem
 * nebo Kartou — nikde natvrdo v kódu. Bez registrace V pořádku se příznak
 * nenabízí vůbec. Zemi dokladu a platnost registrace k času platby tu
 * neposuzuje — to je věc vyhodnocení ({@see \Shipard\Module\Economy\SalesRegistration\Evaluation\DocSaleEvaluator}).
 */
final class ContactPaymentPolicy
{
    /** @param list<RegistrationFacts> $registrations registrace ve stavu V pořádku */
    public function __construct(private readonly array $registrations)
    {
    }

    public static function fromLoader(DocFactsLoader $loader): self
    {
        return new self($loader->registrations());
    }

    public function hasRegistration(): bool
    {
        return $this->registrations !== [];
    }

    /**
     * Typy dokladů, které příznak nést smějí (při některém způsobu úhrady).
     *
     * @return list<string>
     */
    public function docTypes(): array
    {
        $docTypes = [];
        foreach ($this->registrations as $registration) {
            foreach ($registration->type->contactPaymentDocTypes() as $docType) {
                $docTypes[$docType] = true;
            }
        }
        return array_keys($docTypes);
    }

    /** Má formulář dokladu tohoto typu záložku s příznakem? */
    public function offersTab(string $docType): bool
    {
        return in_array($docType, $this->docTypes(), true);
    }

    /** Smí doklad s tímto typem a způsobem úhrady příznak nést? */
    public function allows(string $docType, int $paymentMethod): bool
    {
        return $this->registrationFor($docType, $paymentMethod) !== null;
    }

    /**
     * Zóna místa tržby pro čas platby dokladu (U23): typ registrace, která
     * dokladu příznak dovoluje, jinak první registrace, jinak zóna serveru.
     */
    public function saleTimeZone(string $docType = '', int $paymentMethod = -1): \DateTimeZone
    {
        $registration = $this->registrationFor($docType, $paymentMethod) ?? ($this->registrations[0] ?? null);
        return $registration?->type->saleTimeZone() ?? new \DateTimeZone(date_default_timezone_get());
    }

    private function registrationFor(string $docType, int $paymentMethod): ?RegistrationFacts
    {
        foreach ($this->registrations as $registration) {
            if ($registration->type->allowsContactPayment($docType, $paymentMethod)) {
                return $registration;
            }
        }
        return null;
    }
}

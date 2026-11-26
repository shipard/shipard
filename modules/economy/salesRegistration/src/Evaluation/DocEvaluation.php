<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Evaluation;

/**
 * Výsledek vyhodnocení dokladu (#171 fáze 2), tři stavy:
 *
 *  - **nespadá** — do evidence nejde nic (částka, která do evidence spadá, je 0);
 *  - **spadá** — částka, registrace, jednotka a označení pokladny;
 *  - **spadá, ale nejde založit** (U9) — chybí nastavení; tržba nevznikne,
 *    upozornění, po opravě ji dorovná záchranná síť.
 *
 * `reason` říká proč (kód pro log, upozornění a testy).
 */
final readonly class DocEvaluation
{
    // Nespadá
    public const string REASON_IMPORTED = 'imported';
    public const string REASON_STATE = 'state';
    public const string REASON_CANCELLED = 'cancelled';
    public const string REASON_NO_REGISTRATION = 'noRegistration';
    public const string REASON_NO_RULE = 'noRule';
    public const string REASON_BEFORE_REGISTRATION = 'beforeRegistration';
    public const string REASON_NO_MATCHING_ROWS = 'noMatchingRows';
    public const string REASON_ZERO_AMOUNT = 'zeroAmount';

    // Spadá, ale nejde založit
    public const string REASON_MULTIPLE_REGISTRATIONS = 'multipleRegistrations';
    public const string REASON_CURRENCY = 'currency';
    public const string REASON_NO_UNIT = 'noUnit';
    public const string REASON_FOREIGN_UNIT = 'foreignUnit';
    public const string REASON_UNIT_ID = 'unitId';
    public const string REASON_CASH_REGISTER_ID = 'cashRegisterId';
    public const string REASON_SERIAL_NUMBER = 'serialNumber';
    public const string REASON_AMOUNT = 'amount';

    private function __construct(
        public bool $applicable,
        public bool $unregistrable,
        public string $reason,
        public int $cents = 0,
        public ?RegistrationFacts $registration = null,
        public ?int $unit = null,
        public ?string $unitId = null,
        public ?string $cashRegisterId = null,
    ) {
    }

    public static function notApplicable(string $reason): self
    {
        return new self(false, false, $reason);
    }

    public static function unregistrable(string $reason, ?RegistrationFacts $registration = null): self
    {
        return new self(false, true, $reason, registration: $registration);
    }

    public static function applicable(
        int $cents,
        RegistrationFacts $registration,
        int $unit,
        string $unitId,
        string $cashRegisterId,
    ): self {
        return new self(true, false, '', $cents, $registration, $unit, $unitId, $cashRegisterId);
    }
}

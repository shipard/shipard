<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Queue;

/**
 * Stavy tržby ve frontě (#171 U5) — hodnoty sloupce `state`, popisky
 * v `economy.salesRegistration.saleStates`.
 */
final class SaleState
{
    public const string PENDING = 'pending';
    public const string SENDING = 'sending';
    public const string CONFIRMED = 'confirmed';
    public const string VERIFIED = 'verified';
    public const string DROPPED = 'dropped';
    public const string FAILED = 'failed';

    /** Stavy, ve kterých povinnost ještě není vyřízená (upozornění). */
    public const array OPEN = [self::PENDING, self::SENDING, self::FAILED];

    /** Stavy, ze kterých jde tržbu poslat znovu ručně. */
    public const array RESENDABLE = [self::FAILED, self::PENDING];

    /**
     * Pořadí stavů od nejhoršího (#171 U19) — stav evidence dokladu je
     * nejhorší stav jeho tržeb: odmítnutá > čeká na odeslání > zahozená
     * pojistkou > potvrzená nebo ověřená.
     */
    private const array SEVERITY = [
        self::FAILED    => 4,
        self::PENDING   => 3,
        self::SENDING   => 3,
        self::DROPPED   => 2,
        self::CONFIRMED => 1,
        self::VERIFIED  => 1,
    ];

    /**
     * Nejhorší stav ze stavů tržeb dokladu; null pro prázdný seznam. Při
     * shodě závažnosti vyhrává dřívější (tržby jdou v pořadí vzniku).
     *
     * @param list<string> $states
     */
    public static function worst(array $states): ?string
    {
        $worst = null;
        foreach ($states as $state) {
            if ($worst === null || (self::SEVERITY[$state] ?? 0) > (self::SEVERITY[$worst] ?? 0)) {
                $worst = $state;
            }
        }
        return $worst;
    }
}

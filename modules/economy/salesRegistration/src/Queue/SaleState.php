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
}

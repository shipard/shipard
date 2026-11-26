<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Engine;

/** Stav odeslání zprávy evidence tržeb. */
enum SendStatus: string
{
    /** Zpráva přijata, správce daně vydal potvrzení. */
    case Confirmed = 'confirmed';
    /** Ověřovací mód: zprávu šlo zpracovat, nic se neeviduje. */
    case Verified = 'verified';
    /** Zpráva odmítnuta (kritická chyba), nebo ji nešlo ani sestavit. */
    case Rejected = 'rejected';
    /** Dočasná chyba služby — poslat znovu později. */
    case RetryLater = 'retryLater';
    /** Stav není známý: žádná odpověď, nebo odpověď, které nelze věřit. */
    case Unknown = 'unknown';
    /** Pojistka serveru: nic se neodeslalo. */
    case Dropped = 'dropped';

    /** Je evidenční povinnost touto zprávou splněná? */
    public function isRegistered(): bool
    {
        return $this === self::Confirmed;
    }
}

<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Engine;

/**
 * Engine typu evidence tržeb (#171 D2, D12, D17) — třída, která umí protokol
 * jedné země. K typu ji přiřazuje cfgItem `economy.salesRegistration.engines`;
 * instance vzniká bez argumentů konstruktoru.
 *
 * Kontrakt:
 *
 *  - engine pošle **jednu zprávu** do daného prostředí pod danou identitou
 *    a vrátí {@see SendResult}. Nikdy nevyhazuje — každé selhání je stav
 *    výsledku (`rejected`, `retryLater`, `unknown`);
 *  - nerozhoduje, kam zpráva jde, ani pod jakou identitou (to je věc
 *    odesílače a pojistky serveru), nic neukládá a neopakuje;
 *  - nepředpokládá online potvrzení každé tržby: `confirmationCode` je
 *    obecný kód potvrzení, pokud ho protokol vydává;
 *  - klíč čte ze souborů identity jen po dobu volání a nikam ho nezapisuje
 *    ani neloguje.
 */
interface SalesRegistrationEngine
{
    public function send(SalesMessage $message, SenderIdentity $identity, EngineEnvironment $environment): SendResult;
}

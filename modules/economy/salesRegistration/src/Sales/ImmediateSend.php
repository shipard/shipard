<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Sales;

use Shipard\Core\Logging\ErrorLogger;
use Shipard\Module\Economy\SalesRegistration\Queue\AttemptResult;
use Shipard\Module\Economy\SalesRegistration\Queue\SaleQueue;

/**
 * Okamžitý pokus o odeslání čerstvě založené tržby (#171 D7, U6) — stejnou
 * cestou jako cron. Sdílí ho handler přechodu dokladu i akce Kontaktní
 * platba a Zrušit kontaktní platbu, aby se jistič uplatnil všude stejně.
 *
 * **Jistič** (U6): skončil-li poslední pokus registrace v posledních 60 s
 * výpadkem, tržba zůstane frontě — každé uložení dokladu (a každá akce) by
 * jinak čekalo na timeout služby.
 */
final class ImmediateSend
{
    public function __construct(private readonly SaleQueue $queue)
    {
    }

    /** Výsledek pokusu; null = tržba zůstala frontě (jistič, nebo ji právě posílá jiný proces). */
    public function attempt(SyncResult $result): ?AttemptResult
    {
        if ($result->saleId === null) {
            return null;
        }
        if ($result->registration !== null && $this->queue->circuitOpen($result->registration)) {
            ErrorLogger::info('sales registration: sale left to the queue (service outage)', [
                'sale'         => $result->saleId,
                'registration' => $result->registration,
            ]);
            return null;
        }
        return $this->queue->attempt($result->saleId);
    }
}

<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\SalesRegistration;

use Shipard\Module\Economy\SalesRegistration\Queue\SaleQueue;
use Shipard\Module\Economy\SalesRegistration\Sales\DocsHeadsSalesHandler;

/**
 * Handler dokladů bez okamžitého odeslání — integrační test tak nic
 * neposílá ven, ať je pojistka serveru nastavená jakkoli (na vývojovém
 * stroji bývá `playground`). Tržba zůstane `pending`.
 */
final class NoSendSalesHandler extends DocsHeadsSalesHandler
{
    protected function queue(): ?SaleQueue
    {
        return null;
    }
}

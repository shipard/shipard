<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\ExchangeRates\Download;

/**
 * HTTP vrstva klienta služby kurzovních lístků (#121 task B, U7): produkce
 * = {@see CurlExchangeRateServiceTransport}, testy podstrčí uložené odpovědi.
 * Transport nikdy nehází — chybu sítě vrací v `error`.
 */
interface ExchangeRateServiceTransport
{
    /** @return array{statusCode: int, body: string, error: ?string} */
    public function get(string $url): array;
}

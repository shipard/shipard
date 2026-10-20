<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\ExchangeRates\Download;

/**
 * cURL transport služby kurzovních lístků (#121 task B §3): timeout 15 s,
 * připojení 5 s, bez přesměrování (adresa je jen ze server.json, nikdy
 * z odpovědi), jen http(s).
 */
final class CurlExchangeRateServiceTransport implements ExchangeRateServiceTransport
{
    private const int TIMEOUT_SECONDS = 15;
    private const int CONNECT_TIMEOUT_SECONDS = 5;
    private const string USER_AGENT = 'Shipard/exchange-rates';

    public function get(string $url): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = $errno !== 0 ? curl_error($ch) : null;
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'statusCode' => $statusCode,
            'body'       => $body === false ? '' : (string) $body,
            'error'      => $error,
        ];
    }
}

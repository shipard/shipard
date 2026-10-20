<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\ExchangeRates\Download;

/**
 * Klient služby kurzovních lístků (#121 task B §3, D2):
 *
 *   GET <serviceUrl>?dateFrom=YYYY-MM-DD&dateTo=YYYY-MM-DD&listType=<serviceListType>
 *
 * Vždy rozsah (i pro jeden den from = to). Odpověď je HTTP 200 + JSON
 * `{"valid": 1, "data": [lístky]}`, bez lístku `{"valid": 0}`, chybné
 * parametry `{"valid": 0, "error": "…"}`. Do zpráv jde jen stručný důvod,
 * nikdy tělo odpovědi. Adresa jen ze server.json
 * (`ServerConfig::getExchangeRatesServiceUrl`).
 */
final class ExchangeRateServiceClient
{
    public function __construct(
        private readonly string $serviceUrl,
        private readonly ExchangeRateServiceTransport $transport,
    ) {
        if (!preg_match('#^https?://#', $serviceUrl)) {
            throw new \InvalidArgumentException("ExchangeRateServiceClient: serviceUrl must be an http(s) URL, got '{$serviceUrl}'");
        }
    }

    public function url(int $serviceListType, string $dateFrom, string $dateTo): string
    {
        return $this->serviceUrl . (str_contains($this->serviceUrl, '?') ? '&' : '?') . http_build_query([
            'dateFrom' => $dateFrom,
            'dateTo'   => $dateTo,
            'listType' => $serviceListType,
        ]);
    }

    public function fetch(int $serviceListType, string $dateFrom, string $dateTo): ExchangeRateServiceResponse
    {
        try {
            $response = $this->transport->get($this->url($serviceListType, $dateFrom, $dateTo));
        } catch (\Throwable $e) {
            return ExchangeRateServiceResponse::error('síť: ' . $e->getMessage());
        }
        $error = $response['error'] ?? null;
        if (is_string($error) && $error !== '') {
            return ExchangeRateServiceResponse::error('síť: ' . $error);
        }
        $status = (int) ($response['statusCode'] ?? 0);
        if ($status !== 200) {
            return ExchangeRateServiceResponse::error("HTTP {$status}");
        }
        $data = json_decode((string) ($response['body'] ?? ''), true);
        if (!is_array($data)) {
            return ExchangeRateServiceResponse::error('odpověď není JSON');
        }
        if (!array_key_exists('valid', $data)) {
            return ExchangeRateServiceResponse::error('odpověď bez klíče valid');
        }
        if ((int) $data['valid'] !== 1) {
            $message = $data['error'] ?? null;
            if (is_string($message) && $message !== '') {
                return ExchangeRateServiceResponse::error('služba: ' . $message);
            }
            return ExchangeRateServiceResponse::none();
        }
        if (!isset($data['data']) || !is_array($data['data'])) {
            return ExchangeRateServiceResponse::error('odpověď bez pole data');
        }
        return ExchangeRateServiceResponse::lists(array_values(array_filter($data['data'], 'is_array')));
    }
}

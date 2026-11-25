<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\ExchangeRates;

use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Config\ServerConfigSection;

/**
 * Sekce `exchangeRates` v `/etc/shipard/server.json` (#121 task B, U3;
 * sekce modulu #172 D10, docs/exchange-rates.md §9) — adresa služby
 * kurzovních lístků `exchangeRates.serviceUrl`:
 *
 *  - klíč chybí → veřejná služba {@see self::DEFAULT_SERVICE_URL};
 *  - prázdný řetězec → `null` = stahování vypnuté (úloha
 *    `exchange-rates-download` skončí hned);
 *  - jiná hodnota musí být http(s) adresa, koncové lomítko se odřízne.
 *
 * Čte ji `ExchangeRatesDownloadCommand`.
 */
final readonly class ExchangeRatesServerConfig implements ServerConfigSection
{
    /** Veřejná služba kurzovních lístků. */
    public const DEFAULT_SERVICE_URL = 'https://data.shipard.app/feed/money-exchange-rates';

    private function __construct(private ?string $serviceUrl)
    {
    }

    public static function key(): string
    {
        return 'exchangeRates';
    }

    public static function fromServerConfig(ServerConfig $config): static
    {
        $section = $config->getSection(self::key());
        if (!is_array($section) || !array_key_exists('serviceUrl', $section)) {
            return new self(self::DEFAULT_SERVICE_URL);
        }
        $url = $section['serviceUrl'];
        if ($url === '') {
            return new self(null);
        }
        if (!is_string($url)) {
            throw new \RuntimeException(
                "Server config 'exchangeRates.serviceUrl' must be a string (empty string disables downloading)",
            );
        }
        if (!preg_match('#^https?://#', $url)) {
            throw new \RuntimeException(
                "Server config 'exchangeRates.serviceUrl' must start with http:// or https://, got '{$url}'",
            );
        }
        return new self(rtrim($url, '/'));
    }

    /** Adresa služby bez koncového lomítka; `null` = stahování vypnuté. */
    public function serviceUrl(): ?string
    {
        return $this->serviceUrl;
    }
}

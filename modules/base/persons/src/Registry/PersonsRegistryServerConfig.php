<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Persons\Registry;

use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Config\ServerConfigSection;

/**
 * Sekce `registry.persons` v `/etc/shipard/server.json` (sekce modulu
 * #172 D10) — adresa registru osob `registry.persons.baseUrl` (společná
 * služba ARES + RPO + registr plátců DPH):
 *
 *  - klíč chybí → veřejná služba {@see PersonsRegistryClient::DEFAULT_BASE_URL};
 *  - prázdná hodnota, jiný typ nebo jiné schéma než http(s) → výjimka;
 *  - koncové lomítko se odřízne, volající skládá cesty jednotně:
 *    `{baseUrl}/{country}/{companyId}/json?formatMode=ns`.
 *
 * Čte ji {@see PersonsRegistryClient::fromServerConfig()} — průvodce „Přidat
 * firmu z registru“ a import stran ve výměnném formátu.
 */
final readonly class PersonsRegistryServerConfig implements ServerConfigSection
{
    private function __construct(private string $baseUrl)
    {
    }

    public static function key(): string
    {
        return 'registry.persons';
    }

    public static function fromServerConfig(ServerConfig $config): static
    {
        $section = $config->getSection(self::key());
        $url = is_array($section)
            ? ($section['baseUrl'] ?? PersonsRegistryClient::DEFAULT_BASE_URL)
            : PersonsRegistryClient::DEFAULT_BASE_URL;
        if (!is_string($url) || $url === '') {
            throw new \RuntimeException(
                "Server config 'registry.persons.baseUrl' must be a non-empty string",
            );
        }
        if (!preg_match('#^https?://#', $url)) {
            throw new \RuntimeException(
                "Server config 'registry.persons.baseUrl' must start with http:// or https://, got '{$url}'",
            );
        }
        return new self(rtrim($url, '/'));
    }

    /** Adresa registru bez koncového lomítka. */
    public function baseUrl(): string
    {
        return $this->baseUrl;
    }
}

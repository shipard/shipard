<?php

declare(strict_types=1);

namespace Shipard\Core\Config;

use Shipard\Core\Mail\MailRelayConfig;
use Shipard\Core\Mail\MailSafetyConfig;
use Shipard\Core\Server\HostingConfig;

class ServerConfig
{
    private array $data = [];

    public function __construct(private readonly string $configPath = '/etc/shipard/server.json')
    {
    }

    public function load(): void
    {
        if (!file_exists($this->configPath)) {
            throw new \RuntimeException("Config file not found: {$this->configPath}");
        }

        $content = file_get_contents($this->configPath);
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Invalid JSON in config file: " . json_last_error_msg());
        }

        $required = ['host', 'port', 'admin_user', 'admin_password', 'mode'];
        foreach ($required as $field) {
            if (!isset($data[$field])) {
                throw new \RuntimeException("Missing required config field: {$field}");
            }
        }

        if (isset($data['extraModulesPath'])) {
            if (!is_array($data['extraModulesPath']) || !array_is_list($data['extraModulesPath'])) {
                throw new \RuntimeException(
                    "Server config field 'extraModulesPath' must be a JSON array of strings"
                );
            }
            foreach ($data['extraModulesPath'] as $i => $entry) {
                if (!is_string($entry) || $entry === '') {
                    throw new \RuntimeException(
                        "Server config 'extraModulesPath[$i]' must be a non-empty string"
                    );
                }
            }
        }

        $this->data = $data;
    }

    public function getHost(): string
    {
        return $this->data['host'];
    }

    public function getPort(): int
    {
        return (int) $this->data['port'];
    }

    public function getAdminUser(): string
    {
        return $this->data['admin_user'];
    }

    public function getAdminPassword(): string
    {
        return $this->data['admin_password'];
    }

    public function getMode(): string
    {
        return $this->data['mode'];
    }

    public function getDomainsFile(): string
    {
        return $this->data['domainsFile'] ?? '/etc/shipard/domains.json';
    }

    public function getDataSourcesDir(): string
    {
        return $this->data['dataSources'] ?? '/opt/shipard/data-sources';
    }

    public function getLogFile(): string
    {
        return $this->data['logFile'] ?? '/opt/shipard/log/shipard.log';
    }

    public function getLogLevel(): string
    {
        return $this->data['logLevel'] ?? 'debug';
    }

    /**
     * Additional module root paths beyond the in-repo `modules/` directory.
     * Used to load third-party / customer modules from outside the main
     * repository.
     *
     * @return list<string>  Absolute paths, in the order given in server.json.
     */
    public function getExtraModulesPath(): array
    {
        return $this->data['extraModulesPath'] ?? [];
    }

    /**
     * Base URL of the Shipard persons registry (combined ARES + RPO +
     * VAT-registry HTTP service). Read from nested config key
     * `registry.persons.baseUrl`; defaults to the public service.
     *
     * Trailing slash is stripped so callers can append paths uniformly:
     *   `{baseUrl}/{country}/{companyId}/json?formatMode=ns`
     *
     * Used by `PersonsRegistryClient` (modul base.persons) for the
     * "Přidat firmu z registru" wizard and the AI Analyzer person
     * importer.
     */
    public function getRegistryPersonsBaseUrl(): string
    {
        $url = $this->data['registry']['persons']['baseUrl']
            ?? 'https://data.shipard.org/persons';
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
        return rtrim($url, '/');
    }

    /**
     * Server-wide default SMTP relay pro odchozí poštu — nested klíč
     * `mail.relay`. Null = relay není nakonfigurován (per-DS override
     * v main.json může platit i tak; merge dělá MailServiceFactory).
     */
    public function getMailRelay(): ?MailRelayConfig
    {
        $relay = $this->data['mail']['relay'] ?? null;
        if ($relay === null) {
            return null;
        }
        if (!is_array($relay)) {
            throw new \RuntimeException("Server config 'mail.relay' must be an object");
        }
        return MailRelayConfig::fromArray($relay);
    }

    /**
     * Pojistka odchozí pošty (#95) — nested klíč `mail.safety`. Nikdy
     * nevyhazuje: chybějící sekce se řídí režimem serveru (`production` =
     * vypnuto, jinak nic neodejde), chybná znamená `drop`.
     */
    public function getMailSafety(): MailSafetyConfig
    {
        return MailSafetyConfig::fromServerData($this->data);
    }

    /**
     * PDF rendering služba (Gotenberg) — volitelný klíč `render`. Null =
     * služba není nakonfigurována; RenderClient pak degraduje na
     * errorKind=unconfigured, nic nepadá. Viz docs/render.md (#34).
     */
    public function getRender(): ?RenderConfig
    {
        $render = $this->data['render'] ?? null;
        if ($render === null) {
            return null;
        }
        if (!is_array($render)) {
            throw new \RuntimeException("Server config 'render' must be an object");
        }
        return RenderConfig::fromArray($render);
    }

    /**
     * Napojení na hosting (D3) — volitelná sekce `hosting`. Null = server
     * není spravovaný hostingem; validace až při použití, chybějící sekce
     * nesmí rozbít ostatní commandy.
     */
    public function getHosting(): ?HostingConfig
    {
        $hosting = $this->data['hosting'] ?? null;
        if ($hosting === null) {
            return null;
        }
        if (!is_array($hosting)) {
            throw new \RuntimeException("Server config 'hosting' must be an object");
        }
        return HostingConfig::fromArray($hosting);
    }
}

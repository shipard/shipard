<?php

declare(strict_types=1);

namespace Shipard\Core\Config;

use Shipard\Core\Ai\DevAiConfig;
use Shipard\Core\Mail\MailRelayConfig;
use Shipard\Core\Mail\MailSafetyConfig;
use Shipard\Core\Server\HostingConfig;

class ServerConfig
{
    public const DEFAULT_AI_ANALYSIS_MAX_CONCURRENT = 2;

    /** Veřejná služba kurzovních lístků (#121 task B, U3). */
    public const DEFAULT_EXCHANGE_RATES_SERVICE_URL = 'https://data.shipard.app/feed/money-exchange-rates';

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
     * "Přidat firmu z registru" wizard and the exchange party import.
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
     * Adresa služby kurzovních lístků — `exchangeRates.serviceUrl`
     * (#121 task B, U3, docs/exchange-rates.md §9): klíč chybí → veřejná
     * služba; prázdný řetězec → null = stahování vypnuté (úloha
     * `exchange-rates-download` skončí hned); jiná hodnota musí být http(s).
     * Čte ji `ExchangeRatesDownloadCommand` (modul economy.exchangeRates).
     */
    public function getExchangeRatesServiceUrl(): ?string
    {
        $section = $this->data['exchangeRates'] ?? null;
        if (!is_array($section) || !array_key_exists('serviceUrl', $section)) {
            return self::DEFAULT_EXCHANGE_RATES_SERVICE_URL;
        }
        $url = $section['serviceUrl'];
        if ($url === '') {
            return null;
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

    /** Adresář konfigurace serveru (`/etc/shipard`) — vedle `server.json` leží `secrets/`. */
    public function getConfigDir(): string
    {
        return dirname($this->configPath);
    }

    /**
     * Serverové AI připojení pro vývoj (tasks/testing-phase4.md D40) —
     * volitelná sekce `devAi` {platform, baseUrl}; chybí = platforma
     * `anthropic` s výchozí adresou. Platí jen v režimu `development` —
     * to hlídá {@see \Shipard\Core\Ai\ServerAiConnection::detect()}, ne config.
     */
    public function getDevAi(): DevAiConfig
    {
        $devAi = $this->data['devAi'] ?? null;
        if ($devAi !== null && !is_array($devAi)) {
            throw new \RuntimeException("Server config 'devAi' must be an object");
        }
        return DevAiConfig::fromArray($devAi);
    }

    /**
     * Limit souběhu AI analýz pošty per server — nested klíč
     * `ai.analysis.maxConcurrent` (tasks/mail-analysis-inprocess.md D15).
     * Chybějící sekce = výchozí {@see DEFAULT_AI_ANALYSIS_MAX_CONCURRENT};
     * `0` = analýza pošty na tomto serveru vypnutá (vypínač, žádný
     * runner se nespustí).
     * Záporná nebo nečíselná hodnota je chyba konfigurace.
     */
    public function getAiAnalysisMaxConcurrent(): int
    {
        $ai = $this->data['ai'] ?? null;
        $analysis = is_array($ai) ? ($ai['analysis'] ?? null) : null;
        $value = is_array($analysis) ? ($analysis['maxConcurrent'] ?? null) : null;
        if ($value === null) {
            return self::DEFAULT_AI_ANALYSIS_MAX_CONCURRENT;
        }
        if (!is_int($value) || $value < 0) {
            throw new \RuntimeException("Server config 'ai.analysis.maxConcurrent' must be a non-negative integer");
        }
        return $value;
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

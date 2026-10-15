<?php

declare(strict_types=1);

namespace Shipard\Core\Config;

use Shipard\Core\Auth\AuthPolicy;
use Shipard\Core\Mail\MailRelayConfig;

class DataSourceConfig
{
    private array $data = [];
    private ?AuthPolicy $authPolicy = null;
    private ?MailRelayConfig $mailRelay = null;
    private bool $mailRelayParsed = false;

    public function __construct(private readonly string $dataSourceDir)
    {
        $this->load();
    }

    private function load(): void
    {
        $configFile = $this->dataSourceDir . '/config/main.json';

        if (!file_exists($configFile)) {
            throw new \RuntimeException("Data source config not found: {$configFile}");
        }

        $content = file_get_contents($configFile);
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Invalid JSON in data source config: " . json_last_error_msg());
        }

        $required = ['id', 'name', 'database_name', 'database_user', 'database_password', 'created'];
        foreach ($required as $field) {
            if (!isset($data[$field])) {
                throw new \RuntimeException("Missing required data source config field: {$field}");
            }
        }

        $this->data = $data;
    }

    public function getId(): string
    {
        return $this->data['id'];
    }

    public function getName(): string
    {
        return $this->data['name'];
    }

    public function getDatabaseName(): string
    {
        return $this->data['database_name'];
    }

    public function getDatabaseUser(): string
    {
        return $this->data['database_user'];
    }

    public function getDatabasePassword(): string
    {
        return $this->data['database_password'];
    }

    public function getCreated(): string
    {
        return $this->data['created'];
    }

    public function getModules(): array
    {
        return $this->data['modules'] ?? [];
    }

    public function getDataSourceDir(): string
    {
        return $this->dataSourceDir;
    }

    /**
     * Default language for this DS — used as fallback when the request has no
     * Accept-Language header. ISO 639-1 lower-case (e.g. 'cs', 'en').
     * Optional; defaults to 'en' when missing from main.json.
     */
    public function getDefaultLanguage(): string
    {
        return $this->data['defaultLanguage'] ?? 'en';
    }

    /**
     * Country of the legal entity this DS runs on behalf of. ISO 3166-1
     * alpha-2 lower-case (e.g. 'cz', 'sk'). Steers which company registry
     * is queried, which VAT rate set applies and address formatting.
     *
     * Transitional: data sources created before ds-setup Task 01 have no
     * `country` key, so 'cz' is returned as a fallback and ds-upgrade emits
     * a warning. Once all data sources are re-imported (ds-setup.md D9) the
     * fallback goes away and a missing value becomes a config error.
     */
    public function getCountry(): string
    {
        return $this->hasCountry() ? $this->data['country'] : 'cz';
    }

    /** False for data sources created before the `country` key existed. */
    public function hasCountry(): bool
    {
        return isset($this->data['country']) && $this->data['country'] !== '';
    }

    /**
     * When true, `shpd-ds ds-upgrade` syncs the schema but SKIPS auto-provisioning
     * of reference data (units, item kinds, fiscal years, VAT periods, number series,
     * mail router, AI analyzer). Intended for data migration / import from another
     * system, where that reference data is supplied by the import itself.
     * Optional; defaults to false when missing from main.json.
     */
    public function shouldSkipProvisioning(): bool
    {
        return $this->data['skipProvisioning'] ?? false;
    }

    /**
     * Per-DS auth policy from the optional `auth` key in main.json — local login
     * on/off + OIDC providers. Missing key = today's behaviour (local only).
     * Validated lazily on first access (fail-fast with a clear message), NOT in
     * load(), so a broken auth section never blocks CLI commands (break-glass).
     */
    public function getAuthPolicy(): AuthPolicy
    {
        return $this->authPolicy ??= AuthPolicy::fromArray($this->data['auth'] ?? []);
    }

    /**
     * Per-DS SMTP relay override z volitelného klíče `mail.relay` v main.json.
     * Null = DS nemá override — volající (MailServiceFactory) padá na server
     * default ze server.json. Validace lazy při prvním použití, ne v load(),
     * aby rozbitá mail sekce neblokovala ostatní CLI příkazy.
     */
    public function getMailRelay(): ?MailRelayConfig
    {
        if (!$this->mailRelayParsed) {
            $relay = $this->data['mail']['relay'] ?? null;
            if ($relay !== null) {
                if (!is_array($relay)) {
                    throw new \RuntimeException("Data source config 'mail.relay' must be an object");
                }
                $this->mailRelay = MailRelayConfig::fromArray($relay);
            }
            $this->mailRelayParsed = true;
        }
        return $this->mailRelay;
    }

    /**
     * When true, `shpd-ds ds-reset` is allowed even on a production-mode server.
     * Marks a disposable testing/alpha data source. The production guard in
     * DsResetCommand refuses without this flag. Never set it on a data source
     * holding real data; `shpd-server doctor` warns about it on production.
     * Optional; defaults to false when missing from main.json.
     */
    public function allowsReset(): bool
    {
        return $this->data['enableReset'] ?? false;
    }

    /**
     * Serverové AI připojení pro vývoj (tasks/testing-phase4.md D40, #103 D10):
     * volitelné `ai.useServerConnection` v main.json — nastavuje `ds-create`
     * na serveru v režimu development (bez `--no-server-ai`) a `dataset-seed`
     * (manifest `provision.serverAi`). Obnovená reálná kopie příznak nemá,
     * takže bez vlastního připojení zůstane bez AI. Chybí = false.
     */
    public function usesServerAiConnection(): bool
    {
        $ai = $this->data['ai'] ?? null;
        return is_array($ai) && ($ai['useServerConnection'] ?? false) === true;
    }
}

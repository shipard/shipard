<?php

declare(strict_types=1);

namespace Shipard\Core\Config;

use Shipard\Core\Auth\AuthPolicy;
use Shipard\Core\Mail\MailRelayConfig;

/**
 * `config/main.json` zdroje dat. Tajemství (heslo k databázi, heslo relay
 * pošty, `clientSecret` OIDC providerů) žijí v `secrets/` — co tajemství, to
 * soubor ({@see DataSourceSecrets}, #126 D1–D3) — a čtou se **líně** až
 * v `getDatabasePassword()`, `getMailRelay()` a `getAuthPolicy()`: kód, který
 * potřebuje jen konfiguraci (dispatcher cronu, `DataSourceModules`,
 * `ds-class`, `DataSourceInventory`), do `secrets/` nesáhne.
 *
 * Hodnota tajemství, která v `main.json` zůstala (obnova starší zálohy, ruční
 * zápis), se **ignoruje** (#126 B2, U1): zdroj dat běží s tajemstvím ze
 * souboru, `doctor` ji hlásí jako chybu a krok 0 `ds-upgrade` ji přesune
 * a soubor přepíše. Sloty ({@see getSecretSlots()}) ji ukazují bez hodnoty.
 */
class DataSourceConfig
{
    private array $data = [];
    private ?AuthPolicy $authPolicy = null;
    private ?MailRelayConfig $mailRelay = null;
    private bool $mailRelayParsed = false;
    private ?DataSourceSecrets $secrets = null;

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

        // database_password už povinné není — heslo žije v secrets/db.password (#126 D3).
        $required = ['id', 'name', 'database_name', 'database_user', 'created'];
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

    /**
     * Heslo k databázi — jen `secrets/db.password`; klíč `database_password`
     * v `main.json` se ignoruje (B2). Chybí-li soubor, výjimka s cestou (a
     * s poznámkou, že klíč v `main.json` ještě je) — nikdy tiché prázdné
     * heslo a nikdy hodnota.
     */
    public function getDatabasePassword(): string
    {
        $fromFile = $this->secrets()->get(DataSourceSecrets::DB_PASSWORD);
        if ($fromFile !== null) {
            return $fromFile;
        }
        $message = 'Database password missing — expected secrets/' . DataSourceSecrets::DB_PASSWORD . ' (run shpd-ds ds-upgrade)';
        if ($this->nonEmptyString($this->data['database_password'] ?? null) !== null) {
            $message .= ' — config/main.json still has database_password, run shpd-ds ds-upgrade to move it';
        }
        throw new \RuntimeException($message);
    }

    /** Tajemství v `secrets/` — líně; veřejné pro `ds-secret` a `doctor` (`has()`). */
    public function secrets(): DataSourceSecrets
    {
        return $this->secrets ??= new DataSourceSecrets($this->dataSourceDir);
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
        if ($this->authPolicy === null) {
            $auth = $this->data['auth'] ?? [];
            if (is_array($auth) && is_array($auth['providers'] ?? null)) {
                foreach ($auth['providers'] as $i => $provider) {
                    if (!is_array($provider)) {
                        continue;
                    }
                    // clientSecret jen ze secrets/oidc-<id>.client-secret — hodnota
                    // v main.json se ignoruje (B2); neplatné id nechat AuthPolicy odmítnout.
                    unset($auth['providers'][$i]['clientSecret']);
                    $id = $provider['id'] ?? null;
                    if (!is_string($id) || !preg_match('/^[a-z0-9-]+$/', $id)) {
                        continue;
                    }
                    $secret = $this->secrets()->get(DataSourceSecrets::oidcClientSecret($id));
                    if ($secret !== null) {
                        $auth['providers'][$i]['clientSecret'] = $secret;
                    }
                }
            }
            $this->authPolicy = AuthPolicy::fromArray(is_array($auth) ? $auth : []);
        }
        return $this->authPolicy;
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
                // Heslo jen ze secrets/mail-relay.password — hodnota v main.json se
                // ignoruje (B2); bez souboru zůstane relay bez hesla jako dřív.
                unset($relay['password']);
                $password = $this->secrets()->get(DataSourceSecrets::MAIL_RELAY_PASSWORD);
                if ($password !== null) {
                    $relay['password'] = $password;
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
     * Třída dat (#126 D6): `dataClass` v main.json — `free` / `realCopy`.
     * Klíč chybí, je null, má jiný typ nebo neznámou hodnotu → realCopy
     * (fail-safe); neplatná hodnota zdroj dat nezavírá, ukáže ji `ds-class`
     * a `ds-list` (U6). Nastavuje `ds-create`, `dataset-seed` a `shpd-ds
     * ds-class`; vedle ní `purpose`, `origin` a `dataClassChanged`.
     */
    public function getDataClass(): DataClass
    {
        return DataClass::fromConfig($this->data['dataClass'] ?? null);
    }

    /** Zdroj dat je volný — ukázková / seedovaná data (třída `free`). */
    public function isFree(): bool
    {
        return $this->getDataClass() === DataClass::Free;
    }

    /** Klíč `dataClass` je v main.json a má platnou hodnotu. */
    public function hasExplicitDataClass(): bool
    {
        return DataClass::isValidConfigValue($this->data['dataClass'] ?? null);
    }

    /** Surová hodnota `dataClass` — pro hlášení neplatné hodnoty; null = klíč chybí. */
    public function getDataClassRaw(): mixed
    {
        return $this->data['dataClass'] ?? null;
    }

    /** Účel zdroje dat (`purpose`, volný text); prázdný nebo ne-řetězec = null. */
    public function getPurpose(): ?string
    {
        return $this->optionalText('purpose');
    }

    /** Kdo třídu naposledy nastavil (`origin`: `ds-create`, `dataset-seed <sada>`, `ds-class`); null = neznámo. */
    public function getOrigin(): ?string
    {
        return $this->optionalText('origin');
    }

    /** Kdy se třída naposledy nastavila (`dataClassChanged`, ISO 8601 v UTC); null = neznámo. */
    public function getDataClassChanged(): ?string
    {
        return $this->optionalText('dataClassChanged');
    }

    /**
     * Která sada ve zdroji dat je (`dataset`, #103 F6b D59): `{name, hash,
     * seededAt}` zapsané `dataset-seed` po úspěšném seedu; null = klíč chybí
     * nebo nemá řetězcové `name` a `hash` (seed před F6b, `--no-reset`).
     *
     * @return array{name: string, hash: string, seededAt: ?string}|null
     */
    public function getDataset(): ?array
    {
        return self::datasetFrom($this->data);
    }

    /**
     * Lenientní čtení pole `dataset` z dekódovaného main.json — sdílí
     * {@see getDataset()} a inventář {@see \Shipard\Core\Server\DataSourceInventory}.
     *
     * @param array<string, mixed> $config
     * @return array{name: string, hash: string, seededAt: ?string}|null
     */
    public static function datasetFrom(array $config): ?array
    {
        $raw = $config['dataset'] ?? null;
        if (!is_array($raw) || !is_string($raw['name'] ?? null) || trim($raw['name']) === ''
            || !is_string($raw['hash'] ?? null) || trim($raw['hash']) === ''
        ) {
            return null;
        }
        $seededAt = $raw['seededAt'] ?? null;
        return [
            'name' => trim($raw['name']),
            'hash' => trim($raw['hash']),
            'seededAt' => is_string($seededAt) && trim($seededAt) !== '' ? trim($seededAt) : null,
        ];
    }

    /**
     * Zaniklý příznak `ai.useServerConnection` (#126 D9). Na chování nemá
     * vliv — serverové AI připojení řídí třída dat (`isFree()`); čte se jen
     * pro varování `ds-upgrade`, výpis `ds-class show` a přehled `ds-list`.
     * Klíč odebere první zápis třídy (`MainConfigPatcher::withDataClass`).
     */
    public function hasLegacyServerAiFlag(): bool
    {
        $ai = $this->data['ai'] ?? null;
        return is_array($ai) && ($ai['useServerConnection'] ?? false) === true;
    }

    /**
     * Místa konfigurace, která potřebují tajemství (#126 B1, U6) — ze surového
     * `main.json`, bez hodnot: `db.password` vždy; `mail-relay.password`, má-li
     * `main.json` sekci `mail.relay` (povinné jen s `username`);
     * `oidc-<id>.client-secret` pro každý provider s řetězcovým `id` (i
     * neplatným — ten `AuthPolicy` odmítne a `ds-secret` / `doctor` ho má
     * ukázat). `inMainConfig` = hodnota zůstala v `main.json` a ignoruje se
     * (B2). Jediný zdroj pro `ds-secret list` a sekci `doctor`.
     *
     * @return list<DataSourceSecretSlot>
     */
    public function getSecretSlots(): array
    {
        $slots = [new DataSourceSecretSlot(
            name: DataSourceSecrets::DB_PASSWORD,
            usedBy: 'database',
            required: true,
            inMainConfig: $this->nonEmptyString($this->data['database_password'] ?? null) !== null,
        )];

        $relay = $this->data['mail']['relay'] ?? null;
        if (is_array($relay)) {
            $slots[] = new DataSourceSecretSlot(
                name: DataSourceSecrets::MAIL_RELAY_PASSWORD,
                usedBy: 'mail.relay',
                required: $this->nonEmptyString($relay['username'] ?? null) !== null,
                inMainConfig: $this->nonEmptyString($relay['password'] ?? null) !== null,
            );
        }

        $providers = $this->data['auth']['providers'] ?? null;
        if (is_array($providers)) {
            foreach ($providers as $provider) {
                if (!is_array($provider) || !is_string($provider['id'] ?? null) || $provider['id'] === '') {
                    continue;
                }
                $id = $provider['id'];
                $slots[] = new DataSourceSecretSlot(
                    name: 'oidc-' . $id . '.client-secret',
                    usedBy: "auth.providers[{$id}]",
                    required: true,
                    inMainConfig: $this->nonEmptyString($provider['clientSecret'] ?? null) !== null,
                );
            }
        }

        return $slots;
    }

    /**
     * Názvy souborů tajemství, jejichž hodnota zůstala v `main.json` a ignoruje
     * se (pro `doctor` a `ds-secret list`) — nikdy hodnoty.
     *
     * @return list<string>
     */
    public function getSecretsInMainConfig(): array
    {
        $names = [];
        foreach ($this->getSecretSlots() as $slot) {
            if ($slot->inMainConfig) {
                $names[] = $slot->name;
            }
        }
        return $names;
    }

    public function hasSecretsInMainConfig(): bool
    {
        return $this->getSecretsInMainConfig() !== [];
    }

    /** Neprázdný řetězec, jinak null (tajemství se netrimují). */
    private function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** Volitelný textový klíč: trim, prázdný nebo ne-řetězec = null. */
    private function optionalText(string $key): ?string
    {
        $value = $this->data[$key] ?? null;
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        return $value === '' ? null : $value;
    }
}

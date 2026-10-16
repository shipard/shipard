<?php

declare(strict_types=1);

namespace Shipard\Core\Config;

use Shipard\Core\Security\DsSecretCipher;

/**
 * Tajemství zdroje dat v `secrets/` — co tajemství, to soubor (#126 D1–D3,
 * tasks/ds-secrets-split.md):
 *
 *   db.password                  heslo k databázi (dřív main.json database_password)
 *   mail-relay.password          heslo relay pošty (dřív mail.relay.password)
 *   oidc-<id>.client-secret      clientSecret OIDC providera <id> (dřív auth.providers[].clientSecret)
 *
 * Obsah souboru je holá hodnota; při čtení se odstraní jedno koncové `\n`
 * nebo `\r\n` (soubor uložený editorem), prázdný soubor = tajemství chybí
 * (U2). Čte se líně a cachuje per název — kód, který potřebuje jen
 * konfiguraci, do `secrets/` nesáhne. Zápis dělá výhradně
 * {@see \Shipard\Core\Security\SecretsFileWriter}. Název souboru je odkaz,
 * `main.json` žádné odkazy nenese (D2).
 */
final class DataSourceSecrets
{
    public const string DB_PASSWORD = 'db.password';
    public const string MAIL_RELAY_PASSWORD = 'mail-relay.password';

    private const string OIDC_PREFIX = 'oidc-';
    private const string OIDC_SUFFIX = '.client-secret';
    private const string PROVIDER_ID_PATTERN = '/^[a-z0-9-]+$/';

    /** @var array<string, ?string> načtené hodnoty per název (null = chybí) */
    private array $cache = [];

    public function __construct(private readonly string $dataSourceDir)
    {
    }

    /** Název souboru pro clientSecret OIDC providera; id validuje stejně jako OidcProviderConfig. */
    public static function oidcClientSecret(string $providerId): string
    {
        if (!preg_match(self::PROVIDER_ID_PATTERN, $providerId)) {
            throw new \InvalidArgumentException("Auth provider id must match [a-z0-9-]+, got '{$providerId}'");
        }
        return self::OIDC_PREFIX . $providerId . self::OIDC_SUFFIX;
    }

    /** Je `$name` jedním ze známých názvů tajemství (vč. `oidc-<id>.client-secret`)? */
    public static function isValidName(string $name): bool
    {
        return $name === self::DB_PASSWORD
            || $name === self::MAIL_RELAY_PASSWORD
            || self::oidcProviderIdFromName($name) !== null;
    }

    /** Id providera z názvu `oidc-<id>.client-secret`; null = jiný název. */
    public static function oidcProviderIdFromName(string $name): ?string
    {
        if (preg_match('/^oidc-([a-z0-9-]+)\.client-secret$/', $name, $m) !== 1) {
            return null;
        }
        return $m[1];
    }

    public function path(string $name): string
    {
        return DsSecretCipher::secretsDirPath($this->dataSourceDir) . '/' . $name;
    }

    /**
     * Hodnota tajemství, nebo null, když soubor chybí nebo je prázdný.
     *
     * @throws \RuntimeException soubor existuje, ale nejde přečíst (cesta, nikdy obsah)
     */
    public function get(string $name): ?string
    {
        if (array_key_exists($name, $this->cache)) {
            return $this->cache[$name];
        }

        $path = $this->path($name);
        if (!is_file($path)) {
            return $this->cache[$name] = null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Cannot read secret file {$path}");
        }

        $value = self::stripTrailingNewline($raw);
        return $this->cache[$name] = ($value === '' ? null : $value);
    }

    /** Jen existence a neprázdnost souboru — obsah se nečte (doctor, ds-secret list). */
    public function has(string $name): bool
    {
        $path = $this->path($name);
        clearstatcache(false, $path);
        if (!is_file($path)) {
            return false;
        }
        $size = @filesize($path);
        return $size !== false && $size > 0;
    }

    /** Odstraní jedno koncové `\r\n` nebo `\n` (U2); jinak hodnotu nemění. */
    public static function stripTrailingNewline(string $raw): string
    {
        if (str_ends_with($raw, "\r\n")) {
            return substr($raw, 0, -2);
        }
        if (str_ends_with($raw, "\n")) {
            return substr($raw, 0, -1);
        }
        return $raw;
    }
}

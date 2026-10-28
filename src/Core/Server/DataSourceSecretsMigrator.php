<?php

declare(strict_types=1);

namespace Shipard\Core\Server;

use Shipard\Core\Config\DataSourceSecrets;
use Shipard\Core\Security\SecretsFileWriter;

/**
 * Přesun tajemství z `config/main.json` do `secrets/` (#126 D4,
 * tasks/ds-secrets-split.md §3) — krok 0 `shpd-ds ds-upgrade`, jen soubory,
 * bez databáze a bez `DataSourceConfig` (funguje i nad jinak rozbitou
 * konfigurací).
 *
 * Pořadí: nejdřív všechny soubory přes {@see SecretsFileWriter::write()},
 * teprve potom {@see MainConfigFile::patch()} odebere klíče. Pád mezi tím
 * nechá hodnotu v obou souborech (shodnou) a další běh migraci dokončí;
 * `main.json` bez tajemství = no-op bez přepisu souboru. Soubor s jinou
 * hodnotou se přepíše hodnotou z `main.json` s varováním (B1 U3, B2 U2 —
 * hodnota v `main.json` je po B2 čekající změna od člověka nebo z obnovené
 * zálohy). Provider s neplatným `id` soubor pojmenovat nejde: tajemství
 * zůstane v `main.json` s varováním (U5).
 *
 * Z `main.json` se odebírá nad **čerstvým** obsahem pod zámkem patche
 * a jen hodnota, kterou migrátor zapsal do souboru (B2 U3): `database_password`
 * a `mail.relay.password` podle hodnoty, `clientSecret` providera podle `id`
 * **a** hodnoty, bez ohledu na pořadí v poli. Změní-li `main.json` mezi čtením
 * a patchem jiný zapisovatel (`hosting-sync`, ruční úprava), hodnota zůstane
 * s varováním a další běh ji přesune; soubor se starší hodnotou nevadí
 * (přepíše se). {@see SecretsMigrationResult::$moved} nese jen tajemství,
 * která z `main.json` skutečně zmizela.
 * Migrátor zůstává natrvalo — obnova starší zálohy přinese starý `main.json`.
 */
class DataSourceSecretsMigrator
{
    private readonly FileOwnership $ownership;

    /** @param FileOwnership|null $ownership šev pro testy bez roota (vzor HostingSyncRunner) */
    public function __construct(?FileOwnership $ownership = null)
    {
        $this->ownership = $ownership ?? new FileOwnership();
    }

    /** Šev pro testy: zásah jiného zapisovatele do `main.json` mezi zápisem souborů a patchem (U3). */
    protected function beforeConfigPatch(string $dsDir): void
    {
    }

    /**
     * @throws \RuntimeException nečitelný / nevalidní `main.json`, nebo selhání zápisu — nic se nezměnilo,
     *   resp. `main.json` zůstal (soubory už mohou být zapsané, další běh je dorovná)
     */
    public function migrate(string $dsDir): SecretsMigrationResult
    {
        $file = MainConfigFile::path($dsDir);
        $content = @file_get_contents($file);
        if ($content === false) {
            throw new \RuntimeException("cannot read {$file}");
        }
        $config = json_decode($content, true);
        if (!is_array($config)) {
            throw new \RuntimeException("invalid JSON in {$file}");
        }

        $warnings = [];
        /** @var array<string, string> název souboru → hodnota */
        $secrets = [];

        $dbPassword = $config['database_password'] ?? null;
        if (is_string($dbPassword) && $dbPassword !== '') {
            $secrets[DataSourceSecrets::DB_PASSWORD] = $dbPassword;
        }

        $relayPassword = $config['mail']['relay']['password'] ?? null;
        if (is_array($config['mail']['relay'] ?? null) && is_string($relayPassword) && $relayPassword !== '') {
            $secrets[DataSourceSecrets::MAIL_RELAY_PASSWORD] = $relayPassword;
        }

        $providers = $config['auth']['providers'] ?? null;
        if (is_array($providers)) {
            foreach ($providers as $i => $provider) {
                if (!is_array($provider)) {
                    continue;
                }
                $clientSecret = $provider['clientSecret'] ?? null;
                if (!is_string($clientSecret) || $clientSecret === '') {
                    continue;
                }
                $id = $provider['id'] ?? null;
                try {
                    $name = DataSourceSecrets::oidcClientSecret(is_string($id) ? $id : '');
                } catch (\InvalidArgumentException) {
                    $warnings[] = sprintf(
                        'auth.providers[%s] has an invalid id — its clientSecret stays in config/main.json (fix the id, then run ds-upgrade again)',
                        (string) $i,
                    );
                    continue;
                }
                $secrets[$name] = $clientSecret;
            }
        }

        if ($secrets === []) {
            return new SecretsMigrationResult([], $warnings);
        }

        $reader = new DataSourceSecrets($dsDir);
        foreach ($secrets as $name => $value) {
            if ($reader->has($name) && $reader->get($name) !== $value) {
                $warnings[] = "secrets/{$name} had a different value — overwritten with the value from config/main.json";
            }
            foreach (SecretsFileWriter::write($dsDir, $name, $value) as $ownershipWarning) {
                $warnings[] = $ownershipWarning;
            }
        }

        $this->beforeConfigPatch($dsDir);

        /** @var list<string> názvy, jejichž hodnota z main.json skutečně zmizela */
        $removed = [];
        MainConfigFile::patch(
            $dsDir,
            static function (array $raw) use ($secrets, &$removed, &$warnings): array {
                foreach ($secrets as $name => $value) {
                    if (self::removeFromConfig($raw, $name, $value)) {
                        $removed[] = $name;
                    } else {
                        $warnings[] = "{$name} changed in config/main.json during migration — left there, run ds-upgrade again";
                    }
                }
                return $raw;
            },
            $this->ownership,
        );

        return new SecretsMigrationResult($removed, $warnings);
    }

    /**
     * Odebere z čerstvě načteného `main.json` klíč tajemství `$name`, jen když
     * jeho hodnota sedí na tu, kterou migrátor zapsal do souboru (U3);
     * providera hledá podle `id`, ne podle indexu. Vrací, zda se odebralo.
     *
     * @param array<string, mixed> $raw
     */
    private static function removeFromConfig(array &$raw, string $name, string $value): bool
    {
        if ($name === DataSourceSecrets::DB_PASSWORD) {
            if (($raw['database_password'] ?? null) !== $value) {
                return false;
            }
            unset($raw['database_password']);
            return true;
        }

        if ($name === DataSourceSecrets::MAIL_RELAY_PASSWORD) {
            if (!is_array($raw['mail']['relay'] ?? null) || ($raw['mail']['relay']['password'] ?? null) !== $value) {
                return false;
            }
            unset($raw['mail']['relay']['password']);
            return true;
        }

        $providerId = DataSourceSecrets::oidcProviderIdFromName($name);
        $providers = $raw['auth']['providers'] ?? null;
        if ($providerId === null || !is_array($providers)) {
            return false;
        }
        $removed = false;
        foreach ($providers as $i => $provider) {
            if (is_array($provider) && ($provider['id'] ?? null) === $providerId && ($provider['clientSecret'] ?? null) === $value) {
                unset($raw['auth']['providers'][$i]['clientSecret']);
                $removed = true;
            }
        }
        return $removed;
    }
}

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
 * hodnotou se přepíše hodnotou z `main.json` s varováním (U3 — hodnota
 * v `main.json` má v přechodném období přednost). Provider s neplatným `id`
 * soubor pojmenovat nejde: tajemství zůstane v `main.json` s varováním (U5).
 * Migrátor zůstává natrvalo — obnova starší zálohy přinese starý `main.json`.
 */
final class DataSourceSecretsMigrator
{
    private readonly FileOwnership $ownership;

    /** @param FileOwnership|null $ownership šev pro testy bez roota (vzor HostingSyncRunner) */
    public function __construct(?FileOwnership $ownership = null)
    {
        $this->ownership = $ownership ?? new FileOwnership();
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
        /** @var list<int> indexy providerů, jejichž clientSecret se přesouvá */
        $movedProviders = [];

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
                $movedProviders[] = (int) $i;
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

        MainConfigFile::patch(
            $dsDir,
            static function (array $raw) use ($secrets, $movedProviders): array {
                if (isset($secrets[DataSourceSecrets::DB_PASSWORD])) {
                    unset($raw['database_password']);
                }
                if (isset($secrets[DataSourceSecrets::MAIL_RELAY_PASSWORD]) && is_array($raw['mail']['relay'] ?? null)) {
                    unset($raw['mail']['relay']['password']);
                }
                foreach ($movedProviders as $i) {
                    if (is_array($raw['auth']['providers'][$i] ?? null)) {
                        unset($raw['auth']['providers'][$i]['clientSecret']);
                    }
                }
                return $raw;
            },
            $this->ownership,
        );

        return new SecretsMigrationResult(array_keys($secrets), $warnings);
    }
}

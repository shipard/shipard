<?php

declare(strict_types=1);

namespace Shipard\Core\Server;

use Shipard\Core\Config\DataClass;

/**
 * Čisté funkce pro patchování DS `config/main.json` — agent hosting-sync
 * přes ně zapisuje `auth.providers` na nově založený DS (D3), `ds-create`,
 * `dataset-seed` a `ds-class` třídu dat (#126 D6). Zápis na disk (atomicky
 * tmp + rename, chmod 0600, vlastník) dělá {@see MainConfigFile::patch} —
 * tady jen transformace pole kvůli testovatelnosti.
 */
final class MainConfigPatcher
{
    /** Nejvyšší délka účelu zdroje dat (`purpose`) ve znacích. */
    public const PURPOSE_MAX_LENGTH = 200;

    /**
     * Merge položky do `auth.providers` podle `id`: existující položka se
     * stejným id se nahradí, jinak se přidá na konec. Ostatní klíče configu
     * (vč. ostatních klíčů sekce `auth`) zůstávají beze změny.
     *
     * @param array<string, mixed> $config celý dekódovaný main.json
     * @param array<string, mixed> $provider položka auth.providers
     *        (id, label, issuer, clientId, clientSecret, autoLinkEmail, …)
     * @return array<string, mixed>
     */
    public static function mergeAuthProvider(array $config, array $provider): array
    {
        $id = (string) ($provider['id'] ?? '');
        if ($id === '' || !preg_match('/^[a-z0-9-]+$/', $id)) {
            throw new \InvalidArgumentException("Auth provider 'id' must match [a-z0-9-]+, got '{$id}'");
        }

        $auth = is_array($config['auth'] ?? null) ? $config['auth'] : [];
        $providers = is_array($auth['providers'] ?? null) ? array_values($auth['providers']) : [];

        $replaced = false;
        foreach ($providers as $i => $existing) {
            if (is_array($existing) && (string) ($existing['id'] ?? '') === $id) {
                $providers[$i] = $provider;
                $replaced = true;
                break;
            }
        }
        if (!$replaced) {
            $providers[] = $provider;
        }

        $auth['providers'] = $providers;
        $config['auth'] = $auth;

        return $config;
    }

    /**
     * Třída dat (#126 D6): nastaví `dataClass`, `origin` (kdo třídu nastavil —
     * `ds-create`, `ds-create --real-copy`, `dataset-seed <sada>`, `ds-class`)
     * a `dataClassChanged` (ISO 8601 v UTC, {@see \Shipard\Core\Config\DataSourceState::formatUtc})
     * a odebere zaniklý `ai.useServerConnection` (D9, U8) — prázdná sekce `ai`
     * zmizí, ostatní klíče `ai` zůstávají.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function withDataClass(array $config, DataClass $class, string $origin, string $changedAt): array
    {
        $config['dataClass'] = $class->value;
        $config['origin'] = $origin;
        $config['dataClassChanged'] = $changedAt;

        if (is_array($config['ai'] ?? null)) {
            $ai = $config['ai'];
            unset($ai['useServerConnection']);
            if ($ai === []) {
                unset($config['ai']);
            } else {
                $config['ai'] = $ai;
            }
        }

        return $config;
    }

    /**
     * Účel zdroje dat (`purpose`): null nebo prázdný řetězec klíč odebere.
     * Hodnotu předem projde {@see normalizePurpose()}.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function withPurpose(array $config, ?string $purpose): array
    {
        if ($purpose === null || $purpose === '') {
            unset($config['purpose']);
        } else {
            $config['purpose'] = $purpose;
        }
        return $config;
    }

    /**
     * Jediná validace účelu pro `ds-create`, `ds-class` i vývojářský dashboard:
     * trim, jeden řádek bez řídicích znaků, nejvýš PURPOSE_MAX_LENGTH znaků.
     * Prázdný výsledek je dovolený (= bez účelu; volající ho přes
     * {@see withPurpose()} odebere).
     *
     * @throws \InvalidArgumentException věta pro uživatele
     */
    public static function normalizePurpose(string $raw): string
    {
        $purpose = trim($raw);
        if (preg_match('/\p{Cc}/u', $purpose) !== 0) {
            throw new \InvalidArgumentException('Purpose must be a single line without control characters.');
        }
        if (mb_strlen($purpose) > self::PURPOSE_MAX_LENGTH) {
            throw new \InvalidArgumentException(
                sprintf('Purpose is too long (max %d characters).', self::PURPOSE_MAX_LENGTH)
            );
        }
        return $purpose;
    }

    /**
     * Příznak serverového AI připojení pro vývoj (`ai.useServerConnection`,
     * tasks/testing-phase4.md D40) — `true` zapne, `false` klíč odebere
     * (prázdnou sekci `ai` nechá zmizet). Ostatní klíče `ai` zůstávají.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function withServerAi(array $config, bool $enabled): array
    {
        $ai = is_array($config['ai'] ?? null) ? $config['ai'] : [];
        if ($enabled) {
            $ai['useServerConnection'] = true;
        } else {
            unset($ai['useServerConnection']);
        }
        if ($ai === []) {
            unset($config['ai']);
        } else {
            $config['ai'] = $ai;
        }
        return $config;
    }
}

<?php

declare(strict_types=1);

namespace Shipard\Core\Ai;

use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Logging\ErrorLogger;

/**
 * Serverové AI připojení pro vývoj (tasks/testing-phase4.md D40, #103 D10):
 * virtuální připojení s kódem `server`, které nabízí server v režimu
 * `development` s uloženým klíčem ({@see DevAiKeyStore}). Použije ho jen
 * zdroj dat s třídou dat `free` (`dataClass` v `config/main.json`, #126 D9 —
 * `ds-create` ve vývoji, `dataset-seed`, `shpd-ds ds-class set free`)
 * a jen když nemá vlastní aktivní připojení s klíčem — rozhoduje
 * {@see AiBackendResolver}. Mimo `development` {@see detect()} vrací null,
 * takže se nikdy nepoužije.
 */
final class ServerAiConnection
{
    public const string CODE = 'server';
    public const int ID = 0;
    public const string MODE_DEVELOPMENT = 'development';

    /** Marker virtuálního řádku připojení ({@see row()}). */
    private const string ROW_MARKER = '_server';

    private static bool $detected = false;
    private static ?self $instance = null;

    public function __construct(
        public readonly string $platform,
        public readonly ?string $baseUrl,
        public readonly string $configDir,
    ) {}

    /**
     * Připojení podle `/etc/shipard/server.json` (nebo předané konfigurace):
     * jen `mode = development` **a** existující klíč. Chybějící či
     * nečitelný `server.json` = null (fail-soft — nikdy nesmí shodit
     * resolver). Výsledek bez argumentu se cachuje po dobu procesu.
     */
    public static function detect(?ServerConfig $server = null): ?self
    {
        if ($server !== null) {
            return self::fromServerConfig($server);
        }
        if (self::$detected) {
            return self::$instance;
        }
        self::$detected = true;
        try {
            $server = new ServerConfig();
            $server->load();
        } catch (\Throwable) {
            return self::$instance = null;
        }
        return self::$instance = self::fromServerConfig($server);
    }

    public static function fromServerConfig(ServerConfig $server): ?self
    {
        try {
            if ($server->getMode() !== self::MODE_DEVELOPMENT) {
                return null;
            }
            $devAi = $server->getDevAi();
            $configDir = $server->getConfigDir();
        } catch (\Throwable $e) {
            ErrorLogger::warn('ServerAiConnection: server.json devAi section is invalid — server AI connection unavailable', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
        if (!DevAiKeyStore::exists($configDir, $devAi->platform)) {
            return null;
        }
        return new self($devAi->platform, $devAi->baseUrl, $configDir);
    }

    /** Testy: pevný výsledek {@see detect()} bez argumentu (null = žádné serverové připojení). */
    public static function overrideForTesting(?self $instance): void
    {
        self::$detected = true;
        self::$instance = $instance;
    }

    /** Testy: další {@see detect()} bez argumentu čte server.json znovu. */
    public static function resetForTesting(): void
    {
        self::$detected = false;
        self::$instance = null;
    }

    /** @throws \Shipard\Core\Ai\Exception\DevAiKeyException */
    public function apiKey(): string
    {
        return DevAiKeyStore::read($this->configDir, $this->platform);
    }

    public function keyFilePath(): string
    {
        return DevAiKeyStore::keyFilePath($this->configDir, $this->platform);
    }

    /**
     * Virtuální řádek připojení ve tvaru `core_ai_backends` — resolver s ním
     * zachází jako s řádkem (platforma, adresa), klíč čte {@see apiKey()};
     * `api_key` nese jen marker, aby prošla kontrola „klíč je uložený“.
     *
     * @return array<string, mixed>
     */
    public function row(): array
    {
        return [
            'id' => self::ID,
            'backend_id' => self::CODE,
            'name' => 'Server (development)',
            'provider' => $this->platform,
            'model' => null,
            'base_url' => $this->baseUrl,
            'api_key' => '(server)',
            'is_default' => 0,
            'is_active' => 1,
            self::ROW_MARKER => true,
        ];
    }

    /** @param array<string, mixed> $row */
    public static function isServerRow(array $row): bool
    {
        return ($row[self::ROW_MARKER] ?? false) === true;
    }
}

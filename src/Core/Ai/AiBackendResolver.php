<?php

declare(strict_types=1);

namespace Shipard\Core\Ai;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Security\DsSecretCipher;

/**
 * Loads AI connections (`core_ai_backends`: the DS-wide default, a row by
 * id or by code) and decrypts their API key. Used by {@see AiFunctionResolver}
 * only — callers get a {@see ResolvedAiCall} through it, never a raw row
 * (tasks/ai-functions.md D40). The `api_key` column is `encrypted_text` —
 * see docs/operations/secrets.md.
 *
 * Serverové připojení pro vývoj (tasks/testing-phase4.md D40, #103 D10,
 * #126 D9): zdroj dat s třídou dat `free` (`dataClass` v main.json,
 * {@see DataSourceConfig::isFree()}), na serveru v režimu `development`
 * s uloženým klíčem ({@see ServerAiConnection}), dostane místo chybějícího
 * výchozího připojení s klíčem virtuální řádek `server`. Kopie reálných dat
 * (`realCopy`, i výchozí bez třídy) ho nedostane nikdy — opt-out neexistuje.
 * Vlastní aktivní připojení s klíčem má vždy přednost; mimo `development`
 * serverové připojení neexistuje.
 */
class AiBackendResolver
{
    private const string BACKENDS_TABLE = 'core_ai_backends';

    private ?ServerAiConnection $server;
    private bool $serverResolved;

    /**
     * @param ServerAiConnection|null $server Serverové připojení; null =
     *        zjistit ze server.json ({@see ServerAiConnection::detect()}),
     *        pokud `$detectServer` (testy ho vypínají).
     */
    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly ?DataSourceConfig $dsConfig = null,
        ?ServerAiConnection $server = null,
        bool $detectServer = true,
    ) {
        $this->server = $server;
        $this->serverResolved = $server !== null || !$detectServer;
    }

    /**
     * The default active backend row **holding a key**; without one the
     * server connection (free data source on a development server, D40,
     * #126 D9); otherwise the keyless default row (resolver reports
     * `KEY_MISSING`) or null when none is configured.
     *
     * @return array<string, mixed>|null
     */
    public function defaultBackend(): ?array
    {
        $row = $this->db->fetchRow(
            'SELECT * FROM `' . self::BACKENDS_TABLE . '` WHERE `is_default` = %i AND `is_active` = %i LIMIT 1',
            1,
            1,
        );
        if ($row !== null && (string) ($row['api_key'] ?? '') !== '') {
            return $row;
        }
        return $this->serverBackend() ?? $row;
    }

    /**
     * A specific active backend by its row id, or null when it does not
     * exist or is inactive (a connection chosen on an AI function row,
     * in a mail AI profile, or by a CLI option).
     *
     * @return array<string, mixed>|null
     */
    public function backendByNdx(int $ndx): ?array
    {
        if ($ndx <= 0) {
            return null;
        }
        return $this->db->fetchRow(
            'SELECT * FROM `' . self::BACKENDS_TABLE . '` WHERE `id` = %i AND `is_active` = %i LIMIT 1',
            $ndx,
            1,
        );
    }

    /**
     * An active backend by its code (`backend_id`), or the server connection
     * for {@see ServerAiConnection::CODE}; null when missing / inactive /
     * not available (variant files of `ai-eval`, D35).
     *
     * @return array<string, mixed>|null
     */
    public function backendByCode(string $code): ?array
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }
        if ($code === ServerAiConnection::CODE) {
            return $this->serverBackend();
        }
        return $this->db->fetchRow(
            'SELECT * FROM `' . self::BACKENDS_TABLE . '` WHERE `backend_id` = %s AND `is_active` = %i LIMIT 1',
            $code,
            1,
        );
    }

    /**
     * Virtuální řádek serverového připojení, smí-li ho tento zdroj dat
     * použít (třída dat `free`, #126 D9) a server ho nabízí; jinak null.
     *
     * @return array<string, mixed>|null
     */
    public function serverBackend(): ?array
    {
        if ($this->dsConfig === null || !$this->dsConfig->isFree()) {
            return null;
        }
        return $this->server()?->row();
    }

    /**
     * Decrypts the backend's API key. Returns null when the backend has no
     * key stored (not activated yet). The server connection reads its key
     * from the server key file instead.
     *
     * @param array<string, mixed> $backend
     * @throws \RuntimeException when a key exists but cannot be decrypted
     *                           (missing DS config or cipher failure)
     * @throws \Shipard\Core\Ai\Exception\DevAiKeyException server key missing / unreadable
     */
    public function apiKey(array $backend): ?string
    {
        if (ServerAiConnection::isServerRow($backend)) {
            $server = $this->server();
            if ($server === null) {
                throw new \RuntimeException('AiBackendResolver: the server AI connection is not available on this server');
            }
            return $server->apiKey();
        }
        $encrypted = $backend['api_key'] ?? null;
        if ($encrypted === null || $encrypted === '') {
            return null;
        }
        if ($this->dsConfig === null) {
            throw new \RuntimeException('AiBackendResolver: DataSourceConfig is required to decrypt the backend API key');
        }
        return DsSecretCipher::forConfig($this->dsConfig)->decrypt((string) $encrypted);
    }

    private function server(): ?ServerAiConnection
    {
        if (!$this->serverResolved) {
            $this->serverResolved = true;
            $this->server = ServerAiConnection::detect();
        }
        return $this->server;
    }
}

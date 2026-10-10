<?php

declare(strict_types=1);

namespace Shipard\Core\Ai;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Security\DsSecretCipher;

/**
 * Loads AI connections (`core_ai_backends`: the DS-wide default or a row by
 * id) and decrypts their API key. Used by {@see AiFunctionResolver} only —
 * callers get a {@see ResolvedAiCall} through it, never a raw row
 * (tasks/ai-functions.md D40). The `api_key` column is `encrypted_text` —
 * see docs/operations/secrets.md.
 */
class AiBackendResolver
{
    private const string BACKENDS_TABLE = 'core_ai_backends';

    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly ?DataSourceConfig $dsConfig = null,
    ) {}

    /**
     * The default active backend row, or null when none is configured.
     *
     * @return array<string, mixed>|null
     */
    public function defaultBackend(): ?array
    {
        return $this->db->fetchRow(
            'SELECT * FROM `' . self::BACKENDS_TABLE . '` WHERE `is_default` = %i AND `is_active` = %i LIMIT 1',
            1,
            1,
        );
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
     * Decrypts the backend's API key. Returns null when the backend has no
     * key stored (not activated yet).
     *
     * @param array<string, mixed> $backend
     * @throws \RuntimeException when a key exists but cannot be decrypted
     *                           (missing DS config or cipher failure)
     */
    public function apiKey(array $backend): ?string
    {
        $encrypted = $backend['api_key'] ?? null;
        if ($encrypted === null || $encrypted === '') {
            return null;
        }
        if ($this->dsConfig === null) {
            throw new \RuntimeException('AiBackendResolver: DataSourceConfig is required to decrypt the backend API key');
        }
        return DsSecretCipher::forConfig($this->dsConfig)->decrypt((string) $encrypted);
    }
}

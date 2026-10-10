<?php

declare(strict_types=1);

namespace Shipard\Core\Ai;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Security\DsSecretCipher;

/**
 * Resolves the DS-wide default AI backend and decrypts its API key.
 *
 * Extracted from the ChatController pattern (default active backend from
 * `core_ai_backends`, key decryption via DsSecretCipher) so non-chat LLM
 * consumers (dashboard summary) don't duplicate the SQL + secrets plumbing.
 * The `api_key` column is `encrypted_text` — see docs/operations/secrets.md.
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
     * exist or is inactive. Used by per-feature backend overrides
     * (e.g. the `exchange.contentTag.backend` setting).
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
     * Backend row → tuning parameters for `LlmChatParams`
     * (tasks/ai-models-phase0.md F0-D7). One place for the conversion:
     * `auto` (and NULL / empty) → null = parameter not sent; NULL
     * temperature → null, any numeric value (including `0`) → float.
     *
     * Who reads what: `thinking` and `effort` go to every caller; the
     * `temperature` key is for the mail analysis runner only (F0-D1) —
     * chat, dashboard summary and the tag classifiers keep passing null,
     * otherwise the `0` stored on existing rows would change their
     * behaviour.
     *
     * @param array<string, mixed> $backend
     * @return array{temperature: ?float, thinking: ?string, effort: ?string}
     */
    public static function tuning(array $backend): array
    {
        $temperature = $backend['temperature'] ?? null;
        return [
            'temperature' => is_numeric($temperature) ? (float) $temperature : null,
            'thinking' => self::tuningValue($backend['thinking'] ?? null),
            'effort' => self::tuningValue($backend['effort'] ?? null),
        ];
    }

    private static function tuningValue(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        return $value === '' || $value === 'auto' ? null : $value;
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

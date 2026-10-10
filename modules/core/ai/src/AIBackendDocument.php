<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Ai;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;
use Shipard\Core\Security\DsSecretCipher;

/**
 * Document třída pro `core_ai_backends`.
 *
 * Zodpovědnosti:
 *   - validace povinných polí (backend_id, name, model)
 *   - vynucení invariantu "max. jeden backend s is_default = true per DS"
 *   - šifrování `api_key` přes DsSecretCipher v `beforeSave()` při dirty change
 *   - audit pole (created, modified)
 *
 * Pattern šifrování (viz docs/operations/secrets.md, tasks/ds-encrypted-secrets.md §7.1):
 *   - api_key chybí v $data        → ponecháme tak, UPDATE nezahrne sloupec
 *   - api_key === null nebo ''     → unset, UPDATE nezahrne sloupec (placeholder
 *                                    submit beze změny — viz CLAUDE.md "Form pro
 *                                    editaci citlivého pole")
 *   - api_key má hodnotu           → encrypt (volá DsSecretCipher)
 *
 * Cipher se injektuje přes setSecretCipher(). Pokud volající chce uložit hodnotu
 * api_key bez injektovaného cipheru, beforeSave() hodí výjimku — never silently
 * write plaintext.
 */
class AIBackendDocument extends Document
{
    private ?DsSecretCipher $cipher = null;

    public function setSecretCipher(DsSecretCipher $cipher): void
    {
        $this->cipher = $cipher;
    }

    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();

        if (empty(trim((string) ($data['backend_id'] ?? '')))) {
            $result->addError('backend_id', 'Kód backendu je povinný', 'required');
        }

        if (empty(trim((string) ($data['name'] ?? '')))) {
            $result->addError('name', 'Název je povinný', 'required');
        }

        if (empty(trim((string) ($data['model'] ?? '')))) {
            $result->addError('model', 'Model je povinný', 'required');
        }

        if (!empty($data['is_default']) && $this->db !== null) {
            $existing = $this->findExistingDefault((int) ($data['id'] ?? 0));
            if ($existing !== null) {
                $result->addError(
                    'is_default',
                    "Výchozí backend již existuje: {$existing}",
                    'duplicate_default',
                );
            }
        }

        return $result;
    }

    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        $now = date('Y-m-d H:i:s');
        $isNew = empty($data['id']);

        if (isset($data['is_default'])) {
            $data['is_default'] = $data['is_default'] ? 1 : 0;
        }
        if (isset($data['is_active'])) {
            $data['is_active'] = $data['is_active'] ? 1 : 0;
        }

        if (array_key_exists('api_key', $data)) {
            $value = $data['api_key'];
            if ($value === null || $value === '') {
                // Empty submit znamená "neměnit" — nepropagujeme do UPDATE,
                // jinak bychom přepsali ciphertext prázdnou hodnotou.
                unset($data['api_key']);
            } else {
                if ($this->cipher === null) {
                    throw new \RuntimeException(
                        'AIBackendDocument: cannot save api_key without DsSecretCipher. '
                        . 'Call setSecretCipher() before saving.',
                    );
                }
                $data['api_key'] = $this->cipher->encrypt((string) $value);
            }
        }

        if ($isNew) {
            if (empty($data['created'])) {
                $data['created'] = $now;
            }
        }
        $data['modified'] = $now;
    }

    /**
     * Decrypt API key from a freshly loaded row. Volá se v
     * AnalysisClaimService::claim() těsně před předáním klíče runneru.
     * Plaintext se nikdy neuchovává mimo dobu zpracování.
     */
    public function decryptApiKey(array $row): ?string
    {
        if (!array_key_exists('api_key', $row) || $row['api_key'] === null || $row['api_key'] === '') {
            return null;
        }
        if ($this->cipher === null) {
            throw new \RuntimeException(
                'AIBackendDocument: cannot decrypt api_key without DsSecretCipher.',
            );
        }
        return $this->cipher->decrypt((string) $row['api_key']);
    }

    private function findExistingDefault(int $excludeId): ?string
    {
        $row = $this->db->fetch(
            'SELECT %n, %n FROM %n WHERE %n = %i AND %n != %i LIMIT 1',
            'backend_id',
            'name',
            'core_ai_backends',
            'is_default',
            1,
            'id',
            $excludeId,
        );

        if ($row === null) {
            return null;
        }

        $row = (array) $row;
        return sprintf('%s (%s)', (string) ($row['name'] ?? ''), (string) ($row['backend_id'] ?? ''));
    }
}

<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Ai;

use Shipard\Core\Ai\AiFunctionRegistry;
use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;

/**
 * Document třída pro `core_ai_function_settings` (tasks/ai-functions.md
 * D38): nastavení AI funkce per zdroj dat.
 *
 * Zodpovědnosti:
 *   - `function_id` existuje v registru deklarovaných funkcí a po založení
 *     se nemění (řádky zakládá ds-upgrade, ne uživatel)
 *   - připojení existuje; prázdné = výchozí připojení
 *   - `thinking` / `effort` z cfgItemu (vč. `default` a `auto`)
 *   - `max_tokens` prázdné (= podle funkce) nebo kladné
 *   - normalizace prázdných hodnot na NULL, audit (created, modified)
 *   - mazání odmítá — jeden řádek na deklarovanou funkci drží ds-upgrade
 *
 * Vlastní ID modelu a `model_like` (D43) hlídá {@see ModelFieldValidator}.
 */
class AiFunctionSettingsDocument extends Document
{
    public const string TABLE = 'core_ai_function_settings';

    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();

        $functionId = trim((string) ($data['function_id'] ?? ''));
        if ($functionId === '') {
            $result->addError('function_id', 'Funkce je povinná', 'required');
        } else {
            $registry = AiFunctionRegistry::fromConfig($this->config);
            if (!$registry->isEmpty() && !$registry->has($functionId)) {
                $result->addError('function_id', "Funkci '{$functionId}' žádný aktivní modul nedeklaruje", 'unknown_function');
            }
            // Řádek patří své funkci napořád — uložená hodnota rozhoduje
            // (validace nedostává originál, beforeSave ho hlídá podruhé).
            $stored = $this->storedFunctionId((int) ($data['id'] ?? 0));
            if ($stored !== null && $stored !== $functionId) {
                $result->addError('function_id', "Funkci řádku nelze změnit — řádek patří funkci {$stored}", 'immutable');
            }
        }

        $connection = self::intOrNull($data['connection'] ?? null);
        if ($connection !== null && $this->db !== null && !$this->connectionExists($connection)) {
            $result->addError('connection', 'AI připojení neexistuje', 'unknown_connection');
        }

        foreach (['thinking' => 'core.ai.thinkingModes', 'effort' => 'core.ai.effortLevels'] as $column => $cfgItemId) {
            $value = trim((string) ($data[$column] ?? ''));
            if ($value === '') {
                continue; // beforeSave doplní `default`
            }
            $cfg = $this->config?->cfgItem($cfgItemId);
            if (is_array($cfg) && $cfg !== [] && !isset($cfg[$value])) {
                $result->addError($column, "Hodnota '{$value}' není v nabídce", 'invalid_value');
            }
        }

        $maxTokens = $data['max_tokens'] ?? null;
        if ($maxTokens !== null && $maxTokens !== '' && (!is_numeric($maxTokens) || (int) $maxTokens < 0 || (string) (int) $maxTokens !== (string) $maxTokens)) {
            $result->addError('max_tokens', 'Strop tokenů musí být celé kladné číslo, nebo prázdný', 'invalid_value');
        }

        ModelFieldValidator::validate($this->config, $data, $result);

        return $result;
    }

    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        $now = date('Y-m-d H:i:s');
        $isNew = empty($data['id']);

        if (array_key_exists('function_id', $data)) {
            $data['function_id'] = trim((string) $data['function_id']);
            $original = trim((string) ($originalData['function_id'] ?? ''));
            if (!$isNew && $original !== '' && $data['function_id'] !== $original) {
                throw new \DomainException('Funkci řádku nelze změnit — řádek patří funkci ' . $original . '.');
            }
        }
        if (array_key_exists('connection', $data)) {
            $data['connection'] = self::intOrNull($data['connection']);
        }
        foreach (['model', 'model_like'] as $column) {
            if (array_key_exists($column, $data)) {
                $data[$column] = self::stringOrNull($data[$column]);
            }
        }
        foreach (['thinking', 'effort'] as $column) {
            if (array_key_exists($column, $data)) {
                $value = trim((string) ($data[$column] ?? ''));
                $data[$column] = $value === '' ? 'default' : $value;
            }
        }
        if (array_key_exists('max_tokens', $data)) {
            $value = $data['max_tokens'];
            $data['max_tokens'] = $value === null || $value === '' || (int) $value === 0 ? null : (int) $value;
        }

        if ($isNew && empty($data['created'])) {
            $data['created'] = $now;
        }
        $data['modified'] = $now;
    }

    public function beforeDelete(array $data): void
    {
        throw new \DomainException(
            'Nastavení AI funkce nelze smazat — řádek pro každou deklarovanou funkci zakládá ds-upgrade.'
            . ' Vraťte přepsání na výchozí hodnoty (prázdné připojení a model, ladění „podle funkce“).',
        );
    }

    public static function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        $int = (int) $value;
        return $int > 0 ? $int : null;
    }

    public static function stringOrNull(mixed $value): ?string
    {
        $string = trim((string) ($value ?? ''));
        return $string === '' ? null : $string;
    }

    private function storedFunctionId(int $id): ?string
    {
        if ($id <= 0 || $this->db === null) {
            return null;
        }
        $row = $this->db->fetch('SELECT %n FROM %n WHERE %n = %i', 'function_id', self::TABLE, 'id', $id);
        return $row !== null ? (string) $row['function_id'] : null;
    }

    private function connectionExists(int $id): bool
    {
        $row = $this->db->fetch('SELECT %n FROM %n WHERE %n = %i LIMIT 1', 'id', 'core_ai_backends', 'id', $id);
        return $row !== null;
    }
}

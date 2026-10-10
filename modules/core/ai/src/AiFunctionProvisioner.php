<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Ai;

use Shipard\Core\Ai\AiFunctionRegistry;
use Shipard\Core\Database\DataSourceConnection;

/**
 * Idempotentní provisioning AI funkcí (tasks/ai-functions.md D38): jeden
 * řádek `core_ai_function_settings` na každou funkci deklarovanou aktivním
 * modulem (registr z compiled configu). Volá ho `ds-upgrade` bezpodmínečně
 * (i pod `skipProvisioning`) před provisioningem AI pošty — převod ladění
 * backendů (D41) do řádků funkcí je potřebuje.
 *
 * Bez registru (compiled config před kompilací) se nic nezakládá —
 * provisioner to vrátí jako `skipped_reason`, nikdy neodhaduje.
 */
class AiFunctionProvisioner
{
    public const TABLE = 'core_ai_function_settings';

    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly AiFunctionRegistry $registry,
    ) {}

    /**
     * @return array{functions: array{created: list<string>, existing: int, skipped_reason?: string}}
     */
    public function provision(): array
    {
        return ['functions' => $this->ensureFunctionRows()];
    }

    /**
     * Založí chybějící řádky deklarovaných funkcí; existující nechá být
     * (přepsání uživatele se nepřepisuje). Po prvním běhu `created` prázdné.
     *
     * @return array{created: list<string>, existing: int, skipped_reason?: string}
     */
    public function ensureFunctionRows(): array
    {
        if ($this->registry->isEmpty()) {
            return [
                'created' => [],
                'existing' => 0,
                'skipped_reason' => 'AI function declarations unavailable (cfgItem ' . AiFunctionRegistry::CFG_ITEM . ')',
            ];
        }

        $existing = [];
        foreach ($this->db->fetchAll('SELECT function_id FROM ' . self::TABLE) as $row) {
            $existing[(string) $row['function_id']] = true;
        }

        $created = [];
        $now = date('Y-m-d H:i:s');
        foreach ($this->registry->ids() as $functionId) {
            if (isset($existing[$functionId])) {
                continue;
            }
            $this->db->insertRow(self::TABLE, [
                'function_id' => $functionId,
                'connection' => null,
                'model' => null,
                'model_like' => null,
                'thinking' => 'default',
                'effort' => 'default',
                'max_tokens' => null,
                'created' => $now,
                'modified' => $now,
            ]);
            $created[] = $functionId;
        }

        return ['created' => $created, 'existing' => count($existing)];
    }
}

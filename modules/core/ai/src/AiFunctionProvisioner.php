<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Ai;

use Shipard\Core\Ai\AiFunctionRegistry;
use Shipard\Core\Ai\ModelCatalog;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Settings\SettingsStore;

/**
 * Idempotentní provisioning AI funkcí (tasks/ai-functions.md D38, D41,
 * D46): jeden řádek `core_ai_function_settings` na každou funkci
 * deklarovanou aktivním modulem (registr z compiled configu), převod
 * dřívějšího nastavení backendů do řádků funkcí a přepis vyřazených
 * modelů. Volá ho `ds-upgrade` bezpodmínečně (i pod `skipProvisioning`)
 * před provisioningem AI pošty; {@see finishLegacyConversion()} až po
 * něm — převod profilů (MailAiProvisioner) čte tytéž staré sloupce.
 *
 * Převod (D41, jednorázově, staré sloupce `model`, `thinking`, `effort`
 * na připojení jsou značka „nepřevedeno“ — po dokončení je
 * {@see finishLegacyConversion()} vynuluje, D46):
 *  2. nastavení `exchange.contentTag.backend` → připojení (a model, liší-li
 *     se od modelu úrovně) řádku `exchange.contentTags`; klíč se smaže;
 *  3. výchozí připojení s `thinking` / `effort` jiným než `auto` → stejná
 *     hodnota do řádků všech funkcí, které ještě mají `default` (dnes
 *     platí pro všechny volající).
 * Pravidlo 1 (profily pošty) žije v MailAiProvisioner. `max_tokens`
 * a `temperature` připojení se nepřevádějí — funkce mají vlastní strop
 * a teplotu.
 *
 * Bez registru (compiled config před kompilací) se nic nezakládá —
 * provisioner to vrátí jako `skipped_reason`, nikdy neodhaduje.
 */
class AiFunctionProvisioner
{
    public const TABLE = 'core_ai_function_settings';
    public const CONNECTIONS_TABLE = 'core_ai_backends';

    /** Dřívější per-funkce volba připojení klasifikace štítků (tasks/content-tag-enrichment.md D18). */
    public const LEGACY_SETTING_CONTENT_TAG_CONNECTION = 'exchange.contentTag.backend';
    public const FUNCTION_CONTENT_TAGS = 'exchange.contentTags';

    /** Sloupce připojení, které od fáze 1b nic nečte; `model` zůstává v definici jako nullable (D39). */
    private const LEGACY_TUNING_COLUMNS = ['max_tokens', 'temperature', 'thinking', 'effort'];

    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly AiFunctionRegistry $registry,
        private readonly ?ModelCatalog $catalog = null,
        private readonly ?SettingsStore $settings = null,
    ) {}

    /**
     * @return array{
     *     functions: array{created: list<string>, existing: int, skipped_reason?: string},
     *     converted: list<array{function_id: string, changes: list<string>}>,
     *     retired: list<array{id: int, function_id: string, from: string, to: string}>
     * }
     */
    public function provision(): array
    {
        $functions = $this->ensureFunctionRows();
        $converted = isset($functions['skipped_reason']) ? [] : $this->convertLegacy();
        $retired = isset($functions['skipped_reason']) ? [] : $this->retireModels();

        return ['functions' => $functions, 'converted' => $converted, 'retired' => $retired];
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

    /**
     * Pravidla 2 a 3 převodu (D41). Druhý běh nic nenajde: nastavení je
     * smazané, řádky funkcí už `default` nemají, a po
     * {@see finishLegacyConversion()} žádné připojení značku nenese.
     *
     * @return list<array{function_id: string, changes: list<string>}>
     */
    public function convertLegacy(): array
    {
        $converted = [];
        $contentTags = $this->convertContentTagSetting();
        if ($contentTags !== null) {
            $converted[] = $contentTags;
        }
        foreach ($this->convertDefaultConnectionTuning() as $entry) {
            $converted[] = $entry;
        }
        return $converted;
    }

    /** @return array{function_id: string, changes: list<string>}|null */
    private function convertContentTagSetting(): ?array
    {
        if ($this->settings === null) {
            return null;
        }
        $value = $this->settings->get(self::LEGACY_SETTING_CONTENT_TAG_CONNECTION);
        if (!is_numeric($value) || (int) $value <= 0) {
            return null;
        }
        $connectionId = (int) $value;
        $changes = [];

        $connection = $this->db->fetchRow(
            'SELECT id, backend_id, model, is_default FROM ' . self::CONNECTIONS_TABLE . ' WHERE id = %i',
            $connectionId,
        );
        $row = $this->db->fetchRow(
            'SELECT id, function_id, connection, model FROM ' . self::TABLE . ' WHERE function_id = %s',
            self::FUNCTION_CONTENT_TAGS,
        );
        if ($connection === null) {
            $changes[] = "setting " . self::LEGACY_SETTING_CONTENT_TAG_CONNECTION . " = {$connectionId} dropped (connection missing)";
        } elseif ($row === null) {
            $changes[] = "setting " . self::LEGACY_SETTING_CONTENT_TAG_CONNECTION . " = {$connectionId} dropped (function row missing)";
        } else {
            $update = [];
            $code = (string) ($connection['backend_id'] ?? $connectionId);
            if (empty($connection['is_default']) && ($row['connection'] ?? null) === null) {
                $update['connection'] = $connectionId;
                $changes[] = "connection '{$code}' (from setting " . self::LEGACY_SETTING_CONTENT_TAG_CONNECTION . ')';
            } elseif (!empty($connection['is_default'])) {
                $changes[] = "setting " . self::LEGACY_SETTING_CONTENT_TAG_CONNECTION . " pointed at the default connection '{$code}' — dropped";
            }
            $model = trim((string) ($connection['model'] ?? ''));
            $tierModel = $this->tierModelOf(self::FUNCTION_CONTENT_TAGS);
            if ($model !== '' && $model !== $tierModel && ($row['model'] ?? null) === null) {
                $update['model'] = $model;
                $changes[] = "model {$model} (from connection '{$code}')";
            }
            if ($update !== []) {
                $this->db->updateWhere(self::TABLE, $update + ['modified' => date('Y-m-d H:i:s')], 'id = %i', (int) $row['id']);
            }
        }
        $this->settings->delete(self::LEGACY_SETTING_CONTENT_TAG_CONNECTION);

        return ['function_id' => self::FUNCTION_CONTENT_TAGS, 'changes' => $changes];
    }

    /** @return list<array{function_id: string, changes: list<string>}> */
    private function convertDefaultConnectionTuning(): array
    {
        $columns = $this->db->getTableColumns(self::CONNECTIONS_TABLE);
        if (!isset($columns['thinking']) || !isset($columns['effort'])) {
            return [];
        }
        $default = $this->db->fetchRow(
            'SELECT id, backend_id, thinking, effort FROM ' . self::CONNECTIONS_TABLE . ' WHERE is_default = %i AND model IS NOT NULL LIMIT 1',
            1,
        );
        if ($default === null) {
            return [];
        }
        $tuning = [];
        foreach (['thinking', 'effort'] as $column) {
            $value = trim((string) ($default[$column] ?? ''));
            if ($value !== '' && $value !== 'auto' && $value !== 'default') {
                $tuning[$column] = $value;
            }
        }
        if ($tuning === []) {
            return [];
        }
        $code = (string) ($default['backend_id'] ?? '');

        $converted = [];
        foreach ($this->db->fetchAll('SELECT id, function_id, thinking, effort FROM ' . self::TABLE) as $row) {
            $update = [];
            $changes = [];
            foreach ($tuning as $column => $value) {
                if (trim((string) ($row[$column] ?? 'default')) === 'default') {
                    $update[$column] = $value;
                    $changes[] = "{$column} {$value} (from default connection '{$code}')";
                }
            }
            if ($update === []) {
                continue;
            }
            $this->db->updateWhere(self::TABLE, $update + ['modified' => date('Y-m-d H:i:s')], 'id = %i', (int) $row['id']);
            $converted[] = ['function_id' => (string) $row['function_id'], 'changes' => $changes];
        }
        return $converted;
    }

    /**
     * Jednorázový idempotentní přepis vyřazených modelů u řádků funkcí
     * (`lifecycle.replacedBy` katalogu, F0-D8); dřív běžel nad backendy.
     *
     * @return list<array{id: int, function_id: string, from: string, to: string}>
     */
    public function retireModels(): array
    {
        if ($this->catalog === null || $this->catalog->isEmpty()) {
            return [];
        }
        $changed = [];
        foreach ($this->db->fetchAll('SELECT id, function_id, model FROM ' . self::TABLE . ' WHERE model IS NOT NULL') as $row) {
            $model = (string) ($row['model'] ?? '');
            $replacement = $this->catalog->retiredReplacement($model);
            if ($replacement === null) {
                continue;
            }
            $this->db->updateWhere(self::TABLE, ['model' => $replacement, 'modified' => date('Y-m-d H:i:s')], 'id = %i', (int) $row['id']);
            $changed[] = ['id' => (int) $row['id'], 'function_id' => (string) $row['function_id'], 'from' => $model, 'to' => $replacement];
        }
        return $changed;
    }

    /**
     * Značka hotového převodu (D46): u všech připojení vynuluje `model`
     * a staré sloupce ladění (existují-li ještě v databázi — `ds-upgrade`
     * sloupce nemaže, nový zdroj dat je nemá). Volat až po převodu profilů
     * pošty. Po prvním běhu navždy nic.
     *
     * @return list<string> kódy vyčištěných připojení
     */
    public function finishLegacyConversion(): array
    {
        $rows = $this->db->fetchAll('SELECT id, backend_id FROM ' . self::CONNECTIONS_TABLE . ' WHERE model IS NOT NULL');
        if ($rows === []) {
            return [];
        }
        $columns = $this->db->getTableColumns(self::CONNECTIONS_TABLE);
        $set = ['model' => null, 'modified' => date('Y-m-d H:i:s')];
        $defaults = ['max_tokens' => 0, 'temperature' => null, 'thinking' => 'auto', 'effort' => 'auto'];
        foreach (self::LEGACY_TUNING_COLUMNS as $column) {
            if (isset($columns[$column])) {
                $set[$column] = $defaults[$column];
            }
        }
        $this->db->updateWhere(self::CONNECTIONS_TABLE, $set, 'model IS NOT NULL');

        return array_map(static fn (array $row): string => (string) ($row['backend_id'] ?? $row['id']), $rows);
    }

    private function tierModelOf(string $functionId): ?string
    {
        $function = $this->registry->get($functionId);
        if ($function === null || $this->catalog === null) {
            return null;
        }
        return $this->catalog->defaultModel($function->tier);
    }
}
